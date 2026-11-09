<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\Product;
use App\Domain\Enum\ContentStatus;
use App\Domain\Port\Outbound\ProductRepositoryPort;
use App\Domain\Port\Outbound\TagRepositoryPort;
use App\Domain\ValueObject\Slug;

final readonly class ProductService
{
    public function __construct(
        private ProductRepositoryPort $productRepository,
        private TagRepositoryPort $tagRepository
    ) {
    }

    /**
     * @return Product[]
     */
    public function listProducts(
        ?ContentStatus $status = null,
        ?int $categoryId = null,
        string $search = '',
        ?bool $featuredOnly = null,
        ?int $limit = null
    ): array {
        return $this->productRepository->findAll($status, $categoryId, $search, $featuredOnly, $limit);
    }

    public function getById(int $id): ?Product
    {
        return $this->productRepository->findById($id);
    }

    public function getPublishedBySlug(string $slug): ?Product
    {
        $product = $this->productRepository->findBySlug($slug);
        if ($product === null || $product->status !== ContentStatus::PUBLISHED) {
            return null;
        }
        return $product;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveProduct(array $data): int
    {
        $id = isset($data['id']) && (int) $data['id'] > 0 ? (int) $data['id'] : null;
        $name = trim((string) ($data['name'] ?? 'Sản phẩm mới'));
        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug = (new Slug($rawSlug !== '' ? $rawSlug : $name))->value;
        $slug = $this->ensureUniqueSlug($slug, $id);

        $price = max(0, (float) ($data['price'] ?? 0));
        $rawSale = trim((string) ($data['sale_price'] ?? ''));
        $salePrice = ($rawSale !== '' && (float) $rawSale > 0 && (float) $rawSale < $price)
            ? (float) $rawSale
            : null;

        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku === '') {
            $sku = 'SKU-' . strtoupper(substr(md5($slug), 0, 6));
        }

        $galleryRaw = (string) ($data['gallery_images'] ?? '');
        $galleryImages = array_values(array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $galleryRaw))), fn(string $u) => $u !== ''));

        $status = ContentStatus::tryFrom((string) ($data['status'] ?? 'published')) ?? ContentStatus::PUBLISHED;
        $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;

        $product = new Product(
            id: $id,
            name: $name,
            slug: $slug,
            sku: $sku,
            shortDescription: trim((string) ($data['short_description'] ?? '')),
            description: (string) ($data['description'] ?? ''),
            price: $price,
            salePrice: $salePrice,
            stockQuantity: max(0, (int) ($data['stock_quantity'] ?? 0)),
            featuredImage: trim((string) ($data['featured_image'] ?? '')),
            galleryImages: $galleryImages,
            status: $status,
            categoryId: $categoryId,
            categoryName: null,
            isFeatured: !empty($data['is_featured'])
        );

        $savedId = $this->productRepository->save($product);

        $rawTags = (string) ($data['tags'] ?? '');
        $tagNames = array_values(array_filter(array_map('trim', explode(',', $rawTags)), fn(string $t) => $t !== ''));
        $this->tagRepository->syncProductTags($savedId, $tagNames);

        return $savedId;
    }

    public function changeStatus(int $id, ContentStatus $status): void
    {
        $this->productRepository->updateStatus($id, $status);
    }

    public function deletePermanently(int $id): void
    {
        $this->productRepository->delete($id);
    }

    /**
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        return $this->productRepository->countByStatuses();
    }

    private function ensureUniqueSlug(string $baseSlug, ?int $ignoreId): string
    {
        $slug = $baseSlug;
        $counter = 2;
        while (true) {
            $existing = $this->productRepository->findBySlug($slug);
            if ($existing === null || ($ignoreId !== null && $existing->id === $ignoreId)) {
                return $slug;
            }
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
    }
}
