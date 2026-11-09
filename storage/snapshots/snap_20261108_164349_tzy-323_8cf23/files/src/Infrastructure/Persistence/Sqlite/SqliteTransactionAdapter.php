<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Port\Outbound\TransactionPort;
use PDO;
use RuntimeException;

final readonly class SqliteTransactionAdapter implements TransactionPort
{
    public function __construct(private PDO $pdo)
    {
    }

    public function begin(): void
    {
        if (!$this->pdo->beginTransaction()) {
            throw new RuntimeException('Không thể bắt đầu transaction cho đơn hàng.');
        }
    }

    public function commit(): void
    {
        if (!$this->pdo->commit()) {
            throw new RuntimeException('Không thể xác nhận transaction cho đơn hàng.');
        }
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
