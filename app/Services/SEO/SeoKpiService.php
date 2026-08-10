<?php

declare(strict_types=1);

namespace App\Services\SEO;

use App\Database;
use App\Services\Rank\RankTrackerService;
use PDO;
use Throwable;

/**
 * Loop de KPI do Hidden SEO / Ficha Técnica — mede uplift vs baseline.
 * Não aplica mudanças no ML; só captura baseline e avalia janelas 7/14/28d.
 */
final class SeoKpiService
{
    private PDO $db;
    private RankTrackerService $ranks;

    public function __construct(?PDO $db = null, ?RankTrackerService $ranks = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->ranks = $ranks ?? new RankTrackerService($this->db);
        $this->ensureSchema();
    }

    /**
     * Captura baseline no momento da aprovação / "plano pronto".
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function captureBaseline(
        int $accountId,
        string $mlbId,
        string $tipo,
        array $meta = []
    ): array {
        $mlbId = strtoupper($mlbId);
        $baseline = $this->buildBaseline($accountId, $mlbId);
        $now = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "datetime('now')"
            : 'NOW()';
        $stmt = $this->db->prepare(
            "INSERT INTO seo_interventions
             (account_id, mlb_id, tipo, status, baseline_json, meta_json, approved_at, created_at)
             VALUES (?, ?, ?, 'baseline_captured', ?, ?, {$now}, {$now})"
        );
        $stmt->execute([
            $accountId,
            $mlbId,
            $tipo,
            json_encode($baseline, JSON_UNESCAPED_UNICODE),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
        $id = (int) $this->db->lastInsertId();

        return [
            'success' => true,
            'id' => $id,
            'mlb_id' => $mlbId,
            'baseline' => $baseline,
            'status' => 'baseline_captured',
        ];
    }

    public function markApplied(int $interventionId): bool
    {
        $now = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "datetime('now')"
            : 'NOW()';
        $stmt = $this->db->prepare(
            "UPDATE seo_interventions SET status = 'applied', applied_at = {$now} WHERE id = ?"
        );
        return $stmt->execute([$interventionId]);
    }

    /**
     * Avalia janelas 7/14/28 dias após applied_at.
     *
     * @return array<string, mixed>
     */
    public function evaluate(int $interventionId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM seo_interventions WHERE id = ? LIMIT 1');
        $stmt->execute([$interventionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'error' => 'not_found'];
        }

        $baseline = json_decode((string) ($row['baseline_json'] ?? '{}'), true) ?: [];
        $appliedAt = $row['applied_at'] ?? $row['approved_at'] ?? null;
        if ($appliedAt === null) {
            return ['success' => false, 'error' => 'not_applied'];
        }

        $accountId = (int) $row['account_id'];
        $mlbId = (string) $row['mlb_id'];
        $windows = [];
        foreach ([7, 14, 28] as $days) {
            $elapsed = (time() - strtotime((string) $appliedAt)) / 86400;
            if ($elapsed < $days) {
                $windows[(string) $days] = ['status' => 'pending', 'days' => $days];
                continue;
            }
            $current = $this->buildBaseline($accountId, $mlbId, $days);
            $windows[(string) $days] = $this->compare($baseline, $current, $days);
        }

        $overall = $this->overallStatus($windows);
        $now = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "datetime('now')"
            : 'NOW()';
        $upd = $this->db->prepare(
            "UPDATE seo_interventions SET results_json = ?, status = ?, evaluated_at = {$now} WHERE id = ?"
        );
        $upd->execute([
            json_encode($windows, JSON_UNESCAPED_UNICODE),
            $overall,
            $interventionId,
        ]);

        return [
            'success' => true,
            'id' => $interventionId,
            'status' => $overall,
            'windows' => $windows,
            'baseline' => $baseline,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInterventions(int $accountId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(
            "SELECT id, mlb_id, tipo, status, baseline_json, results_json,
                    approved_at, applied_at, evaluated_at, created_at
             FROM seo_interventions
             WHERE account_id = ?
             ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([$accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['baseline'] = json_decode((string) ($row['baseline_json'] ?? '{}'), true) ?: [];
            $row['results'] = json_decode((string) ($row['results_json'] ?? 'null'), true);
            unset($row['baseline_json'], $row['results_json']);
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBaseline(int $accountId, string $mlbId, int $days = 7): array
    {
        $visitsPerDay = $this->visitsPerDay($accountId, $mlbId, $days);
        $salesPerDay = $this->salesPerDay($accountId, $mlbId, $days);
        $conversion = ($visitsPerDay > 0) ? round($salesPerDay / $visitsPerDay, 4) : null;
        $rank = null;
        $hist = $this->ranks->historyForItem($accountId, $mlbId, $days);
        if ($hist !== [] && $hist[0]['position'] !== null) {
            $rank = (int) $hist[0]['position'];
        }

        return [
            'captured_at' => date('c'),
            'window_days' => $days,
            'visits_per_day' => $visitsPerDay,
            'sales_per_day' => $salesPerDay,
            'conversion' => $conversion,
            'organic_position' => $rank,
        ];
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function compare(array $baseline, array $current, int $days): array
    {
        $deltaVisits = $this->deltaPct($baseline['visits_per_day'] ?? null, $current['visits_per_day'] ?? null);
        $deltaSales = $this->deltaPct($baseline['sales_per_day'] ?? null, $current['sales_per_day'] ?? null);
        $deltaConv = $this->deltaPct($baseline['conversion'] ?? null, $current['conversion'] ?? null);
        $posBefore = $baseline['organic_position'] ?? null;
        $posAfter = $current['organic_position'] ?? null;
        $deltaPos = ($posBefore !== null && $posAfter !== null) ? ((int) $posBefore - (int) $posAfter) : null;

        $score = 0;
        if ($deltaVisits !== null) {
            $score += $deltaVisits > 5 ? 1 : ($deltaVisits < -5 ? -1 : 0);
        }
        if ($deltaConv !== null) {
            $score += $deltaConv > 5 ? 1 : ($deltaConv < -5 ? -1 : 0);
        }
        if ($deltaPos !== null) {
            $score += $deltaPos > 0 ? 1 : ($deltaPos < 0 ? -1 : 0);
        }

        $status = $score > 0 ? 'improved' : ($score < 0 ? 'regressed' : 'neutral');

        return [
            'days' => $days,
            'status' => $status,
            'delta_visits_pct' => $deltaVisits,
            'delta_sales_pct' => $deltaSales,
            'delta_conversion_pct' => $deltaConv,
            'delta_position' => $deltaPos,
            'current' => $current,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $windows
     */
    private function overallStatus(array $windows): string
    {
        $has = false;
        foreach ($windows as $w) {
            if (($w['status'] ?? '') === 'pending') {
                continue;
            }
            $has = true;
            if (($w['status'] ?? '') === 'regressed') {
                return 'regressed';
            }
            if (($w['status'] ?? '') === 'improved') {
                return 'improved';
            }
        }
        return $has ? 'neutral' : 'baseline_captured';
    }

    private function deltaPct(mixed $before, mixed $after): ?float
    {
        if ($before === null || $after === null) {
            return null;
        }
        $b = (float) $before;
        $a = (float) $after;
        if (abs($b) < 0.00001) {
            return $a > 0 ? 100.0 : 0.0;
        }
        return round((($a - $b) / $b) * 100, 2);
    }

    private function visitsPerDay(int $accountId, string $mlbId, int $days): ?float
    {
        try {
            // Prefer visits history table if present
            $stmt = $this->db->prepare(
                "SELECT AVG(visits) FROM item_visits_daily
                 WHERE account_id = ? AND mlb_id = ?
                   AND `date` >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
            );
            $stmt->execute([$accountId, $mlbId, $days]);
            $v = $stmt->fetchColumn();
            if ($v !== false && $v !== null) {
                return round((float) $v, 2);
            }
        } catch (Throwable) {
            // fallback
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT visits FROM items WHERE account_id = ? AND ml_item_id = ? LIMIT 1'
            );
            $stmt->execute([$accountId, $mlbId]);
            $v = $stmt->fetchColumn();
            if ($v !== false && $v !== null) {
                return round(((float) $v) / max(1, $days), 2);
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    private function salesPerDay(int $accountId, string $mlbId, int $days): ?float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) / ? FROM order_items oi
                 JOIN ml_orders o ON o.id = oi.order_id
                 WHERE o.account_id = ? AND oi.item_id = ?
                   AND o.date_created >= DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->execute([$days, $accountId, $mlbId, $days]);
            $v = $stmt->fetchColumn();
            if ($v !== false) {
                return round((float) $v, 4);
            }
        } catch (Throwable) {
            // fallback sold_quantity rough
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT sold_quantity FROM items WHERE account_id = ? AND ml_item_id = ? LIMIT 1'
            );
            $stmt->execute([$accountId, $mlbId]);
            $v = $stmt->fetchColumn();
            return $v !== false ? round(((float) $v) / max(30, $days), 4) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function ensureSchema(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS seo_interventions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INT NOT NULL,
                    mlb_id TEXT NOT NULL,
                    tipo TEXT NOT NULL,
                    status TEXT NOT NULL,
                    baseline_json TEXT NULL,
                    results_json TEXT NULL,
                    meta_json TEXT NULL,
                    approved_at TEXT NULL,
                    applied_at TEXT NULL,
                    evaluated_at TEXT NULL,
                    created_at TEXT NOT NULL
                )'
            );
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS seo_interventions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                mlb_id VARCHAR(32) NOT NULL,
                tipo VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                baseline_json JSON NULL,
                results_json JSON NULL,
                meta_json JSON NULL,
                approved_at DATETIME NULL,
                applied_at DATETIME NULL,
                evaluated_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_account (account_id, created_at),
                INDEX idx_mlb (mlb_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
