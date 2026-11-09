<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\Product;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ProductSort;

interface ProductRepositoryPort
{
    /**
     * @return Product[]
     */
    public function findAll(
        ?ContentStatus $status = null,
        ?int $categoryId = null,
        string $search = '',
        ?bool $featuredOnly = null,
        ?int $limit = null,
        int $offset = 0,
        ProductSort $sort = ProductSort::NEWEST,
        ?float $minimumPrice = null,
        ?float $maximumPrice = null,
        ?bool $inStock = null
    ): array;

    public function countMatching(
        ?ContentStatus $status = null,
        ?int $categoryId = null,
        string $search = '',
        ?bool $featuredOnly = null,
        ?float $minimumPrice = null,
        ?float $maximumPrice = null,
        ?bool $inStock = null
    ): int;

    public function findById(int $id): ?Product;

    public function findBySlug(string $slug): ?Product;

    public function save(Product $product): int;

    public function updateStatus(int $id, ContentStatus $status): void;

    public function decrementStock(int $productId, int $quantity): bool;

    public function delete(int $id): void;

    /**
     * @return array<string, int>
     */
    public function countByStatuses(): array;
}
