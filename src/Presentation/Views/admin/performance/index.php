<?php
/** @var array<string,mixed> $performanceStats */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$size = static function (int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1024, 0) . ' KB';
};
$state = static fn(bool $value): string => $value ? 'Đang bật' : 'Chưa bật';
?>
<div class="wp-page-header"><div><h1 class="wp-page-title">⚡ Performance</h1><p class="wp-page-subtitle">Tình trạng các tối ưu đang hoạt động trên máy chủ và ứng dụng.</p></div></div>
<div class="wp-stats-grid performance-stats-grid">
    <article class="wp-stat-card"><span>PHP</span><strong><?= $e($performanceStats['php_version']) ?></strong></article>
    <article class="wp-stat-card"><span>OPcache</span><strong><?= $e($state($performanceStats['opcache'])) ?></strong><small><?= $performanceStats['opcache'] ? $e($size($performanceStats['opcache_memory'])) . ' đang sử dụng' : 'Bật OPcache trên PHP-FPM/Apache để giảm thời gian biên dịch PHP.' ?></small></article>
    <article class="wp-stat-card"><span>Nén phản hồi PHP</span><strong><?= $e($state($performanceStats['gzip'])) ?></strong><small>Nén gzip cho response động khi trình duyệt hỗ trợ.</small></article>
    <article class="wp-stat-card"><span>SQLite journal</span><strong><?= $e($performanceStats['database_mode']) ?></strong><small>WAL hỗ trợ đọc đồng thời khi có ghi dữ liệu.</small></article>
    <article class="wp-stat-card"><span>SQLite cache</span><strong><?= $performanceStats['database_cache_kib'] !== null ? $e(number_format((int)$performanceStats['database_cache_kib'])) . ' KiB' : 'Không áp dụng' ?></strong><small>Cache trang SQLite được cấu hình cho từng kết nối.</small></article>
    <article class="wp-stat-card"><span>Cơ sở dữ liệu</span><strong><?= $e($size((int)$performanceStats['database_bytes'])) ?></strong><small>Kích thước tệp SQLite hiện tại.</small></article>
    <article class="wp-stat-card"><span>Application cache</span><strong><?= $e((string)$performanceStats['cache_files']) ?> tệp</strong><small><?= $e($size((int)$performanceStats['cache_bytes'])) ?> · HTMLPurifier và báo cáo GA4</small></article>
    <article class="wp-stat-card"><span>Image WebP support</span><strong><?= $e($state($performanceStats['webp'])) ?></strong><small><?= $performanceStats['gd'] ? 'GD có sẵn.' : 'GD chưa được cài trong PHP.' ?></small></article>
</div>
<section class="wp-card performance-guidance"><div class="wp-card-header"><h2>⚙️ Tối ưu đã áp dụng</h2></div><ul>
    <li>SQLite dùng WAL, busy timeout, bộ nhớ cache kết nối và các index cho truy vấn danh sách bài viết, sản phẩm, đơn hàng, bình luận và media.</li>
    <li>PHP nén response bằng gzip khi client hỗ trợ.</li>
    <li><?= $performanceStats['apache_rules'] ? 'Apache .htaccess có quy tắc nén văn bản và cache tài nguyên tĩnh.' : 'Chưa tìm thấy public/.htaccess; với Nginx, cần cấu hình gzip và cache header trong server block.' ?></li>
    <li>Cache hiện có được dùng cho HTMLPurifier và GA4 Data API; chưa bật full-page cache vì trang có session giỏ hàng và CSRF token.</li>
</ul><p class="wp-muted">Trang này hiển thị cấu hình runtime, không thay thế đo tải thực tế hoặc kiểm tra Core Web Vitals từ trình duyệt.</p></section>
