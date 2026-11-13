<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Extension\ExtensionCatalog;
use App\Domain\Port\Outbound\SettingRepositoryPort;
use App\Infrastructure\Container\Container;
use App\Presentation\Controller\BaseController;
use App\Presentation\View\ViewRenderer;
use Throwable;

final class ExtensionController extends BaseController
{
    /** @param array<string,mixed> $config */
    public function __construct(Container $container, ViewRenderer $view, private readonly array $config)
    {
        parent::__construct($container, $view);
    }

    public function index(): void
    {
        $catalog = ExtensionCatalog::normalize($this->config['extension_providers'] ?? []);
        $settings = $this->container->get(SettingRepositoryPort::class);
        foreach ($catalog as &$extension) {
            $extension['enabled'] = $settings->get('extension_enabled_' . $extension['id'], '1') === '1';
            $extension['installed'] = ExtensionCatalog::providerFileExists($extension['provider']);
        }
        unset($extension);
        $this->view->renderAdmin('admin/extensions/index', [
            'pageTitle' => 'Quản lý Extensions',
            'extensions' => $catalog,
        ]);
    }

    public function toggle(): void
    {
        $id = strtolower(trim((string)($_POST['extension_id'] ?? '')));
        $enable = (string)($_POST['state'] ?? '') === '1';
        try {
            $catalog = ExtensionCatalog::normalize($this->config['extension_providers'] ?? []);
            $match = null;
            foreach ($catalog as $extension) if ($extension['id'] === $id) { $match = $extension; break; }
            if ($match === null) throw new \RuntimeException('Extension không có trong danh sách cài đặt.');
            if ($enable && !class_exists($match['provider'])) throw new \RuntimeException('Không thể bật extension: chưa cài package/provider qua Composer.');
            $this->container->get(SettingRepositoryPort::class)->saveMany(['extension_enabled_' . $id => $enable ? '1' : '0']);
            $this->flash('success', $enable ? 'Đã bật extension. Provider sẽ được khởi tạo từ request tiếp theo.' : 'Đã tắt extension. Provider sẽ ngừng khởi tạo từ request tiếp theo.');
        } catch (Throwable $error) {
            $this->flash('error', $error->getMessage());
        }
        $this->redirect('/admin/extensions');
    }
}
