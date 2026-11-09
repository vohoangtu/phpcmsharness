<?php
declare(strict_types=1);
/** @var string|null $error */
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập Quản trị — WP-Hexagon CMS</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="wp-login-page">
    <div class="wp-login-box">
        <div class="wp-login-brand">
            <div class="wp-login-hexagon">⬡</div>
            <h1>WP-Hexagon CMS</h1>
            <p>Clean Code &amp; Hexagonal Architecture (PHP 8.5)</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="wp-notice wp-notice-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/login" class="wp-card wp-login-form">
            <?= \App\Presentation\Http\Csrf::field() ?>
            <div class="wp-form-group">
                <label for="username">Tên người dùng</label>
                <input type="text" id="username" name="username" value="admin" required autofocus class="wp-input">
            </div>
            <div class="wp-form-group">
                <label for="password">Mật khẩu</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required class="wp-input">
            </div>
            <button type="submit" class="wp-btn wp-btn-primary wp-btn-block">Đăng nhập vào Dashboard</button>
        </form>

        <div class="wp-login-back">
            <a href="/">← Quay lại trang chủ</a>
        </div>
    </div>
</body>
</html>
