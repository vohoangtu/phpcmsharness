<?php
declare(strict_types=1);
/** @var \App\Domain\Entity\WorkerJob[] $jobs */
?>
<div class="wp-page-header"><div><h1 class="wp-page-title">⚙️ Workers</h1><p class="wp-page-subtitle">Hàng đợi bền vững SQLite cho Harness và các yêu cầu AI. Worker xử lý mỗi lần một job; trạng thái đang chạy được phục hồi sau khi tiến trình dừng bất thường.</p></div></div>
<p class="wp-text-muted">Worker: <?= !empty($workerStatus['active']) ? 'Đang hoạt động' : 'Không hoạt động' ?><?= !empty($workerStatus['log_available']) ? ' · Log vận hành: storage/workers/worker.log' : '' ?></p>
<div class="wp-card"><table class="wp-table"><thead><tr><th>ID</th><th>Loại</th><th>Payload</th><th>Trạng thái</th><th>Lần thử</th><th>Kết quả / Lỗi</th><th>Thao tác</th></tr></thead><tbody>
<?php if (!$jobs): ?><tr><td colspan="7">Chưa có job.</td></tr><?php endif; ?>
<?php foreach ($jobs as $job): $jobResult = json_decode($job->result ?? '', true); $legacyFailure = $job->status === 'completed' && in_array($jobResult['status'] ?? '', ['failed', 'rolled_back'], true); $displayPayload = $job->type === 'ai_assistant' ? ['action' => $job->payload['action'] ?? 'ai', 'owner_id' => $job->payload['owner_id'] ?? null] : $job->payload; ?><tr>
<td>#<?= $job->id ?></td><td><?= htmlspecialchars($job->type === 'ai_assistant' ? 'AI · ' . (string)($job->payload['action'] ?? 'assistant') : $job->type) ?></td><td><code><?= htmlspecialchars(json_encode($displayPayload, JSON_UNESCAPED_UNICODE) ?: '{}') ?></code></td><td><span class="wp-status-badge <?= $job->status === 'failed' || $legacyFailure ? 'badge-danger' : ($job->status === 'completed' ? 'badge-success' : '') ?>"><?= htmlspecialchars($legacyFailure ? 'task ' . $jobResult['status'] : $job->status) ?></span></td><td><?= $job->attempts ?></td>
<td><?php if ($job->error): ?><details><summary>Lỗi</summary><pre><?= htmlspecialchars($job->error) ?></pre></details><?php elseif ($job->result): ?><details><summary>Xem kết quả</summary><pre><?= htmlspecialchars($job->result) ?></pre></details><?php else: ?>—<?php endif; ?></td><td>
<?php if ($job->status === 'failed' || $legacyFailure): ?><form method="post" action="/admin/workers/retry/<?= $job->id ?>" style="display:inline"><?= \App\Presentation\Http\Csrf::field() ?><button class="wp-btn wp-btn-primary wp-btn-sm">Retry</button></form><?php endif; ?>
<?php if ($job->status === 'queued'): ?><form method="post" action="/admin/workers/cancel/<?= $job->id ?>" style="display:inline"><?= \App\Presentation\Http\Csrf::field() ?><button class="wp-btn wp-btn-danger-outline wp-btn-sm">Hủy</button></form><?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table></div>
<p class="wp-text-muted">Tắt module Workers sẽ từ chối job mới. Worker hiện tại xử lý hết hàng đợi rồi dừng.</p>
