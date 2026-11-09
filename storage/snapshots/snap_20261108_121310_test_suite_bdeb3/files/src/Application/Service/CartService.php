<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Enum\ContentStatus;
use App\Domain\Port\Outbound\CartPort;
use App\Domain\Port\Outbound\ProductRepositoryPort;
use RuntimeException;

final readonly class CartService
{
    public function __construct(
        private ProductRepositoryPort $productRepository,
        private CartPort $cart
    ) {
    }

    public function addItem(mixed $productId, mixed $quantity): void
    {
        $id = $this->parsePositiveInteger($productId);
        $amount = $this->parsePositiveInteger($quantity);
        if ($id === null || $amount === null) {
            throw new RuntimeException('Sản phẩm hoặc số lượng không hợp lệ.');
        }

        $product = $this->productRepository->findById($id);
        if ($product === null || $product->status !== ContentStatus::PUBLISHED) {
            throw new RuntimeException('Sản phẩm này hiện không thể đặt mua.');
        }
        if ($product->stockQuantity < 1) {
            throw new RuntimeException(sprintf('Sản phẩm "%s" hiện đã hết hàng.', $product->name));
        }

        $currentQuantity = (int) ($this->cart->getItems()[$id] ?? 0);
        if ($currentQuantity + $amount > $product->stockQuantity) {
            throw new RuntimeException(sprintf(
                'Sản phẩm "%s" chỉ còn %d sản phẩm trong kho (đã có %d trong giỏ).',
                $product->name,
                $product->stockQuantity,
                $currentQuantity
            ));
        }

        $this->cart->add($id, $amount);
    }

    /**
     * @param array<int|string, mixed> $quantities
     */
    public function updateItems(array $quantities): void
    {
        $currentItems = $this->cart->getItems();
        $updates = [];

        foreach ($quantities as $rawProductId => $rawQuantity) {
            $productId = $this->parsePositiveInteger($rawProductId);
            $quantity = $this->parseNonNegativeInteger($rawQuantity);
            if ($productId === null || $quantity === null || !array_key_exists($productId, $currentItems)) {
                throw new RuntimeException('Dữ liệu cập nhật giỏ hàng không hợp lệ. Vui lòng tải lại trang và thử lại.');
            }

            if ($quantity > 0) {
                $product = $this->productRepository->findById($productId);
                if ($product === null || $product->status !== ContentStatus::PUBLISHED) {
                    throw new RuntimeException('Một sản phẩm trong giỏ hiện không thể đặt mua.');
                }
                if ($quantity > $product->stockQuantity) {
                    throw new RuntimeException(sprintf(
                        'Sản phẩm "%s" chỉ còn %d sản phẩm trong kho.',
                        $product->name,
                        $product->stockQuantity
                    ));
                }
            }

            $updates[$productId] = $quantity;
        }

        // Validate the full request before mutating session state, so errors never leave a partial update.
        foreach ($updates as $productId => $quantity) {
            $this->cart->update($productId, $quantity);
        }
    }

    private function parsePositiveInteger(mixed $value): ?int
    {
        $integer = $this->parseNonNegativeInteger($value);
        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private function parseNonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer === false || $integer < 0 ? null : $integer;
    }
}
