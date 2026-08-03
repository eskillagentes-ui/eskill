<?php

declare(strict_types=1);

/**
 * Helper global do Pregão — emitir eventos no canal Redis + persistência.
 *
 * Uso:
 *   pregao_emit('op', [...], $accountId);
 *   pregao_emit('op', [...], $accountId, 'seed'); // só com PREGAO_SEED=true
 */

use App\Services\Pregao\PregaoEmitService;

if (!function_exists('pregao_seed_enabled')) {
    function pregao_seed_enabled(): bool
    {
        $raw = $_ENV['PREGAO_SEED'] ?? getenv('PREGAO_SEED') ?: 'false';
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}

if (!function_exists('pregao_emit')) {
    /**
     * @param array<string, mixed> $payload
     * @param 'live'|'seed' $source
     * @return array{v: int, type: string, ts: string, payload: array<string, mixed>, source: string, account_id?: int}
     */
    function pregao_emit(string $type, array $payload, ?int $accountId = null, string $source = 'live'): array
    {
        static $service = null;
        if ($service === null) {
            $service = new PregaoEmitService();
        }
        return $service->emit($type, $payload, $accountId, $source);
    }
}

if (!function_exists('pregao_emit_sale')) {
    /**
     * @param array{order_id: string, valor: float|int|string, titulo?: string, sku?: string} $sale
     * @param 'live'|'seed' $source
     * @return list<array<string, mixed>>
     */
    function pregao_emit_sale(array $sale, ?int $accountId = null, string $source = 'live'): array
    {
        static $service = null;
        if ($service === null) {
            $service = new PregaoEmitService();
        }
        return $service->emitSale($sale, $accountId, $source);
    }
}
