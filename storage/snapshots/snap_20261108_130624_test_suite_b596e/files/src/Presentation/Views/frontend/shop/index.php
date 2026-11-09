<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Product[] $products
 * @var \App\Domain\Entity\Category[] $categories
 * @var int|null $currentCategory
 * @var string $search
 * @var float|null $minimumPrice
 * @var float|null $maximumPrice
 * @var bool $inStock
 * @var \App\Domain\Enum\ProductSort $sort
 * @var int $currentPage
 * @var int $totalPages
 * @var array<string, bool> $enabledModules
 */
$categoryFilters = array_filter([
    's' => $search,
    'sort' => $sort->value !== 'newest' ? $sort->value : null,
    'min_price' => $minimumPrice,
    'max_price' => $maximumPrice,
    'in_stock' => $inStock ? '1' : null,
], static fn($value): bool => $value !== null && $value !== '');
?>
<div class="fe-page-head">
    <h1>🛍️ Cửa hàng Sản phẩm</h1>
    <p>Lựa chọn các sản phẩm chất lượng cao với mức giá ưu đãi nhất</p>
</div>

<div class="fe-catalog-layout">
    <aside class="fe-sidebar">
        <div class="fe-widget">
            <h3>🔍 Tìm kiếm sản phẩm</h3>
            <form method="GET" action="/shop">
                <input type="text" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="Tên sản phẩm, SKU..." class="fe-input">
                <div style="display:flex; gap:8px; margin-top:12px;">
                    <label style="flex:1;">Giá từ
                        <input type="number" name="min_price" min="0" step="any" value="<?= $minimumPrice !== null ? htmlspecialchars((string) $minimumPrice, ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="Tối thiểu" class="fe-input">
                    </label>
                    <label style="flex:1;">Đến
                        <input type="number" name="max_price" min="0" step="any" value="<?= $maximumPrice !== null ? htmlspecialchars((string) $maximumPrice, ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="Tối đa" class="fe-input">
                    </label>
                </div>
                <label style="display:flex; align-items:center; gap:8px; margin-top:12px;">
                    <input type="checkbox" name="in_stock" value="1" <?= $inStock ? 'checked' : '' ?>>
                    Chỉ hiện sản phẩm còn hàng
                </label>
                <?php if ($currentCategory !== null): ?>
                    <input type="hidden" name="category" value="<?= (int) $currentCategory ?>">
                <?php endif; ?>
                <label for="product-sort" style="display:block; margin-top:12px;">Sắp xếp theo</label>
                <select id="product-sort" name="sort" class="fe-input">
                    <?php foreach (\App\Domain\Enum\ProductSort::cases() as $sortOption): ?>
                        <option value="<?= htmlspecialchars($sortOption->value, ENT_QUOTES, 'UTF-8') ?>" <?= $sort === $sortOption ? 'selected' : '' ?>><?= htmlspecialchars($sortOption->label(), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="fe-btn fe-btn-primary fe-btn-block" style="margin-top:8px;">Tìm kiếm</button>
            </form>
        </div>

        <div class="fe-widget">
            <h3>📁 Danh mục sản phẩm</h3>
            <ul class="fe-cat-list">
                <li><a href="/shop" class="<?= $currentCategory === null ? 'active' : '' ?>">Tất cả sản phẩm</a></li>
                <?php foreach ($categories as $cat): ?>
                    <li>
                        <a href="/shop?<?= htmlspecialchars(http_build_query(['category' => $cat->id] + $categoryFilters), ENT_QUOTES, 'UTF-8') ?>" class="<?= $currentCategory === $cat->id ? 'active' : '' ?>">
                            <?= htmlspecialchars($cat->name) ?> <span>(<?= $cat->itemCount ?>)</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>

    <div class="fe-catalog-main">
        <?php if (empty($products)): ?>
            <div class="fe-empty-box">Không tìm thấy sản phẩm nào phù hợp.</div>
        <?php else: ?>
            <div class="fe-product-grid">
                <?php foreach ($products as $prod): ?>
                    <article class="fe-product-card">
                        <a href="/product/<?= urlencode($prod->slug) ?>" class="fe-product-img-wrap">
                            <?php if ($prod->isOnSale()): ?>
                                <span class="fe-sale-badge">-<?= $prod->discountPercent() ?>%</span>
                            <?php endif; ?>
                            <?php if ($prod->featuredImage !== ''): ?>
                                <img src="<?= htmlspecialchars($prod->featuredImage) ?>" alt="<?= htmlspecialchars($prod->name) ?>">
                            <?php else: ?>
                                <div class="fe-img-placeholder">🛍️</div>
                            <?php endif; ?>
                        </a>
                        <div class="fe-product-body">
                            <?php if ($prod->categoryName): ?>
                                <small class="fe-category-tag"><?= htmlspecialchars($prod->categoryName) ?></small>
                            <?php endif; ?>
                            <h3><a href="/product/<?= urlencode($prod->slug) ?>"><?= htmlspecialchars($prod->name) ?></a></h3>
                            <div class="fe-price-row">
                                <span class="fe-price-current"><?= $prod->formattedEffectivePrice() ?></span>
                                <?php if ($prod->isOnSale()): ?>
                                    <del class="fe-price-old"><?= $prod->formattedRegularPrice() ?></del>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($enabledModules['orders'])): ?>
                                <form method="POST" action="/cart/add">
            <?= \App\Presentation\Http\Csrf::field() ?>
                                    <input type="hidden" name="product_id" value="<?= $prod->id ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <input type="hidden" name="redirect_to" value="/cart">
                                    <button type="submit" class="fe-btn fe-btn-primary fe-btn-block" <?= !$prod->inStock() ? 'disabled' : '' ?>>
                                        <?= $prod->inStock() ? '🛒 Thêm vào giỏ' : 'Hết hàng' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="/product/<?= urlencode($prod->slug) ?>" class="fe-btn fe-btn-secondary fe-btn-block">Xem chi tiết</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($totalPages > 1): ?>
            <?php $pageFilters = array_filter(['category' => $currentCategory, 's' => $search, 'sort' => $sort->value !== 'newest' ? $sort->value : null, 'min_price' => $minimumPrice, 'max_price' => $maximumPrice, 'in_stock' => $inStock ? '1' : null], static fn($value): bool => $value !== null && $value !== ''); ?>
            <nav aria-label="Phân trang sản phẩm" style="display:flex; justify-content:center; align-items:center; gap:12px; margin-top:24px;">
                <?php if ($currentPage > 1): ?>
                    <a class="fe-btn fe-btn-secondary" href="/shop?<?= htmlspecialchars(http_build_query($pageFilters + ['page' => $currentPage - 1]), ENT_QUOTES, 'UTF-8') ?>">← Trang trước</a>
                <?php endif; ?>
                <span>Trang <?= $currentPage ?> / <?= $totalPages ?></span>
                <?php if ($currentPage < $totalPages): ?>
                    <a class="fe-btn fe-btn-secondary" href="/shop?<?= htmlspecialchars(http_build_query($pageFilters + ['page' => $currentPage + 1]), ENT_QUOTES, 'UTF-8') ?>">Trang sau →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</div>
