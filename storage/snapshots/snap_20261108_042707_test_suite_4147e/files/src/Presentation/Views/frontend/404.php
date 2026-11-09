<?php
declare(strict_types=1);
/**
 * @var string $pageTitle
 * @var string $message
 */
?>
<div class="fe-empty-box" style="max-width:600px; margin: 48px auto;">
    <div style="font-size:46px; margin-bottom:10px;">🔎</div>
    <h2><?= htmlspecialchars($pageTitle ?? '404 - Không tìm thấy nội dung') ?></h2>
    <p><?= htmlspecialchars($message ?? 'Trang bạn đang tìm kiếm không tồn tại hoặc chức năng này đang tạm ngưng.') ?></p>
    <div style="margin-top:20px;">
        <a href="/" class="fe-btn fe-btn-primary">← Quay về Trang chủ</a>
    </div>
</div>
