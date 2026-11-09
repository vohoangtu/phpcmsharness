<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\Category;

interface CategoryRepositoryPort
{
    /**
     * @return Category[]
     */
    public function findByType(string $type): array;

    public function findById(int $id): ?Category;

    public function findBySlug(string $slug, string $type): ?Category;

    public function save(Category $category): int;

    public function delete(int $id): void;
}
