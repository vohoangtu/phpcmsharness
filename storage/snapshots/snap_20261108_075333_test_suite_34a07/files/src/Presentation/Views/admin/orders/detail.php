<?php
declare(strict_types=1);
/** @var \App\Domain\Entity\Order $order */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">🧾 Chi tiết Đơn hàng #<?= htmlspecialchars($order->orderCode) ?></h1>
        <p class="wp-page-subtitle">Đặt lúc <?= htmlspecialchars($order->createdAt) ?></p>
    </div>
    <a href="/admin/orders" class="wp-btn wp-btn-secondary">← Quay lại danh sách đơn</a>
</div>

<div class="wp-editor-layout">
    <div class="wp-editor-main">
        <div class="wp-card wp-mb-20">
            <div class="wp-card-header">
                <h3>📦 Danh sách sản phẩm trong đơn</h3>
            </div>
            <table class="wp-table">
                <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th>Đơn giá</th>
                        <th>Số lượng</th>
                        <th style="text-align:right;">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->items as $item): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($item->productName) ?></strong></td>
                            <td><?= number_format($item->unitPrice, 0, ',', '.') ?> ₫</td>
                            <td>× <?= $item->quantity ?></td>
                            <td style="text-align:right;"><strong><?= number_format($item->subtotal, 0, ',', '.') ?> ₫</strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" style="text-align:right;">Tổng thanh toán:</th>
                        <th style="text-align:right; font-size:18px; color:#d63638;"><?= $order->formattedTotal() ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="wp-card">
            <div class="wp-card-header">
                <h3>📍 Thông tin giao hàng của khách</h3>
            </div>
            <p><strong>Họ tên người nhận:</strong> <?= htmlspecialchars($order->customerName) ?></p>
            <p><strong>Số điện thoại:</strong> <?= htmlspecialchars($order->customerPhone) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($order->customerEmail) ?></p>
            <p><strong>Địa chỉ nhận hàng:</strong> <?= htmlspecialchars($order->customerAddress) ?></p>
            <?php if ($order->note !== ''): ?>
                <p><strong>Ghi chú của khách:</strong> <em><?= htmlspecialchars($order->note) ?></em></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="wp-editor-sidebar">
        <div class="wp-card">
            <div class="wp-card-header">
                <h3>⚙️ Cập nhật Trạng thái Đơn</h3>
            </div>
            <form method="POST" action="/admin/orders/status/<?= $order->
            <?= \App\Presentation\Http\Csrf::field() ?>id ?>">
                <div class="wp-form-group">
                    <label>Trạng thái hiện tại</label>
                    <select name="status" class="wp-select">
                        <?php foreach (\App\Domain\Enum\OrderStatus::cases() as $st): ?>
                            <option value="<?= $st->value ?>" <?= $order->status === $st ? 'selected' : '' ?>>
                                <?= $st->label() ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="wp-btn wp-btn-primary wp-btn-block">Lưu trạng thái đơn hàng</button>
            </form>
        </div>
    </div>
</div>
