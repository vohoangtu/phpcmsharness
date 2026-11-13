<?php
/** @var array{measurement_id:string,property_id:string} $ga4Settings */
/** @var bool $ga4CredentialConfigured */
/** @var array<string,mixed>|null $ga4Report */
/** @var string|null $ga4ApiError */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$summary = $ga4Report['summary']['rows'][0] ?? [];
$number = static fn($value) => number_format((float)($value ?? 0), 0, ',', '.');
?>
<div class="wp-page-header"><div><h1>📈 Analytics</h1><p>Đo lường website và đọc báo cáo GA4 qua Google Analytics Data API.</p></div></div>

<section class="wp-card" style="margin-bottom:24px">
    <div class="wp-card-header"><h2>🔗 Kết nối Google Analytics 4</h2></div>
    <form method="post" action="/admin/analytics/save" enctype="multipart/form-data" class="ga4-settings-grid" style="padding:20px">
        <?= \App\Presentation\Http\Csrf::field() ?>
        <label><span>Measurement ID</span><input class="wp-input" name="measurement_id" value="<?= $e($ga4Settings['measurement_id']) ?>" placeholder="G-XXXXXXXXXX"><small>Gắn thẻ GA4 vào website để ghi nhận lượt xem và sự kiện.</small></label>
        <label><span>GA4 Property ID</span><input class="wp-input" name="property_id" value="<?= $e($ga4Settings['property_id']) ?>" placeholder="123456789"><small>Property ID dạng số, không phải Measurement ID.</small></label>
        <label class="ga4-full-row"><span>Service account JSON key</span><input class="wp-input" type="file" name="service_account_file" accept=".json,application/json"><small>Tải lên key JSON từ Google Cloud (tối đa 64 KiB). Key được mã hóa và lưu ngoài thư mục public; không ghi vào SQLite hoặc hiển thị lại.</small></label>
        <details class="ga4-full-row"><summary>Hoặc dán nội dung JSON</summary><textarea class="wp-textarea" name="service_account_json" rows="7" autocomplete="off" placeholder="Dán JSON service account nếu không tải file lên."></textarea></details>
        <div class="ga4-full-row ga4-setup-help"><strong>Cách lấy thông tin GA4</strong><ol><li>Trong Google Cloud Console, bật <em>Google Analytics Data API</em> và tạo service account, sau đó tải JSON key.</li><li>Trong GA4 Admin → Property Access Management, cấp email service account quyền <strong>Viewer</strong>.</li><li>Lấy <strong>Measurement ID</strong> trong Data Streams → Web; lấy <strong>Property ID</strong> trong Property details.</li></ol><p>Measurement ID dùng để gửi dữ liệu từ website. Property ID và key dùng để đọc báo cáo bằng API. Có thể lưu ID trước rồi tải key sau.</p><p>Trạng thái key: <strong><?= $ga4CredentialConfigured ? 'Đã cấu hình' : 'Chưa cấu hình' ?></strong>.</p></div>
        <?php if ($ga4CredentialConfigured): ?><label class="ga4-full-row"><span><input type="checkbox" name="remove_service_account" value="1"> Xóa key service account đã lưu</span></label><?php endif; ?>
        <div style="grid-column:1/-1"><button class="wp-btn wp-btn-primary" type="submit">Lưu cấu hình GA4</button></div>
    </form>
</section>

<?php if ($ga4ApiError): ?><div class="wp-notice wp-notice-error" role="alert"><strong>Không lấy được báo cáo GA4:</strong> <?= $e($ga4ApiError) ?><br>Kiểm tra API đã bật, property ID và quyền Viewer của service account.</div>
<?php elseif (!$ga4Report): ?><section class="wp-card"><div class="wp-card-body"><h2>Chưa có báo cáo</h2><p>Điền Measurement ID, Property ID và service account để bật theo dõi và đọc dữ liệu Analytics.</p></div></section>
<?php else: ?>
<p class="wp-muted">Khoảng thời gian: <?= $e($ga4Report['start']) ?> đến <?= $e($ga4Report['end']) ?> · số liệu đến hết ngày hôm qua.</p>
<div class="wp-stats-grid" style="margin:16px 0 24px">
    <?php foreach ([['Người dùng','activeUsers'],['Phiên','sessions'],['Lượt xem','screenPageViews'],['Đơn mua','ecommercePurchases'],['Doanh thu','totalRevenue'],['Thêm vào giỏ','addToCarts'],['Bắt đầu thanh toán','checkouts']] as [$label,$key]): ?>
    <article class="wp-stat-card"><span><?= $e($label) ?></span><strong><?= $number($summary[$key] ?? 0) ?><?= $key === 'totalRevenue' ? ' ₫' : '' ?></strong></article>
    <?php endforeach; ?>
</div>
<div class="wp-dashboard-grid">
    <?php foreach ([['Lượt truy cập theo ngày',$ga4Report['trend'],['date','screenPageViews','sessions','activeUsers']],['Trang được xem nhiều',$ga4Report['pages'],['pagePath','screenPageViews','activeUsers']],['Nguồn truy cập',$ga4Report['sources'],['sessionSourceMedium','sessions','activeUsers']]] as [$title,$table,$columns]): ?>
    <section class="wp-card"><div class="wp-card-header"><h2><?= $e($title) ?></h2></div><div class="wp-table-wrap"><table class="wp-table"><thead><tr><?php foreach ($columns as $column): ?><th><?= $e($column) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($table['rows'] as $row): ?><tr><?php foreach ($columns as $column): ?><td><?= $e($row[$column] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if (!$table['rows']): ?><tr><td colspan="<?= count($columns) ?>">Chưa có dữ liệu trong khoảng thời gian này.</td></tr><?php endif; ?></tbody></table></div></section>
    <?php endforeach; ?>
</div>
<?php $aiContext = ['period'=>['start'=>$ga4Report['start']??'','end'=>$ga4Report['end']??''],'summary'=>$ga4Report['summary']['rows'][0]??[],'daily_trend'=>array_slice($ga4Report['trend']['rows']??[],-14),'top_pages'=>array_slice($ga4Report['pages']['rows']??[],0,10),'traffic_sources'=>array_slice($ga4Report['sources']['rows']??[],0,8)]; ?>
<section class="wp-card ai-analytics-card"><div class="wp-card-header"><div><h2>✨ AI phân tích Analytics</h2><p class="wp-text-muted">Tóm tắt xu hướng và gợi ý cơ hội từ dữ liệu GA4 đang hiển thị. Bản tóm tắt số liệu sẽ được gửi tới provider AI trong Harness.</p></div><button class="wp-btn wp-btn-primary" type="button" data-ai-analytics>Phân tích dữ liệu GA4</button></div><input type="hidden" data-ai-analytics-context value="<?= $e(json_encode($aiContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>"><div data-ai-analytics-result class="ai-analytics-result" aria-live="polite" hidden></div></section>
<?php endif; ?>
