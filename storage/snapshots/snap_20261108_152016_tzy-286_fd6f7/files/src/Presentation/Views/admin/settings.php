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

<div class="wp-card" style="max-width: 760px;">
    <form method="POST" action="/admin/settings/save">
            <?= \App\Presentation\Http\Csrf::field() ?>
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
                <input type="email" id="contact-email" name="contact_email" value="<?= htmlspecialchars($settings['contact_email'] ?? '') ?>" class="wp-input">
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
