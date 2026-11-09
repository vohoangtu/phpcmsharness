<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\Product;
use App\Domain\Enum\ContentStatus;
use App\Domain\Port\Outbound\ProductRepositoryPort;
use PDO;

final readonly class SqliteProductRepository implements ProductRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findAll(
        ?ContentStatus $status = null,
        ?int $categoryId = null,
        string $search = '',
        ?bool $featuredOnly = null,
        ?int $limit = null
    ): array {
        $sql = '
            SELECT p.*, c.name AS category_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE 1=1
        ';
        $params = [];

        if ($status !== null) {
            $sql .= ' AND p.status = ?';
            $params[] = $status->value;
        } else {
            $sql .= ' AND p.status != "trash"';
        }

        if ($categoryId !== null && $categoryId > 0) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }

        if ($featuredOnly !== null) {
            $sql .= ' AND p.is_featured = ?';
            $params[] = $featuredOnly ? 1 : 0;
        }

        if ($search !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.short_description LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY p.id DESC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    public function findById(int $id): ?Product
    {
        $stmt = $this->pdo->prepare('
            SELECT p.*, c.name AS category_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.id = ? LIMIT 1
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Product
    {
        $stmt = $this->pdo->prepare('
            SELECT p.*, c.name AS category_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.slug = ? LIMIT 1
        ');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function save(Product $product): int
    {
        $now = date('Y-m-d H:i:s');
        $galleryJson = json_encode(array_values($product->galleryImages), JSON_UNESCAPED_SLASHES);

        if ($product->id === null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO products (name, slug, sku, short_description, description, price, sale_price, stock_quantity, featured_image, gallery_images, status, category_id, is_featured, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $product->name,
                $product->slug,
                $product->sku,
                $product->shortDescription,
                $product->description,
                $product->price,
                $product->salePrice,
                $product->stockQuantity,
                $product->featuredImage,
                $galleryJson,
                $product->status->value,
                $product->categoryId,
                $product->isFeatured ? 1 : 0,
                $now,
                $now,
            ]);
            return (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare('
            UPDATE products
            SET name = ?, slug = ?, sku = ?, short_description = ?, description = ?, price = ?, sale_price = ?, stock_quantity = ?, featured_image = ?, gallery_images = ?, status = ?, category_id = ?, is_featured = ?, updated_at = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $product->name,
            $product->slug,
            $product->sku,
            $product->shortDescription,
            $product->description,
            $product->price,
            $product->salePrice,
            $product->stockQuantity,
            $product->featuredImage,
            $galleryJson,
            $product->status->value,
            $product->categoryId,
            $product->isFeatured ? 1 : 0,
            $now,
            $product->id,
        ]);
        return $product->id;
    }

    public function updateStatus(int $id, ContentStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE products SET status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$status->value, date('Y-m-d H:i:s'), $id]);
    }

    public function decrementStock(int $productId, int $quantity): void
    {
        $stmt = $this->pdo->prepare('UPDATE products SET stock_quantity = MAX(0, stock_quantity - ?) WHERE id = ?');
        $stmt->execute([$quantity, $productId]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countByStatuses(): array
    {
        $counts = [
            'all' => 0,
            'published' => 0,
            'draft' => 0,
            'trash' => 0,
        ];
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS cnt FROM products GROUP BY status');
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            $st = (string) $row['status'];
            $cnt = (int) $row['cnt'];
            if (isset($counts[$st])) {
                $counts[$st] = $cnt;
            }
            if ($st !== 'trash') {
                $counts['all'] += $cnt;
            }
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Product
    {
        $productId = (int) $row['id'];
        $stmtTags = $this->pdo->prepare('
            SELECT t.name FROM tags t
            INNER JOIN product_tags pt ON pt.tag_id = t.id
            WHERE pt.product_id = ?
        ');
        $stmtTags->execute([$productId]);
        $tags = array_map('strval', $stmtTags->fetchAll(PDO::FETCH_COLUMN));

        $galleryDecoded = json_decode((string) ($row['gallery_images'] ?? '[]'), true);
        $galleryImages = is_array($galleryDecoded) ? array_map('strval', $galleryDecoded) : [];

        return new Product(
            id: $productId,
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            sku: (string) $row['sku'],
            shortDescription: (string) $row['short_description'],
            description: (string) $row['description'],
            price: (float) $row['price'],
            salePrice: $row['sale_price'] !== null ? (float) $row['sale_price'] : null,
            stockQuantity: (int) $row['stock_quantity'],
            featuredImage: (string) $row['featured_image'],
            galleryImages: $galleryImages,
            status: ContentStatus::tryFrom((string) $row['status']) ?? ContentStatus::DRAFT,
            categoryId: !empty($row['category_id']) ? (int) $row['category_id'] : null,
            categoryName: isset($row['category_name']) ? (string) $row['category_name'] : null,
            isFeatured: (bool) ((int) $row['is_featured']),
            tags: $tags,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }
}
