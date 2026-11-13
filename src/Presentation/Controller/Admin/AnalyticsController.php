<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Domain\Port\Outbound\AnalyticsReportPort;
use App\Domain\Port\Outbound\SettingRepositoryPort;
use App\Infrastructure\Analytics\Ga4CredentialVault;
use App\Presentation\Controller\BaseController;
use Throwable;

final class AnalyticsController extends BaseController
{
    private Ga4CredentialVault $vault;
    private string $storagePath;

    public function __construct(\App\Infrastructure\Container\Container $container, \App\Presentation\View\ViewRenderer $view, string $storagePath)
    {
        parent::__construct($container, $view);
        $this->storagePath = rtrim($storagePath, '/');
        $this->vault = new Ga4CredentialVault($storagePath);
    }

    public function index(): void
    {
        $settings = $this->container->get(SettingRepositoryPort::class)->getAll();
        $report = null; $apiError = null;
        if ($this->vault->configured() && !empty($settings['ga4_property_id'])) {
            try { $report = $this->cachedDashboard((string)$settings['ga4_property_id']); }
            catch (Throwable $e) { $apiError = $e->getMessage(); }
        }
        $this->view->renderAdmin('admin/analytics/index', [
            'pageTitle' => 'Analytics · Google Analytics 4',
            'ga4Settings' => ['measurement_id'=>$settings['ga4_measurement_id'] ?? '', 'property_id'=>$settings['ga4_property_id'] ?? ''],
            'ga4CredentialConfigured' => $this->vault->configured(), 'ga4Report' => $report, 'ga4ApiError' => $apiError,
        ]);
    }

    /** @return array<string,mixed> */
    private function cachedDashboard(string $propertyId): array
    {
        $cacheDir = $this->storagePath . '/cache/ga4';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
        $cacheFile = $cacheDir . '/' . hash('sha256', $propertyId) . '.json';
        if (is_file($cacheFile) && filemtime($cacheFile) >= time() - 300) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }
        $report = $this->container->get(AnalyticsReportPort::class)->dashboard($this->vault->read() ?? [], $propertyId);
        @file_put_contents($cacheFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        return $report;
    }

    public function save(): void
    {
        $repo = $this->container->get(SettingRepositoryPort::class);
        $measurementId = strtoupper(trim((string)($_POST['measurement_id'] ?? '')));
        $propertyId = trim((string)($_POST['property_id'] ?? ''));
        if ($measurementId !== '' && !preg_match('/^G-[A-Z0-9]+$/', $measurementId)) {
            $this->flash('error', 'Measurement ID không hợp lệ. Định dạng đúng: G-XXXXXXXXXX.'); $this->redirect('/admin/analytics'); return;
        }
        if ($propertyId !== '' && !preg_match('/^\d{4,20}$/', $propertyId)) {
            $this->flash('error', 'Property ID chỉ gồm chữ số, không nhập tiền tố properties/.'); $this->redirect('/admin/analytics'); return;
        }
        try {
            $json = trim((string)($_POST['service_account_json'] ?? ''));
            $upload = $_FILES['service_account_file'] ?? null;
            if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
                    throw new \RuntimeException('Tải lên file JSON key không thành công.');
                }
                if ((int)($upload['size'] ?? 0) > 65536) throw new \RuntimeException('File JSON key vượt quá giới hạn 64 KiB.');
                $uploadedJson = file_get_contents((string)$upload['tmp_name']);
                if (!is_string($uploadedJson)) throw new \RuntimeException('Không đọc được file JSON key đã tải lên.');
                $json = $uploadedJson;
            }
            if ($json !== '') $this->vault->write($json);
            if (isset($_POST['remove_service_account'])) $this->vault->delete();
            $repo->saveMany(['ga4_measurement_id'=>$measurementId, 'ga4_property_id'=>$propertyId]);
            $this->flash('success', 'Đã lưu cấu hình GA4. Trang sẽ kiểm tra API khi tải lại.');
        } catch (Throwable $e) { $this->flash('error', $e->getMessage()); }
        $this->redirect('/admin/analytics');
    }
}
