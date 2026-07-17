<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Database;
use App\Security\AuthorizedAccountContext;
use PDO;

/**
 * Persistência de bloqueios/irregularidades ML (sem tokens).
 * Fonte: ListingIrregularityScanService / performance snapshots.
 */
final class SalesBlockerStore
{
    public function __construct(private readonly ?PDO $pdo = null) {}

    private function db(): PDO
    {
        return $this->pdo ?? Database::getInstance();
    }

    /**
     * Upsert de itens bloqueados para uma conta autorizada.
     *
     * @param list<array<string, mixed>> $blockedRows
     * @return array{upserted: int, account_id: int}
     */
    public function upsertBlocked(
        AuthorizedAccountContext $context,
        array $blockedRows,
        string $queue = 'urgent'
    ): array {
        $accountId = $context->accountId();
        $upserted = 0;
        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO ml_sales_blockers (
                    account_id, item_id, queue, source_status, severity,
                    reason, remedy, wordings_json, performance_json, scanned_at, updated_at
                ) VALUES (
                    :account_id, :item_id, :queue, :source_status, :severity,
                    :reason, :remedy, :wordings_json, :performance_json, :scanned_at, :updated_at
                )
                ON DUPLICATE KEY UPDATE
                    queue = VALUES(queue),
                    source_status = VALUES(source_status),
                    severity = VALUES(severity),
                    reason = VALUES(reason),
                    remedy = VALUES(remedy),
                    wordings_json = VALUES(wordings_json),
                    performance_json = VALUES(performance_json),
                    scanned_at = VALUES(scanned_at),
                    updated_at = VALUES(updated_at),
                    resolved_at = NULL';

        $stmt = $this->db()->prepare($sql);

        foreach ($blockedRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemId = (string) ($row['item_id'] ?? $row['listing_id'] ?? $row['id'] ?? '');
            if ($itemId === '' || !preg_match('/^MLB?\d+$/i', $itemId)) {
                continue;
            }

            $moderation = is_array($row['moderation'] ?? null) ? $row['moderation'] : [];
            $performance = is_array($row['performance'] ?? null) ? $row['performance'] : null;
            $severityRaw = (string) ($row['severity'] ?? $moderation['severity'] ?? 'high');
            $severity = match ($severityRaw) {
                'block' => 'block',
                'exposure_loss' => 'exposure_loss',
                'high', 'critical' => 'block',
                'medium' => 'exposure_loss',
                default => $severityRaw !== '' ? $severityRaw : 'high',
            };

            $stmt->execute([
                'account_id' => $accountId,
                'item_id' => strtoupper($itemId),
                'queue' => $queue,
                'source_status' => (string) ($row['source_status'] ?? $row['status'] ?? 'unknown'),
                'severity' => $severity,
                'reason' => isset($moderation['reason'])
                    ? (string) $moderation['reason']
                    : (isset($row['reason']) ? (string) $row['reason'] : null),
                'remedy' => isset($moderation['remedy'])
                    ? (string) $moderation['remedy']
                    : (isset($row['remedy']) ? (string) $row['remedy'] : null),
                'wordings_json' => json_encode($moderation['wordings'] ?? $row['wordings'] ?? [], JSON_UNESCAPED_UNICODE),
                'performance_json' => $performance !== null
                    ? json_encode($performance, JSON_UNESCAPED_UNICODE)
                    : null,
                'scanned_at' => (string) ($row['scanned_at'] ?? $now),
                'updated_at' => $now,
            ]);
            $upserted++;
        }

        return ['upserted' => $upserted, 'account_id' => $accountId];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpen(int $accountId, string $queue = 'urgent', int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db()->prepare(
            'SELECT id, account_id, item_id, queue, source_status, severity, reason, remedy,
                    wordings_json, performance_json, scanned_at, updated_at
             FROM ml_sales_blockers
             WHERE account_id = :account_id
               AND queue = :queue
               AND resolved_at IS NULL
             ORDER BY scanned_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([
            'account_id' => $accountId,
            'queue' => $queue,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['wordings'] = json_decode((string) ($row['wordings_json'] ?? '[]'), true) ?: [];
            $row['performance'] = json_decode((string) ($row['performance_json'] ?? 'null'), true);
            unset($row['wordings_json'], $row['performance_json']);
        }

        return $rows;
    }

    /**
     * Contagens por fila para dashboard operacional.
     *
     * @return array<string, int>
     */
    public function countsByQueue(int $accountId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT queue, COUNT(*) AS total
             FROM ml_sales_blockers
             WHERE account_id = :account_id AND resolved_at IS NULL
             GROUP BY queue'
        );
        $stmt->execute(['account_id' => $accountId]);
        $out = ['urgent' => 0, 'exposure' => 0, 'account' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $q = (string) ($row['queue'] ?? '');
            if (isset($out[$q])) {
                $out[$q] = (int) $row['total'];
            }
        }
        return $out;
    }
}
