<?php

declare(strict_types=1);

namespace Modules\Followups\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\LeadRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Followups\Services\FollowUpService;

use function App\Infrastructure\Theme\t;

final class FollowUpController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly FollowUpService $followUps,
        private readonly UserRepository $users,
        private readonly ContactRepository $contacts,
        private readonly LeadRepository $leads,
        private readonly ?RecordTypeRegistry $recordTypes = null,
    ) {
        parent::__construct($templates);
    }

    /**
     * Other modules' record types a follow-up can be created for, from
     * that record's page. Read ONLY through RecordTypeRegistry::find():
     * the owning module must be available to the user, who must be allowed
     * to view that very record (record scope included) in their own
     * organization. Followups never touches those modules' tables.
     */
    public const REGISTRY_RELATED_TYPES = ['contract', 'project'];

    public function index(Request $request): Response
    {
        $filters = [];
        if ($request->query('status') !== null && $request->query('status') !== '') {
            $filters['status'] = $request->query('status');
        }
        if ($request->query('overdue') === '1') {
            $filters['overdue_only'] = true;
        }

        [$followUps, $pagination] = Pagination::slice($this->followUps->list(CurrentUser::user(), $filters), $request);

        return $this->viewWithLayout('followups/index', 'layout', [
            'followUps' => $followUps,
            'canDelete' => $this->followUps->deletableIds(CurrentUser::user(), $followUps),
            'pagination' => $pagination,
            'statusFilter' => (string) $request->query('status', ''),
            'overdueFilter' => $request->query('overdue') === '1',
        ]);
    }

    /**
     * ?related_type=contact|lead&related_id=N preselects the record the
     * follow-up is for (the "New follow-up" buttons on contact/lead pages).
     * ?related_type=contract|project&related_id=N (the Follow-ups panel on
     * those pages) fixes the link — and is checked here on the server: a
     * record this user can't view is a 404, never a prefilled form.
     */
    public function showCreate(Request $request): Response
    {
        $old = [];
        $type = (string) $request->query('related_type', '');
        $id = (int) $request->query('related_id', 0);
        if (in_array($type, self::REGISTRY_RELATED_TYPES, true)) {
            if ($this->registryRecord($type, $id) === null) {
                throw new NotFoundException();
            }
            $old['related_type'] = $type;
            $old['related_id'] = $id;
        } elseif ($this->relatedRecord($type, $id) !== null) {
            $old['related_' . $type . '_id'] = $id;
        }

        return $this->form(null, [], $old);
    }

    public function create(Request $request): Response
    {
        try {
            $followUp = $this->followUps->create(CurrentUser::user(), $this->withRelated($request->all()));
        } catch (ValidationException $e) {
            return $this->form(null, $e->errors, $request->all());
        }

        Session::setFlash('success', t('followups.flash.created'));

        return $this->redirect('/followups/' . $followUp['id']);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        $followUp = $this->followUps->find(CurrentUser::user(), $id);

        return $this->viewWithLayout('followups/show', 'layout', [
            'followUp' => $followUp,
            'relatedLabel' => $this->relatedLabel((string) ($followUp['related_type'] ?? ''), (int) ($followUp['related_id'] ?? 0)),
        ]);
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->form($this->followUps->find(CurrentUser::user(), $id), [], []);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $existing = $this->followUps->find(CurrentUser::user(), $id);
            $this->followUps->update(CurrentUser::user(), $id, $this->withRelated($request->all(), $existing));
        } catch (ValidationException $e) {
            return $this->form($this->followUps->find(CurrentUser::user(), $id), $e->errors, $request->all());
        }

        Session::setFlash('success', t('followups.flash.updated'));

        return $this->redirect('/followups/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->followUps->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('followups.flash.deleted'));

        return $this->redirect('/followups');
    }

    /** Bulk delete from the list page; returns to the same filtered page. */
    public function bulkDelete(Request $request): Response
    {
        $result = $this->followUps->bulkDelete(CurrentUser::user(), (array) $request->input('ids', []));
        Session::setFlash(
            $result['deleted'] > 0 ? 'success' : 'error',
            t('bulk.deleted_summary', ['deleted' => $result['deleted'], 'skipped' => $result['skipped']])
        );

        $back = (string) $request->input('return_to', '/followups');
        if (!str_starts_with($back, '/followups') || str_contains($back, '//')) {
            $back = '/followups';
        }

        return $this->redirect($back);
    }

    public function complete(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->followUps->complete(CurrentUser::user(), $id);
        Session::setFlash('success', t('followups.flash.completed'));

        return $this->redirect('/followups/' . $id);
    }

    public function cancel(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->followUps->cancel(CurrentUser::user(), $id);
        Session::setFlash('success', t('followups.flash.cancelled'));

        return $this->redirect('/followups/' . $id);
    }

    public function reschedule(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->followUps->reschedule(CurrentUser::user(), $id, (string) $request->input('due_at', ''));
            Session::setFlash('success', t('followups.flash.rescheduled'));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? t('followups.flash.reschedule_failed'));
        }

        return $this->redirect('/followups/' . $id);
    }

    /**
     * The form's two selects (related_contact_id, related_lead_id) become
     * related_type/related_id — a follow-up links to one record, so at
     * most one of them may be picked. Only contacts and leads of the
     * current organization are accepted (both repositories are
     * tenant-scoped). Without those fields — the form hides them for a
     * follow-up linked to something else, e.g. a deal by automation —
     * the existing link is kept as is.
     */
    private function withRelated(array $input, ?array $existing = null): array
    {
        // New follow-up for a contract/project (hidden fields from the form
        // opened on that record's page): re-validated here, never trusted.
        // Any other raw related_type on create is refused; on edit the saved
        // link is kept as is (below), whatever the request says.
        $rawType = (string) ($input['related_type'] ?? '');
        if ($existing === null && $rawType !== '') {
            if (!in_array($rawType, self::REGISTRY_RELATED_TYPES, true)) {
                throw new ValidationException(['related' => [t('followups.related_invalid')]]);
            }
            if ((int) ($input['related_contact_id'] ?? 0) > 0 || (int) ($input['related_lead_id'] ?? 0) > 0) {
                throw new ValidationException(['related' => [t('followups.related_one_only')]]);
            }
            $id = (int) ($input['related_id'] ?? 0);
            if ($this->registryRecord($rawType, $id) === null) {
                throw new ValidationException(['related' => [t('followups.related_invalid')]]);
            }
            $input['related_type'] = $rawType;
            $input['related_id'] = $id;

            return $input;
        }

        if (!array_key_exists('related_contact_id', $input) && !array_key_exists('related_lead_id', $input)) {
            $input['related_type'] = $existing['related_type'] ?? '';
            $input['related_id'] = $existing['related_id'] ?? '';

            return $input;
        }

        $contactId = (int) ($input['related_contact_id'] ?? 0);
        $leadId = (int) ($input['related_lead_id'] ?? 0);
        $input['related_type'] = '';
        $input['related_id'] = '';

        if ($contactId > 0 && $leadId > 0) {
            throw new ValidationException(['related' => [t('followups.related_one_only')]]);
        }

        if ($contactId > 0 || $leadId > 0) {
            [$type, $id] = $contactId > 0 ? ['contact', $contactId] : ['lead', $leadId];
            if ($this->relatedRecord($type, $id) === null) {
                throw new ValidationException(['related' => [t('followups.related_invalid')]]);
            }
            $input['related_type'] = $type;
            $input['related_id'] = $id;
        }

        return $input;
    }

    private function relatedRecord(string $type, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return match ($type) {
            'contact' => $this->contacts->findById($id),
            'lead' => $this->leads->findById($id),
            default => null,
        };
    }

    /** A contract/project row this user may view (module available, view permission, organization, record scope), else null. */
    private function registryRecord(string $type, int $id): ?array
    {
        if ($id <= 0 || $this->recordTypes === null || !in_array($type, self::REGISTRY_RELATED_TYPES, true)) {
            return null;
        }

        return $this->recordTypes->find($type, $id, CurrentUser::user());
    }

    /**
     * @return array{url: ?string, name: string, type: string}|null url null =
     *   the record isn't viewable any more (module off, gone, out of scope)
     */
    private function relatedLabel(string $type, int $id): ?array
    {
        if (in_array($type, self::REGISTRY_RELATED_TYPES, true) && $id > 0) {
            $record = $this->registryRecord($type, $id);

            return [
                'url' => $record !== null ? $this->recordTypes->get($type)?->showUrl($id) : null,
                'name' => $record !== null ? (string) ($record['title'] ?? $record['name'] ?? ('#' . $id)) : '#' . $id,
                'type' => $type,
            ];
        }

        $record = $this->relatedRecord($type, $id);
        if ($record === null) {
            return null;
        }

        return [
            'url' => '/' . $type . 's/' . $id,
            'name' => trim($record['first_name'] . ' ' . ($record['last_name'] ?? '')),
            'type' => $type,
        ];
    }

    private function form(?array $followUp, array $errors, array $old): Response
    {
        $linkedElsewhere = $followUp !== null && !empty($followUp['related_type'])
            && !in_array($followUp['related_type'], ['contact', 'lead'], true);

        // New follow-up fixed to a contract/project: the label is re-derived
        // on the server (never from the request); if the record isn't
        // viewable the fixed link is dropped and the normal pickers show.
        $relatedFixed = null;
        $fixedType = (string) ($old['related_type'] ?? '');
        if ($followUp === null && in_array($fixedType, self::REGISTRY_RELATED_TYPES, true)) {
            $label = $this->relatedLabel($fixedType, (int) ($old['related_id'] ?? 0));
            $relatedFixed = $label !== null && $label['url'] !== null ? $label + ['id' => (int) $old['related_id']] : null;
        }
        $hideSelects = $linkedElsewhere || $relatedFixed !== null;

        return $this->viewWithLayout('followups/form', 'layout', [
            'followUp' => $followUp,
            'relatedFixed' => $relatedFixed,
            'relatedEditable' => !$hideSelects,
            'contactOptions' => $hideSelects ? [] : $this->contacts->search([]),
            'leadOptions' => $hideSelects ? [] : $this->leads->search([]),
            // Only assignees the service accepts: active users of the
            // organization, plus the one already saved on this follow-up.
            'users' => array_values(array_filter(
                $this->users->findAllInOrganization((int) CurrentUser::user()['organization_id']),
                fn (array $u) => ($u['status'] ?? '') === 'active' || (int) $u['id'] === (int) ($followUp['assignee_user_id'] ?? 0)
            )),
            'errors' => $errors,
            'old' => $old,
        ], $errors === [] ? 200 : 422);
    }
}
