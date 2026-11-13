<?php
/** @var list<array{id:string,name:string,version:string,description:string,provider:string,enabled:bool,installed:bool}> $extensions */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="wp-page-header"><div><h1 class="wp-page-title">🧩 Extensions</h1><p class="wp-page-subtitle">Quản lý provider đã cài và khai báo trong cấu hình hệ thống.</p></div></div>
<div class="wp-notice wp-notice-info"><span>Để cài extension mới, cài package PHP qua Composer rồi khai báo manifest trong <code>config/extensions.php</code>. Chỉ dùng mã nguồn đáng tin cậy; bật extension sẽ chạy PHP của provider trong ứng dụng.</span></div>
<section class="wp-card">
    <?php if (!$extensions): ?><div class="wp-empty-state"><h2>Chưa có extension</h2><p>Khai báo provider đầu tiên trong <code>config/extensions.php</code>.</p></div>
    <?php else: ?><div class="wp-table-wrap"><table class="wp-table"><thead><tr><th>Extension</th><th>Provider</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
        <?php foreach ($extensions as $extension): ?><tr>
            <td><strong><?= $e($extension['name']) ?></strong> <small>v<?= $e($extension['version']) ?></small><div class="wp-muted"><?= $e($extension['description']) ?></div><code><?= $e($extension['id']) ?></code></td>
            <td><code><?= $e($extension['provider']) ?></code><?php if (!$extension['installed']): ?><div class="wp-status-badge badge-danger">Provider chưa được Composer autoload</div><?php endif; ?></td>
            <td><span class="wp-status-badge <?= $extension['enabled'] && $extension['installed'] ? 'badge-success' : 'badge-danger' ?>"><?= $extension['enabled'] && $extension['installed'] ? 'Đang bật' : ($extension['enabled'] ? 'Thiếu package' : 'Đã tắt') ?></span></td>
            <td><form method="post" action="/admin/extensions/toggle"><?= \App\Presentation\Http\Csrf::field() ?><input type="hidden" name="extension_id" value="<?= $e($extension['id']) ?>"><input type="hidden" name="state" value="<?= $extension['enabled'] ? '0' : '1' ?>"><button class="wp-btn <?= $extension['enabled'] ? 'wp-btn-danger-outline' : 'wp-btn-primary' ?>" type="submit" <?= !$extension['installed'] && !$extension['enabled'] ? 'disabled' : '' ?>><?= $extension['enabled'] ? 'Tắt extension' : 'Bật extension' ?></button></form></td>
        </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
