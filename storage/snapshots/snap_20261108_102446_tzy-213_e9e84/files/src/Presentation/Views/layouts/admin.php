<?php
declare(strict_types=1);
/**
 * @var string $pageTitle
 * @var string $content
 * @var array<string, bool> $enabledModules
 * @var array<string, string> $settings
 * @var array<string, mixed>|null $currentUser
 * @var array{type: string, message: string}|null $flash
 * @var int $pendingCommentsCount
 * @var int $pendingOrdersCount
 * @var string $currentUri
 */
$siteTitle = htmlspecialchars($settings['site_title'] ?? 'WP-Hexagon CMS');
$isActive = fn(string $prefix): string => str_starts_with($currentUri, $prefix) ? 'active' : '';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> ‹ <?= $siteTitle ?> — WP-Hexagon</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="wp-admin-body">
    <!-- Top Admin Bar (Giống WordPress) -->
    <header class="wp-topbar">
        <div class="wp-topbar-left">
            <a href="/admin" class="wp-logo-badge" title="WP-Hexagon Architecture">⬡ WP-Hexagon</a>
            <a href="/" target="_blank" class="wp-topbar-link">🏠 Xem trang chủ: <strong><?= $siteTitle ?></strong> ↗</a>
            <?php if (!empty($enabledModules['posts'])): ?>
                <a href="/admin/posts/create" class="wp-topbar-link">+ Bài viết mới</a>
            <?php endif; ?>
            <?php if (!empty($enabledModules['products'])): ?>
                <a href="/admin/products/create" class="wp-topbar-link">+ Sản phẩm mới</a>
            <?php endif; ?>
        </div>
        <div class="wp-topbar-right">
            <a href="/admin/modules" class="wp-topbar-link wp-module-pill">
                🔌 Modules đang bật: <strong><?= count(array_filter($enabledModules)) ?>/<?= count($enabledModules) ?></strong>
            </a>
            <span class="wp-user-greeting">
                Xin chào, <strong><?= htmlspecialchars((string) ($currentUser['display_name'] ?? 'Admin')) ?></strong>
            </span>
            <form method="post" action="/admin/logout" class="wp-topbar-logout-form">
                <?= \App\Presentation\Http\Csrf::field() ?>
                <button type="submit" class="wp-topbar-logout">Đăng xuất</button>
            </form>
        </div>
    </header>

    <div class="wp-layout">
        <!-- Left Sidebar Navigation -->
        <aside class="wp-sidebar">
            <nav class="wp-nav">
                <a href="/admin" class="wp-nav-item <?= $currentUri === '/admin' ? 'active' : '' ?>">
                    <span class="wp-nav-icon">📊</span>
                    <span class="wp-nav-text">Bảng điều khiển</span>
                </a>

                <a href="/admin/modules" class="wp-nav-item <?= $isActive('/admin/modules') ?>">
                    <span class="wp-nav-icon">🔌</span>
                    <span class="wp-nav-text">Chức năng (Modules)</span>
                    <span class="wp-badge wp-badge-blue"><?= count(array_filter($enabledModules)) ?></span>
                </a>

                <?php if (!empty($enabledModules['harness'])): ?>
                    <a href="/admin/harness" class="wp-nav-item <?= $isActive('/admin/harness') ?>">
                        <span class="wp-nav-icon">🤖</span>
                        <span class="wp-nav-text">Tuzygent Harness</span>
                        <span class="wp-badge wp-badge-blue">AI</span>
                    </a>
                <?php endif; ?>

                <div class="wp-nav-divider">NỘI DUNG &amp; CỬA HÀNG</div>

                <?php if (!empty($enabledModules['posts'])): ?>
                    <a href="/admin/posts" class="wp-nav-item <?= $isActive('/admin/posts') ?>">
                        <span class="wp-nav-icon">📝</span>
                        <span class="wp-nav-text">Bài viết (Posts)</span>
                    </a>
                    <div class="wp-subnav">
                        <a href="/admin/posts" class="<?= $currentUri === '/admin/posts' ? 'sub-active' : '' ?>">Tất cả bài viết</a>
                        <a href="/admin/posts/create" class="<?= $currentUri === '/admin/posts/create' ? 'sub-active' : '' ?>">Viết bài mới</a>
                        <?php if (!empty($enabledModules['categories_tags'])): ?>
                            <a href="/admin/categories?type=post">Danh mục bài viết</a>
                            <a href="/admin/tags">Thẻ (Tags)</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($enabledModules['products'])): ?>
                    <a href="/admin/products" class="wp-nav-item <?= $isActive('/admin/products') ?>">
                        <span class="wp-nav-icon">🛍️</span>
                        <span class="wp-nav-text">Sản phẩm (Products)</span>
                    </a>
                    <div class="wp-subnav">
                        <a href="/admin/products" class="<?= $currentUri === '/admin/products' ? 'sub-active' : '' ?>">Tất cả sản phẩm</a>
                        <a href="/admin/products/create" class="<?= $currentUri === '/admin/products/create' ? 'sub-active' : '' ?>">Thêm sản phẩm</a>
                        <?php if (!empty($enabledModules['categories_tags'])): ?>
                            <a href="/admin/categories?type=product">Danh mục sản phẩm</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($enabledModules['orders'])): ?>
                    <a href="/admin/orders" class="wp-nav-item <?= $isActive('/admin/orders') ?>">
                        <span class="wp-nav-icon">🛒</span>
                        <span class="wp-nav-text">Đơn hàng (Orders)</span>
                        <?php if ($pendingOrdersCount > 0): ?>
                            <span class="wp-badge wp-badge-orange"><?= $pendingOrdersCount ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <?php if (!empty($enabledModules['media'])): ?>
                    <a href="/admin/media" class="wp-nav-item <?= $isActive('/admin/media') ?>">
                        <span class="wp-nav-icon">🖼️</span>
                        <span class="wp-nav-text">Thư viện Media</span>
                    </a>
                <?php endif; ?>

                <?php if (!empty($enabledModules['comments'])): ?>
                    <a href="/admin/comments" class="wp-nav-item <?= $isActive('/admin/comments') ?>">
                        <span class="wp-nav-icon">💬</span>
                        <span class="wp-nav-text">Bình luận &amp; Đánh giá</span>
                        <?php if ($pendingCommentsCount > 0): ?>
                            <span class="wp-badge wp-badge-orange"><?= $pendingCommentsCount ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <div class="wp-nav-divider">HỆ THỐNG</div>

                <a href="/admin/users" class="wp-nav-item <?= $isActive('/admin/users') ?>">
                    <span class="wp-nav-icon">👥</span>
                    <span class="wp-nav-text">Thành viên (Users)</span>
                </a>

                <a href="/admin/settings" class="wp-nav-item <?= $isActive('/admin/settings') ?>">
                    <span class="wp-nav-icon">⚙️</span>
                    <span class="wp-nav-text">Cài đặt (Settings)</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="wp-main">
            <?php if (!empty($flash)): ?>
                <div class="wp-notice wp-notice-<?= htmlspecialchars($flash['type']) ?>">
                    <span><?= htmlspecialchars($flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <div id="ajax-toast" class="wp-toast" style="display:none;"></div>

            <?= $content ?>
        </main>
    </div>

    <script src="/assets/js/admin.js"></script>
</body>
</html>
