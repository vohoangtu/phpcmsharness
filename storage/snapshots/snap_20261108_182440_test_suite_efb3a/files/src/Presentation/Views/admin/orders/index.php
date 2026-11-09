<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Order[] $orders
 * @var array{total_orders: int, pending_orders: int, total_revenue: float} $stats
 * @var string $currentStatus
 * @var string $searchQuery
 * @var int $currentPage
 * @var int $totalPages
 * @var int $totalItems
 * @var int $pageSize
 */
$paginationQuery = array_filter(['status' => $currentStatus, 'q' => $searchQuery], static fn(string $value): bool => $value !== '');
$statusUrl = static function (string $status) use ($searchQuery): string {
    $query = array_filter(['status' => $status, 'q' => $searchQuery], static fn(string $value): bool => $value !== '');
    return '/admin/orders' . ($query === [] ? '' : '?' . http_build_query($query));
};
$rangeStart = $totalItems === 0 ? 0 : (($currentPage - 1) * $pageSize) + 1;
$rangeEnd = min($currentPage * $pageSize, $totalItems);
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">🛒 Quản lý Đơn hàng (WooCommerce Orders)</h1>
        <p class="wp-page-subtitle">Theo dõi đơn đặt hàng, doanh thu và cập nhật tiến độ giao hàng.</p>
    </div>
</div>

<form method="get" action="/admin/orders" class="wp-card" style="display:flex; align-items:end; gap:10px; margin:16px 0;">
    <?php if ($currentStatus !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <label for="order-search">Tìm theo mã đơn, tên, email hoặc số điện thoại</label>
    <input id="order-search" type="search" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" placeholder="Nhập từ khóa">
    <button type="submit" class="wp-btn wp-btn-primary">Tìm đơn hàng</button>
    <?php if ($searchQuery !== ''): ?><a class="wp-btn wp-btn-secondary" href="/admin/orders<?= $currentStatus !== '' ? '?status=' . rawurlencode($currentStatus) : '' ?>">Xóa tìm kiếm</a><?php endif; ?>
</form>

<div class="wp-subsubsub">
    <a href="<?= htmlspecialchars($statusUrl(''), ENT_QUOTES, 'UTF-8') ?>" class="<?= $currentStatus === '' ? 'current' : '' ?>">Tất cả đơn hàng (<?= $stats['total_orders'] ?>)</a> |
    <a href="<?= htmlspecialchars($statusUrl('pending'), ENT_QUOTES, 'UTF-8') ?>" class="<?= $currentStatus === 'pending' ? 'current' : '' ?>">Chờ xử lý (<?= $stats['pending_orders'] ?>)</a> |
    <a href="<?= htmlspecialchars($statusUrl('processing'), ENT_QUOTES, 'UTF-8') ?>" class="<?= $currentStatus === 'processing' ? 'current' : '' ?>">Đang giao hàng</a> |
    <a href="<?= htmlspecialchars($statusUrl('completed'), ENT_QUOTES, 'UTF-8') ?>" class="<?= $currentStatus === 'completed' ? 'current' : '' ?>">Hoàn thành</a> |
    <a href="<?= htmlspecialchars($statusUrl('cancelled'), ENT_QUOTES, 'UTF-8') ?>" class="<?= $currentStatus === 'cancelled' ? 'current' : '' ?>">Đã hủy</a>
</div>

<div class="wp-card">
    <?php if (empty($orders)): ?>
        <div class="wp-empty-state">
            <p>Chưa có đơn đặt hàng nào trong trạng thái này.</p>
        </div>
    <?php else: ?>
        <table class="wp-table">
            <thead>
                <tr>
                    <th>Mã đơn</th>
                    <th>Khách hàng</th>
                    <th>Số điện thoại</th>
                    <th>Số mặt hàng</th>
                    <th>Tổng thanh toán</th>
                    <th>Trạng thái</th>
                    <th>Ngày đặt</th>
                    <th style="text-align:right;">Chi tiết</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><a href="/admin/orders/<?= $order->id ?>"><strong>#<?= htmlspecialchars($order->orderCode) ?></strong></a></td>
                        <td>
                            <strong><?= htmlspecialchars($order->customerName) ?></strong><br>
                            <small class="wp-text-muted"><?= htmlspecialchars($order->customerEmail) ?></small>
                        </td>
                        <td><?= htmlspecialchars($order->customerPhone) ?></td>
                        <td><?= count($order->items) ?> sản phẩm</td>
                        <td><strong><?= $order->formattedTotal() ?></strong></td>
                        <td><span class="wp-status-badge <?= $order->status->badgeClass() ?>"><?= $order->status->label() ?></span></td>
                        <td><small><?= htmlspecialchars($order->createdAt) ?></small></td>
                        <td style="text-align:right;">
                            <a href="/admin/orders/<?= $order->id ?>" class="wp-btn wp-btn-secondary wp-btn-sm">Xem &amp; Xử lý →</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<nav class="wp-pagination" aria-label="Phân trang đơn hàng" style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:16px;">
    <span class="wp-text-muted">Hiển thị <?= $rangeStart ?>–<?= $rangeEnd ?> trên <?= $totalItems ?> đơn hàng</span>
    <div style="display:flex; align-items:center; gap:8px;">
        <?php if ($currentPage > 1): ?>
            <a class="wp-btn wp-btn-secondary" href="/admin/orders?<?= htmlspecialchars(http_build_query($paginationQuery + ['page' => $currentPage - 1]), ENT_QUOTES, 'UTF-8') ?>">← Trước</a>
        <?php else: ?>
            <span class="wp-btn wp-btn-secondary" aria-disabled="true">← Trước</span>
        <?php endif; ?>
        <span>Trang <?= $currentPage ?> / <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?>
            <a class="wp-btn wp-btn-secondary" href="/admin/orders?<?= htmlspecialchars(http_build_query($paginationQuery + ['page' => $currentPage + 1]), ENT_QUOTES, 'UTF-8') ?>">Tiếp →</a>
        <?php else: ?>
            <span class="wp-btn wp-btn-secondary" aria-disabled="true">Tiếp →</span>
        <?php endif; ?>
    </div>
</nav>
