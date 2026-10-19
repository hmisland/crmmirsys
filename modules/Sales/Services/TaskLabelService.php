<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use Modules\Sales\TaskLabelRepository;

use function App\Infrastructure\Theme\t;

/**
 * The organization's task labels (T4). Managing them (create, rename,
 * recolor, delete) needs tasks.edit at organization scope — the existing
 * "may edit every task of the organization" grant (org_admin by default);
 * no new permission. Putting labels on a task is TaskService::setLabels()
 * (tasks.edit on that task).
 */
final class TaskLabelService
{
    /** The fixed palette: keys only, mapped to theme tokens in app.css (.task-label--<key>). */
    public const COLORS = ['gray', 'blue', 'sky', 'green', 'orange', 'red'];

    private const MAX_NAME_LENGTH = 50;

    public function __construct(
        private readonly TaskLabelRepository $labels,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function canManage(array $actingUser): bool
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::TASKS_EDIT);

        return $scope === Scope::ALL || $scope === Scope::ORGANIZATION;
    }

    /** Every label of the organization (anyone who can see tasks sees their labels). */
    public function all(array $actingUser): array
    {
        if (!$this->authorization->can($actingUser, Permission::TASKS_VIEW)) {
            throw new AuthorizationException();
        }

        return $this->labels->all();
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorizeManage($actingUser);
        [$name, $color] = $this->validated($input);

        return $this->labels->findById($this->labels->create($name, $color, (int) $actingUser['id']));
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $this->authorizeManage($actingUser);
        $this->requireLabel($id);
        [$name, $color] = $this->validated($input, $id);
        $this->labels->updateLabel($id, ['name' => $name, 'color' => $color]);

        return $this->labels->findById($id);
    }

    /** Removes the label from every task (links cascade); tasks and contact tags are untouched. */
    public function delete(array $actingUser, int $id): void
    {
        $this->authorizeManage($actingUser);
        $this->requireLabel($id);
        $this->labels->deleteLabel($id);
    }

    /** @return array{0: string, 1: string} */
    private function validated(array $input, ?int $id = null): array
    {
        $errors = [];
        $name = is_string($input['name'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $input['name'])) : '';
        $color = $input['color'] ?? null;

        if ($name === '') {
            $errors['label_name'] = [t('tasks.error.label_name_required')];
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['label_name'] = [t('tasks.error.label_name_too_long')];
        } elseif ($this->labels->findByName($name, $id) !== null) {
            $errors['label_name'] = [t('tasks.error.label_name_taken')];
        }
        if (!is_string($color) || !in_array($color, self::COLORS, true)) {
            $errors['label_color'] = [t('tasks.error.label_color_invalid')];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$name, $color];
    }

    private function authorizeManage(array $actingUser): void
    {
        if (!$this->canManage($actingUser)) {
            throw new AuthorizationException();
        }
    }

    private function requireLabel(int $id): array
    {
        return $this->labels->findById($id) ?? throw new NotFoundException();
    }
}
