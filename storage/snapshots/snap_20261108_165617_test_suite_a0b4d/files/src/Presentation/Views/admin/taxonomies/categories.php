<?php
declare(strict_types=1);
/**
 * @var string $type
 * @var \App\Domain\Entity\Category[] $categories
 * @var \App\Domain\Entity\Category|null $editingCategory
 */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">📁 <?= $type === 'product' ? 'Danh mục Sản phẩm' : 'Danh mục Bài viết' ?></h1>
        <p class="wp-page-subtitle">Phân loại nội dung theo cấu trúc giống WordPress Categories.</p>
    </div>
    <div class="wp-header-actions">
        <a href="/admin/categories?type=post" class="wp-btn <?= $type === 'post' ? 'wp-btn-primary' : 'wp-btn-secondary' ?>">📝 Danh mục Bài viết</a>
        <a href="/admin/categories?type=product" class="wp-btn <?= $type === 'product' ? 'wp-btn-primary' : 'wp-btn-secondary' ?>">🛍️ Danh mục Sản phẩm</a>
    </div>
</div>

<div class="wp-editor-layout">
    <!-- Left Form (Giống WordPress Categories) -->
    <div class="wp-editor-sidebar">
        <div class="wp-card">
            <div class="wp-card-header">
                <h2><?= $editingCategory ? '✏️ Sửa danh mục' : '+ Thêm danh mục mới' ?></h2>
            </div>
            <form method="POST" action="/admin/categories/save">
            <?= \App\Presentation\Http\Csrf::field() ?>
                <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                <?php if ($editingCategory): ?>
                    <input type="hidden" name="id" value="<?= $editingCategory->id ?>">
                <?php endif; ?>
                <div class="wp-form-group">
                    <label for="category-name">Tên danh mục *</label>
                    <input type="text" id="category-name" name="name" value="<?= htmlspecialchars($editingCategory->name ?? '') ?>" required class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label for="category-slug">Đường dẫn tĩnh (Slug)</label>
                    <input type="text" id="category-slug" name="slug" value="<?= htmlspecialchars($editingCategory->slug ?? '') ?>" placeholder="Tu-dong-sinh-neu-de-trong" class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label for="category-description">Mô tả</label>
                    <textarea id="category-description" name="description" rows="3" class="wp-textarea"><?= htmlspecialchars($editingCategory->description ?? '') ?></textarea>
                </div>
                <button type="submit" class="wp-btn wp-btn-primary wp-btn-block">
                    <?= $editingCategory ? 'Cập nhật Danh mục' : 'Thêm Danh mục mới' ?>
                </button>
                <?php if ($editingCategory): ?>
                    <a href="/admin/categories?type=<?= urlencode($type) ?>" class="wp-btn wp-btn-secondary wp-btn-block" style="margin-top:8px; text-align:center;">Hủy chỉnh sửa</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Right Table -->
    <div class="wp-editor-main">
        <div class="wp-card">
            <table class="wp-table">
                <thead>
                    <tr>
                        <th>Tên danh mục</th>
                        <th>Mô tả</th>
                        <th>Đường dẫn (Slug)</th>
                        <th>Số lượng mục</th>
                        <th style="text-align:right;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($cat->name) ?></strong></td>
                            <td><?= htmlspecialchars($cat->description) ?></td>
                            <td><code><?= htmlspecialchars($cat->slug) ?></code></td>
                            <td><span class="wp-badge wp-badge-blue"><?= $cat->itemCount ?></span></td>
                            <td style="text-align:right;">
                                <a href="/admin/categories?type=<?= urlencode($type) ?>&edit=<?= $cat->id ?>" class="wp-link">Sửa</a> |
                                <form method="POST" action="/admin/categories/delete/<?= $cat->id ?>" style="display:inline;" onsubmit="return confirm('Xóa danh mục này?');">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                    <button type="submit" class="wp-link-danger">Xóa</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
