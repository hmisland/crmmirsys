# Core Migrations

Phase 1 intentionally ships **no business-table migrations** — `users`,
`organizations`, `roles`, etc. belong to Phase 2/3, and `modules` /
`organization_modules` / `user_module_overrides` belong to Phase 4
(Module Manager), per the Phase 0 roadmap. The `migrations` tracking
table itself is created automatically by `App\Infrastructure\Database\Migrator`
the first time it runs — there is nothing to hand-migrate for that.

## File naming

`NNNN_description.php`, zero-padded, applied in filename order:

```
0001_create_organizations_table.php
0002_create_users_table.php
```

## File shape

Each file returns an instance implementing `MigrationInterface`:

```php
<?php

use App\Infrastructure\Database\MigrationInterface;
use PDO;

return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE example (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS example');
    }
};
```

## Running migrations

There is no CLI runner yet in Phase 1 (no SSH is assumed available on
the target shared host). A tiny protected web/cron endpoint that calls
`Migrator::run()` is the intended shared-hosting-compatible mechanism —
that endpoint is added once there's an authenticated Super Admin to
protect it behind (Phase 2/3), rather than being left open in Phase 1.
Locally, a one-off PHP script calling `Migrator::run()` directly is
enough for development.

## Per-module migrations (Phase 4+)

A module owns its own migrations under `/modules/<Name>/Migrations/`
and is tracked separately (`module_migrations`, keyed by module), per
Phase 0 §31. This directory only ever holds Core's own migrations.
