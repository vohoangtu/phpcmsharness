<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\FeatureModule;
use App\Domain\Enum\ModuleKey;

interface ModuleRegistryPort
{
    /**
     * @return FeatureModule[]
     */
    public function findAll(): array;

    public function isEnabled(ModuleKey $key): bool;

    public function setStatus(ModuleKey $key, bool $enabled): void;
}
