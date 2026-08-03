<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Database;
use App\Services\Pregao\PregaoEmitService;
use PDO;
use Throwable;

/**
 * Oito alertas do módulo Ads — recomendação only, op em mudança de estado.
 */
final class AdsAlertService
{
    private PDO $db;
    private PregaoEmitService $emitter;
    private SkuCustoService $skuCustos;

    /** @var list<array<string, mixed>> */
    private array $fired = [];

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
     * @return list<array<string, mixed>>
     */
    public function evaluate(int $accountId): array
    {
        $this->fired = [];
        if (!$this->tableExists('ads_sku_metrics_daily')) {
            return [];
        }

        $tz = new \DateTimeZone('America/Sao_Paulo');
        $today = new \DateTimeImmutable('today', $tz);
        $from7 = $today->modify('-6 days')->format('Y-m-d');
        $to = $today->format('Y-m-d');

        $skuRows = $this->skuAgg($accountId, $from7, $to);
        foreach ($skuRows as $row) {
            $this->checkSkuAlerts($accountId, $row);
        }

        $this->checkBudgetExhaustion($accountId, $today);
        $this->checkLearningWindow($accountId);

        return $this->fired;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function checkSkuAlerts(int $accountId, array $row): void
    {
        $mlbId = (string) $row['mlb_id'];
        $gasto = (float) $row['gasto'];
        $receita = (float) $row['receita_atribuida'];
        $vendas = (int) $row['vendas_atribuidas'];
        $acos = $receita > 0 ? ($gasto / $receita) * 100.0 : null;
        $roasReal = $gasto > 0 ? $receita / $gasto : null;
        $trio = $this->skuCustos->roasTrio($accountId, $mlbId);
        $margemBruta = $trio['margem_bruta_pct'];
        $margemLiq = $trio['margem_liquida_pct'];
        $health = isset($row['health']) ? (float) $row['health'] : null;

        // 1) ACOS > margem bruta
        if ($acos !== null && $margemBruta !== null && $acos > $margemBruta) {
            $this->fire($accountId, 'ADS_ACOS_ACIMA_MARGEM:' . $mlbId, [
                'robot' => 'ADS',
                'level' => 'alert',
                'icon' => '💸',
                'msg' => sprintf(
                    '%s · ACOS acima da margem — campanha destrói lucro (ACOS %.1f%% > margem bruta %.1f%%)',
                    $mlbId,
                    $acos,
                    $margemBruta
                ),
                'meta' => [
                    'mlb_id' => $mlbId,
                    'acos' => round($acos, 2),
                    'margem_bruta_pct' => $margemBruta,
                    'acao' => 'Revisar anúncio/qualidade antes de aumentar lance',
                ],
            ], ['on' => true, 'acos' => round($acos, 1)]);
        }

        // 2) ROAS real < breakeven
        if ($roasReal !== null && $trio['roas_breakeven'] !== null && $roasReal < $trio['roas_breakeven']) {
            $this->fire($accountId, 'ADS_ABAIXO_BREAKEVEN:' . $mlbId, [
                'robot' => 'ADS',
                'level' => 'alert',
                'icon' => '🛑',
                'msg' => sprintf(
                    '%s · abaixo do breakeven — candidato a pausa (ROAS %.2fx < %.2fx)',
                    $mlbId,
                    $roasReal,
                    $trio['roas_breakeven']
                ),
                'meta' => [
                    'mlb_id' => $mlbId,
                    'roas_real' => round($roasReal, 2),
                    'roas_breakeven' => $trio['roas_breakeven'],
                    'acao' => 'Recomendação: pausar após confirmar 7d; sem execução automática',
                ],
            ], ['on' => true, 'roas' => round($roasReal, 2)]);
        }

        // 3) margem líquida < 15% com Ads
        if ($gasto > 0 && $margemLiq !== null && $margemLiq < 15.0) {
            $this->fire($accountId, 'ADS_MARGEM_INSUFICIENTE:' . $mlbId, [
                'robot' => 'ADS',
                'level' => 'alert',
                'icon' => '📉',
                'msg' => sprintf(
                    '%s · margem insuficiente para Ads (líquida %.1f%% < 15%%)',
                    $mlbId,
                    $margemLiq
                ),
                'meta' => [
                    'mlb_id' => $mlbId,
                    'margem_liquida_pct' => $margemLiq,
                    'acao' => 'Remover do Ads ou renegociar custo/preço',
                ],
            ], ['on' => true, 'margem' => round($margemLiq, 1)]);
        }

        // 4) gasto sem venda 7d
        if ($gasto > 0 && $vendas === 0) {
            $this->fire($accountId, 'ADS_SEM_CONVERSAO:' . $mlbId, [
                'robot' => 'ADS',
                'level' => 'alert',
                'icon' => '🔍',
                'msg' => sprintf(
                    '%s · %.2f reais sem conversão — revisar anúncio antes do lance',
                    $mlbId,
                    $gasto
                ),
                'meta' => [
                    'mlb_id' => $mlbId,
                    'gasto_7d' => round($gasto, 2),
                    'acao' => 'Melhorar health/foto/atributos antes de subir lance',
                ],
            ], ['on' => true, 'gasto' => round($gasto, 2)]);
        }

        // 5) ROAS ≥ escala por 3 dias consecutivos
        if ($trio['roas_escala'] !== null) {
            $streak = $this->roasEscalaStreak($accountId, $mlbId, (float) $trio['roas_escala']);
            if ($streak >= 3) {
                $this->fire($accountId, 'ADS_PRONTO_ESCALAR:' . $mlbId, [
                    'robot' => 'ADS',
                    'level' => 'alert',
                    'icon' => '🚀',
                    'msg' => sprintf(
                        '%s · pronto para escalar (+30%% na segunda ou terça) — %d dias ≥ ROAS escala %.2fx',
                        $mlbId,
                        $streak,
                        $trio['roas_escala']
                    ),
                    'meta' => [
                        'mlb_id' => $mlbId,
                        'streak_dias' => $streak,
                        'roas_escala' => $trio['roas_escala'],
                        'acao' => 'Recomendação: +30% verba na próxima segunda/terça (manual)',
                    ],
                ], ['on' => true, 'streak' => $streak]);
        }
        }

        // 6) Ads + health < 0.9
        if ($gasto > 0 && $health !== null && $health < 0.9) {
            $this->fire($accountId, 'ADS_HEALTH_BAIXO:' . $mlbId, [
                'robot' => 'ADS',
                'level' => 'alert',
                'icon' => '🩹',
                'msg' => sprintf(
                    '%s · pagando clique para página incompleta (health %.2f < 0,9)',
                    $mlbId,
                    $health
                ),
                'meta' => [
                    'mlb_id' => $mlbId,
                    'health' => $health,
                    'acao' => 'Completar atributos/fotos antes de investir em Ads',
                ],
            ], ['on' => true, 'health' => round($health, 2)]);
        }
    }

    private function checkBudgetExhaustion(int $accountId, \DateTimeImmutable $today): void
    {
        $hour = (int) (new \DateTimeImmutable('now', $today->getTimezone()))->format('G');
        if ($hour >= 18) {
            // depois das 18h o alerta de "esgotando antes das 18h" não se aplica como estado atual
            return;
        }

        $date = $today->format('Y-m-d');
        $stmt = $this->db->prepare(
            'SELECT campaign_id, orcamento_diario, gasto, status
             FROM ads_campaign_metrics_daily
             WHERE account_id = ? AND `date` = ? AND status = \'active\''
        );
        $stmt->execute([$accountId, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $budget = isset($row['orcamento_diario']) ? (float) $row['orcamento_diario'] : 0.0;
            $gasto = (float) ($row['gasto'] ?? 0);
            $cid = (string) $row['campaign_id'];
            if ($budget <= 0) {
                continue;
            }
            // esgotando: ≥95% do orçamento antes das 18h
            if ($gasto >= $budget * 0.95) {
                $this->fire($accountId, 'ADS_VERBA_CURTA:' . $cid, [
                    'robot' => 'ADS',
                    'level' => 'alert',
                    'icon' => '⏱️',
                    'msg' => sprintf(
                        'campanha %s · verba curta — perdendo impressões no fim do dia (R$ %.2f / R$ %.2f)',
                        $cid,
                        $gasto,
                        $budget
                    ),
                    'meta' => [
                        'campaign_id' => $cid,
                        'gasto' => $gasto,
                        'orcamento' => $budget,
                        'acao' => 'Recomendação: revisar orçamento diário (manual)',
                    ],
                ], ['on' => true, 'pct' => round(($gasto / $budget) * 100, 1)]);
            }
        }
    }

    private function checkLearningWindow(int $accountId): void
    {
        // Só dispara se o payload da API trouxer timestamp de ajuste de lance/ROAS.
        // Não usar updated_at do cache — o coletor atualiza a cada tick e geraria falso positivo.
        try {
            $stmt = $this->db->prepare(
                'SELECT campaign_id, data
                 FROM ads_campaigns_cache
                 WHERE account_id = ? AND status = \'active\''
            );
            $stmt->execute([$accountId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $now = time();
            foreach ($rows as $row) {
                $cid = (string) $row['campaign_id'];
                $data = json_decode((string) ($row['data'] ?? '{}'), true) ?: [];
                $changeAt = $data['last_bid_change_at'] ?? $data['last_roas_change_at'] ?? null;
                if (!is_string($changeAt) || $changeAt === '') {
                    continue;
                }
                $ts = strtotime($changeAt);
                if ($ts === false) {
                    continue;
                }
                $ageH = ($now - $ts) / 3600.0;
                if ($ageH < 72.0) {
                    $this->fire($accountId, 'ADS_JANELA_APRENDIZADO:' . $cid, [
                        'robot' => 'ADS',
                        'level' => 'alert',
                        'icon' => '🧠',
                        'msg' => sprintf(
                            'campanha %s · janela de aprendizado — não mexer (%.0fh < 72h)',
                            $cid,
                            $ageH
                        ),
                        'meta' => [
                            'campaign_id' => $cid,
                            'horas_desde_ajuste' => round($ageH, 1),
                            'acao' => 'Aguardar 72h antes de novo ajuste de lance/ROAS',
                        ],
                    ], ['on' => true, 'hours' => (int) $ageH]);
                }
            }
        } catch (Throwable $e) {
            // cache ausente
        }
    }

    private function roasEscalaStreak(int $accountId, string $mlbId, float $roasEscala): int
    {
        $stmt = $this->db->prepare(
            'SELECT `date`,
                    SUM(gasto) AS gasto,
                    SUM(receita_atribuida) AS receita
             FROM ads_sku_metrics_daily
             WHERE account_id = ? AND mlb_id = ?
             GROUP BY `date`
             ORDER BY `date` DESC
             LIMIT 7'
        );
        $stmt->execute([$accountId, $mlbId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $streak = 0;
        foreach ($rows as $row) {
            $gasto = (float) $row['gasto'];
            $receita = (float) $row['receita'];
            if ($gasto <= 0) {
                break;
            }
            $roas = $receita / $gasto;
            if ($roas >= $roasEscala) {
                $streak++;
            } else {
                break;
            }
        }
        return $streak;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skuAgg(int $accountId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT mlb_id,
                    SUM(gasto) AS gasto,
                    SUM(receita_atribuida) AS receita_atribuida,
                    SUM(vendas_atribuidas) AS vendas_atribuidas,
                    AVG(health) AS health
             FROM ads_sku_metrics_daily
             WHERE account_id = ? AND `date` BETWEEN ? AND ?
             GROUP BY mlb_id'
        );
        $stmt->execute([$accountId, $from, $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $fingerprint
     */
    private function fire(int $accountId, string $key, array $payload, array $fingerprint): void
    {
        $event = $this->emitter->emitOpOnTransition($key, $payload, $fingerprint, $accountId, 'live');
        if ($event !== null && empty($event['payload']['heartbeat'])) {
            $this->fired[] = [
                'key' => $key,
                'msg' => $payload['msg'] ?? '',
                'meta' => $payload['meta'] ?? [],
            ];
        }
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
