<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\RobotsTxtBuilder;
use App\Infrastructure\Container\Container;
use App\Presentation\Controller\BaseController;
use App\Presentation\View\ViewRenderer;

final class RobotsTxtController extends BaseController
{
    public function __construct(
        Container $container,
        ViewRenderer $view,
        private readonly string $siteUrl
    ) {
        parent::__construct($container, $view);
    }

    public function index(): void
    {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo (new RobotsTxtBuilder($this->siteUrl))->build();
    }
}
