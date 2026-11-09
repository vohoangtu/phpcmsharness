<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\OrderStatus;
use App\Domain\ValueObject\Money;

final readonly class OrderItem
{
    public function __construct(
        public int $productId,
        public string $productName,
        public float $unitPrice,
        public int $quantity,
        public float $subtotal
    ) {
    }
}
