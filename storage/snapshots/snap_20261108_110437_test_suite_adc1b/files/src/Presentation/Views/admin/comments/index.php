<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Comment[] $comments
 * @var array<string, int> $counts
 * @var string $currentStatus
 * @var string $currentType
 * @var int $currentPage
 * @var int $totalPages
 * @var int $totalItems
 * @var int $pageSize
 */
$paginationQuery = array_filter([
    'status' => $currentStatus,
    'type' => $currentType,
], static fn(string $value): bool => $value !== '');
$rangeStart = $totalItems === 0 ? 0 : (($currentPage - 1) * $pageSize) + 1;
$rangeEnd = min($currentPage * $pageSize, $totalItems);
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">💬 Quản lý Bình luận &amp; Đánh giá 5 sao</h1>
        <p class="wp-page-subtitle">Kiểm duyệt phản hồi bài viết và đánh giá sản phẩm từ khách hàng.</p>
    </div>
</div>

<div class="wp-subsubsub">
    <?php foreach (['' => 'Tất cả', 'approved' => 'Đã duyệt', 'pending' => 'Chờ duyệt', 'spam' => 'Spam'] as $statusKey => $statusLabel): ?>
        <?php $statusQuery = array_filter(['type' => $currentType, 'status' => $statusKey], static fn(string $value): bool => $value !== ''); ?>
        <a href="/admin/comments<?= $statusQuery === [] ? '' : '?' . htmlspecialchars(http_build_query($statusQuery), ENT_QUOTES, 'UTF-8') ?>" class="<?= $currentStatus === $statusKey ? 'current' : '' ?>"><?= $statusLabel ?> (<?= (int) ($counts[$statusKey === '' ? 'all' : $statusKey] ?? 0) ?>)</a><?= $statusKey !== 'spam' ? ' |' : '' ?>
    <?php endforeach; ?>
</div>

<form method="GET" action="/admin/comments" class="wp-filter-bar">
    <?php if ($currentStatus !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <select name="type" class="wp-select">
        <option value="">— Tất cả nội dung —</option>
        <option value="post" <?= $currentType === 'post' ? 'selected' : '' ?>>Bài viết</option>
        <option value="product" <?= $currentType === 'product' ? 'selected' : '' ?>>Sản phẩm</option>
    </select>
    <button type="submit" class="wp-btn wp-btn-secondary">Lọc</button>
</form>

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
                                    <form method="POST" action="/admin/comments/status/<?= $cm->id ?>" style="display:inline;">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                        <input type="hidden" name="status" value="approved">
                                        <button type="submit" class="wp-link">Duyệt</button>
                                    </form> |
                                <?php else: ?>
                                    <form method="POST" action="/admin/comments/status/<?= $cm->id ?>" style="display:inline;">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                        <input type="hidden" name="status" value="pending">
                                        <button type="submit" class="wp-link">Bỏ duyệt</button>
                                    </form> |
                                <?php endif; ?>
                                <form method="POST" action="/admin/comments/status/<?= $cm->id ?>" style="display:inline;">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                    <input type="hidden" name="status" value="spam">
                                    <button type="submit" class="wp-link-danger">Đánh dấu Spam</button>
                                </form> |
                                <form method="POST" action="/admin/comments/delete/<?= $cm->id ?>" style="display:inline;" onsubmit="return confirm('Xóa bình luận này?');">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
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

<div class="wp-pagination" style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
    <span class="wp-text-muted">Hiển thị <?= $rangeStart ?>–<?= $rangeEnd ?> / <?= $totalItems ?> bình luận</span>
    <div style="display:flex; gap:10px; align-items:center;">
        <?php if ($currentPage > 1): ?>
            <a class="wp-btn wp-btn-secondary" href="/admin/comments?<?= htmlspecialchars(http_build_query($paginationQuery + ['page' => $currentPage - 1]), ENT_QUOTES, 'UTF-8') ?>">← Trước</a>
        <?php endif; ?>
        <span>Trang <?= $currentPage ?> / <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?>
            <a class="wp-btn wp-btn-secondary" href="/admin/comments?<?= htmlspecialchars(http_build_query($paginationQuery + ['page' => $currentPage + 1]), ENT_QUOTES, 'UTF-8') ?>">Tiếp →</a>
        <?php endif; ?>
    </div>
</div>
