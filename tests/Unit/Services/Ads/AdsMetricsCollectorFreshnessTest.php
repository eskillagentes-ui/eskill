<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ads;

use App\Services\Ads\AdsMetricsCollector;
use App\Services\Ads\SkuCustoService;
use App\Services\Pregao\PregaoEmitService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Ads\AdsMetricsCollector
 */
class AdsMetricsCollectorFreshnessTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function makeCollector(array $config = []): AdsMetricsCollector
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willThrowException(new \RuntimeException('no_db'));
        $emitter = new PregaoEmitService($pdo);
        $sku = new SkuCustoService($pdo);

        return new AdsMetricsCollector($pdo, $emitter, $sku, array_merge([
            'ads_collect_freshness_ttl' => 300,
            'ads_max_stale_age' => 3600,
        ], $config));
    }

    /**
     * @return array<string, mixed>
     */
    private function goodPayload(): array
    {
        return [
            'ok' => true,
            'available' => true,
            'active_campaigns' => 2,
            'tacos' => 7.042,
            'acos' => 57.9,
            'gasto_hoje' => 1.14,
            'tacos_baseline' => 10.0,
            'message' => null,
            'reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function negativePayload(): array
    {
        return [
            'ok' => true,
            'available' => false,
            'active_campaigns' => 0,
            'tacos' => null,
            'acos' => null,
            'gasto_hoje' => null,
            'tacos_baseline' => 10.0,
            'message' => 'nenhuma campanha ativa',
            'reason' => 'no_active_campaign',
        ];
    }

    /**
     * @param object{n: int} $counter
     */
    private function throwingFactory(object $counter): callable
    {
        return static function () use ($counter): object {
            return new class($counter) {
                /** @var object{n: int} */
                private object $counter;

                public function __construct(object $counter)
                {
                    $this->counter = $counter;
                }

                public function getCampaigns(string $status = 'all'): array
                {
                    $this->counter->n++;
                    throw new \RuntimeException('pads_http_429');
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    $this->counter->n++;
                    return [];
                }

                public function getAdsItems(string $from, string $to, int $limit = 50): array
                {
                    $this->counter->n++;
                    return [];
                }
            };
        };
    }

    public function testFreshnessTtlUsaConfigInjetado(): void
    {
        $collector = $this->makeCollector(['ads_collect_freshness_ttl' => 180]);
        $this->assertSame(180, $collector->freshnessTtlSeconds());
        $this->assertSame(3600, $collector->maxStaleAgeSeconds());
    }

    public function testCacheHitNaoChamaApi(): void
    {
        $collector = $this->makeCollector();
        $calls = 0;
        $collector->setAdsFactory(static function () use (&$calls): object {
            $calls++;
            return new class {
                public function getCampaigns(string $status = 'all'): array
                {
                    return ['campaigns' => [], '_meta' => []];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    return [];
                }

                public function getAdsItems(string $from, string $to, int $limit = 50): array
                {
                    return [];
                }
            };
        });

        $now = 1_700_000_000;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->goodPayload(), $now - 60);

        $result = $collector->collect(1335, false, false);

        $this->assertTrue($result['cached']);
        $this->assertFalse($result['stale']);
        $this->assertTrue($result['available']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(0, $collector->getApiCallCount());
        $this->assertSame(0, $calls);
        $this->assertEqualsWithDelta(7.042, (float) $result['tacos'], 0.001);
    }

    public function testNegativeCacheHitZeroGet(): void
    {
        $collector = $this->makeCollector();
        $counter = new class {
            public int $n = 0;
        };
        $collector->setAdsFactory($this->throwingFactory($counter));

        $now = 1_700_000_000;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->negativePayload(), $now - 30);

        $result = $collector->collect(1335, false, false);

        $this->assertTrue($result['cached']);
        $this->assertFalse($result['available']);
        $this->assertSame(0, $result['api_calls']);
        $this->assertSame(0, $counter->n);
        $this->assertSame('no_active_campaign', $result['reason']);
        $this->assertSame($now - 30, $result['collected_at']);
    }

    public function testCacheStaleChamaApi(): void
    {
        $collector = $this->makeCollector();
        $counter = new class {
            public int $n = 0;
        };
        $collector->setAdsFactory($this->throwingFactory($counter));

        $now = 1_700_000_000;
        $original = $now - 400;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->goodPayload(), $original);

        $result = $collector->collect(1335, false, false);

        $this->assertGreaterThanOrEqual(1, $collector->getApiCallCount());
        $this->assertGreaterThanOrEqual(1, $counter->n);
        $this->assertTrue($result['stale']);
        $this->assertTrue($result['available'], 'Ft/TACOS preservados após 429');
        $this->assertSame('transient_error_preserved', $result['reason']);
        $this->assertEqualsWithDelta(7.042, (float) $result['tacos'], 0.001);
    }

    public function testTimestampOriginalImutavelEmStale(): void
    {
        $collector = $this->makeCollector();
        $counter = new class {
            public int $n = 0;
        };
        $collector->setAdsFactory($this->throwingFactory($counter));

        $now = 1_700_000_000;
        $original = $now - 400;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->goodPayload(), $original);

        $result = $collector->collect(1335, false, false);

        $this->assertSame($original, $result['collected_at'], 'collected_at não pode virar agora');
        $this->assertSame($original, $result['original_collected_at']);
        $this->assertSame($now, $result['stale_at']);
        $this->assertNotSame($now, $result['collected_at']);
    }

    public function testMaxStaleExpiracaoFtIndisponivel(): void
    {
        $collector = $this->makeCollector(['ads_max_stale_age' => 3600]);
        $counter = new class {
            public int $n = 0;
        };
        $collector->setAdsFactory($this->throwingFactory($counter));

        $now = 1_700_000_000;
        $original = $now - 3601; // além de 1h
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->goodPayload(), $original);

        $result = $collector->collect(1335, false, false);

        $this->assertGreaterThanOrEqual(1, $counter->n);
        $this->assertFalse($result['available']);
        $this->assertTrue($result['stale']);
        $this->assertSame('max_stale_expired', $result['reason']);
        $this->assertSame($original, $result['collected_at']);
        $this->assertSame($original, $result['original_collected_at']);
        $this->assertSame($now, $result['stale_at']);
    }

    public function testErroTransitorioSemLastKnownNaoPreserva(): void
    {
        $collector = $this->makeCollector();
        $collector->setAdsFactory(static function (): object {
            return new class {
                public function getCampaigns(string $status = 'all'): array
                {
                    throw new \RuntimeException('pads_http_503');
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    return [];
                }

                public function getAdsItems(string $from, string $to, int $limit = 50): array
                {
                    return [];
                }
            };
        });
        $collector->setClockOverride(1_700_000_000);

        $result = $collector->collect(9999, false, false);

        $this->assertFalse($result['available']);
        $this->assertFalse($result['cached']);
        $this->assertSame('collector_error', $result['reason']);
        $this->assertGreaterThanOrEqual(1, $result['api_calls']);
    }

    public function testIsFreshRespeitaTtlDe5Min(): void
    {
        $collector = $this->makeCollector();
        $this->assertTrue($collector->isFresh(1000, 1000 + 299, 300));
        $this->assertFalse($collector->isFresh(1000, 1000 + 300, 300));
        $this->assertFalse($collector->isFresh(1000, 1000 + 301, 300));
    }

    public function testIsWithinMaxStaleAge(): void
    {
        $collector = $this->makeCollector(['ads_max_stale_age' => 3600]);
        $this->assertTrue($collector->isWithinMaxStaleAge(1000, 1000 + 3600, 3600));
        $this->assertFalse($collector->isWithinMaxStaleAge(1000, 1000 + 3601, 3600));
    }

    public function testForceRefreshIgnoraCache(): void
    {
        $collector = $this->makeCollector();
        $counter = new class {
            public int $n = 0;
        };
        $collector->setAdsFactory($this->throwingFactory($counter));

        $now = 1_700_000_000;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->goodPayload(), $now - 10);

        $result = $collector->collect(1335, false, true);

        $this->assertGreaterThanOrEqual(1, $counter->n);
        $this->assertTrue($result['stale']);
        $this->assertTrue($result['available']);
        $this->assertSame($now - 10, $result['collected_at']);
    }
}
