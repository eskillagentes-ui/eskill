<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use PDO;
use Redis;
use Throwable;

/**
 * Snapshot REST do Pregão — estado inicial completo para o frontend.
 */
final class PregaoSnapshotService
{
    private PDO $db;
    private AccountIndexCalculator $calculator;

    public function __construct(?PDO $db = null, ?AccountIndexCalculator $calculator = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->calculator = $calculator ?? new AccountIndexCalculator();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(int $accountId): array
    {
        $metrics = $this->loadMetrics($accountId);
        $baselines = $this->loadBaselines($accountId);
        $candles = $this->loadCandles($accountId, 90);
        $ops = $this->loadRecentEvents($accountId, 'op', 50);
        $keywords = $this->loadKeywordRanks($accountId);
        $qa = $this->loadLatestQa($accountId);
        $semaforo = $this->buildSemaforo($metrics);

        $indexValue = (float) ($metrics['indice_atual'] ?? 1000);
        if ($candles !== []) {
            $indexValue = (float) $candles[array_key_last($candles)]['c'];
        }

        $openRef = $candles !== [] ? (float) $candles[0]['o'] : $indexValue;
        $changePct = $openRef > 0 ? (($indexValue / $openRef) - 1.0) * 100.0 : 0.0;

        return [
            'account_id' => $accountId,
            'server_ts' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:sP'),
            'index' => [
                'symbol' => 'ESKL11',
                'value' => round($indexValue, 2),
                'change_pct' => round($changePct, 2),
                'open' => round($openRef, 2),
                'high' => $candles !== [] ? round(max(array_column($candles, 'h')), 2) : round($indexValue, 2),
                'low' => $candles !== [] ? round(min(array_column($candles, 'l')), 2) : round($indexValue, 2),
            ],
            'candles' => $candles,
            'metrics' => $this->formatMetrics($metrics),
            'operations' => $ops,
            'keywords' => $keywords,
            'qa' => $qa,
            'semaforo' => $semaforo,
            'baselines' => $baselines,
            'read_only' => true,
        ];
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
            'health_medio' => 0,
            'reputacao_cor' => 'verde',
            'reclamacoes_pct' => 0,
            'atrasos_pct' => 0,
            'cancelamentos_pct' => 0,
            'perguntas_hoje' => 0,
            'tempo_medio_resposta_s' => 0,
            'acoes_hora' => 0,
            'indice_atual' => 1000,
            'semaforo_status' => 'verde',
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
        $stmt = $this->db->prepare(
            'SELECT type, ts, payload
             FROM pregao_events
             WHERE type = ?
               AND (account_id = ? OR account_id IS NULL)
             ORDER BY ts DESC
             LIMIT ?'
        );
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
                'account_id' => $accountId,
            ];
        }
        return $out;
    }

    /**
     * @return list<array{kw: string, pos: int, delta: int|null}>
     */
    private function loadKeywordRanks(int $accountId): array
    {
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
        $stmt = $this->db->prepare(
            'SELECT payload, ts FROM pregao_events
             WHERE type = ? AND (account_id = ? OR account_id IS NULL)
             ORDER BY ts DESC LIMIT 1'
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
            'SELECT payload, ts FROM pregao_events
             WHERE type = ? AND (account_id = ? OR account_id IS NULL)
             ORDER BY ts DESC LIMIT 12'
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
     * @return array<string, mixed>
     */
    private function buildSemaforo(array $metrics): array
    {
        $indicadores = [
            'reclamacoes_pct' => (float) ($metrics['reclamacoes_pct'] ?? 0),
            'atrasos_pct' => (float) ($metrics['atrasos_pct'] ?? 0),
            'cancelamentos_pct' => (float) ($metrics['cancelamentos_pct'] ?? 0),
        ];
        $limites = [
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
     * @return array<string, mixed>
     */
    private function formatMetrics(array $metrics): array
    {
        return [
            'vendas_hoje' => (int) ($metrics['vendas_hoje'] ?? 0),
            'receita_hoje' => (float) ($metrics['receita_hoje'] ?? 0),
            'ticket_medio' => (float) ($metrics['ticket_medio'] ?? 0),
            'tacos' => (float) ($metrics['tacos'] ?? 0),
            'posicao_media' => (float) ($metrics['posicao_media'] ?? 10),
            'health_medio' => (float) ($metrics['health_medio'] ?? 0),
            'reputacao' => [
                'cor' => (string) ($metrics['reputacao_cor'] ?? 'verde'),
                'reclamacoes_pct' => (float) ($metrics['reclamacoes_pct'] ?? 0),
                'atrasos_pct' => (float) ($metrics['atrasos_pct'] ?? 0),
            ],
            'perguntas_hoje' => (int) ($metrics['perguntas_hoje'] ?? 0),
            'tempo_medio_resposta_s' => (int) ($metrics['tempo_medio_resposta_s'] ?? 0),
            'acoes_hora' => (int) ($metrics['acoes_hora'] ?? 0),
        ];
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
