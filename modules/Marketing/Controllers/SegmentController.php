<?php

declare(strict_types=1);

namespace Modules\Marketing\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Marketing\Services\SegmentService;

use function App\Infrastructure\Theme\t;

/**
 * Marketing → Segments: list, create/edit (static or dynamic), view with
 * preview, static members (search, add, remove), delete. Thin: rules and
 * permissions are in SegmentService.
 */
final class SegmentController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly SegmentService $segments)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = CurrentUser::user();
        $filters = ['q' => trim((string) $request->query('q', '')), 'type' => (string) $request->query('type', '')];
        $total = $this->segments->page($user, $filters, 1, 0)['total'];
        $pagination = Pagination::fromRequest($request, $total);
        $page = $this->segments->page($user, $filters, $pagination->perPage, $pagination->offset());

        return $this->viewWithLayout('marketing/segments/index', 'layout', [
            'segments' => $page['rows'],
            'memberCounts' => $page['counts'],
            'filters' => $filters,
            'pagination' => $pagination,
            'canManage' => $this->segments->canManage($user),
            'canSeeContacts' => $this->segments->canSeeContacts($user),
        ]);
    }

    public function create(Request $request): Response
    {
        $type = (string) $request->query('type', 'dynamic') === 'static' ? 'static' : 'dynamic';

        return $this->form(null, ['type' => $type, 'rules_json' => ''], []);
    }

    public function store(Request $request): Response
    {
        $user = CurrentUser::user();
        $input = $request->all();
        if ((string) $request->input('action', '') === 'preview') {
            return $this->form(null, $input, [], 200, $this->formPreview($input));
        }

        try {
            $id = $this->segments->create($user, $input);
        } catch (ValidationException $e) {
            return $this->form(null, $input, $e->errors, 422);
        }
        Session::setFlash('success', t('marketing.segment.flash.saved'));

        return $this->redirect('/marketing/segments/' . $id);
    }

    public function show(Request $request): Response
    {
        $user = CurrentUser::user();
        $segment = $this->segments->find($user, (int) $request->attribute('id'));
        $page = max(1, (int) $request->query('page', 1));
        $preview = $this->segments->preview($user, (int) $segment['id'], null, $page);
        $canManage = $this->segments->canManage($user);
        $canSeeContacts = $this->segments->canSeeContacts($user);

        $search = null;
        $q = trim((string) $request->query('q', ''));
        if ($segment['type'] === 'static' && $canManage && $canSeeContacts && $request->query('find') !== null) {
            $search = $this->segments->searchContacts($user, (int) $segment['id'], $q, max(1, (int) $request->query('find_page', 1)));
        }

        return $this->viewWithLayout('marketing/segments/show', 'layout', [
            'segment' => $segment,
            'rulesSummary' => $segment['type'] === 'dynamic' ? $this->describe((array) $segment['rules_array'], $user) : [],
            'preview' => $preview,
            'previewPage' => $page,
            'previewPages' => max(1, (int) ceil($preview['total'] / SegmentService::PREVIEW_PAGE)),
            'canManage' => $canManage,
            'canEdit' => $canManage && !$this->segments->rulesLocked($user, $segment),
            'canSeeContacts' => $canSeeContacts,
            'search' => $search,
            'q' => $q,
            'findPage' => max(1, (int) $request->query('find_page', 1)),
        ]);
    }

    public function edit(Request $request): Response
    {
        $segment = $this->segments->find(CurrentUser::user(), (int) $request->attribute('id'));
        if ($this->segments->rulesLocked(CurrentUser::user(), $segment)) {
            // The form would carry the personal-field values of the rules.
            throw new AuthorizationException();
        }
        $old = $segment;
        $old['rules_json'] = (string) ($segment['rules'] ?? '');

        return $this->form($segment, $old, []);
    }

    public function update(Request $request): Response
    {
        $user = CurrentUser::user();
        $id = (int) $request->attribute('id');
        $segment = $this->segments->find($user, $id);
        $input = $request->all();
        if ((string) $request->input('action', '') === 'preview') {
            return $this->form($segment, $input + ['type' => $segment['type']], [], 200, $this->formPreview($input + ['type' => $segment['type']]));
        }

        try {
            $this->segments->update($user, $id, $input);
        } catch (ValidationException $e) {
            return $this->form($segment, $input + ['type' => $segment['type']], $e->errors, 422);
        }
        Session::setFlash('success', t('marketing.segment.flash.saved'));

        return $this->redirect('/marketing/segments/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->segments->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('marketing.segment.flash.deleted'));

        return $this->redirect('/marketing/segments');
    }

    public function addMembers(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $added = $this->segments->addMembers(CurrentUser::user(), $id, (array) $request->input('contact_ids', []));
        Session::setFlash('success', t('marketing.segment.flash.members_added', ['count' => (string) $added]));

        return $this->redirect('/marketing/segments/' . $id);
    }

    public function removeMembers(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $removed = $this->segments->removeMembers(CurrentUser::user(), $id, (array) $request->input('contact_ids', []));
        Session::setFlash('success', t('marketing.segment.flash.members_removed', ['count' => (string) $removed]));

        return $this->redirect('/marketing/segments/' . $id);
    }

    /** Preview of unsaved builder rules (dynamic segments). */
    private function formPreview(array $input): ?array
    {
        if (($input['type'] ?? '') !== 'dynamic') {
            return null;
        }
        $user = CurrentUser::user();
        $checked = $this->segments->checkRules($user, $input['rules_json'] ?? null);
        if ($checked['rules'] === null) {
            return ['errors' => $checked['errors']];
        }

        return $this->segments->preview($user, null, $checked['rules']) + ['errors' => []];
    }

    private function form(?array $segment, array $old, array $errors, int $status = 200, ?array $preview = null): Response
    {
        $user = CurrentUser::user();
        $type = (string) ($old['type'] ?? 'dynamic') === 'static' ? 'static' : 'dynamic';

        return $this->viewWithLayout('marketing/segments/form', 'layout', [
            'segment' => $segment,
            'old' => $old,
            'type' => $type,
            'errors' => $errors,
            'preview' => $preview,
            'builder' => $type === 'dynamic' ? $this->builder($user, (string) ($old['rules_json'] ?? '')) : null,
            'canSeeContacts' => $this->segments->canSeeContacts($user),
        ], $status);
    }

    /** Everything the rule builder script needs, all labels already translated. */
    private function builder(array $user, string $rulesJson): array
    {
        $fields = [];
        foreach ($this->segments->builderSchema($user) as $field) {
            $fields[] = [
                'key' => $field['key'],
                'label' => t($field['label']),
                'category' => t($field['category']),
                'ops' => array_map(fn ($op, $type) => ['key' => $op, 'label' => t('marketing.segment.op.' . $op), 'type' => $type], array_keys($field['ops']), $field['ops']),
                'choices' => array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $this->label($label)], array_keys($field['choices']), $field['choices']),
            ];
        }
        $decoded = json_decode($rulesJson, true);

        return [
            'fields' => $fields,
            'rules' => is_array($decoded) ? $decoded : ['match' => 'all', 'groups' => [['match' => 'all', 'conditions' => [['field' => 'status', 'op' => 'eq', 'value' => 'active']]]]],
            'i18n' => [
                'addCondition' => t('marketing.segment.builder.add_condition'),
                'addGroup' => t('marketing.segment.builder.add_group'),
                'remove' => t('marketing.segment.builder.remove'),
                'matchAll' => t('marketing.segment.builder.match_all'),
                'matchAny' => t('marketing.segment.builder.match_any'),
                'groupsAll' => t('marketing.segment.builder.groups_all'),
                'groupsAny' => t('marketing.segment.builder.groups_any'),
                'group' => t('marketing.segment.builder.group'),
                'field' => t('marketing.segment.builder.field'),
                'operator' => t('marketing.segment.builder.operator'),
                'value' => t('marketing.segment.builder.value'),
            ],
        ];
    }

    /**
     * Human-readable rules for the segment page.
     *
     * @return list<array{match: string, conditions: list<string>}>
     */
    private function describe(array $rules, array $user): array
    {
        $hidePersonal = !$this->segments->canSeeContacts($user);
        $fields = [];
        foreach ($this->segments->builderSchema($user, true) as $field) {
            $fields[$field['key']] = $field;
        }

        $groups = [];
        foreach ((array) ($rules['groups'] ?? []) as $group) {
            $lines = [];
            foreach ((array) ($group['conditions'] ?? []) as $c) {
                $field = $fields[$c['field'] ?? ''] ?? null;
                if ($field === null) {
                    $lines[] = t('marketing.segment.unknown_rule');
                    continue;
                }
                $value = $c['value'] ?? null;
                if ($value !== null && $field['personal'] && $hidePersonal) {
                    $value = '•••'; // a name/email/phone typed into the rule is personal data too
                } elseif ($value !== null && $field['choices'] !== []) {
                    $value = isset($field['choices'][(string) $value]) ? $this->label($field['choices'][(string) $value]) : t('marketing.segment.removed_value');
                }
                $lines[] = trim(t($field['label']) . ' ' . t('marketing.segment.op.' . ($c['op'] ?? '')) . ' ' . ($value !== null ? '«' . $value . '»' : ''));
            }
            $groups[] = ['match' => ($group['match'] ?? 'all') === 'any' ? 'any' : 'all', 'conditions' => $lines];
        }

        return ['match' => ($rules['match'] ?? 'all') === 'any' ? 'any' : 'all', 'groups' => $groups];
    }

    private function label(string $label): string
    {
        return str_starts_with($label, 't:') ? t(substr($label, 2)) : $label;
    }
}
