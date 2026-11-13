<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\ContentStatus;
use App\Domain\ValueObject\Money;

final class Product
{
    /**
     * @param string[] $galleryImages
     * @param string[] $tags
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $slug,
        public string $sku,
        public string $shortDescription,
        public string $description,
        public float $price,
        public ?float $salePrice,
        public int $stockQuantity,
        public string $featuredImage,
        public array $galleryImages,
        public ContentStatus $status,
        public ?int $categoryId,
        public ?string $categoryName,
        public bool $isFeatured = false,
        public array $tags = [],
        public string $createdAt = '',
        public string $updatedAt = '',
        public string $seoTitle = '',
        public string $seoDescription = ''
    ) {
    }

    public function isOnSale(): bool
    {
        return $this->salePrice !== null && $this->salePrice > 0 && $this->salePrice < $this->price;
    }

    public function effectivePrice(): float
    {
        return $this->isOnSale() ? (float) $this->salePrice : $this->price;
    }

    public function formattedEffectivePrice(): string
    {
        return (new Money($this->effectivePrice()))->formatVnd();
    }

    public function formattedRegularPrice(): string
    {
        return (new Money($this->price))->formatVnd();
    }

    public function discountPercent(): int
    {
        if (!$this->isOnSale() || $this->price <= 0) {
            return 0;
        }
        return (int) round((($this->price - (float) $this->salePrice) / $this->price) * 100);
    }

    public function inStock(): bool
    {
        return $this->stockQuantity > 0;
    }
}
