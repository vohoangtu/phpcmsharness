<?php
declare(strict_types=1);
/** @var \App\Domain\Entity\MediaFile[] $mediaFiles */
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
        <h3>⬆️ Tải tệp hình ảnh mới lên Thư viện</h3>
    </div>
    <form method="POST" action="/admin/media/upload" enctype="multipart/form-data" class="wp-filter-bar">
            <?= \App\Presentation\Http\Csrf::field() ?>
        <input type="file" name="file" accept="image/jpeg,image/png,image/gif,image/webp" required class="wp-input">
        <input type="text" name="alt_text" placeholder="Văn bản thay thế (Alt Text chuẩn SEO)..." class="wp-input" style="min-width:260px;">
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
                            <form method="POST" action="/admin/media/delete/<?= $file->
            <?= \App\Presentation\Http\Csrf::field() ?>id ?>" onsubmit="return confirm('Xóa tệp ảnh này khỏi máy chủ?');">
                                <button type="submit" class="wp-link-danger">Xóa</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
