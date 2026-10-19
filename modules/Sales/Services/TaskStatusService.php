<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Sales\TaskStatusRepository;

use function App\Infrastructure\Theme\t;

/**
 * Custom task statuses (T8): each organization's own list, managed by
 * users with organization.edit.
 *
 * A status's lifecycle category is fixed at creation and alone decides
 * behavior — never its name. Each category writes a legacy value to
 * tasks.status (the organization's six original statuses keep exactly
 * theirs, via system_key), so the existing workflow, reopen permission,
 * reminders, recurrence and lists run unchanged:
 *   open → pending, in_progress → in_progress, completed → completed,
 *   cancelled → cancelled; archived = the task is archived (tasks.status
 *   unchanged).
 *
 * Safety: an organization always keeps at least one active open and one
 * active completed status (new tasks and the done checkbox need them); a
 * status still shown on tasks can only be deleted by moving those tasks to
 * another status of the same category — so no task changes behavior and
 * none is deleted.
 */
final class TaskStatusService
{
    public const CATEGORIES = ['open', 'in_progress', 'completed', 'cancelled', 'archived'];
    public const COLORS = TaskLabelService::COLORS;

    /** The six original statuses: system_key => [category, color, sort]. */
    public const DEFAULTS = [
        'pending' => ['open', 'gray', 10],
        'in_progress' => ['in_progress', 'blue', 20],
        'on_hold' => ['in_progress', 'orange', 30],
        'completed' => ['completed', 'green', 40],
        'rejected' => ['cancelled', 'red', 50],
        'cancelled' => ['cancelled', 'gray', 60],
    ];

    /** Categories that must always keep an active status. */
    private const REQUIRED = ['open', 'completed'];

    /** The badge tone for each palette color (the CRM's existing badge styles). */
    public const TONES = ['gray' => null, 'blue' => 'brand', 'sky' => 'info', 'green' => 'success', 'orange' => 'warning', 'red' => 'danger'];

    private const MAX_NAME_LENGTH = 50;

    public function __construct(
        private readonly TaskStatusRepository $statuses,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    // --- Reading ---------------------------------------------------------------

    /**
     * The current organization's statuses in order, each with its display
     * 'label' (an unnamed original shows its translated name). An
     * organization without any (created after migration 0013) gets the six
     * originals first.
     */
    public function all(): array
    {
        $rows = $this->statuses->all();
        if ($rows === [] && OrganizationContext::has()) {
            $this->statuses->atomically(function () {
                if ($this->statuses->all() === []) {
                    foreach (self::DEFAULTS as $key => [$category, $color, $sort]) {
                        $this->statuses->create(['color' => $color, 'category' => $category, 'sort_order' => $sort, 'is_active' => 1, 'system_key' => $key]);
                    }
                }
            });
            $rows = $this->statuses->all();
        }

        return array_map(fn (array $s) => $s + ['label' => self::label($s)], $rows);
    }

    /** @return array<int, array> by id */
    public function byId(): array
    {
        return array_column($this->all(), null, 'id');
    }

    public function active(): array
    {
        return array_values(array_filter($this->all(), fn (array $s) => (int) $s['is_active'] === 1));
    }

    public static function label(array $status): string
    {
        $name = trim((string) ($status['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $status['system_key'] !== null ? t('tasks.status.' . $status['system_key']) : '';
    }

    /** The tasks.status value a status writes; null for archived (the legacy value stays). */
    public static function legacyFor(array $status): ?string
    {
        if (!empty($status['system_key'])) {
            return (string) $status['system_key'];
        }

        return match ($status['category']) {
            'open' => 'pending',
            'in_progress' => 'in_progress',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => null,
        };
    }

    /**
     * The status a task shows: its chosen one, else the organization's
     * status for its legacy value; null when neither exists any more (the
     * legacy label is shown then).
     *
     * @param array<int, array> $byId
     */
    public static function effective(array $task, array $byId): ?array
    {
        if (!empty($task['status_id']) && isset($byId[(int) $task['status_id']])) {
            return $byId[(int) $task['status_id']];
        }
        foreach ($byId as $status) {
            if ($status['system_key'] === ($task['status'] ?? null)) {
                return $status;
            }
        }

        return null;
    }

    /** The first active status of a category, in the organization's order. */
    public function defaultFor(string $category): ?array
    {
        foreach ($this->active() as $status) {
            if ($status['category'] === $category) {
                return $status;
            }
        }

        return null;
    }

    /** The status of this organization with this id, or null (another organization's ids never resolve). */
    public function find(int $id): ?array
    {
        $row = $id > 0 ? $this->statuses->findById($id) : null;

        return $row !== null ? $row + ['label' => self::label($row)] : null;
    }

    // --- Managing ----------------------------------------------------------------

    public function canManage(array $actingUser): bool
    {
        return $this->authorization->can($actingUser, Permission::ORGANIZATION_EDIT);
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorizeManage($actingUser);
        $this->all(); // the originals exist first
        $name = $this->validatedName($input['name'] ?? null, null, true);
        $color = $this->validatedColor($input['color'] ?? null);
        $category = $input['category'] ?? null;
        if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
            throw new ValidationException(['status_category' => [t('tasks.error.status_category')]]);
        }

        return $this->find($this->statuses->create([
            'name' => $name, 'color' => $color, 'category' => $category, 'sort_order' => $this->statuses->nextSortOrder(),
            'is_active' => 1, 'created_by_user_id' => (int) $actingUser['id'],
        ]));
    }

    /**
     * Name, color and active flag (the forms send a hidden 0 before the
     * checkbox; absent = unchanged). The category never changes. An original
     * status may be given an empty name: it shows its translated name again.
     */
    public function update(array $actingUser, int $id, array $input): array
    {
        $this->authorizeManage($actingUser);
        $status = $this->requireStatus($id);
        $fields = [
            'name' => $this->validatedName($input['name'] ?? null, $id, $status['system_key'] === null),
            'color' => $this->validatedColor($input['color'] ?? null),
        ];
        if (array_key_exists('is_active', $input)) {
            $fields['is_active'] = in_array($input['is_active'], ['1', 1, true, 'on'], true) ? 1 : 0;
            if ($fields['is_active'] === 0 && (int) $status['is_active'] === 1) {
                $this->requireNotLastRequired($status);
            }
        }
        $this->statuses->updateStatus($id, $fields);

        return $this->find($id);
    }

    /** Swaps the status with its neighbour above ('up') or below ('down'). */
    public function move(array $actingUser, int $id, string $direction): void
    {
        $this->authorizeManage($actingUser);
        $this->requireStatus($id);
        $rows = array_values($this->all());
        $index = array_search($id, array_map(fn (array $s) => (int) $s['id'], $rows), true);
        $other = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : -1);
        if ($index === false || !isset($rows[$other])) {
            return; // already first / last
        }
        $this->statuses->atomically(function () use ($rows, $index, $other) {
            // Normalised positions first, so equal sort values can't block a swap.
            foreach ($rows as $i => $row) {
                $position = ($i === $index ? $other : ($i === $other ? $index : $i)) * 10 + 10;
                if ((int) $row['sort_order'] !== $position) {
                    $this->statuses->updateStatus((int) $row['id'], ['sort_order' => $position]);
                }
            }
        });
    }

    /**
     * Deletes a status. When tasks still show it, $replacementId must name
     * another active status of the same category; those tasks move to it.
     */
    public function delete(array $actingUser, int $id, mixed $replacementId = null): void
    {
        $this->authorizeManage($actingUser);
        $status = $this->requireStatus($id);
        if ((int) $status['is_active'] === 1) {
            $this->requireNotLastRequired($status);
        }

        $this->statuses->atomically(function () use ($status, $id, $replacementId) {
            if ($this->statuses->countTasksUsing($id, $status['system_key']) > 0) {
                $target = is_scalar($replacementId) && ctype_digit((string) $replacementId) ? $this->find((int) $replacementId) : null;
                if ($target === null || (int) $target['id'] === $id || $target['category'] !== $status['category'] || (int) $target['is_active'] !== 1) {
                    throw new ValidationException(['status_replacement' => [t('tasks.error.status_replacement')]]);
                }
                $this->statuses->reassign($id, $status['system_key'], (int) $target['id'], (string) (self::legacyFor($target) ?? $status['system_key'] ?? 'pending'));
            }
            $this->statuses->deleteStatus($id);
        });
    }

    /** Statuses tasks of $status could be moved to on delete (same category, active, not itself). */
    public function replacementsFor(array $status): array
    {
        return array_values(array_filter($this->active(), fn (array $s) => $s['category'] === $status['category'] && (int) $s['id'] !== (int) $status['id']));
    }

    public function usage(array $status): int
    {
        return $this->statuses->countTasksUsing((int) $status['id'], $status['system_key']);
    }

    private function requireNotLastRequired(array $status): void
    {
        if (!in_array($status['category'], self::REQUIRED, true)) {
            return;
        }
        $others = array_filter($this->active(), fn (array $s) => $s['category'] === $status['category'] && (int) $s['id'] !== (int) $status['id']);
        if ($others === []) {
            throw new ValidationException(['status' => [t('tasks.error.status_last_' . $status['category'])]]);
        }
    }

    private function validatedName(mixed $name, ?int $exceptId, bool $required): ?string
    {
        $name = is_string($name) ? trim(preg_replace('/\s+/u', ' ', $name) ?? '') : null;
        if ($name === null || $name === '') {
            if ($required) {
                throw new ValidationException(['status_name' => [t('tasks.error.status_name_required')]]);
            }

            return null;
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationException(['status_name' => [t('tasks.error.status_name_too_long')]]);
        }
        foreach ($this->all() as $other) {
            if ((int) $other['id'] !== $exceptId && mb_strtolower($other['label']) === mb_strtolower($name)) {
                throw new ValidationException(['status_name' => [t('tasks.error.status_name_taken')]]);
            }
        }

        return $name;
    }

    private function validatedColor(mixed $color): string
    {
        if (!is_string($color) || !in_array($color, self::COLORS, true)) {
            throw new ValidationException(['status_color' => [t('tasks.error.status_color')]]);
        }

        return $color;
    }

    private function authorizeManage(array $actingUser): void
    {
        if (!$this->canManage($actingUser)) {
            throw new AuthorizationException();
        }
    }

    private function requireStatus(int $id): array
    {
        $status = $this->find($id);
        if ($status === null) {
            throw new NotFoundException('Status not found.');
        }

        return $status;
    }
}
