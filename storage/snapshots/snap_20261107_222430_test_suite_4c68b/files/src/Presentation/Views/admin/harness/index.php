<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\HarnessTask[] $tasks
 * @var array<string, array{name: string, binary: string, available: bool, description: string}> $agents
 * @var array<int, array{snapshot_id: string, label: string, created_at: string, file_count: int}> $snapshots
 * @var array{passed: bool, gates: array<string, array{passed: bool, title: string, details: string}>, summary: string} $gateReport
 */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">🤖 Tuzygent Autonomous Code Harness (Self-Evolving Loop)</h1>
        <p class="wp-page-subtitle">
            Vòng lặp tự phát triển mã nguồn theo kiến trúc Hexagonal:
            <code>Snapshot Backup</code> &rarr; <code>Agent Code Proposal</code> &rarr; <code>3-Stage Verification Gate</code> &rarr; <code>Self-Healing Retry / Auto-Rollback</code>.
        </p>
    </div>
    <div class="wp-header-actions">
        <form method="POST" action="/admin/harness/verify" style="display:inline;">
            <button type="submit" class="wp-btn wp-btn-secondary">🧪 Chạy Verification Gate ngay</button>
        </form>
        <form method="POST" action="/admin/harness/snapshot" style="display:inline;">
            <input type="hidden" name="label" value="dashboard">
            <button type="submit" class="wp-btn wp-btn-primary">📸 Tạo Snapshot Mã nguồn</button>
        </form>
    </div>
</div>

<!-- 1. 3-STAGE VERIFICATION GATE STATUS -->
<div class="wp-stats-grid">
    <?php foreach ($gateReport['gates'] as $gate): ?>
        <div class="wp-stat-card" style="border-left-color: <?= $gate['passed'] ? '#008a20' : '#d63638' ?>;">
            <div class="wp-stat-icon"><?= $gate['passed'] ? '✅' : '❌' ?></div>
            <div>
                <div class="wp-stat-value" style="font-size:15px;"><?= htmlspecialchars($gate['title']) ?></div>
                <div class="wp-stat-label"><?= htmlspecialchars(mb_strimwidth($gate['details'], 0, 110, '...')) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="wp-editor-layout">
    <!-- Left Column: Create Task + Backlog & Evolution History -->
    <div class="wp-editor-main">
        <!-- Create New Autonomous Evolution Task -->
        <div class="wp-card wp-mb-20">
            <div class="wp-card-header">
                <h3>🚀 Giao nhiệm vụ Tự phát triển Source Code mới (Tuzygent Task Spec)</h3>
                <span class="wp-tag-chip">CLI tương đương: <code>./bin/tuzygent run</code></span>
            </div>
            <form method="POST" action="/admin/harness/create">
                <div class="wp-grid-2">
                    <div class="wp-form-group">
                        <label>Loại tác vụ tự phát triển *</label>
                        <select name="action_type" class="wp-select" style="width:100%;">
                            <option value="scaffold_module">🏗️ Sinh phân hệ Hexagonal mới (Entity + Port + SQLite Adapter + Service)</option>
                            <option value="agent_prompt">🤖 Giao đặc tả tùy chỉnh cho AI Agent CLI (.harness/ Prompt Loop)</option>
                            <option value="self_audit">🔍 Kiểm toán &amp; Bảo chứng Kiến trúc Hexagonal (Self-Audit)</option>
                        </select>
                    </div>
                    <div class="wp-form-group">
                        <label>Tên Thực thể / Module (PascalCase, VD: <code>Banner</code>, <code>Brand</code>, <code>Warranty</code>)</label>
                        <input type="text" name="target_name" placeholder="VD: Brand hoặc Coupon" class="wp-input">
                    </div>
                </div>

                <div class="wp-grid-2">
                    <div class="wp-form-group">
                        <label>Tiêu đề nhiệm vụ</label>
                        <input type="text" name="title" placeholder="VD: Phát triển phân hệ Quản lý Thương hiệu (Brand)" class="wp-input">
                    </div>
                    <div class="wp-form-group">
                        <label>Chọn AI Agent Runner &amp; Số lần thử Self-Healing</label>
                        <div style="display:flex; gap:8px;">
                            <select name="agent_backend" class="wp-select" style="flex:1;">
                                <?php foreach ($agents as $aKey => $aInfo): ?>
                                    <option value="<?= htmlspecialchars($aKey) ?>">
                                        <?= $aInfo['available'] ? '🟢' : '⚪' ?> <?= htmlspecialchars($aInfo['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="max_attempts" value="3" min="1" max="5" title="Số lần tự sửa lỗi tối đa (Max Retries)" class="wp-input" style="width:80px;">
                        </div>
                    </div>
                </div>

                <div class="wp-form-group">
                    <label>Đặc tả chi tiết (Specification / Prompt cho Agent)</label>
                    <textarea name="specification" rows="3" placeholder="Mô tả các trường dữ liệu, quy tắc nghiệp vụ Domain và yêu cầu kiểm thử..." class="wp-textarea"></textarea>
                </div>

                <div style="display:flex; gap:12px; align-items:center;">
                    <button type="submit" name="run_immediately" value="1" class="wp-btn wp-btn-primary">
                        ⚡ Tạo &amp; Chạy Vòng Lặp Tự Phát Triển Ngay
                    </button>
                    <button type="submit" class="wp-btn wp-btn-secondary">
                        + Lưu vào Backlog chờ chạy sau
                    </button>
                </div>
            </form>
        </div>

        <!-- Backlog & Evolution History -->
        <div class="wp-card">
            <div class="wp-card-header">
                <h3>📜 Backlog &amp; Lịch sử Tiến hóa Source Code (Evolution Trajectory)</h3>
            </div>
            <?php if (empty($tasks)): ?>
                <p class="wp-text-muted">Chưa có nhiệm vụ nào trong Backlog.</p>
            <?php else: ?>
                <table class="wp-table">
                    <thead>
                        <tr>
                            <th style="width:90px;">Mã Task</th>
                            <th>Nhiệm vụ &amp; Các tệp đã sinh/sửa</th>
                            <th>Agent</th>
                            <th>Trạng thái</th>
                            <th style="text-align:right; width:210px;">Điều khiển Loop</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $t): ?>
                            <tr>
                                <td>
                                    <strong><code><?= htmlspecialchars($t->taskCode) ?></code></strong><br>
                                    <small class="wp-text-muted">Lần thử: <?= $t->attempts ?>/<?= $t->maxAttempts ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($t->title) ?></strong>
                                    <?php if ($t->targetName !== ''): ?>
                                        <span class="wp-tag-chip">Target: <?= htmlspecialchars($t->targetName) ?></span>
                                    <?php endif; ?>
                                    <p class="wp-text-muted" style="font-size:12.5px; margin-top:4px;"><?= htmlspecialchars($t->specification) ?></p>

                                    <?php if (!empty($t->filesChanged)): ?>
                                        <div style="margin-top:6px;">
                                            <small><strong>📄 Tệp đã tác động:</strong></small>
                                            <?php foreach ($t->filesChanged as $f): ?>
                                                <code style="display:inline-block; background:#f0f6fc; padding:1px 6px; border-radius:4px; font-size:11.5px; margin:2px;"><?= htmlspecialchars($f) ?></code>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($t->executionLog !== '' || $t->verificationOutput !== ''): ?>
                                        <details style="margin-top:8px; font-size:12px;">
                                            <summary style="cursor:pointer; color:#2271b1; font-weight:600;">🔎 Xem nhật ký tiến hóa &amp; kết quả Verification Gate</summary>
                                            <pre style="background:#1d2327; color:#f0f0f1; padding:10px; border-radius:6px; margin-top:6px; overflow-x:auto; white-space:pre-wrap;"><?= htmlspecialchars($t->executionLog . "\n\n=== VERIFICATION GATE ===\n" . $t->verificationOutput) ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($t->agentBackend) ?></code></td>
                                <td>
                                    <span class="wp-status-badge <?= $t->status->badgeClass() ?>"><?= $t->status->label() ?></span>
                                    <?php if ($t->snapshotId !== ''): ?>
                                        <div style="margin-top:4px;"><small class="wp-text-muted">Snap: <code><?= htmlspecialchars(substr($t->snapshotId, 0, 18)) ?></code></small></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <form method="POST" action="/admin/harness/run/<?= $t->id ?>" style="display:inline;">
                                        <button type="submit" class="wp-btn wp-btn-primary wp-btn-sm">
                                            🚀 <?= $t->attempts > 0 ? 'Chạy lại Loop' : 'Chạy Loop' ?>
                                        </button>
                                    </form>
                                    <?php if ($t->snapshotId !== ''): ?>
                                        <form method="POST" action="/admin/harness/rollback" style="display:inline;" onsubmit="return confirm('Khôi phục source code về Snapshot trước khi chạy Task này?');">
                                            <input type="hidden" name="snapshot_id" value="<?= htmlspecialchars($t->snapshotId) ?>">
                                            <button type="submit" class="wp-btn wp-btn-secondary wp-btn-sm" title="Rollback về Snapshot">⏪</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="/admin/harness/delete/<?= $t->id ?>" style="display:inline;" onsubmit="return confirm('Xóa Task này?');">
                                        <button type="submit" class="wp-link-danger" style="margin-left:6px;">✖</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Sidebar: Multi-Agent CLI Status & Snapshot Time-Machine -->
    <div class="wp-editor-sidebar">
        <!-- Discovered AI Agent CLIs -->
        <div class="wp-card wp-mb-20">
            <div class="wp-card-header">
                <h3>🤖 AI Agent Runners trên máy</h3>
            </div>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($agents as $key => $ag): ?>
                    <div style="border:1px solid #e2e8f0; border-radius:6px; padding:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong><?= htmlspecialchars($ag['name']) ?></strong>
                            <?php if ($ag['available']): ?>
                                <span class="wp-status-badge badge-success">Sẵn sàng</span>
                            <?php else: ?>
                                <span class="wp-status-badge badge-warning">Chưa cài</span>
                            <?php endif; ?>
                        </div>
                        <small class="wp-text-muted" style="display:block; margin-top:4px;"><?= htmlspecialchars($ag['description']) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Snapshot Time-Machine & Rollback -->
        <div class="wp-card">
            <div class="wp-card-header">
                <h3>📸 Snapshots &amp; Auto-Rollback</h3>
            </div>
            <?php if (empty($snapshots)): ?>
                <p class="wp-text-muted">Chưa có bản chụp Snapshot nào.</p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach (array_slice($snapshots, 0, 8) as $snap): ?>
                        <div style="border:1px solid #e2e8f0; border-radius:6px; padding:10px;">
                            <strong style="font-size:12.5px;"><code><?= htmlspecialchars($snap['snapshot_id']) ?></code></strong>
                            <div class="wp-text-muted" style="font-size:12px; margin:3px 0;">
                                🕒 <?= htmlspecialchars($snap['created_at']) ?> • <?= $snap['file_count'] ?> tệp PHP
                            </div>
                            <form method="POST" action="/admin/harness/rollback" onsubmit="return confirm('Khôi phục toàn bộ source code về Snapshot này?');">
                                <input type="hidden" name="snapshot_id" value="<?= htmlspecialchars($snap['snapshot_id']) ?>">
                                <button type="submit" class="wp-btn wp-btn-secondary wp-btn-sm wp-btn-block" style="margin-top:4px;">
                                    ⏪ Rollback về bản này
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
