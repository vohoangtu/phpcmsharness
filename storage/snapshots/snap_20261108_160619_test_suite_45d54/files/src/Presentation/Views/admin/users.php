<?php
declare(strict_types=1);
/** @var \App\Domain\Entity\User[] $users */
?>
<div class="wp-page-header">
    <div>
        <h1 class="wp-page-title">👥 Quản lý Thành viên (Users)</h1>
        <p class="wp-page-subtitle">Quản lý tài khoản quản trị viên, biên tập viên và tác giả.</p>
    </div>
</div>

<div class="wp-editor-layout">
    <div class="wp-editor-sidebar">
        <div class="wp-card">
            <div class="wp-card-header">
                <h3>+ Thêm thành viên mới</h3>
            </div>
            <form method="POST" action="/admin/users/save">
            <?= \App\Presentation\Http\Csrf::field() ?>
                <div class="wp-form-group">
                    <label for="user-username">Tên đăng nhập (Username) *</label>
                    <input type="text" id="user-username" name="username" required class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label for="user-display-name">Tên hiển thị *</label>
                    <input type="text" id="user-display-name" name="display_name" required class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label for="user-email">Email *</label>
                    <input type="email" id="user-email" name="email" autocomplete="email" required class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label for="user-password">Mật khẩu *</label>
                    <input type="password" id="user-password" name="password" required class="wp-input">
                </div>
                <div class="wp-form-group">
                    <label for="user-role">Vai trò (Role)</label>
                    <select id="user-role" name="role" class="wp-select">
                        <option value="admin">Quản trị viên (Administrator)</option>
                        <option value="editor" selected>Biên tập viên (Editor)</option>
                        <option value="author">Tác giả (Author)</option>
                    </select>
                </div>
                <button type="submit" class="wp-btn wp-btn-primary wp-btn-block">Tạo tài khoản</button>
            </form>
        </div>
    </div>

    <div class="wp-editor-main">
        <div class="wp-card">
            <table class="wp-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tên đăng nhập</th>
                        <th>Tên hiển thị</th>
                        <th>Email</th>
                        <th>Vai trò</th>
                        <th style="text-align:right;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>#<?= $u->id ?></td>
                            <td><strong><?= htmlspecialchars($u->username) ?></strong></td>
                            <td><?= htmlspecialchars($u->displayName) ?></td>
                            <td><?= htmlspecialchars($u->email) ?></td>
                            <td><span class="wp-tag-chip"><?= htmlspecialchars(strtoupper($u->role)) ?></span></td>
                            <td style="text-align:right;">
                                <?php if ($u->id !== 1): ?>
                                    <form method="POST" action="/admin/users/delete/<?= $u->id ?>" style="display:inline;" onsubmit="return confirm('Xóa tài khoản này?');">
                                        <?= \App\Presentation\Http\Csrf::field() ?>
                                        <button type="submit" class="wp-link-danger">Xóa</button>
                                    </form>
                                <?php else: ?>
                                    <small class="wp-text-muted">Root Admin</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
