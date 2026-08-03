<?php

declare(strict_types=1);

/**
 * Helper global do Pregão — emitir eventos no canal Redis + persistência.
 *
 * Uso:
 *   pregao_emit('op', ['robot' => 'R2', 'level' => 'info', 'icon' => '🤖', 'msg' => '...'], $accountId);
 */

use App\Services\Pregao\PregaoEmitService;

if (!function_exists('pregao_emit')) {
    /**
     * @param array<string, mixed> $payload
     * @return array{v: int, type: string, ts: string, payload: array<string, mixed>, account_id?: int}
     */
    function pregao_emit(string $type, array $payload, ?int $accountId = null): array
    {
        static $service = null;
        if ($service === null) {
            $service = new PregaoEmitService();
        }
        return $service->emit($type, $payload, $accountId);
    }
}

if (!function_exists('pregao_emit_sale')) {
    /**
     * @param array{order_id: string, valor: float|int|string, titulo?: string, sku?: string} $sale
     * @return list<array<string, mixed>>
     */
    function pregao_emit_sale(array $sale, ?int $accountId = null): array
    {
        static $service = null;
        if ($service === null) {
            $service = new PregaoEmitService();
        }
        return $service->emitSale($sale, $accountId);
    }
}
