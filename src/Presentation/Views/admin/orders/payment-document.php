<?php
declare(strict_types=1);
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn($value): string => number_format((float) $value, 0, ',', '.') . ' ₫';
$isReceipt = $document['kind'] === 'payment';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($document['document_title']) ?> <?= $e($document['document_code']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; color: #26314d; background: #f2f4fa; font: 14px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
        .toolbar { max-width: 820px; margin: 0 auto 14px; text-align: right; }
        button { padding: 10px 16px; border: 0; border-radius: 9px; color: #fff; background: #6255dc; font: inherit; font-weight: 700; cursor: pointer; }
        .voucher { max-width: 820px; min-height: 900px; margin: 0 auto; padding: 46px 54px; border: 1px solid #e1e5f0; border-radius: 15px; background: #fff; box-shadow: 0 14px 40px rgba(34,45,82,.08); }
        .brand { display: flex; justify-content: space-between; gap: 20px; padding-bottom: 20px; border-bottom: 2px solid #6559dc; }
        .brand strong { display: block; color: #4f46b7; font-size: 13px; letter-spacing: .08em; }
        .brand small,.muted { color: #78839f; }
        .document-number { text-align: right; color: #78839f; font-size: 12px; }
        .document-number b { display: block; margin-top: 4px; color: #303956; font-size: 15px; }
        h1 { margin: 34px 0 2px; text-align: center; font-size: 30px; letter-spacing: .12em; }
        .subtitle { margin: 0 0 30px; text-align: center; color: #78839f; }
        .info { display: grid; grid-template-columns: 1fr 1fr; gap: 0 28px; }
        .info-row { padding: 11px 0; border-bottom: 1px solid #edf0f6; }
        .info-row span { display: block; color: #8892aa; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .info-row strong { display: block; margin-top: 3px; font-size: 14px; overflow-wrap: anywhere; }
        .amount { margin: 28px 0 12px; padding: 20px; border: 1px solid #e4e2fb; border-radius: 12px; background: #f8f7ff; text-align: center; }
        .amount span { display: block; color: #78839f; font-size: 12px; }
        .amount strong { display: block; margin-top: 5px; color: #5144ce; font-size: 27px; }
        .note { min-height: 55px; margin: 18px 0 44px; padding: 12px 14px; border-radius: 8px; background: #f8f9fc; }
        .note strong { display: block; margin-bottom: 3px; color: #78839f; font-size: 11px; text-transform: uppercase; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; text-align: center; }
        .signatures strong { display: block; }
        .signatures small { color: #78839f; }
        .signature-space { height: 95px; }
        .foot { margin-top: 22px; padding-top: 12px; border-top: 1px solid #edf0f6; color: #8a94aa; font-size: 10px; text-align: center; }
        @media (max-width: 600px) { body { padding: 10px; } .voucher { min-height: auto; padding: 26px 20px; } .brand { flex-direction: column; } .document-number { text-align: left; } .info { grid-template-columns: 1fr; } h1 { margin-top: 25px; font-size: 24px; } .amount strong { font-size: 22px; } }
        @media print { @page { size: A4; margin: 12mm; } body { padding: 0; background: #fff; } .toolbar { display: none; } .voucher { max-width: none; min-height: 0; padding: 16mm 15mm; border: 0; border-radius: 0; box-shadow: none; } }
    </style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">In / Lưu PDF</button></div>
<main class="voucher">
    <header class="brand"><div><strong>CHỨNG TỪ NỘI BỘ</strong><small>Quản lý đơn hàng và dòng tiền</small></div><div class="document-number">Số chứng từ<b><?= $e($document['document_code']) ?></b></div></header>
    <h1><?= $isReceipt ? 'PHIẾU THU' : 'PHIẾU CHI' ?></h1>
    <p class="subtitle"><?= $isReceipt ? 'Xác nhận khoản tiền đã nhận' : 'Xác nhận khoản tiền đã hoàn/chi' ?></p>
    <section class="info">
        <div class="info-row"><span>Ngày lập</span><strong><?= $e($document['created_at']) ?></strong></div>
        <div class="info-row"><span>Đơn hàng</span><strong><?= $e($document['order_code']) ?></strong></div>
        <div class="info-row"><span><?= $isReceipt ? 'Đã nhận từ' : 'Đã chi hoàn cho' ?></span><strong><?= $e($document['customer_name']) ?></strong></div>
        <div class="info-row"><span>Phương thức</span><strong><?= $e($document['method']) ?></strong></div>
        <?php if ($document['customer_phone'] !== ''): ?><div class="info-row"><span>Số điện thoại</span><strong><?= $e($document['customer_phone']) ?></strong></div><?php endif; ?>
        <?php if ($document['reference'] !== ''): ?><div class="info-row"><span>Mã tham chiếu</span><strong><?= $e($document['reference']) ?></strong></div><?php endif; ?>
    </section>
    <div class="amount"><span>Số tiền <?= $isReceipt ? 'đã thu' : 'đã chi' ?></span><strong><?= $money($document['amount_vnd']) ?></strong></div>
    <div class="note"><strong>Nội dung</strong><?= $document['note'] !== '' ? nl2br($e($document['note'])) : ($isReceipt ? 'Thu tiền đơn hàng ' : 'Hoàn tiền đơn hàng ') . $e($document['order_code']) ?></div>
    <section class="signatures"><div><strong><?= $isReceipt ? 'Người nộp tiền' : 'Người nhận tiền' ?></strong><small>(Ký, ghi rõ họ tên)</small><div class="signature-space"></div><strong><?= $e($document['customer_name']) ?></strong></div><div><strong>Người lập phiếu</strong><small>(Ký, ghi rõ họ tên)</small><div class="signature-space"></div><strong><?= $e($document['actor']) ?></strong></div></section>
    <footer class="foot">Phiếu được tạo từ hệ thống quản lý đơn hàng, là chứng từ nội bộ và không thay thế hóa đơn.</footer>
</main>
</body>
</html>
