<?php

declare(strict_types=1);

namespace App\Application\Extension;

interface ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void;
}
