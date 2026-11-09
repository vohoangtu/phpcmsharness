<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\User;

interface UserRepositoryPort
{
    /**
     * @return User[]
     */
    public function findAll(): array;

    public function findById(int $id): ?User;

    public function findByUsername(string $username): ?User;

    public function save(User $user): int;

    public function delete(int $id): void;
}
