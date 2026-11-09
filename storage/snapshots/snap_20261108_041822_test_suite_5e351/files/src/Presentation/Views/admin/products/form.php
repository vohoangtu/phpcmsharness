<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Product|null $product
 * @var \App\Domain\Entity\Category[] $categories
 * @var \App\Domain\Entity\MediaFile[] $mediaFiles
 * @var array<string, bool> $enabledModules
 */
$isEdit = $product !== null;
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title"><?= $isEdit ? '✏️ Chỉnh sửa Sản phẩm' : '🛍️ Thêm Sản phẩm mới' ?></h1>
    </div>
    <div class="wp-header-actions">
        <?php if ($isEdit): ?>
            <a href="/product/<?= urlencode($product->slug) ?>" target="_blank" class="wp-btn wp-btn-secondary">Xem ngoài Cửa hàng ↗</a>
        <?php endif; ?>
        <a href="/admin/products" class="wp-btn wp-btn-secondary">← Danh sách sản phẩm</a>
    </div>
</div>

<form method="POST" action="/admin/products/save" enctype="multipart/form-data" class="wp-editor-layout">
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $product->id ?>">
    <?php endif; ?>

    <!-- Left Column -->
    <div class="wp-editor-main">
        <div class="wp-card wp-mb-20">
            <div class="wp-form-group">
                <label>Tên sản phẩm</label>
                <input
                    type="text"
                    name="name"
                    id="post-title"
                    value="<?= htmlspecialchars($product->name ?? '') ?>"
                    placeholder="Nhập tên sản phẩm..."
                    required
                    class="wp-input wp-title-input"
                >
            </div>
            <div class="wp-permalink-row">
                <span>🔗 Đường dẫn sản phẩm (Slug): <code>/product/</code></span>
                <input
                    type="text"
                    name="slug"
                    id="post-slug"
                    value="<?= htmlspecialchars($product->slug ?? '') ?>"
                    placeholder="tu-dong-tao-tu-ten-san-pham"
                    class="wp-input wp-slug-input"
                >
            </div>
        </div>

        <!-- WooCommerce Style Product Data Box -->
        <div class="wp-card wp-mb-20">
            <div class="wp-card-header">
                <h3>📦 Dữ liệu Sản phẩm (Giá bán &amp; Kho hàng - WooCommerce Style)</h3>
            </div>
            <div class="wp-grid-2">
                <div class="wp-form-group">
                    <label>Giá bán gốc (VNĐ) *</label>
                    <input type="number" step="1000" name="price" value="<?= htmlspecialchars((string) ($product->price ?? 0)) ?>" required class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label>Giá khuyến mãi (VNĐ - để trống nếu không giảm)</label>
                    <input type="number" step="1000" name="sale_price" value="<?= htmlspecialchars((string) ($product->salePrice ?? '')) ?>" class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label>Mã sản phẩm (SKU)</label>
                    <input type="text" name="sku" value="<?= htmlspecialchars($product->sku ?? '') ?>" placeholder="VD: MBP-M4-16" class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label>Số lượng tồn kho</label>
                    <input type="number" name="stock_quantity" value="<?= htmlspecialchars((string) ($product->stockQuantity ?? 10)) ?>" required class="wp-input">
                </div>
            </div>
            <div class="wp-form-group">
                <label class="wp-checkbox-label">
                    <input type="checkbox" name="is_featured" value="1" <?= ($product?->isFeatured ?? false) ? 'checked' : '' ?>>
                    ⭐ Đánh dấu là <strong>Sản phẩm nổi bật</strong> ngoài Trang chủ
                </label>
            </div>
        </div>

        <!-- Rich Text Product Description -->
        <div class="wp-card wp-mb-20">
            <div class="wp-card-header">
                <h3>📄 Mô tả chi tiết sản phẩm</h3>
            </div>
            <div class="wp-editor-toolbar">
                <button type="button" onclick="formatDoc('bold')"><b>B</b></button>
                <button type="button" onclick="formatDoc('italic')"><i>I</i></button>
                <button type="button" onclick="formatDoc('formatBlock', 'H3')">H3</button>
                <button type="button" onclick="formatDoc('insertUnorderedList')">• Danh sách</button>
                <button type="button" onclick="toggleSourceMode()" class="wp-toolbar-right">«/» Mã HTML</button>
            </div>
            <div
                id="visual-editor"
                class="wp-visual-editor"
                contenteditable="true"
                oninput="syncEditorContent()"
            ><?= $product->description ?? '<p>Thông số kỹ thuật và mô tả chi tiết sản phẩm...</p>' ?></div>
            <textarea
                name="description"
                id="raw-content"
                rows="10"
                class="wp-textarea"
                style="display:none;"
            ><?= htmlspecialchars($product->description ?? '<p>Thông số kỹ thuật và mô tả chi tiết sản phẩm...</p>') ?></textarea>
        </div>

        <div class="wp-card">
            <div class="wp-form-group">
                <label>Mô tả ngắn (Short Description)</label>
                <textarea name="short_description" rows="3" class="wp-textarea" placeholder="Hiển thị ngay cạnh giá sản phẩm..."><?= htmlspecialchars($product->shortDescription ?? '') ?></textarea>
            </div>
            <div class="wp-form-group">
                <label>Album ảnh phụ (Mỗi dòng 1 URL hình ảnh)</label>
                <textarea name="gallery_images" rows="3" class="wp-textarea" placeholder="https://..."><?= htmlspecialchars(implode("\n", $product?->galleryImages ?? [])) ?></textarea>
            </div>
        </div>
    </div>

    <!-- Right Sidebar -->
    <div class="wp-editor-sidebar">
        <div class="wp-card wp-mb-20">
            <div class="wp-card-header">
                <h3>📢 Xuất bản Sản phẩm</h3>
            </div>
            <div class="wp-form-group">
                <label>Trạng thái hiển thị</label>
                <select name="status" class="wp-select">
                    <option value="published" <?= ($product?->status->value ?? 'published') === 'published' ? 'selected' : '' ?>>Đang bán (Published)</option>
                    <option value="draft" <?= ($product?->status->value ?? '') === 'draft' ? 'selected' : '' ?>>Bản nháp (Draft)</option>
                    <option value="trash" <?= ($product?->status->value ?? '') === 'trash' ? 'selected' : '' ?>>Thùng rác (Trash)</option>
                </select>
            </div>
            <button type="submit" class="wp-btn wp-btn-primary wp-btn-block" onclick="syncEditorContent()">
                💾 <?= $isEdit ? 'Cập nhật Sản phẩm' : 'Đăng Sản phẩm' ?>
            </button>
        </div>

        <?php if (!empty($enabledModules['categories_tags'])): ?>
            <div class="wp-card wp-mb-20">
                <div class="wp-card-header">
                    <h3>📁 Danh mục sản phẩm</h3>
                    <a href="/admin/categories?type=product" class="wp-link">+ Thêm mới</a>
                </div>
                <div class="wp-form-group">
                    <select name="category_id" class="wp-select">
                        <option value="">— Chọn danh mục —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat->id ?>" <?= ($product?->categoryId ?? null) === $cat->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="wp-card wp-mb-20">
                <div class="wp-card-header">
                    <h3>🏷️ Từ khóa / Thẻ sản phẩm</h3>
                </div>
                <div class="wp-form-group">
                    <input
                        type="text"
                        name="tags"
                        value="<?= htmlspecialchars(implode(', ', $product?->tags ?? [])) ?>"
                        placeholder="Laptop, Apple, M4 Max..."
                        class="wp-input"
                    >
                </div>
            </div>
        <?php endif; ?>

        <div class="wp-card">
            <div class="wp-card-header">
                <h3>🖼️ Ảnh sản phẩm chính</h3>
            </div>
            <div class="wp-featured-preview">
                <?php if (!empty($product?->featuredImage)): ?>
                    <img src="<?= htmlspecialchars($product->featuredImage) ?>" id="featured-preview-img" alt="">
                <?php else: ?>
                    <img src="" id="featured-preview-img" alt="" style="display:none;">
                <?php endif; ?>
            </div>
            <div class="wp-form-group">
                <label>URL ảnh sản phẩm</label>
                <input
                    type="text"
                    name="featured_image"
                    id="featured-image-input"
                    value="<?= htmlspecialchars($product->featuredImage ?? '') ?>"
                    placeholder="https://... hoặc /uploads/..."
                    class="wp-input"
                    oninput="previewFeaturedUrl(this.value)"
                >
            </div>
            <div class="wp-form-group">
                <label>Tải ảnh mới từ máy tính</label>
                <input type="file" name="featured_image_file" accept="image/*" class="wp-input">
            </div>
            <?php if (!empty($mediaFiles)): ?>
                <div class="wp-media-mini-picker">
                    <small>Chọn nhanh từ Thư viện Media:</small>
                    <div class="wp-media-mini-grid">
                        <?php foreach (array_slice($mediaFiles, 0, 6) as $mf): ?>
                            <img
                                src="<?= htmlspecialchars($mf->filePath) ?>"
                                title="<?= htmlspecialchars($mf->originalName) ?>"
                                onclick="selectFeaturedImage('<?= htmlspecialchars($mf->filePath) ?>')"
                            >
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>
