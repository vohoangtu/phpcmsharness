<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use RuntimeException;

/** Minimal Google Analytics Data API v1beta client using a read-only service account. */
final class Ga4DataClient
{
    /** @param array{client_email:string,private_key:string,token_uri?:string} $credentials */
    public function __construct(private readonly array $credentials) {}

    /** @return array<string,mixed> */
    public function dashboard(string $propertyId, int $days = 28): array
    {
        if (!preg_match('/^\d{4,20}$/', $propertyId)) throw new RuntimeException('GA4 Property ID phải là số (không gồm tiền tố properties/).');
        $days = max(7, min(90, $days));
        $token = $this->accessToken();
        $end = new \DateTimeImmutable('yesterday'); $start = $end->modify('-' . ($days - 1) . ' days');
        $range = [['startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d')]];
        $summary = $this->report($propertyId, $token, ['activeUsers','sessions','screenPageViews','ecommercePurchases','totalRevenue','addToCarts','checkouts'], [], $range);
        $trend = $this->report($propertyId, $token, ['activeUsers','sessions','screenPageViews'], ['date'], $range);
        $pages = $this->report($propertyId, $token, ['screenPageViews','activeUsers'], ['pagePath'], $range, 10);
        $sources = $this->report($propertyId, $token, ['sessions','activeUsers'], ['sessionSourceMedium'], $range, 10);
        return ['summary' => $summary, 'trend' => $trend, 'pages' => $pages, 'sources' => $sources, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }

    private function accessToken(): string
    {
        $now = time();
        $encode = static fn(string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $header = $encode(json_encode(['alg'=>'RS256','typ'=>'JWT'], JSON_THROW_ON_ERROR));
        $claim = $encode(json_encode(['iss'=>$this->credentials['client_email'],'scope'=>'https://www.googleapis.com/auth/analytics.readonly','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600], JSON_THROW_ON_ERROR));
        $input = $header . '.' . $claim;
        if (!openssl_sign($input, $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Không ký được JWT service account GA4.');
        $response = $this->request('POST', 'https://oauth2.googleapis.com/token', ['Content-Type'=>'application/x-www-form-urlencoded'], http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$input.'.'.$encode($signature)]));
        $data = json_decode($response, true);
        if (!is_array($data) || !is_string($data['access_token'] ?? null)) throw new RuntimeException('Google OAuth không trả access token hợp lệ.');
        return $data['access_token'];
    }

    /** @param list<string> $metrics @param list<string> $dimensions @param list<array{startDate:string,endDate:string}> $ranges @return array{headers:list<string>,rows:list<array<string,string>>} */
    private function report(string $property, string $token, array $metrics, array $dimensions, array $ranges, int $limit = 100): array
    {
        $payload = ['dateRanges'=>$ranges,'metrics'=>array_map(static fn($n)=>['name'=>$n],$metrics),'limit'=>(string)$limit];
        if ($dimensions) $payload['dimensions'] = array_map(static fn($n)=>['name'=>$n],$dimensions);
        $json = $this->request('POST', 'https://analyticsdata.googleapis.com/v1beta/properties/'.$property.':runReport', ['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        $data = json_decode($json, true);
        if (!is_array($data)) throw new RuntimeException('GA4 Data API trả JSON không hợp lệ.');
        $headers = array_merge(array_column($data['dimensionHeaders'] ?? [], 'name'), array_column($data['metricHeaders'] ?? [], 'name'));
        $rows = [];
        foreach ($data['rows'] ?? [] as $row) {
            $values = array_merge(array_column($row['dimensionValues'] ?? [], 'value'), array_column($row['metricValues'] ?? [], 'value'));
            $rows[] = array_combine($headers, array_map('strval', $values)) ?: [];
        }
        return ['headers'=>$headers,'rows'=>$rows];
    }

    /** @param array<string,string> $headers */
    private function request(string $method, string $url, array $headers, string $body): string
    {
        $headerLines = [];
        foreach ($headers as $key=>$value) $headerLines[] = $key . ': ' . $value;
        $context = stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headerLines),'content'=>$body,'timeout'=>15,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $result = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $status = (int)$m[1];
        if (!is_string($result)) throw new RuntimeException('Không kết nối được Google Analytics API. Kiểm tra HTTPS/DNS và cấu hình máy chủ.');
        if ($status < 200 || $status >= 300) {
            $data = json_decode($result, true); $message = is_array($data) ? (string)($data['error']['message'] ?? 'Google API request thất bại.') : 'Google API request thất bại.';
            throw new RuntimeException('GA4 API (' . $status . '): ' . substr($message, 0, 400));
        }
        return $result;
    }
}
