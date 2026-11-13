<?php
declare(strict_types=1);
/** @var array<string, mixed> $sliderConfig */
$slides = is_array($sliderConfig['slides'] ?? null) ? $sliderConfig['slides'] : [];
if ($slides === []) $slides = [['image' => '', 'media_id' => 0, 'eyebrow' => '', 'title' => '', 'description' => '', 'button_text' => '', 'button_url' => '', 'enabled' => true]];
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<div class="wp-page-header">
    <div><h1 class="wp-page-title">🖼️ Slider trang chủ</h1><p class="wp-page-subtitle">Quản lý ảnh banner, nội dung, hiệu ứng và tốc độ chuyển slide ngay dưới thanh điều hướng.</p></div>
    <a class="wp-btn wp-btn-secondary" href="/" target="_blank" rel="noopener">Xem trang chủ ↗</a>
</div>

<form method="post" action="/admin/home-slider/save" class="wp-home-slider-form" data-home-slider-form>
    <?= \App\Presentation\Http\Csrf::field() ?>
    <section class="wp-card wp-home-slider-options">
        <div class="wp-card-header"><h2>⚙️ Cài đặt hiển thị</h2></div>
        <label class="wp-checkbox-label"><input type="checkbox" name="enabled" value="1" <?= !empty($sliderConfig['enabled']) ? 'checked' : '' ?>> Bật slider trên trang chủ</label>
        <?php if (empty($sliderConfig['enabled']) && count(array_filter($slides, static fn(array $slide): bool => !empty($slide['enabled']) && !empty($slide['image']))) > 0): ?><p class="wp-slider-disabled-notice">Các slide đã có ảnh nhưng slider đang tắt. Bật tùy chọn trên rồi lưu để hiển thị ở trang chủ.</p><?php endif; ?>
        <div class="wp-grid-2">
            <div class="wp-form-group"><label for="slider-interval">Thời gian mỗi slide (giây)</label><input id="slider-interval" class="wp-input" type="number" name="interval" min="2" max="12" value="<?= $e($sliderConfig['interval'] ?? 3) ?>"><small class="wp-text-muted">Tự chuyển sau mỗi 3 giây theo mặc định.</small></div>
            <div class="wp-form-group"><label for="slider-effect">Hiệu ứng chuyển cảnh</label><select id="slider-effect" class="wp-input" name="effect"><option value="fade" <?= ($sliderConfig['effect'] ?? 'fade') === 'fade' ? 'selected' : '' ?>>Mờ dần (Fade)</option><option value="zoom" <?= ($sliderConfig['effect'] ?? '') === 'zoom' ? 'selected' : '' ?>>Phóng ảnh nhẹ (Zoom)</option><option value="slide" <?= ($sliderConfig['effect'] ?? '') === 'slide' ? 'selected' : '' ?>>Trượt ngang (Slide)</option></select></div>
        </div>
    </section>

    <section class="wp-card">
        <div class="wp-card-header wp-home-slider-list-heading"><div><h2>🌄 Danh sách slide</h2><p class="wp-text-muted">Tối đa 12 slide. Kéo các thẻ để đổi thứ tự; ảnh nên có tỷ lệ ngang 16:6.</p></div><button class="wp-btn wp-btn-secondary" type="button" data-slider-add>＋ Thêm slide</button></div>
        <div class="wp-home-slider-slides" data-slider-list>
            <?php foreach ($slides as $i => $slide): ?>
                <article class="wp-home-slide-card" data-slider-card draggable="true">
                    <div class="wp-home-slide-card-heading"><strong><span data-slide-number><?= $i + 1 ?></span> · Slide</strong><span class="wp-slide-drag-handle" aria-hidden="true">⠿ Kéo để sắp xếp</span><button class="wp-btn-text-danger" type="button" data-slide-remove aria-label="Xóa slide">Xóa</button></div>
                    <div class="wp-home-slide-layout">
                        <div class="wp-home-slide-image">
                            <div class="wp-home-slide-preview" data-slide-preview><?= !empty($slide['image']) ? '<img src="' . $e($slide['image']) . '" alt="">' : '<span>Chưa chọn ảnh</span>' ?></div>
                            <input type="hidden" name="slides[<?= $i ?>][image]" value="<?= $e($slide['image'] ?? '') ?>" data-slide-image>
                            <input type="hidden" name="slides[<?= $i ?>][media_id]" value="<?= $e($slide['media_id'] ?? 0) ?>" data-slide-media-id>
                            <button class="wp-btn wp-btn-secondary wp-btn-block" type="button" data-slide-pick>Chọn ảnh từ Media Library</button>
                            <small class="wp-text-muted">Hoặc <a href="/admin/media" target="_blank" rel="noopener">tải ảnh mới lên thư viện</a> rồi chọn tại đây.</small>
                        </div>
                        <div class="wp-home-slide-fields">
                            <div class="wp-form-group"><label>Dòng nhãn nhỏ</label><input class="wp-input" name="slides[<?= $i ?>][eyebrow]" maxlength="80" value="<?= $e($slide['eyebrow'] ?? '') ?>" placeholder="Bộ sưu tập mới"></div>
                            <div class="wp-form-group"><label>Tiêu đề</label><input class="wp-input" name="slides[<?= $i ?>][title]" maxlength="140" value="<?= $e($slide['title'] ?? '') ?>" placeholder="Tiêu đề nổi bật"></div>
                            <div class="wp-form-group"><label>Mô tả</label><textarea class="wp-textarea" name="slides[<?= $i ?>][description]" maxlength="260" rows="3" placeholder="Mô tả ngắn cho banner"><?= $e($slide['description'] ?? '') ?></textarea></div>
                            <div class="wp-grid-2"><div class="wp-form-group"><label>Nhãn nút</label><input class="wp-input" name="slides[<?= $i ?>][button_text]" maxlength="40" value="<?= $e($slide['button_text'] ?? '') ?>" placeholder="Khám phá ngay"></div><div class="wp-form-group"><label>Liên kết nút</label><input class="wp-input" name="slides[<?= $i ?>][button_url]" value="<?= $e($slide['button_url'] ?? '') ?>" placeholder="/shop hoặc https://..."></div></div>
                            <label class="wp-checkbox-label"><input type="checkbox" name="slides[<?= $i ?>][enabled]" value="1" <?= !empty($slide['enabled']) ? 'checked' : '' ?>> Hiển thị slide này</label>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <template data-slider-template>
            <article class="wp-home-slide-card" data-slider-card draggable="true">
                <div class="wp-home-slide-card-heading"><strong><span data-slide-number></span> · Slide</strong><span class="wp-slide-drag-handle" aria-hidden="true">⠿ Kéo để sắp xếp</span><button class="wp-btn-text-danger" type="button" data-slide-remove aria-label="Xóa slide">Xóa</button></div>
                <div class="wp-home-slide-layout"><div class="wp-home-slide-image"><div class="wp-home-slide-preview" data-slide-preview><span>Chưa chọn ảnh</span></div><input type="hidden" data-slide-image data-slide-field="image"><input type="hidden" data-slide-media-id data-slide-field="media_id"><button class="wp-btn wp-btn-secondary wp-btn-block" type="button" data-slide-pick>Chọn ảnh từ Media Library</button><small class="wp-text-muted">Hoặc <a href="/admin/media" target="_blank" rel="noopener">tải ảnh mới lên thư viện</a> rồi chọn tại đây.</small></div>
                    <div class="wp-home-slide-fields"><div class="wp-form-group"><label>Dòng nhãn nhỏ</label><input class="wp-input" data-slide-field="eyebrow" maxlength="80" placeholder="Bộ sưu tập mới"></div><div class="wp-form-group"><label>Tiêu đề</label><input class="wp-input" data-slide-field="title" maxlength="140" placeholder="Tiêu đề nổi bật"></div><div class="wp-form-group"><label>Mô tả</label><textarea class="wp-textarea" data-slide-field="description" maxlength="260" rows="3" placeholder="Mô tả ngắn cho banner"></textarea></div><div class="wp-grid-2"><div class="wp-form-group"><label>Nhãn nút</label><input class="wp-input" data-slide-field="button_text" maxlength="40" placeholder="Khám phá ngay"></div><div class="wp-form-group"><label>Liên kết nút</label><input class="wp-input" data-slide-field="button_url" placeholder="/shop hoặc https://..."></div></div><label class="wp-checkbox-label"><input type="checkbox" data-slide-field="enabled" checked> Hiển thị slide này</label></div>
                </div>
            </article>
        </template>
        <div class="wp-home-slider-save"><button type="submit" class="wp-btn wp-btn-primary">💾 Lưu slider trang chủ</button></div>
    </section>
</form>

<dialog class="product-media-dialog" id="rich-text-media-dialog" aria-label="Chọn ảnh cho slider">
    <div class="product-media-dialog-header"><strong class="rich-media-dialog-title" role="heading" aria-level="2">Chọn ảnh từ Media Library</strong><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-close>✕</button></div>
    <div class="product-media-toolbar"><label class="product-media-search"><span>⌕</span><input type="search" placeholder="Tìm ảnh…" data-rich-media-search></label><div class="product-media-toolbar-actions"><input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden data-rich-media-upload><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-upload-button>⬆️ Tải ảnh lên</button></div></div>
    <div class="product-media-grid" data-rich-media-grid></div>
    <div class="product-media-dialog-footer"><span data-rich-media-page></span><div><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-prev>←</button><button type="button" class="wp-btn wp-btn-secondary" data-rich-media-next>→</button></div><button type="button" class="wp-btn wp-btn-primary" data-rich-media-insert disabled>Chọn ảnh</button></div>
</dialog>
<script src="/assets/js/home-slider-admin.js" defer></script>
