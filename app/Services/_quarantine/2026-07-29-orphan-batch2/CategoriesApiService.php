<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

class CategoriesApiService
{
    private CategoriesApiGatewayInterface $gateway;
    private int $maxRetries;
    private int $baseDelayMs;
    private int $maxDelayMs;

    /** @var callable */
    private $sleepFn;

    public function __construct(
        ?CategoriesApiGatewayInterface $gateway = null,
        ?callable $sleepFn = null,
        int $maxRetries = 3,
        int $baseDelayMs = 200,
        int $maxDelayMs = 2000
    ) {
        $this->gateway = $gateway ?? new MercadoLivreCategoriesGateway();
        $this->sleepFn = $sleepFn ?? static function (int $delayMs): void {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        };
        $this->maxRetries = max(1, $maxRetries);
        $this->baseDelayMs = max(1, $baseDelayMs);
        $this->maxDelayMs = max($this->baseDelayMs, $maxDelayMs);
    }

    /**
     * Lista categorias raiz por site usando endpoint real: GET /sites/{site_id}/categories
     *
     * @return CategoryNodeDTO[]
     */
    public function listSiteCategories(string $siteId = 'MLB'): array
    {
        $this->validateSiteId($siteId);

        $endpoint = "/sites/{$siteId}/categories";
        $payload = $this->requestWithRetry(
            fn() => $this->gateway->get($endpoint, [], 3600, true),
            'ml.categories.list',
            [
                'site_id' => $siteId,
                'endpoint' => $endpoint,
            ]
        );

        if (!$this->isListArray($payload)) {
            throw new CategoriesApiException(
                'Resposta inválida ao listar categorias do site',
                502,
                'invalid_payload',
                [
                    'site_id' => $siteId,
                    'endpoint' => $endpoint,
                ]
            );
        }

        $categories = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }
            $categories[] = CategoryNodeDTO::fromArray($row);
        }

        return $categories;
    }

    /**
     * Obtém detalhes completos da categoria: GET /categories/{category_id}
     */
    public function getCategory(string $categoryId): CategoryDetailDTO
    {
        $this->validateCategoryId($categoryId);

        $endpoint = "/categories/{$categoryId}";
        $payload = $this->requestWithRetry(
            fn() => $this->gateway->get($endpoint, [], 86400, true),
            'ml.categories.detail',
            [
                'category_id' => $categoryId,
                'endpoint' => $endpoint,
            ]
        );

        if ($this->isListArray($payload)) {
            throw new CategoriesApiException(
                'Resposta inválida ao consultar detalhe da categoria',
                502,
                'invalid_payload',
                [
                    'category_id' => $categoryId,
                    'endpoint' => $endpoint,
                ]
            );
        }

        return CategoryDetailDTO::fromArray($payload);
    }

    private function validateSiteId(string $siteId): void
    {
        if (!preg_match('/^[A-Z]{3}$/', $siteId)) {
            throw new CategoriesApiException('site_id inválido', 422, 'validation_error', ['site_id' => $siteId]);
        }
    }

    private function validateCategoryId(string $categoryId): void
    {
        if (!preg_match('/^[A-Z]{3}\d+$/', $categoryId)) {
            throw new CategoriesApiException('category_id inválido', 422, 'validation_error', [
                'category_id' => $categoryId,
            ]);
        }
    }

    private function requestWithRetry(callable $request, string $operation, array $context = []): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $response = $request();

            if (!$this->isApiError($response)) {
                return $response;
            }

            $status = (int)($response['status'] ?? 500);
            $errorCode = (string)($response['error'] ?? 'unknown_error');
            $message = (string)($response['message'] ?? 'Erro na API do Mercado Livre');

            $lastError = new CategoriesApiException(
                $message,
                $status,
                $errorCode,
                array_merge($context, [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'response' => $response,
                ])
            );

            if (!$this->isRetryableError($status, $errorCode, $message) || $attempt === $this->maxRetries) {
                throw $lastError;
            }

            $delayMs = $this->calculateExponentialDelayMs($attempt);
            ($this->sleepFn)($delayMs);
        }

        throw $lastError ?? new CategoriesApiException('Falha inesperada na integração de categorias', 500);
    }

    private function calculateExponentialDelayMs(int $attempt): int
    {
        $delay = $this->baseDelayMs * (2 ** max(0, $attempt - 1));
        return (int)min($delay, $this->maxDelayMs);
    }

    private function isRetryableError(int $status, string $errorCode, string $message): bool
    {
        if ($errorCode === 'network_disabled') {
            return false;
        }

        if (in_array($status, [0, 429, 500, 502, 503, 504], true)) {
            return true;
        }

        $transientErrorCodes = [
            'connection_error',
            'network_error',
            'timeout',
            'circuit_breaker_open',
            'service_unavailable',
        ];
        if (in_array(strtolower($errorCode), $transientErrorCodes, true)) {
            return true;
        }

        $normalized = strtolower($message);
        $transientFragments = [
            'timeout',
            'timed out',
            'connection',
            'network',
            'temporari',
            'rate limit',
            'too many requests',
            'service unavailable',
        ];

        foreach ($transientFragments as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function isApiError(array $response): bool
    {
        return isset($response['error']) && is_string($response['error']) && $response['error'] !== '';
    }

    private function isListArray(array $array): bool
    {
        $index = 0;
        foreach ($array as $key => $_value) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }
        return true;
    }
}
