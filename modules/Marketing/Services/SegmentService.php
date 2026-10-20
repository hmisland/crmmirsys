<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\MarketingPermission;
use Modules\Marketing\SegmentRepository;
use Modules\Marketing\Segments\SegmentConditionRegistry;
use Modules\Marketing\Segments\SegmentPreviewRepository;
use Modules\Marketing\Segments\SegmentRules;

/**
 * Saved audiences (Phase 2).
 *
 * Permissions (re-checked here; routes are only a first gate):
 *  - marketing.view: list, view, see counts, use in campaigns;
 *  - marketing.manage_segments: create, edit, delete, change members;
 *  - contacts.view: additionally needed to see contact rows in previews,
 *    to add/remove static members (that means seeing contacts), and to
 *    write rules on personal fields (name, email, phone) — otherwise a
 *    count could be used to probe someone's email/number.
 *
 * Every read/write is in the current organization (repositories); deleting
 * is soft, so campaigns that used a segment keep their history.
 */
final class SegmentService
{
    public const PREVIEW_PAGE = 25;

    public function __construct(
        private readonly SegmentRepository $segments,
        private readonly SegmentRules $rules,
        private readonly SegmentConditionRegistry $registry,
        private readonly SegmentPreviewRepository $preview,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
    ) {
    }

    public function canManage(array $user): bool
    {
        return $this->authorization->can($user, MarketingPermission::MANAGE_SEGMENTS);
    }

    public function canSeeContacts(array $user): bool
    {
        return $this->authorization->can($user, Permission::CONTACTS_VIEW);
    }

    /**
     * A dynamic segment with rules on personal fields (name, email, phone)
     * is closed to users without contacts.view: they may not edit it, and
     * the values in those rules are not shown to them.
     */
    public function rulesLocked(array $user, array $segment): bool
    {
        return $segment['type'] === 'dynamic' && !$this->canSeeContacts($user)
            && $this->rules->usesPersonalData((array) ($segment['rules_array'] ?? json_decode((string) ($segment['rules'] ?? ''), true)));
    }

    /** @return array{rows: array, total: int, counts: array<int, int>} */
    public function page(array $user, array $filters, int $limit, int $offset): array
    {
        $this->authorize($user, MarketingPermission::VIEW);
        $rows = $this->segments->page($filters, $limit, $offset);
        $static = array_column(array_filter($rows, fn ($r) => $r['type'] === 'static'), 'id');

        return ['rows' => $rows, 'total' => $this->segments->count($filters), 'counts' => $this->segments->memberCounts($static)];
    }

    public function options(array $user): array
    {
        $this->authorize($user, MarketingPermission::VIEW);

        return $this->segments->options();
    }

    public function find(array $user, int $id): array
    {
        $this->authorize($user, MarketingPermission::VIEW);
        $segment = $this->segments->find($id);
        if ($segment === null) {
            throw new NotFoundException('Segment not found.');
        }
        $segment['rules_array'] = $segment['type'] === 'dynamic' ? (array) json_decode((string) $segment['rules'], true) : null;

        return $segment;
    }

    /** @return int new id */
    public function create(array $user, array $input): int
    {
        $this->authorize($user, MarketingPermission::MANAGE_SEGMENTS);
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, ['static', 'dynamic'], true)) {
            throw new ValidationException(['type' => ['marketing.segment.error.type']]);
        }
        if ($type === 'static' && !$this->canSeeContacts($user)) {
            throw new ValidationException(['type' => ['marketing.segment.error.contacts_permission']]);
        }
        $data = $this->validated($user, $type, $input);

        $id = $this->segments->create($data + ['type' => $type, 'owner_id' => (int) $user['id'], 'updated_by_user_id' => (int) $user['id']]);
        $this->audit('marketing.segment_created', 'Segment created: ' . mb_substr($data['name'], 0, 150), $user);

        return $id;
    }

    public function update(array $user, int $id, array $input): void
    {
        $this->authorize($user, MarketingPermission::MANAGE_SEGMENTS);
        $segment = $this->find($user, $id);
        if ($this->rulesLocked($user, $segment)) {
            // Can't edit rules on personal fields they may not use themselves.
            throw new ValidationException(['rules' => ['marketing.segment.error.personal_fields']]);
        }
        $data = $this->validated($user, (string) $segment['type'], $input);
        $this->segments->updateSegment($id, $data + ['updated_by_user_id' => (int) $user['id']]);
        $this->audit('marketing.segment_updated', 'Segment edited: ' . mb_substr($data['name'], 0, 150), $user);
    }

    public function delete(array $user, int $id): void
    {
        $this->authorize($user, MarketingPermission::MANAGE_SEGMENTS);
        $segment = $this->find($user, $id);
        $this->segments->softDelete($id, (int) $user['id']);
        $this->audit('marketing.segment_deleted', 'Segment deleted: ' . mb_substr((string) $segment['name'], 0, 150), $user);
    }

    /** @param int[] $contactIds */
    public function addMembers(array $user, int $id, array $contactIds): int
    {
        $segment = $this->staticForMembers($user, $id);
        $added = $this->segments->addMembers((int) $segment['id'], $contactIds);
        $this->segments->updateSegment((int) $segment['id'], ['updated_by_user_id' => (int) $user['id']]);

        return $added;
    }

    /** @param int[] $contactIds */
    public function removeMembers(array $user, int $id, array $contactIds): int
    {
        $segment = $this->staticForMembers($user, $id);
        $removed = $this->segments->removeMembers((int) $segment['id'], $contactIds);
        $this->segments->updateSegment((int) $segment['id'], ['updated_by_user_id' => (int) $user['id']]);

        return $removed;
    }

    /**
     * Matching contacts of a saved segment (or of unsaved rules) — total
     * and one page of rows. Rows only for users with contacts.view;
     * everybody else gets the count.
     *
     * @return array{total: int, rows: array, rows_visible: bool}
     */
    public function preview(array $user, ?int $segmentId, ?array $rules, int $page = 1, bool $membersOnly = false): array
    {
        $this->authorize($user, MarketingPermission::VIEW);
        $visible = $this->canSeeContacts($user);
        $offset = max(0, $page - 1) * self::PREVIEW_PAGE;

        if ($rules !== null) {
            if (!$visible && $this->rules->usesPersonalData($rules)) {
                throw new ValidationException(['rules' => ['marketing.segment.error.personal_fields']]);
            }
            $total = $this->preview->countRules($rules);
            $rows = $visible ? $this->preview->rowsRules($rules, self::PREVIEW_PAGE, $offset) : [];
        } else {
            $total = $this->preview->countSegment((int) $segmentId);
            $rows = $visible ? $this->preview->rowsSegment((int) $segmentId, self::PREVIEW_PAGE, $offset) : [];
        }

        return ['total' => $total, 'rows' => $rows, 'rows_visible' => $visible];
    }

    /**
     * Contacts to add to a static segment (search by name/phone/email),
     * paginated — scalable member selection (no 2,000-contact picker).
     *
     * @return array{rows: array, total: int}
     */
    public function searchContacts(array $user, int $segmentId, string $q, int $page): array
    {
        $this->staticForMembers($user, $segmentId);

        return [
            'rows' => $this->preview->searchContacts($q, $segmentId, self::PREVIEW_PAGE, max(0, $page - 1) * self::PREVIEW_PAGE),
            'total' => $this->preview->countSearchContacts($q),
        ];
    }

    /** Validates rules from the builder without saving (form preview). @return array{rules: ?array, errors: string[]} */
    public function checkRules(array $user, mixed $input): array
    {
        $this->authorize($user, MarketingPermission::VIEW);
        $result = $this->rules->validate($input);
        if ($result['rules'] !== null && $result['personal'] && !$this->canSeeContacts($user)) {
            return ['rules' => null, 'errors' => ['marketing.segment.error.personal_fields']];
        }

        return ['rules' => $result['rules'], 'errors' => $result['errors']];
    }

    /**
     * Field definitions for the rule builder (labels resolved by the caller).
     *
     * @return list<array{key: string, label: string, category: string, personal: bool, ops: array<string, string>, choices: array<string, string>}>
     */
    public function builderSchema(array $user, bool $forLabelsOnly = false): array
    {
        // Labels for displaying saved rules may include personal fields; the builder itself may not.
        $visible = $forLabelsOnly || $this->canSeeContacts($user);
        $fields = [];
        foreach ($this->registry->all() as $condition) {
            if ($condition->isPersonalData() && !$visible) {
                continue;
            }
            $fields[] = [
                'key' => $condition->key(),
                'label' => $condition->labelKey(),
                'category' => $condition->categoryKey(),
                'personal' => $condition->isPersonalData(),
                'ops' => $condition->operators(),
                'choices' => $condition->choices(),
                'scopes' => $condition instanceof \Modules\Marketing\Segments\ScopedSegmentCondition ? $condition->scopes() : [],
            ];
        }

        return $fields;
    }

    /** @return array<string, mixed> columns */
    private function validated(array $user, string $type, array $input): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'][] = 'marketing.error.name_required';
        }
        if (mb_strlen($description) > 500) {
            $errors['description'][] = 'marketing.segment.error.description';
        }

        $data = ['name' => $name, 'description' => $description !== '' ? $description : null];
        if ($type === 'dynamic') {
            $checked = $this->checkRules($user, $input['rules_json'] ?? ($input['rules'] ?? null));
            if ($checked['rules'] === null) {
                $errors['rules'] = $checked['errors'];
            } else {
                $data['rules'] = (string) json_encode($checked['rules'], JSON_UNESCAPED_UNICODE);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    private function staticForMembers(array $user, int $id): array
    {
        $this->authorize($user, MarketingPermission::MANAGE_SEGMENTS);
        if (!$this->canSeeContacts($user)) {
            throw new AuthorizationException();
        }
        $segment = $this->find($user, $id);
        if ($segment['type'] !== 'static') {
            throw new ValidationException(['type' => ['marketing.segment.error.not_static']]);
        }

        return $segment;
    }

    private function audit(string $type, string $description, array $user): void
    {
        $this->timeline->record('organization', OrganizationContext::id(), $type, $description, (int) $user['id']);
    }

    private function authorize(array $user, string $permission): void
    {
        if (!$this->authorization->can($user, $permission)) {
            throw new AuthorizationException();
        }
    }
}
