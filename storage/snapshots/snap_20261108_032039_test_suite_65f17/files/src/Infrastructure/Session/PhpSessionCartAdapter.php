<?php

declare(strict_types=1);

namespace App\Infrastructure\Session;

use App\Domain\Port\Outbound\CartPort;

final class PhpSessionCartAdapter implements CartPort
{
    private const SESSION_KEY = 'wp_hexagon_cart';

    public function getItems(): array
    {
        $items = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($items) ? $items : [];
    }

    public function add(int $productId, int $quantity = 1): void
    {
        $items = $this->getItems();
        $current = (int) ($items[$productId] ?? 0);
        $items[$productId] = max(1, $current + max(1, $quantity));
        $_SESSION[self::SESSION_KEY] = $items;
    }

    public function update(int $productId, int $quantity): void
    {
        $items = $this->getItems();
        if ($quantity <= 0) {
            unset($items[$productId]);
        } else {
            $items[$productId] = $quantity;
        }
        $_SESSION[self::SESSION_KEY] = $items;
    }

    public function remove(int $productId): void
    {
        $items = $this->getItems();
        unset($items[$productId]);
        $_SESSION[self::SESSION_KEY] = $items;
    }

    public function clear(): void
    {
        $_SESSION[self::SESSION_KEY] = [];
    }

    public function totalCount(): int
    {
        return (int) array_sum($this->getItems());
    }
}
