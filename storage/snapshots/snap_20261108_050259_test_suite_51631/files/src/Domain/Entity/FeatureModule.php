<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\ModuleKey;

final readonly class FeatureModule
{
    public function __construct(
        public ModuleKey $key,
        public string $name,
        public string $description,
        public string $icon,
        public bool $isEnabled,
        public ?ModuleKey $dependsOn = null,
        public string $updatedAt = ''
    ) {
    }
}
