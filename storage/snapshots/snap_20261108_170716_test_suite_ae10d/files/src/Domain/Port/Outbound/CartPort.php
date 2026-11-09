<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

interface CartPort
{
    /**
     * @return array<int, int> Map of productId => quantity
     */
    public function getItems(): array;

    public function add(int $productId, int $quantity = 1): void;

    public function update(int $productId, int $quantity): void;

    public function remove(int $productId): void;

    public function clear(): void;

    public function totalCount(): int;
}
