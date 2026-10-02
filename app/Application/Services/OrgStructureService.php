<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\DepartmentRepository;
use App\Infrastructure\Repositories\TeamRepository;
use App\Infrastructure\Validation\Validator;

/**
 * Departments and Teams are simple enough, and similar enough in
 * shape, to share one service rather than two near-identical files —
 * revisit as two services only if one of them grows real complexity
 * (e.g. department hierarchy) that the other doesn't need.
 */
final class OrgStructureService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        EventBus $events,
        private readonly DepartmentRepository $departments,
        private readonly TeamRepository $teams,
        private readonly AuthorizationEngine $authorization,
    ) {
        parent::__construct($logger, $events);
    }

    public function listDepartments(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::DEPARTMENTS_VIEW);

        return $this->departments->all();
    }

    public function createDepartment(array $actingUser, string $name): array
    {
        $this->authorize($actingUser, Permission::DEPARTMENTS_CREATE);
        $this->validateName($name);

        $id = $this->departments->create(['name' => trim($name)]);

        return $this->departments->findById($id);
    }

    public function renameDepartment(array $actingUser, int $id, string $name): array
    {
        $this->authorize($actingUser, Permission::DEPARTMENTS_EDIT);
        $this->validateName($name);
        $this->requireDepartment($id);

        $this->departments->updateDepartment($id, ['name' => trim($name)]);

        return $this->departments->findById($id);
    }

    public function deleteDepartment(array $actingUser, int $id): void
    {
        $this->authorize($actingUser, Permission::DEPARTMENTS_DELETE);
        $this->requireDepartment($id);

        $this->departments->deleteDepartment($id);
    }

    public function listTeams(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::TEAMS_VIEW);

        return $this->teams->all();
    }

    public function createTeam(array $actingUser, string $name, ?int $departmentId): array
    {
        $this->authorize($actingUser, Permission::TEAMS_CREATE);
        $this->validateName($name);

        if ($departmentId !== null) {
            $this->requireDepartment($departmentId);
        }

        $id = $this->teams->create(['name' => trim($name), 'department_id' => $departmentId]);

        return $this->teams->findById($id);
    }

    public function renameTeam(array $actingUser, int $id, string $name, ?int $departmentId): array
    {
        $this->authorize($actingUser, Permission::TEAMS_EDIT);
        $this->validateName($name);
        $this->requireTeam($id);

        if ($departmentId !== null) {
            $this->requireDepartment($departmentId);
        }

        $this->teams->updateTeam($id, ['name' => trim($name), 'department_id' => $departmentId]);

        return $this->teams->findById($id);
    }

    public function deleteTeam(array $actingUser, int $id): void
    {
        $this->authorize($actingUser, Permission::TEAMS_DELETE);
        $this->requireTeam($id);

        $this->teams->deleteTeam($id);
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function validateName(string $name): void
    {
        $validator = Validator::make(['name' => $name], ['name' => 'required|min:2']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }
    }

    private function requireDepartment(int $id): array
    {
        $department = $this->departments->findById($id);

        if ($department === null) {
            throw new NotFoundException('Department not found.');
        }

        return $department;
    }

    private function requireTeam(int $id): array
    {
        $team = $this->teams->findById($id);

        if ($team === null) {
            throw new NotFoundException('Team not found.');
        }

        return $team;
    }
}
