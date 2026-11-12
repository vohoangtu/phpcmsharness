<?php
declare(strict_types=1);
namespace App\Domain\Port\Outbound;
/** External invoice adapter; no provider is configured by default. */
interface ElectronicInvoiceProviderPort
{
    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    public function issue(array $invoice): array;
    /** @return array<string,mixed> */
    public function getStatus(string $reference): array;
}
