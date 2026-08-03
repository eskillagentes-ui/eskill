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
 * Sem campanha ativa → n/d sem erro.
 * Nunca escreve na API do ML.
 */
final class AdsMetricsCollector
{
    /** Baseline inicial de TACOS (%) — documentado; recalculado semanalmente quando houver dados. */
    public const TACOS_BASELINE_INITIAL = 10.0;

    private const BATCH_SLEEP_US = 250000;
    private const MAX_RETRIES_429 = 4;

    private PDO $db;
    private PregaoEmitService $emitter;
    private SkuCustoService $skuCustos;

    public function __construct(
        ?PDO $db = null,
        ?PregaoEmitService $emitter = null,
        ?SkuCustoService $skuCustos = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->emitter = $emitter ?? new PregaoEmitService($this->db);
        $this->skuCustos = $skuCustos ?? new SkuCustoService($this->db);
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(int $accountId, bool $fullHistory = false): array
    {
        if (!$this->tablesReady()) {
            return [
                'ok' => false,
                'available' => false,
                'reason' => 'tables_missing',
                'tacos' => null,
                'acos' => null,
                'message' => 'rode php bin/ads-migrate-bloco5.php',
            ];
        }

        try {
            $ads = new AdsService($accountId);
            $campaignsPayload = $this->withBackoff(static fn () => $ads->getCampaigns('all'));
            $campaigns = $campaignsPayload['campaigns'] ?? [];
            if (!is_array($campaigns)) {
                $campaigns = [];
            }

            $active = array_values(array_filter(
                $campaigns,
                static fn ($c): bool => is_array($c) && strtolower((string) ($c['status'] ?? '')) === 'active'
            ));

            if ($active === [] && $campaigns === []) {
                return $this->persistEmpty($accountId, 'nenhuma campanha');
            }
            if ($active === []) {
                return $this->persistEmpty($accountId, 'nenhuma campanha ativa');
            }

            $tz = new \DateTimeZone('America/Sao_Paulo');
            $today = new \DateTimeImmutable('today', $tz);
            $dates = [$today->format('Y-m-d')];
            if ($fullHistory) {
                for ($i = 1; $i <= 35; $i++) {
                    $dates[] = $today->modify("-{$i} days")->format('Y-m-d');
                }
            }

            $dayMetrics = [];
            foreach ($active as $campaign) {
                $campaignId = (string) ($campaign['id'] ?? '');
                if ($campaignId === '') {
                    continue;
                }
                $budget = $this->extractBudget($campaign);
                $roasObj = $this->extractRoasObjetivo($campaign);
                $status = (string) ($campaign['status'] ?? 'active');
                $items = $this->extractItemIds($campaign);

                foreach ($dates as $date) {
                    $report = $this->withBackoff(
                        static fn () => $ads->getCampaignReport($campaignId, $date, $date)
                    );
                    $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
                    $gasto = (float) ($metrics['investment'] ?? 0);
                    $receita = (float) ($metrics['revenue'] ?? 0);
                    $clicks = (int) ($metrics['clicks'] ?? 0);
                    $impressions = (int) ($metrics['impressions'] ?? 0);
                    $sold = (int) ($metrics['sold_quantity'] ?? $metrics['conversions'] ?? 0);
                    $cpc = $clicks > 0 ? round($gasto / $clicks, 4) : null;
                    $acos = $receita > 0 ? round(($gasto / $receita) * 100, 4) : ($gasto > 0 ? null : null);
                    if ($receita > 0) {
                        $acos = round(($gasto / $receita) * 100, 4);
                    } elseif ($gasto > 0) {
                        $acos = null; // receita zero com gasto → n/d (não inventar)
                    } else {
                        $acos = null;
                    }
                    $roasReal = $gasto > 0 ? round($receita / $gasto, 4) : null;

                    $this->upsertCampaignDay([
                        'account_id' => $accountId,
                        'campaign_id' => $campaignId,
                        'date' => $date,
                        'status' => $status,
                        'orcamento_diario' => $budget,
                        'roas_objetivo' => $roasObj,
                        'gasto' => $gasto,
                        'impressoes' => $impressions,
                        'cliques' => $clicks,
                        'cpc_medio' => $cpc,
                        'vendas_atribuidas' => $sold,
                        'receita_atribuida' => $receita,
                        'acos' => $acos,
                        'roas_real' => $roasReal,
                        'data' => $campaign,
                    ]);

                    // SKU-level: distribui proporcionalmente se não houver breakdown; marca n/d de ROAS trio via custo
                    if ($items !== []) {
                        $share = 1.0 / count($items);
                        foreach ($items as $mlbId) {
                            $skuGasto = round($gasto * $share, 4);
                            $skuReceita = round($receita * $share, 4);
                            $skuClicks = (int) round($clicks * $share);
                            $skuImp = (int) round($impressions * $share);
                            $skuSold = (int) round($sold * $share);
                            $skuCpc = $skuClicks > 0 ? round($skuGasto / $skuClicks, 4) : null;
                            $skuAcos = $skuReceita > 0 ? round(($skuGasto / $skuReceita) * 100, 4) : null;
                            $skuRoas = $skuGasto > 0 ? round($skuReceita / $skuGasto, 4) : null;
                            $trio = $this->skuCustos->roasTrio($accountId, $mlbId);
                            $health = $this->lookupItemHealth($accountId, $mlbId);

                            $this->upsertSkuDay([
                                'account_id' => $accountId,
                                'campaign_id' => $campaignId,
                                'mlb_id' => $mlbId,
                                'date' => $date,
                                'gasto' => $skuGasto,
                                'impressoes' => $skuImp,
                                'cliques' => $skuClicks,
                                'cpc_medio' => $skuCpc,
                                'vendas_atribuidas' => $skuSold,
                                'receita_atribuida' => $skuReceita,
                                'acos' => $skuAcos,
                                'roas_real' => $skuRoas,
                                'roas_objetivo' => $trio['roas_objetivo'] ?? $roasObj,
                                'health' => $health,
                            ]);
                        }
                    }

                    if (!isset($dayMetrics[$date])) {
                        $dayMetrics[$date] = ['gasto' => 0.0, 'receita_atribuida' => 0.0];
                    }
                    $dayMetrics[$date]['gasto'] += $gasto;
                    $dayMetrics[$date]['receita_atribuida'] += $receita;
                }

                usleep(self::BATCH_SLEEP_US);
            }

            foreach ($dayMetrics as $date => $agg) {
                $receitaTotal = $this->receitaTotalConta($accountId, $date);
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
            $this->persistIndexMetrics($accountId, $windows, count($active));

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
                'tacos' => $windows['tacos_atual'],
                'acos' => $windows['acos_atual'],
                'gasto_hoje' => $windows['gasto_hoje'],
                'tacos_baseline' => $windows['tacos_baseline'],
                'message' => null,
            ];
        } catch (Throwable $e) {
            log_warning('AdsMetricsCollector: falha', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            return [
                'ok' => false,
                'available' => false,
                'reason' => 'collector_error',
                'tacos' => null,
                'acos' => null,
                'error' => $e->getMessage(),
            ];
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
               COUNT(*) AS rows
             FROM ads_account_metrics_daily
             WHERE account_id = ? AND `date` BETWEEN ? AND ?'
        );
        $stmt->execute([$accountId, $from, $to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'gasto' => (float) ($row['gasto'] ?? 0),
            'receita_atribuida' => (float) ($row['receita_atribuida'] ?? 0),
            'receita_total' => (float) ($row['receita_total'] ?? 0),
            'rows' => (int) ($row['rows'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $windows
     */
    private function persistIndexMetrics(int $accountId, array $windows, int $activeCount): void
    {
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

        // Atualiza metrics_meta.available.Ft
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
            ];
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
                'reason' => 'no_active_campaign',
                'message' => $message,
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
            $result = $fn();
            $status = null;
            if (is_array($result)) {
                $status = $result['status'] ?? ($result['_meta']['product_ads_status'] ?? null);
                $err = (string) ($result['error'] ?? '');
                if ($status === 429 || str_contains(strtolower($err), 'too many') || str_contains($err, '429')) {
                    if ($attempt >= self::MAX_RETRIES_429) {
                        return $result;
                    }
                    usleep($delay);
                    $delay = min($delay * 2, 8000000);
                    continue;
                }
            }
            return $result;
        }
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
