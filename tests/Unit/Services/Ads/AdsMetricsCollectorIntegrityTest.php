<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ads;

use App\Services\Ads\AdsMetricsCollector;
use App\Services\Ads\SkuCustoService;
use App\Services\Pregao\PregaoEmitService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Integridade Ads/PADS: atomicidade, 35 datas, wire fail-closed, CLI sem pós-efeito.
 *
 * @covers \App\Services\Ads\AdsMetricsCollector
 */
class AdsMetricsCollectorIntegrityTest extends TestCase
{
    /** @var list<array{sql: string, params: mixed}> */
    private array $executions = [];

    private bool $inTxn = false;

    private ?string $failExecuteContaining = null;

    /**
     * @param array<string, mixed> $config
     */
    private function makeCollector(array $config = [], ?string $failExecuteContaining = null): AdsMetricsCollector
    {
        $this->executions = [];
        $this->inTxn = false;
        $this->failExecuteContaining = $failExecuteContaining;
        $pdo = $this->createRecordingPdo();
        $emitter = new PregaoEmitService($pdo);
        $sku = new SkuCustoService($pdo);

        return new AdsMetricsCollector($pdo, $emitter, $sku, array_merge([
            'ads_collect_freshness_ttl' => 300,
            'ads_max_stale_age' => 3600,
        ], $config));
    }

    private function createRecordingPdo(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(function () {
            $this->inTxn = true;
            $this->executions[] = ['sql' => 'BEGIN', 'params' => null];
            return true;
        });
        $pdo->method('commit')->willReturnCallback(function () {
            $this->inTxn = false;
            $this->executions[] = ['sql' => 'COMMIT', 'params' => null];
            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(function () {
            $this->inTxn = false;
            $this->executions[] = ['sql' => 'ROLLBACK', 'params' => null];
            return true;
        });
        $pdo->method('inTransaction')->willReturnCallback(fn (): bool => $this->inTxn);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function ($params = null) use ($sql) {
                if (
                    $this->inTxn
                    && $this->failExecuteContaining !== null
                    && stripos($sql, $this->failExecuteContaining) !== false
                ) {
                    throw new \RuntimeException('injected_execute_failure');
                }
                $this->executions[] = ['sql' => $sql, 'params' => $params];
                return true;
            });
            $stmt->method('fetch')->willReturn(false);
            $stmt->method('fetchAll')->willReturn([]);
            $stmt->method('fetchColumn')->willReturnCallback(function () use ($sql) {
                if (stripos($sql, 'information_schema.TABLES') !== false) {
                    return 1;
                }
                if (stripos($sql, 'information_schema.COLUMNS') !== false) {
                    return 0;
                }
                if (stripos($sql, 'SUM(total_amount)') !== false) {
                    return 0;
                }
                if (stripos($sql, 'FROM ads_account_metrics_daily') !== false) {
                    return 0;
                }
                return null;
            });
            return $stmt;
        });

        return $pdo;
    }

    /**
     * @return array<string, mixed>
     */
    private function goodPayload(): array
    {
        return [
            'ok' => true,
            'available' => true,
            'active_campaigns' => 1,
            'tacos' => 8.5,
            'acos' => 40.0,
            'gasto_hoje' => 2.0,
            'tacos_baseline' => 10.0,
            'message' => null,
            'reason' => null,
        ];
    }

    private function countUpserts(string $needle): int
    {
        $n = 0;
        foreach ($this->executions as $ex) {
            if (stripos($ex['sql'], $needle) !== false) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @return array{investment: float, revenue: float, clicks: int, impressions: int, conversions: int, sold_quantity: int}
     */
    private function okMetrics(): array
    {
        return [
            'investment' => 1.5,
            'revenue' => 10.0,
            'clicks' => 3,
            'impressions' => 100,
            'conversions' => 1,
            'sold_quantity' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $campaignsPayload
     * @param array<string, mixed> $reportPayload
     * @param array<string, mixed> $itemsPayload
     * @return callable(int): object
     */
    private function payloadFactory(array $campaignsPayload, array $reportPayload, array $itemsPayload): callable
    {
        return static function (int $requestedAccountId) use ($campaignsPayload, $reportPayload, $itemsPayload): object {
            return new class($campaignsPayload, $reportPayload, $itemsPayload, $requestedAccountId) {
                /** @var array<string, mixed> */
                private array $campaignsPayload;
                /** @var array<string, mixed> */
                private array $reportPayload;
                /** @var array<string, mixed> */
                private array $itemsPayload;
                private int $requestedAccountId;

                /**
                 * @param array<string, mixed> $campaignsPayload
                 * @param array<string, mixed> $reportPayload
                 * @param array<string, mixed> $itemsPayload
                 */
                public function __construct(
                    array $campaignsPayload,
                    array $reportPayload,
                    array $itemsPayload,
                    int $requestedAccountId
                ) {
                    $this->campaignsPayload = $campaignsPayload;
                    $this->reportPayload = $reportPayload;
                    $this->itemsPayload = $itemsPayload;
                    $this->requestedAccountId = $requestedAccountId;
                }

                public function getCampaigns(string $status = 'all'): array
                {
                    return $this->campaignsPayload;
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    return $this->reportPayload;
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    return $this->itemsPayload;
                }

                public function requestedAccountId(): int
                {
                    return $this->requestedAccountId;
                }
            };
        };
    }

    /** @return array<string, mixed> */
    private function validCampaignsPayload(): array
    {
        return [
            'ok' => true,
            'campaigns' => [['id' => 'contract-1', 'status' => 'active', 'budget' => 10]],
            'incomplete' => false,
            'api_status' => null,
            'error' => null,
            '_meta' => ['data_source' => 'mercadolivre_api_pads_v2'],
        ];
    }

    /** @return array<string, mixed> */
    private function validItemsPayload(): array
    {
        return [
            'ok' => true,
            'items' => [],
            'incomplete' => false,
            'api_status' => null,
            'error' => null,
            'aggregation_type' => 'item',
        ];
    }

    /** @return array<string, mixed> */
    private function validReportPayload(): array
    {
        return ['ok' => true, 'metrics' => $this->okMetrics()];
    }

    private function assertNoMetricUpserts(): void
    {
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_campaign_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_sku_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_account_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('BEGIN'));
    }

    /**
     * @dataProvider invalidCampaignEnvelopeProvider
     * @param array<string, mixed> $payload
     */
    public function testCampaignsEnvelopeAusenteOuInvalidoFalhaFechadoSemUpsert(array $payload): void
    {
        $collector = $this->makeCollector();
        $collector->setAdsFactory($this->payloadFactory(
            $payload,
            $this->validReportPayload(),
            $this->validItemsPayload()
        ));

        $result = $collector->collect(5101, false, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('pads_invalid_wire_shape:campaigns', (string) ($result['error'] ?? ''));
        $this->assertNoMetricUpserts();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public function invalidCampaignEnvelopeProvider(): array
    {
        return [
            'missing campaigns' => [['ok' => true]],
            'campaigns is not array' => [['ok' => true, 'campaigns' => 'invalid']],
        ];
    }

    /**
     * @dataProvider invalidReportMetricsProvider
     * @param array<string, mixed> $reportPayload
     */
    public function testReportMetricsAusenteOuInvalidoFalhaFechadoSemUpsert(array $reportPayload): void
    {
        $collector = $this->makeCollector();
        $collector->setAdsFactory($this->payloadFactory(
            $this->validCampaignsPayload(),
            $reportPayload,
            $this->validItemsPayload()
        ));

        $result = $collector->collect(5102, false, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('pads_invalid_wire_shape:campaign_report.metrics', (string) ($result['error'] ?? ''));
        $this->assertNoMetricUpserts();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public function invalidReportMetricsProvider(): array
    {
        return [
            'missing metrics' => [['ok' => true]],
            'metrics is not array' => [['ok' => true, 'metrics' => 'invalid']],
        ];
    }

    /**
     * @dataProvider invalidItemsEnvelopeProvider
     * @param array<string, mixed> $itemsPayload
     */
    public function testItemsEnvelopeAusenteOuInvalidoFalhaFechadoSemUpsert(array $itemsPayload): void
    {
        $collector = $this->makeCollector();
        $collector->setAdsFactory($this->payloadFactory(
            $this->validCampaignsPayload(),
            $this->validReportPayload(),
            $itemsPayload
        ));

        $result = $collector->collect(5103, false, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('pads_invalid_wire_shape:ads_items.items', (string) ($result['error'] ?? ''));
        $this->assertNoMetricUpserts();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public function invalidItemsEnvelopeProvider(): array
    {
        return [
            'missing items' => [['ok' => true]],
            'items is not array' => [['ok' => true, 'items' => 'invalid']],
        ];
    }

    /**
     * @dataProvider invalidSkuMetricsProvider
     * @param array<string, mixed> $item
     */
    public function testSkuMetricsAusenteOuInvalidoFalhaFechadoSemUpsert(array $item): void
    {
        $itemsPayload = $this->validItemsPayload();
        $itemsPayload['items'] = [$item];
        $collector = $this->makeCollector();
        $collector->setAdsFactory($this->payloadFactory(
            $this->validCampaignsPayload(),
            $this->validReportPayload(),
            $itemsPayload
        ));

        $result = $collector->collect(5104, false, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('pads_invalid_wire_shape:ads_items.metrics', (string) ($result['error'] ?? ''));
        $this->assertNoMetricUpserts();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public function invalidSkuMetricsProvider(): array
    {
        return [
            'missing metrics' => [['item_id' => 'MLB5104', 'campaign_id' => 'contract-1']],
            'metrics is not array' => [[
                'item_id' => 'MLB5104',
                'campaign_id' => 'contract-1',
                'metrics' => 'invalid',
            ]],
        ];
    }

    /**
     * @dataProvider retryableStatusProvider
     */
    public function test429E503TemRetryBoundedESucessoAposFalhaTransitoria(int $status): void
    {
        $attempts = new \stdClass();
        $attempts->count = 0;
        $collector = $this->makeCollector();
        $collector->setAdsFactory(function () use ($attempts, $status): object {
            return new class($attempts, $status, $this->validCampaignsPayload(), $this->validReportPayload(), $this->validItemsPayload()) {
                private object $attempts;
                private int $status;
                /** @var array<string, mixed> */
                private array $campaignsPayload;
                /** @var array<string, mixed> */
                private array $reportPayload;
                /** @var array<string, mixed> */
                private array $itemsPayload;

                /**
                 * @param array<string, mixed> $campaignsPayload
                 * @param array<string, mixed> $reportPayload
                 * @param array<string, mixed> $itemsPayload
                 */
                public function __construct(
                    object $attempts,
                    int $status,
                    array $campaignsPayload,
                    array $reportPayload,
                    array $itemsPayload
                ) {
                    $this->attempts = $attempts;
                    $this->status = $status;
                    $this->campaignsPayload = $campaignsPayload;
                    $this->reportPayload = $reportPayload;
                    $this->itemsPayload = $itemsPayload;
                }

                public function getCampaigns(string $status = 'all'): array
                {
                    $this->attempts->count++;
                    if ($this->attempts->count === 1) {
                        throw new \RuntimeException('pads_http_' . $this->status);
                    }
                    return $this->campaignsPayload;
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    return $this->reportPayload;
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    return $this->itemsPayload;
                }
            };
        });

        $result = $collector->collect(5201, false, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $attempts->count);
        $this->assertSame(4, $collector->getApiCallCount());
        $this->assertGreaterThan(0, $this->countUpserts('INSERT INTO ads_campaign_metrics_daily'));
    }

    /**
     * @dataProvider retryableStatusProvider
     */
    public function testExaustaoDe429E503PreservaLastKnownSemUpsert(int $status): void
    {
        $attempts = new \stdClass();
        $attempts->count = 0;
        $collector = $this->makeCollector();
        $now = 1_700_000_000;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(5202, $this->goodPayload(), $now - 400);
        $collector->setAdsFactory(static function () use ($attempts, $status): object {
            return new class($attempts, $status) {
                private object $attempts;
                private int $status;

                public function __construct(object $attempts, int $status)
                {
                    $this->attempts = $attempts;
                    $this->status = $status;
                }

                public function getCampaigns(string $status = 'all'): array
                {
                    $this->attempts->count++;
                    return [
                        'ok' => false,
                        'api_status' => $this->status,
                        'error' => 'transient_' . $this->status,
                    ];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    throw new \LogicException('not reached');
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    throw new \LogicException('not reached');
                }
            };
        });

        $result = $collector->collect(5202, false, true);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['available']);
        $this->assertTrue($result['stale']);
        $this->assertSame('transient_error_preserved', $result['reason']);
        $this->assertSame(4, $attempts->count);
        $this->assertSame(4, $result['api_calls']);
        $this->assertEqualsWithDelta(8.5, (float) $result['tacos'], 0.001);
        $this->assertNoMetricUpserts();
    }

    /** @return array<string, array{0: int}> */
    public function retryableStatusProvider(): array
    {
        return [
            'rate limited' => [429],
            'service unavailable' => [503],
        ];
    }

    public function testFalhaExecuteAposBeginFazRollbackSemCommit(): void
    {
        $collector = $this->makeCollector([], 'ads_campaign_metrics_daily');
        $collector->setAdsFactory($this->payloadFactory(
            $this->validCampaignsPayload(),
            $this->validReportPayload(),
            $this->validItemsPayload()
        ));

        $result = $collector->collect(5301, false, true);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $this->countUpserts('BEGIN'));
        $this->assertSame(1, $this->countUpserts('ROLLBACK'));
        $this->assertSame(0, $this->countUpserts('COMMIT'));
        $this->assertFalse($this->inTxn);
    }

    public function testTodosOsUpsertsUsamAccountIdSolicitado(): void
    {
        $requestedAccountId = 5302;
        $itemsPayload = $this->validItemsPayload();
        $itemsPayload['items'] = [[
            'item_id' => 'MLB5302',
            'campaign_id' => 'contract-1',
            'metrics' => ['cost' => 1.0, 'total_amount' => 3.0, 'clicks' => 1, 'prints' => 10, 'units_quantity' => 1],
        ]];
        $collector = $this->makeCollector();
        $collector->setAdsFactory($this->payloadFactory(
            $this->validCampaignsPayload(),
            $this->validReportPayload(),
            $itemsPayload
        ));

        $result = $collector->collect($requestedAccountId, false, true);

        $this->assertTrue($result['ok']);
        $expectedTables = [
            'ads_campaign_metrics_daily',
            'ads_metrics_history',
            'ads_sku_metrics_daily',
            'ads_account_metrics_daily',
            'account_index_metrics',
            'account_index_baselines',
        ];
        foreach ($expectedTables as $table) {
            $matching = array_values(array_filter(
                $this->executions,
                static fn (array $execution): bool => stripos($execution['sql'], 'INSERT INTO ' . $table) !== false
            ));
            $this->assertNotEmpty($matching, 'upsert não observado: ' . $table);
            foreach ($matching as $execution) {
                $this->assertIsArray($execution['params']);
                $this->assertSame($requestedAccountId, $execution['params'][0] ?? null, 'account_id incorreto: ' . $table);
            }
        }
    }

    public function testReportHttp429NaoPersisteZeroEPreservaLastKnown(): void
    {
        $collector = $this->makeCollector();
        $now = 1_700_000_000;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->goodPayload(), $now - 400);

        $collector->setAdsFactory(static function (): object {
            return new class {
                public function getCampaigns(string $status = 'all'): array
                {
                    return [
                        'ok' => true,
                        'campaigns' => [
                            ['id' => '100', 'name' => 'A', 'status' => 'active', 'budget' => 50],
                        ],
                        'incomplete' => false,
                        'api_status' => null,
                        'error' => null,
                        '_meta' => ['data_source' => 'mercadolivre_api_pads_v2'],
                    ];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    return [
                        'ok' => false,
                        'api_status' => 429,
                        'error' => 'too_many_requests',
                        'metrics' => null,
                        'campaign_id' => $id,
                    ];
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    return [
                        'ok' => true,
                        'items' => [],
                        'incomplete' => false,
                        'api_status' => null,
                        'error' => null,
                        'aggregation_type' => $aggregationType,
                    ];
                }
            };
        });

        $result = $collector->collect(1335, false, true);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['stale']);
        $this->assertTrue($result['available']);
        $this->assertSame('transient_error_preserved', $result['reason']);
        $this->assertEqualsWithDelta(8.5, (float) $result['tacos'], 0.001);
        $this->assertSame(0, $this->countUpserts('ads_campaign_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('ads_sku_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('ads_account_metrics_daily'));
    }

    public function testReportHttp503NaoPersisteEmpty(): void
    {
        $collector = $this->makeCollector();
        $collector->setClockOverride(1_700_000_000);
        $collector->setAdsFactory(static function (): object {
            return new class {
                public function getCampaigns(string $status = 'all'): array
                {
                    return [
                        'ok' => true,
                        'campaigns' => [
                            ['id' => '200', 'name' => 'B', 'status' => 'active', 'budget' => 10],
                        ],
                        '_meta' => ['data_source' => 'mercadolivre_api_pads_v2'],
                    ];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    return [
                        'ok' => false,
                        'api_status' => 503,
                        'error' => 'service_unavailable',
                        'metrics' => null,
                    ];
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    return ['ok' => true, 'items' => [], 'incomplete' => false, 'api_status' => null, 'error' => null, 'aggregation_type' => $aggregationType];
                }
            };
        });

        $result = $collector->collect(42, false, true);

        $this->assertFalse($result['ok']);
        $this->assertSame('collector_error', $result['reason']);
        $this->assertStringContainsString('pads_http_503', (string) ($result['error'] ?? ''));
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_campaign_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_account_metrics_daily'));
    }

    public function testAdsItemsErroTardioZeroUpsertsCampaignSkuAccount(): void
    {
        $collector = $this->makeCollector();
        $now = 1_700_000_000;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->goodPayload(), $now - 400);

        $collector->setAdsFactory(function (): object {
            $metrics = $this->okMetrics();
            return new class($metrics) {
                /** @var array<string, mixed> */
                private array $metrics;

                /** @param array<string, mixed> $metrics */
                public function __construct(array $metrics)
                {
                    $this->metrics = $metrics;
                }

                public function getCampaigns(string $status = 'all'): array
                {
                    return [
                        'ok' => true,
                        'campaigns' => [
                            ['id' => '300', 'name' => 'C', 'status' => 'active', 'budget' => 20],
                        ],
                        '_meta' => ['data_source' => 'mercadolivre_api_pads_v2'],
                    ];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    return [
                        'ok' => true,
                        'api_status' => null,
                        'error' => null,
                        'metrics' => $this->metrics,
                    ];
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    return [
                        'ok' => false,
                        'items' => [],
                        'incomplete' => false,
                        'api_status' => 429,
                        'error' => 'rate_limit',
                        'aggregation_type' => $aggregationType,
                    ];
                }
            };
        });

        $result = $collector->collect(1335, false, true);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['available']);
        $this->assertSame('transient_error_preserved', $result['reason']);
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_campaign_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_sku_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_account_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('BEGIN'));
    }

    public function testHistoryCobreTodayAteTodayMenos35EJanelasCompletas(): void
    {
        $collector = $this->makeCollector();
        $collector->setClockOverride(1_700_000_000);

        $seen = new \stdClass();
        $seen->reportDates = [];
        $seen->skuCalls = [];
        $seen->aggTypes = [];

        $collector->setAdsFactory(function () use ($seen): object {
            $metrics = $this->okMetrics();
            return new class($seen, $metrics) {
                private object $seen;
                /** @var array<string, mixed> */
                private array $metrics;

                /** @param array<string, mixed> $metrics */
                public function __construct(object $seen, array $metrics)
                {
                    $this->seen = $seen;
                    $this->metrics = $metrics;
                }

                public function getCampaigns(string $status = 'all'): array
                {
                    return [
                        'ok' => true,
                        'campaigns' => [
                            ['id' => '400', 'name' => 'Hist', 'status' => 'active', 'budget' => 30],
                        ],
                        '_meta' => ['data_source' => 'mercadolivre_api_pads_v2'],
                    ];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    $this->seen->reportDates[] = $from;
                    $this->assertSame($from, $to);
                    return [
                        'ok' => true,
                        'metrics' => $this->metrics,
                    ];
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    $this->seen->skuCalls[] = ['from' => $from, 'to' => $to];
                    $this->seen->aggTypes[] = $aggregationType;
                    return [
                        'ok' => true,
                        'items' => [
                            [
                                'item_id' => 'MLB111',
                                'campaign_id' => '400',
                                'metrics' => [
                                    'cost' => 1.0,
                                    'total_amount' => 5.0,
                                    'clicks' => 2,
                                    'prints' => 20,
                                    'units_quantity' => 1,
                                ],
                            ],
                        ],
                        'incomplete' => false,
                        'api_status' => null,
                        'error' => null,
                        'aggregation_type' => $aggregationType,
                    ];
                }

                private function assertSame(string $a, string $b): void
                {
                    if ($a !== $b) {
                        throw new \RuntimeException("expected date_from=date_to got {$a}/{$b}");
                    }
                }
            };
        });

        $result = $collector->collect(7, true, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(36, AdsMetricsCollector::HISTORY_DAYS);
        $this->assertSame(36, $result['history_days'] ?? null);
        $this->assertTrue($result['history_coverage']['campaign_days_ok'] ?? false);
        $this->assertTrue($result['history_coverage']['account_days_ok'] ?? false);
        $this->assertSame(36, $result['history_coverage']['expected_days'] ?? null);
        $this->assertCount(36, $seen->reportDates);
        $this->assertCount(36, array_unique($seen->reportDates));
        $this->assertCount(36, $seen->skuCalls);
        $this->assertSame(
            36,
            count(array_filter($seen->aggTypes, static fn ($t) => $t === 'item'))
        );
        $this->assertNotContains('DAILY', $seen->aggTypes);

        foreach ($seen->skuCalls as $call) {
            $this->assertSame($call['from'], $call['to']);
        }

        $tz = new \DateTimeZone('America/Sao_Paulo');
        $today = new \DateTimeImmutable('today', $tz);
        $expectedDates = [];
        for ($daysAgo = 0; $daysAgo <= 35; $daysAgo++) {
            $expectedDates[] = $today->modify("-{$daysAgo} days")->format('Y-m-d');
        }
        $this->assertSame($expectedDates, $seen->reportDates);

        $windowParams = [];
        foreach ($this->executions as $execution) {
            if (
                stripos($execution['sql'], 'FROM ads_account_metrics_daily') !== false
                && is_array($execution['params'])
                && count($execution['params']) === 3
            ) {
                $windowParams[] = $execution['params'];
            }
        }
        $this->assertContains([
            7,
            $today->modify('-7 days')->format('Y-m-d'),
            $today->modify('-1 day')->format('Y-m-d'),
        ], $windowParams);
        $this->assertContains([
            7,
            $today->modify('-35 days')->format('Y-m-d'),
            $today->modify('-8 days')->format('Y-m-d'),
        ], $windowParams);
        $this->assertContains([
            7,
            $today->format('Y-m-d'),
            $today->format('Y-m-d'),
        ], $windowParams);

        $this->assertSame(36, $this->countUpserts('INSERT INTO ads_sku_metrics_daily'));
        $this->assertSame(36, $this->countUpserts('INSERT INTO ads_campaign_metrics_daily'));
    }

    public function testWireShapeSemIdentidadeFailClosedNuncaSkip(): void
    {
        $collector = $this->makeCollector();
        $now = 1_700_000_000;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->goodPayload(), $now - 400);

        $collector->setAdsFactory(function (): object {
            $metrics = $this->okMetrics();
            return new class($metrics) {
                /** @var array<string, mixed> */
                private array $metrics;

                /** @param array<string, mixed> $metrics */
                public function __construct(array $metrics)
                {
                    $this->metrics = $metrics;
                }

                public function getCampaigns(string $status = 'all'): array
                {
                    return [
                        'ok' => true,
                        'campaigns' => [
                            ['id' => '500', 'name' => 'W', 'status' => 'active', 'budget' => 10],
                        ],
                        '_meta' => ['data_source' => 'mercadolivre_api_pads_v2'],
                    ];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    return ['ok' => true, 'metrics' => $this->metrics];
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    // Shape tipo DAILY (sem identidade) — deve falhar fechado
                    return [
                        'ok' => true,
                        'items' => [
                            [
                                'date' => $from,
                                'metrics' => ['cost' => 1.0, 'total_amount' => 2.0, 'clicks' => 1, 'prints' => 10],
                            ],
                        ],
                        'incomplete' => false,
                        'api_status' => null,
                        'error' => null,
                        'aggregation_type' => $aggregationType,
                    ];
                }
            };
        });

        $result = $collector->collect(1335, false, true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('pads_invalid_wire_shape', (string) ($result['error'] ?? ''));
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_campaign_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_sku_metrics_daily'));
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_account_metrics_daily'));
    }

    public function testPausedComHistoricoEntraNaColeta(): void
    {
        $collector = $this->makeCollector();
        $collector->setClockOverride(1_700_000_000);
        $reported = new \stdClass();
        $reported->ids = [];

        $collector->setAdsFactory(function () use ($reported): object {
            return new class($reported) {
                private object $reported;

                public function __construct(object $reported)
                {
                    $this->reported = $reported;
                }

                public function getCampaigns(string $status = 'all'): array
                {
                    return [
                        'ok' => true,
                        'campaigns' => [
                            ['id' => 'paused-1', 'name' => 'Old', 'status' => 'paused', 'budget' => 15],
                        ],
                        '_meta' => ['data_source' => 'mercadolivre_api_pads_v2'],
                    ];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    $this->reported->ids[] = $id;
                    return [
                        'ok' => true,
                        'metrics' => [
                            'investment' => 3.0,
                            'revenue' => 12.0,
                            'clicks' => 5,
                            'impressions' => 50,
                            'conversions' => 1,
                            'sold_quantity' => 1,
                        ],
                    ];
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    return [
                        'ok' => true,
                        'items' => [],
                        'incomplete' => false,
                        'api_status' => null,
                        'error' => null,
                        'aggregation_type' => $aggregationType,
                    ];
                }
            };
        });

        $result = $collector->collect(9, false, true);

        $this->assertTrue($result['ok']);
        $this->assertContains('paused-1', $reported->ids);
        $this->assertSame(0, $result['active_campaigns']);
        $this->assertSame(1, $result['eligible_campaigns']);
        $this->assertGreaterThanOrEqual(1, $this->countUpserts('INSERT INTO ads_campaign_metrics_daily'));
    }

    public function testPaginacaoIncompleteFailClosedSemPersistir(): void
    {
        $collector = $this->makeCollector();
        $now = 1_700_000_000;
        $collector->setClockOverride($now);
        $collector->seedLastKnownGood(1335, $this->goodPayload(), $now - 400);

        $collector->setAdsFactory(static function (): object {
            return new class {
                public function getCampaigns(string $status = 'all'): array
                {
                    return [
                        'ok' => false,
                        'campaigns' => [],
                        'incomplete' => true,
                        'api_status' => null,
                        'error' => 'pagination_incomplete',
                        '_meta' => [
                            'data_source' => 'local_cache',
                            'reason' => 'pagination_incomplete',
                            'incomplete' => true,
                        ],
                    ];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    return ['ok' => true, 'metrics' => ['investment' => 0, 'revenue' => 0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0, 'sold_quantity' => 0]];
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    return ['ok' => true, 'items' => [], 'incomplete' => false, 'api_status' => null, 'error' => null, 'aggregation_type' => $aggregationType];
                }
            };
        });

        $result = $collector->collect(1335, false, true);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['stale']);
        $this->assertSame('transient_error_preserved', $result['reason']);
        $this->assertSame(0, $this->countUpserts('INSERT INTO ads_campaign_metrics_daily'));
    }

    public function testCliDelegaOrquestracaoEExitCodeAoComandoTestavel(): void
    {
        $bin = file_get_contents(dirname(__DIR__, 4) . '/bin/ads-collect.php');
        $this->assertNotFalse($bin);
        $src = (string) $bin;

        $this->assertStringContainsString('use App\\Services\\Ads\\AdsCollectCommand;', $src);
        $this->assertStringContainsString('new AdsCollectCommand(', $src);
        $this->assertStringContainsString('$command->execute(', $src);
        $this->assertSame(2, substr_count($src, "exit(\$execution['exit_code'])"));
    }

    public function testTickSemFullHistoryUsaAggregationItemUmaData(): void
    {
        $collector = $this->makeCollector();
        $collector->setClockOverride(1_700_000_000);
        $seen = new \stdClass();
        $seen->type = null;
        $seen->skuCalls = 0;
        $seen->reportDates = [];

        $collector->setAdsFactory(function () use ($seen): object {
            return new class($seen) {
                private object $seen;

                public function __construct(object $seen)
                {
                    $this->seen = $seen;
                }

                public function getCampaigns(string $status = 'all'): array
                {
                    return [
                        'ok' => true,
                        'campaigns' => [
                            ['id' => '500', 'name' => 'Tick', 'status' => 'active', 'budget' => 10],
                        ],
                        '_meta' => ['data_source' => 'mercadolivre_api_pads_v2'],
                    ];
                }

                public function getCampaignReport(string $id, string $from, string $to): array
                {
                    $this->seen->reportDates[] = $from;
                    return [
                        'ok' => true,
                        'metrics' => [
                            'investment' => 0.1,
                            'revenue' => 1.0,
                            'clicks' => 1,
                            'impressions' => 5,
                            'conversions' => 0,
                            'sold_quantity' => 0,
                        ],
                    ];
                }

                public function getAdsItems(
                    string $from,
                    string $to,
                    int $limit = 50,
                    string $aggregationType = 'item'
                ): array {
                    $this->seen->type = $aggregationType;
                    $this->seen->skuCalls++;
                    return [
                        'ok' => true,
                        'items' => [],
                        'incomplete' => false,
                        'api_status' => null,
                        'error' => null,
                        'aggregation_type' => $aggregationType,
                    ];
                }
            };
        });

        $result = $collector->collect(11, false, true);
        $this->assertTrue($result['ok']);
        $this->assertSame('item', $seen->type);
        $this->assertSame(1, $seen->skuCalls);
        $this->assertCount(1, $seen->reportDates);
    }
}
