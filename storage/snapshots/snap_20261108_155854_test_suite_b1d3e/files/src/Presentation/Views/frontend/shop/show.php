<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Product $product
 * @var \App\Domain\Entity\Comment[] $reviews
 * @var array<string, bool> $enabledModules
 */
?>
<div class="fe-breadcrumb">
    <a href="/shop">← Quay lại Cửa hàng</a>
    <?php if ($product->categoryName): ?>
        <span>/ <?= htmlspecialchars($product->categoryName) ?></span>
    <?php endif; ?>
</div>

<div class="fe-product-detail">
    <div class="fe-product-gallery">
        <div class="fe-product-main-img">
            <?php if ($product->featuredImage !== '' || !empty($product->galleryImages)): ?>
                <?php $initialGalleryImage = $product->featuredImage !== '' ? $product->featuredImage : $product->galleryImages[0]; ?>
                <img src="<?= htmlspecialchars($initialGalleryImage, ENT_QUOTES, 'UTF-8') ?>" id="main-prod-img" alt="<?= htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8') ?>">
            <?php else: ?>
                <div class="fe-img-placeholder">🛍️</div>
            <?php endif; ?>
        </div>
        <?php if (!empty($product->galleryImages)): ?>
            <div class="fe-product-thumbs" role="group" aria-label="Ảnh sản phẩm">
                <?php foreach ($product->galleryImages as $index => $gUrl): ?>
                    <?php $galleryImageAlt = sprintf('%s — ảnh %d', $product->name, $index + 1); ?>
                    <button
                        type="button"
                        class="fe-product-thumb<?= $product->featuredImage === '' && $index === 0 ? ' is-active' : '' ?>"
                        aria-label="Hiển thị <?= htmlspecialchars($galleryImageAlt, ENT_QUOTES, 'UTF-8') ?>"
                        aria-pressed="<?= $product->featuredImage === '' && $index === 0 ? 'true' : 'false' ?>"
                        data-image-src="<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>"
                        data-image-alt="<?= htmlspecialchars($galleryImageAlt, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <img src="<?= htmlspecialchars($gUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                    </button>
                <?php endforeach; ?>
            </div>
            <script>
                document.querySelectorAll('.fe-product-gallery .fe-product-thumb').forEach((button) => {
                    button.addEventListener('click', () => {
                        const mainImage = document.getElementById('main-prod-img');
                        if (!mainImage) return;

                        mainImage.src = button.dataset.imageSrc;
                        mainImage.alt = button.dataset.imageAlt;
                        document.querySelectorAll('.fe-product-gallery .fe-product-thumb').forEach((thumbnail) => {
                            const isActive = thumbnail === button;
                            thumbnail.setAttribute('aria-pressed', String(isActive));
                            thumbnail.classList.toggle('is-active', isActive);
                        });
                    });
                });
            </script>
        <?php endif; ?>
    </div>

    <div class="fe-product-summary">
        <?php if ($product->isOnSale()): ?>
            <span class="fe-sale-pill">Giảm <?= $product->discountPercent() ?>%</span>
        <?php endif; ?>
        <h1><?= htmlspecialchars($product->name) ?></h1>
        <p class="fe-sku-meta">Mã SKU: <code><?= htmlspecialchars($product->sku) ?></code> • Tình trạng:
            <?php if ($product->inStock()): ?>
                <strong style="color:#16a34a;">Còn hàng (<?= $product->stockQuantity ?> sản phẩm)</strong>
            <?php else: ?>
                <strong style="color:#dc2626;">Tạm hết hàng</strong>
            <?php endif; ?>
        </p>

        <div class="fe-detail-price">
            <span class="fe-price-big"><?= $product->formattedEffectivePrice() ?></span>
            <?php if ($product->isOnSale()): ?>
                <del class="fe-price-old-big"><?= $product->formattedRegularPrice() ?></del>
            <?php endif; ?>
        </div>

        <p class="fe-short-desc"><?= nl2br(htmlspecialchars($product->shortDescription)) ?></p>

        <?php if (!empty($enabledModules['orders'])): ?>
            <form method="POST" action="/cart/add" class="fe-buy-box">
            <?= \App\Presentation\Http\Csrf::field() ?>
                <input type="hidden" name="product_id" value="<?= $product->id ?>">
                <input type="hidden" name="redirect_to" value="/cart">
                <label for="product-quantity-<?= (int) $product->id ?>">Số lượng</label>
                <input type="number" id="product-quantity-<?= (int) $product->id ?>" name="quantity" value="1" min="1" max="<?= max(1, $product->stockQuantity) ?>" <?= !$product->inStock() ? 'disabled' : '' ?> class="fe-qty-input">
                <button type="submit" class="fe-btn fe-btn-primary fe-btn-lg" <?= !$product->inStock() ? 'disabled' : '' ?>>
                    🛒 <?= $product->inStock() ? 'Thêm vào giỏ & Mua ngay' : 'Tạm hết hàng' ?>
                </button>
            </form>
        <?php else: ?>
            <div class="fe-catalog-notice">
                ℹ️ Cửa hàng hiện đang ở chế độ <strong>Catalog trưng bày</strong>. Vui lòng liên hệ Hotline để đặt hàng trực tiếp.
            </div>
        <?php endif; ?>

        <?php if (!empty($product->tags) && !empty($enabledModules['categories_tags'])): ?>
            <div class="fe-single-tags" style="margin-top:20px;">
                <strong>🏷️ Từ khóa:</strong>
                <?php foreach ($product->tags as $t): ?>
                    <span class="fe-tag-badge"><?= htmlspecialchars($t) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<section class="fe-product-description-box">
    <h2>📄 Thông tin chi tiết sản phẩm</h2>
    <div class="fe-single-content">
        <?= \App\Presentation\Security\HtmlContentSanitizer::sanitize($product->description) ?>
    </div>
</section>

<?php if (!empty($enabledModules['comments'])): ?>
    <section id="reviews" class="fe-comments-section">
        <h2>⭐ Đánh giá từ khách hàng (<?= count($reviews) ?>)</h2>

        <div class="fe-comment-list">
            <?php if (empty($reviews)): ?>
                <p class="fe-text-muted">Chưa có đánh giá nào cho sản phẩm này.</p>
            <?php else: ?>
                <?php foreach ($reviews as $rv): ?>
                    <div class="fe-comment-item">
                        <div class="fe-comment-head">
                            <div>
                                <strong><?= htmlspecialchars($rv->authorName) ?></strong>
                                <span class="fe-stars"><?= str_repeat('★', $rv->rating) . str_repeat('☆', 5 - $rv->rating) ?></span>
                            </div>
                            <small><?= htmlspecialchars($rv->createdAt) ?></small>
                        </div>
                        <p><?= nl2br(htmlspecialchars($rv->content)) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form method="POST" action="/product/<?= urlencode($product->slug) ?>/review" class="fe-comment-form">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
            <h3>Gửi đánh giá của bạn về sản phẩm</h3>
            <div class="fe-grid-2">
                <div>
                    <label for="product-review-author-name">Họ và tên *</label>
                    <input id="product-review-author-name" type="text" name="author_name" required class="fe-input">
                </div>
                <div>
                    <label for="product-review-author-email">Email *</label>
                    <input id="product-review-author-email" type="email" name="author_email" required class="fe-input">
                </div>
            </div>
            <div style="margin-top:12px;">
                <label for="product-review-rating">Mức độ hài lòng (Số sao)</label>
                <select id="product-review-rating" name="rating" class="fe-input">
                    <option value="5">★★★★★ — Rất tuyệt vời (5 sao)</option>
                    <option value="4">★★★★☆ — Tốt (4 sao)</option>
                    <option value="3">★★★☆☆ — Bình thường (3 sao)</option>
                    <option value="2">★★☆☆☆ — Chưa hài lòng (2 sao)</option>
                    <option value="1">★☆☆☆☆ — Rất tệ (1 sao)</option>
                </select>
            </div>
            <div style="margin-top:12px;">
                <label for="product-review-content">Nhận xét chi tiết *</label>
                <textarea id="product-review-content" name="content" rows="3" required class="fe-textarea"></textarea>
            </div>
            <button type="submit" class="fe-btn fe-btn-primary" style="margin-top:12px;">Gửi đánh giá</button>
        </form>
    </section>
<?php endif; ?>
