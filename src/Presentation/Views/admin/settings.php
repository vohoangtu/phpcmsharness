<?php
declare(strict_types=1);
/** @var array<string, string> $settings */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">⚙️ Cài đặt Hệ thống (General Settings)</h1>
        <p class="wp-page-subtitle">Cấu hình thông tin chung cho Website, Cửa hàng và Bình luận.</p>
    </div>
</div>

<section class="wp-card" style="max-width:760px;margin-top:24px">
    <div class="wp-card-header"><h2>🖼️ Nội dung trang chủ</h2></div>
    <p>Chọn ảnh banner, nội dung nút và hiệu ứng trình chiếu ngay dưới menu website.</p>
    <a class="wp-btn wp-btn-primary" href="/admin/home-slider">Cấu hình Slider trang chủ →</a>
</section>

<section class="wp-card" style="max-width:760px;margin-top:24px">
    <div class="wp-card-header"><h2>🔌 Tích hợp &amp; API</h2></div>
    <p>Kết nối các dịch vụ bên ngoài và lưu thông tin API cần cho hệ thống.</p>
    <div class="wp-module-quick-grid">
        <article class="wp-module-quick-card">
            <div><h3>📈 Google Analytics 4</h3><p>Nhập Measurement ID, Property ID và service account key để bật theo dõi website và xem báo cáo trong quản trị.</p></div>
            <a class="wp-btn wp-btn-primary" href="/admin/analytics">Cấu hình GA4 →</a>
        </article>
        <article class="wp-module-quick-card">
            <div><h3>⚡ Performance</h3><p>Xem trạng thái PHP, OPcache, gzip, SQLite và dung lượng cache ứng dụng.</p></div>
            <a class="wp-btn wp-btn-secondary" href="/admin/performance">Xem hiệu năng →</a>
        </article>
        <article class="wp-module-quick-card">
            <div><h3>🧩 Extensions</h3><p>Quản lý provider đã cài và được khai báo trong cấu hình.</p></div>
            <a class="wp-btn wp-btn-secondary" href="/admin/extensions">Quản lý Extensions →</a>
        </article>
        <article class="wp-module-quick-card">
            <div><h3>✨ AI cho nội dung &amp; Analytics</h3><p>Dùng chung OpenAI-compatible API đã cấu hình trong Harness để hỗ trợ SEO, từ khóa, bản nháp và phân tích GA4. Chỉ gửi dữ liệu liên quan khi người quản trị chủ động yêu cầu.</p></div>
            <a class="wp-btn wp-btn-secondary" href="/admin/harness">Cấu hình AI API trong Harness →</a>
        </article>
    </div>
</section>

<div class="wp-card" style="max-width: 760px;">
    <form method="POST" action="/admin/settings/save">
            <?= \App\Presentation\Http\Csrf::field() ?>
        <div class="wp-form-group">
            <label for="site-url">Địa chỉ chính thức của website (Canonical Base URL)</label>
            <input type="url" id="site-url" name="site_url" value="<?= htmlspecialchars($settings['site_url'] ?? '') ?>" placeholder="https://example.com" required class="wp-input">
            <small class="wp-text-muted">Dùng để tạo canonical URL, Open Graph URL và liên kết trong sitemap. Nhập domain chính thức, không thêm dấu / ở cuối.</small>
        </div>
        <div class="wp-form-group">
            <label for="site-title">Tên Website / Cửa hàng (Site Title)</label>
            <input type="text" id="site-title" name="site_title" value="<?= htmlspecialchars($settings['site_title'] ?? '') ?>" required class="wp-input">
        </div>
        <div class="wp-form-group">
            <label for="site-tagline">Khẩu hiệu (Tagline)</label>
            <input type="text" id="site-tagline" name="site_tagline" value="<?= htmlspecialchars($settings['site_tagline'] ?? '') ?>" class="wp-input">
        </div>
        <div class="wp-grid-2">
            <div class="wp-form-group">
                <label for="contact-email">Email liên hệ</label>
                <input type="email" id="contact-email" name="contact_email" autocomplete="email" value="<?= htmlspecialchars($settings['contact_email'] ?? '') ?>" class="wp-input">
            </div>
            <div class="wp-form-group">
                <label for="contact-phone">Số điện thoại Hotline</label>
                <input type="text" id="contact-phone" name="contact_phone" value="<?= htmlspecialchars($settings['contact_phone'] ?? '') ?>" class="wp-input">
            </div>
        </div>
        <div class="wp-form-group">
            <label for="footer-text">Nội dung chân trang (Footer Copyright)</label>
            <input type="text" id="footer-text" name="footer_text" value="<?= htmlspecialchars($settings['footer_text'] ?? '') ?>" class="wp-input">
        </div>
        <div class="wp-form-group">
            <label class="wp-checkbox-label">
                <input type="checkbox" name="auto_approve_comments" value="1" <?= ($settings['auto_approve_comments'] ?? '1') === '1' ? 'checked' : '' ?>>
                Tự động phê duyệt bình luận &amp; đánh giá mới từ khách hàng
            </label>
        </div>
        <button type="submit" class="wp-btn wp-btn-primary">💾 Lưu thay đổi</button>
    </form>
</div>
