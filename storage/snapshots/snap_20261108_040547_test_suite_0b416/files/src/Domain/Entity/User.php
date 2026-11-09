<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final readonly class User
{
    public function __construct(
        public ?int $id,
        public string $username,
        public string $email,
        public string $passwordHash,
        public string $displayName,
        public string $role, // 'admin' | 'editor' | 'author'
        public string $createdAt = ''
    ) {
    }
}
