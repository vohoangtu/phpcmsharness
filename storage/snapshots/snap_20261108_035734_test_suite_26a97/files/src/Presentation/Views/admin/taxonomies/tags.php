<?php
declare(strict_types=1);
/** @var \App\Domain\Entity\Tag[] $tags */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">🏷️ Quản lý Thẻ (Tags)</h1>
        <p class="wp-page-subtitle">Gắn thẻ từ khóa cho cả Bài viết và Sản phẩm.</p>
    </div>
</div>

<div class="wp-editor-layout">
    <div class="wp-editor-sidebar">
        <div class="wp-card">
            <div class="wp-card-header">
                <h3>+ Thêm thẻ (Tag) mới</h3>
            </div>
            <form method="POST" action="/admin/tags/save">
                <div class="wp-form-group">
                    <label>Tên thẻ *</label>
                    <input type="text" name="name" required placeholder="VD: Clean Architecture" class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label>Slug (Tùy chọn)</label>
                    <input type="text" name="slug" placeholder="clean-architecture" class="wp-input">
                </div>
                <button type="submit" class="wp-btn wp-btn-primary wp-btn-block">Thêm Thẻ mới</button>
            </form>
        </div>
    </div>

    <div class="wp-editor-main">
        <div class="wp-card">
            <table class="wp-table">
                <thead>
                    <tr>
                        <th>Tên thẻ</th>
                        <th>Đường dẫn (Slug)</th>
                        <th>Lượt gắn kết</th>
                        <th style="text-align:right;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tags as $tag): ?>
                        <tr>
                            <td><span class="wp-tag-chip"><?= htmlspecialchars($tag->name) ?></span></td>
                            <td><code><?= htmlspecialchars($tag->slug) ?></code></td>
                            <td><?= $tag->usageCount ?></td>
                            <td style="text-align:right;">
                                <form method="POST" action="/admin/tags/delete/<?= $tag->id ?>" style="display:inline;" onsubmit="return confirm('Xóa thẻ này?');">
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
