<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
$config = require $basePath . '/config/app.php';
$container = new \App\Infrastructure\Container\Container($config);
exit($container->get(\App\Application\Service\WorkerService::class)->run(trim((string) stream_get_contents(STDIN))));
