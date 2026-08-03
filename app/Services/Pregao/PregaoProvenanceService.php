<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use App\Database;
use PDO;
use Throwable;

/**
 * Proveniência de eventos do Pregão: backfill seed auditável e recálculo do índice.
 *
 * Critério de seed (smoke Fase 2 / Hermes):
 * - payload com "Teste Hermes", order_id T\d+, sku MLB1; OU
 * - cadeia sale/op/metric com order_id começando por "T" na janela 2026-08-02 00:00–23:59 BRT
 *   (deploy Fase 2 + smoke documentado em docs/qa/PREGAO_HERMES_REPORT.md).
 */
final class PregaoProvenanceService
{
    public const SMOKE_WINDOW_START = '2026-08-02 00:00:00';
    public const SMOKE_WINDOW_END = '2026-08-03 00:00:00';

    private PDO $db;
    private ?AccountIndexService $indexService;

    public function __construct(?PDO $db = null, ?AccountIndexService $indexService = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->indexService = $indexService;
    }

    private function indexService(): AccountIndexService
    {
        return $this->indexService ??= new AccountIndexService($this->db);
    }

    /**
     * SQL WHERE compartilhado pelo backfill (testável).
     */
    public static function smokeMatchSql(string $alias = 'e'): string
    {
        $a = $alias;
        return "("
            . "{$a}.payload LIKE '%Teste Hermes%'"
            . " OR {$a}.payload LIKE '%\"order_id\":\"T%'"
            . " OR {$a}.payload LIKE '%\"sku\":\"MLB1\"%'"
            . " OR ({$a}.type IN ('sale','op','metric.update') "
            . " AND {$a}.ts >= '" . self::SMOKE_WINDOW_START . "'"
            . " AND {$a}.ts < '" . self::SMOKE_WINDOW_END . "'"
            . " AND ({$a}.payload LIKE '%Teste Hermes%' OR {$a}.payload LIKE '%\"order_id\":\"T%' OR {$a}.payload LIKE '%MLB1%'))"
            . ")";
    }

    /**
     * @return array{seed_marked: int, seed_total: int, live_total: int, criteria: string}
     */
    public function backfillSmokeAsSeed(bool $dryRun = false): array
    {
        $this->ensureSourceColumn();
        $where = self::smokeMatchSql('pregao_events');
        $countStmt = $this->db->query(
            "SELECT COUNT(*) FROM pregao_events WHERE source <> 'seed' AND {$where}"
        );
        $toMark = (int) ($countStmt ? $countStmt->fetchColumn() : 0);

        if (!$dryRun && $toMark > 0) {
            $this->db->exec(
                "UPDATE pregao_events SET source = 'seed' WHERE source <> 'seed' AND {$where}"
            );
        }

        if (!$dryRun) {
            $this->insertAuditMarker($toMark);
        }

        $totals = $this->sourceTotals();
        return [
            'seed_marked' => $toMark,
            'seed_total' => $totals['seed'],
            'live_total' => $totals['live'],
            'criteria' => 'smoke payload (Teste Hermes|order_id T*|sku MLB1) na janela '
                . self::SMOKE_WINDOW_START . ' .. ' . self::SMOKE_WINDOW_END . ' BRT',
        ];
    }

    /**
     * Evento seed de auditoria — garante rastreabilidade mesmo se o smoke já foi purgado.
     */
    private function insertAuditMarker(int $marked): void
    {
        $payload = json_encode([
            'robot' => 'PROVENANCE',
            'level' => 'info',
            'icon' => '🏷️',
            'msg' => sprintf(
                'backfill seed · marcados=%d · critério smoke Fase2 (%s .. %s)',
                $marked,
                self::SMOKE_WINDOW_START,
                self::SMOKE_WINDOW_END
            ),
            'meta' => [
                'marked' => $marked,
                'criteria' => 'Teste Hermes|order_id T*|sku MLB1',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->db->prepare(
            "INSERT INTO pregao_events (account_id, type, ts, payload, source)
             VALUES (NULL, 'op', NOW(3), ?, 'seed')"
        )->execute([$payload]);
    }

    /**
     * Remove DEFAULT silencioso de `source` — INSERT deve informar o valor.
     */
    public function dropSourceDefault(): void
    {
        $this->ensureSourceColumn();
        $col = $this->db->query("SHOW COLUMNS FROM pregao_events LIKE 'source'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            return;
        }
        if (($col['Default'] ?? null) === null && strtoupper((string) ($col['Null'] ?? '')) === 'NO') {
            return;
        }
        $this->db->exec(
            "ALTER TABLE pregao_events MODIFY COLUMN `source` varchar(32) NOT NULL"
        );
    }

    /**
     * Recalcula candle diário via tick live (métricas atuais) e registra divergência.
     *
     * @return array{account_id: int, before: array<string, mixed>|null, after: array<string, mixed>|null, indice: float|null, log: string}
     */
    public function recalculateDailyExcludingSeed(int $accountId, ?string $date = null): array
    {
        $date = $date ?? (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $beforeStmt = $this->db->prepare(
            'SELECT o, h, l, c FROM account_index_daily WHERE account_id = ? AND `date` = ?'
        );
        $beforeStmt->execute([$accountId, $date]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $tick = $this->indexService()->tick($accountId);
        $afterStmt = $this->db->prepare(
            'SELECT o, h, l, c FROM account_index_daily WHERE account_id = ? AND `date` = ?'
        );
        $afterStmt->execute([$accountId, $date]);
        $after = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $beforeC = $before ? (float) $before['c'] : null;
        $afterC = $after ? (float) $after['c'] : null;
        $delta = ($beforeC !== null && $afterC !== null) ? round($afterC - $beforeC, 4) : null;

        $log = sprintf(
            'recalc account=%d date=%s before_c=%s after_c=%s delta=%s indice=%s (excluindo source!=live na fita/snapshot)',
            $accountId,
            $date,
            $beforeC === null ? 'null' : (string) $beforeC,
            $afterC === null ? 'null' : (string) $afterC,
            $delta === null ? 'n/a' : (string) $delta,
            $tick['indice'] === null ? 'n/d' : (string) round((float) $tick['indice'], 4)
        );

        return [
            'account_id' => $accountId,
            'before' => $before,
            'after' => $after,
            'indice' => $tick['indice'],
            'log' => $log,
        ];
    }

    /**
     * @return array{seed: int, live: int, other: int}
     */
    public function sourceTotals(): array
    {
        $rows = $this->db->query(
            "SELECT source, COUNT(*) AS c FROM pregao_events GROUP BY source"
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = ['seed' => 0, 'live' => 0, 'other' => 0];
        foreach ($rows as $row) {
            $src = (string) ($row['source'] ?? '');
            $c = (int) $row['c'];
            if ($src === 'seed') {
                $out['seed'] += $c;
            } elseif ($src === 'live') {
                $out['live'] += $c;
            } else {
                $out['other'] += $c;
            }
        }
        return $out;
    }

    private function ensureSourceColumn(): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pregao_events' AND COLUMN_NAME = 'source'"
        );
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        try {
            $this->db->exec(
                "ALTER TABLE pregao_events ADD COLUMN `source` varchar(32) NOT NULL DEFAULT 'live' AFTER `payload`"
            );
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column')) {
                throw $e;
            }
        }
    }
}
