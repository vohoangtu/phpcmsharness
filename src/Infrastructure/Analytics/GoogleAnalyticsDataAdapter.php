<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Port\Outbound\AnalyticsReportPort;

final class GoogleAnalyticsDataAdapter implements AnalyticsReportPort
{
    public function dashboard(array $credentials, string $propertyId, int $days = 28): array
    {
        return (new Ga4DataClient($credentials))->dashboard($propertyId, $days);
    }
}
