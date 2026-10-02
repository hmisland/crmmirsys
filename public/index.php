<?php

declare(strict_types=1);

use App\Core\Application;

require dirname(__DIR__) . '/app/autoload.php';

$app = new Application(dirname(__DIR__));
$app->loadRoutes();
$app->run();
