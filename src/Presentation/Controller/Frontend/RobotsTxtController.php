<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\RobotsTxtBuilder;
use App\Domain\Port\Outbound\SettingRepositoryPort;
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
        $siteUrl = (string)$this->container->get(SettingRepositoryPort::class)->get('site_url', $this->siteUrl);
        echo (new RobotsTxtBuilder($siteUrl))->build();
    }
}
