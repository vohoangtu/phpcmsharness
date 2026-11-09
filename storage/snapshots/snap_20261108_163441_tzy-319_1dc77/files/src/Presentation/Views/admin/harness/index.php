<?php
declare(strict_types=1);
/**
 * @var \App\Domain\Entity\HarnessTask[] $tasks
 * @var array<int, array{id: int, status: string, status_label: string, badge_class: string, attempts: int, max_attempts: int, summary: string, files_changed: string[], snapshot_id: string}> $taskProgress
 * @var array<string, array{name: string, binary: string, available: bool, description: string}> $agents
 * @var array<int, array{snapshot_id: string, label: string, created_at: string, file_count: int}> $snapshots
 * @var array{passed: bool, gates: array<string, array{passed: bool, title: string, details: string}>, summary: string} $gateReport
 * @var array<string, mixed> $continuousState
 */
$cEnabled = (bool) ($continuousState['enabled'] ?? false);
$cDaemonAlive = (bool) ($continuousState['daemon_alive'] ?? false);
$cInterval = (int) ($continuousState['interval_seconds'] ?? 5);
$cAutoDiscover = (bool) ($continuousState['auto_discover'] ?? true);
$cAutoHeal = (bool) ($continuousState['auto_heal_on_drift'] ?? true);
$cEvents = is_array($continuousState['recent_events'] ?? null) ? $continuousState['recent_events'] : [];
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
        <form method="POST" action="/admin/harness/continuous/tick" style="display:inline;">
            <?= \App\Presentation\Http\Csrf::field() ?>
            <button type="submit" class="wp-btn wp-btn-secondary">⚡ Chạy 1 Chu Kỳ Continuous (Tick)</button>
        </form>
        <form method="POST" action="/admin/harness/verify" style="display:inline;">
            <?= \App\Presentation\Http\Csrf::field() ?>
            <button type="submit" class="wp-btn wp-btn-secondary">🧪 Chạy Verification Gate ngay</button>
        </form>
        <form method="POST" action="/admin/harness/snapshot" style="display:inline;">
            <?= \App\Presentation\Http\Csrf::field() ?>
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

<!-- 1.5 CONTINUOUS AUTONOMOUS MODE CONTROL CENTER -->
<div class="wp-card wp-mb-20" style="border-left: 4px solid <?= $cEnabled ? '#008a20' : '#2271b1' ?>;">
    <div class="wp-card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <h2 style="margin:0;">🔄 Chế độ Tự Hành Liên Tục — Continuous Autonomous Mode (24/7 Loop)</h2>
            <?php if ($cEnabled): ?>
                <span id="continuous-badge" class="wp-status-badge badge-success">🟢 RUNNING (CONTINUOUS)</span>
            <?php else: ?>
                <span id="continuous-badge" class="wp-status-badge badge-secondary">⚪ STOPPED</span>
            <?php endif; ?>
            <?php if ($cDaemonAlive): ?>
                <span class="wp-tag-chip">Daemon PID: <code><?= (int) $continuousState['pid'] ?></code></span>
            <?php endif; ?>
        </div>
        <span class="wp-tag-chip">CLI: <code>./bin/tuzygent continuous</code></span>
    </div>

    <div class="wp-grid-2" style="align-items:start; gap:20px;">
        <div>
            <form method="POST" action="/admin/harness/continuous/toggle">
            <?= \App\Presentation\Http\Csrf::field() ?>
                <div class="wp-grid-2">
                    <div class="wp-form-group">
                        <label for="continuous-interval">Chu kỳ lặp (Interval Seconds)</label>
                        <select name="interval_seconds" id="continuous-interval" class="wp-select" style="width:100%;">
                            <?php foreach ([2 => '2 giây (Siêu tốc)', 5 => '5 giây (Khuyên dùng)', 10 => '10 giây (Tiêu chuẩn)', 30 => '30 giây (Tiết kiệm)', 60 => '60 giây (Tuần tra)'] as $sec => $lbl): ?>
                                <option value="<?= $sec ?>" <?= $cInterval === $sec ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wp-form-group">
                        <strong>Thống kê Vòng lặp Liên tục</strong>
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; font-size:13px;">
                            Chu kỳ: <strong id="stat-cycles"><?= (int) ($continuousState['cycles_completed'] ?? 0) ?></strong> •
                            Tasks: <strong id="stat-tasks"><?= (int) ($continuousState['tasks_executed'] ?? 0) ?></strong>
                            (<span style="color:#008a20;">Pass: <strong id="stat-passed"><?= (int) ($continuousState['tasks_succeeded'] ?? 0) ?></strong></span> /
                            <span style="color:#d63638;">Rollback: <strong id="stat-rollback"><?= (int) ($continuousState['tasks_rolled_back'] ?? 0) ?></strong></span>)
                        </div>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:14px; font-size:13.5px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="auto_discover" id="continuous-autodiscover" value="1" <?= $cAutoDiscover ? 'checked' : '' ?>>
                        <span>🔭 <strong>Auto-Discover / Brainstorm</strong> (mặc định bật): Quét source PHP để tạo backlog từ TODO/FIXME, service chưa có dấu vết trong test và tệp lớn bất thường; coding CLI khả dụng sẽ triển khai từng đề tài và brainstorm tiếp khi hết tín hiệu.</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="auto_heal_on_drift" value="1" <?= $cAutoHeal ? 'checked' : '' ?>>
                        <span>🚑 <strong>Continuous Drift Auto-Heal</strong>: Tự động chạy 3-Stage Verification Gate mỗi chu kỳ và Rollback ngay nếu phát hiện lỗi cú pháp/kiến trúc.</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="spawn_daemon" value="1" <?= $cDaemonAlive ? 'checked' : '' ?>>
                        <span>⚙️ <strong>Chạy ngầm Background CLI Daemon</strong> (<code>bin/tuzygent continuous</code> chạy độc lập ngay cả khi đóng trình duyệt).</span>
                    </label>
                </div>

                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <?php if (!$cEnabled): ?>
                        <button type="submit" name="action" value="start" class="wp-btn wp-btn-primary">
                            ▶️ Bật Continuous Mode
                        </button>
                    <?php else: ?>
                        <button type="submit" name="action" value="start" class="wp-btn wp-btn-secondary">
                            🔄 Cập nhật Cấu hình &amp; Chạy Chu kỳ
                        </button>
                        <button type="submit" name="action" value="stop" class="wp-btn wp-btn-danger" style="background:#d63638; color:#fff; border-color:#d63638;">
                            ⏹️ Dừng Continuous Mode
                        </button>
                    <?php endif; ?>
                    <button type="button" id="btn-live-tick" class="wp-btn wp-btn-secondary">
                        ⚡ Kích hoạt 1 Tick ngay (AJAX)
                    </button>
                </div>
            </form>
        </div>

        <div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <strong style="font-size:13px;">📡 Nhật ký Continuous Telemetry &amp; Heartbeat</strong>
                <small class="wp-text-muted">Heartbeat: <code id="stat-heartbeat"><?= htmlspecialchars((string) ($continuousState['last_heartbeat'] ?: 'Chưa có')) ?></code></small>
            </div>
            <div id="continuous-log-box" style="background:#1d2327; color:#50fa7b; font-family:monospace; font-size:12px; padding:10px 12px; border-radius:6px; height:150px; overflow-y:auto; line-height:1.5;">
                <?php if (empty($cEvents)): ?>
                    <div style="color:#94a3b8;">[Idle] Chế độ Continuous đang chờ kích hoạt. Nhấn "Bật Continuous Mode" hoặc chạy `./bin/tuzygent continuous`...</div>
                <?php else: ?>
                    <?php foreach ($cEvents as $ev): ?>
                        <div><?= htmlspecialchars((string) $ev) ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="wp-editor-layout">
    <!-- Left Column: Create Task + Backlog & Evolution History -->
    <div class="wp-editor-main">
        <!-- Create New Autonomous Evolution Task -->
        <div class="wp-card wp-mb-20">
            <div class="wp-card-header">
                <h2>🚀 Giao nhiệm vụ Tự phát triển Source Code mới (Tuzygent Task Spec)</h2>
                <span class="wp-tag-chip">CLI tương đương: <code>./bin/tuzygent run</code></span>
            </div>
            <form method="POST" action="/admin/harness/create">
            <?= \App\Presentation\Http\Csrf::field() ?>
                <div class="wp-grid-2">
                    <div class="wp-form-group">
                        <label for="task-action-type">Loại tác vụ tự phát triển *</label>
                        <select name="action_type" id="task-action-type" class="wp-select" style="width:100%;">
                            <option value="scaffold_module">🏗️ Sinh phân hệ Hexagonal mới (Entity + Port + SQLite Adapter + Service)</option>
                            <option value="agent_prompt">🤖 Giao đặc tả tùy chỉnh cho AI Agent CLI (.harness/ Prompt Loop)</option>
                            <option value="research_and_develop">🔭 Tự nghiên cứu đề tài &amp; triển khai tính năng bằng AI Agent</option>
                            <option value="self_audit">🔍 Kiểm toán &amp; Bảo chứng Kiến trúc Hexagonal (Self-Audit)</option>
                        </select>
                    </div>
                    <div class="wp-form-group">
                        <label for="task-target-name">Tên Thực thể / Module (PascalCase, VD: <code>Banner</code>, <code>Brand</code>, <code>Warranty</code>)</label>
                        <input type="text" name="target_name" id="task-target-name" placeholder="VD: Brand hoặc Coupon" class="wp-input">
                    </div>
                </div>

                <div class="wp-grid-2">
                    <div class="wp-form-group">
                        <label for="task-title">Tiêu đề nhiệm vụ</label>
                        <input type="text" name="title" id="task-title" placeholder="VD: Phát triển phân hệ Quản lý Thương hiệu (Brand)" class="wp-input">
                    </div>
                    <div class="wp-form-group">
                        <label for="task-agent-backend">Chọn AI Agent Runner</label>
                        <div style="display:flex; gap:8px;">
                            <select name="agent_backend" id="task-agent-backend" class="wp-select" style="flex:1;">
                                <?php foreach ($agents as $aKey => $aInfo): ?>
                                    <option value="<?= htmlspecialchars($aKey) ?>">
                                        <?= $aInfo['available'] ? '🟢' : '⚪' ?> <?= htmlspecialchars($aInfo['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="display:flex; flex-direction:column; gap:2px;">
                                <label for="task-max-attempts" style="font-size:11px;">Số lần thử</label>
                                <input type="number" name="max_attempts" id="task-max-attempts" value="3" min="1" max="5" title="Số lần tự sửa lỗi tối đa (Max Retries)" class="wp-input" style="width:80px;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wp-form-group">
                    <label for="task-specification">Đặc tả chi tiết (Specification / Prompt cho Agent)</label>
                    <textarea name="specification" id="task-specification" rows="3" placeholder="Mô tả các trường dữ liệu, quy tắc nghiệp vụ Domain và yêu cầu kiểm thử..." class="wp-textarea"></textarea>
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
                <h2>📜 Backlog &amp; Lịch sử Tiến hóa Source Code (Evolution Trajectory)</h2>
            </div>
            <?php if (empty($tasks)): ?>
                <p class="wp-text-muted">Chưa có nhiệm vụ nào trong Backlog.</p>
            <?php else: ?>
                <table class="wp-table">
                    <thead>
                        <tr>
                            <th style="width:90px;">Mã Task</th>
                            <th>Nhiệm vụ, kết quả &amp; công việc</th>
                            <th>Agent</th>
                            <th>Trạng thái</th>
                            <th style="text-align:right; width:210px;">Điều khiển Loop</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $t): ?>
                            <?php $progress = $taskProgress[(int) $t->id] ?? null; ?>
                            <tr data-harness-task-row="<?= (int) $t->id ?>">
                                <td>
                                    <strong><code><?= htmlspecialchars($t->taskCode) ?></code></strong><br>
                                    <small class="wp-text-muted" data-task-attempts>Lần thử: <?= $t->attempts ?>/<?= $t->maxAttempts ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($t->title) ?></strong>
                                    <?php if ($t->targetName !== ''): ?>
                                        <span class="wp-tag-chip">Target: <?= htmlspecialchars($t->targetName) ?></span>
                                    <?php endif; ?>
                                    <div class="harness-task-result">
                                        <strong>📌 Kết quả / tiến độ</strong>
                                        <p class="wp-text-muted" data-task-summary><?= htmlspecialchars((string) ($progress['summary'] ?? 'Chưa có kết quả ghi nhận.')) ?></p>
                                        <div class="harness-task-files" data-task-files data-task-status="<?= htmlspecialchars($t->status->value) ?>">
                                            <?php if (!empty($t->filesChanged)): ?>
                                                <small><strong><?= $t->status->value === 'running' ? '🛠️ Tệp đang sửa:' : '📄 Tệp đã thay đổi:' ?></strong></small>
                                                <div>
                                                    <?php foreach ($t->filesChanged as $f): ?>
                                                        <code><?= htmlspecialchars($f) ?></code>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php elseif ($t->status->value === 'running'): ?>
                                                <small class="wp-text-muted">Chưa ghi nhận tệp thay đổi.</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <details class="harness-task-input">
                                        <summary>📨 Xem yêu cầu đầu vào</summary>
                                        <p><?= htmlspecialchars($t->specification) ?></p>
                                    </details>

                                    <?php if ($t->verificationOutput !== ''): ?>
                                        <details class="harness-task-gates">
                                            <summary>🧪 Chi tiết cổng kiểm định</summary>
                                            <pre><?= htmlspecialchars($t->verificationOutput) ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($t->agentBackend) ?></code></td>
                                <td>
                                    <span class="wp-status-badge <?= $t->status->badgeClass() ?>" data-task-status-badge><?= $t->status->label() ?></span>
                                    <?php if ($t->snapshotId !== ''): ?>
                                        <div style="margin-top:4px;"><small class="wp-text-muted">Snap: <code><?= htmlspecialchars(substr($t->snapshotId, 0, 18)) ?></code></small></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <form method="POST" action="/admin/harness/run/<?= $t->id ?>" style="display:inline;">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                        <button type="submit" class="wp-btn wp-btn-primary wp-btn-sm">
                                            🚀 <?= $t->attempts > 0 ? 'Chạy lại Loop' : 'Chạy Loop' ?>
                                        </button>
                                    </form>
                                    <?php if ($t->snapshotId !== ''): ?>
                                        <form method="POST" action="/admin/harness/rollback" style="display:inline;" onsubmit="return confirm('Khôi phục source code về Snapshot trước khi chạy Task này?');">
            <?= \App\Presentation\Http\Csrf::field() ?>
                                            <input type="hidden" name="snapshot_id" value="<?= htmlspecialchars($t->snapshotId) ?>">
                                            <button type="submit" class="wp-btn wp-btn-secondary wp-btn-sm" title="Rollback về Snapshot">⏪</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="/admin/harness/delete/<?= $t->id ?>" style="display:inline;" onsubmit="return confirm('Xóa Task này?');">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
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
                <h2>🤖 AI Agent Runners trên máy</h2>
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
                <h2>📸 Snapshots &amp; Auto-Rollback</h2>
            </div>
            <?php if (empty($snapshots)): ?>
                <p class="wp-text-muted">Chưa có bản chụp Snapshot nào.</p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach (array_slice($snapshots, 0, 8) as $snap): ?>
                        <div style="border:1px solid #e2e8f0; border-radius:6px; padding:10px;">
                            <strong style="font-size:12.5px;"><code><?= htmlspecialchars($snap['snapshot_id']) ?></code></strong>
                            <div class="wp-text-muted" style="font-size:12px; margin:3px 0;">
                                🕒 <?= htmlspecialchars($snap['created_at']) ?> • <?= $snap['file_count'] ?> tệp mã nguồn
                            </div>
                            <form method="POST" action="/admin/harness/rollback" onsubmit="return confirm('Khôi phục toàn bộ source code về Snapshot này?');">
            <?= \App\Presentation\Http\Csrf::field() ?>
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

<script>
(function () {
    const actionSelect = document.querySelector('select[name="action_type"]');
    const backendSelect = document.getElementById('task-agent-backend');
    const specification = document.getElementById('task-specification');

    function updateResearchTaskFields() {
        if (!actionSelect || !backendSelect) return;
        const researchMode = actionSelect.value === 'research_and_develop';
        const builtinOption = backendSelect.querySelector('option[value="builtin-hexagonal"]');
        if (builtinOption) builtinOption.disabled = researchMode;
        if (researchMode && backendSelect.value === 'builtin-hexagonal') {
            const preferred = backendSelect.querySelector('option[value="codex"]:not(:disabled)');
            if (preferred) backendSelect.value = 'codex';
        }
        if (specification && researchMode) {
            specification.placeholder = 'Đề tài / câu hỏi nghiên cứu và tính năng mong muốn. Nêu bối cảnh, người dùng, yêu cầu nghiệp vụ và giới hạn cần giữ.';
        } else if (specification) {
            specification.placeholder = 'Mô tả các trường dữ liệu, quy tắc nghiệp vụ Domain và yêu cầu kiểm thử...';
        }
    }
    if (actionSelect) {
        actionSelect.addEventListener('change', updateResearchTaskFields);
        updateResearchTaskFields();
    }

    const isEnabled = <?= $cEnabled ? 'true' : 'false' ?>;
    const isDaemonAlive = <?= $cDaemonAlive ? 'true' : 'false' ?>;
    const intervalSec = <?= max(2, $cInterval) ?>;
    const btnTick = document.getElementById('btn-live-tick');
    const logBox = document.getElementById('continuous-log-box');
    const statCycles = document.getElementById('stat-cycles');
    const statTasks = document.getElementById('stat-tasks');
    const statPassed = document.getElementById('stat-passed');
    const statRollback = document.getElementById('stat-rollback');
    const statHeartbeat = document.getElementById('stat-heartbeat');
    const chkAutoDiscover = document.getElementById('continuous-autodiscover');

    function renderTaskProgress(tasks) {
        if (!Array.isArray(tasks)) return;
        tasks.forEach(task => {
            const row = document.querySelector('[data-harness-task-row="' + Number(task.id) + '"]');
            if (!row) return;

            const badge = row.querySelector('[data-task-status-badge]');
            if (badge) {
                badge.className = 'wp-status-badge ' + String(task.badge_class || 'badge-secondary');
                badge.textContent = String(task.status_label || task.status || '');
            }
            const summary = row.querySelector('[data-task-summary]');
            if (summary) summary.textContent = String(task.summary || '');

            const attempts = row.querySelector('[data-task-attempts]');
            if (attempts) attempts.textContent = 'Lần thử: ' + Number(task.attempts || 0) + '/' + Number(task.max_attempts || 0);

            const filesBox = row.querySelector('[data-task-files]');
            if (filesBox) {
                filesBox.replaceChildren();
                filesBox.dataset.taskStatus = String(task.status || '');
                const files = Array.isArray(task.files_changed) ? task.files_changed : [];
                if (files.length > 0) {
                    const label = document.createElement('small');
                    const strong = document.createElement('strong');
                    strong.textContent = task.status === 'running' ? '🛠️ Tệp đang sửa:' : '📄 Tệp đã thay đổi:';
                    label.appendChild(strong);
                    filesBox.appendChild(label);
                    const list = document.createElement('div');
                    files.forEach(path => {
                        const code = document.createElement('code');
                        code.textContent = String(path);
                        list.appendChild(code);
                    });
                    filesBox.appendChild(list);
                } else if (task.status === 'running') {
                    const empty = document.createElement('small');
                    empty.className = 'wp-text-muted';
                    empty.textContent = 'Chưa ghi nhận tệp thay đổi.';
                    filesBox.appendChild(empty);
                }
            }
        });
    }

    function renderState(st) {
        if (!st) return;
        if (statCycles) statCycles.textContent = String(st.cycles_completed ?? 0);
        if (statTasks) statTasks.textContent = String(st.tasks_executed ?? 0);
        if (statPassed) statPassed.textContent = String(st.tasks_succeeded ?? 0);
        if (statRollback) statRollback.textContent = String(st.tasks_rolled_back ?? 0);
        if (statHeartbeat && st.last_heartbeat) statHeartbeat.textContent = st.last_heartbeat;
        if (logBox && Array.isArray(st.recent_events)) {
            logBox.innerHTML = st.recent_events
                .map(ev => '<div>' + String(ev).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>')
                .join('');
        }
        renderTaskProgress(st.tasks);
    }

    async function triggerTick(reloadOnTask = false) {
        if (btnTick) btnTick.disabled = true;
        try {
            const fd = new FormData();
            fd.append('json', '1');
            if (chkAutoDiscover && chkAutoDiscover.checked) {
                fd.append('auto_discover', '1');
            }
            const res = await fetch('/admin/harness/continuous/tick', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const data = await res.json();
            if (data && data.state) {
                renderState(data.state);
                if (reloadOnTask && data.action === 'executed_task') {
                    setTimeout(() => window.location.reload(), 1000);
                }
            }
        } catch (e) {
            console.error('Continuous tick error:', e);
        } finally {
            if (btnTick) btnTick.disabled = false;
        }
    }

    if (btnTick) {
        btnTick.addEventListener('click', function () {
            triggerTick(true);
        });
    }

    if (isEnabled) {
        setInterval(async function () {
            if (isDaemonAlive) {
                try {
                    const res = await fetch('/admin/harness/continuous/status');
                    const st = await res.json();
                    renderState(st);
                } catch (e) {}
            } else {
                await triggerTick(true);
            }
        }, intervalSec * 1000);
    }
})();
</script>
