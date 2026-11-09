<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Post[] $posts
 * @var \App\Domain\Entity\Category[] $categories
 * @var int|null $currentCategory
 * @var string $search
 * @var int $currentPage
 * @var int $totalPages
 */
$paginationPages = static function (int $currentPage, int $totalPages): array {
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($end - $start < 4) {
        $start = max(1, $end - 4);
        $end = min($totalPages, $start + 4);
    }

    return range($start, $end);
};
?>
<div class="fe-page-head">
    <h1>📝 Bài viết &amp; Tin tức</h1>
    <p>Cập nhật kiến thức công nghệ, lập trình và xu hướng mới nhất</p>
</div>

<div class="fe-catalog-layout">
    <aside class="fe-sidebar">
        <div class="fe-widget">
            <h3>🔍 Tìm kiếm bài viết</h3>
            <form method="GET" action="/blog">
                <label for="blog-search-query">Từ khóa</label>
                <input id="blog-search-query" type="text" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="Nhập từ khóa..." class="fe-input">
                <button type="submit" class="fe-btn fe-btn-primary fe-btn-block" style="margin-top:8px;">Tìm kiếm</button>
            </form>
        </div>

        <div class="fe-widget">
            <h3>📁 Danh mục bài viết</h3>
            <ul class="fe-cat-list">
                <li><a href="/blog" class="<?= $currentCategory === null ? 'active' : '' ?>">Tất cả chuyên mục</a></li>
                <?php foreach ($categories as $cat): ?>
                    <li>
                        <a href="/blog?category=<?= $cat->id ?>" class="<?= $currentCategory === $cat->id ? 'active' : '' ?>">
                            <?= htmlspecialchars($cat->name) ?> <span>(<?= $cat->itemCount ?>)</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </aside>

    <div class="fe-catalog-main">
        <?php if (empty($posts)): ?>
            <div class="fe-empty-box">Không tìm thấy bài viết nào phù hợp.</div>
        <?php else: ?>
            <div class="fe-post-grid">
                <?php foreach ($posts as $post): ?>
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
                            <a href="/post/<?= urlencode($post->slug) ?>" class="fe-read-more">Đọc chi tiết →</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($totalPages > 1): ?>
            <?php $pageFilters = array_filter(['category' => $currentCategory, 's' => $search], static fn($value): bool => $value !== null && $value !== ''); ?>
            <nav aria-label="Phân trang bài viết" class="fe-search-pagination">
                <?php if ($currentPage > 1): ?>
                    <a class="fe-btn fe-btn-secondary" href="/blog?<?= htmlspecialchars(http_build_query($pageFilters + ['page' => $currentPage - 1]), ENT_QUOTES, 'UTF-8') ?>">← Trang trước</a>
                <?php endif; ?>
                <ul class="fe-search-page-list" aria-label="Số trang bài viết">
                    <?php foreach ($paginationPages($currentPage, $totalPages) as $page): ?>
                        <li><?php if ($page === $currentPage): ?><span class="fe-search-page-current" aria-current="page" aria-label="Trang <?= $page ?> hiện tại"><?= $page ?></span><?php else: ?><a href="/blog?<?= htmlspecialchars(http_build_query($pageFilters + ['page' => $page]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Trang <?= $page ?> bài viết"><?= $page ?></a><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
                <span class="fe-search-page-status">Trang <?= $currentPage ?> / <?= $totalPages ?></span>
                <?php if ($currentPage < $totalPages): ?>
                    <a class="fe-btn fe-btn-secondary" href="/blog?<?= htmlspecialchars(http_build_query($pageFilters + ['page' => $currentPage + 1]), ENT_QUOTES, 'UTF-8') ?>">Trang sau →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</div>
