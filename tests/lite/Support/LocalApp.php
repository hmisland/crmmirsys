<?php

declare(strict_types=1);

namespace Tests\Lite\Support;

use App\Core\Application;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\CurrentUser;

/**
 * Shared harness for the step 2.10 suites: boots the real app against
 * crm_local (refuses anything else), opens ONE transaction that the
 * suite's last test rolls back, and offers request helpers that go
 * through the real router + middleware.
 *
 * Users: $admin (org_admin), $member (a real second user of the org,
 * acting with the system member role), $noRole (same org, no role).
 */
final class LocalApp
{
    public Application $app;
    public \PDO $pdo;
    public array $admin;
    public ?array $member;
    public array $noRole;
    public int $orgId;
    public ?int $otherOrgId;
    public int $memberRoleId;
    public int $contractsModuleId = 0;

    public static function boot(): self
    {
        $root = dirname(__DIR__, 3);
        $app = new Application($root);
        $app->loadRoutes();
        restore_error_handler();
        restore_exception_handler();

        $pdo = Connection::pdo();
        if (Config::get('app.env') !== 'local' || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'crm_local') {
            throw new \RuntimeException('not the local crm_local database');
        }
        $adminId = $pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.`key` = 'org_admin' AND u.organization_id IS NOT NULL AND u.status = 'active' ORDER BY u.id LIMIT 1"
        )->fetchColumn();
        if ($adminId === false) {
            throw new \RuntimeException('no active org_admin user');
        }

        $self = new self();
        $self->app = $app;
        $self->pdo = $pdo;
        $users = new UserRepository();
        $self->admin = $users->findById((int) $adminId);
        $self->orgId = (int) $self->admin['organization_id'];
        $self->memberRoleId = (int) $pdo->query("SELECT id FROM roles WHERE `key` = 'member' AND is_system = 1 AND organization_id IS NULL")->fetchColumn();
        $otherUser = $pdo->query("SELECT id FROM users WHERE organization_id = {$self->orgId} AND id <> {$adminId} AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        $self->member = $otherUser === false ? null : array_merge($users->findById((int) $otherUser), ['role_id' => $self->memberRoleId, 'is_super_admin' => 0, 'team_id' => null, 'department_id' => null]);
        $self->noRole = ['id' => 900777, 'organization_id' => $self->orgId, 'role_id' => null, 'is_super_admin' => 0, 'team_id' => null, 'department_id' => null, 'status' => 'active', 'first_name' => 'No', 'last_name' => 'Role', 'email' => 'norole@example.test', 'language' => 'en'];
        $other = $pdo->query("SELECT id FROM organizations WHERE id <> {$self->orgId} ORDER BY id LIMIT 1")->fetchColumn();
        $self->otherOrgId = $other === false ? null : (int) $other;

        CurrentUser::set($self->admin);
        OrganizationContext::set($self->orgId);
        $pdo->beginTransaction();
        register_shutdown_function(fn () => $pdo->inTransaction() ? $pdo->rollBack() : null);

        return $self;
    }

    public function container(): \App\Core\Container
    {
        return $this->app->container;
    }

    /** Super-Admin-style install + entitlement of Contracts for this org (in the transaction). */
    public function activateContracts(): void
    {
        if ($this->contractsModuleId > 0) {
            return;
        }
        $this->pdo->exec("DELETE FROM modules WHERE `key` = 'contracts'");
        $this->pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('contracts', 'Contracts', '0.1.0', 'addon', 0, 'active', '[]')");
        $this->contractsModuleId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$this->orgId, $this->contractsModuleId]);
    }

    public function as(array $user): void
    {
        CurrentUser::set($user);
        $this->container()->make(ModuleGate::class)->reset();
    }

    public function locale(string $locale): void
    {
        Locale::set(new Translator($locale, dirname(__DIR__, 3) . '/lang'));
    }

    /** @return array{status: int, body: string, location: ?string} */
    public function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $this->container()->make(ModuleGate::class)->reset();
        $_SERVER['REQUEST_URI'] = $path;
        if ($method === 'POST' && !array_key_exists('_csrf', $body)) {
            $body['_csrf'] = Csrf::token();
        }
        $response = $this->app->router->dispatch(new Request($method, $path, $query, $body, [], []));
        $read = fn (string $p) => (new \ReflectionProperty(Response::class, $p))->getValue($response);

        return ['status' => $read('status'), 'body' => (string) $read('body'), 'location' => $read('headers')['Location'] ?? null];
    }

    /** "ok" (<400 response), "forbidden", "notfound", "csrf", or "status:NNN". */
    public function outcome(string $method, string $path, array $query = [], array $body = []): string
    {
        try {
            $status = $this->request($method, $path, $query, $body)['status'];

            return $status < 400 || $status === 422 ? 'ok' : 'status:' . $status;
        } catch (AuthorizationException) {
            return 'forbidden';
        } catch (NotFoundException) {
            return 'notfound';
        } catch (HttpException $e) {
            return $e->statusCode === 419 ? 'csrf' : 'status:' . $e->statusCode;
        }
    }

    public function setModuleStatus(string $key, string $status): void
    {
        $this->pdo->prepare('UPDATE modules SET status = ? WHERE `key` = ?')->execute([$status, $key]);
    }

    public function setOrgEntitled(string $key, bool $on): void
    {
        $this->pdo->prepare('UPDATE organization_modules om JOIN modules m ON m.id = om.module_id SET om.enabled = ? WHERE om.organization_id = ? AND m.`key` = ?')
            ->execute([$on ? 1 : 0, $this->orgId, $key]);
    }

    public function setUserOverride(int $userId, string $key, ?bool $enabled): void
    {
        $moduleId = (int) $this->pdo->query('SELECT id FROM modules WHERE `key` = ' . $this->pdo->quote($key))->fetchColumn();
        $this->pdo->prepare('DELETE FROM user_module_overrides WHERE user_id = ? AND module_id = ?')->execute([$userId, $moduleId]);
        if ($enabled !== null) {
            $this->pdo->prepare('INSERT INTO user_module_overrides (organization_id, user_id, module_id, enabled) VALUES (?, ?, ?, ?)')->execute([$this->orgId, $userId, $moduleId, $enabled ? 1 : 0]);
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
