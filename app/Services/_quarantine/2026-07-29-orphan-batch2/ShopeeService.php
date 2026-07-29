<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class ShopeeService
{
    private ?\PDO $db;
    private int $partnerId;
    private string $partnerKey;
    private string $redirectUri;
    private string $baseUrl;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db;
        $this->partnerId = (int)$this->readEnv('SHOPEE_PARTNER_ID', '0');
        $this->partnerKey = $this->readEnv('SHOPEE_PARTNER_KEY', '');

        $appUrl = $this->readEnv('APP_URL', 'https://eskill.com.br');
        $this->redirectUri = rtrim($appUrl, '/') . '/shopee/callback';
        $this->baseUrl = rtrim(
            $this->readEnv('SHOPEE_BASE_URL', 'https://partner.shopeemobile.com/api/v2'),
            '/'
        );
    }

    public function getAuthUrl(): string
    {
        $path = '/shop/auth_partner';
        $timestamp = time();
        $baseString = $this->partnerId . $path . $timestamp;
        $sign = hash_hmac('sha256', $baseString, $this->partnerKey);

        $query = http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'redirect' => $this->redirectUri,
        ]);

        return $this->baseUrl . $path . '?' . $query;
    }

    public function saveAuth(int $shopId, string $code): bool
    {
        try {
            $stmt = $this->pdo()->prepare(
                'INSERT INTO shopee_accounts (shop_id, auth_code, updated_at)
                 VALUES (:shop_id, :auth_code, NOW())
                 ON DUPLICATE KEY UPDATE auth_code = VALUES(auth_code), updated_at = NOW()'
            );

            return $stmt->execute([
                'shop_id' => $shopId,
                'auth_code' => $code,
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, scalar|null> $queryParams
     * @return array<string, mixed>
     */
    public function callPublicApi(string $endpoint, array $queryParams = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'success' => false,
                'status' => 0,
                'error' => $curlError !== '' ? $curlError : 'Request failed',
                'data' => null,
            ];
        }

        $decoded = json_decode($raw, true);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'error' => $httpCode >= 400 ? ('HTTP ' . $httpCode) : null,
            'data' => is_array($decoded) ? $decoded : ['raw' => $raw],
        ];
    }

    private function pdo(): \PDO
    {
        if ($this->db instanceof \PDO) {
            return $this->db;
        }

        $this->db = Database::getInstance();
        return $this->db;
    }

    private function readEnv(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            return $default;
        }

        return $trimmed;
    }
}
