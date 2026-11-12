<?php
declare(strict_types=1);
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn($value): string => number_format((float) $value, 0, ',', '.') . ' ₫';
$filters = array_filter(['kind'=>$kind, 'q'=>$query, 'from'=>$from, 'to'=>$to], static fn($value): bool => $value !== '');
$url = static fn(array $changes = []): string => '/admin/orders/cashbook' . (($params = array_filter(array_merge($filters, $changes), static fn($value): bool => $value !== '' && $value !== null)) ? '?' . http_build_query($params) : '');
$start = $summary['total'] === 0 ? 0 : (($page - 1) * $pageSize) + 1;
$end = min($page * $pageSize, $summary['total']);
?>
<div class="wp-page-header cashbook-header">
    <div><p class="order-eyebrow">ĐƠN HÀNG <span>·</span> TÀI CHÍNH NỘI BỘ</p><h1 class="wp-page-title">💵 Sổ thu chi</h1><p class="wp-page-subtitle">Theo dõi giao dịch và in phiếu thu, phiếu chi theo từng đơn.</p></div>
    <a href="/admin/orders" class="wp-btn wp-btn-secondary">← Danh sách đơn hàng</a>
</div>

<section class="cashbook-summary" aria-label="Tổng quan thu chi">
    <article><span>Tổng thu</span><strong><?= $money($summary['received']) ?></strong><small>Tiền đã nhận từ khách</small></article>
    <article><span>Tổng chi hoàn</span><strong><?= $money($summary['paid_out']) ?></strong><small>Tiền đã hoàn cho khách</small></article>
    <article class="cashbook-net"><span>Thu ròng</span><strong><?= $money($summary['net']) ?></strong><small><?= number_format($summary['total']) ?> giao dịch phù hợp</small></article>
</section>

<nav class="wp-subsubsub cashbook-tabs" aria-label="Lọc loại chứng từ">
    <a class="<?= $kind === '' ? 'current' : '' ?>" href="<?= $e($url(['kind'=>''])) ?>">Tất cả</a> |
    <a class="<?= $kind === 'payment' ? 'current' : '' ?>" href="<?= $e($url(['kind'=>'payment'])) ?>">Phiếu thu</a> |
    <a class="<?= $kind === 'refund' ? 'current' : '' ?>" href="<?= $e($url(['kind'=>'refund'])) ?>">Phiếu chi</a>
</nav>

<form method="get" action="/admin/orders/cashbook" class="wp-card cashbook-filter">
    <?php if ($kind !== ''): ?><input type="hidden" name="kind" value="<?= $e($kind) ?>"><?php endif; ?>
    <label>Tìm giao dịch<input class="wp-input" type="search" name="q" value="<?= $e($query) ?>" placeholder="Mã phiếu, đơn hàng, khách, phương thức"></label>
    <label>Từ ngày<input class="wp-input" type="date" name="from" value="<?= $e($from) ?>"></label>
    <label>Đến ngày<input class="wp-input" type="date" name="to" value="<?= $e($to) ?>"></label>
    <button class="wp-btn wp-btn-primary" type="submit">Lọc sổ</button>
    <?php if ($filters): ?><a class="wp-btn wp-btn-secondary" href="/admin/orders/cashbook">Xóa lọc</a><?php endif; ?>
</form>

<section class="wp-card cashbook-table-card">
    <?php if (!$rows): ?>
        <div class="wp-empty-state"><p>Chưa có giao dịch phù hợp.</p><a href="/admin/orders">Mở đơn hàng để ghi nhận thu hoặc hoàn tiền</a></div>
    <?php else: ?>
        <div class="cashbook-table-wrap"><table class="wp-table cashbook-table">
            <thead><tr><th>Chứng từ</th><th>Thời điểm</th><th>Đơn / khách hàng</th><th>Phương thức</th><th>Người lập</th><th class="numeric">Số tiền</th><th>Thao tác</th></tr></thead>
            <tbody><?php foreach ($rows as $row): $receipt = $row['kind'] === 'payment'; ?>
                <tr>
                    <td data-label="Chứng từ"><span class="cashbook-kind <?= $receipt ? 'is-receipt' : 'is-expense' ?>"><?= $receipt ? 'PHIẾU THU' : 'PHIẾU CHI' ?></span><strong class="cashbook-code"><?= $e($row['document_code']) ?></strong></td>
                    <td data-label="Thời điểm"><?= $e($row['created_at']) ?></td>
                    <td data-label="Đơn / khách hàng"><a href="/admin/orders/<?= (int) $row['order_id'] ?>"><strong>#<?= $e($row['order_code']) ?></strong></a><small><?= $e($row['customer_name']) ?> · <?= $e($row['customer_phone']) ?></small></td>
                    <td data-label="Phương thức"><?= $e($row['method']) ?><?= $row['reference'] !== '' ? '<small>Mã: ' . $e($row['reference']) . '</small>' : '' ?></td>
                    <td data-label="Người lập"><?= $e($row['actor']) ?></td>
                    <td data-label="Số tiền" class="numeric"><strong class="<?= $receipt ? 'text-positive' : 'text-negative' ?>"><?= $receipt ? '+' : '−' ?><?= $money($row['amount_vnd']) ?></strong></td>
                    <td data-label="Thao tác"><a class="wp-btn wp-btn-secondary wp-btn-sm" href="/admin/orders/payment-document/<?= (int) $row['id'] ?>" target="_blank" rel="noopener">In phiếu ↗</a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>

<nav class="wp-pagination cashbook-pagination" aria-label="Phân trang sổ thu chi"><span class="wp-text-muted">Hiển thị <?= $start ?>–<?= $end ?> trên <?= $summary['total'] ?> giao dịch</span><div><span>Trang <?= $page ?> / <?= $totalPages ?></span><?php if ($page > 1): ?><a class="wp-btn wp-btn-secondary" href="<?= $e($url(['page'=>$page-1])) ?>">← Trước</a><?php endif; ?><?php if ($page < $totalPages): ?><a class="wp-btn wp-btn-secondary" href="<?= $e($url(['page'=>$page+1])) ?>">Tiếp →</a><?php endif; ?></div></nav>
