<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Post|null $post
 * @var \App\Domain\Entity\Category[] $categories
 * @var \App\Domain\Entity\MediaFile[] $mediaFiles
 * @var array<string, bool> $enabledModules
 */
$isEdit = $post !== null;
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title"><?= $isEdit ? '✏️ Chỉnh sửa Bài viết' : '📝 Thêm Bài viết mới' ?></h1>
        <p class="wp-page-subtitle">Soạn nội dung, thiết lập hiển thị và tối ưu SEO trong cùng một biểu mẫu.</p>
    </div>
    <div class="wp-header-actions">
        <?php if ($isEdit): ?>
            <a href="/post/<?= urlencode($post->slug) ?>" target="_blank" class="wp-btn wp-btn-secondary">Xem bài viết ↗</a>
        <?php endif; ?>
        <a href="/admin/posts" class="wp-btn wp-btn-secondary">← Danh sách bài viết</a>
    </div>
</div>

<form method="POST" action="/admin/posts/save" enctype="multipart/form-data" class="wp-editor-layout post-editor-form">
            <?= \App\Presentation\Http\Csrf::field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $post->id ?>">
    <?php endif; ?>

    <!-- Main Left Column -->
    <div class="wp-editor-main">
        <div class="wp-card wp-mb-20">
            <div class="wp-form-group">
                <label for="post-title">Tiêu đề bài viết</label>
                <input
                    type="text"
                    name="title"
                    id="post-title"
                    value="<?= htmlspecialchars($post->title ?? '') ?>"
                    placeholder="Nhập tiêu đề bài viết tại đây..."
                    required
                    class="wp-input wp-title-input"
                >
            </div>

            <div class="wp-permalink-row">
                <label for="post-slug">🔗 Đường dẫn tĩnh (Slug): <code>/post/</code></label>
                <input
                    type="text"
                    name="slug"
                    id="post-slug"
                    value="<?= htmlspecialchars($post->slug ?? '') ?>"
                    placeholder="tu-dong-tao-tu-tieu-de"
                    class="wp-input wp-slug-input"
                >
            </div>
        </div>

        <div class="wp-card wp-mb-20">
            <label id="post-content-label" for="raw-content">Nội dung bài viết</label>
            <div class="wp-rich-text-editor" data-rich-text-editor data-source-id="raw-content">
                <div class="wp-editor-toolbar" data-rich-text-toolbar role="toolbar" aria-label="Công cụ định dạng nội dung">
                    <div class="wp-toolbar-group" aria-label="Kiểu chữ"><button type="button" data-editor-command="bold" title="In đậm"><b>B</b></button><button type="button" data-editor-command="italic" title="In nghiêng"><i>I</i></button><button type="button" data-editor-command="underline" title="Gạch chân"><u>U</u></button><button type="button" data-editor-command="strike" title="Gạch ngang"><s>S</s></button><select data-editor-font-family aria-label="Phông chữ"><option value="">Phông chữ</option><option value="Arial, sans-serif">Arial</option><option value="Georgia, serif">Georgia</option><option value="'Times New Roman', serif">Times New Roman</option><option value="Verdana, sans-serif">Verdana</option></select><select data-editor-font-size aria-label="Cỡ chữ"><option value="">Cỡ chữ</option><option value="12px">12</option><option value="14px">14</option><option value="16px">16</option><option value="18px">18</option><option value="24px">24</option><option value="32px">32</option></select></div>
                    <div class="wp-toolbar-group" aria-label="Định dạng đoạn"><select data-editor-command="heading" aria-label="Kiểu tiêu đề"><option value="p">Đoạn văn</option><option value="h2">Tiêu đề 2</option><option value="h3">Tiêu đề 3</option><option value="h4">Tiêu đề 4</option></select><button type="button" data-editor-command="bulletList">• Danh sách</button><button type="button" data-editor-command="orderedList">1. Danh sách</button><button type="button" data-editor-command="blockquote">Trích dẫn</button><button type="button" data-editor-command="alignLeft" title="Căn trái">☰</button><button type="button" data-editor-command="alignCenter" title="Căn giữa">≡</button><button type="button" data-editor-command="alignRight" title="Căn phải">☷</button></div>
                    <div class="wp-toolbar-group" aria-label="Chèn nội dung"><button type="button" data-editor-command="link">🔗 Liên kết</button><button type="button" data-editor-command="unlink">Bỏ link</button><button type="button" data-editor-command="insertTable">▦ Bảng</button><button type="button" data-editor-command="addRow">＋ Hàng</button><button type="button" data-editor-command="deleteTable">Xóa bảng</button><button type="button" data-editor-command="image" title="Chọn ảnh từ Media Library; kéo góc ảnh để đổi kích thước">🖼 Ảnh</button><button type="button" data-editor-command="youtube">▶ Video</button></div>
                    <div class="wp-toolbar-group" aria-label="Màu và hoàn tác"><label class="wp-editor-color" title="Màu chữ">A <input type="color" data-editor-color value="#303754" aria-label="Màu chữ"></label><label class="wp-editor-color" title="Tô sáng">▰ <input type="color" data-editor-highlight value="#fff2a8" aria-label="Màu nền chữ"></label><button type="button" data-editor-command="clearFormatting" title="Xóa định dạng">Tx</button><button type="button" data-editor-command="undo" title="Hoàn tác">↶</button><button type="button" data-editor-command="redo" title="Làm lại">↷</button></div><span class="wp-editor-word-count" data-editor-word-count aria-live="polite"></span><button type="button" data-editor-command="source" class="wp-toolbar-right">&lt;/&gt; HTML</button>
                </div>
                <div id="visual-editor" class="wp-visual-editor" data-rich-text-surface role="textbox" aria-multiline="true" aria-labelledby="post-content-label" hidden></div>
            <textarea
                name="content"
                id="raw-content"
                rows="14"
                class="wp-textarea"
            ><?= htmlspecialchars($post->content ?? '<p>Bắt đầu viết nội dung bài viết tại đây...</p>') ?></textarea>
            </div>
        </div>

        <div class="wp-card">
            <div class="wp-card-header"><h2>📝 Tóm tắt bài viết</h2></div>
            <div class="wp-form-group">
                <label for="post-excerpt">Tóm tắt ngắn (Excerpt)</label>
                <textarea
                    name="excerpt"
                    id="post-excerpt"
                    rows="3"
                    placeholder="Mô tả ngắn hiển thị ngoài trang danh sách tin tức & SEO..."
                    class="wp-textarea"
                ><?= htmlspecialchars($post->excerpt ?? '') ?></textarea>
            </div>
        </div>
        <div class="wp-card wp-mb-20 seo-editor-card">
            <div class="wp-card-header"><h2>🔎 SEO &amp; chia sẻ mạng xã hội</h2><div class="ai-assistant-actions"><button type="button" class="wp-btn wp-btn-secondary" data-ai-action="post_seo">✨ Gợi ý SEO</button><button type="button" class="wp-btn wp-btn-secondary" data-ai-action="content_audit">🔎 Phân tích nội dung</button><button type="button" class="wp-btn wp-btn-secondary" data-ai-action="keyword_ideas">🏷️ Nghiên cứu từ khóa</button><button type="button" class="wp-btn wp-btn-secondary" data-ai-action="article_draft">✍️ Viết bản nháp</button></div></div>
            <div class="wp-form-group"><label for="ai-article-brief">Yêu cầu / đối tượng / ý chính cho AI (tùy chọn)</label><textarea id="ai-article-brief" class="wp-textarea" rows="3" data-ai-brief placeholder="Ví dụ: hướng dẫn cho chủ shop mới, giọng chuyên nghiệp, nêu 5 bước tối ưu..."></textarea><small class="wp-text-muted">Khi bấm AI, tiêu đề, nội dung, thẻ và yêu cầu này được gửi tới provider đã cấu hình trong Harness. Kết quả chỉ điền vào biểu mẫu sau khi bạn chọn áp dụng.</small></div>
            <div class="wp-form-group"><label for="post-seo-title">SEO title</label><input id="post-seo-title" name="seo_title" class="wp-input" maxlength="70" value="<?= htmlspecialchars($post->seoTitle ?? '') ?>" placeholder="Để trống để dùng tiêu đề bài viết"><small class="wp-text-muted">Tùy chọn · tối đa 70 ký tự.</small></div>
            <div class="wp-form-group"><label for="post-seo-description">Meta description</label><textarea id="post-seo-description" name="seo_description" class="wp-textarea" rows="3" maxlength="160" placeholder="Để trống để dùng phần tóm tắt bài viết"><?= htmlspecialchars($post->seoDescription ?? '') ?></textarea><small class="wp-text-muted">Tùy chọn · tối đa 160 ký tự. Công cụ tìm kiếm có thể tự chọn đoạn trích khác.</small></div>
        </div>
    </div>

    <!-- Right Sidebar Meta Boxes (Giống WordPress) -->
    <aside class="wp-editor-sidebar" aria-label="Thiết lập bài viết">
        <!-- Publish Box -->
        <div class="wp-card wp-mb-20">
            <div class="wp-card-header">
                <h2>📢 Xuất bản (Publish)</h2>
            </div>
            <div class="wp-form-group">
                <label for="post-status">Trạng thái</label>
                <select name="status" id="post-status" class="wp-select">
                    <option value="published" <?= ($post?->status->value ?? 'published') === 'published' ? 'selected' : '' ?>>Đã xuất bản (Published)</option>
                    <option value="draft" <?= ($post?->status->value ?? '') === 'draft' ? 'selected' : '' ?>>Bản nháp (Draft)</option>
                    <option value="trash" <?= ($post?->status->value ?? '') === 'trash' ? 'selected' : '' ?>>Thùng rác (Trash)</option>
                </select>
            </div>
            <button type="submit" class="wp-btn wp-btn-primary wp-btn-block">
                💾 <?= $isEdit ? 'Cập nhật Bài viết' : 'Xuất bản Bài viết' ?>
            </button>
        </div>

        <?php if (!empty($enabledModules['categories_tags'])): ?>
            <!-- Category Box -->
            <div class="wp-card wp-mb-20 post-taxonomy-card">
                <div class="wp-card-header">
                    <h2>📁 Phân loại bài viết</h2>
                    <a href="/admin/categories?type=post" class="wp-link">+ Thêm mới</a>
                </div>
                <div class="wp-form-group">
                    <label for="post-category">Danh mục bài viết</label>
                    <select name="category_id" id="post-category" class="wp-select">
                        <option value="">— Chọn danh mục —</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat->id ?>" <?= ($post?->categoryId ?? null) === $cat->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wp-form-group">
                    <label for="post-tags">Thẻ (Tags)</label>
                    <input
                        type="text"
                        name="tags"
                        id="post-tags"
                        value="<?= htmlspecialchars(implode(', ', $post?->tags ?? [])) ?>"
                        placeholder="PHP 8.5, Clean Code, Hexagon..."
                        class="wp-input"
                    >
                    <small class="wp-text-muted">Phân cách các thẻ bằng dấu phẩy (,)</small>
                </div>
            </div>
        <?php endif; ?>

        <!-- Featured Image Box -->
        <div class="wp-card">
            <div class="wp-card-header">
                <h2>🖼️ Ảnh đại diện (Featured Image)</h2>
            </div>
            <div class="wp-featured-preview" id="featured-preview-box">
                <img
                    src="<?= htmlspecialchars($post?->featuredImage ?? '') ?>"
                    id="featured-preview-img"
                    alt=""
                    <?= empty($post?->featuredImage) ? 'style="display:none;"' : '' ?>
                >
            </div>
            <div class="wp-form-group">
                <label for="featured-image-input">URL hình ảnh hoặc chọn từ Media</label>
                <input
                    type="text"
                    name="featured_image"
                    id="featured-image-input"
                    value="<?= htmlspecialchars($post->featuredImage ?? '') ?>"
                    placeholder="https://... hoặc /uploads/..."
                    class="wp-input"
                    oninput="previewFeaturedUrl(this.value)"
                >
            </div>
            <div class="wp-form-group">
                <label for="featured-image-file">Hoặc tải ảnh mới từ máy tính</label>
                <input type="file" name="featured_image_file" id="featured-image-file" accept="image/*" class="wp-input">
                <small class="wp-text-muted">Dung lượng tối đa: 5 MiB cho mỗi ảnh.</small>
            </div>
            <?php if (!empty($mediaFiles)): ?>
                <div class="wp-media-mini-picker">
                    <small>Chọn nhanh từ Thư viện Media:</small>
                    <div class="wp-media-mini-grid">
                        <?php foreach (array_slice($mediaFiles, 0, 6) as $mf): ?>
                            <img
                                src="<?= htmlspecialchars($mf->filePath) ?>"
                                alt="<?= htmlspecialchars($mf->altText) ?>"
                                title="<?= htmlspecialchars($mf->originalName) ?>"
                                onclick="selectFeaturedImage('<?= htmlspecialchars($mf->filePath) ?>')"
                            >
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </aside>
</form>

<dialog class="product-media-dialog" id="rich-text-media-dialog" aria-label="Chọn ảnh trong thư viện Media">
    <div class="product-media-dialog-header"><strong class="rich-media-dialog-title" role="heading" aria-level="2">Chèn ảnh từ Media Library</strong><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-close>✕</button></div>
    <div class="product-media-toolbar"><label class="product-media-search"><span>⌕</span><input type="search" placeholder="Tìm ảnh…" data-rich-media-search></label><div class="product-media-toolbar-actions"><input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden data-rich-media-upload><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-upload-button>⬆️ Tải ảnh lên</button></div></div>
    <div class="product-media-grid" data-rich-media-grid></div>
    <div class="product-media-dialog-footer"><span data-rich-media-page></span><div><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-prev>←</button><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-next>→</button></div><button type="button" class="wp-btn wp-btn-primary" data-rich-media-insert disabled>Chèn ảnh</button></div>
</dialog>
