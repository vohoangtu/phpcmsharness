<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\FeatureModule[] $modules
 * @var int $totalCount
 * @var int $enabledCount
 * @var int $disabledCount
 * @var string $currentFilter
 */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">🔌 Quản lý Chức năng / Modules (Giống WordPress Plugins)</h1>
        <p class="wp-page-subtitle">Bật hoặc tắt từng chức năng của hệ thống. Mỗi chức năng được bảo vệ bởi <code>ModuleGuardMiddleware</code> và <code>ModuleRegistryPort</code> trong kiến trúc Hexagonal.</p>
    </div>
</div>

<!-- Filter Tabs (All | Active | Inactive) giống WordPress Plugins -->
<div class="wp-subsubsub">
    <a href="/admin/modules?filter=all" class="<?= $currentFilter === 'all' ? 'current' : '' ?>">
        Tất cả <span class="count">(<?= $totalCount ?>)</span>
    </a> |
    <a href="/admin/modules?filter=enabled" class="<?= $currentFilter === 'enabled' ? 'current' : '' ?>">
        Đang kích hoạt <span class="count">(<?= $enabledCount ?>)</span>
    </a> |
    <a href="/admin/modules?filter=disabled" class="<?= $currentFilter === 'disabled' ? 'current' : '' ?>">
        Đang tắt <span class="count">(<?= $disabledCount ?>)</span>
    </a>
</div>

<div class="wp-card">
    <table class="wp-table wp-plugins-table">
        <thead>
            <tr>
                <th style="width: 280px;">Chức năng (Module)</th>
                <th>Mô tả chi tiết &amp; Kiến trúc</th>
                <th style="width: 150px;">Trạng thái</th>
                <th style="width: 170px; text-align:right;">Thao tác Bật / Tắt</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($modules as $mod): ?>
                <tr class="<?= $mod->isEnabled ? 'plugin-active' : 'plugin-inactive' ?>">
                    <td>
                        <div class="wp-plugin-title">
                            <span class="wp-plugin-icon"><?= $mod->icon ?></span>
                            <div>
                                <strong><?= htmlspecialchars($mod->name) ?></strong>
                                <div class="wp-plugin-code">Key: <code><?= htmlspecialchars($mod->key->value) ?></code></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <p class="wp-mb-8"><?= htmlspecialchars($mod->description) ?></p>
                        <?php if ($mod->dependsOn !== null): ?>
                            <small class="wp-dep-tag">🔗 Phụ thuộc vào module: <strong><?= htmlspecialchars($mod->dependsOn->label()) ?></strong></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($mod->isEnabled): ?>
                            <span class="wp-status-badge badge-success">● Đang hoạt động</span>
                        <?php else: ?>
                            <span class="wp-status-badge badge-danger">○ Đã tắt</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <form method="POST" action="/admin/modules/toggle" style="display:inline-flex; align-items:center; gap:10px;">
                            <input type="hidden" name="module_key" value="<?= htmlspecialchars($mod->key->value) ?>">
                            <input type="hidden" name="redirect_to" value="/admin/modules?filter=<?= urlencode($currentFilter) ?>">
                            <input type="hidden" name="state" value="<?= $mod->isEnabled ? '0' : '1' ?>">
                            <?php if ($mod->isEnabled): ?>
                                <button type="submit" class="wp-btn wp-btn-danger-outline wp-btn-sm">Ngừng kích hoạt (Tắt)</button>
                            <?php else: ?>
                                <button type="submit" class="wp-btn wp-btn-primary wp-btn-sm">Kích hoạt (Bật)</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
