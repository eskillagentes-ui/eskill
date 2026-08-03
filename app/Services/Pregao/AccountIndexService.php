<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use PDO;
use Redis;
use Throwable;

/**
 * Recalcula o índice ESKL11 (tick 30–60s) com renormalização sobre fatores ativos.
 */
final class AccountIndexService
{
    private PDO $db;
    private AccountIndexCalculator $calculator;
    private PregaoEmitService $emitter;

    public function __construct(
        ?PDO $db = null,
        ?AccountIndexCalculator $calculator = null,
        ?PregaoEmitService $emitter = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->calculator = $calculator ?? new AccountIndexCalculator();
        $this->emitter = $emitter ?? new PregaoEmitService($this->db);
    }

    /**
     * @return array{
     *   indice: float|null,
     *   factors: array<string, float|null>,
     *   factors_active: int,
     *   factors_total: int,
     *   label: string,
     *   event: array<string, mixed>|null
     * }
     */
    public function tick(int $accountId): array
    {
        $metrics = $this->ensureMetricsRow($accountId);
        $baselines = $this->ensureBaselinesRow($accountId);
        $available = $this->resolveAvailable($metrics);
        $prevFactorsActive = (int) ($metrics['factors_active'] ?? 0);
        $prevIndice = isset($metrics['indice_atual']) ? (float) $metrics['indice_atual'] : null;
        $beforeSnapshot = [
            'account_id' => $accountId,
            'ft_was_available' => (bool) ($available['Ft'] ?? false) && $prevFactorsActive >= 5,
            'indice' => $prevIndice,
            'factors_active' => $prevFactorsActive,
            'label' => sprintf('%d de 5 fatores ativos', $prevFactorsActive),
            'factors' => null,
        ];

        $result = $this->calculator->calculate([
            'vendas_7d' => (float) ($metrics['vendas_7d'] ?? 0),
            'vendas_7d_baseline' => (float) ($baselines['vendas_7d_baseline'] ?? 1),
            'visitas_7d' => (float) ($metrics['visitas_7d'] ?? 0),
            'visitas_baseline' => (float) ($baselines['visitas_baseline'] ?? 1),
            'health_medio' => (float) ($metrics['health_medio'] ?? 0),
            'reputacao' => (string) ($metrics['reputacao_cor'] ?? 'verde'),
            'tacos_atual' => (float) ($metrics['tacos'] ?? 0),
            'tacos_baseline' => (float) ($baselines['tacos_baseline'] ?? 10),
            'available' => $available,
        ]);

        $indice = $result['indice'];
        $this->auditFtActivation($accountId, $beforeSnapshot, $result);
        $this->persistTickMeta($accountId, $indice, $result);

        $event = null;
        if ($indice !== null) {
            $this->upsertIntradayCandle($accountId, $indice);
            $event = $this->emitter->emit('index.tick', [
                'value' => round($indice, 2),
                'factors_active' => $result['factors_active'],
                'factors_total' => $result['factors_total'],
                'label' => $result['label'],
                'active' => $result['active'],
                'factors' => $result['factors'],
            ], $accountId, 'live');
        }

        return [
            'indice' => $indice,
            'factors' => $result['factors'],
            'factors_active' => $result['factors_active'],
            'factors_total' => $result['factors_total'],
            'label' => $result['label'],
            'event' => $event,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consolidateDailyCandle(int $accountId, ?string $date = null): ?array
    {
        $date = $date ?? (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $stmt = $this->db->prepare(
            'SELECT o, h, l, c FROM account_index_daily WHERE account_id = ? AND `date` = ?'
        );
        $stmt->execute([$accountId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $payload = [
            'date' => $date,
            'o' => (float) $row['o'],
            'h' => (float) $row['h'],
            'l' => (float) $row['l'],
            'c' => (float) $row['c'],
        ];
        $this->emitter->emit('index.candle', $payload, $accountId, 'live');
        return $payload;
    }

    public function recalculateBaselines(int $accountId, float $vendas7d, float $posMedia, float $tacos): void
    {
        $vendasBaseline = max($vendas7d > 0 ? $vendas7d : 1.0, 1.0);
        $posBaseline = max($posMedia, 0.1);
        $tacosBaseline = max($tacos, 0.1);

        $this->db->prepare(
            'INSERT INTO account_index_baselines
               (account_id, vendas_7d_baseline, pos_baseline, tacos_baseline, recalculated_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
               vendas_7d_baseline = VALUES(vendas_7d_baseline),
               pos_baseline = VALUES(pos_baseline),
               tacos_baseline = VALUES(tacos_baseline),
               recalculated_at = CURRENT_TIMESTAMP'
        )->execute([$accountId, $vendasBaseline, $posBaseline, $tacosBaseline]);
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{Fv: bool, Fe: bool, Fh: bool, Fr: bool, Ft: bool}
     */
    private function resolveAvailable(array $metrics): array
    {
        $available = [
            'Fv' => false,
            'Fe' => false,
            'Fh' => false,
            'Fr' => false,
            'Ft' => false,
        ];

        $metaRaw = $metrics['metrics_meta'] ?? null;
        $meta = is_string($metaRaw) ? (json_decode($metaRaw, true) ?: []) : (is_array($metaRaw) ? $metaRaw : []);
        if (isset($meta['available']) && is_array($meta['available'])) {
            foreach ($available as $key => $_) {
                $available[$key] = (bool) ($meta['available'][$key] ?? false);
            }
            // Compat legado Fp → Fe
            if (!($available['Fe']) && !empty($meta['available']['Fp'])) {
                $available['Fe'] = true;
            }
        }

        // Ft ativo somente quando o coletor Ads marcar TACOS available=true
        $tacosMeta = is_array($meta['metrics']['tacos'] ?? null) ? $meta['metrics']['tacos'] : [];
        $available['Ft'] = (($tacosMeta['available'] ?? false) === true);

        return $available;
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $baselines
     * @param array{Fv: bool, Fe: bool, Fh: bool, Fr: bool, Ft: bool} $available
     * @return array<string, mixed>
     */
    private function snapshotForAudit(int $accountId, array $metrics, array $baselines, array $available): array
    {
        $withoutFt = $available;
        $withoutFt['Ft'] = false;
        $before = $this->calculator->calculate([
            'vendas_7d' => (float) ($metrics['vendas_7d'] ?? 0),
            'vendas_7d_baseline' => (float) ($baselines['vendas_7d_baseline'] ?? 1),
            'visitas_7d' => (float) ($metrics['visitas_7d'] ?? 0),
            'visitas_baseline' => (float) ($baselines['visitas_baseline'] ?? 1),
            'health_medio' => (float) ($metrics['health_medio'] ?? 0),
            'reputacao' => (string) ($metrics['reputacao_cor'] ?? 'verde'),
            'tacos_atual' => (float) ($metrics['tacos'] ?? 0),
            'tacos_baseline' => (float) ($baselines['tacos_baseline'] ?? 10),
            'available' => $withoutFt,
        ]);

        return [
            'account_id' => $accountId,
            'ft_was_available' => (bool) ($available['Ft'] ?? false),
            'indice' => $before['indice'],
            'factors_active' => $before['factors_active'],
            'label' => $before['label'],
            'factors' => $before['factors'],
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array{
     *   indice: float|null,
     *   factors_active: int,
     *   factors_total: int,
     *   label: string,
     *   active: array<string, bool>,
     *   factors: array<string, float|null>
     * } $after
     */
    private function auditFtActivation(int $accountId, array $before, array $after): void
    {
        $ftNow = (bool) ($after['active']['Ft'] ?? false);
        if (!$ftNow) {
            return;
        }
        // Já estava ligado — não reauditar
        if (!empty($before['ft_was_available'])) {
            return;
        }

        try {
            if ($this->columnExists('account_index_audit', 'event_type')) {
                $this->db->prepare(
                    'INSERT INTO account_index_audit (account_id, event_type, before_json, after_json)
                     VALUES (?, ?, ?, ?)'
                )->execute([
                    $accountId,
                    'ft_activated',
                    json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode([
                        'indice' => $after['indice'],
                        'factors_active' => $after['factors_active'],
                        'label' => $after['label'],
                        'factors' => $after['factors'],
                        'active' => $after['active'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }

            $this->emitter->emitOpOnTransition(
                'INDICE_FT_ATIVADO',
                [
                    'robot' => 'ÍNDICE',
                    'level' => 'info',
                    'icon' => '📊',
                    'msg' => sprintf(
                        'Ft (TACOS) ligado · índice %s → %s (%s)',
                        $before['indice'] === null ? 'n/d' : (string) round((float) $before['indice'], 2),
                        $after['indice'] === null ? 'n/d' : (string) round((float) $after['indice'], 2),
                        $after['label']
                    ),
                    'meta' => [
                        'before' => $before,
                        'after_indice' => $after['indice'],
                        'after_label' => $after['label'],
                    ],
                ],
                ['ft' => true, 'factors_active' => $after['factors_active']],
                $accountId,
                'live'
            );
        } catch (Throwable $e) {
            log_warning('AccountIndexService: falha auditoria Ft', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array{
     *   factors_active: int,
     *   factors_total: int,
     *   label: string,
     *   active: array<string, bool>,
     *   factors: array<string, float|null>
     * } $result
     */
    private function persistTickMeta(int $accountId, ?float $indice, array $result): void
    {
        if ($this->columnExists('account_index_metrics', 'factors_active')) {
            $this->db->prepare(
                'UPDATE account_index_metrics
                 SET indice_atual = ?,
                     factors_active = ?,
                     factors_total = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE account_id = ?'
            )->execute([
                $indice ?? 0,
                $result['factors_active'],
                $result['factors_total'],
                $accountId,
            ]);
            return;
        }

        $this->db->prepare(
            'UPDATE account_index_metrics SET indice_atual = ?, updated_at = CURRENT_TIMESTAMP WHERE account_id = ?'
        )->execute([$indice ?? 0, $accountId]);
    }

    private function upsertIntradayCandle(int $accountId, float $indice): void
    {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $this->db->prepare(
            'INSERT INTO account_index_daily (account_id, `date`, o, h, l, c)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               h = GREATEST(h, VALUES(h)),
               l = LEAST(l, VALUES(l)),
               c = VALUES(c)'
        )->execute([$accountId, $date, $indice, $indice, $indice, $indice]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureMetricsRow(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_index_metrics WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $this->db->prepare(
            'INSERT INTO account_index_metrics (account_id) VALUES (?)'
        )->execute([$accountId]);
        $stmt->execute([$accountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'account_id' => $accountId,
            'vendas_7d' => 0,
            'posicao_media' => 10,
            'health_medio' => 0,
            'reputacao_cor' => 'verde',
            'tacos' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureBaselinesRow(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_index_baselines WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $this->db->prepare(
            'INSERT INTO account_index_baselines (account_id) VALUES (?)'
        )->execute([$accountId]);
        return [
            'vendas_7d_baseline' => 1,
            'pos_baseline' => 10,
            'visitas_baseline' => 1,
            'tacos_baseline' => 10,
        ];
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
