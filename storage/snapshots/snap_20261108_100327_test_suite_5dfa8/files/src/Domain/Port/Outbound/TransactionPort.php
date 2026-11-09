<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

interface TransactionPort
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;
}
