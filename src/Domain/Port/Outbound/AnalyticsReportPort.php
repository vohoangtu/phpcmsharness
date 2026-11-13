<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

interface AnalyticsReportPort
{
    /** @param array{client_email:string,private_key:string,token_uri?:string} $credentials
     *  @return array<string,mixed>
     */
    public function dashboard(array $credentials, string $propertyId, int $days = 28): array;
}
