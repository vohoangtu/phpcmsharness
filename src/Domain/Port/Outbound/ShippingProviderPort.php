<?php
declare(strict_types=1);
namespace App\Domain\Port\Outbound;
/** External carrier adapter; configured providers supply implementations. */
interface ShippingProviderPort
{
    /** @param array<string,mixed> $shipment @return array<string,mixed> */
    public function createShipment(array $shipment): array;
    /** @return array<string,mixed> */
    public function getShipmentStatus(string $reference): array;
}
