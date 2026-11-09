<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\User;
use App\Domain\Port\Outbound\AuthenticationSessionPort;
use App\Domain\Port\Outbound\LoginAttemptRepositoryPort;
use App\Domain\Port\Outbound\UserRepositoryPort;

final readonly class AuthService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const FAILURE_WINDOW_SECONDS = 900;
    private const THROTTLE_SECONDS = 60;

    private \Closure $clock;

    public function __construct(
        private UserRepositoryPort $userRepository,
        private AuthenticationSessionPort $authenticationSession,
        private LoginAttemptRepositoryPort $loginAttempts,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function attemptLogin(string $username, string $password): ?User
    {
        $now = ($this->clock)();
        $normalizedUsername = strtolower(trim($username));
        $identifierHash = hash('sha256', $normalizedUsername);
        $this->loginAttempts->deleteBefore($now - self::FAILURE_WINDOW_SECONDS);
        $attempt = $this->loginAttempts->find($identifierHash);

        if ($attempt !== null && $attempt['window_started_at'] + self::FAILURE_WINDOW_SECONDS <= $now) {
            $this->loginAttempts->delete($identifierHash);
            $attempt = null;
        }
        if ($attempt !== null && $attempt['blocked_until'] > $now) {
            return null;
        }

        $user = $this->userRepository->findByUsername(trim($username));
        if ($user === null || !password_verify($password, $user->passwordHash)) {
            $windowStartedAt = $attempt['window_started_at'] ?? $now;
            $failures = ($attempt['failures'] ?? 0) + 1;
            $blockedUntil = $failures >= self::MAX_FAILED_ATTEMPTS ? $now + self::THROTTLE_SECONDS : 0;
            $this->loginAttempts->save($identifierHash, $failures, $windowStartedAt, $blockedUntil);
            return null;
        }

        $this->loginAttempts->delete($identifierHash);

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
