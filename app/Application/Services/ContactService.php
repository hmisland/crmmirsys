<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\ContactEmailRepository;
use App\Infrastructure\Repositories\ContactInterestRepository;
use App\Infrastructure\Repositories\ContactPhoneRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\ContactSocialProfileRepository;
use App\Infrastructure\Validation\Validator;

/**
 * Per the post-launch UX correction: the single most-used phone
 * (mobile), landline+extension, and email live as plain quick fields
 * directly on the main "Details" tab — not one click away — while the
 * "Phones & Emails" tab is only for anything ADDITIONAL beyond those
 * three (a second mobile, a fax line, a second email, ...). The quick
 * mobile is always the one synced into contacts.phone (legacy column,
 * used by search/CSV/duplicate-detection); the quick email likewise
 * syncs into contacts.email.
 */
final class ContactService
{
    private const SOCIAL_PLATFORMS = ['facebook', 'instagram', 'youtube', 'twitter', 'linkedin', 'telegram', 'whatsapp', 'custom'];

    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly ContactPhoneRepository $phones,
        private readonly ContactEmailRepository $emails,
        private readonly ContactSocialProfileRepository $socialProfiles,
        private readonly ContactInterestRepository $interests,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function list(array $actingUser, array $filters = []): array
    {
        $this->authorize($actingUser, Permission::CONTACTS_VIEW);

        return $this->contacts->search($filters);
    }

    public function find(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::CONTACTS_VIEW);

        return $this->requireContact($id);
    }

    /** @return array{phones: array, emails: array, social: array, interests: array} */
    public function relatedData(int $contactId): array
    {
        return [
            'phones' => $this->phones->forContact($contactId),
            'emails' => $this->emails->forContact($contactId),
            'social' => $this->socialProfiles->forContact($contactId),
            'interests' => $this->interests->forContact($contactId),
        ];
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::CONTACTS_CREATE);

        $validator = Validator::make($input, ['first_name' => 'required|min:1']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        // The real Contact form always includes this hidden sentinel;
        // the contact detail page's small "save custom fields" quick-
        // form (only ever first_name + custom[]) does not — without
        // this distinction that quick-save would silently wipe every
        // phone/email/social profile on the contact, since the
        // collections below are a full replace, not a merge. A
        // request that doesn't carry the real form doesn't touch them.
        $touchesContactInfo = array_key_exists('full_contact_form', $input);

        $phones = $touchesContactInfo ? $this->buildPhones($input) : [];
        $emails = $touchesContactInfo ? $this->buildEmails($input) : [];

        $fields = $this->fieldsFromInput($input);
        $fields['owner_id'] = (int) $actingUser['id'];
        if ($touchesContactInfo) {
            $fields['phone'] = $this->primaryValue($phones, 'number');
            $fields['email'] = $this->primaryValue($emails, 'email');
        }

        $id = $this->contacts->create($fields);

        if ($touchesContactInfo) {
            $this->phones->replaceForContact($id, $phones);
            $this->emails->replaceForContact($id, $emails);
            $this->socialProfiles->replaceForContact($id, $this->extractSocialProfiles($input));
            $this->interests->replaceForContact($id, $this->extractInterests($input));
        }

        $this->timeline->record('contact', $id, 'created', 'Contact created', (int) $actingUser['id']);

        return $this->contacts->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $contact = $this->requireContact($id);
        $this->authorizeRecord($actingUser, Permission::CONTACTS_EDIT, $contact);

        $validator = Validator::make($input, ['first_name' => 'required|min:1']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $touchesContactInfo = array_key_exists('full_contact_form', $input);

        $phones = $touchesContactInfo ? $this->buildPhones($input) : [];
        $emails = $touchesContactInfo ? $this->buildEmails($input) : [];

        $fields = $this->fieldsFromInput($input);
        if ($touchesContactInfo) {
            $fields['phone'] = $this->primaryValue($phones, 'number');
            $fields['email'] = $this->primaryValue($emails, 'email');
        }

        $this->contacts->updateContact($id, $fields);

        if ($touchesContactInfo) {
            $this->phones->replaceForContact($id, $phones);
            $this->emails->replaceForContact($id, $emails);
            $this->socialProfiles->replaceForContact($id, $this->extractSocialProfiles($input));
            $this->interests->replaceForContact($id, $this->extractInterests($input));
        }

        $this->timeline->record('contact', $id, 'updated', 'Contact details updated', (int) $actingUser['id']);

        return $this->contacts->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $contact = $this->requireContact($id);
        $this->authorizeRecord($actingUser, Permission::CONTACTS_DELETE, $contact);

        $this->contacts->deleteContact($id);
    }

    /**
     * The quick mobile (always primary) + quick landline/extension,
     * plus anything from the "additional phone numbers" list.
     *
     * @return array<int, array{type: string, number: string, extension: ?string, is_primary: bool}>
     */
    private function buildPhones(array $input): array
    {
        $result = [];

        $mobile = trim((string) ($input['mobile'] ?? ''));
        if ($mobile !== '') {
            $result[] = ['type' => 'mobile', 'number' => $mobile, 'extension' => null, 'is_primary' => true];
        }

        $landline = trim((string) ($input['landline'] ?? ''));
        if ($landline !== '') {
            $extension = trim((string) ($input['landline_extension'] ?? ''));
            $result[] = [
                'type' => 'landline',
                'number' => $landline,
                'extension' => $extension !== '' ? $extension : null,
                'is_primary' => false,
            ];
        }

        $additional = is_array($input['phones'] ?? null) ? $input['phones'] : [];
        foreach ($additional as $row) {
            $number = trim((string) ($row['number'] ?? ''));
            if ($number === '') {
                continue;
            }

            $type = in_array($row['type'] ?? null, ['mobile', 'landline'], true) ? $row['type'] : 'mobile';
            $result[] = ['type' => $type, 'number' => $number, 'extension' => null, 'is_primary' => false];
        }

        // No mobile entered at all but there IS a landline or an
        // additional number — make the first phone present primary,
        // so contacts.phone (the legacy sync column) is never left
        // empty when the contact actually has a number on file.
        if ($result !== [] && !array_filter($result, fn ($r) => $r['is_primary'])) {
            $result[0]['is_primary'] = true;
        }

        return $result;
    }

    /**
     * The quick email (always primary) plus anything from the
     * "additional email addresses" list.
     *
     * @return array<int, array{email: string, is_primary: bool}>
     */
    private function buildEmails(array $input): array
    {
        $result = [];

        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '') {
            $result[] = ['email' => $email, 'is_primary' => true];
        }

        $additional = is_array($input['emails'] ?? null) ? $input['emails'] : [];
        foreach ($additional as $row) {
            $value = trim((string) ($row['email'] ?? ''));
            if ($value === '') {
                continue;
            }

            $result[] = ['email' => $value, 'is_primary' => false];
        }

        if ($result !== [] && !array_filter($result, fn ($r) => $r['is_primary'])) {
            $result[0]['is_primary'] = true;
        }

        return $result;
    }

    /**
     * @return array<int, array{platform: string, custom_label: ?string, value: string}>
     */
    private function extractSocialProfiles(array $input): array
    {
        $rows = is_array($input['social'] ?? null) ? $input['social'] : [];

        $result = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $platform = in_array($row['platform'] ?? null, self::SOCIAL_PLATFORMS, true) ? $row['platform'] : 'custom';
            $customLabel = $platform === 'custom' ? trim((string) ($row['custom_label'] ?? '')) : '';

            $result[] = [
                'platform' => $platform,
                'custom_label' => $customLabel !== '' ? $customLabel : null,
                'value' => $value,
            ];
        }

        return $result;
    }

    private function primaryValue(array $rows, string $valueKey): ?string
    {
        foreach ($rows as $row) {
            if ($row['is_primary']) {
                return $row[$valueKey];
            }
        }

        return null;
    }

    /**
     * @return string[] trimmed, deduped, empty entries dropped
     */
    private function extractInterests(array $input): array
    {
        $raw = (string) ($input['interests'] ?? '');

        $items = array_filter(array_map('trim', explode(',', $raw)));

        return array_values(array_unique($items));
    }

    private function fieldsFromInput(array $input): array
    {
        return array_filter([
            'first_name' => trim((string) $input['first_name']),
            'last_name' => $this->orNull($input, 'last_name'),
            'gender' => in_array($input['gender'] ?? null, ['male', 'female'], true) ? $input['gender'] : null,
            'date_of_birth' => $this->orNull($input, 'date_of_birth'),
            'company_id' => isset($input['company_id']) && $input['company_id'] !== '' ? (int) $input['company_id'] : null,
            'category_id' => isset($input['category_id']) && $input['category_id'] !== '' ? (int) $input['category_id'] : null,
            'position' => $this->orNull($input, 'position'),
            'address' => $this->orNull($input, 'address'),
            'country' => $this->orNull($input, 'country'),
            'city' => $this->orNull($input, 'city'),
            'province' => $this->orNull($input, 'province'),
            'source' => $this->orNull($input, 'source'),
            'description' => $this->orNull($input, 'description'),
        ], fn ($v) => $v !== null);
    }

    private function orNull(array $input, string $key): ?string
    {
        $value = isset($input[$key]) ? trim((string) $input[$key]) : '';

        return $value === '' ? null : $value;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function authorizeRecord(array $actingUser, string $permission, array $record): void
    {
        if (!$this->authorization->can($actingUser, $permission, $record)) {
            throw new AuthorizationException();
        }
    }

    private function requireContact(int $id): array
    {
        $contact = $this->contacts->findById($id);

        if ($contact === null) {
            throw new NotFoundException('Contact not found.');
        }

        return $contact;
    }
}
