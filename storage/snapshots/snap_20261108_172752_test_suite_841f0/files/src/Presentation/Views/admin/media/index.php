<?php
declare(strict_types=1);
/** @var \App\Domain\Entity\MediaFile[] $mediaFiles */
/** @var int $currentPage */
/** @var int $totalPages */
/** @var int $totalItems */
/** @var int $pageSize */
$rangeStart = $totalItems === 0 ? 0 : (($currentPage - 1) * $pageSize) + 1;
$rangeEnd = min($currentPage * $pageSize, $totalItems);
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">🖼️ Thư viện Media (Media Library)</h1>
        <p class="wp-page-subtitle">Quản lý hình ảnh tải lên phục vụ cho Bài viết và Sản phẩm.</p>
    </div>
</div>

<!-- Upload Box -->
<div class="wp-card wp-mb-24">
    <div class="wp-card-header">
        <h2>⬆️ Tải tệp hình ảnh mới lên Thư viện</h2>
    </div>
    <form method="POST" action="/admin/media/upload" enctype="multipart/form-data" class="wp-filter-bar">
            <?= \App\Presentation\Http\Csrf::field() ?>
        <div class="wp-form-group" style="flex:1; min-width:260px; margin-bottom:0;">
            <label for="media-upload-file">Chọn tệp hình ảnh</label>
            <input type="file" id="media-upload-file" name="file" accept="image/jpeg,image/png,image/gif,image/webp" required class="wp-input">
            <small class="wp-text-muted">Dung lượng tối đa: 5 MiB cho mỗi ảnh.</small>
        </div>
        <div class="wp-form-group" style="flex:1; min-width:260px; margin-bottom:0;">
            <label for="media-upload-alt-text">Văn bản thay thế (không bắt buộc)</label>
            <input type="text" id="media-upload-alt-text" name="alt_text" placeholder="Mô tả hình ảnh..." class="wp-input">
        </div>
        <button type="submit" class="wp-btn wp-btn-primary">Tải lên ngay</button>
    </form>
</div>

<!-- Media Grid -->
<div class="wp-card">
    <?php if (empty($mediaFiles)): ?>
        <div class="wp-empty-state">
            <div class="wp-empty-icon">🖼️</div>
            <p>Thư viện Media hiện chưa có tệp tải lên nào. Hãy tải lên hình ảnh đầu tiên ở khung phía trên!</p>
        </div>
    <?php else: ?>
        <div class="wp-media-grid">
            <?php foreach ($mediaFiles as $file): ?>
                <div class="wp-media-item">
                    <div class="wp-media-thumb">
                        <img src="<?= htmlspecialchars($file->filePath) ?>" alt="<?= htmlspecialchars($file->altText) ?>">
                    </div>
                    <div class="wp-media-meta">
                        <strong title="<?= htmlspecialchars($file->originalName) ?>"><?= htmlspecialchars($file->originalName) ?></strong>
                        <small><?= $file->formattedSize() ?> • <?= htmlspecialchars($file->createdAt) ?></small>
                        <input type="text" readonly value="<?= htmlspecialchars($file->filePath) ?>" class="wp-input wp-input-sm" onclick="this.select()">
                        <div class="wp-media-actions">
                            <a href="<?= htmlspecialchars($file->filePath) ?>" target="_blank" class="wp-link">Xem gốc ↗</a>
                            <form method="POST" action="/admin/media/delete/<?= $file->id ?>" onsubmit="return confirm('Xóa tệp ảnh này khỏi máy chủ?');">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                <button type="submit" class="wp-link-danger">Xóa</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<nav class="wp-pagination" aria-label="Phân trang thư viện Media" style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:16px;">
    <span class="wp-text-muted">Hiển thị <?= $rangeStart ?>–<?= $rangeEnd ?> trên <?= $totalItems ?> tệp</span>
    <div style="display:flex; align-items:center; gap:8px;">
        <?php if ($currentPage > 1): ?>
            <a class="wp-btn wp-btn-secondary" href="/admin/media?page=<?= $currentPage - 1 ?>">← Trước</a>
        <?php else: ?>
            <span class="wp-btn wp-btn-secondary" aria-disabled="true">← Trước</span>
        <?php endif; ?>
        <span>Trang <?= $currentPage ?> / <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?>
            <a class="wp-btn wp-btn-secondary" href="/admin/media?page=<?= $currentPage + 1 ?>">Tiếp →</a>
        <?php else: ?>
            <span class="wp-btn wp-btn-secondary" aria-disabled="true">Tiếp →</span>
        <?php endif; ?>
    </div>
</nav>
