<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;

/**
 * Per Phase 0 §47: CSV import must validate data, respect permissions
 * and tenant isolation, report errors, and avoid duplicate corruption
 * (handled here via email-based upsert — an existing contact with a
 * matching email is updated, not duplicated). Export respects the
 * same permission/tenant restrictions as any other read.
 *
 * Import is a two-step, stateless flow — no server-side session state
 * or staging table: parsePreview() reads the uploaded file once and
 * returns every row (valid or not) for the browser to render as an
 * editable table; commit() receives that same data back from the
 * resubmitted form and performs the actual writes. The uploaded
 * file's tmp path is gone by the time commit() runs (a separate
 * request) — commit() never needs it again, only what the preview
 * form carries.
 */
final class ContactImportExportService
{
    /** header label (as shown to the user) => internal field key */
    private const CORE_COLUMNS = [
        'First Name' => 'first_name',
        'Last Name' => 'last_name',
        'Email' => 'email',
        'Phone' => 'phone',
        'Company' => 'company',
        'Position' => 'position',
        'Address' => 'address',
        'City' => 'city',
        'Country' => 'country',
        'Source' => 'source',
        'Status' => 'status',
        'Tags' => 'tags',
    ];

    private const MAX_ROWS = 2000;
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly CustomFieldService $customFields,
        private readonly TagService $tags,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    // --- Export -----------------------------------------------------------

    public function exportCsv(array $actingUser, array $filters): string
    {
        $this->authorize($actingUser, Permission::CONTACTS_EXPORT);

        $definitions = $this->customFields->definitionsFor('contact');
        $rows = $this->contacts->search($filters);

        $handle = fopen('php://temp', 'r+');

        $header = array_keys(self::CORE_COLUMNS);
        foreach ($definitions as $def) {
            $header[] = $def['label'];
        }
        fputcsv($handle, $header);

        foreach ($rows as $contact) {
            $tagNames = array_column($this->tags->forSubject('contact', (int) $contact['id']), 'name');
            $customValues = $this->customFields->valuesFor('contact', (int) $contact['id']);

            $line = [
                $contact['first_name'],
                $contact['last_name'],
                $contact['email'],
                $contact['phone'],
                $contact['company_name'] ?? '',
                $contact['position'],
                $contact['address'],
                $contact['city'],
                $contact['country'],
                $contact['source'],
                $contact['status'],
                implode(', ', $tagNames),
            ];

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

    // --- Import: parse + validate for preview -----------------------------

    /**
     * @param array $file One entry from Request::file()
     * @return array{rows: array, customFieldDefs: array}
     */
    public function parsePreview(array $actingUser, array $file): array
    {
        $this->authorize($actingUser, Permission::CONTACTS_IMPORT);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => ['Upload failed.']]);
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new ValidationException(['file' => ['File is too large (5 MB max).']]);
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            throw new ValidationException(['file' => ['Only .csv files are supported right now.']]);
        }

        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            throw new ValidationException(['file' => ['Could not read the uploaded file.']]);
        }

        // Strip a UTF-8 BOM if present (common in Excel-exported CSVs)
        // so it doesn't get glued onto the first header name.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false) {
            fclose($handle);
            throw new ValidationException(['file' => ['The file appears to be empty.']]);
        }

        $definitions = $this->customFields->definitionsFor('contact');
        $columnMap = $this->mapColumns($headerRow, $definitions);

        $rows = [];
        $rowNumber = 1;

        while (($raw = fgetcsv($handle)) !== false && count($rows) < self::MAX_ROWS) {
            $rowNumber++;

            if ($raw === [null] || $raw === []) {
                continue; // blank line
            }

            $rows[] = $this->buildPreviewRow($raw, $columnMap, $definitions, $rowNumber);
        }

        fclose($handle);

        return ['rows' => $rows, 'customFieldDefs' => $definitions];
    }

    /**
     * @return array<int, array{type: string, key: string}> column
     *   index => ['type' => 'core'|'custom', 'key' => field key]
     */
    private function mapColumns(array $headerRow, array $definitions): array
    {
        $coreByLabel = [];
        foreach (self::CORE_COLUMNS as $label => $key) {
            $coreByLabel[strtolower($label)] = $key;
        }

        $customByLabel = [];
        foreach ($definitions as $def) {
            $customByLabel[strtolower($def['label'])] = $def['field_key'];
        }

        $map = [];
        foreach ($headerRow as $index => $rawLabel) {
            $label = strtolower(trim((string) $rawLabel));

            if (isset($coreByLabel[$label])) {
                $map[$index] = ['type' => 'core', 'key' => $coreByLabel[$label]];
            } elseif (isset($customByLabel[$label])) {
                $map[$index] = ['type' => 'custom', 'key' => $customByLabel[$label]];
            }
            // Unrecognized columns are silently ignored, not an error —
            // a spreadsheet with extra notes columns shouldn't block import.
        }

        return $map;
    }

    private function buildPreviewRow(array $raw, array $columnMap, array $definitions, int $rowNumber): array
    {
        $data = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'company' => '',
            'position' => '', 'address' => '', 'city' => '', 'country' => '', 'source' => '', 'status' => 'active', 'tags' => ''];
        $custom = [];

        foreach ($columnMap as $index => $target) {
            $value = trim((string) ($raw[$index] ?? ''));

            if ($target['type'] === 'core') {
                $data[$target['key']] = $value;
            } else {
                $custom[$target['key']] = $value;
            }
        }

        $errors = [];
        if ($data['first_name'] === '') {
            $errors[] = 'First name is required.';
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is not valid.';
        }

        $action = 'create';
        $existingId = null;
        if ($data['email'] !== '') {
            $existing = $this->contacts->findByEmail($data['email']);
            if ($existing !== null) {
                $action = 'update';
                $existingId = (int) $existing['id'];
            }
        }

        $companyNote = null;
        if ($data['company'] !== '') {
            $match = $this->findCompanyByName($data['company']);
            $companyNote = $match !== null ? "Will link to existing company \"{$match['name']}\"" : 'Will create a new company';
        }

        return [
            'row_number' => $rowNumber,
            'data' => $data,
            'custom' => $custom,
            'errors' => $errors,
            'action' => $action,
            'existing_id' => $existingId,
            'company_note' => $companyNote,
        ];
    }

    private function findCompanyByName(string $name): ?array
    {
        foreach ($this->companies->search(['search' => $name]) as $candidate) {
            if (strcasecmp($candidate['name'], $name) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    // --- Import: commit -----------------------------------------------------

    /**
     * @param array $rows rows[i] = ['include' => '1'|null, 'data' => [...], 'custom' => [...], 'action' => ..., 'existing_id' => ...]
     * @return array{created: int, updated: int, skipped: int, errors: string[]}
     */
    public function commit(array $actingUser, array $rows): array
    {
        $this->authorize($actingUser, Permission::CONTACTS_IMPORT);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            if (empty($row['include'])) {
                $skipped++;
                continue;
            }

            $data = $row['data'] ?? [];
            $firstName = trim((string) ($data['first_name'] ?? ''));
            $email = trim((string) ($data['email'] ?? ''));

            if ($firstName === '') {
                $errors[] = 'Row ' . ($i + 1) . ': first name is required — skipped.';
                $skipped++;
                continue;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Row ' . ($i + 1) . ': invalid email — skipped.';
                $skipped++;
                continue;
            }

            $companyId = $this->resolveCompanyId(trim((string) ($data['company'] ?? '')), (int) $actingUser['id']);

            $fields = array_filter([
                'first_name' => $firstName,
                'last_name' => $this->orNull($data, 'last_name'),
                'email' => $email !== '' ? $email : null,
                'phone' => $this->orNull($data, 'phone'),
                'company_id' => $companyId,
                'position' => $this->orNull($data, 'position'),
                'address' => $this->orNull($data, 'address'),
                'city' => $this->orNull($data, 'city'),
                'country' => $this->orNull($data, 'country'),
                'source' => $this->orNull($data, 'source'),
            ], fn ($v) => $v !== null);

            $existingId = !empty($row['existing_id']) ? (int) $row['existing_id'] : null;
            // Re-check by email at commit time too — the preview may be
            // stale if another import/edit happened in between.
            if ($existingId === null && $email !== '') {
                $existing = $this->contacts->findByEmail($email);
                $existingId = $existing !== null ? (int) $existing['id'] : null;
            }

            if ($existingId !== null) {
                $this->contacts->updateContact($existingId, $fields);
                $contactId = $existingId;
                $updated++;
                $this->timeline->record('contact', $contactId, 'updated', 'Updated via CSV import', (int) $actingUser['id']);
            } else {
                $fields['owner_id'] = (int) $actingUser['id'];
                $contactId = $this->contacts->create($fields);
                $created++;
                $this->timeline->record('contact', $contactId, 'created', 'Created via CSV import', (int) $actingUser['id']);
            }

            $this->applyTags($contactId, (string) ($data['tags'] ?? ''), (int) $actingUser['id']);

            if (!empty($row['custom']) && is_array($row['custom'])) {
                $this->customFields->saveValues('contact', $contactId, $row['custom']);
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function resolveCompanyId(string $companyName, int $actingUserId): ?int
    {
        if ($companyName === '') {
            return null;
        }

        $existing = $this->findCompanyByName($companyName);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->companies->create(['name' => $companyName, 'owner_id' => $actingUserId]);
    }

    private function applyTags(int $contactId, string $tagsField, int $actorUserId): void
    {
        $names = array_filter(array_map('trim', explode(',', $tagsField)));

        foreach ($names as $name) {
            $this->tags->attach('contact', $contactId, $name, $actorUserId);
        }
    }

    private function orNull(array $data, string $key): ?string
    {
        $value = trim((string) ($data[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
