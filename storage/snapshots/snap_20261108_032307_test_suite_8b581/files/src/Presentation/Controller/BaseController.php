<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Infrastructure\Container\Container;
use App\Presentation\View\ViewRenderer;

abstract class BaseController
{
    public function __construct(
        protected readonly Container $container,
        protected readonly ViewRenderer $view
    ) {
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
