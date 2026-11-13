<?php
declare(strict_types=1);
/**
 * @var string $pageTitle
 * @var string $content
 * @var array<string, bool> $enabledModules
 * @var array<string, string> $settings
 * @var array<string, mixed>|null $currentUser
 * @var array{type: string, message: string}|null $flash
 * @var int $cartCount
 * @var string $currentUri
 * @var string|null $adminEditUrl
 */
$siteTitle = htmlspecialchars($settings['site_title'] ?? 'WP-Hexagon CMS & Store');
$rawSiteTagline = $settings['site_tagline'] ?? '';
$siteTagline = htmlspecialchars($rawSiteTagline);
$headerSearch = isset($search) && is_string($search) ? $search : '';
$descriptionTitle = isset($pageTitle) && is_string($pageTitle) ? trim($pageTitle) : '';
if ($descriptionTitle === '') {
    $descriptionTitle = 'Trang chủ';
}
$metaDescription = isset($metaDescription) && is_string($metaDescription) ? trim($metaDescription) : '';
if ($metaDescription === '') {
    $metaDescription = trim($descriptionTitle . ($rawSiteTagline !== '' ? ' — ' . $rawSiteTagline : ''));
}
$seoTitle = isset($seoTitle) && is_string($seoTitle) && trim($seoTitle) !== '' ? trim($seoTitle) : (string)($pageTitle ?? 'Trang chủ');
$baseUrl = rtrim(trim((string)($settings['site_url'] ?? '')), '/');
$requestPath = parse_url($currentUri ?? '/', PHP_URL_PATH) ?: '/';
$canonicalUrl = isset($canonicalUrl) && is_string($canonicalUrl) && $canonicalUrl !== ''
    ? $canonicalUrl
    : ($baseUrl !== '' ? $baseUrl . '/' . ltrim($requestPath, '/') : '');
$ogImage = isset($ogImage) && is_string($ogImage) ? trim($ogImage) : '';
if ($ogImage !== '' && !preg_match('#^https?://#i', $ogImage) && $baseUrl !== '') $ogImage = $baseUrl . '/' . ltrim($ogImage, '/');
$ogType = isset($ogType) && in_array($ogType, ['article', 'product'], true) ? $ogType : 'website';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <?php if ($canonicalUrl !== ''): ?><link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <meta property="og:locale" content="vi_VN"><meta property="og:site_name" content="<?= $siteTitle ?>"><meta property="og:type" content="<?= htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?>"><meta property="og:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($canonicalUrl !== ''): ?><meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <?php if ($ogImage !== ''): ?><meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>"><meta name="twitter:card" content="summary_large_image"><?php else: ?><meta name="twitter:card" content="summary">
    <?php endif; ?><meta name="twitter:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?>"><meta name="twitter:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($seoTitle) ?> — <?= $siteTitle ?></title>
    <?php $ga4MeasurementId = trim((string)($settings['ga4_measurement_id'] ?? '')); if (!empty($enabledModules['analytics']) && preg_match('/^G-[A-Z0-9]+$/', $ga4MeasurementId)): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($ga4MeasurementId, ENT_QUOTES, 'UTF-8') ?>"></script>
    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',<?= json_encode($ga4MeasurementId, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>);</script>
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/frontend.css?v=20261113-ai">
    <?= isset($extensionHeadMarkup) && is_string($extensionHeadMarkup) ? $extensionHeadMarkup : '' ?>
</head>
<body>
    <?php if (!empty($currentUser)): ?>
        <!-- Thanh Admin Bar ngoài Frontend giống WordPress -->
        <div class="fe-admin-bar">
            <div class="fe-container fe-admin-bar-inner">
                <div>
                    <a href="/admin">⬡ <strong>WP-Hexagon Dashboard</strong></a>
                    <a href="/admin/modules">🔌 Bật/Tắt Modules</a>
                    <?php if (!empty($enabledModules['posts'])): ?>
                        <a href="/admin/posts/create">+ Viết bài mới</a>
                    <?php endif; ?>
                    <?php if (!empty($enabledModules['products'])): ?>
                        <a href="/admin/products/create">+ Thêm sản phẩm</a>
                    <?php endif; ?>
                    <?php if (!empty($adminEditUrl)): ?>
                        <a href="<?= htmlspecialchars($adminEditUrl) ?>" class="fe-admin-edit-highlight">✏️ Chỉnh sửa trang này</a>
                    <?php endif; ?>
                </div>
                <div>
                    <span>Đang đăng nhập: <strong><?= htmlspecialchars((string) $currentUser['display_name']) ?></strong></span>
                    <form method="post" action="/admin/logout" class="fe-admin-logout-form">
                        <?= \App\Presentation\Http\Csrf::field() ?>
                        <button type="submit" class="fe-admin-logout">Đăng xuất</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header Navigation -->
    <header class="fe-header">
        <div class="fe-container fe-header-inner">
            <a href="/" class="fe-brand">
                <span class="fe-brand-logo">⬡</span>
                <div>
                    <strong class="fe-brand-title"><?= $siteTitle ?></strong>
                    <?php if ($siteTagline !== ''): ?>
                        <small class="fe-brand-sub"><?= $siteTagline ?></small>
                    <?php endif; ?>
                </div>
            </a>

            <nav class="fe-nav" aria-label="Điều hướng chính">
                <a href="/" class="<?= $currentUri === '/' ? 'active' : '' ?>" <?= $currentUri === '/' ? 'aria-current="page"' : '' ?>>⌂ <span>Trang chủ</span></a>
                <?php if (!empty($enabledModules['products'])): ?>
                    <a href="/shop" class="<?= str_starts_with($currentUri, '/shop') || str_starts_with($currentUri, '/product/') ? 'active' : '' ?>" <?= str_starts_with($currentUri, '/shop') || str_starts_with($currentUri, '/product/') ? 'aria-current="page"' : '' ?>>🛍️ <span>Cửa hàng</span></a>
                <?php endif; ?>
                <?php if (!empty($enabledModules['posts'])): ?>
                    <a href="/blog" class="<?= str_starts_with($currentUri, '/blog') || str_starts_with($currentUri, '/post/') ? 'active' : '' ?>" <?= str_starts_with($currentUri, '/blog') || str_starts_with($currentUri, '/post/') ? 'aria-current="page"' : '' ?>>📝 <span>Bài viết</span></a>
                <?php endif; ?>
                <?php if (!empty($enabledModules['products']) && !empty($enabledModules['orders'])): ?>
                    <a href="/cart" class="fe-cart-btn">
                        🛒 Giỏ hàng <span class="fe-cart-badge"><?= (int) $cartCount ?></span>
                    </a>
                <?php endif; ?>
                <?php if (empty($currentUser)): ?>
                    <a href="/admin/login" class="fe-admin-link">Quản trị ↗</a>
                <?php endif; ?>
            </nav>
            <?php if (!empty($enabledModules['posts']) || !empty($enabledModules['products'])): ?>
                <form method="get" action="/search" role="search" aria-label="Tìm kiếm toàn trang" class="fe-site-search">
                    <label for="site-search-query">Tìm kiếm</label>
                    <input id="site-search-query" type="search" name="s" value="<?= htmlspecialchars($headerSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Bài viết, sản phẩm..." maxlength="200">
                    <button type="submit" aria-label="Tìm kiếm">⌕</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <main class="fe-main">
        <div class="fe-container">
            <?php if (!empty($flash)): ?>
                <div class="fe-alert fe-alert-<?= htmlspecialchars($flash['type']) ?>">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </div>
    </main>

    <footer class="fe-footer">
        <div class="fe-container fe-footer-inner">
            <div>
                <strong><?= $siteTitle ?></strong>
                <p><?= htmlspecialchars($settings['footer_text'] ?? '© 2026 WP-Hexagon CMS.') ?></p>
            </div>
            <form method="post" action="/newsletter/subscribe" class="fe-newsletter-form">
                <?= \App\Presentation\Http\Csrf::field() ?>
                <label for="newsletter-email">Nhận tin mới qua email</label>
                <div>
                    <input id="newsletter-email" type="email" name="email" maxlength="254" autocomplete="email" placeholder="ban@example.com" required aria-describedby="newsletter-help">
                    <button type="submit" class="fe-btn fe-btn-primary">Đăng ký</button>
                </div>
                <small id="newsletter-help">Bạn có thể đăng ký nhận các cập nhật mới từ website.</small>
            </form>
            <div class="fe-footer-meta">
                <span>📧 <?= htmlspecialchars($settings['contact_email'] ?? '') ?></span>
                <span>📞 <?= htmlspecialchars($settings['contact_phone'] ?? '') ?></span>
            </div>
        </div>
    </footer>
</body>
</html>
