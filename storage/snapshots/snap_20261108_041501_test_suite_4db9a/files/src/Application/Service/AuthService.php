<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\User;
use App\Domain\Port\Outbound\UserRepositoryPort;

final readonly class AuthService
{
    public function __construct(
        private UserRepositoryPort $userRepository
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

        $_SESSION['admin_user'] = [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->displayName,
            'email' => $user->email,
            'role' => $user->role,
        ];

        return $user;
    }

    public function logout(): void
    {
        unset($_SESSION['admin_user']);
    }

    public function currentUser(): ?array
    {
        return $_SESSION['admin_user'] ?? null;
    }

    public function isAuthenticated(): bool
    {
        return !empty($_SESSION['admin_user']['id']);
    }
}
