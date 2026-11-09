<?php
declare(strict_types=1);
/** @var \App\Domain\Entity\MediaFile[] $mediaFiles */
/** @var int $currentPage */
/** @var int $totalPages */
/** @var int $totalItems */
/** @var int $pageSize */
/** @var string $query */
/** @var string $typeFilter */
/** @var string $sortOrder */
/** @var array<int, array{id:int,name:string,parent_id:?int}> $folders */
/** @var int|null $currentFolder */
/** @var \App\Domain\Entity\MediaFile[] $recentFiles */
/** @var \App\Domain\Entity\MediaFile[] $recentlyUsedFiles */
$rangeStart = $totalItems === 0 ? 0 : (($currentPage - 1) * $pageSize) + 1;
$rangeEnd = min($currentPage * $pageSize, $totalItems);
$paginationQuery = http_build_query(['q' => $query, 'type' => $typeFilter, 'sort' => $sortOrder, 'folder' => $currentFolder]);
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">🖼️ Thư viện Media (Media Library)</h1>
        <p class="wp-page-subtitle">Tải lên, xem trước và quản lý hình ảnh dùng trong bài viết và sản phẩm.</p>
    </div>
    <span class="media-library-count"><?= $totalItems ?> <span>tệp trong thư viện</span></span>
</div>

<?php if (!empty($recentFiles)): ?>
    <section class="media-recent-strip"><div><span class="dashboard-section-kicker">RECENTLY ADDED</span><h2>Ảnh mới tải lên</h2></div><div class="media-recent-items"><?php foreach ($recentFiles as $recent): ?><a href="<?= htmlspecialchars($recent->filePath) ?>" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars($recent->originalName) ?>"><img src="<?= htmlspecialchars($recent->filePath) ?>" alt="<?= htmlspecialchars($recent->altText) ?>" loading="lazy"><span><?= htmlspecialchars($recent->originalName) ?></span></a><?php endforeach; ?></div></section>
<?php endif; ?>

<?php if (!empty($recentlyUsedFiles)): ?>
    <section class="media-recent-strip"><div><span class="dashboard-section-kicker">RECENTLY USED</span><h2>Ảnh dùng gần đây</h2></div><div class="media-recent-items"><?php foreach ($recentlyUsedFiles as $recent): ?><a href="<?= htmlspecialchars($recent->filePath) ?>" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars($recent->originalName) ?>"><img src="<?= htmlspecialchars($recent->filePath) ?>" alt="<?= htmlspecialchars($recent->altText) ?>" loading="lazy"><span><?= htmlspecialchars($recent->originalName) ?> · <?= $recent->useCount ?> lần</span></a><?php endforeach; ?></div></section>
<?php endif; ?>

<div class="wp-card media-upload-card">
    <div class="wp-card-header"><div><span class="dashboard-section-kicker">MEDIA MANAGER</span><h2>Tải ảnh lên thư viện</h2></div><span class="media-upload-limit">JPG · PNG · GIF · WEBP / tối đa 5 MiB</span></div>
    <form method="POST" action="/admin/media/upload" enctype="multipart/form-data" class="media-upload-form" data-media-upload-form>
        <?= \App\Presentation\Http\Csrf::field() ?>
        <label class="media-upload-dropzone" for="media-upload-file">
            <span class="media-upload-icon">↑</span><strong data-upload-file-name>Chọn ảnh hoặc kéo thả vào đây</strong><small>Hình ảnh sẽ được lưu trong Media Library</small>
            <input type="file" id="media-upload-file" name="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
        </label>
        <div class="media-upload-previews" data-media-upload-previews aria-live="polite"></div>
        <p class="media-upload-status" data-media-upload-status aria-live="polite"></p>
        <div class="media-upload-fields">
            <div class="wp-form-group"><label for="media-upload-alt-text">Văn bản thay thế</label><input type="text" id="media-upload-alt-text" name="alt_text" placeholder="Mô tả ngắn cho ảnh (không bắt buộc)" class="wp-input"></div>
            <button type="submit" class="wp-btn wp-btn-primary">⬆️ Tải lên ngay</button>
        </div>
    </form>
</div>

<section class="wp-card media-library-card">
    <div class="media-library-section-heading"><div><span class="dashboard-section-kicker">YOUR ASSETS</span><h2>Ảnh trong thư viện</h2></div><span class="wp-text-muted">Hiển thị <?= $rangeStart ?>–<?= $rangeEnd ?> / <?= $totalItems ?></span></div>
    <div class="media-folder-tools">
        <form method="POST" action="/admin/media/folders/create"><?= \App\Presentation\Http\Csrf::field() ?><input class="wp-input" name="name" maxlength="80" placeholder="Tên thư mục mới" required><button class="wp-btn wp-btn-secondary" type="submit">＋ Tạo thư mục</button></form>
        <nav class="media-folder-nav"><a class="<?= $currentFolder === null ? 'is-active' : '' ?>" href="/admin/media">Tất cả ảnh</a><?php foreach ($folders as $folder): ?><a class="<?= $currentFolder === $folder['id'] ? 'is-active' : '' ?>" href="/admin/media?folder=<?= $folder['id'] ?>"><?= htmlspecialchars($folder['name']) ?></a><?php endforeach; ?></nav>
    </div>
    <form method="GET" action="/admin/media" class="media-library-filters">
        <input type="search" name="q" value="<?= htmlspecialchars($query) ?>" class="wp-input" placeholder="Tìm theo tên, URL hoặc mô tả ảnh…">
        <?php if ($currentFolder !== null): ?><input type="hidden" name="folder" value="<?= $currentFolder ?>"><?php endif; ?>
        <select name="type" class="wp-select"><option value="all">Tất cả định dạng</option><?php foreach (['jpeg' => 'JPG', 'png' => 'PNG', 'gif' => 'GIF', 'webp' => 'WEBP'] as $key => $label): ?><option value="<?= $key ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
        <select name="sort" class="wp-select"><option value="newest" <?= $sortOrder === 'newest' ? 'selected' : '' ?>>Mới tải lên</option><option value="oldest" <?= $sortOrder === 'oldest' ? 'selected' : '' ?>>Cũ nhất</option><option value="name" <?= $sortOrder === 'name' ? 'selected' : '' ?>>Tên A–Z</option><option value="largest" <?= $sortOrder === 'largest' ? 'selected' : '' ?>>Dung lượng lớn nhất</option></select>
        <button class="wp-btn wp-btn-primary" type="submit">Lọc thư viện</button>
        <button class="wp-btn wp-btn-secondary" type="button" data-media-view="grid">Lưới</button><button class="wp-btn wp-btn-secondary" type="button" data-media-view="list">Danh sách</button>
    </form>
    <?php if (!empty($mediaFiles)): ?>
        <div class="media-bulk-toolbar"><label><input type="checkbox" data-media-select-all> Chọn tất cả trang</label><span data-media-selected-count>Chưa chọn ảnh</span><form method="POST" action="/admin/media/move-bulk" data-media-move-form><?= \App\Presentation\Http\Csrf::field() ?><div data-media-move-ids></div><select name="folder_id" class="wp-select"><option value="">Không có thư mục</option><?php foreach ($folders as $folder): ?><option value="<?= $folder['id'] ?>"><?= htmlspecialchars($folder['name']) ?></option><?php endforeach; ?></select><button type="submit" class="wp-btn wp-btn-secondary" disabled data-media-move>Chuyển</button></form><form method="POST" action="/admin/media/delete-bulk" data-media-bulk-form><?= \App\Presentation\Http\Csrf::field() ?><div data-media-bulk-ids></div><button type="submit" class="wp-btn wp-btn-danger-outline" disabled data-media-bulk-delete>Xóa ảnh đã chọn</button></form></div>
    <?php endif; ?>
    <?php if (empty($mediaFiles)): ?>
        <div class="wp-empty-state media-library-empty"><div class="wp-empty-icon">🖼️</div><p>Thư viện đang trống. Tải ảnh đầu tiên lên từ khu vực phía trên.</p></div>
    <?php else: ?>
        <div class="wp-media-grid">
            <?php foreach ($mediaFiles as $file): ?>
                <article class="wp-media-item" data-media-item>
                    <label class="media-item-select"><input type="checkbox" data-media-select value="<?= $file->id ?>" aria-label="Chọn <?= htmlspecialchars($file->originalName) ?>"></label>
                    <div class="wp-media-thumb">
                        <button type="button" class="media-preview-open" data-media-preview="<?= htmlspecialchars($file->filePath) ?>" data-media-name="<?= htmlspecialchars($file->originalName) ?>" data-media-alt="<?= htmlspecialchars($file->altText) ?>" data-media-size="<?= htmlspecialchars($file->formattedSize()) ?>" data-media-dimensions="<?= $file->width ?? '—' ?> × <?= $file->height ?? '—' ?> px"><img src="<?= htmlspecialchars($file->filePath) ?>" alt="<?= htmlspecialchars($file->altText) ?>" loading="lazy"<?= $file->width !== null && $file->width > 0 && $file->height !== null && $file->height > 0 ? ' width="' . $file->width . '" height="' . $file->height . '"' : '' ?>><span>Xem trước</span></button>
                    </div>
                    <div class="wp-media-meta">
                        <strong title="<?= htmlspecialchars($file->originalName) ?>"><?= htmlspecialchars($file->originalName) ?></strong>
                        <small><?= $file->formattedSize() ?> · <?= $file->width ?? '—' ?> × <?= $file->height ?? '—' ?> px</small>
                        <form method="POST" action="/admin/media/metadata/<?= $file->id ?>" class="media-metadata-form"><?= \App\Presentation\Http\Csrf::field() ?><input name="alt_text" value="<?= htmlspecialchars($file->altText) ?>" class="wp-input" aria-label="Văn bản thay thế" placeholder="Văn bản thay thế"><input name="tags" value="<?= htmlspecialchars($file->tags) ?>" class="wp-input" aria-label="Tags" placeholder="Tags, cách nhau bằng dấu phẩy"><button class="wp-btn wp-btn-secondary" type="submit">Lưu thông tin</button></form>
                        <div class="media-library-url"><input type="text" readonly value="<?= htmlspecialchars($file->filePath) ?>" class="wp-input wp-input-sm" onclick="this.select()" aria-label="URL ảnh"><button type="button" class="wp-btn wp-btn-secondary" data-copy-media-url="<?= htmlspecialchars($file->filePath) ?>">Sao chép</button></div>
                        <div class="wp-media-actions">
                            <a href="<?= htmlspecialchars($file->filePath) ?>" target="_blank" rel="noopener noreferrer" class="wp-link">Mở ảnh ↗</a>
                            <button type="button" class="wp-link" data-edit-image="<?= htmlspecialchars($file->filePath) ?>" data-edit-alt="<?= htmlspecialchars($file->altText) ?>" data-edit-name="<?= htmlspecialchars($file->originalName) ?>">Chỉnh ảnh</button>
                            <form method="POST" action="/admin/media/delete/<?= $file->id ?>" onsubmit="return confirm('Xóa tệp ảnh này khỏi máy chủ?');">
                                <?= \App\Presentation\Http\Csrf::field() ?>
                                <button type="submit" class="wp-link-danger">Xóa ảnh</button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<nav class="wp-pagination media-library-pagination" aria-label="Phân trang thư viện Media">
    <span class="wp-text-muted">Hiển thị <?= $rangeStart ?>–<?= $rangeEnd ?> trên <?= $totalItems ?> tệp</span>
    <div>
        <?php if ($currentPage > 1): ?>
            <a class="wp-btn wp-btn-secondary" href="/admin/media?page=<?= $currentPage - 1 ?>&amp;<?= htmlspecialchars($paginationQuery) ?>">← Trước</a>
        <?php else: ?>
            <span class="wp-btn wp-btn-secondary" aria-disabled="true">← Trước</span>
        <?php endif; ?>
        <span>Trang <?= $currentPage ?> / <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?>
            <a class="wp-btn wp-btn-secondary" href="/admin/media?page=<?= $currentPage + 1 ?>&amp;<?= htmlspecialchars($paginationQuery) ?>">Tiếp →</a>
        <?php else: ?>
            <span class="wp-btn wp-btn-secondary" aria-disabled="true">Tiếp →</span>
        <?php endif; ?>
    </div>
</nav>

<dialog class="media-preview-dialog" data-media-preview-dialog>
    <button type="button" class="wp-btn wp-btn-secondary" data-media-preview-close>Đóng</button>
    <img data-media-preview-image alt="">
    <div><h2 data-media-preview-name></h2><p data-media-preview-meta></p><p data-media-preview-alt></p></div>
</dialog>

<dialog class="media-edit-dialog" data-media-edit-dialog>
    <form method="dialog" class="media-edit-header"><div><span class="dashboard-section-kicker">IMAGE STUDIO</span><h2>Chỉnh sửa ảnh</h2></div><button class="wp-btn wp-btn-secondary">Đóng</button></form>
    <div class="media-edit-layout"><div class="media-edit-canvas-wrap"><canvas data-edit-canvas></canvas></div><aside class="media-edit-controls">
        <label>Cắt ảnh — X<input type="number" min="0" value="0" data-crop-x></label><label>Y<input type="number" min="0" value="0" data-crop-y></label><label>Chiều rộng<input type="number" min="1" data-crop-width></label><label>Chiều cao<input type="number" min="1" data-crop-height></label>
        <label>Tỉ lệ cắt<select data-crop-ratio><option value="free">Tự do</option><option value="1">Vuông 1:1</option><option value="1.333333">Ngang 4:3</option><option value="1.777778">Ngang 16:9</option><option value="0.75">Dọc 3:4</option></select></label>
        <label>Xoay<select data-rotate><option value="0">Không xoay</option><option value="90">90°</option><option value="180">180°</option><option value="270">270°</option></select></label><label>Chiều rộng đầu ra tối đa<input type="number" min="100" max="4000" value="1600" data-output-width></label>
        <label>Định dạng<select data-output-format><option value="image/webp">WebP</option><option value="image/jpeg">JPEG</option></select></label><label>Chất lượng nén <output data-quality-value>86%</output><input type="range" min="45" max="100" value="86" data-output-quality></label>
        <button type="button" class="wp-btn wp-btn-secondary" data-edit-reset>Đặt lại vùng cắt</button><button type="button" class="wp-btn wp-btn-primary" data-edit-save>Lưu thành ảnh mới</button><p data-edit-status aria-live="polite"></p>
    </aside></div>
</dialog>
