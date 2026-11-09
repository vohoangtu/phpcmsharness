<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final readonly class Category
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $slug,
        public string $type, // 'post' | 'product'
        public string $description = '',
        public int $itemCount = 0
    ) {
    }
}
