<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\OrderStatus;
use App\Domain\ValueObject\Money;

final readonly class Order
{
    /**
     * @param OrderItem[] $items
     */
    public function __construct(
        public ?int $id,
        public string $orderCode,
        public string $customerName,
        public string $customerEmail,
        public string $customerPhone,
        public string $customerAddress,
        public string $note,
        public float $totalAmount,
        public OrderStatus $status,
        public array $items = [],
        public string $createdAt = ''
    ) {
    }

    public function formattedTotal(): string
    {
        return (new Money($this->totalAmount))->formatVnd();
    }
}
