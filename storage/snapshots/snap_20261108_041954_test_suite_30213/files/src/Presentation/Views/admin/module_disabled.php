<?php
declare(strict_types=1);
/** @var \App\Domain\Enum\ModuleKey $disabledModule */
?>
<div class="wp-card wp-empty-state">
    <div class="wp-empty-icon"><?= $disabledModule->icon() ?></div>
    <h2>Chức năng "<?= htmlspecialchars($disabledModule->label()) ?>" hiện đang TẮT</h2>
    <p><?= htmlspecialchars($disabledModule->description()) ?></p>
    <form method="POST" action="/admin/modules/toggle" style="margin-top: 20px;">
        <input type="hidden" name="module_key" value="<?= htmlspecialchars($disabledModule->value) ?>">
        <input type="hidden" name="state" value="1">
        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/admin') ?>">
        <button type="submit" class="wp-btn wp-btn-primary">⚡ Bật ngay chức năng này</button>
        <a href="/admin/modules" class="wp-btn wp-btn-secondary">Quản lý tất cả Modules</a>
    </form>
</div>
