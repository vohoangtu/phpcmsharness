<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\FeatureModule;
use App\Domain\Enum\ModuleKey;
use App\Domain\Port\Outbound\ModuleRegistryPort;

final class ModuleManagerService
{
    /** @var array<string, bool>|null */
    private ?array $cachedStates = null;

    public function __construct(
        private readonly ModuleRegistryPort $moduleRegistry
    ) {
    }

    /**
     * @return FeatureModule[]
     */
    public function getAllModules(): array
    {
        return $this->moduleRegistry->findAll();
    }

    public function isEnabled(ModuleKey $key): bool
    {
        if ($this->cachedStates === null) {
            $this->refreshCache();
        }
        return $this->cachedStates[$key->value] ?? true;
    }

    /**
     * @return array<string, bool>
     */
    public function getEnabledMap(): array
    {
        if ($this->cachedStates === null) {
            $this->refreshCache();
        }
        return $this->cachedStates ?? [];
    }

    /**
     * Bật/tắt chức năng và tự động xử lý ràng buộc phụ thuộc (ví dụ Orders cần Products).
     *
     * @return string Thông báo kết quả
     */
    public function setModuleState(ModuleKey $key, bool $enabled): string
    {
        // Nếu bật một module có phụ thuộc (ví dụ ORDERS cần PRODUCTS), tự động bật module cha
        if ($enabled && $key->dependsOn() !== null) {
            $parent = $key->dependsOn();
            if (!$this->isEnabled($parent)) {
                $this->moduleRegistry->setStatus($parent, true);
            }
        }

        $this->moduleRegistry->setStatus($key, $enabled);

        // Nếu tắt một module cha (ví dụ PRODUCTS), tự động tắt các module con phụ thuộc nó (ví dụ ORDERS)
        if (!$enabled) {
            foreach (ModuleKey::cases() as $candidate) {
                if ($candidate->dependsOn() === $key && $this->moduleRegistry->isEnabled($candidate)) {
                    $this->moduleRegistry->setStatus($candidate, false);
                }
            }
        }

        $this->refreshCache();

        $actionText = $enabled ? 'Đã BẬT' : 'Đã TẮT';
        return sprintf('%s chức năng "%s" thành công.', $actionText, $key->label());
    }

    public function toggleModule(ModuleKey $key): bool
    {
        $newState = !$this->isEnabled($key);
        $this->setModuleState($key, $newState);
        return $newState;
    }

    private function refreshCache(): void
    {
        $this->cachedStates = [];
        foreach ($this->moduleRegistry->findAll() as $module) {
            $this->cachedStates[$module->key->value] = $module->isEnabled;
        }
    }
}
