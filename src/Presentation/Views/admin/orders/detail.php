<?php
declare(strict_types=1);
/** @var \App\Domain\Entity\Order $order */
$operations = $operations ?? ['payments' => [], 'shipments' => [], 'adjustments' => [], 'invoice' => null, 'activity' => [], 'net_paid' => 0];
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$money = static fn($v): string => number_format((float) $v, 0, ',', '.') . ' ₫';
$net = (int) $operations['net_paid'];
$paid = array_sum(array_map(static fn($p): int => $p['kind'] === 'payment' ? (int) $p['amount_vnd'] : 0, $operations['payments']));
$refunded = $paid - $net;
$itemTotal = array_sum(array_map(static fn($item): float => $item->subtotal, $order->items));
$adjustmentTotal = array_sum(array_map(static fn($item): int => (int) $item['amount_vnd'], $operations['adjustments']));
$paymentStatus = !$operations['payments'] ? 'Chưa có thông tin thanh toán' : ($refunded > 0 ? ($net === 0 ? 'Đã hoàn hết' : 'Đã hoàn một phần') : ($net >= (int) round($order->totalAmount) ? 'Đã thanh toán' : 'Thanh toán một phần'));
$shippingLabels = ['pending'=>'Chưa tạo vận đơn','awaiting_pickup'=>'Chờ lấy hàng','in_transit'=>'Đang vận chuyển','delivered'=>'Đã giao','failed'=>'Giao thất bại','returned'=>'Hoàn hàng'];
$invoice = $operations['invoice'] ?? [];
$allowedOrderStatuses = match ($order->status) {
    \App\Domain\Enum\OrderStatus::PENDING => [\App\Domain\Enum\OrderStatus::PENDING, \App\Domain\Enum\OrderStatus::PROCESSING, \App\Domain\Enum\OrderStatus::CANCELLED],
    \App\Domain\Enum\OrderStatus::PROCESSING => [\App\Domain\Enum\OrderStatus::PROCESSING, \App\Domain\Enum\OrderStatus::COMPLETED, \App\Domain\Enum\OrderStatus::CANCELLED],
    \App\Domain\Enum\OrderStatus::COMPLETED => [\App\Domain\Enum\OrderStatus::COMPLETED, \App\Domain\Enum\OrderStatus::CANCELLED],
    \App\Domain\Enum\OrderStatus::CANCELLED => [\App\Domain\Enum\OrderStatus::CANCELLED],
};
?>
<div class="wp-page-header order-page-header">
    <div class="order-page-heading">
        <p class="order-eyebrow">QUẢN LÝ ĐƠN HÀNG <span>·</span> <?= $e($order->createdAt) ?></p>
        <h1 class="wp-page-title">Đơn hàng <span class="order-code">#<?= $e($order->orderCode) ?></span></h1>
        <p class="wp-page-subtitle">Theo dõi sản phẩm, thanh toán và quá trình giao nhận.</p>
    </div>
    <div class="order-header-actions"><a href="/admin/orders" class="wp-btn wp-btn-secondary">← Danh sách đơn</a><a href="/admin/orders/print/<?= (int) $order->id ?>" class="wp-btn wp-btn-secondary" target="_blank" rel="noopener">🖨 In phiếu</a></div>
</div>

<section class="order-overview" aria-label="Tóm tắt đơn hàng">
    <div class="order-overview-item"><span>Trạng thái đơn</span><strong><i class="order-status-dot"></i><?= $e($order->status->label()) ?></strong></div>
    <div class="order-overview-item"><span>Thanh toán</span><strong><?= $e($paymentStatus) ?></strong><small>Đã thu ròng <?= $money($net) ?></small></div>
    <div class="order-overview-item"><span>Giao hàng</span><strong><?= $operations['shipments'] ? $e($shippingLabels[$operations['shipments'][0]['status']] ?? $operations['shipments'][0]['status']) : 'Chưa tạo vận đơn' ?></strong><small><?= count($operations['shipments']) ?> vận đơn</small></div>
    <div class="order-overview-total"><span>Tổng đơn hàng</span><strong><?= $order->formattedTotal() ?></strong><small>Còn phải thu <?= $money(max(0, $order->totalAmount - $net)) ?></small></div>
</section>

<div class="wp-editor-layout order-detail-layout">
    <div class="wp-editor-main">
        <div class="wp-card wp-mb-20 order-section-card" id="order-items">
            <div class="wp-card-header">
                <div><span class="order-section-kicker">CHI TIẾT ĐƠN</span><h2>📦 Sản phẩm</h2></div><span class="order-count-badge"><?= count($order->items) ?> mặt hàng</span>
            </div>
            <div class="order-detail-table-wrap"><table class="wp-table order-detail-table">
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
                            <td data-label="Sản phẩm"><strong><?= htmlspecialchars($item->productName) ?></strong></td>
                            <td data-label="Đơn giá"><?= number_format($item->unitPrice, 0, ',', '.') ?> ₫</td>
                            <td data-label="Số lượng">× <?= $item->quantity ?></td>
                            <td data-label="Thành tiền" style="text-align:right;"><strong><?= number_format($item->subtotal, 0, ',', '.') ?> ₫</strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" style="text-align:right;">Tổng thanh toán:</th>
                        <th style="text-align:right; font-size:18px; color:#d63638;"><?= $order->formattedTotal() ?></th>
                    </tr>
                </tfoot>
            </table></div>
            <p class="wp-text-muted order-total-note">Tiền hàng <?= $money($itemTotal) ?> <span>·</span> Tổng ban đầu <?= $money($order->totalAmount - $adjustmentTotal) ?> <span>·</span> Điều chỉnh <?= $money($adjustmentTotal) ?>. Phí vận chuyển theo dõi riêng, không tự cộng vào tổng đơn.</p>
        </div>

        <div class="wp-card wp-mb-20 order-section-card" id="order-payment">
            <div class="wp-card-header"><h2>💳 Thanh toán</h2></div>
            <p><strong><?= $e($paymentStatus) ?></strong> · Đã thu: <?= $money($paid) ?> · Đã hoàn: <?= $money($refunded) ?> · Thu ròng: <?= $money($net) ?> · Còn phải thu: <?= $money(max(0, $order->totalAmount - $net)) ?></p>
            <?php if (!$operations['payments']): ?><p class="wp-text-muted">Chưa có giao dịch được ghi nhận.</p><?php endif; ?>
            <?php foreach ($operations['payments'] as $payment): ?><p><?= $e($payment['created_at']) ?> · <?= $payment['kind'] === 'payment' ? 'Thu' : 'Hoàn' ?> <?= $money($payment['amount_vnd']) ?> · <?= $e($payment['method']) ?> · <?= $e($payment['reference']) ?> · <?= $e($payment['actor']) ?> <?= $e($payment['note']) ?> · <a href="/admin/orders/payment-document/<?= (int) $payment['id'] ?>" target="_blank" rel="noopener"><?= $payment['kind'] === 'payment' ? 'In phiếu thu' : 'In phiếu chi' ?> ↗</a></p><?php endforeach; ?>
            <details class="order-form-disclosure"><summary>+ Ghi nhận thu tiền hoặc hoàn tiền</summary><form method="post" action="/admin/orders/operations/<?= (int)$order->id ?>" class="wp-form-group order-operations-form">
                <?= \App\Presentation\Http\Csrf::field() ?><input type="hidden" name="operation" value="payment">
                <div class="wp-grid-2"><label>Loại giao dịch<select name="kind" class="wp-select"><option value="payment">Thu tiền</option><option value="refund">Hoàn tiền</option></select></label><label>Số tiền (₫)<input class="wp-input" type="number" name="amount" min="1" step="1" required></label><label>Phương thức<input class="wp-input" name="method" placeholder="COD, chuyển khoản..." required></label><label>Mã tham chiếu<input class="wp-input" name="reference"></label></div>
                <label>Ghi chú<input class="wp-input" name="note"></label><button class="wp-btn wp-btn-primary" type="submit">Ghi nhận giao dịch thủ công</button>
            </form></details>
        </div>
        <div class="wp-card wp-mb-20 order-section-card" id="order-shipping">
            <div class="wp-card-header"><h2>🚚 Vận chuyển và giao hàng</h2></div>
            <?php if (!$operations['shipments']): ?><p class="wp-text-muted">Chưa có vận đơn.</p><?php endif; ?>
            <?php foreach ($operations['shipments'] as $shipment): ?>
            <form method="post" action="/admin/orders/operations/<?= (int)$order->id ?>" class="wp-form-group order-operations-form" style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin:12px 0">
                <?= \App\Presentation\Http\Csrf::field() ?><input type="hidden" name="operation" value="shipment"><input type="hidden" name="shipment_id" value="<?= (int)$shipment['id'] ?>"><strong><?= $shipment['id'] ? 'Vận đơn #'.(int)$shipment['id'] : '+ Thêm vận đơn thủ công' ?></strong>
                <div class="wp-grid-2"><label>Đơn vị vận chuyển<input class="wp-input" name="carrier" value="<?= $e($shipment['carrier']) ?>"></label><label>Mã vận đơn<input class="wp-input" name="tracking_code" value="<?= $e($shipment['tracking_code']) ?>"></label><label>Trạng thái<select class="wp-select" name="status"><?php foreach ($shippingLabels as $value=>$label): ?><option value="<?= $e($value) ?>" <?= $shipment['status']===$value?'selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label><label>Phí vận chuyển (₫)<input class="wp-input" name="shipping_fee" type="number" min="0" step="1" value="<?= (int)$shipment['shipping_fee_vnd'] ?>"></label><label>Ngày giao dự kiến<input class="wp-input" type="date" name="expected_at" value="<?= $e($shipment['expected_at']) ?>"></label><label>Ghi chú<input class="wp-input" name="note" value="<?= $e($shipment['note']) ?>"></label></div>
                <label>Liên kết theo dõi<input class="wp-input" type="url" name="tracking_url" value="<?= $e($shipment['tracking_url'] ?? '') ?>" placeholder="https://..."></label>
                <?php if (!empty($shipment['tracking_url']) && in_array(strtolower((string)parse_url($shipment['tracking_url'], PHP_URL_SCHEME)), ['http','https'], true)): ?><a href="<?= $e($shipment['tracking_url']) ?>" target="_blank" rel="noopener noreferrer">Theo dõi vận đơn ↗</a><br><?php endif; ?>
                <small class="wp-text-muted">Phí vận đơn không tự thay đổi tổng đơn.</small><br><button class="wp-btn wp-btn-secondary" type="submit">Lưu vận đơn</button>
            </form><?php endforeach; ?>
            <details class="order-form-disclosure"><summary>+ Tạo vận đơn mới</summary><form method="post" action="/admin/orders/operations/<?= (int)$order->id ?>" class="wp-form-group order-operations-form order-shipment-form">
                <?= \App\Presentation\Http\Csrf::field() ?><input type="hidden" name="operation" value="shipment"><input type="hidden" name="shipment_id" value="0">
                <div class="wp-grid-2"><label>Đơn vị vận chuyển<input class="wp-input" name="carrier"></label><label>Mã vận đơn<input class="wp-input" name="tracking_code"></label><label>Trạng thái<select class="wp-select" name="status"><?php foreach ($shippingLabels as $value=>$label): ?><option value="<?= $e($value) ?>" <?= $value==='pending'?'selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label><label>Phí vận chuyển (₫)<input class="wp-input" name="shipping_fee" type="number" min="0" step="1" value="0"></label><label>Ngày giao dự kiến<input class="wp-input" type="date" name="expected_at"></label><label>Ghi chú<input class="wp-input" name="note"></label></div>
                <label>Liên kết theo dõi<input class="wp-input" type="url" name="tracking_url" placeholder="https://..."></label><small class="wp-text-muted">Phí vận đơn không tự thay đổi tổng đơn.</small><button class="wp-btn wp-btn-secondary" type="submit">Tạo vận đơn</button>
            </form></details>
        </div>
        <div class="wp-card wp-mb-20 order-section-card" id="order-invoice"><div class="wp-card-header"><h2>🧾 Yêu cầu hóa đơn</h2></div><p class="wp-text-muted">Thông tin quản lý thủ công; hệ thống chưa phát hành hóa đơn điện tử.</p>
            <details class="order-form-disclosure"><summary>Cập nhật thông tin hóa đơn</summary><form method="post" class="order-operations-form" action="/admin/orders/operations/<?= (int)$order->id ?>"><?= \App\Presentation\Http\Csrf::field() ?><input type="hidden" name="operation" value="invoice">
            <div class="wp-grid-2"><label>Người mua / đơn vị<input class="wp-input" name="buyer_name" value="<?= $e($invoice['buyer_name'] ?? '') ?>"></label><label>Mã số thuế<input class="wp-input" name="tax_code" value="<?= $e($invoice['tax_code'] ?? '') ?>"></label><label>Địa chỉ<input class="wp-input" name="address" value="<?= $e($invoice['address'] ?? '') ?>"></label><label>Email<input class="wp-input" type="email" name="email" value="<?= $e($invoice['email'] ?? '') ?>"></label><label>Trạng thái<select class="wp-select" name="status"><?php foreach (['not_requested'=>'Chưa yêu cầu','requested'=>'Đã yêu cầu','processing'=>'Đang xử lý','issued'=>'Đã phát hành bên ngoài'] as $v=>$label): ?><option value="<?= $e($v) ?>" <?= ($invoice['status'] ?? 'not_requested')===$v?'selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label><label>Mã tham chiếu hóa đơn<input class="wp-input" name="reference" value="<?= $e($invoice['reference'] ?? '') ?>"></label></div><button class="wp-btn wp-btn-secondary" type="submit">Lưu yêu cầu hóa đơn</button></form></details>
        </div>
        <div class="wp-card wp-mb-20 order-section-card" id="order-adjustments"><div class="wp-card-header"><h2>🧮 Điều chỉnh tổng đơn</h2></div><p class="wp-text-muted">Dùng số âm để giảm; mọi điều chỉnh cần lý do.</p>
            <?php foreach ($operations['adjustments'] as $a): ?><p><?= $e($a['created_at']) ?> · <?= $money($a['amount_vnd']) ?> · <?= $e($a['reason']) ?> · <?= $e($a['actor']) ?></p><?php endforeach; ?>
            <details class="order-form-disclosure"><summary>+ Thêm điều chỉnh</summary><form method="post" class="order-operations-form" action="/admin/orders/operations/<?= (int)$order->id ?>"><?= \App\Presentation\Http\Csrf::field() ?><input type="hidden" name="operation" value="adjustment"><div class="wp-grid-2"><label>Số tiền điều chỉnh (₫)<input class="wp-input" name="amount" type="number" step="1" required></label><label>Lý do<input class="wp-input" name="reason" required></label></div><button class="wp-btn wp-btn-secondary" type="submit">Lưu điều chỉnh</button></form></details>
        </div>
        <div class="wp-card wp-mb-20 order-section-card" id="order-history"><div class="wp-card-header"><h2>🕒 Lịch sử xử lý</h2></div><?php if (!$operations['activity']): ?><p class="wp-text-muted">Chưa có lịch sử mới; đơn cũ được giữ nguyên.</p><?php endif; ?><div class="order-timeline"><?php foreach ($operations['activity'] as $activity): ?><article class="order-timeline-item"><i></i><div><strong><?= $e($activity['event']) ?></strong><p><?= $e($activity['detail']) ?></p><small><?= $e($activity['created_at']) ?> · <?= $e($activity['actor']) ?></small></div></article><?php endforeach; ?></div></div>
    </div>

    <aside class="wp-editor-sidebar order-detail-sidebar">
        <div class="wp-card order-customer-card" id="order-customer"><div class="wp-card-header"><h2>📍 Giao hàng tới</h2></div><strong class="order-customer-name"><?= $e($order->customerName) ?></strong><a href="tel:<?= $e(preg_replace('/[^0-9+]/', '', $order->customerPhone)) ?>"><?= $e($order->customerPhone) ?></a><a href="mailto:<?= $e($order->customerEmail) ?>"><?= $e($order->customerEmail) ?></a><p><?= nl2br($e($order->customerAddress)) ?></p><?php if ($order->note !== ''): ?><div class="order-customer-note"><strong>Ghi chú khách</strong><br><?= nl2br($e($order->note)) ?></div><?php endif; ?></div>
        <div class="wp-card order-status-card">
            <div class="wp-card-header">
                <h3>⚙️ Trạng thái đơn</h3>
            </div>
            <form method="POST" action="/admin/orders/status/<?= $order->id ?>">
                <?php if ($net > 0): ?><p class="wp-text-muted">Cần ghi nhận hoàn toàn bộ <?= $money($net) ?> trước khi hủy đơn.</p><?php endif; ?>
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                <div class="wp-form-group">
                    <label for="order-status">Trạng thái hiện tại</label>
                    <select id="order-status" name="status" class="wp-select">
                        <?php foreach ($allowedOrderStatuses as $st): ?>
                            <option value="<?= $st->value ?>" <?= $order->status === $st ? 'selected' : '' ?>>
                                <?= $st->label() ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="wp-btn wp-btn-primary wp-btn-block">Lưu trạng thái</button>
            </form>
        </div>
    </aside>
</div>
