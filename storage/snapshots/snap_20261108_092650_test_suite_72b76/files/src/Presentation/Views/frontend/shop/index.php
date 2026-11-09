<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Product[] $products
 * @var \App\Domain\Entity\Category[] $categories
 * @var int|null $currentCategory
 * @var string $search
 * @var int $currentPage
 * @var int $totalPages
 * @var array<string, bool> $enabledModules
 */
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
                <button type="submit" class="fe-btn fe-btn-primary fe-btn-block" style="margin-top:8px;">Tìm kiếm</button>
            </form>
        </div>

        <div class="fe-widget">
            <h3>📁 Danh mục sản phẩm</h3>
            <ul class="fe-cat-list">
                <li><a href="/shop" class="<?= $currentCategory === null ? 'active' : '' ?>">Tất cả sản phẩm</a></li>
                <?php foreach ($categories as $cat): ?>
                    <li>
                        <a href="/shop?category=<?= $cat->id ?>" class="<?= $currentCategory === $cat->id ? 'active' : '' ?>">
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
            <?php $pageFilters = array_filter(['category' => $currentCategory, 's' => $search], static fn($value): bool => $value !== null && $value !== ''); ?>
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
