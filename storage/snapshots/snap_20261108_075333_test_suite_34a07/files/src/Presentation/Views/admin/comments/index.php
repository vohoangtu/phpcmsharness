<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Comment[] $comments
 * @var array<string, int> $counts
 * @var string $currentStatus
 * @var string $currentType
 */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">💬 Quản lý Bình luận &amp; Đánh giá 5 sao</h1>
        <p class="wp-page-subtitle">Kiểm duyệt phản hồi bài viết và đánh giá sản phẩm từ khách hàng.</p>
    </div>
</div>

<div class="wp-subsubsub">
    <a href="/admin/comments" class="<?= $currentStatus === '' ? 'current' : '' ?>">Tất cả (<?= (int) ($counts['all'] ?? 0) ?>)</a> |
    <a href="/admin/comments?status=approved" class="<?= $currentStatus === 'approved' ? 'current' : '' ?>">Đã duyệt (<?= (int) ($counts['approved'] ?? 0) ?>)</a> |
    <a href="/admin/comments?status=pending" class="<?= $currentStatus === 'pending' ? 'current' : '' ?>">Chờ duyệt (<?= (int) ($counts['pending'] ?? 0) ?>)</a> |
    <a href="/admin/comments?status=spam" class="<?= $currentStatus === 'spam' ? 'current' : '' ?>">Spam (<?= (int) ($counts['spam'] ?? 0) ?>)</a>
</div>

<div class="wp-card">
    <?php if (empty($comments)): ?>
        <div class="wp-empty-state">
            <p>Chưa có bình luận hoặc đánh giá nào.</p>
        </div>
    <?php else: ?>
        <table class="wp-table">
            <thead>
                <tr>
                    <th style="width:200px;">Người gửi</th>
                    <th>Nội dung bình luận &amp; Đánh giá</th>
                    <th>Mục đích (Bài viết / Sản phẩm)</th>
                    <th>Trạng thái</th>
                    <th>Thời gian</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comments as $cm): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($cm->authorName) ?></strong><br>
                            <small class="wp-text-muted"><?= htmlspecialchars($cm->authorEmail) ?></small>
                        </td>
                        <td>
                            <?php if ($cm->commentableType === 'product'): ?>
                                <div class="wp-stars"><?= str_repeat('★', $cm->rating) . str_repeat('☆', 5 - $cm->rating) ?></div>
                            <?php endif; ?>
                            <p style="margin:4px 0;"><?= htmlspecialchars($cm->content) ?></p>
                            <div class="wp-row-actions">
                                <?php if ($cm->status->value !== 'approved'): ?>
                                    <form method="POST" action="/admin/comments/status/<?= $cm->
            <?= \App\Presentation\Http\Csrf::field() ?>id ?>" style="display:inline;">
                                        <input type="hidden" name="status" value="approved">
                                        <button type="submit" class="wp-link">Duyệt</button>
                                    </form> |
                                <?php else: ?>
                                    <form method="POST" action="/admin/comments/status/<?= $cm->
            <?= \App\Presentation\Http\Csrf::field() ?>id ?>" style="display:inline;">
                                        <input type="hidden" name="status" value="pending">
                                        <button type="submit" class="wp-link">Bỏ duyệt</button>
                                    </form> |
                                <?php endif; ?>
                                <form method="POST" action="/admin/comments/status/<?= $cm->
            <?= \App\Presentation\Http\Csrf::field() ?>id ?>" style="display:inline;">
                                    <input type="hidden" name="status" value="spam">
                                    <button type="submit" class="wp-link-danger">Đánh dấu Spam</button>
                                </form> |
                                <form method="POST" action="/admin/comments/delete/<?= $cm->
            <?= \App\Presentation\Http\Csrf::field() ?>id ?>" style="display:inline;" onsubmit="return confirm('Xóa bình luận này?');">
                                    <button type="submit" class="wp-link-danger">Xóa</button>
                                </form>
                            </div>
                        </td>
                        <td>
                            <span class="wp-tag-chip"><?= $cm->commentableType === 'product' ? '🛍️ Sản phẩm' : '📝 Bài viết' ?></span><br>
                            <strong><?= htmlspecialchars($cm->targetTitle) ?></strong>
                        </td>
                        <td><span class="wp-status-badge <?= $cm->status->badgeClass() ?>"><?= $cm->status->label() ?></span></td>
                        <td><small><?= htmlspecialchars($cm->createdAt) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
