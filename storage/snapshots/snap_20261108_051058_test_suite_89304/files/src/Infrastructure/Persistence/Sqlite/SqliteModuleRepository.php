<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\FeatureModule;
use App\Domain\Enum\ModuleKey;
use App\Domain\Port\Outbound\ModuleRegistryPort;
use PDO;

final readonly class SqliteModuleRepository implements ModuleRegistryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM modules ORDER BY rowid ASC');
        $rows = $stmt ? $stmt->fetchAll() : [];
        $modules = [];

        foreach ($rows as $row) {
            $key = ModuleKey::tryFrom((string) $row['module_key']);
            if ($key === null) {
                continue;
            }
            $dependsOn = !empty($row['depends_on']) ? ModuleKey::tryFrom((string) $row['depends_on']) : null;
            $modules[] = new FeatureModule(
                key: $key,
                name: (string) $row['name'],
                description: (string) $row['description'],
                icon: (string) $row['icon'],
                isEnabled: (bool) ((int) $row['is_enabled']),
                dependsOn: $dependsOn,
                updatedAt: (string) $row['updated_at']
            );
        }

        return $modules;
    }

    public function isEnabled(ModuleKey $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT is_enabled FROM modules WHERE module_key = ? LIMIT 1');
        $stmt->execute([$key->value]);
        $val = $stmt->fetchColumn();
        return $val === false ? true : (bool) ((int) $val);
    }

    public function setStatus(ModuleKey $key, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE modules SET is_enabled = ?, updated_at = ? WHERE module_key = ?');
        $stmt->execute([$enabled ? 1 : 0, date('Y-m-d H:i:s'), $key->value]);
    }
}
