<?php
declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\AiAssistantService;
use App\Application\Service\WorkerService;
use App\Presentation\Controller\BaseController;
use Throwable;

final class AiAssistantController extends BaseController
{
    public function __construct(\App\Infrastructure\Container\Container $container, \App\Presentation\View\ViewRenderer $view) { parent::__construct($container, $view); }

    public function generate(): void
    {
        try {
            $action = (string)($_POST['action'] ?? '');
            $context = json_decode((string)($_POST['context'] ?? '{}'), true);
            if (!is_array($context)) throw new \InvalidArgumentException('Dữ liệu nội dung không hợp lệ.');
            $payload = $this->container->get(AiAssistantService::class)->prepareJob($action, $context, (int)($_SESSION['admin_user']['id'] ?? 0));
            $jobId = $this->container->get(WorkerService::class)->enqueue('ai_assistant', $payload);
            $this->json(['ok' => true, 'job_id' => $jobId, 'status' => 'queued'], 202);
        } catch (Throwable $error) {
            $this->json(['ok' => false, 'error' => $error->getMessage()], 422);
        }
    }

    public function analyticsInsights(): void
    {
        try {
            $context = json_decode((string)($_POST['context'] ?? '{}'), true);
            if (!is_array($context)) throw new \InvalidArgumentException('Dữ liệu Analytics không hợp lệ.');
            $payload = $this->container->get(AiAssistantService::class)->prepareJob('analytics_insights', $context, (int)($_SESSION['admin_user']['id'] ?? 0));
            $jobId = $this->container->get(WorkerService::class)->enqueue('ai_assistant', $payload);
            $this->json(['ok' => true, 'job_id' => $jobId, 'status' => 'queued'], 202);
        } catch (Throwable $error) {
            $this->json(['ok' => false, 'error' => $error->getMessage()], 422);
        }
    }

    public function jobStatus(string $id): void
    {
        try {
            $job = $this->container->get(WorkerService::class)->getJob((int)$id);
            $ownerId = (int)($_SESSION['admin_user']['id'] ?? 0);
            if ($job === null || $job->type !== 'ai_assistant' || (int)($job->payload['owner_id'] ?? -1) !== $ownerId) {
                $this->json(['ok' => false, 'error' => 'Không tìm thấy tác vụ AI.'], 404);
                return;
            }
            $result = $job->status === 'completed' ? json_decode((string)$job->result, true) : null;
            if (is_array($result) && isset($result['html'])) $result['html'] = \App\Presentation\Security\HtmlContentSanitizer::sanitize((string)$result['html']);
            $this->json(['ok' => true, 'job' => ['id' => $job->id, 'type' => $job->type, 'status' => $job->status, 'error' => $job->error, 'result' => is_array($result) ? $result : null]]);
        } catch (Throwable $error) {
            $this->json(['ok' => false, 'error' => 'Không thể đọc trạng thái tác vụ AI: ' . $error->getMessage()], 500);
        }
    }
}
