<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\Order[] $orders
 * @var array{total_orders: int, pending_orders: int, total_revenue: float} $stats
 * @var string $currentStatus
 */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">🛒 Quản lý Đơn hàng (WooCommerce Orders)</h1>
        <p class="wp-page-subtitle">Theo dõi đơn đặt hàng, doanh thu và cập nhật tiến độ giao hàng.</p>
    </div>
</div>

<div class="wp-subsubsub">
    <a href="/admin/orders" class="<?= $currentStatus === '' ? 'current' : '' ?>">Tất cả đơn hàng (<?= $stats['total_orders'] ?>)</a> |
    <a href="/admin/orders?status=pending" class="<?= $currentStatus === 'pending' ? 'current' : '' ?>">Chờ xử lý (<?= $stats['pending_orders'] ?>)</a> |
    <a href="/admin/orders?status=processing" class="<?= $currentStatus === 'processing' ? 'current' : '' ?>">Đang giao hàng</a> |
    <a href="/admin/orders?status=completed" class="<?= $currentStatus === 'completed' ? 'current' : '' ?>">Hoàn thành</a> |
    <a href="/admin/orders?status=cancelled" class="<?= $currentStatus === 'cancelled' ? 'current' : '' ?>">Đã hủy</a>
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
