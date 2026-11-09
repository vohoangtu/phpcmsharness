<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Entity\User;
use App\Domain\Port\Outbound\SettingRepositoryPort;
use App\Domain\Port\Outbound\UserRepositoryPort;
use App\Presentation\Controller\BaseController;

final class SettingController extends BaseController
{
    public function settings(): void
    {
        $repo = $this->container->get(SettingRepositoryPort::class);
        $this->view->renderAdmin('admin/settings', [
            'pageTitle' => 'Cài đặt Hệ thống (Settings)',
            'settings' => $repo->getAll(),
        ]);
    }

    public function saveSettings(): void
    {
        $repo = $this->container->get(SettingRepositoryPort::class);
        $allowedKeys = ['site_title', 'site_tagline', 'contact_email', 'contact_phone', 'footer_text', 'auto_approve_comments'];
        $toSave = [];
        foreach ($allowedKeys as $key) {
            if ($key === 'auto_approve_comments') {
                $toSave[$key] = isset($_POST[$key]) ? '1' : '0';
            } elseif (isset($_POST[$key])) {
                $toSave[$key] = trim((string) $_POST[$key]);
            }
        }
        $repo->saveMany($toSave);
        $this->flash('success', 'Đã lưu cài đặt hệ thống thành công!');
        $this->redirect('/admin/settings');
    }

    public function users(): void
    {
        $userRepo = $this->container->get(UserRepositoryPort::class);
        $this->view->renderAdmin('admin/users', [
            'pageTitle' => 'Quản lý Thành viên (Users)',
            'users' => $userRepo->findAll(),
        ]);
    }

    public function saveUser(): void
    {
        $userRepo = $this->container->get(UserRepositoryPort::class);
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $this->flash('error', 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.');
            $this->redirect('/admin/users');
            return;
        }

        if ($userRepo->findByUsername($username) !== null) {
            $this->flash('error', 'Tên đăng nhập đã tồn tại.');
            $this->redirect('/admin/users');
            return;
        }

        $userRepo->save(new User(
            id: null,
            username: $username,
            email: trim((string) ($_POST['email'] ?? '')),
            passwordHash: password_hash($password, PASSWORD_DEFAULT),
            displayName: trim((string) ($_POST['display_name'] ?? $username)),
            role: in_array(($_POST['role'] ?? 'editor'), ['admin', 'editor', 'author'], true) ? (string) $_POST['role'] : 'editor'
        ));

        $this->flash('success', 'Đã thêm thành viên mới thành công!');
        $this->redirect('/admin/users');
    }

    public function deleteUser(string $id): void
    {
        $userId = (int) $id;
        if ($userId === 1) {
            $this->flash('error', 'Không thể xóa tài khoản Quản trị viên gốc.');
            $this->redirect('/admin/users');
            return;
        }
        $this->container->get(UserRepositoryPort::class)->delete($userId);
        $this->flash('success', 'Đã xóa thành viên.');
        $this->redirect('/admin/users');
    }
}
