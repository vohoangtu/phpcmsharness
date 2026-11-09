<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\FeatureModule[] $modules
 * @var array<string, int> $postCounts
 * @var array<string, int> $productCounts
 * @var array{total_orders: int, pending_orders: int, total_revenue: float} $orderStats
 * @var array<string, int> $commentCounts
 * @var \App\Domain\Entity\Post[] $recentPosts
 * @var \App\Domain\Entity\Product[] $recentProducts
 * @var \App\Domain\Entity\Order[] $recentOrders
 * @var array<string, bool> $enabledModules
 */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">Bảng điều khiển (Dashboard)</h1>
        <p class="wp-page-subtitle">Quản lý tổng quan Bài viết, Sản phẩm và bật/tắt tức thì các Module theo kiến trúc Hexagonal.</p>
    </div>
    <div class="wp-header-actions">
        <a href="/admin/modules" class="wp-btn wp-btn-secondary">🔌 Quản lý chi tiết Modules</a>
        <?php if (!empty($enabledModules['posts'])): ?>
            <a href="/admin/posts/create" class="wp-btn wp-btn-primary">+ Viết bài mới</a>
        <?php endif; ?>
        <?php if (!empty($enabledModules['products'])): ?>
            <a href="/admin/products/create" class="wp-btn wp-btn-primary">+ Thêm sản phẩm</a>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Row -->
<div class="wp-stats-grid">
    <div class="wp-stat-card">
        <div class="wp-stat-icon">📝</div>
        <div>
            <div class="wp-stat-value"><?= (int) ($postCounts['all'] ?? 0) ?></div>
            <div class="wp-stat-label">Bài viết (<?= (int) ($postCounts['published'] ?? 0) ?> xuất bản)</div>
        </div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-icon">🛍️</div>
        <div>
            <div class="wp-stat-value"><?= (int) ($productCounts['all'] ?? 0) ?></div>
            <div class="wp-stat-label">Sản phẩm trong kho</div>
        </div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-icon">🛒</div>
        <div>
            <div class="wp-stat-value"><?= (int) ($orderStats['total_orders'] ?? 0) ?></div>
            <div class="wp-stat-label">Đơn hàng (<?= number_format((float) ($orderStats['total_revenue'] ?? 0), 0, ',', '.') ?> ₫)</div>
        </div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-icon">💬</div>
        <div>
            <div class="wp-stat-value"><?= (int) ($commentCounts['all'] ?? 0) ?></div>
            <div class="wp-stat-label">Bình luận &amp; Đánh giá</div>
        </div>
    </div>
</div>

<!-- BẢNG ĐIỀU KHIỂN BẬT/TẮT CHỨC NĂNG NHANH (FEATURE TOGGLE CENTER) -->
<section class="wp-card wp-mb-24">
    <div class="wp-card-header">
        <div>
            <h2>⚡ Trung tâm Bật / Tắt Chức năng Nhanh (Hexagonal Feature Modules)</h2>
            <p class="wp-text-muted">Gạt công tắc để bật hoặc tắt từng phân hệ ngay lập tức. Thanh điều hướng và giao diện khách hàng sẽ tự động cập nhật.</p>
        </div>
        <a href="/admin/modules" class="wp-link">Xem trang Plugins/Modules →</a>
    </div>
    <div class="wp-module-quick-grid">
        <?php foreach ($modules as $mod): ?>
            <div class="wp-module-quick-card <?= $mod->isEnabled ? 'is-enabled' : 'is-disabled' ?>">
                <div class="wp-module-quick-info">
                    <span class="wp-module-icon"><?= $mod->icon ?></span>
                    <div>
                        <strong><?= htmlspecialchars($mod->name) ?></strong>
                        <small><?= htmlspecialchars($mod->description) ?></small>
                        <?php if ($mod->dependsOn !== null): ?>
                            <span class="wp-dep-tag">Phụ thuộc: <?= htmlspecialchars($mod->dependsOn->label()) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <form method="POST" action="/admin/modules/toggle" class="wp-toggle-form">
            <?= \App\Presentation\Http\Csrf::field() ?>
                    <input type="hidden" name="module_key" value="<?= htmlspecialchars($mod->key->value) ?>">
                    <input type="hidden" name="redirect_to" value="/admin">
                    <input type="hidden" name="state" value="<?= $mod->isEnabled ? '0' : '1' ?>">
                    <label class="wp-switch" title="Nhấn để Bật/Tắt chức năng này">
                        <input
                            type="checkbox"
                            class="js-module-toggle"
                            data-module="<?= htmlspecialchars($mod->key->value) ?>"
                            <?= $mod->isEnabled ? 'checked' : '' ?>
                            onchange="this.form.submit()"
                        >
                        <span class="wp-slider"></span>
                    </label>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Dashboard 2-Column Widgets (Giống WordPress At a Glance + Quick Draft) -->
<div class="wp-dashboard-grid">
    <!-- Left Column: Recent Posts & Products -->
    <div class="wp-dashboard-col">
        <?php if (!empty($enabledModules['posts'])): ?>
            <div class="wp-card wp-mb-20">
                <div class="wp-card-header">
                    <h3>📝 Bài viết mới xuất bản</h3>
                    <a href="/admin/posts" class="wp-link">Xem tất cả →</a>
                </div>
                <table class="wp-table">
                    <thead>
                        <tr>
                            <th>Tiêu đề</th>
                            <th>Danh mục</th>
                            <th>Trạng thái</th>
                            <th>Lượt xem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPosts as $p): ?>
                            <tr>
                                <td>
                                    <a href="/admin/posts/edit/<?= $p->id ?>"><strong><?= htmlspecialchars($p->title) ?></strong></a>
                                </td>
                                <td><?= htmlspecialchars($p->categoryName ?? 'Chưa phân loại') ?></td>
                                <td><span class="wp-status-badge <?= $p->status->badgeClass() ?>"><?= $p->status->label() ?></span></td>
                                <td><?= $p->views ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if (!empty($enabledModules['products'])): ?>
            <div class="wp-card">
                <div class="wp-card-header">
                    <h3>🛍️ Sản phẩm nổi bật trong cửa hàng</h3>
                    <a href="/admin/products" class="wp-link">Quản lý kho →</a>
                </div>
                <table class="wp-table">
                    <thead>
                        <tr>
                            <th>Sản phẩm</th>
                            <th>Mã SKU</th>
                            <th>Giá bán</th>
                            <th>Tồn kho</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentProducts as $prod): ?>
                            <tr>
                                <td>
                                    <a href="/admin/products/edit/<?= $prod->id ?>"><strong><?= htmlspecialchars($prod->name) ?></strong></a>
                                </td>
                                <td><code><?= htmlspecialchars($prod->sku) ?></code></td>
                                <td><strong><?= $prod->formattedEffectivePrice() ?></strong></td>
                                <td><?= $prod->stockQuantity ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Quick Draft & Recent Orders -->
    <div class="wp-dashboard-col">
        <?php if (!empty($enabledModules['posts'])): ?>
            <div class="wp-card wp-mb-20">
                <div class="wp-card-header">
                    <h3>✍️ Tạo Bản Nháp Nhanh (Quick Draft)</h3>
                </div>
                <form method="POST" action="/admin/quick-draft">
            <?= \App\Presentation\Http\Csrf::field() ?>
                    <div class="wp-form-group">
                        <label for="quick-draft-title">Tiêu đề bài viết</label>
                        <input type="text" id="quick-draft-title" name="title" placeholder="Bạn đang nghĩ gì?" required class="wp-input">
                    </div>
                    <div class="wp-form-group">
                        <label for="quick-draft-content">Nội dung ghi chú nhanh</label>
                        <textarea id="quick-draft-content" name="content" rows="4" placeholder="Nhập ý tưởng bài viết của bạn..." class="wp-textarea"></textarea>
                    </div>
                    <button type="submit" class="wp-btn wp-btn-primary">Lưu bản nháp</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($enabledModules['orders'])): ?>
            <div class="wp-card">
                <div class="wp-card-header">
                    <h3>🛒 Đơn đặt hàng gần đây</h3>
                    <a href="/admin/orders" class="wp-link">Tất cả đơn →</a>
                </div>
                <?php if (empty($recentOrders)): ?>
                    <p class="wp-text-muted">Chưa có đơn hàng nào.</p>
                <?php else: ?>
                    <table class="wp-table">
                        <thead>
                            <tr>
                                <th>Mã đơn</th>
                                <th>Khách hàng</th>
                                <th>Tổng tiền</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $ord): ?>
                                <tr>
                                    <td><a href="/admin/orders/<?= $ord->id ?>"><strong>#<?= htmlspecialchars($ord->orderCode) ?></strong></a></td>
                                    <td><?= htmlspecialchars($ord->customerName) ?></td>
                                    <td><strong><?= $ord->formattedTotal() ?></strong></td>
                                    <td><span class="wp-status-badge <?= $ord->status->badgeClass() ?>"><?= $ord->status->label() ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
