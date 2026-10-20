<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

/**
 * The national product/service list (kala.db, SQLite with an FTS5 index —
 * the same file the tax project uses). Optional: without it, "new product
 * from the tax product ID" stays disabled. A relative path is relative to
 * the project root.
 */
return [
    'kala_db_path' => env('KALA_DB_PATH', 'storage/kala/kala.db'),
];
