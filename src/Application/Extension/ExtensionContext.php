<?php

declare(strict_types=1);

namespace App\Application\Extension;

use App\Infrastructure\Container\Container;

final readonly class ExtensionContext
{
    public function __construct(
        public Container $container,
        public HookRegistry $hooks
    ) {
    }

    /** @param callable(Container):object $factory */
    public function registerService(string $id, callable $factory): void
    {
        $this->container->registerExtensionFactory($id, $factory);
    }
}
