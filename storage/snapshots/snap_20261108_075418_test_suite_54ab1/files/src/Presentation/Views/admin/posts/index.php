<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Post[] $posts
 * @var array<string, int> $counts
 * @var \App\Domain\Entity\Category[] $categories
 * @var string $currentStatus
 * @var int|null $currentCategory
 * @var string $search
 */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">📝 Bài viết (Posts)</h1>
        <p class="wp-page-subtitle">Quản lý danh sách bài viết tin tức, blog và trạng thái xuất bản.</p>
    </div>
    <a href="/admin/posts/create" class="wp-btn wp-btn-primary">+ Viết bài mới</a>
</div>

<div class="wp-subsubsub">
    <a href="/admin/posts" class="<?= $currentStatus === '' ? 'current' : '' ?>">
        Tất cả <span class="count">(<?= (int) ($counts['all'] ?? 0) ?>)</span>
    </a> |
    <a href="/admin/posts?status=published" class="<?= $currentStatus === 'published' ? 'current' : '' ?>">
        Đã xuất bản <span class="count">(<?= (int) ($counts['published'] ?? 0) ?>)</span>
    </a> |
    <a href="/admin/posts?status=draft" class="<?= $currentStatus === 'draft' ? 'current' : '' ?>">
        Bản nháp <span class="count">(<?= (int) ($counts['draft'] ?? 0) ?>)</span>
    </a> |
    <a href="/admin/posts?status=trash" class="<?= $currentStatus === 'trash' ? 'current' : '' ?>">
        Thùng rác <span class="count">(<?= (int) ($counts['trash'] ?? 0) ?>)</span>
    </a>
</div>

<!-- Filter Bar -->
<form method="GET" action="/admin/posts" class="wp-filter-bar">
    <?php if ($currentStatus !== ''): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($currentStatus) ?>">
    <?php endif; ?>
    <select name="category_id" class="wp-select">
        <option value="">— Tất cả danh mục —</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat->id ?>" <?= $currentCategory === $cat->id ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat->name) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="Tìm tiêu đề, nội dung..." class="wp-input wp-search-input">
    <button type="submit" class="wp-btn wp-btn-secondary">Lọc &amp; Tìm kiếm</button>
</form>

<div class="wp-card">
    <?php if (empty($posts)): ?>
        <div class="wp-empty-state">
            <p>Chưa có bài viết nào phù hợp với bộ lọc.</p>
            <a href="/admin/posts/create" class="wp-btn wp-btn-primary">+ Tạo bài viết đầu tiên</a>
        </div>
    <?php else: ?>
        <table class="wp-table">
            <thead>
                <tr>
                    <th style="width:70px;">Ảnh</th>
                    <th>Tiêu đề &amp; Thao tác</th>
                    <th>Tác giả</th>
                    <th>Danh mục</th>
                    <th>Thẻ (Tags)</th>
                    <th>Trạng thái</th>
                    <th>Ngày đăng</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $post): ?>
                    <tr>
                        <td>
                            <?php if ($post->featuredImage !== ''): ?>
                                <img src="<?= htmlspecialchars($post->featuredImage) ?>" alt="" class="wp-thumb">
                            <?php else: ?>
                                <div class="wp-thumb-placeholder">📝</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/admin/posts/edit/<?= $post->id ?>" class="wp-row-title"><?= htmlspecialchars($post->title) ?></a>
                            <div class="wp-row-actions">
                                <a href="/admin/posts/edit/<?= $post->id ?>">Chỉnh sửa</a> |
                                <a href="/post/<?= urlencode($post->slug) ?>" target="_blank">Xem bài ↗</a> |
                                <?php if ($post->status->value !== 'trash'): ?>
                                    <form method="POST" action="/admin/posts/status/<?= $post->id ?>" style="display:inline;">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                        <input type="hidden" name="status" value="trash">
                                        <button type="submit" class="wp-link-danger">Bỏ vào thùng rác</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="/admin/posts/status/<?= $post->id ?>" style="display:inline;">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                        <input type="hidden" name="status" value="published">
                                        <button type="submit" class="wp-link">Khôi phục</button>
                                    </form> |
                                    <form method="POST" action="/admin/posts/delete/<?= $post->id ?>" style="display:inline;" onsubmit="return confirm('Xóa vĩnh viễn bài viết này?');">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                        <button type="submit" class="wp-link-danger">Xóa vĩnh viễn</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($post->authorName) ?></td>
                        <td><?= htmlspecialchars($post->categoryName ?? '—') ?></td>
                        <td>
                            <?php foreach ($post->tags as $t): ?>
                                <span class="wp-tag-chip"><?= htmlspecialchars($t) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td><span class="wp-status-badge <?= $post->status->badgeClass() ?>"><?= $post->status->label() ?></span></td>
                        <td><small><?= htmlspecialchars($post->createdAt) ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
