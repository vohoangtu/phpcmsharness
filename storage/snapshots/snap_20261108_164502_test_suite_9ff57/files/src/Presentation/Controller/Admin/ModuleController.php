<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\ModuleManagerService;
use App\Domain\Enum\ModuleKey;
use App\Presentation\Controller\BaseController;
use App\Presentation\Http\LocalRedirect;

final class ModuleController extends BaseController
{
    public function index(): void
    {
        $moduleManager = $this->container->get(ModuleManagerService::class);
        $filter = (string) ($_GET['filter'] ?? 'all');
        $allModules = $moduleManager->getAllModules();

        $filteredModules = array_filter($allModules, function ($m) use ($filter): bool {
            if ($filter === 'enabled') {
                return $m->isEnabled;
            }
            if ($filter === 'disabled') {
                return !$m->isEnabled;
            }
            return true;
        });

        $enabledCount = count(array_filter($allModules, fn($m) => $m->isEnabled));

        $this->view->renderAdmin('admin/modules', [
            'pageTitle' => 'Quản lý Chức năng / Modules (Plugins)',
            'modules' => $filteredModules,
            'totalCount' => count($allModules),
            'enabledCount' => $enabledCount,
            'disabledCount' => count($allModules) - $enabledCount,
            'currentFilter' => $filter,
        ]);
    }

    public function toggle(): void
    {
        $rawKey = (string) ($_POST['module_key'] ?? '');
        $moduleKey = ModuleKey::tryFrom($rawKey);
        if ($moduleKey === null) {
            if ($this->isAjax()) {
                $this->json(['ok' => false, 'message' => 'Module không hợp lệ.'], 400);
                return;
            }
            $this->flash('error', 'Mã chức năng không hợp lệ.');
            $this->redirect('/admin/modules');
            return;
        }

        $moduleManager = $this->container->get(ModuleManagerService::class);
        $currentState = $moduleManager->isEnabled($moduleKey);
        $targetState = isset($_POST['state']) ? ((string) $_POST['state'] === '1') : !$currentState;

        $msg = $moduleManager->setModuleState($moduleKey, $targetState);

        if ($this->isAjax()) {
            $this->json([
                'ok' => true,
                'message' => $msg,
                'enabledMap' => $moduleManager->getEnabledMap(),
            ]);
            return;
        }

        $this->flash('success', $msg);
        $redirectTo = LocalRedirect::resolve((string) ($_POST['redirect_to'] ?? '/admin/modules'), '/admin/modules');
        $this->redirect($redirectTo);
    }

    private function isAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
}
