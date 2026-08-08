<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Database;
use PDO;
use Throwable;

/**
 * Agrega dados read-only para o painel /dashboard/ads.
 */
final class AdsObservationService
{
    private PDO $db;
    private SkuCustoService $skuCustos;

    public function __construct(?PDO $db = null, ?SkuCustoService $skuCustos = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->skuCustos = $skuCustos ?? new SkuCustoService($this->db);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $accountId): array
    {
        $campaigns = $this->campaignsTable($accountId);
        $skus = $this->skusTable($accountId);
        $recovery = $this->recoveryBlock($accountId);
        $cpcHealth = $this->cpcHealthSeries($accountId);

        $active = count(array_filter($campaigns, static fn ($c) => ($c['status'] ?? '') === 'active'));
        $tacos = null;
        $acos = null;
        $gastoHoje = null;
        try {
            $stmt = $this->db->prepare(
                'SELECT tacos, acos, gasto FROM ads_account_metrics_daily
                 WHERE account_id = ? ORDER BY `date` DESC LIMIT 1'
            );
            $stmt->execute([$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $tacos = $row['tacos'] !== null ? (float) $row['tacos'] : null;
                $acos = $row['acos'] !== null ? (float) $row['acos'] : null;
                $gastoHoje = (float) $row['gasto'];
            }
        } catch (Throwable $e) {
            // n/d
        }

        return [
            'read_only' => true,
            'active_campaigns' => $active,
            'has_campaigns' => $campaigns !== [],
            'message' => $active === 0 ? 'nenhuma campanha ativa' : null,
            'tacos' => $tacos,
            'acos' => $acos,
            'gasto_hoje' => $gastoHoje,
            'sku_custos_count' => $this->skuCustos->countByAccount($accountId),
            'campaigns' => $campaigns,
            'skus' => $skus,
            'cpc_health_series' => $cpcHealth,
            'recovery' => $recovery,
        ];
    }

    /**
     * Agrega gasto/receita de Ads em um intervalo de datas, a partir dos snapshots
     * diários já coletados pelo AdsMetricsCollector (tabela ads_account_metrics_daily).
     * TACOS/ACOS do período são recalculados sobre os totais somados (não é a média
     * simples dos TACOS diários), para não distorcer dias sem campanha ativa.
     *
     * @return array<string, mixed>
     */
    public function periodMetrics(int $accountId, string $startDate, string $endDate): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT
                    COALESCE(SUM(gasto), 0) as total_gasto,
                    COALESCE(SUM(receita_atribuida), 0) as total_receita_atribuida,
                    COALESCE(SUM(receita_total), 0) as total_receita_total,
                    COUNT(*) as days_with_data
                 FROM ads_account_metrics_daily
                 WHERE account_id = ? AND `date` BETWEEN ? AND ?'
            );
            $stmt->execute([$accountId, $startDate, $endDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [
                'available' => false,
                'error' => 'ads_metrics_unavailable',
                'period' => ['start' => $startDate, 'end' => $endDate],
            ];
        }

        if (!$row || (int) $row['days_with_data'] === 0) {
            return [
                'available' => false,
                'error' => 'no_ads_data_in_period',
                'period' => ['start' => $startDate, 'end' => $endDate],
            ];
        }

        $gasto = (float) $row['total_gasto'];
        $receitaAtribuida = (float) $row['total_receita_atribuida'];
        $receitaTotal = (float) $row['total_receita_total'];

        return [
            'available' => true,
            'period' => ['start' => $startDate, 'end' => $endDate],
            'days_with_data' => (int) $row['days_with_data'],
            'gasto' => round($gasto, 2),
            'receita_atribuida' => round($receitaAtribuida, 2),
            'receita_total' => round($receitaTotal, 2),
            'acos' => $receitaAtribuida > 0 ? round(($gasto / $receitaAtribuida) * 100, 2) : null,
            'tacos' => $receitaTotal > 0 ? round(($gasto / $receitaTotal) * 100, 2) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaignsTable(int $accountId): array
    {
        if (!$this->tableExists('ads_campaign_metrics_daily')) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT c.*
             FROM ads_campaign_metrics_daily c
             INNER JOIN (
               SELECT campaign_id, MAX(`date`) AS d
               FROM ads_campaign_metrics_daily
               WHERE account_id = ?
               GROUP BY campaign_id
             ) x ON x.campaign_id = c.campaign_id AND x.d = c.`date`
             WHERE c.account_id = ?
             ORDER BY c.gasto DESC'
        );
        $stmt->execute([$accountId, $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $roasReal = isset($row['roas_real']) ? (float) $row['roas_real'] : null;
            $roasObj = isset($row['roas_objetivo']) ? (float) $row['roas_objetivo'] : null;
            $out[] = [
                'campaign_id' => $row['campaign_id'],
                'status' => $row['status'],
                'orcamento_diario' => $row['orcamento_diario'] !== null ? (float) $row['orcamento_diario'] : null,
                'gasto' => (float) $row['gasto'],
                'impressoes' => (int) $row['impressoes'],
                'cliques' => (int) $row['cliques'],
                'cpc' => $row['cpc_medio'] !== null ? (float) $row['cpc_medio'] : null,
                'vendas_atribuidas' => (int) $row['vendas_atribuidas'],
                'acos' => $row['acos'] !== null ? (float) $row['acos'] : null,
                'roas_real' => $roasReal,
                'roas_objetivo' => $roasObj,
                'semaforo' => $this->semaforo($roasReal, $roasObj, null),
                'date' => $row['date'],
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $da = $a['roas_objetivo'] !== null && $a['roas_real'] !== null
                ? $a['roas_objetivo'] - $a['roas_real'] : -999;
            $db = $b['roas_objetivo'] !== null && $b['roas_real'] !== null
                ? $b['roas_objetivo'] - $b['roas_real'] : -999;
            if ($da === $db) {
                return $b['gasto'] <=> $a['gasto'];
            }
            return $db <=> $da; // maior déficit primeiro
        });
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skusTable(int $accountId): array
    {
        if (!$this->tableExists('ads_sku_metrics_daily')) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT mlb_id,
                    SUM(gasto) AS gasto,
                    SUM(impressoes) AS impressoes,
                    SUM(cliques) AS cliques,
                    SUM(vendas_atribuidas) AS vendas_atribuidas,
                    SUM(receita_atribuida) AS receita_atribuida,
                    AVG(cpc_medio) AS cpc,
                    AVG(health) AS health
             FROM ads_sku_metrics_daily
             WHERE account_id = ?
               AND `date` >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY mlb_id
             ORDER BY gasto DESC'
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $mlb = (string) $row['mlb_id'];
            $gasto = (float) $row['gasto'];
            $receita = (float) $row['receita_atribuida'];
            $acos = $receita > 0 ? round(($gasto / $receita) * 100, 2) : null;
            $roasReal = $gasto > 0 ? round($receita / $gasto, 4) : null;
            $trio = $this->skuCustos->roasTrio($accountId, $mlb);
            $out[] = [
                'mlb_id' => $mlb,
                'gasto' => $gasto,
                'impressoes' => (int) $row['impressoes'],
                'cliques' => (int) $row['cliques'],
                'cpc' => $row['cpc'] !== null ? round((float) $row['cpc'], 4) : null,
                'vendas_atribuidas' => (int) $row['vendas_atribuidas'],
                'acos' => $acos,
                'roas_real' => $roasReal,
                'roas_objetivo' => $trio['roas_objetivo'],
                'roas_breakeven' => $trio['roas_breakeven'],
                'roas_escala' => $trio['roas_escala'],
                'margem_liquida_pct' => $trio['margem_liquida_pct'],
                'has_custo' => $trio['has_custo'],
                'health' => $row['health'] !== null ? (float) $row['health'] : null,
                'semaforo' => $this->semaforo($roasReal, $trio['roas_objetivo'], $trio['roas_breakeven']),
            ];
        }
        return $out;
    }

    /**
     * @return array<string, list<array{date: string, cpc: float|null, health: float|null}>>
     */
    private function cpcHealthSeries(int $accountId): array
    {
        if (!$this->tableExists('ads_sku_metrics_daily')) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT mlb_id, `date`,
                    AVG(cpc_medio) AS cpc,
                    AVG(health) AS health
             FROM ads_sku_metrics_daily
             WHERE account_id = ?
               AND `date` >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
             GROUP BY mlb_id, `date`
             ORDER BY mlb_id, `date`'
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $series = [];
        foreach ($rows as $row) {
            $mlb = (string) $row['mlb_id'];
            $series[$mlb][] = [
                'date' => (string) $row['date'],
                'cpc' => $row['cpc'] !== null ? (float) $row['cpc'] : null,
                'health' => $row['health'] !== null ? (float) $row['health'] : null,
            ];
        }
        return $series;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recoveryBlock(int $accountId): array
    {
        if (!$this->tableExists('ads_recovery_milestones')) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM ads_recovery_milestones WHERE account_id = ? ORDER BY mlb_id'
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));

        foreach ($rows as $row) {
            $mlb = (string) $row['mlb_id'];
            $marco = $row['campaign_activated_at'] ?? $row['promo_activated_at'] ?? null;
            $dias = null;
            if (is_string($marco) && $marco !== '') {
                $dt = new \DateTimeImmutable($marco, new \DateTimeZone('America/Sao_Paulo'));
                $dias = (int) $dt->diff($now)->format('%a');
            }

            $gasto = null;
            $roas = null;
            $vendas = null;
            try {
                $m = $this->db->prepare(
                    'SELECT SUM(gasto) AS g, SUM(receita_atribuida) AS r, SUM(vendas_atribuidas) AS v
                     FROM ads_sku_metrics_daily
                     WHERE account_id = ? AND mlb_id = ?'
                );
                $m->execute([$accountId, $mlb]);
                $agg = $m->fetch(PDO::FETCH_ASSOC) ?: [];
                $gasto = isset($agg['g']) ? (float) $agg['g'] : null;
                $receita = isset($agg['r']) ? (float) $agg['r'] : null;
                $vendas = isset($agg['v']) ? (int) $agg['v'] : null;
                if ($gasto !== null && $gasto > 0 && $receita !== null) {
                    $roas = round($receita / $gasto, 4);
                }
            } catch (Throwable $e) {
                // n/d
            }

            $visitasAntes = null;
            $visitasAgora = null;
            // n/d se não houver série — painel mostra n/d

            $out[] = [
                'mlb_id' => $mlb,
                'predecessor_mlb_id' => $row['predecessor_mlb_id'],
                'promo_activated_at' => $row['promo_activated_at'],
                'campaign_activated_at' => $row['campaign_activated_at'],
                'dias_desde_marco' => $dias,
                'visitas_dia_antes' => $visitasAntes,
                'visitas_dia_agora' => $visitasAgora,
                'vendas' => $vendas,
                'gasto_acumulado' => $gasto,
                'roas' => $roas,
                'notes' => $row['notes'],
            ];
        }
        return $out;
    }

    private function semaforo(?float $roasReal, ?float $roasObjetivo, ?float $roasBreakeven): string
    {
        if ($roasReal === null) {
            return 'nd';
        }
        if ($roasObjetivo !== null && $roasReal >= $roasObjetivo) {
            return 'verde';
        }
        $floor = $roasBreakeven ?? ($roasObjetivo !== null ? $roasObjetivo / 1.5 : null);
        if ($floor !== null && $roasReal >= $floor) {
            return 'amarelo';
        }
        if ($floor !== null && $roasReal < $floor) {
            return 'vermelho';
        }
        return 'nd';
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
