<?php

declare(strict_types=1);

namespace App\Infrastructure\Session;

use App\Domain\Port\Outbound\AuthenticationSessionPort;

final class PhpAuthenticationSessionAdapter implements AuthenticationSessionPort
{
    private const SESSION_KEY = 'admin_user';

    public function regenerateId(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && session_regenerate_id();
    }

    public function storePrincipal(array $principal): void
    {
        $_SESSION[self::SESSION_KEY] = $principal;
    }

    public function currentPrincipal(): ?array
    {
        $principal = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($principal) ? $principal : null;
    }

    public function clearPrincipal(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
