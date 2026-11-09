<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Post[] $posts
 * @var \App\Domain\Entity\Category[] $categories
 * @var int|null $currentCategory
 * @var string $search
 */
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
                <input type="text" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="Nhập từ khóa..." class="fe-input">
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
    </div>
</div>
