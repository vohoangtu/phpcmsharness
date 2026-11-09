<?php
declare(strict_types=1);
/**
 * @var array{items: array<int, array{product: \App\Domain\Entity\Product, quantity: int, subtotal: float}>, total: float, count: int} $cartData
 */
?>
<div class="fe-page-head">
    <h1>🛒 Giỏ hàng &amp; Thanh toán</h1>
    <p>Kiểm tra danh sách sản phẩm và điền thông tin nhận hàng</p>
</div>

<?php if (empty($cartData['items'])): ?>
    <div class="fe-empty-box">
        <p>Giỏ hàng của bạn hiện đang trống.</p>
        <a href="/shop" class="fe-btn fe-btn-primary" style="margin-top:12px;">🛍️ Tiếp tục mua sắm</a>
    </div>
<?php else: ?>
    <div class="fe-cart-layout">
        <!-- Cart Items Table -->
        <div class="fe-cart-items">
            <form method="POST" action="/cart/update">
            <?= \App\Presentation\Http\Csrf::field() ?>
                <table class="fe-cart-table">
                    <thead>
                        <tr>
                            <th>Sản phẩm</th>
                            <th>Đơn giá</th>
                            <th style="width:100px;">Số lượng</th>
                            <th>Thành tiền</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cartData['items'] as $row): ?>
                            <?php $prod = $row['product']; ?>
                            <tr>
                                <td>
                                    <a href="/product/<?= urlencode($prod->slug) ?>"><strong><?= htmlspecialchars($prod->name) ?></strong></a>
                                </td>
                                <td><?= $prod->formattedEffectivePrice() ?></td>
                                <td>
                                    <input
                                        type="number"
                                        name="quantities[<?= $prod->id ?>]"
                                        value="<?= (int) $row['quantity'] ?>"
                                        min="0"
                                        max="<?= max(0, $prod->stockQuantity) ?>"
                                        class="fe-input"
                                    >
                                </td>
                                <td><strong><?= number_format((float) $row['subtotal'], 0, ',', '.') ?> ₫</strong></td>
                                <td>
                                    <button type="submit" formaction="/cart/remove/<?= $prod->id ?>" class="fe-remove-btn">✖</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top:14px; display:flex; justify-content:space-between; align-items:center;">
                    <a href="/shop" class="fe-link-more">← Chọn thêm sản phẩm</a>
                    <button type="submit" class="fe-btn fe-btn-secondary">Cập nhật số lượng</button>
                </div>
            </form>
        </div>

        <!-- Checkout Box -->
        <div class="fe-checkout-box">
            <h3>🧾 Thông tin Đặt hàng</h3>
            <div class="fe-checkout-total">
                <span>Tổng thanh toán:</span>
                <strong><?= number_format((float) $cartData['total'], 0, ',', '.') ?> ₫</strong>
            </div>
            <form method="POST" action="/checkout">
            <?= \App\Presentation\Http\Csrf::field() ?>
                <div class="fe-form-group">
                    <label>Họ và tên người nhận *</label>
                    <input type="text" name="customer_name" required placeholder="Nguyễn Văn A" class="fe-input">
                </div>
                <div class="fe-form-group">
                    <label>Số điện thoại liên hệ *</label>
                    <input type="text" name="customer_phone" required placeholder="0901 234 567" class="fe-input">
                </div>
                <div class="fe-form-group">
                    <label>Email nhận thông báo đơn hàng *</label>
                    <input type="email" name="customer_email" required placeholder="email@example.com" class="fe-input">
                </div>
                <div class="fe-form-group">
                    <label>Địa chỉ giao hàng chi tiết *</label>
                    <textarea name="customer_address" rows="2" required placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành..." class="fe-textarea"></textarea>
                </div>
                <div class="fe-form-group">
                    <label>Ghi chú đơn hàng (Tùy chọn)</label>
                    <textarea name="note" rows="2" placeholder="Giao giờ hành chính..." class="fe-textarea"></textarea>
                </div>
                <button type="submit" class="fe-btn fe-btn-primary fe-btn-block fe-btn-lg">
                    ✅ Xác nhận Đặt hàng
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>
