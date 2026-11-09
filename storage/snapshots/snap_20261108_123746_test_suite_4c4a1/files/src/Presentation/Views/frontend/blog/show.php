<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Post $post
 * @var \App\Domain\Entity\Comment[] $comments
 * @var array<string, bool> $enabledModules
 */
?>
<article class="fe-single-post">
    <div class="fe-single-header">
        <a href="/blog" class="fe-back-link">← Quay lại danh sách Bài viết</a>
        <div class="fe-post-meta" style="margin-top:12px;">
            <span>📁 <?= htmlspecialchars($post->categoryName ?? 'Tin tức') ?></span>
            <span>✍️ Tác giả: <strong><?= htmlspecialchars($post->authorName) ?></strong></span>
            <span>🕒 <?= htmlspecialchars($post->createdAt) ?></span>
            <span>👁️ <?= $post->views ?> lượt xem</span>
        </div>
        <h1><?= htmlspecialchars($post->title) ?></h1>
    </div>

    <?php if ($post->featuredImage !== ''): ?>
        <div class="fe-single-hero-img">
            <img src="<?= htmlspecialchars($post->featuredImage) ?>" alt="<?= htmlspecialchars($post->title) ?>">
        </div>
    <?php endif; ?>

    <div class="fe-single-content">
        <?= $post->content ?>
    </div>

    <?php if (!empty($post->tags) && !empty($enabledModules['categories_tags'])): ?>
        <div class="fe-single-tags">
            <strong>🏷️ Thẻ từ khóa:</strong>
            <?php foreach ($post->tags as $t): ?>
                <span class="fe-tag-badge"><?= htmlspecialchars($t) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>

<?php if (!empty($enabledModules['comments'])): ?>
    <section id="comments" class="fe-comments-section">
        <h2>💬 Bình luận (<?= count($comments) ?>)</h2>

        <div class="fe-comment-list">
            <?php if (empty($comments)): ?>
                <p class="fe-text-muted">Chưa có bình luận nào. Hãy là người đầu tiên chia sẻ ý kiến!</p>
            <?php else: ?>
                <?php foreach ($comments as $cm): ?>
                    <div class="fe-comment-item">
                        <div class="fe-comment-head">
                            <strong><?= htmlspecialchars($cm->authorName) ?></strong>
                            <small><?= htmlspecialchars($cm->createdAt) ?></small>
                        </div>
                        <p><?= nl2br(htmlspecialchars($cm->content)) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form method="POST" action="/post/<?= urlencode($post->slug) ?>/comment" class="fe-comment-form">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
            <h3>Để lại bình luận của bạn</h3>
            <div class="fe-grid-2">
                <div>
                    <label>Họ và tên *</label>
                    <input type="text" name="author_name" required class="fe-input">
                </div>
                <div>
                    <label>Email *</label>
                    <input type="email" name="author_email" required class="fe-input">
                </div>
            </div>
            <div style="margin-top:12px;">
                <label>Nội dung bình luận *</label>
                <textarea name="content" rows="4" required class="fe-textarea"></textarea>
            </div>
            <button type="submit" class="fe-btn fe-btn-primary" style="margin-top:12px;">Gửi bình luận</button>
        </form>
    </section>
<?php endif; ?>
