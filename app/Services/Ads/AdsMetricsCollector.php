<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Database;
use App\Services\AdsService;
use App\Services\Pregao\PregaoEmitService;
use PDO;
use Throwable;

/**
 * Coletor read-only de Product Ads.
 *
 * Persiste histórico diário, calcula ACOS/TACOS/ROAS/CPC.
 * Freshness padrão: 5 min — dentro da janela reutiliza last-known (positivo ou negativo, 0 GETs).
 * Full history só sob flag explícita (nunca no tick de 45s).
 * Falha transitória/429: preserva Ft se houver snapshot válido (stale), até ads_max_stale_age.
 * Nunca escreve na API do ML.
 */
final class AdsMetricsCollector
{
    /** Baseline inicial de TACOS (%) — documentado; recalculado semanalmente quando houver dados. */
    public const TACOS_BASELINE_INITIAL = 10.0;

    /** Janela de freshness do coletor no tick frequente (segundos). */
    public const FRESHNESS_TTL_SECONDS = 300;

    /** Idade máxima do snapshot para preservar Ft em falha transitória (segundos). */
    public const MAX_STALE_AGE_SECONDS = 3600;

    private const BATCH_SLEEP_US = 250000;
    private const MAX_RETRIES_TRANSIENT = 4;

    /** Janela de histórico diário (today … today-35) — exatamente 36 datas. */
    public const HISTORY_DAYS = 36;

    private PDO $db;
    private PregaoEmitService $emitter;
    private SkuCustoService $skuCustos;

    /** @var array<string, mixed> fonte única (config/pregao.php); injetável em testes */
    private array $config;

    /** @var callable(int): object|null factory injetável (testes) */
    private $adsFactory = null;

    private int $apiCallCount = 0;

    /** @var array<int, array{at: int, payload: array<string, mixed>}> cache em memória (testes / processo) */
    private array $memoryFreshness = [];

    private ?int $clockOverride = null;

    /**
     * @param array<string, mixed>|null $config config/pregao.php (ads_collect_freshness_ttl, ads_max_stale_age)
     */
    public function __construct(
        ?PDO $db = null,
        ?PregaoEmitService $emitter = null,
        ?SkuCustoService $skuCustos = null,
        ?array $config = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->emitter = $emitter ?? new PregaoEmitService($this->db);
        $this->skuCustos = $skuCustos ?? new SkuCustoService($this->db);
        $this->config = $config ?? (require dirname(__DIR__, 3) . '/config/pregao.php');
    }

    /**
     * @param callable(int): object $factory deve expor getCampaigns/getCampaignReport/getAdsItems
     */
    public function setAdsFactory(callable $factory): void
    {
        $this->adsFactory = $factory;
    }

    public function setClockOverride(?int $unixTs): void
    {
        $this->clockOverride = $unixTs;
    }

    public function getApiCallCount(): int
    {
        return $this->apiCallCount;
    }

    public function resetApiCallCount(): void
    {
        $this->apiCallCount = 0;
    }

    /**
     * Semeia last-known (positivo ou negativo; útil em testes sem DB/API).
     *
     * @param array<string, mixed> $payload
     */
    public function seedLastKnownGood(int $accountId, array $payload, ?int $collectedAt = null): void
    {
        $this->memoryFreshness[$accountId] = [
            'at' => $collectedAt ?? $this->now(),
            'payload' => $payload,
        ];
    }

    public function freshnessTtlSeconds(): int
    {
        return max(60, (int) ($this->config['ads_collect_freshness_ttl'] ?? self::FRESHNESS_TTL_SECONDS));
    }

    public function maxStaleAgeSeconds(): int
    {
        return max(60, (int) ($this->config['ads_max_stale_age'] ?? self::MAX_STALE_AGE_SECONDS));
    }

    public function isFresh(int $collectedAt, ?int $now = null, ?int $ttl = null): bool
    {
        $now ??= $this->now();
        $ttl ??= $this->freshnessTtlSeconds();
        return ($now - $collectedAt) < $ttl;
    }

    public function isWithinMaxStaleAge(int $collectedAt, ?int $now = null, ?int $maxAge = null): bool
    {
        $now ??= $this->now();
        $maxAge ??= $this->maxStaleAgeSeconds();
        return ($now - $collectedAt) <= $maxAge;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(int $accountId, bool $fullHistory = false, bool $forceRefresh = false): array
    {
        $this->apiCallCount = 0;

        if (!$this->tablesReady() && $this->adsFactory === null) {
            return [
                'ok' => false,
                'available' => false,
                'cached' => false,
                'stale' => false,
                'reason' => 'tables_missing',
                'tacos' => null,
                'acos' => null,
                'api_calls' => 0,
                'message' => 'rode php bin/ads-migrate-bloco5.php',
            ];
        }

        // Full history nunca no caminho de freshness — só sob flag explícita
        if (!$forceRefresh && !$fullHistory) {
            $known = $this->readLastKnown($accountId);
            if ($known !== null && $this->isFresh((int) $known['collected_at'])) {
                $payload = $known['payload'];
                return array_merge($payload, [
                    'ok' => (bool) ($payload['ok'] ?? true),
                    'cached' => true,
                    'stale' => false,
                    'api_calls' => 0,
                    'collected_at' => $known['collected_at'],
                    'reason' => $payload['reason'] ?? 'cache_hit',
                ]);
            }
        }

        try {
            $result = $this->collectFromApi($accountId, $fullHistory);
            $result['cached'] = false;
            $result['stale'] = false;
            $result['api_calls'] = $this->apiCallCount;
            $result['collected_at'] = $this->now();
            $this->rememberLastKnown($accountId, $result);
            return $result;
        } catch (Throwable $e) {
            log_warning('AdsMetricsCollector: falha', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return $this->preserveOrFail($accountId, $e);
        }
    }

    /**
     * Preserva Ft com snapshot válido dentro de max_stale_age; senão Ft indisponível.
     *
     * @return array<string, mixed>
     */
    private function preserveOrFail(int $accountId, Throwable $e): array
    {
        $known = $this->readLastKnown($accountId);
        $originalAt = $known !== null ? (int) $known['collected_at'] : 0;
        $staleAt = $this->now();

        if (
            $known !== null
            && !empty($known['payload']['available'])
            && $originalAt > 0
            && $this->isWithinMaxStaleAge($originalAt)
        ) {
            $preserved = $known['payload'];
            $this->markMetaStale($accountId, $e->getMessage(), $originalAt);
            return array_merge($preserved, [
                'ok' => false,
                'cached' => true,
                'stale' => true,
                'available' => true,
                'api_calls' => $this->apiCallCount,
                'collected_at' => $originalAt,
                'original_collected_at' => $originalAt,
                'stale_at' => $staleAt,
                'error' => $e->getMessage(),
                'reason' => 'transient_error_preserved',
                'message' => 'falha transitória — Ft preservada (stale)',
            ]);
        }

        if ($known !== null && !empty($known['payload']['available']) && $originalAt > 0) {
            $this->markFtUnavailable($accountId, 'max_stale_expired', $originalAt, $e->getMessage());
            return [
                'ok' => false,
                'available' => false,
                'cached' => false,
                'stale' => true,
                'reason' => 'max_stale_expired',
                'tacos' => null,
                'acos' => null,
                'api_calls' => $this->apiCallCount,
                'collected_at' => $originalAt,
                'original_collected_at' => $originalAt,
                'stale_at' => $staleAt,
                'error' => $e->getMessage(),
                'message' => 'snapshot stale além do máximo — Ft indisponível',
            ];
        }

        return [
            'ok' => false,
            'available' => false,
            'cached' => false,
            'stale' => false,
            'reason' => 'collector_error',
            'tacos' => null,
            'acos' => null,
            'api_calls' => $this->apiCallCount,
            'error' => $e->getMessage(),
        ];
    }

    /**
     * Busca/valida TODOS os payloads remotos em memória; persiste em transação curta sem I/O remoto.
     *
     * @return array<string, mixed>
     */
    private function collectFromApi(int $accountId, bool $fullHistory): array
    {
        $ads = $this->makeAdsClient($accountId);
        $campaignsPayload = $this->withBackoff(function () use ($ads) {
            $this->apiCallCount++;
            return $ads->getCampaigns('all');
        });

        // Erros HTTP / incomplete / cache-fallback por falha — nunca tratar como vazio legítimo
        $this->assertPadsPayloadOk($campaignsPayload, 'campaigns');
        $campaigns = $this->requireListEnvelope($campaignsPayload, 'campaigns', 'campaigns');

        // Docs: status active,paused — pausadas com gasto histórico entram no backfill
        $eligible = array_values(array_filter(
            $campaigns,
            static function ($c): bool {
                if (!is_array($c)) {
                    return false;
                }
                $st = strtolower((string) ($c['status'] ?? ''));
                return $st === 'active' || $st === 'paused';
            }
        ));
        $active = array_values(array_filter(
            $eligible,
            static fn (array $c): bool => strtolower((string) ($c['status'] ?? '')) === 'active'
        ));

        if ($eligible === []) {
            return $this->persistEmpty($accountId, 'nenhuma campanha');
        }

        $tz = new \DateTimeZone('America/Sao_Paulo');
        $today = new \DateTimeImmutable('today', $tz);
        $dates = $this->buildCollectDates($today, $fullHistory);

        // ——— FASE FETCH: só I/O remoto + montagem em memória (zero upserts) ———
        $campaignRows = [];
        $dayMetrics = [];

        foreach ($eligible as $campaign) {
            $campaignId = (string) ($campaign['id'] ?? '');
            if ($campaignId === '') {
                continue;
            }
            $budget = $this->extractBudget($campaign);
            $roasObj = $this->extractRoasObjetivo($campaign);
            $statusCamp = (string) ($campaign['status'] ?? 'active');

            foreach ($dates as $date) {
                $report = $this->withBackoff(function () use ($ads, $campaignId, $date) {
                    $this->apiCallCount++;
                    return $ads->getCampaignReport($campaignId, $date, $date);
                });
                $this->assertPadsPayloadOk($report, 'campaign_report');

                $metrics = $this->requireMapEnvelope($report, 'metrics', 'campaign_report.metrics');
                $gasto = (float) ($metrics['investment'] ?? 0);
                $receita = (float) ($metrics['revenue'] ?? 0);
                $clicks = (int) ($metrics['clicks'] ?? 0);
                $impressions = (int) ($metrics['impressions'] ?? 0);
                $sold = (int) ($metrics['sold_quantity'] ?? $metrics['conversions'] ?? 0);
                $cpc = $clicks > 0 ? round($gasto / $clicks, 4) : null;
                $acos = null;
                if ($receita > 0) {
                    $acos = round(($gasto / $receita) * 100, 4);
                }
                $roasReal = $gasto > 0 ? round($receita / $gasto, 4) : null;
                $rowBudget = $budget;
                $rowRoasObj = $roasObj;
                if (isset($report['roas_objetivo']) && is_numeric($report['roas_objetivo'])) {
                    $rowRoasObj = (float) $report['roas_objetivo'];
                }
                if (isset($report['daily_budget']) && is_numeric($report['daily_budget'])) {
                    $rowBudget = (float) $report['daily_budget'];
                }

                $campaignRows[] = [
                    'account_id' => $accountId,
                    'campaign_id' => $campaignId,
                    'date' => $date,
                    'status' => $statusCamp,
                    'orcamento_diario' => $rowBudget,
                    'roas_objetivo' => $rowRoasObj,
                    'gasto' => $gasto,
                    'impressoes' => $impressions,
                    'cliques' => $clicks,
                    'cpc_medio' => $cpc,
                    'vendas_atribuidas' => $sold,
                    'receita_atribuida' => $receita,
                    'acos' => $acos,
                    'roas_real' => $roasReal,
                    'data' => $campaign,
                ];

                if (!isset($dayMetrics[$date])) {
                    $dayMetrics[$date] = ['gasto' => 0.0, 'receita_atribuida' => 0.0];
                }
                $dayMetrics[$date]['gasto'] += $gasto;
                $dayMetrics[$date]['receita_atribuida'] += $receita;
            }

            $this->paceRemote();
        }

        // SKU: ads/search aggregation_type=DAILY oficial = date+metrics SEM item_id/campaign_id.
        // Histórico = exatamente N chamadas diárias em modo item (date_from=date_to), que retornam identidade.
        $skuRows = $this->fetchSkuRowsInMemory($ads, $accountId, $dates);

        $integrity = $this->assertHistoryIntegrity($dates, $campaignRows, $dayMetrics, count($eligible));

        // ——— FASE PERSIST: transação curta, sem I/O remoto ———
        $windows = $this->persistCollectedMetrics(
            $accountId,
            $today,
            $active,
            $campaignRows,
            $skuRows,
            $dayMetrics
        );

        $this->emitter->emit('metric.update', [
            'key' => 'tacos',
            'value' => $windows['tacos_atual'],
            'acos' => $windows['acos_atual'],
            'gasto_hoje' => $windows['gasto_hoje'],
            'flash' => 'green',
        ], $accountId, 'live');

        return [
            'ok' => true,
            'available' => $windows['tacos_atual'] !== null,
            'active_campaigns' => count($active),
            'eligible_campaigns' => count($eligible),
            'tacos' => $windows['tacos_atual'],
            'acos' => $windows['acos_atual'],
            'gasto_hoje' => $windows['gasto_hoje'],
            'tacos_baseline' => $windows['tacos_baseline'],
            'history_days' => count($dates),
            'history_coverage' => $integrity,
            'message' => null,
        ];
    }

    /**
     * Sinaliza dias faltantes/duplicados na janela coletada (fail-closed se incompleto).
     *
     * @param list<string> $expectedDates
     * @param list<array<string, mixed>> $campaignRows
     * @param array<string, array{gasto: float, receita_atribuida: float}> $dayMetrics
     * @return array{
     *   expected_days: int,
     *   campaign_days_ok: bool,
     *   account_days_ok: bool,
     *   missing_campaign_dates: list<string>,
     *   duplicate_campaign_keys: list<string>,
     *   missing_account_dates: list<string>
     * }
     */
    private function assertHistoryIntegrity(
        array         $expectedDates,
        array $campaignRows,
        array $dayMetrics,
        int $eligibleCount
    ): array {
        $seenKeys = [];
        $duplicateKeys = [];
        $campaignDates = [];

        foreach ($campaignRows as $row) {
            $date = (string) ($row['date'] ?? '');
            $cid = (string) ($row['campaign_id'] ?? '');
            $key = $cid . '|' . $date;
            if (isset($seenKeys[$key])) {
                $duplicateKeys[] = $key;
            }
            $seenKeys[$key] = true;
            if ($date !== '') {
                $campaignDates[$date] = true;
            }
        }

        $missingCampaign = [];
        foreach ($expectedDates as $date) {
            if (!isset($campaignDates[$date])) {
                $missingCampaign[] = $date;
            }
        }

        $missingAccount = [];
        foreach ($expectedDates as $date) {
            if (!isset($dayMetrics[$date])) {
                $missingAccount[] = $date;
            }
        }

        // Com campanhas elegíveis, cada data esperada deve ter ≥1 report e 1 agregado de conta.
        if ($eligibleCount > 0 && ($missingCampaign !== [] || $missingAccount !== [] || $duplicateKeys !== [])) {
            throw new \RuntimeException(sprintf(
                'pads_history_integrity: missing_campaign=%s missing_account=%s duplicates=%s',
                implode(',', $missingCampaign) ?: '-',
                implode(',', $missingAccount) ?: '-',
                implode(',', $duplicateKeys) ?: '-'
            ));
        }

        return [
            'expected_days' => count($expectedDates),
            'campaign_days_ok' => $missingCampaign === [] && $duplicateKeys === [],
            'account_days_ok' => $missingAccount === [],
            'missing_campaign_dates' => $missingCampaign,
            'duplicate_campaign_keys' => $duplicateKeys,
            'missing_account_dates' => $missingAccount,
        ];
    }

    /**
     * today (tick) ou today…today-35 — exatamente HISTORY_DAYS datas.
     *
     * @return list<string>
     */
    private function buildCollectDates(\DateTimeImmutable $today, bool $fullHistory): array
    {
        if (!$fullHistory) {
            return [$today->format('Y-m-d')];
        }

        $dates = [];
        for ($i = 0; $i < self::HISTORY_DAYS; $i++) {
            $dates[] = $today->modify("-{$i} days")->format('Y-m-d');
        }
        return $dates;
    }

    /**
     * @param object $ads
     * @param list<string> $dates
     * @return list<array<string, mixed>>
     */
    private function fetchSkuRowsInMemory(object $ads, int $accountId, array $dates): array
    {
        $skuRows = [];
        foreach ($dates as $date) {
            $adsItemsPayload = $this->withBackoff(function () use ($ads, $date) {
                $this->apiCallCount++;
                // Modo item + date_from=date_to → identidade (item_id/campaign_id); NÃO usar DAILY.
                return $ads->getAdsItems($date, $date, 50, 'item');
            });
            $this->assertPadsPayloadOk($adsItemsPayload, 'ads_items');
            $adsItems = $this->requireListEnvelope($adsItemsPayload, 'items', 'ads_items.items');

            foreach ($adsItems as $item) {
                $mlbId = strtoupper((string) ($item['item_id'] ?? ''));
                $campaignId = (string) ($item['campaign_id'] ?? '');
                // Date da chamada (date_from=date_to); item mode não exige date no wire.
                $rowDate = $date;
                if ($mlbId === '' || $campaignId === '' || $rowDate === '') {
                    throw new \RuntimeException('pads_invalid_wire_shape:missing_item_id_campaign_id_or_date');
                }

                $m = $this->requireMapEnvelope($item, 'metrics', 'ads_items.metrics');
                $gasto = (float) ($m['cost'] ?? 0);
                $receita = (float) ($m['total_amount'] ?? $m['direct_amount'] ?? 0);
                $clicks = (int) ($m['clicks'] ?? 0);
                $impressions = (int) ($m['prints'] ?? 0);
                $sold = (int) ($m['units_quantity'] ?? 0);
                $cpc = isset($m['cpc']) ? (float) $m['cpc'] : ($clicks > 0 ? round($gasto / $clicks, 4) : null);
                $acos = isset($m['acos']) ? (float) $m['acos'] : ($receita > 0 ? round(($gasto / $receita) * 100, 4) : null);
                $roasReal = isset($m['roas']) ? (float) $m['roas'] : ($gasto > 0 ? round($receita / $gasto, 4) : null);
                $trio = $this->skuCustos->roasTrio($accountId, $mlbId);
                $health = $this->lookupItemHealth($accountId, $mlbId);

                $skuRows[] = [
                    'account_id' => $accountId,
                    'campaign_id' => $campaignId,
                    'mlb_id' => $mlbId,
                    'date' => $rowDate,
                    'gasto' => $gasto,
                    'impressoes' => $impressions,
                    'cliques' => $clicks,
                    'cpc_medio' => $cpc,
                    'vendas_atribuidas' => $sold,
                    'receita_atribuida' => $receita,
                    'acos' => $acos,
                    'roas_real' => $roasReal,
                    'roas_objetivo' => $trio['roas_objetivo'] ?? null,
                    'health' => $health,
                ];
            }

            $this->paceRemote();
        }

        return $skuRows;
    }

    /**
     * @param list<array<string, mixed>> $active
     * @param list<array<string, mixed>> $campaignRows
     * @param list<array<string, mixed>> $skuRows
     * @param array<string, array{gasto: float, receita_atribuida: float}> $dayMetrics
     * @return array<string, mixed>
     */
    private function persistCollectedMetrics(
        int $accountId,
        \DateTimeImmutable $today,
        array $active,
        array $campaignRows,
        array $skuRows,
        array $dayMetrics
    ): array {
        $startedTxn = false;
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTxn = true;
            }

            foreach ($campaignRows as $row) {
                $this->upsertCampaignDay($row);
            }
            foreach ($skuRows as $row) {
                $this->upsertSkuDay($row);
            }

            foreach ($dayMetrics as $date => $agg) {
                $receitaTotal = $this->receitaTotalConta($accountId, (string) $date);
                $gasto = (float) $agg['gasto'];
                $recAttr = (float) $agg['receita_atribuida'];
                $acos = $recAttr > 0 ? round(($gasto / $recAttr) * 100, 4) : null;
                $tacos = $receitaTotal > 0 ? round(($gasto / $receitaTotal) * 100, 4) : null;

                $this->db->prepare(
                    'INSERT INTO ads_account_metrics_daily
                       (account_id, `date`, gasto, receita_atribuida, receita_total, acos, tacos, campanhas_ativas)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       gasto = VALUES(gasto),
                       receita_atribuida = VALUES(receita_atribuida),
                       receita_total = VALUES(receita_total),
                       acos = VALUES(acos),
                       tacos = VALUES(tacos),
                       campanhas_ativas = VALUES(campanhas_ativas)'
                )->execute([
                    $accountId,
                    $date,
                    $gasto,
                    $recAttr,
                    $receitaTotal,
                    $acos,
                    $tacos,
                    count($active),
                ]);
            }

            $windows = $this->computeWindows($accountId, $today);
            $this->persistIndexMetrics($accountId, $windows, count($active), false, null);

            if ($startedTxn) {
                $this->db->commit();
            }

            return $windows;
        } catch (Throwable $e) {
            if ($startedTxn && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function paceRemote(): void
    {
        if ($this->adsFactory === null) {
            usleep(self::BATCH_SLEEP_US);
        }
    }

    /**
     * Contrato unificado: ok=false / api_status 429|5xx / incomplete → throw (nunca persistir).
     *
     * @param array<string, mixed> $payload
     */
    private function assertPadsPayloadOk(array $payload, string $context): void
    {
        $status = $this->extractApiStatus($payload);
        if ($status === 429 || $status >= 500) {
            throw new \RuntimeException('pads_http_' . $status);
        }
        if (!empty($payload['incomplete']) || (($payload['error'] ?? null) === 'pagination_incomplete')) {
            throw new \RuntimeException('pads_pagination_incomplete:' . $context);
        }
        if (array_key_exists('ok', $payload) && $payload['ok'] === false) {
            $err = (string) ($payload['error'] ?? $payload['_meta']['api_error'] ?? $payload['_meta']['error'] ?? 'unknown');
            throw new \RuntimeException('pads_api_error:' . $err);
        }
        $metaSource = (string) ($payload['_meta']['data_source'] ?? '');
        $metaReason = (string) ($payload['_meta']['reason'] ?? '');
        if ($metaSource === 'local_cache' && in_array($metaReason, ['api_error', 'exception', 'pagination_incomplete'], true)) {
            throw new \RuntimeException('pads_api_error:' . (string) ($payload['_meta']['api_error'] ?? $payload['error'] ?? $metaReason));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function requireListEnvelope(array $payload, string $key, string $context): array
    {
        if (!array_key_exists($key, $payload) || !is_array($payload[$key]) || !array_is_list($payload[$key])) {
            throw new \RuntimeException('pads_invalid_wire_shape:' . $context);
        }

        $rows = [];
        foreach ($payload[$key] as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('pads_invalid_wire_shape:' . $context . '.row');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requireMapEnvelope(array $payload, string $key, string $context): array
    {
        if (!array_key_exists($key, $payload) || !is_array($payload[$key])) {
            throw new \RuntimeException('pads_invalid_wire_shape:' . $context);
        }
        return $payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractApiStatus(array $payload): ?int
    {
        foreach ([
            $payload['api_status'] ?? null,
            $payload['status'] ?? null,
            $payload['_meta']['api_status'] ?? null,
            $payload['_meta']['product_ads_status'] ?? null,
        ] as $candidate) {
            if ($candidate !== null && $candidate !== '' && is_numeric($candidate)) {
                return (int) $candidate;
            }
        }
        return null;
    }

    private function makeAdsClient(int $accountId): object
    {
        if ($this->adsFactory !== null) {
            return ($this->adsFactory)($accountId);
        }
        return new AdsService($accountId);
    }

    private function now(): int
    {
        return $this->clockOverride ?? time();
    }

    /**
     * Last-known positivo (TACOS) ou negativo (sem campanhas) — ambos cacheáveis por TTL.
     *
     * @return array{at: int, payload: array<string, mixed>, collected_at: int}|null
     */
    private function readLastKnown(int $accountId): ?array
    {
        if (isset($this->memoryFreshness[$accountId])) {
            $row = $this->memoryFreshness[$accountId];
            return [
                'at' => $row['at'],
                'collected_at' => $row['at'],
                'payload' => $row['payload'],
            ];
        }

        if (!$this->columnExists('account_index_metrics', 'metrics_meta')) {
            return null;
        }
        try {
            $stmt = $this->db->prepare('SELECT metrics_meta FROM account_index_metrics WHERE account_id = ?');
            $stmt->execute([$accountId]);
            $raw = $stmt->fetchColumn();
            $meta = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            if (!is_array($meta)) {
                return null;
            }
            $tacos = is_array($meta['metrics']['tacos'] ?? null) ? $meta['metrics']['tacos'] : [];
            $collectedAt = isset($tacos['collected_at']) ? (int) $tacos['collected_at'] : 0;
            if ($collectedAt <= 0) {
                return null;
            }
            // Aceita positivo e negativo (ex.: no_active_campaign) — ambos evitam martelar PADS
            $available = ($tacos['available'] ?? false) === true;
            $payload = [
                'ok' => $available || (($tacos['reason'] ?? '') !== 'collector_error'),
                'available' => $available,
                'active_campaigns' => (int) ($tacos['active_campaigns'] ?? 0),
                'tacos' => isset($tacos['value']) ? (float) $tacos['value'] : null,
                'acos' => isset($tacos['acos']) ? (float) $tacos['acos'] : null,
                'gasto_hoje' => isset($tacos['gasto_hoje']) ? (float) $tacos['gasto_hoje'] : null,
                'tacos_baseline' => isset($tacos['baseline']) ? (float) $tacos['baseline'] : self::TACOS_BASELINE_INITIAL,
                'message' => $tacos['message'] ?? null,
                'reason' => $tacos['reason'] ?? null,
            ];
            return [
                'at' => $collectedAt,
                'collected_at' => $collectedAt,
                'payload' => $payload,
            ];
        } catch (Throwable $e) {
            log_warning('AdsMetricsCollector: readLastKnown falhou', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function rememberLastKnown(int $accountId, array $result): void
    {
        $at = isset($result['collected_at']) ? (int) $result['collected_at'] : $this->now();
        $payload = [
            'ok' => (bool) ($result['ok'] ?? false),
            'available' => (bool) ($result['available'] ?? false),
            'active_campaigns' => (int) ($result['active_campaigns'] ?? 0),
            'tacos' => $result['tacos'] ?? null,
            'acos' => $result['acos'] ?? null,
            'gasto_hoje' => $result['gasto_hoje'] ?? null,
            'tacos_baseline' => $result['tacos_baseline'] ?? self::TACOS_BASELINE_INITIAL,
            'message' => $result['message'] ?? null,
            'reason' => $result['reason'] ?? null,
        ];
        $this->memoryFreshness[$accountId] = ['at' => $at, 'payload' => $payload];

        // Positivo e negativo: grava collected_at para freshness no tick
        $this->touchCollectedAt($accountId, $at, false, null, (bool) $payload['available'], $payload);
    }

    private function markMetaStale(int $accountId, string $error, int $originalCollectedAt): void
    {
        $this->touchCollectedAt(
            $accountId,
            $originalCollectedAt,
            true,
            $error,
            true,
            null
        );
    }

    private function markFtUnavailable(
        int $accountId,
        string $reason,
        int $originalCollectedAt,
        ?string $error
    ): void {
        unset($this->memoryFreshness[$accountId]);
        $this->touchCollectedAt(
            $accountId,
            $originalCollectedAt,
            true,
            $error ?? $reason,
            false,
            ['reason' => $reason, 'message' => 'max stale age excedido']
        );
    }

    /**
     * Atualiza metrics_meta.tacos freshness.
     * Em stale: NÃO altera collected_at para "agora"; grava stale_at e original_collected_at.
     *
     * @param array<string, mixed>|null $payloadPatch reason/message/active_campaigns etc.
     */
    private function touchCollectedAt(
        int $accountId,
        int $collectedAt,
        bool $stale,
        ?string $error,
        bool $available,
        ?array $payloadPatch = null
    ): void {
        if (!$this->columnExists('account_index_metrics', 'metrics_meta')) {
            return;
        }
        try {
            $stmt = $this->db->prepare('SELECT metrics_meta FROM account_index_metrics WHERE account_id = ?');
            $stmt->execute([$accountId]);
            $raw = $stmt->fetchColumn();
            $meta = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            if (!is_array($meta)) {
                $meta = [];
            }
            $tacos = is_array($meta['metrics']['tacos'] ?? null) ? $meta['metrics']['tacos'] : [];

            $meta['available']['Ft'] = $available;
            $tacos['available'] = $available;
            // Idade original imutável — caller passa collected_at original em stale
            $tacos['collected_at'] = $collectedAt;
            $tacos['stale'] = $stale;
            if ($stale) {
                $tacos['original_collected_at'] = $collectedAt;
                $tacos['stale_at'] = $this->now();
                if ($error !== null) {
                    $tacos['stale_error'] = $error;
                }
            } else {
                unset($tacos['stale_error'], $tacos['stale_at'], $tacos['original_collected_at']);
                $tacos['stale'] = false;
            }
            if (is_array($payloadPatch)) {
                if (array_key_exists('reason', $payloadPatch)) {
                    $tacos['reason'] = $payloadPatch['reason'];
                }
                if (array_key_exists('message', $payloadPatch)) {
                    $tacos['message'] = $payloadPatch['message'];
                }
                if (array_key_exists('active_campaigns', $payloadPatch)) {
                    $tacos['active_campaigns'] = (int) $payloadPatch['active_campaigns'];
                }
            }
            $meta['metrics']['tacos'] = $tacos;
            $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->prepare(
                'UPDATE account_index_metrics SET metrics_meta = ? WHERE account_id = ?'
            )->execute([$json, $accountId]);
        } catch (Throwable $e) {
            log_warning('AdsMetricsCollector: touchCollectedAt falhou', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{tacos_atual: float|null, acos_atual: float|null, gasto_hoje: float|null, tacos_baseline: float}
     */
    public function computeWindows(int $accountId, ?\DateTimeImmutable $today = null): array
    {
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $today = $today ?? new \DateTimeImmutable('today', $tz);

        // Janela atual: 7 dias completos (ontem ← 7 dias), sem sobreposição com o dia parcial de hoje
        $atualEnd = $today->modify('-1 day');
        $atualStart = $atualEnd->modify('-6 days');
        // Baseline: 28 dias terminando antes do início da janela atual
        $baseEnd = $atualStart->modify('-1 day');
        $baseStart = $baseEnd->modify('-27 days');

        $atual = $this->sumAccountWindow(
            $accountId,
            $atualStart->format('Y-m-d'),
            $atualEnd->format('Y-m-d')
        );
        $base = $this->sumAccountWindow(
            $accountId,
            $baseStart->format('Y-m-d'),
            $baseEnd->format('Y-m-d')
        );

        $hoje = $this->sumAccountWindow($accountId, $today->format('Y-m-d'), $today->format('Y-m-d'));

        $tacosAtual = null;
        if ($atual['receita_total'] > 0) {
            $tacosAtual = round(($atual['gasto'] / $atual['receita_total']) * 100, 4);
        } elseif ($atual['gasto'] <= 0 && $atual['rows'] > 0) {
            $tacosAtual = 0.0;
        }

        $acosAtual = null;
        if ($atual['receita_atribuida'] > 0) {
            $acosAtual = round(($atual['gasto'] / $atual['receita_atribuida']) * 100, 4);
        }

        $tacosBaseline = self::TACOS_BASELINE_INITIAL;
        if ($base['receita_total'] > 0) {
            $tacosBaseline = round(($base['gasto'] / $base['receita_total']) * 100, 4);
            $tacosBaseline = max($tacosBaseline, 0.1);
        }

        return [
            'tacos_atual' => $tacosAtual,
            'acos_atual' => $acosAtual,
            'gasto_hoje' => $hoje['rows'] > 0 ? round($hoje['gasto'], 2) : null,
            'tacos_baseline' => $tacosBaseline,
            'window_atual' => [$atualStart->format('Y-m-d'), $atualEnd->format('Y-m-d')],
            'window_baseline' => [$baseStart->format('Y-m-d'), $baseEnd->format('Y-m-d')],
        ];
    }

    /**
     * @return array{gasto: float, receita_atribuida: float, receita_total: float, rows: int}
     */
    private function sumAccountWindow(int $accountId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT
               COALESCE(SUM(gasto), 0) AS gasto,
               COALESCE(SUM(receita_atribuida), 0) AS receita_atribuida,
               COALESCE(SUM(receita_total), 0) AS receita_total,
               COUNT(*) AS row_count
             FROM ads_account_metrics_daily
             WHERE account_id = ? AND `date` BETWEEN ? AND ?'
        );
        $stmt->execute([$accountId, $from, $to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'gasto' => (float) ($row['gasto'] ?? 0),
            'receita_atribuida' => (float) ($row['receita_atribuida'] ?? 0),
            'receita_total' => (float) ($row['receita_total'] ?? 0),
            'rows' => (int) ($row['row_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $windows
     */
    private function persistIndexMetrics(
        int $accountId,
        array $windows,
        int $activeCount,
        bool $stale = false,
        ?string $staleError = null
    ): void {
        $tacos = $windows['tacos_atual'];
        $available = $tacos !== null;

        $this->db->prepare(
            'INSERT INTO account_index_metrics (account_id, tacos)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE tacos = VALUES(tacos)'
        )->execute([$accountId, $tacos ?? 0]);

        $this->db->prepare(
            'INSERT INTO account_index_baselines (account_id, tacos_baseline, recalculated_at)
             VALUES (?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
               tacos_baseline = VALUES(tacos_baseline),
               recalculated_at = CURRENT_TIMESTAMP'
        )->execute([$accountId, (float) $windows['tacos_baseline']]);

        if ($this->columnExists('account_index_metrics', 'metrics_meta')) {
            $stmt = $this->db->prepare('SELECT metrics_meta FROM account_index_metrics WHERE account_id = ?');
            $stmt->execute([$accountId]);
            $raw = $stmt->fetchColumn();
            $meta = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            if (!is_array($meta)) {
                $meta = [];
            }
            $meta['available']['Ft'] = $available;
            $meta['metrics']['tacos'] = [
                'available' => $available,
                'value' => $tacos,
                'acos' => $windows['acos_atual'],
                'gasto_hoje' => $windows['gasto_hoje'],
                'active_campaigns' => $activeCount,
                'baseline' => $windows['tacos_baseline'],
                'source' => 'ads_account_metrics_daily',
                'reason' => $available ? null : 'sem_dado_tacos',
                'collected_at' => $this->now(),
                'stale' => $stale,
            ];
            if ($stale && $staleError !== null) {
                $meta['metrics']['tacos']['stale_error'] = $staleError;
                $meta['metrics']['tacos']['stale_at'] = $this->now();
            }
            $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->prepare(
                'UPDATE account_index_metrics SET metrics_meta = ? WHERE account_id = ?'
            )->execute([$json, $accountId]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function persistEmpty(int $accountId, string $message): array
    {
        $collectedAt = $this->now();
        if ($this->columnExists('account_index_metrics', 'metrics_meta')) {
            $stmt = $this->db->prepare('SELECT metrics_meta FROM account_index_metrics WHERE account_id = ?');
            $stmt->execute([$accountId]);
            $raw = $stmt->fetchColumn();
            $meta = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            if (!is_array($meta)) {
                $meta = [];
            }
            $meta['available']['Ft'] = false;
            $meta['metrics']['tacos'] = [
                'available' => false,
                'active_campaigns' => 0,
                'reason' => 'no_active_campaign',
                'message' => $message,
                'collected_at' => $collectedAt,
                'stale' => false,
            ];
            $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->prepare(
                'INSERT INTO account_index_metrics (account_id, tacos, metrics_meta)
                 VALUES (?, 0, ?)
                 ON DUPLICATE KEY UPDATE tacos = 0, metrics_meta = VALUES(metrics_meta)'
            )->execute([$accountId, $json]);
        }

        $this->emitter->emit('metric.update', [
            'key' => 'tacos',
            'value' => null,
            'acos' => null,
            'gasto_hoje' => null,
            'message' => $message,
            'flash' => 'yellow',
        ], $accountId, 'live');

        return [
            'ok' => true,
            'available' => false,
            'active_campaigns' => 0,
            'tacos' => null,
            'acos' => null,
            'gasto_hoje' => null,
            'message' => $message,
            'reason' => 'no_active_campaign',
            'collected_at' => $collectedAt,
        ];
    }

    private function receitaTotalConta(int $accountId, string $date): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(total_amount), 0)
                 FROM ml_orders
                 WHERE ml_account_id = ?
                   AND DATE(date_created) = ?
                   AND (status IS NULL OR status NOT IN ('cancelled','canceled'))"
            );
            $stmt->execute([$accountId, $date]);
            return (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /**
     * @param array<string, mixed> $campaign
     */
    private function extractBudget(array $campaign): ?float
    {
        if (isset($campaign['budget']['daily_budget'])) {
            return (float) $campaign['budget']['daily_budget'];
        }
        if (isset($campaign['daily_budget'])) {
            return (float) $campaign['daily_budget'];
        }
        if (isset($campaign['budget']) && is_numeric($campaign['budget'])) {
            return (float) $campaign['budget'];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $campaign
     */
    private function extractRoasObjetivo(array $campaign): ?float
    {
        $candidates = [
            $campaign['target_roas'] ?? null,
            $campaign['roas_target'] ?? null,
            $campaign['bidding']['target_roas'] ?? null,
            $campaign['bidding_strategy']['target_roas'] ?? null,
            $campaign['budget']['target_roas'] ?? null,
        ];
        foreach ($candidates as $v) {
            if ($v !== null && is_numeric($v) && (float) $v > 0) {
                return (float) $v;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $campaign
     * @return list<string>
     */
    private function extractItemIds(array $campaign): array
    {
        $ids = [];
        $items = $campaign['items'] ?? $campaign['ads'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        foreach ($items as $item) {
            if (is_string($item) && preg_match('/^MLB\d+$/i', $item)) {
                $ids[] = strtoupper($item);
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['item_id'] ?? $item['id'] ?? $item['mlb_id'] ?? '');
            if (preg_match('/^MLB\d+$/i', $id)) {
                $ids[] = strtoupper($id);
            }
        }
        return array_values(array_unique($ids));
    }

    private function lookupItemHealth(int $accountId, string $mlbId): ?float
    {
        try {
            // tenta cache SEO / health por item se existir
            if ($this->tableExists('seo_analysis_cache')) {
                $stmt = $this->db->prepare(
                    'SELECT overall_score FROM seo_analysis_cache
                     WHERE account_id = ? AND item_id = ?
                     ORDER BY updated_at DESC LIMIT 1'
                );
                $stmt->execute([$accountId, $mlbId]);
                $score = $stmt->fetchColumn();
                if ($score !== false && $score !== null) {
                    $v = (float) $score;
                    return $v > 1.0 ? round($v / 100.0, 4) : round($v, 4);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertCampaignDay(array $row): void
    {
        $this->db->prepare(
            'INSERT INTO ads_campaign_metrics_daily
               (account_id, campaign_id, `date`, status, orcamento_diario, roas_objetivo,
                gasto, impressoes, cliques, cpc_medio, vendas_atribuidas, receita_atribuida,
                acos, roas_real, data)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               status = VALUES(status),
               orcamento_diario = VALUES(orcamento_diario),
               roas_objetivo = VALUES(roas_objetivo),
               gasto = VALUES(gasto),
               impressoes = VALUES(impressoes),
               cliques = VALUES(cliques),
               cpc_medio = VALUES(cpc_medio),
               vendas_atribuidas = VALUES(vendas_atribuidas),
               receita_atribuida = VALUES(receita_atribuida),
               acos = VALUES(acos),
               roas_real = VALUES(roas_real),
               data = VALUES(data)'
        )->execute([
            $row['account_id'],
            $row['campaign_id'],
            $row['date'],
            $row['status'],
            $row['orcamento_diario'],
            $row['roas_objetivo'],
            $row['gasto'],
            $row['impressoes'],
            $row['cliques'],
            $row['cpc_medio'],
            $row['vendas_atribuidas'],
            $row['receita_atribuida'],
            $row['acos'],
            $row['roas_real'],
            json_encode($row['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        // Mantém ads_metrics_history legado sincronizado
        try {
            $this->db->prepare(
                'INSERT INTO ads_metrics_history
                   (account_id, campaign_id, `date`, cost, revenue, clicks, impressions, conversions, data)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   cost = VALUES(cost),
                   revenue = VALUES(revenue),
                   clicks = VALUES(clicks),
                   impressions = VALUES(impressions),
                   conversions = VALUES(conversions)'
            )->execute([
                $row['account_id'],
                $row['campaign_id'],
                $row['date'],
                $row['gasto'],
                $row['receita_atribuida'],
                $row['cliques'],
                $row['impressoes'],
                $row['vendas_atribuidas'],
                json_encode(['source' => 'AdsMetricsCollector'], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            // tabela legada pode divergir
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertSkuDay(array $row): void
    {
        $this->db->prepare(
            'INSERT INTO ads_sku_metrics_daily
               (account_id, campaign_id, mlb_id, `date`, gasto, impressoes, cliques, cpc_medio,
                vendas_atribuidas, receita_atribuida, acos, roas_real, roas_objetivo, health)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               gasto = VALUES(gasto),
               impressoes = VALUES(impressoes),
               cliques = VALUES(cliques),
               cpc_medio = VALUES(cpc_medio),
               vendas_atribuidas = VALUES(vendas_atribuidas),
               receita_atribuida = VALUES(receita_atribuida),
               acos = VALUES(acos),
               roas_real = VALUES(roas_real),
               roas_objetivo = VALUES(roas_objetivo),
               health = VALUES(health)'
        )->execute([
            $row['account_id'],
            $row['campaign_id'],
            $row['mlb_id'],
            $row['date'],
            $row['gasto'],
            $row['impressoes'],
            $row['cliques'],
            $row['cpc_medio'],
            $row['vendas_atribuidas'],
            $row['receita_atribuida'],
            $row['acos'],
            $row['roas_real'],
            $row['roas_objetivo'],
            $row['health'],
        ]);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withBackoff(callable $fn): mixed
    {
        $attempt = 0;
        $delay = 500000;
        while (true) {
            $attempt++;
            try {
                $result = $fn();
            } catch (Throwable $e) {
                $retryStatus = $this->retryableStatusFromThrowable($e);
                if ($retryStatus === null) {
                    throw $e;
                }
                if ($attempt >= self::MAX_RETRIES_TRANSIENT) {
                    throw new \RuntimeException('pads_http_' . $retryStatus, 0, $e);
                }
                if ($this->adsFactory === null) {
                    usleep($delay);
                    $delay = min($delay * 2, 8000000);
                }
                continue;
            }

            if (!is_array($result)) {
                return $result;
            }

            $status = $this->extractApiStatus($result);
            $error = (string) ($result['error'] ?? $result['_meta']['api_error'] ?? $result['_meta']['error'] ?? '');
            $errorLower = strtolower($error);
            $retryStatus = null;
            if (
                $status === 429
                || str_contains($errorLower, 'too many')
                || str_contains($error, '429')
                || str_contains($errorLower, 'pads_http_429')
            ) {
                $retryStatus = 429;
            } elseif ($status !== null && $status >= 500 && $status <= 599) {
                $retryStatus = $status;
            }

            if ($retryStatus === null) {
                return $result;
            }
            if ($attempt >= self::MAX_RETRIES_TRANSIENT) {
                throw new \RuntimeException('pads_http_' . $retryStatus);
            }
            if ($this->adsFactory === null) {
                usleep($delay);
                $delay = min($delay * 2, 8000000);
            }
        }
    }

    private function retryableStatusFromThrowable(Throwable $error): ?int
    {
        if (preg_match('/pads_http_(429|5[0-9]{2})/', strtolower($error->getMessage()), $matches) !== 1) {
            return null;
        }
        return (int) $matches[1];
    }

    private function tablesReady(): bool
    {
        return $this->tableExists('ads_account_metrics_daily')
            && $this->tableExists('ads_campaign_metrics_daily');
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}
