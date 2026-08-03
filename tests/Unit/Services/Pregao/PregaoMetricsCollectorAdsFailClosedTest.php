<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoMetricsCollector;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \App\Services\Pregao\PregaoMetricsCollector::failClosedAdsException
 */
class PregaoMetricsCollectorAdsFailClosedTest extends TestCase
{
    public function testOuterCatchNuncaPreservaFtMesmoComPrevAvailableRecente(): void
    {
        $pdo = $this->createMock(PDO::class);
        $collector = new PregaoMetricsCollector(
            $pdo,
            null,
            new PregaoEmitService($pdo),
            [
                'ads_collect_freshness_ttl' => 300,
                'ads_max_stale_age' => 3600,
            ]
        );

        $now = time();
        $meta = [
            'available' => ['Ft' => true],
            'metrics' => [
                'tacos' => [
                    'available' => true,
                    'value' => 7.042,
                    'acos' => 57.9,
                    'gasto_hoje' => 1.14,
                    'collected_at' => $now - 60, // fresco, dentro do max_stale
                ],
            ],
        ];

        $result = $collector->failClosedAdsException(
            $meta,
            new RuntimeException('constructor_or_persist_boom')
        );

        $this->assertFalse($result['available']);
        $this->assertFalse($meta['available']['Ft']);
        $this->assertFalse($meta['metrics']['tacos']['available']);
        $this->assertNull($result['tacos']);
        $this->assertSame('outer_catch_fail_closed', $result['reason']);
        $this->assertSame($now - 60, $meta['metrics']['tacos']['previous_collected_at']);
        $this->assertTrue($meta['metrics']['tacos']['previous_available']);
    }

    public function testOuterCatchNuncaPreservaFtSemTimestamp(): void
    {
        $pdo = $this->createMock(PDO::class);
        $collector = new PregaoMetricsCollector(
            $pdo,
            null,
            new PregaoEmitService($pdo),
            ['ads_max_stale_age' => 3600]
        );

        $meta = [
            'available' => ['Ft' => true],
            'metrics' => [
                'tacos' => [
                    'available' => true,
                    'value' => 9.0,
                    // sem collected_at
                ],
            ],
        ];

        $result = $collector->failClosedAdsException($meta, new RuntimeException('boom'));

        $this->assertFalse($result['available']);
        $this->assertFalse($meta['available']['Ft']);
        $this->assertNull($meta['metrics']['tacos']['previous_collected_at']);
        $this->assertSame('outer_catch_fail_closed', $result['reason']);
    }
}
