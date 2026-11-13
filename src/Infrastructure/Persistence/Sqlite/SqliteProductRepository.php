<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\Product;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ProductSort;
use App\Domain\Port\Outbound\ProductRepositoryPort;
use PDO;

final readonly class SqliteProductRepository implements ProductRepositoryPort
{
    private const EFFECTIVE_PRICE_SQL = 'CASE WHEN p.sale_price > 0 AND p.sale_price < p.price THEN p.sale_price ELSE p.price END';

    public function __construct(
        private PDO $pdo
    ) {
    }

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

        if ($minimumPrice !== null) {
            $sql .= ' AND CAST((' . self::EFFECTIVE_PRICE_SQL . ') AS REAL) >= CAST(? AS REAL)';
            $params[] = $minimumPrice;
        }
        if ($maximumPrice !== null) {
            $sql .= ' AND CAST((' . self::EFFECTIVE_PRICE_SQL . ') AS REAL) <= CAST(? AS REAL)';
            $params[] = $maximumPrice;
        }
        if ($inStock !== null) {
            $sql .= $inStock ? ' AND p.stock_quantity > 0' : ' AND p.stock_quantity = 0';
        }

        if ($search !== '') {
            $sql .= " AND (
                p.name LIKE ? ESCAPE '!'
                OR p.sku LIKE ? ESCAPE '!'
                OR p.short_description LIKE ? ESCAPE '!'
                OR p.description LIKE ? ESCAPE '!'
                OR EXISTS (
                    SELECT 1
                    FROM product_tags pt
                    INNER JOIN tags t ON t.id = pt.tag_id
                    WHERE pt.product_id = p.id AND t.name LIKE ? ESCAPE '!'
                )
            )";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $sql .= ' ORDER BY ' . match ($sort) {
            ProductSort::NEWEST => 'p.id DESC',
            ProductSort::NAME_ASC => 'p.name COLLATE NOCASE ASC, p.id DESC',
            ProductSort::PRICE_ASC => self::EFFECTIVE_PRICE_SQL . ' ASC, p.id DESC',
            ProductSort::PRICE_DESC => self::EFFECTIVE_PRICE_SQL . ' DESC, p.id DESC',
        };
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = max(0, $offset);
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    public function countMatching(
        ?ContentStatus $status = null,
        ?int $categoryId = null,
        string $search = '',
        ?bool $featuredOnly = null,
        ?float $minimumPrice = null,
        ?float $maximumPrice = null,
        ?bool $inStock = null
    ): int
    {
        $sql = 'SELECT COUNT(*) FROM products p WHERE 1=1';
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
        if ($minimumPrice !== null) {
            $sql .= ' AND CAST((' . self::EFFECTIVE_PRICE_SQL . ') AS REAL) >= CAST(? AS REAL)';
            $params[] = $minimumPrice;
        }
        if ($maximumPrice !== null) {
            $sql .= ' AND CAST((' . self::EFFECTIVE_PRICE_SQL . ') AS REAL) <= CAST(? AS REAL)';
            $params[] = $maximumPrice;
        }
        if ($inStock !== null) {
            $sql .= $inStock ? ' AND p.stock_quantity > 0' : ' AND p.stock_quantity = 0';
        }
        if ($search !== '') {
            $sql .= " AND (
                p.name LIKE ? ESCAPE '!'
                OR p.sku LIKE ? ESCAPE '!'
                OR p.short_description LIKE ? ESCAPE '!'
                OR p.description LIKE ? ESCAPE '!'
                OR EXISTS (
                    SELECT 1
                    FROM product_tags pt
                    INNER JOIN tags t ON t.id = pt.tag_id
                    WHERE pt.product_id = p.id AND t.name LIKE ? ESCAPE '!'
                )
            )";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
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
                INSERT INTO products (name, slug, sku, short_description, description, price, sale_price, stock_quantity, featured_image, gallery_images, status, category_id, is_featured, created_at, updated_at, seo_title, seo_description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $product->seoTitle,
                $product->seoDescription,
            ]);
            return (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare('
            UPDATE products
            SET name = ?, slug = ?, sku = ?, short_description = ?, description = ?, price = ?, sale_price = ?, stock_quantity = ?, featured_image = ?, gallery_images = ?, status = ?, category_id = ?, is_featured = ?, updated_at = ?, seo_title = ?, seo_description = ?
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
            $product->seoTitle,
            $product->seoDescription,
            $product->id,
        ]);
        return $product->id;
    }

    public function updateStatus(int $id, ContentStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE products SET status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$status->value, date('Y-m-d H:i:s'), $id]);
    }

    public function decrementStock(int $productId, int $quantity): bool
    {
        if ($quantity < 1) {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ?, updated_at = ? WHERE id = ? AND stock_quantity >= ?');
        $stmt->execute([$quantity, date('Y-m-d H:i:s'), $productId, $quantity]);
        return $stmt->rowCount() === 1;
    }

    public function increaseStock(int $productId, int $quantity): void
    {
        if ($quantity < 1) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?');
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
            updatedAt: (string) $row['updated_at'],
            seoTitle: (string) ($row['seo_title'] ?? ''),
            seoDescription: (string) ($row['seo_description'] ?? '')
        );
    }
}
