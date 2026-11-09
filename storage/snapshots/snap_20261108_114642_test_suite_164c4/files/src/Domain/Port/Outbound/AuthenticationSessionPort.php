<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

interface AuthenticationSessionPort
{
    public function regenerateId(bool $deleteOldSession = false): bool;

    /** @param array{id: ?int, username: string, display_name: string, email: string, role: string} $principal */
    public function storePrincipal(array $principal): void;

    /** @return array{id: ?int, username: string, display_name: string, email: string, role: string}|null */
    public function currentPrincipal(): ?array;

    public function clearPrincipal(): void;
}
