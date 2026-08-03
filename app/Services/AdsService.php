<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * AdsService - Serviço de Publicidade MercadoLivre
 *
 * Integração com API de Advertising do Mercado Livre
 * Gerencia campanhas, métricas e otimizações de Product Ads
 */
class AdsService extends MercadoLivreClient
{
    public const PADS_METRICS = 'clicks,prints,cost,cpc,acos,roas,direct_amount,indirect_amount,total_amount,units_quantity,direct_units_quantity,indirect_units_quantity';

    private PDO $db;

    /** @var array{advertiser_id:int,site_id:string}|null */
    private ?array $padsAdvertiser = null;

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    public function __construct(?int $accountId = null)
    {
        parent::__construct($accountId);
        $this->db = Database::getInstance();
    }

    /**
     * Resolve advertiser_id PADS (não confundir com seller/user id).
     *
     * @return array{advertiser_id: int, site_id: string}|null
     */
    public function resolvePadsAdvertiser(): ?array
    {
        if ($this->padsAdvertiser !== null) {
            return $this->padsAdvertiser;
        }

        $resp = $this->getWithHeaders(
            '/advertising/advertisers',
            ['product_id' => 'PADS'],
            ['Api-Version' => '1', 'api-version' => '1']
        );
        $list = $resp['advertisers'] ?? ($resp['body']['advertisers'] ?? []);
        if (!is_array($list) || $list === []) {
            return null;
        }
        $first = $list[0];
        if (!is_array($first) || empty($first['advertiser_id'])) {
            return null;
        }
        $this->padsAdvertiser = [
            'advertiser_id' => (int) $first['advertiser_id'],
            'site_id' => (string) ($first['site_id'] ?? 'MLB'),
        ];
        return $this->padsAdvertiser;
    }

    /**
     * Obtém todas as campanhas de publicidade da conta via API.
     * Paginação completa (paging.total) ou incomplete fail-closed — sem teto silencioso.
     * Status docs: active,paused (filters[status]).
     *
     * @return array{campaigns: list<array<string,mixed>>, _meta: array<string,mixed>, ok?: bool, incomplete?: bool, api_status?: int|null, error?: string|null}
     */
    public function getCampaigns(string $status = 'active'): array
    {
        $meta = [
            'data_source' => 'unknown',
            'fetched_at' => date('Y-m-d H:i:s'),
            'account_id' => $this->accountId,
            'incomplete' => false,
        ];

        try {
            if (!$this->ensureValidAccessToken()) {
                $campaigns = $this->getCachedCampaigns($status);
                $meta['data_source'] = 'local_cache';
                $meta['reason'] = 'missing_or_expired_token';
                return [
                    'ok' => false,
                    'campaigns' => $campaigns,
                    'incomplete' => false,
                    'api_status' => null,
                    'error' => 'missing_or_expired_token',
                    '_meta' => $meta,
                ];
            }

            $adv = $this->resolvePadsAdvertiser();
            if ($adv === null) {
                $campaigns = $this->getCachedCampaigns($status);
                $meta['data_source'] = 'local_cache';
                $meta['reason'] = 'pads_advertiser_not_found';
                return [
                    'ok' => false,
                    'campaigns' => $campaigns,
                    'incomplete' => false,
                    'api_status' => null,
                    'error' => 'pads_advertiser_not_found',
                    '_meta' => $meta,
                ];
            }

            $meta['advertiser_id'] = $adv['advertiser_id'];
            $meta['site_id'] = $adv['site_id'];

            $tz = new \DateTimeZone('America/Sao_Paulo');
            $to = (new \DateTimeImmutable('today', $tz))->format('Y-m-d');
            $from = (new \DateTimeImmutable('today', $tz))->modify('-34 days')->format('Y-m-d');

            $endpoint = sprintf(
                '/advertising/%s/advertisers/%d/product_ads/campaigns/search',
                $adv['site_id'],
                $adv['advertiser_id']
            );

            $statusFilter = $status === 'all' ? 'active,paused' : strtolower($status);
            $page = $this->paginatePadsSearch($endpoint, [
                'date_from' => $from,
                'date_to' => $to,
                'metrics' => self::PADS_METRICS,
                'filters[status]' => $statusFilter,
            ], 50);

            if (!$page['ok']) {
                $campaigns = $this->getCachedCampaigns($status);
                $meta['data_source'] = 'local_cache';
                $meta['reason'] = ($page['incomplete'] ?? false) ? 'pagination_incomplete' : 'api_error';
                $meta['api_error'] = $page['error'] ?? 'unknown';
                $meta['api_status'] = $page['api_status'] ?? null;
                $meta['incomplete'] = (bool) ($page['incomplete'] ?? false);
                return [
                    'ok' => false,
                    'campaigns' => $campaigns,
                    'incomplete' => (bool) ($page['incomplete'] ?? false),
                    'api_status' => $page['api_status'] ?? null,
                    'error' => $page['error'] ?? 'api_error',
                    '_meta' => $meta,
                ];
            }

            $campaigns = [];
            foreach ($page['results'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $campaigns[] = $this->normalizeCampaignRow($row);
            }

            if ($status !== 'all') {
                $want = strtolower($status);
                $campaigns = array_values(array_filter(
                    $campaigns,
                    static fn (array $c): bool => strtolower((string) ($c['status'] ?? '')) === $want
                ));
            }

            if ($campaigns !== []) {
                $this->cacheCampaigns($campaigns);
            }

            $meta['data_source'] = 'mercadolivre_api_pads_v2';
            $meta['api_endpoint'] = $endpoint;
            $meta['paging'] = $page['paging'] ?? null;
            return [
                'ok' => true,
                'campaigns' => $campaigns,
                'incomplete' => false,
                'api_status' => null,
                'error' => null,
                '_meta' => $meta,
            ];
        } catch (\Exception $e) {
            log_warning('Erro ao buscar campanhas de Ads', [
                'service' => 'AdsService',
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            $campaigns = $this->getCachedCampaigns($status);
            $meta['data_source'] = 'local_cache';
            $meta['reason'] = 'exception';
            $meta['error'] = $e->getMessage();
            return [
                'ok' => false,
                'campaigns' => $campaigns,
                'incomplete' => false,
                'api_status' => null,
                'error' => $e->getMessage(),
                '_meta' => $meta,
            ];
        }
    }

    /**
     * Métricas de campanha em um dia (ou janela) via PADS v2.
     * Em 429/5xx/erro: preserva api_status/error — nunca mascara como metrics zero.
     *
     * @return array<string, mixed>
     */
    public function getCampaignMetricsForDates(string $campaignId, string $dateFrom, string $dateTo): array
    {
        $adv = $this->resolvePadsAdvertiser();
        if ($adv === null) {
            return $this->padsMetricsFailure(
                'pads_advertiser_not_found',
                null,
                $campaignId,
                $dateFrom,
                $dateTo
            );
        }

        $endpoint = sprintf(
            '/advertising/%s/advertisers/%d/product_ads/campaigns/search',
            $adv['site_id'],
            $adv['advertiser_id']
        );
        $response = $this->getWithHeaders($endpoint, [
            'limit' => 50,
            'offset' => 0,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'metrics' => self::PADS_METRICS,
            'filters[campaign_ids]' => $campaignId,
            'filters[status]' => 'active,paused',
        ], ['api-version' => '2']);

        if ($this->isPadsHttpError($response)) {
            return $this->padsMetricsFailure(
                (string) ($response['message'] ?? $response['error'] ?? 'api_error'),
                isset($response['status']) ? (int) $response['status'] : null,
                $campaignId,
                $dateFrom,
                $dateTo
            );
        }

        if (!array_key_exists('results', $response) || !is_array($response['results']) || !array_is_list($response['results'])) {
            return $this->padsMetricsFailure(
                'pads_invalid_wire_shape:results',
                null,
                $campaignId,
                $dateFrom,
                $dateTo
            );
        }

        $found = null;
        foreach ($response['results'] as $row) {
            if (!is_array($row)) {
                return $this->padsMetricsFailure(
                    'pads_invalid_wire_shape:results.row',
                    null,
                    $campaignId,
                    $dateFrom,
                    $dateTo
                );
            }
            if ((string) ($row['id'] ?? '') === (string) $campaignId) {
                $found = $row;
                break;
            }
        }
        if ($found === null) {
            return array_merge($this->getEmptyReport(), [
                'ok' => true,
                'api_status' => null,
                'error' => null,
                'campaign_id' => $campaignId,
                'period' => ['from' => $dateFrom, 'to' => $dateTo],
            ]);
        }

        if (!array_key_exists('metrics', $found) || !is_array($found['metrics'])) {
            return $this->padsMetricsFailure(
                'pads_invalid_wire_shape:metrics',
                null,
                $campaignId,
                $dateFrom,
                $dateTo
            );
        }
        $m = $found['metrics'];
        $cost = (float) ($m['cost'] ?? 0);
        $revenue = (float) ($m['total_amount'] ?? $m['direct_amount'] ?? 0);
        $clicks = (int) ($m['clicks'] ?? 0);
        $impressions = (int) ($m['prints'] ?? $m['impressions'] ?? 0);
        $sold = (int) ($m['units_quantity'] ?? 0);

        return [
            'ok' => true,
            'api_status' => null,
            'error' => null,
            'campaign_id' => $campaignId,
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'metrics' => [
                'investment' => $cost,
                'revenue' => $revenue,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'conversions' => $sold,
                'sold_quantity' => $sold,
            ],
            'calculated_metrics' => [
                'acos' => isset($m['acos']) ? (float) $m['acos'] : ($revenue > 0 ? round(($cost / $revenue) * 100, 2) : null),
                'roas' => isset($m['roas']) ? (float) $m['roas'] : ($cost > 0 ? round($revenue / $cost, 2) : null),
                'cpc' => isset($m['cpc']) ? (float) $m['cpc'] : ($clicks > 0 ? round($cost / $clicks, 2) : null),
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            ],
            'roas_objetivo' => isset($found['roas_target']) ? (float) $found['roas_target'] : null,
            'acos_objetivo' => isset($found['acos_target']) ? (float) $found['acos_target'] : null,
            'daily_budget' => isset($found['budget']) ? (float) $found['budget'] : null,
            'status' => (string) ($found['status'] ?? ''),
            'raw' => $found,
            'daily_breakdown' => [],
        ];
    }

    /**
     * Anúncios (SKU) com métricas — PADS ads/search.
     * aggregation_type: item (padrão; retorna item_id/campaign_id) | DAILY (só date+metrics no wire oficial — sem identidade).
     * Para histórico SKU por dia com identidade, chamar modo item com date_from=date_to.
     * Paginação completa ou incomplete fail-closed — sem teto/truncamento silencioso.
     *
     * @return array{ok: bool, items: list<array<string,mixed>>, incomplete: bool, api_status: int|null, error: string|null, aggregation_type: string, paging?: array<string,mixed>|null}
     */
    public function getAdsItems(
        string $dateFrom,
        string $dateTo,
        int $limit = 50,
        string $aggregationType = 'item'
    ): array {
        $aggregationType = strtoupper($aggregationType) === 'DAILY' ? 'DAILY' : 'item';
        $adv = $this->resolvePadsAdvertiser();
        if ($adv === null) {
            return [
                'ok' => false,
                'items' => [],
                'incomplete' => false,
                'api_status' => null,
                'error' => 'pads_advertiser_not_found',
                'aggregation_type' => $aggregationType,
                'paging' => null,
            ];
        }

        $endpoint = sprintf(
            '/advertising/%s/advertisers/%d/product_ads/ads/search',
            $adv['site_id'],
            $adv['advertiser_id']
        );

        $query = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'metrics' => self::PADS_METRICS,
            'filters[statuses]' => 'active,paused',
        ];
        if ($aggregationType === 'DAILY') {
            $query['aggregation_type'] = 'DAILY';
        }

        $page = $this->paginatePadsSearch($endpoint, $query, max(1, $limit));
        if (!$page['ok']) {
            return [
                'ok' => false,
                'items' => [],
                'incomplete' => (bool) ($page['incomplete'] ?? false),
                'api_status' => $page['api_status'] ?? null,
                'error' => $page['error'] ?? 'api_error',
                'aggregation_type' => $aggregationType,
                'paging' => $page['paging'] ?? null,
            ];
        }

        $items = [];
        foreach ($page['results'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->normalizeAdsSearchRow($row, $aggregationType);
        }

        return [
            'ok' => true,
            'items' => $items,
            'incomplete' => false,
            'api_status' => null,
            'error' => null,
            'aggregation_type' => $aggregationType,
            'paging' => $page['paging'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeCampaignRow(array $row): array
    {
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
        return [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'unknown'),
            'budget' => [
                'daily_budget' => (float) ($row['budget'] ?? 0),
            ],
            'daily_budget' => (float) ($row['budget'] ?? 0),
            'type' => 'product_ad',
            'roas_target' => isset($row['roas_target']) ? (float) $row['roas_target'] : null,
            'acos_target' => isset($row['acos_target']) ? (float) $row['acos_target'] : null,
            'target_roas' => isset($row['roas_target']) ? (float) $row['roas_target'] : null,
            'strategy' => $row['strategy'] ?? null,
            'metrics' => $metrics,
            'date_created' => $row['date_created'] ?? null,
            'last_updated' => $row['last_updated'] ?? null,
            'items' => [],
        ];
    }

    /**
     * Paginação PADS completa (offset/limit/total) ou incomplete fail-closed.
     *
     * @param array<string, mixed> $baseQuery
     * @return array{ok: bool, results: list<array<string,mixed>>, incomplete: bool, api_status: int|null, error: string|null, paging: array<string,mixed>|null}
     */
    private function paginatePadsSearch(string $endpoint, array $baseQuery, int $limit): array
    {
        $all = [];
        $offset = 0;
        $total = null;
        $lastPaging = null;
        $limit = max(1, $limit);

        while (true) {
            $query = array_merge($baseQuery, [
                'limit' => $limit,
                'offset' => $offset,
            ]);
            $response = $this->getWithHeaders($endpoint, $query, ['api-version' => '2']);

            if ($this->isPadsHttpError($response)) {
                return [
                    'ok' => false,
                    'results' => [],
                    'incomplete' => $all !== [],
                    'api_status' => isset($response['status']) ? (int) $response['status'] : null,
                    'error' => (string) ($response['message'] ?? $response['error'] ?? 'api_error'),
                    'paging' => is_array($response['paging'] ?? null) ? $response['paging'] : $lastPaging,
                ];
            }

            if (!array_key_exists('results', $response) || !is_array($response['results']) || !array_is_list($response['results'])) {
                return [
                    'ok' => false,
                    'results' => [],
                    'incomplete' => $all !== [],
                    'api_status' => null,
                    'error' => 'pads_invalid_wire_shape:results',
                    'paging' => is_array($response['paging'] ?? null) ? $response['paging'] : $lastPaging,
                ];
            }
            $batch = $response['results'];
            foreach ($batch as $row) {
                if (!is_array($row)) {
                    return [
                        'ok' => false,
                        'results' => [],
                        'incomplete' => $all !== [],
                        'api_status' => null,
                        'error' => 'pads_invalid_wire_shape:results.row',
                        'paging' => is_array($response['paging'] ?? null) ? $response['paging'] : $lastPaging,
                    ];
                }
                $all[] = $row;
            }

            $paging = is_array($response['paging'] ?? null) ? $response['paging'] : [];
            $lastPaging = $paging;
            $pageTotal = isset($paging['total']) ? (int) $paging['total'] : null;
            if ($pageTotal !== null) {
                $total = $pageTotal;
            }

            $batchCount = count($batch);
            if ($batchCount === 0) {
                // Sem paging.total e sem resultados → fim legítimo
                if ($total === null || $offset >= $total) {
                    break;
                }
                // paging.total indica mais páginas, mas veio vazio → incomplete
                return [
                    'ok' => false,
                    'results' => [],
                    'incomplete' => true,
                    'api_status' => null,
                    'error' => 'pagination_incomplete',
                    'paging' => $lastPaging,
                ];
            }

            $offset += $limit;
            if ($total !== null && $offset >= $total) {
                break;
            }
            // Sem total: se batch < limit, última página
            if ($total === null && $batchCount < $limit) {
                break;
            }

            usleep(200000);
        }

        if ($total !== null && count($all) < $total) {
            return [
                'ok' => false,
                'results' => [],
                'incomplete' => true,
                'api_status' => null,
                'error' => 'pagination_incomplete',
                'paging' => $lastPaging,
            ];
        }

        return [
            'ok' => true,
            'results' => $all,
            'incomplete' => false,
            'api_status' => null,
            'error' => null,
            'paging' => $lastPaging ?? ['offset' => 0, 'total' => count($all), 'limit' => $limit],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function isPadsHttpError(array $response): bool
    {
        if (isset($response['error']) && $response['error'] !== '' && $response['error'] !== null) {
            // Respostas PADS OK não trazem "error"; erros ML/client trazem.
            return true;
        }
        if (isset($response['status']) && (int) $response['status'] >= 400) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed>|null $extraMeta
     * @return array<string, mixed>
     */
    private function padsMetricsFailure(
        string $error,
        ?int $apiStatus,
        string $campaignId,
        string $dateFrom,
        string $dateTo,
        ?array $extraMeta = null
    ): array {
        $payload = [
            'ok' => false,
            'api_status' => $apiStatus,
            'error' => $error,
            'campaign_id' => $campaignId,
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            // Sem metrics zero enganosas — consumidor deve checar ok/api_status
            'metrics' => null,
            'calculated_metrics' => null,
            'daily_breakdown' => [],
        ];
        if ($extraMeta !== null) {
            $payload['_meta'] = $extraMeta;
        }
        return $payload;
    }

    /**
     * Normaliza linha ads/search (item ou DAILY flat).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeAdsSearchRow(array $row, string $aggregationType): array
    {
        if (strtoupper($aggregationType) !== 'DAILY') {
            return $row;
        }

        if (!isset($row['metrics']) || !is_array($row['metrics'])) {
            $metricKeys = [
                'clicks', 'prints', 'cost', 'cpc', 'acos', 'roas',
                'direct_amount', 'indirect_amount', 'total_amount',
                'units_quantity', 'direct_units_quantity', 'indirect_units_quantity',
                'ctr', 'cvr', 'sov',
            ];
            $metrics = [];
            foreach ($metricKeys as $key) {
                if (array_key_exists($key, $row)) {
                    $metrics[$key] = $row[$key];
                }
            }
            if ($metrics !== []) {
                $row['metrics'] = $metrics;
            }
        }

        return $row;
    }

    /**
     * Obtém métricas de uma campanha específica em um período.
     *
     * Encapsula getCampaignReport e adiciona metadados de execução.
     */
    public function getCampaignMetrics(string $campaignId, string $period = 'last_30_days'): array
    {
        $dateFrom = $this->getDateFromPeriod($period);
        $dateTo = date('Y-m-d');

        $meta = [
            'fetched_at' => date('Y-m-d H:i:s'),
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'account_id' => $this->accountId,
        ];

        try {
            $report = $this->getCampaignMetricsForDates($campaignId, $dateFrom, $dateTo);
            if (($report['ok'] ?? true) === false) {
                $meta['api_status'] = $report['api_status'] ?? null;
                $meta['error'] = $report['error'] ?? 'api_error';
            }
            return array_merge($report, ['_meta' => $meta]);
        } catch (\Exception $e) {
            log_warning('Erro ao obter métricas de campanha', [
                'service' => 'AdsService',
                'campaign_id' => $campaignId,
                'period' => $period,
                'error' => $e->getMessage(),
            ]);

            $meta['error'] = $e->getMessage();
            return $this->padsMetricsFailure(
                $e->getMessage(),
                null,
                $campaignId,
                $dateFrom,
                $dateTo,
                $meta
            );
        }
    }

    /**
     * Obtém relatório detalhado de campanha.
     * Erros HTTP/API preservam api_status/error — não mascaram como zeros/cache.
     */
    public function getCampaignReport(string $campaignId, string $dateFrom, string $dateTo): array
    {
        try {
            $report = $this->getCampaignMetricsForDates($campaignId, $dateFrom, $dateTo);
            if (($report['ok'] ?? true) === false) {
                return $report;
            }
            if (((float) ($report['metrics']['investment'] ?? 0)) > 0
                || ((float) ($report['metrics']['revenue'] ?? 0)) > 0
                || ((int) ($report['metrics']['clicks'] ?? 0)) > 0
            ) {
                $this->cacheReportMetrics($campaignId, [
                    'total' => [
                        'cost' => $report['metrics']['investment'] ?? 0,
                        'amount' => $report['metrics']['revenue'] ?? 0,
                        'clicks' => $report['metrics']['clicks'] ?? 0,
                        'impressions' => $report['metrics']['impressions'] ?? 0,
                        'conversions' => $report['metrics']['conversions'] ?? 0,
                    ],
                ]);
            }
            return $report;
        } catch (\Exception $e) {
            log_warning('Erro ao obter relatório de campanha', [
                'service' => 'AdsService',
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
            return $this->padsMetricsFailure(
                $e->getMessage(),
                null,
                $campaignId,
                $dateFrom,
                $dateTo
            );
        }
    }

    /**
     * Cria nova campanha de Product Ads
     */
    public function createCampaign(array $data): array
    {
        try {
            $payload = [
                'name' => $data['name'],
                'type' => $data['type'] ?? 'product_ad',
                'status' => $data['status'] ?? 'paused',
                'budget' => [
                    'daily_budget' => $data['daily_budget'] ?? 50.00,
                    'type' => $data['budget_type'] ?? 'daily',
                ],
                'items' => $data['items'] ?? [],
                'bidding_strategy' => $data['bidding_strategy'] ?? 'automatic',
            ];

            $response = $this->post("/advertising/campaigns", $payload);

            if (isset($response['error'])) {
                return ['success' => false, 'error' => $response['message'] ?? 'Erro ao criar campanha'];
            }

            // Salvar campanha criada localmente
            $this->saveCampaignLocal($response);

            return [
                'success' => true,
                'campaign_id' => $response['id'] ?? null,
                'status' => $response['status'] ?? 'paused',
                'details' => $response,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Atualiza uma campanha (wrapper para status/budget).
     *
     * Campos suportados:
     * - status: string
     * - daily_budget: float|int|string numérico
     */
    public function updateCampaign(string $campaignId, array $data): array
    {
        $results = [];
        $errors = [];

        if (array_key_exists('daily_budget', $data)) {
            $budgetValue = $data['daily_budget'];
            $newBudget = is_numeric($budgetValue) ? (float)$budgetValue : null;

            if ($newBudget === null) {
                $errors[] = 'invalid_daily_budget';
            } else {
                $results['budget'] = $this->updateCampaignBudget($campaignId, $newBudget);
                if (($results['budget']['success'] ?? false) !== true) {
                    $errors[] = 'budget_update_failed';
                }
            }
        }

        if (array_key_exists('status', $data)) {
            $statusValue = $data['status'];
            $status = is_string($statusValue) ? $statusValue : null;

            if ($status === null || $status === '') {
                $errors[] = 'invalid_status';
            } else {
                $results['status'] = $this->updateCampaignStatus($campaignId, $status);
                if (($results['status']['success'] ?? false) !== true) {
                    $errors[] = 'status_update_failed';
                }
            }
        }

        return [
            'success' => empty($errors),
            'campaign_id' => $campaignId,
            'results' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Atualiza orçamento de campanha
     */
    public function updateCampaignBudget(string $campaignId, float $newBudget): array
    {
        try {
            $payload = [
                'budget' => [
                    'daily_budget' => $newBudget,
                    'type' => 'daily',
                ],
            ];

            $response = $this->put("/advertising/campaigns/{$campaignId}/budget", $payload);

            if (isset($response['error'])) {
                return ['success' => false, 'error' => $response['message'] ?? 'Erro ao atualizar orçamento'];
            }

            // Atualizar cache local
            $this->updateCampaignLocalBudget($campaignId, $newBudget);

            return [
                'success' => true,
                'campaign_id' => $campaignId,
                'new_budget' => $newBudget,
                'details' => $response,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pausa/ativa campanha
     */
    public function updateCampaignStatus(string $campaignId, string $status): array
    {
        try {
            $payload = ['status' => $status];
            $response = $this->put("/advertising/campaigns/{$campaignId}/status", $payload);

            if (isset($response['error'])) {
                return ['success' => false, 'error' => $response['message'] ?? 'Erro ao atualizar status'];
            }

            // Atualizar cache local
            $this->updateCampaignLocalStatus($campaignId, $status);

            return [
                'success' => true,
                'campaign_id' => $campaignId,
                'status' => $status,
                'details' => $response,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtém bonificações disponíveis para Product Ads
     */
    public function getAvailableBonuses(): array
    {
        try {
            $userId = $this->getSellerId();
            if (!$userId) {
                return ['total' => 0, 'bonuses' => []];
            }
            $response = $this->get("/advertising/bonuses", ['seller_id' => $userId]);

            if (isset($response['error'])) {
                return ['total' => 0, 'bonuses' => []];
            }

            return [
                'total' => count($response['results'] ?? []),
                'bonuses' => $this->formatBonuses($response['results'] ?? []),
            ];
        } catch (\Exception $e) {
            log_warning('Erro ao obter bonificações de Ads', [
                'service' => 'AdsService',
                'error' => $e->getMessage(),
            ]);
            return ['total' => 0, 'bonuses' => []];
        }
    }

    /**
     * Obtém sugestões de lance (bid) para itens
     */
    public function getBidSuggestions(array $itemIds): array
    {
        $suggestions = [];

        foreach ($itemIds as $itemId) {
            try {
                $response = $this->get("/advertising/items/{$itemId}/bid_suggestions");

                $suggestions[$itemId] = [
                    'suggested_bid' => $response['suggested_bid'] ?? 0,
                    'min_bid' => $response['min_bid'] ?? 0,
                    'max_bid' => $response['max_bid'] ?? 0,
                    'competitive_range' => $response['competitive_range'] ?? [],
                ];
            } catch (\Exception $e) {
                $suggestions[$itemId] = [
                    'suggested_bid' => 0,
                    'min_bid' => 0,
                    'max_bid' => 0,
                    'competitive_range' => [],
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Obtém métricas gerais (ROAS, ACOS, Investimento)
     * Implementação real com dados do banco e API
     * Retorna array com métricas e '_meta' indicando fonte dos dados
     */
    public function getMetrics(string $period = 'last_30_days'): array
    {
        $meta = [
            'data_source' => 'unknown',
            'fetched_at' => date('Y-m-d H:i:s'),
            'period' => $period,
        ];

        try {
            $metrics = [
                'investment' => 0.0,
                'revenue' => 0.0,
                'clicks' => 0,
                'impressions' => 0,
                'acos' => 0,
                'roas' => 0,
                'cpc' => 0,
                'ctr' => 0
            ];

            // 1. Verificar se temos token válido
            $apiAvailable = $this->ensureValidAccessToken();

            // 2. Tentar obter da API de reports
            $dateFrom = $this->getDateFromPeriod($period);
            $dateTo = date('Y-m-d');
            $apiSuccess = false;

            if ($apiAvailable) {
                try {
                    $userId = $this->getSellerId();
                    if (!$userId) {
                        throw new \RuntimeException('seller_id_not_found');
                    }

                    // Tentativa 1: Product Ads (quando disponível)
                    $productAdsEndpoint = "/advertising/advertisers/{$userId}/product_ads/reports";
                    $productAdsReport = $this->get($productAdsEndpoint, [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'metrics' => 'cost,clicks,impressions,sold_quantity,amount'
                    ]);

                    if (!isset($productAdsReport['error'])) {
                        $total = $productAdsReport['total'] ?? null;
                        if (!is_array($total) && isset($productAdsReport['body']['total']) && is_array($productAdsReport['body']['total'])) {
                            $total = $productAdsReport['body']['total'];
                        }
                        if (is_array($total)) {
                            $metrics['investment'] = (float)($total['cost'] ?? 0);
                            $metrics['revenue'] = (float)($total['amount'] ?? 0);
                            $metrics['clicks'] = (int)($total['clicks'] ?? 0);
                            $metrics['impressions'] = (int)($total['impressions'] ?? 0);
                            $apiSuccess = true;
                            $meta['data_source'] = 'mercadolivre_api_product_ads';
                            $meta['api_endpoint'] = $productAdsEndpoint;
                        }
                    } else {
                        $meta['product_ads_error'] = $productAdsReport['message'] ?? ($productAdsReport['error'] ?? 'unknown');
                        if (isset($productAdsReport['status'])) {
                            $meta['product_ads_status'] = (int)$productAdsReport['status'];
                        }
                    }

                    // Tentativa 2: endpoint legado
                    $report = $this->get("/advertising/reports", [
                        'user_id' => $userId,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'metrics' => 'cost,clicks,impressions,sold_quantity,amount'
                    ]);

                    if (!$apiSuccess && !isset($report['error'])) {
                        $total = $report['total'] ?? null;
                        if (!is_array($total) && isset($report['body']['total']) && is_array($report['body']['total'])) {
                            $total = $report['body']['total'];
                        }
                        if (is_array($total)) {
                            $metrics['investment'] = (float)($total['cost'] ?? 0);
                            $metrics['revenue'] = (float)($total['amount'] ?? 0);
                            $metrics['clicks'] = (int)($total['clicks'] ?? 0);
                            $metrics['impressions'] = (int)($total['impressions'] ?? 0);
                            $apiSuccess = true;
                            $meta['data_source'] = 'mercadolivre_api';
                            $meta['api_endpoint'] = '/advertising/reports';
                        }
                    } elseif (!$apiSuccess && (isset($report['error']) || isset($report['status']))) {
                        $meta['api_error'] = $report['message'] ?? ($report['error'] ?? 'unknown');
                        if (isset($report['status'])) {
                            $meta['api_status'] = (int)$report['status'];
                        }
                    }
                } catch (\Exception $e) {
                    $meta['api_error'] = $e->getMessage();
                }
            } else {
                $meta['reason'] = 'missing_or_expired_token';
            }

            // 3. Se não conseguiu dados da API, buscar do cache/banco
            if (!$apiSuccess) {
                $cached = $this->getCachedMetrics($period);
                if (!empty($cached)) {
                    $metrics = array_merge($metrics, $cached);
                    $meta['data_source'] = 'local_cache';
                    $meta['cache_age'] = $cached['_cache_age'] ?? 'unknown';
                } else {
                    $meta['data_source'] = 'no_data';
                }
            }

            // 4. Calcular métricas derivadas
            if ($metrics['revenue'] > 0) {
                $metrics['acos'] = round(($metrics['investment'] / $metrics['revenue']) * 100, 2);
                $metrics['roas'] = round($metrics['revenue'] / max($metrics['investment'], 0.01), 2);
            }

            if ($metrics['impressions'] > 0) {
                $metrics['ctr'] = round(($metrics['clicks'] / $metrics['impressions']) * 100, 2);
            }

            if ($metrics['clicks'] > 0) {
                $metrics['cpc'] = round($metrics['investment'] / $metrics['clicks'], 2);
            }

            return array_merge($metrics, ['_meta' => $meta]);
        } catch (\Exception $e) {
            log_error('Erro ao calcular métricas de ADS', [
                'service' => 'AdsService',
                'period' => $period,
                'error' => $e->getMessage(),
            ]);
            $meta['data_source'] = 'error';
            $meta['error'] = $e->getMessage();
            return [
                'investment' => 0.0,
                'revenue' => 0.0,
                'acos' => 0,
                'roas' => 0,
                'clicks' => 0,
                'impressions' => 0,
                'cpc' => 0,
                'ctr' => 0,
                '_meta' => $meta
            ];
        }
    }

    // ==================== MÉTODOS DE CACHE LOCAL ====================

    /**
     * Salva campanhas em cache local (banco)
     */
    private function cacheCampaigns(array $campaigns): void
    {
        try {
            $this->ensureAdsTable();

            foreach ($campaigns as $campaign) {
                $stmt = $this->db->prepare("
                    INSERT INTO ads_campaigns_cache
                    (account_id, campaign_id, name, status, daily_budget, type, data, updated_at)
                    VALUES (:account_id, :campaign_id, :name, :status, :budget, :type, :data, NOW())
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        status = VALUES(status),
                        daily_budget = VALUES(daily_budget),
                        type = VALUES(type),
                        data = VALUES(data),
                        updated_at = NOW()
                ");

                $stmt->execute([
                    'account_id' => $this->accountId,
                    'campaign_id' => $campaign['id'] ?? '',
                    'name' => $campaign['name'] ?? '',
                    'status' => $campaign['status'] ?? 'unknown',
                    'budget' => $campaign['budget']['daily_budget'] ?? $campaign['budget'] ?? 0,
                    'type' => $campaign['type'] ?? 'product_ad',
                    'data' => json_encode($campaign)
                ]);
            }
        } catch (\Exception $e) {
            log_warning('Erro ao cachear campanhas de Ads', [
                'service' => 'AdsService',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Obtém campanhas do cache local
     */
    protected function getCachedCampaigns(string $status = 'active'): array
    {
        try {
            $this->ensureAdsTable();

            $sql = "SELECT * FROM ads_campaigns_cache WHERE account_id = :account_id";
            $params = ['account_id' => $this->accountId];

            if ($status !== 'all') {
                $sql .= " AND status = :status";
                $params['status'] = $status;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(function ($row) {
                $data = json_decode($row['data'] ?? '{}', true);
                return array_merge($data, [
                    'id' => $row['campaign_id'],
                    'name' => $row['name'],
                    'status' => $row['status'],
                    'budget' => $row['daily_budget'],
                    'type' => $row['type'],
                    '_cached' => true,
                    '_cached_at' => $row['updated_at']
                ]);
            }, $cached);
        } catch (\Exception $e) {
            log_warning('Erro ao buscar campanhas do cache', [
                'service' => 'AdsService',
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Salva campanha criada localmente
     */
    private function saveCampaignLocal(array $campaign): void
    {
        $this->cacheCampaigns([$campaign]);
    }

    /**
     * Atualiza budget local
     */
    private function updateCampaignLocalBudget(string $campaignId, float $budget): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE ads_campaigns_cache
                SET daily_budget = :budget, updated_at = NOW()
                WHERE account_id = :account_id AND campaign_id = :campaign_id
            ");
            $stmt->execute([
                'budget' => $budget,
                'account_id' => $this->accountId,
                'campaign_id' => $campaignId
            ]);
        } catch (\Exception $e) {
            log_warning('Erro ao atualizar budget local de campanha', [
                'service' => 'AdsService',
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Atualiza status local
     */
    private function updateCampaignLocalStatus(string $campaignId, string $status): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE ads_campaigns_cache
                SET status = :status, updated_at = NOW()
                WHERE account_id = :account_id AND campaign_id = :campaign_id
            ");
            $stmt->execute([
                'status' => $status,
                'account_id' => $this->accountId,
                'campaign_id' => $campaignId
            ]);
        } catch (\Exception $e) {
            log_warning('Erro ao atualizar status local de campanha', [
                'service' => 'AdsService',
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cache de métricas de relatório
     */
    private function cacheReportMetrics(string $campaignId, array $report): void
    {
        try {
            $this->ensureAdsTable();

            $stmt = $this->db->prepare("
                INSERT INTO ads_metrics_history
                (account_id, campaign_id, date, cost, revenue, clicks, impressions, conversions, data)
                VALUES (:account_id, :campaign_id, CURDATE(), :cost, :revenue, :clicks, :impressions, :conversions, :data)
                ON DUPLICATE KEY UPDATE
                    cost = VALUES(cost),
                    revenue = VALUES(revenue),
                    clicks = VALUES(clicks),
                    impressions = VALUES(impressions),
                    conversions = VALUES(conversions),
                    data = VALUES(data)
            ");

            $stmt->execute([
                'account_id' => $this->accountId,
                'campaign_id' => $campaignId,
                'cost' => $report['total']['cost'] ?? 0,
                'revenue' => $report['total']['amount'] ?? 0,
                'clicks' => $report['total']['clicks'] ?? 0,
                'impressions' => $report['total']['impressions'] ?? 0,
                'conversions' => $report['total']['conversions'] ?? 0,
                'data' => json_encode($report)
            ]);
        } catch (\Exception $e) {
            log_warning('Erro ao cachear métricas de Ads', [
                'service' => 'AdsService',
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Obtém relatório do cache
     */
    private function getCachedReport(string $campaignId, string $dateFrom, string $dateTo): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    SUM(cost) as total_cost,
                    SUM(revenue) as total_revenue,
                    SUM(clicks) as total_clicks,
                    SUM(impressions) as total_impressions,
                    SUM(conversions) as total_conversions
                FROM ads_metrics_history
                WHERE account_id = :account_id
                AND campaign_id = :campaign_id
                AND date BETWEEN :date_from AND :date_to
            ");

            $stmt->execute([
                'account_id' => $this->accountId,
                'campaign_id' => $campaignId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && $result['total_cost'] > 0) {
                return [
                    'campaign_id' => $campaignId,
                    'period' => ['from' => $dateFrom, 'to' => $dateTo],
                    'metrics' => [
                        'investment' => (float)$result['total_cost'],
                        'revenue' => (float)$result['total_revenue'],
                        'clicks' => (int)$result['total_clicks'],
                        'impressions' => (int)$result['total_impressions'],
                        'conversions' => (int)$result['total_conversions'],
                    ],
                    'calculated_metrics' => $this->calculateAdMetrics([
                        'cost' => $result['total_cost'],
                        'amount' => $result['total_revenue'],
                        'clicks' => $result['total_clicks'],
                        'impressions' => $result['total_impressions'],
                    ]),
                    '_cached' => true
                ];
            }
        } catch (\Exception $e) {
            log_warning('Erro ao buscar relatório do cache', [
                'service' => 'AdsService',
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->getEmptyReport();
    }

    /**
     * Obtém métricas agregadas do cache
     */
    private function getCachedMetrics(string $period): array
    {
        try {
            $dateFrom = $this->getDateFromPeriod($period);

            $stmt = $this->db->prepare("
                SELECT
                    SUM(cost) as investment,
                    SUM(revenue) as revenue,
                    SUM(clicks) as clicks,
                    SUM(impressions) as impressions
                FROM ads_metrics_history
                WHERE account_id = :account_id
                AND date >= :date_from
            ");

            $stmt->execute([
                'account_id' => $this->accountId,
                'date_from' => $dateFrom
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'investment' => (float)($result['investment'] ?? 0),
                'revenue' => (float)($result['revenue'] ?? 0),
                'clicks' => (int)($result['clicks'] ?? 0),
                'impressions' => (int)($result['impressions'] ?? 0),
            ];
        } catch (\Exception $e) {
            log_warning('Erro ao buscar métricas do cache', [
                'service' => 'AdsService',
                'period' => $period,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // ==================== MÉTODOS UTILITÁRIOS ====================

    private function calculateAdMetrics(array $data): array
    {
        $investment = (float)($data['cost'] ?? $data['investment'] ?? 0);
        $revenue = (float)($data['amount'] ?? $data['revenue'] ?? 0);
        $clicks = (int)($data['clicks'] ?? 0);
        $impressions = (int)($data['impressions'] ?? 0);

        return [
            'acos' => $revenue > 0 ? round(($investment / $revenue) * 100, 2) : 0,
            'roas' => $investment > 0 ? round($revenue / $investment, 2) : 0,
            'cpc' => $clicks > 0 ? round($investment / $clicks, 2) : 0,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0,
        ];
    }

    private function formatBonuses(array $bonuses): array
    {
        return array_map(function ($bonus) {
            return [
                'id' => $bonus['id'] ?? null,
                'amount' => $bonus['amount'] ?? 0,
                'currency' => $bonus['currency'] ?? 'BRL',
                'expiration_date' => $bonus['expiration_date'] ?? null,
                'status' => $bonus['status'] ?? 'available',
                'description' => $bonus['description'] ?? '',
            ];
        }, $bonuses);
    }

    private function getDateFromPeriod(string $period): string
    {
        return match ($period) {
            'today' => date('Y-m-d'),
            'yesterday' => date('Y-m-d', strtotime('-1 day')),
            'last_7_days' => date('Y-m-d', strtotime('-7 days')),
            'last_30_days' => date('Y-m-d', strtotime('-30 days')),
            'last_90_days' => date('Y-m-d', strtotime('-90 days')),
            default => date('Y-m-d', strtotime('-30 days'))
        };
    }

    private function getEmptyReport(): array
    {
        return [
            'campaign_id' => null,
            'period' => ['from' => null, 'to' => null],
            'metrics' => [
                'investment' => 0,
                'revenue' => 0,
                'clicks' => 0,
                'impressions' => 0,
                'conversions' => 0,
                'sold_quantity' => 0,
            ],
            'calculated_metrics' => [
                'acos' => 0,
                'roas' => 0,
                'cpc' => 0,
                'ctr' => 0,
            ],
            'daily_breakdown' => [],
        ];
    }

    /**
     * Garante que as tabelas de cache existem
     */
    private function ensureAdsTable(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ads_campaigns_cache (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    account_id INT UNSIGNED NOT NULL,
                    campaign_id VARCHAR(100) NOT NULL,
                    name VARCHAR(255),
                    status VARCHAR(50) DEFAULT 'unknown',
                    daily_budget DECIMAL(10,2) DEFAULT 0,
                    type VARCHAR(50) DEFAULT 'product_ad',
                    data JSON,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_account_campaign (account_id, campaign_id),
                    INDEX idx_account_status (account_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ads_metrics_history (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    account_id INT UNSIGNED NOT NULL,
                    campaign_id VARCHAR(100) NOT NULL,
                    date DATE NOT NULL,
                    cost DECIMAL(12,2) DEFAULT 0,
                    revenue DECIMAL(12,2) DEFAULT 0,
                    clicks INT DEFAULT 0,
                    impressions INT DEFAULT 0,
                    conversions INT DEFAULT 0,
                    data JSON,
                    UNIQUE KEY uk_account_campaign_date (account_id, campaign_id, date),
                    INDEX idx_account_date (account_id, date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Exception $e) {
            // Tabelas podem já existir
        }
    }
}
