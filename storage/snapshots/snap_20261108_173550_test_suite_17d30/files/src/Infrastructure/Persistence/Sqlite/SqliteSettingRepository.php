<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Port\Outbound\SettingRepositoryPort;
use PDO;

final readonly class SqliteSettingRepository implements SettingRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM settings');
        $rows = $stmt ? $stmt->fetchAll() : [];
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $result;
    }

    public function get(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : (string) $val;
    }

    public function saveMany(array $settings): void
    {
        $stmt = $this->pdo->prepare('INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($settings as $key => $value) {
            $stmt->execute([(string) $key, (string) $value]);
        }
    }
}
