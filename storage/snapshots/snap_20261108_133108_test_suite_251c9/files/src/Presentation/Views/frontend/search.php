<?php
declare(strict_types=1);
/**
 * @var string $search
 * @var \App\Domain\Entity\Post[] $posts
 * @var \App\Domain\Entity\Product[] $products
 * @var bool $postsEnabled
 * @var bool $productsEnabled
 * @var int $postPage
 * @var int $postTotalPages
 * @var int $productPage
 * @var int $productTotalPages
 */
$escapedSearch = htmlspecialchars($search, ENT_QUOTES, 'UTF-8');
$postCount = count($posts);
$productCount = count($products);
$paginationPages = static function (int $currentPage, int $totalPages): array {
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($end - $start < 4) {
        $start = max(1, $end - 4);
        $end = min($totalPages, $start + 4);
    }

    return range($start, $end);
};
$paginationQuery = static function (int $postsPage, int $productsPage) use ($search): string {
    return htmlspecialchars(http_build_query([
        's' => $search,
        'posts_page' => $postsPage,
        'products_page' => $productsPage,
    ]), ENT_QUOTES, 'UTF-8');
};
?>
<div class="fe-page-head">
    <h1>🔍 Tìm kiếm toàn trang</h1>
    <?php if ($search !== ''): ?>
        <p>Kết quả cho “<?= $escapedSearch ?>”</p>
    <?php else: ?>
        <p>Tìm bài viết và sản phẩm trên website</p>
    <?php endif; ?>
</div>

<form method="get" action="/search" role="search" aria-label="Tìm kiếm nội dung" class="fe-search-page-form">
    <label for="search-query">Từ khóa</label>
    <div>
        <input id="search-query" type="search" name="s" value="<?= $escapedSearch ?>" placeholder="Nhập từ khóa cần tìm..." class="fe-input" maxlength="200">
        <button type="submit" class="fe-btn fe-btn-primary">Tìm kiếm</button>
    </div>
</form>

<?php if ($search !== ''): ?>
    <?php if ($postsEnabled): ?>
        <section class="fe-section" aria-labelledby="search-posts-heading">
            <div class="fe-section-header">
                <div>
                    <h2 id="search-posts-heading">📝 Bài viết</h2>
                    <p><?= $postCount > 0 ? 'Các bài viết phù hợp với từ khóa.' : 'Không tìm thấy bài viết phù hợp.' ?></p>
                </div>
                <?php if ($postTotalPages > 1): ?><span>Trang <?= $postPage ?> / <?= $postTotalPages ?></span><?php endif; ?>
            </div>
            <?php if ($posts !== []): ?>
                <div class="fe-post-grid">
                    <?php foreach ($posts as $post): ?>
                        <article class="fe-post-card">
                            <a href="/post/<?= urlencode($post->slug) ?>" class="fe-post-img-wrap">
                                <?php if ($post->featuredImage !== ''): ?>
                                    <img src="<?= htmlspecialchars($post->featuredImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8') ?>">
                                <?php else: ?>
                                    <div class="fe-img-placeholder">📝</div>
                                <?php endif; ?>
                            </a>
                            <div class="fe-post-body">
                                <h3><a href="/post/<?= urlencode($post->slug) ?>"><?= htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8') ?></a></h3>
                                <p><?= htmlspecialchars($post->excerpt, ENT_QUOTES, 'UTF-8') ?></p>
                                <a href="/post/<?= urlencode($post->slug) ?>" class="fe-read-more">Đọc chi tiết →</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($postTotalPages > 1): ?>
                <nav aria-label="Phân trang bài viết trong kết quả tìm kiếm" class="fe-search-pagination">
                    <?php if ($postPage > 1): ?><a class="fe-btn fe-btn-secondary" href="/search?<?= $paginationQuery($postPage - 1, $productPage) ?>">← Bài viết trước</a><?php endif; ?>
                    <ul class="fe-search-page-list" aria-label="Số trang bài viết">
                        <?php foreach ($paginationPages($postPage, $postTotalPages) as $page): ?>
                            <li><?php if ($page === $postPage): ?><span class="fe-search-page-current" aria-current="page" aria-label="Trang <?= $page ?> hiện tại"><?= $page ?></span><?php else: ?><a href="/search?<?= $paginationQuery($page, $productPage) ?>" aria-label="Trang <?= $page ?> bài viết"><?= $page ?></a><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <span class="fe-search-page-status">Trang <?= $postPage ?> / <?= $postTotalPages ?></span>
                    <?php if ($postPage < $postTotalPages): ?><a class="fe-btn fe-btn-secondary" href="/search?<?= $paginationQuery($postPage + 1, $productPage) ?>">Bài viết sau →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($productsEnabled): ?>
        <section class="fe-section" aria-labelledby="search-products-heading">
            <div class="fe-section-header">
                <div>
                    <h2 id="search-products-heading">🛍️ Sản phẩm</h2>
                    <p><?= $productCount > 0 ? 'Các sản phẩm phù hợp với từ khóa.' : 'Không tìm thấy sản phẩm phù hợp.' ?></p>
                </div>
                <?php if ($productTotalPages > 1): ?><span>Trang <?= $productPage ?> / <?= $productTotalPages ?></span><?php endif; ?>
            </div>
            <?php if ($products !== []): ?>
                <div class="fe-product-grid">
                    <?php foreach ($products as $product): ?>
                        <article class="fe-product-card">
                            <a href="/product/<?= urlencode($product->slug) ?>" class="fe-product-img-wrap">
                                <?php if ($product->isOnSale()): ?><span class="fe-sale-badge">-<?= $product->discountPercent() ?>%</span><?php endif; ?>
                                <?php if ($product->featuredImage !== ''): ?>
                                    <img src="<?= htmlspecialchars($product->featuredImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8') ?>">
                                <?php else: ?>
                                    <div class="fe-img-placeholder">🛍️</div>
                                <?php endif; ?>
                            </a>
                            <div class="fe-product-body">
                                <h3><a href="/product/<?= urlencode($product->slug) ?>"><?= htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8') ?></a></h3>
                                <div class="fe-price-row">
                                    <span class="fe-price-current"><?= $product->formattedEffectivePrice() ?></span>
                                    <?php if ($product->isOnSale()): ?><del class="fe-price-old"><?= $product->formattedRegularPrice() ?></del><?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($productTotalPages > 1): ?>
                <nav aria-label="Phân trang sản phẩm trong kết quả tìm kiếm" class="fe-search-pagination">
                    <?php if ($productPage > 1): ?><a class="fe-btn fe-btn-secondary" href="/search?<?= $paginationQuery($postPage, $productPage - 1) ?>">← Sản phẩm trước</a><?php endif; ?>
                    <ul class="fe-search-page-list" aria-label="Số trang sản phẩm">
                        <?php foreach ($paginationPages($productPage, $productTotalPages) as $page): ?>
                            <li><?php if ($page === $productPage): ?><span class="fe-search-page-current" aria-current="page" aria-label="Trang <?= $page ?> hiện tại"><?= $page ?></span><?php else: ?><a href="/search?<?= $paginationQuery($postPage, $page) ?>" aria-label="Trang <?= $page ?> sản phẩm"><?= $page ?></a><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <span class="fe-search-page-status">Trang <?= $productPage ?> / <?= $productTotalPages ?></span>
                    <?php if ($productPage < $productTotalPages): ?><a class="fe-btn fe-btn-secondary" href="/search?<?= $paginationQuery($postPage, $productPage + 1) ?>">Sản phẩm sau →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!$postsEnabled && !$productsEnabled): ?>
        <div class="fe-empty-box">Tính năng tìm kiếm nội dung hiện không khả dụng.</div>
    <?php elseif ($postCount === 0 && $productCount === 0): ?>
        <div class="fe-empty-box">Không tìm thấy nội dung phù hợp với từ khóa.</div>
    <?php endif; ?>
<?php endif; ?>
