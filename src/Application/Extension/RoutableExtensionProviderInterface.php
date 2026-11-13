<?php

declare(strict_types=1);

namespace App\Application\Extension;

use App\Presentation\Http\Router;
use App\Presentation\View\ViewRenderer;

interface RoutableExtensionProviderInterface extends ExtensionProviderInterface
{
    /** Protect every administrator route with adminAuth=true. */
    public function registerRoutes(Router $router, ViewRenderer $view): void;
}
