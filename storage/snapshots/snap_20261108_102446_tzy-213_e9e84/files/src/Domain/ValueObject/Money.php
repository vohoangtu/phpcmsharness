<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class Money
{
    public function __construct(
        public float $amount
    ) {
    }

    public function formatVnd(): string
    {
        return number_format($this->amount, 0, ',', '.') . ' ₫';
    }
}
