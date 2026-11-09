<?php

declare(strict_types=1);

return [
    'app_name' => 'WP-Hexagon CMS',
    'site_url' => rtrim((string) (getenv('APP_URL') ?: 'http://localhost'), '/'),
    'base_path' => dirname(__DIR__),
    'storage_path' => dirname(__DIR__) . '/storage',
    'uploads_path' => dirname(__DIR__) . '/public/uploads',
    'uploads_url' => '/uploads',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => dirname(__DIR__) . '/storage/database.sqlite',
        // Hổ trợ chuyển sang MySQL dễ dàng qua PDO:
        'mysql_dsn' => 'mysql:host=127.0.0.1;dbname=wp_hexagon;charset=utf8mb4',
        'mysql_user' => 'root',
        'mysql_pass' => '',
    ],
];
