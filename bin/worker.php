<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
$config = require $basePath . '/config/app.php';
$container = new \App\Infrastructure\Container\Container($config);
$lockPath = $argv[2] ?? ($basePath . '/storage/workers/worker.lock');
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
try {
    exit($container->get(\App\Application\Service\WorkerService::class)->run((string)($argv[1] ?? '')));
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
