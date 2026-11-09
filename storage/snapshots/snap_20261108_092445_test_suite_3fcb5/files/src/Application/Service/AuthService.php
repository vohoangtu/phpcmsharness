<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\User;
use App\Domain\Port\Outbound\AuthenticationSessionPort;
use App\Domain\Port\Outbound\UserRepositoryPort;

final readonly class AuthService
{
    public function __construct(
        private UserRepositoryPort $userRepository,
        private AuthenticationSessionPort $authenticationSession
    ) {
    }

    public function attemptLogin(string $username, string $password): ?User
    {
        $user = $this->userRepository->findByUsername(trim($username));
        if ($user === null) {
            return null;
        }
        if (!password_verify($password, $user->passwordHash)) {
            return null;
        }

        if (!$this->authenticationSession->regenerateId()) {
            return null;
        }

        $this->authenticationSession->storePrincipal([
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->displayName,
            'email' => $user->email,
            'role' => $user->role,
        ]);

        return $user;
    }

    public function logout(): void
    {
        $this->authenticationSession->clearPrincipal();
    }

    public function currentUser(): ?array
    {
        return $this->authenticationSession->currentPrincipal();
    }

    public function isAuthenticated(): bool
    {
        return !empty($this->authenticationSession->currentPrincipal()['id']);
    }
}
