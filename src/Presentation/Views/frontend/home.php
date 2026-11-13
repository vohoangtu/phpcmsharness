<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Product[] $featuredProducts
 * @var \App\Domain\Entity\Post[] $latestPosts
 * @var array<string, bool> $enabledModules
 * @var array<string, string> $settings
 * @var array<string, mixed> $homeSlider
 */
?>
<!-- Hero Banner -->
<?php if (!empty($homeSlider['enabled']) && !empty($homeSlider['slides'])): ?>
    <?php $slideInterval = min(12, max(2, (int)($homeSlider['interval'] ?? 3))); $slideEffect = in_array($homeSlider['effect'] ?? 'fade', ['fade', 'zoom', 'slide'], true) ? $homeSlider['effect'] : 'fade'; ?>
    <section class="fe-home-slider fe-slider-effect-<?= htmlspecialchars($slideEffect, ENT_QUOTES, 'UTF-8') ?>" data-home-slider data-interval="<?= $slideInterval ?>" aria-roledescription="carousel" aria-label="Banner nổi bật">
        <div class="fe-home-slider-track">
            <?php foreach ($homeSlider['slides'] as $index => $slide): ?>
                <article class="fe-home-slide <?= $index === 0 ? 'is-active' : '' ?>" data-home-slide aria-roledescription="slide" aria-label="<?= $index + 1 ?> / <?= count($homeSlider['slides']) ?>">
                    <img class="fe-home-slide-image" src="<?= htmlspecialchars((string)$slide['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($slide['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
                    <div class="fe-home-slide-shade"></div>
                    <div class="fe-home-slide-content">
                        <?php if (!empty($slide['eyebrow'])): ?><span class="fe-hero-pill"><?= htmlspecialchars((string)$slide['eyebrow'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        <?php if (!empty($slide['title'])): ?><h1><?= htmlspecialchars((string)$slide['title'], ENT_QUOTES, 'UTF-8') ?></h1><?php endif; ?>
                        <?php if (!empty($slide['description'])): ?><p><?= htmlspecialchars((string)$slide['description'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                        <?php if (!empty($slide['button_text']) && !empty($slide['button_url'])): ?><a class="fe-btn fe-btn-primary" href="<?= htmlspecialchars((string)$slide['button_url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$slide['button_text'], ENT_QUOTES, 'UTF-8') ?> <span aria-hidden="true">→</span></a><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (count($homeSlider['slides']) > 1): ?>
            <button class="fe-slider-arrow fe-slider-prev" type="button" data-slider-prev aria-label="Ảnh trước">‹</button>
            <button class="fe-slider-arrow fe-slider-next" type="button" data-slider-next aria-label="Ảnh tiếp theo">›</button>
            <div class="fe-slider-footer"><div class="fe-slider-dots" role="group" aria-label="Chọn ảnh banner"><?php foreach ($homeSlider['slides'] as $index => $_slide): ?><button type="button" data-slider-dot="<?= $index ?>" class="<?= $index === 0 ? 'is-active' : '' ?>" aria-label="Đến ảnh <?= $index + 1 ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"></button><?php endforeach; ?></div><button type="button" class="fe-slider-pause" data-slider-pause aria-label="Tạm dừng trình chiếu">Ⅱ</button></div>
        <?php endif; ?>
    </section>
    <script src="/assets/js/home-slider.js" defer></script>
<?php else: ?>
<section class="fe-hero">
    <div class="fe-hero-content">
        <span class="fe-hero-pill">⬡ PHP 8.5 Hexagonal Architecture CMS</span>
        <h1><?= htmlspecialchars($settings['site_title'] ?? 'WP-Hexagon CMS & Store') ?></h1>
        <p><?= htmlspecialchars($settings['site_tagline'] ?? 'Hệ thống Quản trị Bài viết & Sản phẩm linh hoạt với khả năng Bật/Tắt Module tức thì.') ?></p>
        <div class="fe-hero-actions">
            <?php if (!empty($enabledModules['products'])): ?>
                <a href="/shop" class="fe-btn fe-btn-primary">🛍️ Khám phá Cửa hàng</a>
            <?php endif; ?>
            <?php if (!empty($enabledModules['posts'])): ?>
                <a href="/blog" class="fe-btn fe-btn-outline">📝 Đọc Bài viết mới</a>
            <?php endif; ?>
            <a href="/admin" class="fe-btn fe-btn-outline">⚙️ Vào trang Quản trị</a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Featured Products Section (Chỉ hiển thị khi Module PRODUCTS đang BẬT) -->
<?php if (!empty($enabledModules['products'])): ?>
    <section class="fe-section">
        <div class="fe-section-header">
            <div>
                <h2>🛍️ Sản phẩm nổi bật</h2>
                <p>Các sản phẩm công nghệ chính hãng đang có mặt tại cửa hàng</p>
            </div>
            <a href="/shop" class="fe-link-more">Xem toàn bộ cửa hàng →</a>
        </div>

        <div class="fe-product-grid">
            <?php foreach ($featuredProducts as $prod): ?>
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
                                    <?= $prod->inStock() ? '🛒 Thêm vào giỏ hàng' : 'Tạm hết hàng' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <a href="/product/<?= urlencode($prod->slug) ?>" class="fe-btn fe-btn-secondary fe-btn-block">Xem chi tiết sản phẩm</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- Latest Blog Posts Section (Chỉ hiển thị khi Module POSTS đang BẬT) -->
<?php if (!empty($enabledModules['posts'])): ?>
    <section class="fe-section">
        <div class="fe-section-header">
            <div>
                <h2>📝 Bài viết &amp; Tin tức mới nhất</h2>
                <p>Chia sẻ kiến thức công nghệ, kiến trúc phần mềm và thương mại điện tử</p>
            </div>
            <a href="/blog" class="fe-link-more">Xem tất cả bài viết →</a>
        </div>

        <div class="fe-post-grid">
            <?php foreach ($latestPosts as $post): ?>
                <article class="fe-post-card">
                    <a href="/post/<?= urlencode($post->slug) ?>" class="fe-post-img-wrap">
                        <?php if ($post->featuredImage !== ''): ?>
                            <img src="<?= htmlspecialchars($post->featuredImage) ?>" alt="<?= htmlspecialchars($post->title) ?>">
                        <?php else: ?>
                            <div class="fe-img-placeholder">📝</div>
                        <?php endif; ?>
                    </a>
                    <div class="fe-post-body">
                        <div class="fe-post-meta">
                            <span>📁 <?= htmlspecialchars($post->categoryName ?? 'Tin tức') ?></span>
                            <span>👁️ <?= $post->views ?> lượt xem</span>
                        </div>
                        <h3><a href="/post/<?= urlencode($post->slug) ?>"><?= htmlspecialchars($post->title) ?></a></h3>
                        <p><?= htmlspecialchars($post->excerpt) ?></p>
                        <a href="/post/<?= urlencode($post->slug) ?>" class="fe-read-more">Đọc tiếp →</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
