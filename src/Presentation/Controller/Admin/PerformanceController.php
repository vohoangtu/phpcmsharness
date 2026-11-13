<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Infrastructure\Container\Container;
use App\Presentation\Controller\BaseController;
use App\Presentation\View\ViewRenderer;
use PDO;

final class PerformanceController extends BaseController
{
    /** @param array<string,mixed> $config */
    public function __construct(Container $container, ViewRenderer $view, private readonly array $config)
    {
        parent::__construct($container, $view);
    }

    public function index(): void
    {
        $pdo = $this->container->get(PDO::class);
        $dbConfig = $this->config['db'] ?? [];
        $dbPath = (string)($dbConfig['sqlite_path'] ?? '');
        $storagePath = (string)($this->config['storage_path'] ?? dirname(__DIR__, 4) . '/storage');
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $cacheDir = $storagePath . '/cache';
        $cacheBytes = 0; $cacheFiles = 0;
        if (is_dir($cacheDir)) {
            try {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile()) { $cacheBytes += $file->getSize(); $cacheFiles++; }
                }
            } catch (\Throwable) { /* Cache telemetry is best effort. */ }
        }
        $databaseMode = 'N/A'; $databaseCacheKiB = null;
        if (($dbConfig['driver'] ?? 'sqlite') === 'sqlite') {
            $databaseMode = (string)$pdo->query('PRAGMA journal_mode')->fetchColumn();
            $databaseCacheKiB = abs((int)$pdo->query('PRAGMA cache_size')->fetchColumn());
        }
        $this->view->renderAdmin('admin/performance/index', [
            'pageTitle' => 'Performance & Hiệu năng',
            'performanceStats' => [
                'php_version' => PHP_VERSION,
                'opcache' => is_array($opcache) && !empty($opcache['opcache_enabled']),
                'opcache_memory' => is_array($opcache) ? (int)($opcache['memory_usage']['used_memory'] ?? 0) : 0,
                'gzip' => function_exists('ob_gzhandler'),
                'gd' => extension_loaded('gd'),
                'webp' => function_exists('imagewebp'),
                'database_mode' => strtoupper($databaseMode),
                'database_cache_kib' => $databaseCacheKiB,
                'database_bytes' => $dbPath !== '' && is_file($dbPath) ? (int)filesize($dbPath) : 0,
                'cache_files' => $cacheFiles,
                'cache_bytes' => $cacheBytes,
                'apache_rules' => is_file((string)($this->config['base_path'] ?? dirname(__DIR__, 4)) . '/public/.htaccess'),
            ],
        ]);
    }
}
