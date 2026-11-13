<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Product|null $product
 * @var \App\Domain\Entity\Category[] $categories
 * @var array<string, bool> $enabledModules
 */
$isEdit = $product !== null;
$featuredImage = $product?->featuredImage ?? '';
$galleryImages = $product?->galleryImages ?? [];
$productDescription = $product->description ?? '<p>Thông số kỹ thuật và mô tả chi tiết sản phẩm...</p>';
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title"><?= $isEdit ? '✏️ Chỉnh sửa Sản phẩm' : '🛍️ Thêm Sản phẩm mới' ?></h1>
        <p class="wp-page-subtitle">Quản lý giá, kho hàng, nội dung, hình ảnh và SEO sản phẩm.</p>
    </div>
    <div class="wp-header-actions">
        <?php if ($isEdit): ?>
            <a href="/product/<?= urlencode($product->slug) ?>" target="_blank" class="wp-btn wp-btn-secondary">Xem ngoài Cửa hàng ↗</a>
        <?php endif; ?>
        <a href="/admin/products" class="wp-btn wp-btn-secondary">← Danh sách sản phẩm</a>
    </div>
</div>

<form method="POST" action="/admin/products/save" enctype="multipart/form-data" class="wp-editor-layout product-editor-form">
            <?= \App\Presentation\Http\Csrf::field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $product->id ?>">
    <?php endif; ?>

    <!-- Left Column -->
    <div class="wp-editor-main">
        <div class="wp-card wp-mb-20">
            <div class="wp-form-group">
                <label for="post-title">Tên sản phẩm</label>
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
                <label for="post-slug">🔗 Đường dẫn sản phẩm (Slug): <code>/product/</code></label>
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
                <h2>📦 Dữ liệu Sản phẩm (Giá bán &amp; Kho hàng - WooCommerce Style)</h2>
            </div>
            <div class="wp-grid-2">
                <div class="wp-form-group">
                    <label for="product-price">Giá bán gốc (VNĐ) *</label>
                    <input type="number" step="1000" name="price" id="product-price" value="<?= htmlspecialchars((string) ($product->price ?? 0)) ?>" required class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label for="product-sale-price">Giá khuyến mãi (VNĐ - để trống nếu không giảm)</label>
                    <input type="number" step="1000" name="sale_price" id="product-sale-price" value="<?= htmlspecialchars((string) ($product->salePrice ?? '')) ?>" class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label for="product-sku">Mã sản phẩm (SKU)</label>
                    <input type="text" name="sku" id="product-sku" value="<?= htmlspecialchars($product->sku ?? '') ?>" placeholder="VD: MBP-M4-16" class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label for="product-stock-quantity">Số lượng tồn kho</label>
                    <input type="number" name="stock_quantity" id="product-stock-quantity" value="<?= htmlspecialchars((string) ($product->stockQuantity ?? 10)) ?>" required class="wp-input">
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
                <h2>📄 Mô tả chi tiết sản phẩm</h2>
            </div>
            <label id="product-description-label" for="raw-content">Mô tả chi tiết sản phẩm</label>
            <div class="wp-rich-text-editor" data-rich-text-editor data-source-id="raw-content">
                <div class="wp-editor-toolbar" data-rich-text-toolbar role="toolbar" aria-label="Công cụ định dạng mô tả sản phẩm">
                    <div class="wp-toolbar-group" aria-label="Kiểu chữ"><button type="button" data-editor-command="bold"><b>B</b></button><button type="button" data-editor-command="italic"><i>I</i></button><button type="button" data-editor-command="underline"><u>U</u></button><button type="button" data-editor-command="strike"><s>S</s></button><select data-editor-font-family aria-label="Phông chữ"><option value="">Phông chữ</option><option value="Arial, sans-serif">Arial</option><option value="Georgia, serif">Georgia</option><option value="'Times New Roman', serif">Times New Roman</option><option value="Verdana, sans-serif">Verdana</option></select><select data-editor-font-size aria-label="Cỡ chữ"><option value="">Cỡ chữ</option><option value="12px">12</option><option value="14px">14</option><option value="16px">16</option><option value="18px">18</option><option value="24px">24</option><option value="32px">32</option></select></div>
                    <div class="wp-toolbar-group" aria-label="Định dạng đoạn"><select data-editor-command="heading" aria-label="Kiểu tiêu đề"><option value="p">Đoạn văn</option><option value="h2">Tiêu đề 2</option><option value="h3">Tiêu đề 3</option><option value="h4">Tiêu đề 4</option></select><button type="button" data-editor-command="bulletList">• Danh sách</button><button type="button" data-editor-command="orderedList">1. Danh sách</button><button type="button" data-editor-command="blockquote">Trích dẫn</button><button type="button" data-editor-command="alignLeft">☰</button><button type="button" data-editor-command="alignCenter">≡</button><button type="button" data-editor-command="alignRight">☷</button></div>
                    <div class="wp-toolbar-group" aria-label="Chèn nội dung"><button type="button" data-editor-command="link">🔗 Liên kết</button><button type="button" data-editor-command="unlink">Bỏ link</button><button type="button" data-editor-command="insertTable">▦ Bảng</button><button type="button" data-editor-command="addRow">＋ Hàng</button><button type="button" data-editor-command="deleteTable">Xóa bảng</button><button type="button" data-editor-command="image" title="Chọn ảnh từ Media Library; kéo góc ảnh để đổi kích thước">🖼 Ảnh</button><button type="button" data-editor-command="youtube">▶ Video</button></div>
                    <div class="wp-toolbar-group" aria-label="Màu và hoàn tác"><label class="wp-editor-color" title="Màu chữ">A <input type="color" data-editor-color value="#303754" aria-label="Màu chữ"></label><label class="wp-editor-color" title="Tô sáng">▰ <input type="color" data-editor-highlight value="#fff2a8" aria-label="Màu nền chữ"></label><button type="button" data-editor-command="clearFormatting" title="Xóa định dạng">Tx</button><button type="button" data-editor-command="undo">↶</button><button type="button" data-editor-command="redo">↷</button></div><span class="wp-editor-word-count" data-editor-word-count aria-live="polite"></span><button type="button" data-editor-command="source" class="wp-toolbar-right">&lt;/&gt; HTML</button>
                </div>
                <div id="visual-editor" class="wp-visual-editor" data-rich-text-surface role="textbox" aria-multiline="true" aria-labelledby="product-description-label" hidden></div>
            <textarea
                name="description"
                id="raw-content"
                rows="10"
                class="wp-textarea"
            ><?= htmlspecialchars($productDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>
        </div>

        <div class="wp-card">
            <div class="wp-card-header"><h2>✍️ Mô tả ngắn sản phẩm</h2></div>
            <div class="wp-form-group">
                <label for="product-short-description">Mô tả ngắn (Short Description)</label>
                <textarea name="short_description" id="product-short-description" rows="3" class="wp-textarea" placeholder="Hiển thị ngay cạnh giá sản phẩm..."><?= htmlspecialchars($product->shortDescription ?? '') ?></textarea>
            </div>
            <div class="product-gallery-manager">
                <div class="product-gallery-heading">
                    <div><h2>Album ảnh phụ</h2><p>Kéo ảnh vào đây, tải từ thiết bị hoặc chọn trong thư viện. Kéo thumbnail để đổi thứ tự.</p></div>
                    <button type="button" class="wp-btn wp-btn-secondary" data-open-media-picker="gallery">＋ Chọn từ Media</button>
                </div>
                <div class="product-gallery-dropzone" data-product-dropzone="gallery">
                    <input type="file" id="product-gallery-files" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden data-product-image-input="gallery">
                    <label for="product-gallery-files" class="product-gallery-drop-label">⬆️ Thả ảnh vào đây hoặc <span>chọn tệp</span><small>JPG, PNG, GIF, WEBP · tối đa 5 MiB mỗi ảnh</small></label>
                </div>
                <div class="product-gallery-grid" id="product-gallery-grid" aria-live="polite"></div>
                <p class="product-image-status" data-product-image-status="gallery" aria-live="polite"></p>
                <textarea name="gallery_images" id="product-gallery-images" hidden><?= htmlspecialchars(implode("\n", $galleryImages)) ?></textarea>
            </div>
        </div>
        <div class="wp-card wp-mb-20 seo-editor-card">
            <div class="wp-card-header"><h2>🔎 SEO &amp; chia sẻ mạng xã hội</h2><div class="ai-assistant-actions"><button type="button" class="wp-btn wp-btn-secondary" data-ai-action="product_seo">✨ Gợi ý SEO</button><button type="button" class="wp-btn wp-btn-secondary" data-ai-action="content_audit">🔎 Phân tích nội dung</button><button type="button" class="wp-btn wp-btn-secondary" data-ai-action="keyword_ideas">🏷️ Nghiên cứu từ khóa</button></div></div>
            <p class="wp-text-muted">Khi được yêu cầu, AI gửi tên, mô tả và thẻ sản phẩm tới provider đã cấu hình. Bạn duyệt đề xuất trước khi áp dụng.</p>
            <div class="wp-form-group"><label for="product-seo-title">SEO title</label><input id="product-seo-title" name="seo_title" class="wp-input" maxlength="70" value="<?= htmlspecialchars($product->seoTitle ?? '') ?>" placeholder="Để trống để dùng tên sản phẩm"><small class="wp-text-muted">Tùy chọn · tối đa 70 ký tự.</small></div>
            <div class="wp-form-group"><label for="product-seo-description">Meta description</label><textarea id="product-seo-description" name="seo_description" class="wp-textarea" rows="3" maxlength="160" placeholder="Để trống để dùng mô tả ngắn sản phẩm"><?= htmlspecialchars($product->seoDescription ?? '') ?></textarea><small class="wp-text-muted">Tùy chọn · tối đa 160 ký tự.</small></div>
        </div>
    </div>

    <!-- Right Sidebar -->
    <div class="wp-editor-sidebar">
        <div class="wp-card wp-mb-20 product-publish-card">
            <div class="wp-card-header"><h2>📢 Xuất bản Sản phẩm</h2></div>
            <div class="wp-form-group">
                <label for="product-status">Trạng thái hiển thị</label>
                <select name="status" id="product-status" class="wp-select">
                    <option value="published" <?= ($product?->status->value ?? 'published') === 'published' ? 'selected' : '' ?>>Đang bán (Published)</option>
                    <option value="draft" <?= ($product?->status->value ?? '') === 'draft' ? 'selected' : '' ?>>Bản nháp (Draft)</option>
                    <option value="trash" <?= ($product?->status->value ?? '') === 'trash' ? 'selected' : '' ?>>Thùng rác (Trash)</option>
                </select>
            </div>
            <button type="submit" class="wp-btn wp-btn-primary wp-btn-block">💾 <?= $isEdit ? 'Cập nhật Sản phẩm' : 'Đăng Sản phẩm' ?></button>
        </div>

        <?php if (!empty($enabledModules['categories_tags'])): ?>
            <div class="wp-card wp-mb-20 product-taxonomy-card">
                <div class="wp-card-header">
                    <h2>📁 Phân loại &amp; từ khóa</h2>
                    <a href="/admin/categories?type=product" class="wp-link">+ Thêm mới</a>
                </div>
                <div class="wp-form-group">
                    <label for="product-category">Danh mục sản phẩm</label>
                    <select name="category_id" id="product-category" class="wp-select">
                        <option value="">— Chọn danh mục —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat->id ?>" <?= ($product?->categoryId ?? null) === $cat->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wp-form-group">
                    <label for="product-tags">Từ khóa / Thẻ sản phẩm</label>
                    <input
                        type="text"
                        name="tags"
                        id="product-tags"
                        value="<?= htmlspecialchars(implode(', ', $product?->tags ?? [])) ?>"
                        placeholder="Laptop, Apple, M4 Max..."
                        class="wp-input"
                    >
                </div>
            </div>
        <?php endif; ?>

        <div class="wp-card product-featured-card">
            <div class="wp-card-header">
                <h2>🖼️ Ảnh sản phẩm chính</h2>
            </div>
            <div class="product-featured-dropzone" data-product-dropzone="featured">
                <input type="file" name="featured_image_file" id="featured-image-file" accept="image/jpeg,image/png,image/gif,image/webp" hidden data-product-image-input="featured">
                <img src="<?= htmlspecialchars($featuredImage) ?>" id="featured-preview-img" alt="Ảnh sản phẩm chính"<?= empty($featuredImage) ? ' style="display:none;"' : '' ?>>
                <div class="product-featured-empty"<?= !empty($featuredImage) ? ' style="display:none;"' : '' ?>>
                    <span>🖼️</span><strong>Thả ảnh vào đây</strong><small>hoặc chọn ảnh từ thiết bị</small>
                </div>
                <label for="featured-image-file" class="wp-btn wp-btn-secondary">⬆️ Tải ảnh lên</label>
                <button type="button" class="wp-btn wp-btn-secondary" data-open-media-picker="featured">Chọn từ Media</button>
                <button type="button" class="wp-btn wp-btn-danger-outline" data-clear-featured<?= $featuredImage === '' ? ' hidden' : '' ?>>Gỡ ảnh</button>
            </div>
            <div class="wp-form-group">
                <label for="featured-image-input">URL ảnh sản phẩm</label>
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
            <p class="product-image-status" data-product-image-status="featured" aria-live="polite"></p>
            <small class="wp-text-muted">JPG, PNG, GIF hoặc WEBP · tối đa 5 MiB mỗi ảnh.</small>
        </div>

    </div>
</form>

<dialog class="product-media-dialog" id="product-media-dialog" aria-labelledby="product-media-title">
    <div class="product-media-dialog-header">
        <div><span class="dashboard-section-kicker">MEDIA LIBRARY</span><h2 id="product-media-title">Chọn ảnh sản phẩm</h2></div>
        <button type="button" class="wp-btn wp-btn-secondary" data-close-media-picker aria-label="Đóng thư viện">✕</button>
    </div>
    <div class="product-media-toolbar">
        <label class="product-media-search"><span>⌕</span><input type="search" placeholder="Tìm theo tên, URL hoặc mô tả ảnh…" data-media-search><kbd>⌘ K</kbd></label>
        <div class="product-media-toolbar-actions">
            <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden data-media-upload-input>
            <button type="button" class="wp-btn wp-btn-secondary" data-media-upload>⬆️ Tải ảnh lên</button>
            <a href="/admin/media" target="_blank" rel="noopener noreferrer">Quản lý Media ↗</a>
        </div>
    </div>
    <div class="product-media-browser" data-media-browser>
        <div class="product-media-grid-wrap">
            <div class="product-media-grid" id="product-media-grid" aria-live="polite"></div>
            <p class="product-media-picker-status" data-media-picker-status aria-live="polite"></p>
        </div>
        <aside class="product-media-details" data-media-details>
            <div class="product-media-detail-preview"><img data-media-detail-image alt=""></div>
            <strong data-media-detail-name>Chưa chọn ảnh</strong>
            <dl>
                <div><dt>Kích thước</dt><dd data-media-detail-dimensions>—</dd></div>
                <div><dt>Văn bản thay thế</dt><dd data-media-detail-alt>—</dd></div>
                <div><dt>Đường dẫn</dt><dd data-media-detail-url>—</dd></div>
            </dl>
        </aside>
    </div>
    <div class="product-media-dialog-footer">
        <div class="product-media-footer-pages"><span class="wp-text-muted" data-media-picker-count></span><button type="button" class="wp-btn wp-btn-secondary" data-media-page="prev">←</button><span data-media-picker-page></span><button type="button" class="wp-btn wp-btn-secondary" data-media-page="next">→</button></div>
        <button type="button" class="wp-btn wp-btn-primary" data-add-selected-media disabled>Chọn ảnh</button>
    </div>
</dialog>

<dialog class="product-media-dialog" id="rich-text-media-dialog" aria-label="Chọn ảnh trong thư viện Media">
    <div class="product-media-dialog-header"><h2>Chèn ảnh từ Media Library</h2><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-close>✕</button></div>
    <div class="product-media-toolbar"><label class="product-media-search"><span>⌕</span><input type="search" placeholder="Tìm ảnh…" data-rich-media-search></label><div class="product-media-toolbar-actions"><input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden data-rich-media-upload><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-upload-button>⬆️ Tải ảnh lên</button></div></div>
    <div class="product-media-grid" data-rich-media-grid></div>
    <div class="product-media-dialog-footer"><span data-rich-media-page></span><div><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-prev>←</button><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-next>→</button></div><button type="button" class="wp-btn wp-btn-primary" data-rich-media-insert disabled>Chèn ảnh</button></div>
</dialog>
