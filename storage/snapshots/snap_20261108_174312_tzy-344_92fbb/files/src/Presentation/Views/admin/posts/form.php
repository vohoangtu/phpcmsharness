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
    </div>
    <div class="wp-header-actions">
        <?php if ($isEdit): ?>
            <a href="/post/<?= urlencode($post->slug) ?>" target="_blank" class="wp-btn wp-btn-secondary">Xem bài viết ↗</a>
        <?php endif; ?>
        <a href="/admin/posts" class="wp-btn wp-btn-secondary">← Danh sách bài viết</a>
    </div>
</div>

<form method="POST" action="/admin/posts/save" enctype="multipart/form-data" class="wp-editor-layout">
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
            <div class="wp-editor-toolbar">
                <button type="button" onclick="formatDoc('bold')" title="In đậm"><b>B</b></button>
                <button type="button" onclick="formatDoc('italic')" title="In nghiêng"><i>I</i></button>
                <button type="button" onclick="formatDoc('underline')" title="Gạch chân"><u>U</u></button>
                <button type="button" onclick="formatDoc('formatBlock', 'H2')">H2</button>
                <button type="button" onclick="formatDoc('formatBlock', 'H3')">H3</button>
                <button type="button" onclick="formatDoc('insertUnorderedList')">• Danh sách</button>
                <button type="button" onclick="formatDoc('insertOrderedList')">1. Danh sách</button>
                <button type="button" onclick="insertLinkPrompt()">🔗 Chèn Link</button>
                <button type="button" onclick="toggleSourceMode()" class="wp-toolbar-right">«/» Xem mã HTML</button>
            </div>
            <div
                id="visual-editor"
                class="wp-visual-editor"
                contenteditable="true"
                aria-labelledby="post-content-label"
                oninput="syncEditorContent()"
                style="display:none;"
            ></div>
            <textarea
                name="content"
                id="raw-content"
                rows="14"
                class="wp-textarea"
            ><?= htmlspecialchars($post->content ?? '<p>Bắt đầu viết nội dung bài viết tại đây...</p>') ?></textarea>
        </div>

        <div class="wp-card">
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
            <button type="submit" class="wp-btn wp-btn-primary wp-btn-block" onclick="syncEditorContent()">
                💾 <?= $isEdit ? 'Cập nhật Bài viết' : 'Xuất bản Bài viết' ?>
            </button>
        </div>

        <?php if (!empty($enabledModules['categories_tags'])): ?>
            <!-- Category Box -->
            <div class="wp-card wp-mb-20">
                <div class="wp-card-header">
                    <h2>📁 Danh mục bài viết</h2>
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
            </div>

            <!-- Tags Box -->
            <div class="wp-card wp-mb-20">
                <div class="wp-card-header">
                    <h2>🏷️ Thẻ (Tags)</h2>
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
