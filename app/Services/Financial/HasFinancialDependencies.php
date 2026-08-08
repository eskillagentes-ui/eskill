<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Database;
use App\Services\MercadoLivreClient;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Trait compartilhado por todos os serviços financeiros.
 * Fornece acesso ao banco de dados, accountId e clientes de API.
 */
trait HasFinancialDependencies
{
    protected \PDO $db;
    protected ?int $accountId;
    private ?MercadoLivreClient $client = null;
    private ?object $mpClient = null;

    // Alíquota padrão de impostos (Simples Nacional - média)
    protected const DEFAULT_TAX_RATE = 0.0;

    // Cache TTL em segundos
    protected const CACHE_TTL_SHORT = 300;   // 5 minutos
    protected const CACHE_TTL_MEDIUM = 1800; // 30 minutos
    protected const CACHE_TTL_LONG = 3600;   // 1 hora

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->db = Database::getInstance();
    }

    /**
     * Obtém instância do cliente ML (lazy loading)
     */
    protected function getClient(): MercadoLivreClient
    {
        if ($this->client === null) {
            $this->client = new MercadoLivreClient($this->accountId);
        }
        return $this->client;
    }

    /**
     * Obtém cliente HTTP para Mercado Pago API (lazy loading)
     */
    protected function getMercadoPagoClient(): object
    {
        if ($this->mpClient !== null) {
            return $this->mpClient;
        }

        $mlClient = $this->getClient();
        $accessToken = $mlClient->getAccessToken();

        $guzzle = new GuzzleClient([
            'base_uri' => 'https://api.mercadopago.com',
            'timeout'  => 30,
            'headers'  => [
                'Authorization' => 'Bearer ' . ($accessToken ?? ''),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);

        $this->mpClient = new class($guzzle) {
            private GuzzleClient $http;

            public function __construct(GuzzleClient $http)
            {
                $this->http = $http;
            }

            private function request(string $method, string $url, array $data = []): array
            {
                $options = !empty($data) ? ['json' => $data] : [];
                $response = $this->http->request($method, $url, $options);
                return json_decode($response->getBody()->getContents(), true) ?: [];
            }

            public function get(string $url, array $params = []): array
            {
                $options = !empty($params) ? ['query' => $params] : [];
                $response = $this->http->request('GET', $url, $options);
                return json_decode($response->getBody()->getContents(), true) ?: [];
            }

            public function post(string $url, array $data = []): array
            {
                return $this->request('POST', $url, $data);
            }

            public function put(string $url, array $data = []): array
            {
                return $this->request('PUT', $url, $data);
            }

            public function delete(string $url): array
            {
                return $this->request('DELETE', $url);
            }
        };

        return $this->mpClient;
    }

    /**
     * GET na API Mercado Pago com envelope de erro compatível com o cliente ML
     * (error/message/status) — /v1/account/* e /v1/payments/* não existem em api.mercadolibre.com.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function mpGet(string $path, array $params = []): array
    {
        return $this->mpRequest('GET', $path, $params);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function mpPost(string $path, array $body = []): array
    {
        return $this->mpRequest('POST', $path, [], $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function mpPut(string $path, array $body = []): array
    {
        return $this->mpRequest('PUT', $path, [], $body);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mpDelete(string $path): array
    {
        return $this->mpRequest('DELETE', $path);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function mpRequest(string $method, string $path, array $query = [], array $body = []): array
    {
        try {
            $client = $this->getMercadoPagoClient();
            $method = strtoupper($method);
            $data = match ($method) {
                'GET' => $client->get($path, $query),
                'POST' => $client->post($path, $body),
                'PUT' => $client->put($path, $body),
                'DELETE' => $client->delete($path),
                default => throw new \InvalidArgumentException('Unsupported MP method: ' . $method),
            };
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $status = null;
            $decoded = null;
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $status = $response->getStatusCode();
                $decoded = json_decode((string) $response->getBody(), true);
            }
            if (is_array($decoded)) {
                return array_merge($decoded, [
                    'error' => (string) ($decoded['error'] ?? 'http_error'),
                    'message' => (string) ($decoded['message'] ?? $e->getMessage()),
                    'status' => $status,
                ]);
            }

            return [
                'error' => 'http_error',
                'message' => $e->getMessage(),
                'status' => $status,
            ];
        }
    }

    /**
     * Obtém o seller ID da conta
     */
    protected function getSellerId(): ?string
    {
        $client = $this->getClient();
        return $client->getSellerId();
    }
}
