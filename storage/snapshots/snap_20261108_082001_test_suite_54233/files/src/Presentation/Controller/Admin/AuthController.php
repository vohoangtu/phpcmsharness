<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\AuthService;
use App\Presentation\Controller\BaseController;

final class AuthController extends BaseController
{
    public function showLogin(): void
    {
        $auth = $this->container->get(AuthService::class);
        if ($auth->isAuthenticated()) {
            $this->redirect('/admin');
            return;
        }

        $viewsPath = dirname(__DIR__, 2) . '/Views';
        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);

        require $viewsPath . '/admin/login.php';
    }

    public function login(): void
    {
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $auth = $this->container->get(AuthService::class);
        $user = $auth->attemptLogin($username, $password);

        if ($user === null) {
            $_SESSION['login_error'] = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
            $this->redirect('/admin/login');
            return;
        }

        session_regenerate_id(true);
        $this->flash('success', sprintf('Chào mừng %s quay trở lại bảng điều khiển!', $user->displayName));
        $this->redirect('/admin');
    }

    public function logout(): void
    {
        $this->container->get(AuthService::class)->logout();
        $this->redirect('/admin/login');
    }
}
