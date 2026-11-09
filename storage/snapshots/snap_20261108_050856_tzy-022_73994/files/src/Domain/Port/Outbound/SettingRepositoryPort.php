<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

interface SettingRepositoryPort
{
    /**
     * @return array<string, string>
     */
    public function getAll(): array;

    public function get(string $key, string $default = ''): string;

    /**
     * @param array<string, string> $settings
     */
    public function saveMany(array $settings): void;
}
