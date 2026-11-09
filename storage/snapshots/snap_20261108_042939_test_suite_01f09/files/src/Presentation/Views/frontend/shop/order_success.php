<?php
declare(strict_types=1);
/** @var \App\Domain\Entity\Order $order */
?>
<div class="fe-empty-box" style="max-width:650px; margin: 40px auto;">
    <div style="font-size:48px; margin-bottom:12px;">🎉</div>
    <h2>Đặt hàng thành công!</h2>
    <p>Cảm ơn <strong><?= htmlspecialchars($order->customerName) ?></strong>. Đơn hàng của bạn đã được hệ thống ghi nhận.</p>
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin:20px 0; text-align:left;">
        <p><strong>Mã đơn hàng:</strong> <code>#<?= htmlspecialchars($order->orderCode) ?></code></p>
        <p><strong>Tổng thanh toán:</strong> <strong style="color:#dc2626;"><?= $order->formattedTotal() ?></strong></p>
        <p><strong>Địa chỉ nhận hàng:</strong> <?= htmlspecialchars($order->customerAddress) ?></p>
    </div>
    <a href="/shop" class="fe-btn fe-btn-primary">Tiếp tục mua sắm</a>
    <a href="/" class="fe-btn fe-btn-secondary">Về trang chủ</a>
</div>
