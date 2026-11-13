<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use RuntimeException;

/** Stores the service-account key encrypted outside the public web root. */
final class Ga4CredentialVault
{
    public function __construct(private readonly string $storagePath) {}

    /** @return array{client_email:string,private_key:string,token_uri?:string}|null */
    public function read(): ?array
    {
        $file = $this->storagePath . '/secrets/ga4-service-account.enc';
        if (!is_file($file)) return null;
        $raw = file_get_contents($file);
        $key = $this->key(false);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Credential GA4 không đọc được.');
        $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) throw new RuntimeException('Không giải mã được credential GA4. Kiểm tra khóa bảo mật trong storage/secrets.');
        $credentials = json_decode($plain, true);
        if (!is_array($credentials) || !is_string($credentials['client_email'] ?? null) || !is_string($credentials['private_key'] ?? null)) {
            throw new RuntimeException('Credential GA4 đã lưu không hợp lệ.');
        }
        return $credentials;
    }

    public function write(string $json): void
    {
        if (strlen($json) > 65536) throw new RuntimeException('Credential JSON vượt quá giới hạn 64 KiB.');
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['type'] ?? '') !== 'service_account'
            || !filter_var($data['client_email'] ?? null, FILTER_VALIDATE_EMAIL)
            || !is_string($data['private_key'] ?? null) || !str_contains($data['private_key'], 'PRIVATE KEY')) {
            throw new RuntimeException('JSON cần là key service account hợp lệ từ Google Cloud.');
        }
        $dir = $this->storagePath . '/secrets';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Không thể tạo thư mục secrets.');
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($json, 'aes-256-gcm', $this->key(true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || file_put_contents($dir . '/ga4-service-account.enc', $iv . $tag . $cipher, LOCK_EX) === false) {
            throw new RuntimeException('Không thể lưu credential GA4.');
        }
        @chmod($dir, 0700); @chmod($dir . '/ga4-service-account.enc', 0600);
    }

    public function delete(): void
    {
        $file = $this->storagePath . '/secrets/ga4-service-account.enc';
        if (is_file($file)) unlink($file);
    }

    public function configured(): bool { return is_file($this->storagePath . '/secrets/ga4-service-account.enc'); }

    private function key(bool $create): string
    {
        $file = $this->storagePath . '/secrets/ga4-encryption.key';
        if (is_file($file)) {
            $key = file_get_contents($file);
            if (is_string($key) && strlen($key) === 32) return $key;
            throw new RuntimeException('Khóa mã hóa GA4 không hợp lệ.');
        }
        if (!$create) throw new RuntimeException('Thiếu khóa mã hóa GA4 trong storage/secrets.');
        $key = random_bytes(32); $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Không thể tạo thư mục secrets.');
        if (file_put_contents($file, $key, LOCK_EX) === false) throw new RuntimeException('Không thể tạo khóa mã hóa GA4.');
        @chmod($dir, 0700); @chmod($file, 0600);
        return $key;
    }
}
