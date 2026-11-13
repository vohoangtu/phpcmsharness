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
        $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
        if ($siteUrl === '' || !filter_var($siteUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($siteUrl, PHP_URL_SCHEME)), ['http', 'https'], true) || !parse_url($siteUrl, PHP_URL_HOST) || parse_url($siteUrl, PHP_URL_QUERY) || parse_url($siteUrl, PHP_URL_FRAGMENT) || parse_url($siteUrl, PHP_URL_USER)) {
            $this->flash('error', 'Nhập domain website hợp lệ dạng https://example.com, không kèm query, fragment hoặc thông tin đăng nhập.');
            $this->redirect('/admin/settings');
            return;
        }
        $allowedKeys = ['site_title', 'site_tagline', 'contact_email', 'contact_phone', 'footer_text', 'auto_approve_comments'];
        $toSave = [];
        foreach ($allowedKeys as $key) {
            if ($key === 'auto_approve_comments') {
                $toSave[$key] = isset($_POST[$key]) ? '1' : '0';
            } elseif (isset($_POST[$key])) {
                $toSave[$key] = trim((string) $_POST[$key]);
            }
        }
        $toSave['site_url'] = $siteUrl;
        $repo->saveMany($toSave);
        $this->flash('success', 'Đã lưu cài đặt hệ thống thành công!');
        $this->redirect('/admin/settings');
    }

    public function homeSlider(): void
    {
        $settings = $this->container->get(SettingRepositoryPort::class)->getAll();
        $config = json_decode((string)($settings['home_slider_config'] ?? ''), true);
        if (!is_array($config)) $config = [];
        $this->view->renderAdmin('admin/home-slider', [
            'pageTitle' => 'Slider trang chủ',
            'sliderConfig' => $config,
        ]);
    }

    public function saveHomeSlider(): void
    {
        $repo = $this->container->get(SettingRepositoryPort::class);
        $slides = [];
        foreach (array_slice((array)($_POST['slides'] ?? []), 0, 12) as $slide) {
            if (!is_array($slide)) continue;
            $image = trim((string)($slide['image'] ?? ''));
            if (!$this->isSafeSliderUrl($image)) continue;
            $title = trim((string)($slide['title'] ?? ''));
            $buttonUrl = trim((string)($slide['button_url'] ?? ''));
            if ($buttonUrl !== '' && !$this->isSafeSliderUrl($buttonUrl)) $buttonUrl = '';
            $slides[] = [
                'image' => $image,
                'media_id' => max(0, (int)($slide['media_id'] ?? 0)),
                'eyebrow' => $this->limitText(trim((string)($slide['eyebrow'] ?? '')), 80),
                'title' => $this->limitText($title, 140),
                'description' => $this->limitText(trim((string)($slide['description'] ?? '')), 260),
                'button_text' => $this->limitText(trim((string)($slide['button_text'] ?? '')), 40),
                'button_url' => $buttonUrl,
                'enabled' => isset($slide['enabled']),
            ];
        }
        $config = [
            'enabled' => isset($_POST['enabled']) && count(array_filter($slides, static fn(array $slide): bool => $slide['enabled'])) > 0,
            'interval' => min(12, max(2, (int)($_POST['interval'] ?? 3))),
            'effect' => in_array($_POST['effect'] ?? 'fade', ['fade', 'zoom', 'slide'], true) ? (string)$_POST['effect'] : 'fade',
            'slides' => $slides,
        ];
        $repo->saveMany(['home_slider_config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $ids = array_column($slides, 'media_id');
        if ($ids !== []) $this->container->get(\App\Application\Service\MediaService::class)->markUsed($ids);
        $this->flash('success', 'Đã lưu cấu hình slider trang chủ.');
        $this->redirect('/admin/home-slider');
    }

    private function isSafeSliderUrl(string $url): bool
    {
        if ($url === '') return false;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return true;
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function limitText(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit * 4);
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
