<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use PDO;
use Throwable;

/**
 * Snapshot REST do Pregão — estado inicial completo para o frontend.
 * Com PREGAO_SEED=false, eventos source=seed ficam ocultos e métricas sem dado real viram null (UI: n/d).
 */
final class PregaoSnapshotService
{
    private PDO $db;
    private AccountIndexCalculator $calculator;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?PDO $db = null, ?AccountIndexCalculator $calculator = null, ?array $config = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->calculator = $calculator ?? new AccountIndexCalculator();
        $this->config = $config ?? (require dirname(__DIR__, 3) . '/config/pregao.php');
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(int $accountId): array
    {
        $metrics = $this->loadMetrics($accountId);
        $baselines = $this->loadBaselines($accountId);
        $meta = $this->decodeMeta($metrics);
        $available = $this->factorAvailability($meta);
        $candles = $this->loadCandles($accountId, 90);
        $ops = $this->loadRecentEvents($accountId, 'op', 50);
        $ranks = $this->loadKeywordRanks($accountId, $meta);
        $qa = $this->loadLatestQa($accountId);
        $semaforo = $this->buildSemaforo($metrics, $meta);

        $calc = $this->calculator->calculate([
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

        $indexValue = $calc['indice'];
        if ($indexValue === null && isset($metrics['indice_atual']) && (int) ($calc['factors_active']) > 0) {
            $indexValue = (float) $metrics['indice_atual'];
        }
        if ($candles !== [] && $indexValue !== null) {
            $indexValue = (float) $candles[array_key_last($candles)]['c'];
        }

        $openRef = $candles !== [] ? (float) $candles[0]['o'] : $indexValue;
        $changePct = ($openRef !== null && $openRef > 0 && $indexValue !== null)
            ? (($indexValue / $openRef) - 1.0) * 100.0
            : null;

        return [
            'account_id' => $accountId,
            'server_ts' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:sP'),
            'index' => [
                'symbol' => 'ESKL11',
                'value' => $indexValue !== null ? round($indexValue, 2) : null,
                'change_pct' => $changePct !== null ? round($changePct, 2) : null,
                'open' => $openRef !== null ? round($openRef, 2) : null,
                'high' => $candles !== [] ? round(max(array_column($candles, 'h')), 2) : ($indexValue !== null ? round($indexValue, 2) : null),
                'low' => $candles !== [] ? round(min(array_column($candles, 'l')), 2) : ($indexValue !== null ? round($indexValue, 2) : null),
                'factors_active' => $calc['factors_active'],
                'factors_total' => $calc['factors_total'],
                'label' => $calc['label'],
                'active' => $calc['active'],
                'factors' => $calc['factors'],
            ],
            'candles' => $candles,
            'metrics' => $this->formatMetrics($metrics, $meta),
            'operations' => $ops,
            'ranks' => $ranks,
            // Alias deprecado (1 versão): clientes antigos ainda leem `keywords`
            'keywords' => $ranks,
            'qa' => $qa,
            'semaforo' => $semaforo,
            'baselines' => $baselines,
            'sentinela' => $this->loadSentinelaSummary($accountId),
            'seed_enabled' => (bool) ($this->config['seed_enabled'] ?? false),
            'rank_tracker_enabled' => (bool) ($this->config['rank_tracker_enabled'] ?? false),
            'read_only' => true,
            'v' => \App\Services\Pregao\PregaoEmitService::VERSION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSentinelaSummary(int $accountId): array
    {
        try {
            return (new \App\Services\Sentinela\Sentinela($this->db))->getSummaryCard($accountId);
        } catch (Throwable $e) {
            return [
                'available' => false,
                'semaforo' => 'nd',
                'monitored' => 0,
                'total' => 11,
                'label' => 'SENTINELA — n/d',
                'href' => '/dashboard/sentinela',
                'pode_expandir' => false,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMetrics(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_index_metrics WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        return [
            'account_id' => $accountId,
            'vendas_hoje' => 0,
            'receita_hoje' => 0,
            'ticket_medio' => 0,
            'vendas_7d' => 0,
            'tacos' => 0,
            'posicao_media' => 10,
            'visitas_7d' => 0,
            'health_medio' => 0,
            'reputacao_cor' => null,
            'reclamacoes_pct' => 0,
            'atrasos_pct' => 0,
            'cancelamentos_pct' => 0,
            'perguntas_hoje' => 0,
            'tempo_medio_resposta_s' => 0,
            'acoes_hora' => 0,
            'indice_atual' => null,
            'semaforo_status' => null,
            'metrics_meta' => null,
            'factors_active' => 0,
            'factors_total' => 5,
        ];
    }

    /**
     * @return array{vendas_7d_baseline: float, pos_baseline: float, tacos_baseline: float}
     */
    private function loadBaselines(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM account_index_baselines WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'vendas_7d_baseline' => (float) ($row['vendas_7d_baseline'] ?? 1),
            'pos_baseline' => (float) ($row['pos_baseline'] ?? 10),
            'visitas_baseline' => (float) ($row['visitas_baseline'] ?? 1),
            'tacos_baseline' => (float) ($row['tacos_baseline'] ?? 10),
        ];
    }

    /**
     * @return list<array{date: string, o: float, h: float, l: float, c: float}>
     */
    private function loadCandles(int $accountId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT `date`, o, h, l, c
             FROM account_index_daily
             WHERE account_id = ?
             ORDER BY `date` DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_reverse($rows);

        return array_map(static function (array $r): array {
            return [
                'date' => (string) $r['date'],
                'o' => (float) $r['o'],
                'h' => (float) $r['h'],
                'l' => (float) $r['l'],
                'c' => (float) $r['c'],
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRecentEvents(int $accountId, string $type, int $limit): array
    {
        $seedOn = (bool) ($this->config['seed_enabled'] ?? false);
        $hasSource = $this->columnExists('pregao_events', 'source');

        if ($hasSource && !$seedOn) {
            $sql = 'SELECT type, ts, payload, source
                    FROM pregao_events
                    WHERE type = ?
                      AND (account_id = ? OR account_id IS NULL)
                      AND source <> \'seed\'
                    ORDER BY ts DESC
                    LIMIT ?';
        } else {
            $sql = 'SELECT type, ts, payload' . ($hasSource ? ', source' : '') . '
                    FROM pregao_events
                    WHERE type = ?
                      AND (account_id = ? OR account_id IS NULL)
                    ORDER BY ts DESC
                    LIMIT ?';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $type, PDO::PARAM_STR);
        $stmt->bindValue(2, $accountId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $payload = is_string($row['payload'])
                ? (json_decode($row['payload'], true) ?: [])
                : (array) $row['payload'];
            $out[] = [
                'v' => 1,
                'type' => $row['type'],
                'ts' => $this->mysqlToIso((string) $row['ts']),
                'payload' => $payload,
                'source' => (string) ($row['source'] ?? 'live'),
                'account_id' => $accountId,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $meta
     * @return list<array{kw: string, pos: int, delta: int|null}>
     */
    private function loadKeywordRanks(int $accountId, array $meta): array
    {
        if (!($this->config['rank_tracker_enabled'] ?? false)) {
            return [];
        }

        $posMeta = $meta['metrics']['posicao_media'] ?? null;
        if (is_array($posMeta) && ($posMeta['available'] ?? false) !== true) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT kw, pos, delta
             FROM keyword_ranks
             WHERE account_id = ?
               AND `date` = (SELECT MAX(`date`) FROM keyword_ranks WHERE account_id = ?)
             ORDER BY pos ASC
             LIMIT 20'
        );
        $stmt->execute([$accountId, $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        return array_map(static function (array $r): array {
            return [
                'kw' => (string) $r['kw'],
                'pos' => (int) $r['pos'],
                'delta' => $r['delta'] !== null ? (int) $r['delta'] : null,
            ];
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLatestQa(int $accountId): array
    {
        $seedOn = (bool) ($this->config['seed_enabled'] ?? false);
        $hasSource = $this->columnExists('pregao_events', 'source');
        $sourceFilter = ($hasSource && !$seedOn) ? " AND source <> 'seed'" : '';

        $stmt = $this->db->prepare(
            "SELECT payload, ts FROM pregao_events
             WHERE type = ? AND (account_id = ? OR account_id IS NULL){$sourceFilter}
             ORDER BY ts DESC LIMIT 1"
        );
        $stmt->execute(['qa.status', $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [
                'running' => false,
                'suite' => null,
                'test' => null,
                'result' => null,
                'video_url' => null,
                'stream_url' => null,
                'log' => [],
            ];
        }
        $payload = is_string($row['payload'])
            ? (json_decode($row['payload'], true) ?: [])
            : (array) $row['payload'];

        $logStmt = $this->db->prepare(
            "SELECT payload, ts FROM pregao_events
             WHERE type = ? AND (account_id = ? OR account_id IS NULL){$sourceFilter}
             ORDER BY ts DESC LIMIT 12"
        );
        $logStmt->execute(['qa.status', $accountId]);
        $log = [];
        foreach ($logStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
            $p = is_string($lr['payload']) ? (json_decode($lr['payload'], true) ?: []) : (array) $lr['payload'];
            $log[] = [
                'ts' => $this->mysqlToIso((string) $lr['ts']),
                'test' => $p['test'] ?? null,
                'result' => $p['result'] ?? null,
                'suite' => $p['suite'] ?? null,
            ];
        }

        return array_merge([
            'running' => false,
            'suite' => null,
            'test' => null,
            'result' => null,
            'video_url' => null,
            'stream_url' => null,
            'log' => $log,
        ], $payload, ['log' => $log]);
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildSemaforo(array $metrics, array $meta): array
    {
        $repAvailable = (bool) (($meta['metrics']['reputacao']['available'] ?? false)
            || ($meta['available']['Fr'] ?? false));

        if (!$repAvailable) {
            return [
                'status' => null,
                'indicadores' => null,
                'limites' => $this->config['semaforo_limites'] ?? [
                    'reclamacoes_pct' => 2.0,
                    'atrasos_pct' => 15.0,
                    'cancelamentos_pct' => 2.5,
                ],
            ];
        }

        $indicadores = [
            'reclamacoes_pct' => (float) ($metrics['reclamacoes_pct'] ?? 0),
            'atrasos_pct' => (float) ($metrics['atrasos_pct'] ?? 0),
            'cancelamentos_pct' => (float) ($metrics['cancelamentos_pct'] ?? 0),
        ];
        $limites = $this->config['semaforo_limites'] ?? [
            'reclamacoes_pct' => 2.0,
            'atrasos_pct' => 15.0,
            'cancelamentos_pct' => 2.5,
        ];
        $status = (string) ($metrics['semaforo_status'] ?? $this->calculator->resolveSemaforo($indicadores, $limites));

        return [
            'status' => $status,
            'indicadores' => $indicadores,
            'limites' => $limites,
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function formatMetrics(array $metrics, array $meta): array
    {
        $m = is_array($meta['metrics'] ?? null) ? $meta['metrics'] : [];

        // Se metrics_meta presente, só libera campos marcados available=true → resto = n/d
        $hasMeta = isset($meta['available']);
        $pick = static function (string $key, $value) use ($m, $hasMeta) {
            if (!$hasMeta) {
                return null;
            }
            if (($m[$key]['available'] ?? false) !== true) {
                return null;
            }
            return $value;
        };

        $repAvail = $hasMeta && (($m['reputacao']['available'] ?? false) === true || ($meta['available']['Fr'] ?? false));

        return [
            'vendas_hoje' => $pick('vendas_hoje', (int) ($metrics['vendas_hoje'] ?? 0)),
            'receita_hoje' => $pick('receita_hoje', (float) ($metrics['receita_hoje'] ?? 0)),
            'ticket_medio' => $pick('ticket_medio', (float) ($metrics['ticket_medio'] ?? 0)),
            'tacos' => (($m['tacos']['available'] ?? false) === true)
                ? (isset($m['tacos']['value']) ? (float) $m['tacos']['value'] : (float) ($metrics['tacos'] ?? 0))
                : null,
            'acos' => (($m['acos']['available'] ?? false) === true)
                ? (isset($m['acos']['value']) ? (float) $m['acos']['value'] : null)
                : null,
            'gasto_ads_hoje' => (($m['gasto_ads_hoje']['available'] ?? false) === true)
                ? (isset($m['gasto_ads_hoje']['value']) ? (float) $m['gasto_ads_hoje']['value'] : null)
                : null,
            'posicao_media' => $pick('posicao_media', (float) ($metrics['posicao_media'] ?? 0)),
            'visitas_7d' => $pick('visitas_7d', (float) ($metrics['visitas_7d'] ?? 0)),
            'exposicao' => (($m['exposicao']['available'] ?? false) === true) ? [
                'visitas_7d' => (float) ($metrics['visitas_7d'] ?? 0),
                'visitas_baseline' => (float) (($m['exposicao']['visitas_baseline'] ?? 0)),
            ] : null,
            'health_medio' => $pick('health_medio', (float) ($metrics['health_medio'] ?? 0)),
            'reputacao' => $repAvail ? [
                'cor' => (string) ($metrics['reputacao_cor'] ?? 'verde'),
                'reclamacoes_pct' => (float) ($metrics['reclamacoes_pct'] ?? 0),
                'atrasos_pct' => (float) ($metrics['atrasos_pct'] ?? 0),
                'cancelamentos_pct' => (float) ($metrics['cancelamentos_pct'] ?? 0),
            ] : null,
            'perguntas_hoje' => $pick('perguntas_hoje', (int) ($metrics['perguntas_hoje'] ?? 0)),
            'tempo_medio_resposta_s' => $pick('tempo_medio_resposta_s', (int) ($metrics['tempo_medio_resposta_s'] ?? 0)),
            'perguntas_recebidas_7d' => $pick('perguntas_recebidas_7d', (int) ($m['perguntas_7d']['recebidas'] ?? 0)),
            'perguntas_respondidas_7d' => $pick('perguntas_respondidas_7d', (int) ($m['perguntas_7d']['respondidas'] ?? 0)),
            'taxa_resposta_7d' => $pick('taxa_resposta_7d', isset($m['perguntas_7d']['taxa_resposta_7d'])
                ? (float) $m['perguntas_7d']['taxa_resposta_7d'] : null),
            'mediana_resposta_s' => $pick('mediana_resposta_s', isset($m['perguntas_7d']['mediana_resposta_s'])
                ? (int) $m['perguntas_7d']['mediana_resposta_s'] : null),
            'perguntas_abertas' => $pick('perguntas_abertas', (int) ($m['perguntas_7d']['perguntas_abertas'] ?? 0)),
            'perguntas_7d' => (($m['perguntas_7d']['available'] ?? false) === true) ? [
                'recebidas' => (int) ($m['perguntas_7d']['recebidas'] ?? 0),
                'respondidas' => (int) ($m['perguntas_7d']['respondidas'] ?? 0),
                'taxa' => isset($m['perguntas_7d']['taxa_resposta_7d'])
                    ? (float) $m['perguntas_7d']['taxa_resposta_7d'] : null,
                'mediana_s' => isset($m['perguntas_7d']['mediana_resposta_s'])
                    ? (int) $m['perguntas_7d']['mediana_resposta_s'] : null,
                'media_s' => isset($m['perguntas_7d']['media_resposta_s'])
                    ? (int) $m['perguntas_7d']['media_resposta_s'] : null,
                'abertas' => (int) ($m['perguntas_7d']['perguntas_abertas'] ?? 0),
                'card_status' => (string) ($m['perguntas_7d']['card_status'] ?? 'verde'),
                'card_reason' => (string) ($m['perguntas_7d']['card_reason'] ?? ''),
                'baseline_28d' => isset($m['perguntas_7d']['baseline_28d'])
                    ? (float) $m['perguntas_7d']['baseline_28d'] : null,
                'volume_delta_pct' => isset($m['perguntas_7d']['volume_delta_pct'])
                    ? (float) $m['perguntas_7d']['volume_delta_pct'] : null,
                'dias_sem_pergunta' => (int) ($m['perguntas_7d']['dias_sem_pergunta'] ?? 0),
                'lista_abertas' => is_array($m['perguntas_7d']['abertas'] ?? null)
                    ? $m['perguntas_7d']['abertas'] : [],
            ] : null,
            'acoes_hora' => $pick('acoes_hora', (int) ($metrics['acoes_hora'] ?? 0)),
            'meta' => $m,
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function decodeMeta(array $metrics): array
    {
        $raw = $metrics['metrics_meta'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{Fv: bool, Fe: bool, Fh: bool, Fr: bool, Ft: bool}
     */
    private function factorAvailability(array $meta): array
    {
        $available = [
            'Fv' => false,
            'Fe' => false,
            'Fh' => false,
            'Fr' => false,
            'Ft' => false,
        ];
        if (isset($meta['available']) && is_array($meta['available'])) {
            foreach ($available as $k => $_) {
                $available[$k] = (bool) ($meta['available'][$k] ?? false);
            }
            if (!$available['Fe'] && !empty($meta['available']['Fp'])) {
                $available['Fe'] = true;
            }
        }
        $available['Ft'] = false;
        return $available;
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

    private function mysqlToIso(string $mysqlTs): string
    {
        try {
            $dt = new \DateTimeImmutable($mysqlTs, new \DateTimeZone('America/Sao_Paulo'));
            return $dt->format('Y-m-d\TH:i:sP');
        } catch (Throwable $e) {
            return $mysqlTs;
        }
    }
}
