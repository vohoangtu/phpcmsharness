<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Product[] $products
 * @var array<string, int> $counts
 * @var \App\Domain\Entity\Category[] $categories
 * @var string $currentStatus
 * @var int|null $currentCategory
 * @var string $search
 */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">🛍️ Sản phẩm (Products - WooCommerce Style)</h1>
        <p class="wp-page-subtitle">Quản lý danh mục sản phẩm, giá bán, giá khuyến mãi, mã SKU và tồn kho.</p>
    </div>
    <a href="/admin/products/create" class="wp-btn wp-btn-primary">+ Thêm Sản phẩm mới</a>
</div>

<div class="wp-subsubsub">
    <a href="/admin/products" class="<?= $currentStatus === '' ? 'current' : '' ?>">
        Tất cả <span class="count">(<?= (int) ($counts['all'] ?? 0) ?>)</span>
    </a> |
    <a href="/admin/products?status=published" class="<?= $currentStatus === 'published' ? 'current' : '' ?>">
        Đang bán <span class="count">(<?= (int) ($counts['published'] ?? 0) ?>)</span>
    </a> |
    <a href="/admin/products?status=draft" class="<?= $currentStatus === 'draft' ? 'current' : '' ?>">
        Bản nháp <span class="count">(<?= (int) ($counts['draft'] ?? 0) ?>)</span>
    </a> |
    <a href="/admin/products?status=trash" class="<?= $currentStatus === 'trash' ? 'current' : '' ?>">
        Thùng rác <span class="count">(<?= (int) ($counts['trash'] ?? 0) ?>)</span>
    </a>
</div>

<form method="GET" action="/admin/products" class="wp-filter-bar">
    <?php if ($currentStatus !== ''): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($currentStatus) ?>">
    <?php endif; ?>
    <select name="category_id" class="wp-select">
        <option value="">— Tất cả danh mục sản phẩm —</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat->id ?>" <?= $currentCategory === $cat->id ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat->name) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="Tìm tên sản phẩm, mã SKU..." class="wp-input wp-search-input">
    <button type="submit" class="wp-btn wp-btn-secondary">Lọc &amp; Tìm kiếm</button>
</form>

<div class="wp-card">
    <?php if (empty($products)): ?>
        <div class="wp-empty-state">
            <p>Chưa có sản phẩm nào phù hợp với bộ lọc.</p>
            <a href="/admin/products/create" class="wp-btn wp-btn-primary">+ Thêm sản phẩm đầu tiên</a>
        </div>
    <?php else: ?>
        <table class="wp-table">
            <thead>
                <tr>
                    <th style="width:70px;">Ảnh</th>
                    <th>Tên sản phẩm</th>
                    <th>Mã SKU</th>
                    <th>Tồn kho</th>
                    <th>Giá bán</th>
                    <th>Danh mục</th>
                    <th>Nổi bật</th>
                    <th>Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $prod): ?>
                    <tr>
                        <td>
                            <?php if ($prod->featuredImage !== ''): ?>
                                <img src="<?= htmlspecialchars($prod->featuredImage) ?>" alt="" class="wp-thumb">
                            <?php else: ?>
                                <div class="wp-thumb-placeholder">🛍️</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/admin/products/edit/<?= $prod->id ?>" class="wp-row-title"><?= htmlspecialchars($prod->name) ?></a>
                            <div class="wp-row-actions">
                                <a href="/admin/products/edit/<?= $prod->id ?>">Chỉnh sửa</a> |
                                <a href="/product/<?= urlencode($prod->slug) ?>" target="_blank">Xem sản phẩm ↗</a> |
                                <?php if ($prod->status->value !== 'trash'): ?>
                                    <form method="POST" action="/admin/products/status/<?= $prod->
            <?= \App\Presentation\Http\Csrf::field() ?>id ?>" style="display:inline;">
                                        <input type="hidden" name="status" value="trash">
                                        <button type="submit" class="wp-link-danger">Thùng rác</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="/admin/products/status/<?= $prod->
            <?= \App\Presentation\Http\Csrf::field() ?>id ?>" style="display:inline;">
                                        <input type="hidden" name="status" value="published">
                                        <button type="submit" class="wp-link">Khôi phục</button>
                                    </form> |
                                    <form method="POST" action="/admin/products/delete/<?= $prod->
            <?= \App\Presentation\Http\Csrf::field() ?>id ?>" style="display:inline;" onsubmit="return confirm('Xóa vĩnh viễn sản phẩm này?');">
                                        <button type="submit" class="wp-link-danger">Xóa vĩnh viễn</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><code><?= htmlspecialchars($prod->sku) ?></code></td>
                        <td>
                            <?php if ($prod->inStock()): ?>
                                <span class="wp-stock-in">✔ Còn hàng (<?= $prod->stockQuantity ?>)</span>
                            <?php else: ?>
                                <span class="wp-stock-out">✖ Hết hàng (0)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($prod->isOnSale()): ?>
                                <del class="wp-text-muted"><?= $prod->formattedRegularPrice() ?></del><br>
                                <strong class="wp-sale-price"><?= $prod->formattedEffectivePrice() ?></strong>
                            <?php else: ?>
                                <strong><?= $prod->formattedRegularPrice() ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($prod->categoryName ?? '—') ?></td>
                        <td><?= $prod->isFeatured ? '⭐ Nổi bật' : '—' ?></td>
                        <td><span class="wp-status-badge <?= $prod->status->badgeClass() ?>"><?= $prod->status->label() ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
