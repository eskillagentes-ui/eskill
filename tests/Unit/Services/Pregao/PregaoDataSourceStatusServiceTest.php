<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoDataSourceStatusService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** @covers \App\Services\Pregao\PregaoDataSourceStatusService */
final class PregaoDataSourceStatusServiceTest extends TestCase
{
    public function testBuildExpõeSomenteFontesSanitizadasEDisponibilidadeReal(): void
    {
        $meta = [
            'metrics' => [
                'vendas_hoje' => ['available' => true, 'source' => 'ml_orders'],
                'tacos' => [
                    'available' => true,
                    'source' => 'AdsMetricsCollector',
                    'collected_at' => 1785907600,
                    'error' => 'segredo interno',
                ],
                'visitas_7d' => ['available' => true, 'source' => 'items_visits'],
                'health_medio' => [
                    'available' => true,
                    'source' => 'account_health_history',
                    'as_of' => '2026-08-04 23:50:26',
                ],
                'reputacao' => ['available' => true, 'source' => 'seller_reputation'],
                'perguntas_7d' => ['available' => true, 'source' => 'ml_api'],
                'posicao_media' => [
                    'available' => false,
                    'reason' => 'rank_tracker_disabled',
                    'message' => 'não deve vazar',
                ],
            ],
        ];
        $now = new DateTimeImmutable('2026-08-05 05:28:00', new DateTimeZone('America/Sao_Paulo'));

        $result = (new PregaoDataSourceStatusService())->build(
            $meta,
            '2026-08-05 08:27:27',
            $now
        );
        $items = array_column($result['items'], null, 'key');

        self::assertSame('2026-08-05T05:27:27-03:00', $result['consolidated_at']);
        self::assertSame(33, $result['age_seconds']);
        self::assertCount(7, $items);
        self::assertSame('ml_orders', $items['sales']['source']);
        self::assertTrue($items['ads']['available']);
        self::assertSame('account_health_history', $items['health']['source']);
        self::assertSame('2026-08-04T20:50:26-03:00', $items['health']['observed_at']);
        self::assertFalse($items['ranks']['available']);
        self::assertSame('rank_tracker_disabled', $items['ranks']['reason']);
        self::assertNull($items['ranks']['observed_at']);
        self::assertStringNotContainsString('segredo interno', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('não deve vazar', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testFonteDesconhecidaETimestampFuturoFalhamFechado(): void
    {
        $meta = [
            'metrics' => [
                'vendas_hoje' => ['available' => true, 'source' => 'https://interno/token'],
                'posicao_media' => ['available' => false, 'reason' => 'erro-com-detalhes'],
            ],
        ];
        $now = new DateTimeImmutable('2026-08-05 05:28:00', new DateTimeZone('America/Sao_Paulo'));

        $result = (new PregaoDataSourceStatusService())->build(
            $meta,
            '2026-08-05 09:00:00',
            $now
        );
        $items = array_column($result['items'], null, 'key');

        self::assertNull($result['consolidated_at']);
        self::assertNull($result['age_seconds']);
        self::assertNull($items['sales']['source']);
        self::assertSame('unavailable', $items['ranks']['reason']);
    }
}
