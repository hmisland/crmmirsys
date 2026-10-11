<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactCategoryRepository;
use App\Infrastructure\Repositories\ContactEmailRepository;
use App\Infrastructure\Repositories\ContactImportBatchRepository;
use App\Infrastructure\Repositories\ContactLinkRepository;
use App\Infrastructure\Repositories\ContactPhoneRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Per Phase 0 §47: CSV import must validate data, respect permissions
 * and tenant isolation, report errors, and avoid duplicate corruption
 * (email-based upsert — an existing contact with a matching email is
 * updated, not duplicated). Export respects the same permission/tenant
 * restrictions as any other read.
 *
 * Import flow:
 *  1. upload()  — the CSV is stored server-side under a random token
 *     (storage/cache/contact-imports), so later steps never round-trip
 *     the rows through the browser. (Posting every row back used to
 *     hit PHP's max_input_vars — 1000 fields — and silently drop
 *     everything after the first ~70 rows.)
 *  2. preview() — reads the stored file with a column mapping (auto-
 *     guessed from the headers, editable per column by the user) and
 *     returns every row with its validation result.
 *  3. commit()  — re-reads the stored file with the final mapping and
 *     writes the rows the user left checked, recording everything it
 *     created/updated as an import batch.
 *  4. rollback() — undoes the organization's most recent batch.
 */
final class ContactImportExportService
{
    /** Export header label => contact field (also accepted on import). */
    private const EXPORT_COLUMNS = [
        'First Name' => 'first_name',
        'Last Name' => 'last_name',
        'Email' => 'email',
        'Phone' => 'phone',
        'Company' => 'company',
        'Position' => 'position',
        'Address' => 'address',
        'City' => 'city',
        'Province' => 'province',
        'Postal Code' => 'postal_code',
        'Country' => 'country',
        'Source' => 'source',
        'Status' => 'status',
        'Tags' => 'tags',
    ];

    /** Import target => translation key of its label, in display order. */
    public const TARGETS = [
        'first_name' => 'auth.first_name',
        'last_name' => 'auth.last_name',
        'email' => 'common.email',
        'phone' => 'contacts.mobile',
        'landline' => 'contacts.landline',
        'company' => 'common.company',
        'position' => 'contacts.position',
        'address' => 'companies.address',
        'city' => 'companies.city',
        'province' => 'contacts.province',
        'postal_code' => 'contacts.postal_code',
        'country' => 'companies.country',
        'national_id' => 'contacts.national_id',
        'economic_code' => 'contacts.economic_code',
        'source' => 'leads.source',
        'description' => 'contacts.description',
        'tags' => 'common.tags',
    ];

    /** Mapping value for "create a new custom field from this column". */
    public const NEW_CUSTOM = '__new_custom__';

    /** Targets that several columns may feed; their values are joined. */
    private const JOINED_TARGETS = ['address' => ', ', 'description' => "\n", 'tags' => ','];

    /**
     * Header synonyms (normalized: lowercase, no spaces/_/-/.) => target.
     * Covers common CRM/e-commerce exports (e.g. WooCommerce billing_*)
     * and Persian headers. shipping_* columns are deliberately absent.
     */
    private const SYNONYMS = [
        'firstname' => 'first_name', 'fname' => 'first_name', 'givenname' => 'first_name', 'name' => 'first_name',
        'billingfirstname' => 'first_name', 'نام' => 'first_name',
        'lastname' => 'last_name', 'surname' => 'last_name', 'familyname' => 'last_name', 'family' => 'last_name',
        'billinglastname' => 'last_name', 'نامخانوادگی' => 'last_name', 'فامیل' => 'last_name', 'فامیلی' => 'last_name',
        'email' => 'email', 'emailaddress' => 'email', 'mail' => 'email', 'useremail' => 'email', 'billingemail' => 'email',
        'ایمیل' => 'email', 'پستالکترونیک' => 'email', 'پستالکترونیکی' => 'email',
        'phone' => 'phone', 'mobile' => 'phone', 'cell' => 'phone', 'cellphone' => 'phone', 'mobilephone' => 'phone',
        'phonenumber' => 'phone', 'billingphone' => 'phone', 'موبایل' => 'phone', 'تلفنهمراه' => 'phone',
        'شمارهموبایل' => 'phone', 'همراه' => 'phone', 'شمارهتلفن' => 'phone', 'تلفن' => 'phone',
        'landline' => 'landline', 'telephone' => 'landline', 'officephone' => 'landline', 'workphone' => 'landline',
        'تلفنثابت' => 'landline',
        'company' => 'company', 'companyname' => 'company', 'organization' => 'company', 'organisation' => 'company',
        'billingcompany' => 'company', 'شرکت' => 'company', 'نامشرکت' => 'company', 'سازمان' => 'company',
        'position' => 'position', 'jobtitle' => 'position', 'سمت' => 'position', 'عنوانشغلی' => 'position',
        'address' => 'address', 'address1' => 'address', 'address2' => 'address', 'street' => 'address',
        'billingaddress' => 'address', 'billingaddress1' => 'address', 'billingaddress2' => 'address',
        'آدرس' => 'address', 'نشانی' => 'address',
        'city' => 'city', 'town' => 'city', 'billingcity' => 'city', 'شهر' => 'city',
        'state' => 'province', 'province' => 'province', 'region' => 'province', 'billingstate' => 'province', 'استان' => 'province',
        'postcode' => 'postal_code', 'postalcode' => 'postal_code', 'zip' => 'postal_code', 'zipcode' => 'postal_code',
        'billingpostcode' => 'postal_code', 'کدپستی' => 'postal_code',
        'country' => 'country', 'billingcountry' => 'country', 'کشور' => 'country',
        'nationalid' => 'national_id', 'nationalcode' => 'national_id', 'کدملی' => 'national_id', 'شمارهملی' => 'national_id',
        'economiccode' => 'economic_code', 'کداقتصادی' => 'economic_code',
        'source' => 'source', 'leadsource' => 'source', 'منبع' => 'source',
        'description' => 'description', 'notes' => 'description', 'note' => 'description', 'comment' => 'description',
        'comments' => 'description', 'توضیحات' => 'description', 'یادداشت' => 'description',
        'tags' => 'tags', 'tag' => 'tags', 'برچسب' => 'tags', 'برچسبها' => 'tags',
    ];

    /** WooCommerce/ISO-style Iranian province codes => province name. */
    private const PROVINCE_CODES = [
        'EAZ' => 'آذربایجان شرقی', 'WAZ' => 'آذربایجان غربی', 'ADL' => 'اردبیل', 'ESF' => 'اصفهان', 'ABZ' => 'البرز',
        'ILM' => 'ایلام', 'BHR' => 'بوشهر', 'THR' => 'تهران', 'CHB' => 'چهارمحال و بختیاری', 'SKH' => 'خراسان جنوبی',
        'RKH' => 'خراسان رضوی', 'NKH' => 'خراسان شمالی', 'KHZ' => 'خوزستان', 'ZJN' => 'زنجان', 'SMN' => 'سمنان',
        'SBN' => 'سیستان و بلوچستان', 'FRS' => 'فارس', 'GZN' => 'قزوین', 'QHM' => 'قم', 'KRD' => 'کردستان',
        'KRN' => 'کرمان', 'KRH' => 'کرمانشاه', 'KBD' => 'کهگیلویه و بویراحمد', 'GLS' => 'گلستان', 'GIL' => 'گیلان',
        'LRS' => 'لرستان', 'MZN' => 'مازندران', 'MKZ' => 'مرکزی', 'HRZ' => 'هرمزگان', 'HDN' => 'همدان', 'YZD' => 'یزد',
    ];

    /** contacts columns an import can write (and a rollback restores). */
    private const CONTACT_COLUMNS = ['first_name', 'last_name', 'email', 'phone', 'company_id', 'category_id', 'position',
        'address', 'city', 'province', 'postal_code', 'country', 'national_id', 'economic_code', 'source', 'description'];

    /** contacts column lengths (and contact_phones.number for the phones). */
    private const FIELD_LIMITS = [
        'first_name' => 100, 'last_name' => 100, 'email' => 255, 'phone' => 50, 'landline' => 50, 'company' => 255,
        'position' => 150, 'address' => 255, 'city' => 100, 'province' => 100, 'postal_code' => 20, 'country' => 100,
        'national_id' => 30, 'economic_code' => 30, 'source' => 100,
    ];

    private const MAX_ROWS = 2000;
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB
    private const FILE_TTL = 86400; // stored uploads older than a day are cleaned up

    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly CustomFieldService $customFields,
        private readonly TagService $tags,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
        private readonly ContactPhoneRepository $phones,
        private readonly ContactEmailRepository $emails,
        private readonly ContactCategoryRepository $categories,
        private readonly ContactImportBatchRepository $batches,
        private readonly ContactLinkRepository $links,
        private readonly string $storagePath,
    ) {
    }

    // --- Export -----------------------------------------------------------

    public function exportCsv(array $actingUser, array $filters): string
    {
        $this->authorize($actingUser, Permission::CONTACTS_EXPORT);

        $definitions = $this->customFields->definitionsFor('contact');
        $rows = $this->contacts->search($filters);

        $handle = fopen('php://temp', 'r+');

        $header = array_keys(self::EXPORT_COLUMNS);
        foreach ($definitions as $def) {
            $header[] = $def['label'];
        }
        fputcsv($handle, $header);

        foreach ($rows as $contact) {
            $tagNames = array_column($this->tags->forSubject('contact', (int) $contact['id']), 'name');
            $customValues = $this->customFields->valuesFor('contact', (int) $contact['id']);

            $line = [];
            foreach (self::EXPORT_COLUMNS as $field) {
                $line[] = match ($field) {
                    'company' => $contact['company_name'] ?? '',
                    'tags' => implode(', ', $tagNames),
                    default => $contact[$field] ?? '',
                };
            }

            foreach ($definitions as $def) {
                $line[] = $customValues[$def['field_key']] ?? '';
            }

            fputcsv($handle, $line);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        // Excel needs a UTF-8 BOM to render non-ASCII (e.g. Persian)
        // text correctly instead of guessing the wrong encoding.
        return "\xEF\xBB\xBF" . $content;
    }

    // --- Import: permissions the preview screen needs --------------------

    /** @return array{category: bool, company: bool, custom_field: bool} */
    public function creationRights(array $actingUser): array
    {
        return [
            'category' => $this->authorization->can($actingUser, Permission::CONTACTS_MANAGE_CATEGORIES),
            'company' => $this->authorization->can($actingUser, Permission::COMPANIES_CREATE),
            'custom_field' => $this->authorization->can($actingUser, Permission::CUSTOM_FIELDS_MANAGE),
        ];
    }

    // --- Import: step 1, store the upload ---------------------------------

    /** @return array{token: string, file_name: string} */
    public function upload(array $actingUser, array $file): array
    {
        $this->authorize($actingUser, Permission::CONTACTS_IMPORT);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => ['import.error.upload_failed']]);
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new ValidationException(['file' => ['import.error.too_large']]);
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            throw new ValidationException(['file' => ['import.error.csv_only']]);
        }

        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
            throw new ValidationException(['file' => ['import.error.store_failed']]);
        }

        $this->cleanupOldFiles();

        $token = bin2hex(random_bytes(16));
        $target = $this->filePath($actingUser, $token);

        if (!move_uploaded_file((string) $file['tmp_name'], $target) && !copy((string) $file['tmp_name'], $target)) {
            throw new ValidationException(['file' => ['import.error.store_failed']]);
        }

        // Fail early (still on the upload screen) on an unreadable file.
        $this->readCsv($target);

        return ['token' => $token, 'file_name' => basename((string) $file['name'])];
    }

    // --- Import: step 2, preview ------------------------------------------

    /**
     * @param array<int|string, string>|null $mapping column index => target ('' = ignore,
     *   a TARGETS key, 'custom:<field_key>' or NEW_CUSTOM); null = auto-guess
     */
    public function preview(array $actingUser, string $token, ?array $mapping): array
    {
        $this->authorize($actingUser, Permission::CONTACTS_IMPORT);

        [$headers, $rawRows] = $this->readCsv($this->requireFile($actingUser, $token));
        $definitions = $this->customFields->definitionsFor('contact');
        $mapping = $mapping === null ? $this->guessMapping($headers, $definitions) : $this->sanitizeMapping($mapping, $headers, $definitions);

        $companiesByName = $this->companiesByName();
        $rows = [];
        $seenEmails = [];

        foreach ($rawRows as $rowNumber => $raw) {
            $row = $this->buildRow($raw, $mapping, $headers, $rowNumber);

            $email = strtolower($row['data']['email']);
            if ($row['errors'] === [] && $email !== '') {
                $existing = $this->contacts->findByEmail($row['data']['email']);
                if ($existing !== null || isset($seenEmails[$email])) {
                    $row['action'] = 'update';
                }
                $seenEmails[$email] = true;
            }

            if ($row['data']['company'] !== '' && !isset($companiesByName[mb_strtolower($row['data']['company'])])) {
                $row['notes'][] = 'new_company';
            }

            $rows[] = $row;
        }

        $samples = [];
        foreach ($headers as $index => $header) {
            $samples[$index] = '';
            foreach ($rawRows as $raw) {
                $value = $this->cleanValue($raw[$index] ?? '');
                if ($value !== '') {
                    $samples[$index] = mb_substr($value, 0, 60);
                    break;
                }
            }
        }

        return [
            'headers' => $headers,
            'samples' => $samples,
            'mapping' => $mapping,
            'definitions' => $definitions,
            'rows' => $rows,
            'columns' => $this->previewColumns($mapping, $headers, $definitions),
        ];
    }

    // --- Import: step 3, commit -------------------------------------------

    /**
     * @param array{token: string, file_name?: string, mapping?: array, excluded_rows?: string,
     *   category_id?: string, new_category_name?: string, company_id?: string, new_company_name?: string} $input
     * @return array{created: int, updated: int, skipped: int, errors: array<int, array{row: int, codes: string[]}>, batch_id: ?int}
     */
    public function commit(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::CONTACTS_IMPORT);
        $actorId = (int) $actingUser['id'];

        $token = (string) ($input['token'] ?? '');
        $path = $this->requireFile($actingUser, $token);
        [$headers, $rawRows] = $this->readCsv($path);
        $definitions = $this->customFields->definitionsFor('contact');
        $mapping = $this->sanitizeMapping((array) ($input['mapping'] ?? []), $headers, $definitions);

        $excluded = array_flip(array_filter(array_map('intval', explode(',', (string) ($input['excluded_rows'] ?? '')))));

        $batchId = $this->batches->create($actorId, (string) ($input['file_name'] ?? ''));

        $categoryId = $this->resolveImportCategory($actingUser, $input, $batchId);
        $defaultCompanyId = $this->resolveImportCompany($actingUser, $input, $batchId);
        $mapping = $this->createNewCustomFields($actingUser, $mapping, $headers, $definitions, $batchId);

        $companiesByName = $this->companiesByName();
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        /** @var array<int, true> contact ids this batch already recorded */
        $touched = [];

        foreach ($rawRows as $rowNumber => $raw) {
            if (isset($excluded[$rowNumber])) {
                $skipped++;
                continue;
            }

            $row = $this->buildRow($raw, $mapping, $headers, $rowNumber);

            if ($row['errors'] !== []) {
                $errors[] = ['row' => $rowNumber, 'codes' => $row['errors']];
                $skipped++;
                continue;
            }

            // One bad row (e.g. a database constraint) is reported and
            // skipped — it never aborts the rest of the file.
            try {
                $action = $this->importRow($row, $actorId, $batchId, $categoryId, $defaultCompanyId, $companiesByName, $touched);
                $action === 'created' ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'codes' => ['save_failed']];
                $skipped++;
            }
        }

        $this->batches->updateBatch($batchId, ['created_count' => $created, 'updated_count' => $updated]);
        @unlink($path);

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors, 'batch_id' => $batchId];
    }

    /**
     * Writes one validated row. The batch item is recorded right after
     * the contact row is written (before phones/tags/custom values), so
     * even a row that fails half-way stays reachable by a rollback.
     *
     * @param array<int, true> $touched contact ids this batch already recorded
     * @return string 'created'|'updated'
     */
    private function importRow(array $row, int $actorId, int $batchId, ?int $categoryId, ?int $defaultCompanyId, array &$companiesByName, array &$touched): string
    {
        $data = $row['data'];
        $fields = [];
        foreach (['first_name', 'last_name', 'email', 'phone', 'position', 'address', 'city', 'province',
            'postal_code', 'country', 'national_id', 'economic_code', 'source', 'description'] as $key) {
            if ($data[$key] !== '') {
                $fields[$key] = $data[$key];
            }
        }

        if ($data['company'] !== '') {
            $fields['company_id'] = $this->companyIdForName($data['company'], $actorId, $batchId, $companiesByName);
        } elseif ($defaultCompanyId !== null) {
            $fields['company_id'] = $defaultCompanyId;
        }

        if ($categoryId !== null) {
            $fields['category_id'] = $categoryId;
        }

        $existing = $data['email'] !== '' ? $this->contacts->findByEmail($data['email']) : null;

        if ($existing !== null) {
            $contactId = (int) $existing['id'];
            if (!isset($touched[$contactId])) {
                $this->batches->addItem($batchId, 'contact', $contactId, 'updated', $this->snapshotContact($existing, $row));
                $touched[$contactId] = true;
            }
            $this->contacts->updateContact($contactId, $fields);
            $this->syncPhonesAndEmails($contactId, $data, false);
            $this->timeline->record('contact', $contactId, 'updated', 'Updated via CSV import', $actorId);
            $action = 'updated';
        } else {
            $fields['owner_id'] = $actorId;
            $contactId = $this->contacts->create($fields);
            $this->batches->addItem($batchId, 'contact', $contactId, 'created');
            $touched[$contactId] = true;
            $this->syncPhonesAndEmails($contactId, $data, true);
            $this->timeline->record('contact', $contactId, 'created', 'Created via CSV import', $actorId);
            $action = 'created';
        }

        foreach (array_filter(array_map('trim', explode(',', $data['tags']))) as $tagName) {
            $this->tags->attach('contact', $contactId, $tagName, $actorId);
        }

        if ($row['custom'] !== []) {
            $this->customFields->saveValues('contact', $contactId, $row['custom']);
        }

        // "Untouched since import" marker for the rollback; moved forward
        // again if the same contact appears twice in one file.
        $this->batches->setItemStamp($batchId, 'contact', $contactId, (string) $this->batches->contactUpdatedAt($contactId));

        return $action;
    }

    // --- Import: step 4, rollback -----------------------------------------

    /** The organization's latest import, with whether it can still be rolled back. */
    public function latestBatch(array $actingUser): ?array
    {
        if (!$this->authorization->can($actingUser, Permission::CONTACTS_IMPORT)) {
            return null;
        }

        $batch = $this->batches->latest();
        if ($batch === null) {
            return null;
        }

        $batch['can_rollback'] = $batch['status'] === 'active' && ((int) $batch['created_count'] + (int) $batch['updated_count']) > 0;

        return $batch;
    }

    /**
     * Undoes the latest import: deletes the contacts it created and
     * restores the ones it updated — except any contact edited or used
     * (invoice, deal, task, note, ...) since, which is left as is and
     * reported. Companies, categories and custom fields the import
     * created are removed when nothing uses them any more.
     *
     * @return array{deleted: int, restored: int, kept: array<int, array{id: int, name: string}>, companies_deleted: int}
     */
    public function rollback(array $actingUser, int $batchId): array
    {
        $this->authorize($actingUser, Permission::CONTACTS_IMPORT);

        $latest = $this->batches->latest();
        if ($latest === null || (int) $latest['id'] !== $batchId || $latest['status'] !== 'active') {
            throw new ValidationException(['batch' => ['import.error.rollback_not_latest']]);
        }

        $actorId = (int) $actingUser['id'];
        $items = $this->batches->items($batchId);
        $report = ['deleted' => 0, 'restored' => 0, 'kept' => [], 'companies_deleted' => 0];

        foreach ($items as $item) {
            if ($item['entity_type'] !== 'contact') {
                continue;
            }

            $contactId = (int) $item['entity_id'];
            $contact = $this->contacts->findById($contactId);
            if ($contact === null) {
                continue; // already deleted by someone
            }

            $editedSince = $item['stamp'] !== null && (string) $contact['updated_at'] !== (string) $item['stamp'];

            if ($item['action'] === 'created') {
                if ($editedSince || $this->links->contactInUse($contactId)) {
                    $report['kept'][] = ['id' => $contactId, 'name' => $this->contactName($contact)];
                    continue;
                }

                $customKeys = array_keys($this->customFields->valuesFor('contact', $contactId));
                if ($customKeys !== []) {
                    $this->customFields->saveValues('contact', $contactId, array_fill_keys($customKeys, ''));
                }
                $this->contacts->deleteContact($contactId);
                $report['deleted']++;
                continue;
            }

            if ($editedSince || !is_array($item['snapshot'])) {
                $report['kept'][] = ['id' => $contactId, 'name' => $this->contactName($contact)];
                continue;
            }

            $this->restoreSnapshot($contactId, $item['snapshot'], $actorId);
            $report['restored']++;
        }

        // Created companies/categories/custom fields last, once the
        // contacts that pointed at them are gone.
        foreach ($items as $item) {
            $id = (int) $item['entity_id'];

            if ($item['entity_type'] === 'company' && !$this->links->companyInUse($id)) {
                $this->companies->deleteCompany($id);
                $report['companies_deleted']++;
            } elseif ($item['entity_type'] === 'category' && !$this->links->categoryInUse($id)) {
                $this->categories->deleteCategory($id);
            } elseif ($item['entity_type'] === 'custom_field') {
                $this->customFields->deleteDefinitionIfUnused($id);
            }
        }

        $this->batches->updateBatch($batchId, [
            'status' => 'rolled_back',
            'rolled_back_at' => date('Y-m-d H:i:s'),
            'rolled_back_by_user_id' => $actorId,
        ]);

        return $report;
    }

    // --- Parsing ------------------------------------------------------------

    /** @return array{0: string[], 1: array<int, array>} headers, rows keyed by file line number */
    private function readCsv(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new ValidationException(['file' => ['import.error.empty']]);
        }

        // Strip a UTF-8 BOM (Excel) and convert legacy Persian/Arabic
        // Windows encodings so headers and names aren't garbled.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @iconv('Windows-1256', 'UTF-8//IGNORE', $content);
            $content = $converted !== false ? $converted : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = ',';
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($firstLine, $candidate) > substr_count($firstLine, $delimiter)) {
                $delimiter = $candidate;
            }
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $headerRow = fgetcsv($handle, null, $delimiter, '"', '\\');
        if ($headerRow === false || $headerRow === [null]) {
            fclose($handle);
            throw new ValidationException(['file' => ['import.error.empty']]);
        }

        $headers = [];
        foreach ($headerRow as $index => $label) {
            $label = trim((string) $label);
            $headers[$index] = $label !== '' ? $label : '#' . ($index + 1);
        }

        $rows = [];
        $lineNumber = 1;
        while (($raw = fgetcsv($handle, null, $delimiter, '"', '\\')) !== false && count($rows) < self::MAX_ROWS) {
            $lineNumber++;
            if ($raw === [null] || $raw === [] || implode('', array_map(fn ($v) => $this->cleanValue($v), $raw)) === '') {
                continue; // blank line
            }
            $rows[$lineNumber] = $raw;
        }
        fclose($handle);

        return [$headers, $rows];
    }

    /** Database exports write empty cells as the text NULL — treat as empty. */
    private function cleanValue(mixed $value): string
    {
        $value = trim((string) $value);

        return in_array(strtoupper($value), ['NULL', '\\N'], true) ? '' : $value;
    }

    private function normalizeHeader(string $header): string
    {
        return (string) preg_replace('/[\s_\-.\x{200C}]+/u', '', mb_strtolower(trim($header)));
    }

    /** @return array<int, string> column index => target */
    private function guessMapping(array $headers, array $definitions): array
    {
        $customByLabel = [];
        foreach ($definitions as $def) {
            $customByLabel[$this->normalizeHeader($def['label'])] = 'custom:' . $def['field_key'];
        }

        $exportLabels = [];
        foreach (self::EXPORT_COLUMNS as $label => $field) {
            $exportLabels[$this->normalizeHeader($label)] = $field === 'status' ? '' : $field;
        }

        $mapping = [];
        $used = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);
            $target = $customByLabel[$normalized] ?? self::SYNONYMS[$normalized] ?? $exportLabels[$normalized] ?? '';

            // Each single-value target goes to the first matching column
            // only; the user can still pick it for another one by hand.
            if ($target !== '' && isset($used[$target]) && !isset(self::JOINED_TARGETS[$target])) {
                $target = '';
            }

            $mapping[$index] = $target;
            if ($target !== '') {
                $used[$target] = true;
            }
        }

        return $mapping;
    }

    /** Keeps only valid targets, one entry per header. */
    private function sanitizeMapping(array $mapping, array $headers, array $definitions): array
    {
        $customKeys = array_map(fn ($d) => 'custom:' . $d['field_key'], $definitions);
        $clean = [];

        foreach ($headers as $index => $header) {
            $target = (string) ($mapping[$index] ?? '');
            $valid = $target === ''
                || $target === self::NEW_CUSTOM
                || isset(self::TARGETS[$target])
                || in_array($target, $customKeys, true);
            $clean[$index] = $valid ? $target : '';
        }

        return $clean;
    }

    /**
     * @return array{row_number: int, data: array<string, string>, custom: array<string, string>,
     *   new_custom: array<int, string>, errors: string[], notes: string[], action: string}
     */
    private function buildRow(array $raw, array $mapping, array $headers, int $rowNumber): array
    {
        $data = array_fill_keys(array_keys(self::TARGETS), '');
        $custom = [];
        $newCustom = [];

        foreach ($mapping as $index => $target) {
            if ($target === '') {
                continue;
            }

            $value = $this->cleanValue($raw[$index] ?? '');
            if ($value === '') {
                continue;
            }

            if ($target === self::NEW_CUSTOM) {
                $newCustom[$index] = $value;
            } elseif (str_starts_with($target, 'custom:')) {
                $custom[substr($target, 7)] = $value;
            } elseif (isset(self::JOINED_TARGETS[$target]) && $data[$target] !== '') {
                $data[$target] .= self::JOINED_TARGETS[$target] . $value;
            } elseif ($data[$target] === '') {
                $data[$target] = $value;
            }
        }

        if (isset(self::PROVINCE_CODES[strtoupper($data['province'])])) {
            $data['province'] = self::PROVINCE_CODES[strtoupper($data['province'])];
        }

        // Fit the database columns — a long joined address must not
        // make the whole row fail.
        foreach (self::FIELD_LIMITS as $key => $limit) {
            if (mb_strlen($data[$key]) > $limit) {
                $data[$key] = mb_substr($data[$key], 0, $limit);
            }
        }

        $errors = [];
        $notes = [];

        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'invalid_email';
        }

        // No first name: fall back to the part of the email before @
        // (or the phone number), so rows that only carry an email —
        // e.g. store sign-ups — are still importable.
        if ($data['first_name'] === '') {
            if ($data['email'] !== '' && $errors === []) {
                $data['first_name'] = mb_substr(strstr($data['email'], '@', true) ?: $data['email'], 0, 100);
                $notes[] = 'name_from_email';
            } elseif ($data['phone'] !== '') {
                $data['first_name'] = $data['phone'];
                $notes[] = 'name_from_phone';
            } elseif ($data['last_name'] === '') {
                $errors[] = 'no_name';
            }
        }

        return [
            'row_number' => $rowNumber,
            'data' => $data,
            'custom' => $custom,
            'new_custom' => $newCustom,
            'errors' => $errors,
            'notes' => $notes,
            'action' => 'create',
        ];
    }

    /**
     * Columns shown in the preview table, in file order, one per
     * distinct target.
     *
     * @return array<int, array{target: string, label_key: ?string, label: ?string, column: int}>
     */
    private function previewColumns(array $mapping, array $headers, array $definitions): array
    {
        $labels = [];
        foreach ($definitions as $def) {
            $labels['custom:' . $def['field_key']] = $def['label'];
        }

        $columns = [];
        $seen = [];
        foreach ($mapping as $index => $target) {
            if ($target === '' || (isset($seen[$target]) && $target !== self::NEW_CUSTOM)) {
                continue;
            }
            $seen[$target] = true;

            $columns[] = [
                'target' => $target,
                'column' => $index,
                'label_key' => self::TARGETS[$target] ?? null,
                'label' => $target === self::NEW_CUSTOM ? $headers[$index] : ($labels[$target] ?? null),
            ];
        }

        return $columns;
    }

    // --- Commit helpers -----------------------------------------------------

    private function resolveImportCategory(array $actingUser, array $input, int $batchId): ?int
    {
        $newName = trim((string) ($input['new_category_name'] ?? ''));

        if ($newName !== '') {
            $this->authorize($actingUser, Permission::CONTACTS_MANAGE_CATEGORIES);

            foreach ($this->categories->all() as $category) {
                if (mb_strtolower($category['name']) === mb_strtolower($newName)) {
                    return (int) $category['id'];
                }
            }

            $id = $this->categories->create($newName);
            $this->batches->addItem($batchId, 'category', $id, 'created');

            return $id;
        }

        $id = (int) ($input['category_id'] ?? 0);

        return $id > 0 && $this->categories->findById($id) !== null ? $id : null;
    }

    private function resolveImportCompany(array $actingUser, array $input, int $batchId): ?int
    {
        $newName = trim((string) ($input['new_company_name'] ?? ''));

        if ($newName !== '') {
            $this->authorize($actingUser, Permission::COMPANIES_CREATE);
            $companiesByName = $this->companiesByName();

            return $this->companyIdForName($newName, (int) $actingUser['id'], $batchId, $companiesByName);
        }

        $id = (int) ($input['company_id'] ?? 0);

        return $id > 0 && $this->companies->findById($id) !== null ? $id : null;
    }

    /** Creates a definition for every column mapped to "new custom field" and remaps it. */
    private function createNewCustomFields(array $actingUser, array $mapping, array $headers, array $definitions, int $batchId): array
    {
        $byLabel = [];
        foreach ($definitions as $def) {
            $byLabel[mb_strtolower($def['label'])] = $def['field_key'];
        }

        foreach ($mapping as $index => $target) {
            if ($target !== self::NEW_CUSTOM) {
                continue;
            }

            $this->authorize($actingUser, Permission::CUSTOM_FIELDS_MANAGE);
            $label = mb_substr($headers[$index], 0, 100);

            if (!isset($byLabel[mb_strtolower($label)])) {
                try {
                    $definition = $this->customFields->defineField($actingUser, 'contact', $label, 'text');
                } catch (ValidationException) {
                    // Key collision with a differently-labelled field:
                    // make the label unique and try once more.
                    $definition = $this->customFields->defineField($actingUser, 'contact', $label . ' ' . ($index + 1), 'text');
                }
                $byLabel[mb_strtolower($label)] = $definition['field_key'];
                $this->batches->addItem($batchId, 'custom_field', (int) $definition['id'], 'created');
            }

            $mapping[$index] = 'custom:' . $byLabel[mb_strtolower($label)];
        }

        return $mapping;
    }

    /** @return array<string, int> lowercased company name => id */
    private function companiesByName(): array
    {
        $map = [];
        foreach ($this->companies->all() as $company) {
            $map[mb_strtolower($company['name'])] = (int) $company['id'];
        }

        return $map;
    }

    private function companyIdForName(string $name, int $actorId, int $batchId, array &$companiesByName): int
    {
        $key = mb_strtolower($name);

        if (!isset($companiesByName[$key])) {
            $companiesByName[$key] = $this->companies->create(['name' => $name, 'owner_id' => $actorId]);
            $this->batches->addItem($batchId, 'company', $companiesByName[$key], 'created');
        }

        return $companiesByName[$key];
    }

    /**
     * Keeps the phone/email child tables (what the contact form reads)
     * in step with contacts.phone/email. For an existing contact the
     * imported number/email becomes the primary one and everything
     * already on file is kept.
     */
    private function syncPhonesAndEmails(int $contactId, array $data, bool $isNew): void
    {
        $phones = [];
        if ($data['phone'] !== '') {
            $phones[] = ['type' => 'mobile', 'number' => $data['phone'], 'extension' => null, 'is_primary' => true];
        }
        if ($data['landline'] !== '') {
            $phones[] = ['type' => 'landline', 'number' => $data['landline'], 'extension' => null, 'is_primary' => $phones === []];
        }

        $emails = $data['email'] !== '' ? [['email' => $data['email'], 'is_primary' => true]] : [];

        if (!$isNew) {
            if ($phones === [] && $emails === []) {
                return;
            }

            $newNumbers = array_column($phones, 'number');
            foreach ($this->phones->forContact($contactId) as $p) {
                if (!in_array($p['number'], $newNumbers, true)) {
                    $phones[] = ['type' => $p['type'], 'number' => $p['number'], 'extension' => $p['extension'],
                        'is_primary' => $phones === [] && (bool) $p['is_primary']];
                }
            }

            foreach ($this->emails->forContact($contactId) as $em) {
                if (strcasecmp($em['email'], $data['email']) !== 0) {
                    $emails[] = ['email' => $em['email'], 'is_primary' => $emails === [] && (bool) $em['is_primary']];
                }
            }
        }

        if ($phones !== []) {
            $this->phones->replaceForContact($contactId, $phones);
        }
        if ($emails !== []) {
            $this->emails->replaceForContact($contactId, $emails);
        }
    }

    private function snapshotContact(array $contact, array $row): array
    {
        $fields = [];
        foreach (self::CONTACT_COLUMNS as $column) {
            $fields[$column] = $contact[$column] ?? null;
        }

        $customBefore = $this->customFields->valuesFor('contact', (int) $contact['id']);
        $customKeys = array_keys($row['custom']);

        return [
            'fields' => $fields,
            'phones' => $this->phones->forContact((int) $contact['id']),
            'emails' => $this->emails->forContact((int) $contact['id']),
            'tags' => array_column($this->tags->forSubject('contact', (int) $contact['id']), 'name'),
            // Only keys this row touches; null = had no value before.
            'custom' => array_combine($customKeys, array_map(fn ($k) => $customBefore[$k] ?? null, $customKeys)) ?: [],
        ];
    }

    private function restoreSnapshot(int $contactId, array $snapshot, int $actorId): void
    {
        $fields = array_intersect_key((array) ($snapshot['fields'] ?? []), array_flip(self::CONTACT_COLUMNS));
        if ($fields !== []) {
            $this->contacts->updateContact($contactId, $fields);
        }

        $this->phones->replaceForContact($contactId, array_map(fn ($p) => [
            'type' => $p['type'], 'number' => $p['number'], 'extension' => $p['extension'] ?? null, 'is_primary' => (bool) $p['is_primary'],
        ], (array) ($snapshot['phones'] ?? [])));

        $this->emails->replaceForContact($contactId, array_map(fn ($e) => [
            'email' => $e['email'], 'is_primary' => (bool) $e['is_primary'],
        ], (array) ($snapshot['emails'] ?? [])));

        $tagsBefore = (array) ($snapshot['tags'] ?? []);
        foreach ($this->tags->forSubject('contact', $contactId) as $tag) {
            if (!in_array($tag['name'], $tagsBefore, true)) {
                $this->tags->detach('contact', $contactId, (int) $tag['id'], $actorId);
            }
        }

        $custom = array_map(fn ($v) => $v ?? '', (array) ($snapshot['custom'] ?? []));
        if ($custom !== []) {
            $this->customFields->saveValues('contact', $contactId, $custom);
        }

        $this->timeline->record('contact', $contactId, 'updated', 'CSV import rolled back', $actorId);
    }

    private function contactName(array $contact): string
    {
        return trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''));
    }

    // --- Stored upload files --------------------------------------------

    private function filePath(array $actingUser, string $token): string
    {
        return rtrim($this->storagePath, '/\\') . '/' . (int) OrganizationContext::id() . '-' . (int) $actingUser['id'] . '-' . $token . '.csv';
    }

    private function requireFile(array $actingUser, string $token): string
    {
        $path = $this->filePath($actingUser, $token);

        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($path)) {
            throw new ValidationException(['file' => ['import.error.expired']]);
        }

        return $path;
    }

    private function cleanupOldFiles(): void
    {
        foreach (glob(rtrim($this->storagePath, '/\\') . '/*.csv') ?: [] as $file) {
            if (filemtime($file) < time() - self::FILE_TTL) {
                @unlink($file);
            }
        }
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
