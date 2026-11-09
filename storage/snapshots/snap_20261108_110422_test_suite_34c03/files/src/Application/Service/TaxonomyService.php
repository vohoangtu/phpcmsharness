<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\Category;
use App\Domain\Entity\Tag;
use App\Domain\Port\Outbound\CategoryRepositoryPort;
use App\Domain\Port\Outbound\TagRepositoryPort;
use App\Domain\ValueObject\Slug;

final readonly class TaxonomyService
{
    public function __construct(
        private CategoryRepositoryPort $categoryRepository,
        private TagRepositoryPort $tagRepository
    ) {
    }

    /**
     * @return Category[]
     */
    public function getCategories(string $type): array
    {
        return $this->categoryRepository->findByType($type);
    }

    public function getCategoryById(int $id): ?Category
    {
        return $this->categoryRepository->findById($id);
    }

    public function getCategoryBySlug(string $slug, string $type): ?Category
    {
        return $this->categoryRepository->findBySlug($slug, $type);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveCategory(array $data): int
    {
        $id = !empty($data['id']) ? (int) $data['id'] : null;
        $name = trim((string) ($data['name'] ?? 'Danh mục mới'));
        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug = (new Slug($rawSlug !== '' ? $rawSlug : $name))->value;
        $requestedType = $data['type'] ?? 'post';
        $type = in_array($requestedType, ['post', 'product'], true) ? (string) $requestedType : 'post';

        return $this->categoryRepository->save(new Category(
            id: $id,
            name: $name,
            slug: $slug,
            type: $type,
            description: trim((string) ($data['description'] ?? ''))
        ));
    }

    public function deleteCategory(int $id): void
    {
        $this->categoryRepository->delete($id);
    }

    /**
     * @return Tag[]
     */
    public function getAllTags(): array
    {
        return $this->tagRepository->findAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveTag(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug = (new Slug($rawSlug !== '' ? $rawSlug : $name))->value;

        return $this->tagRepository->save(new Tag(
            id: null,
            name: $name,
            slug: $slug
        ));
    }

    public function deleteTag(int $id): void
    {
        $this->tagRepository->delete($id);
    }
}
