<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Orquestra sincronização automática de contas ML desatualizadas.
 *
 * Compensa o badge DESINCRONIZADA sem clique manual em "Sincronizar",
 * usando AccountSyncService (itens + pedidos + perguntas + last_synced_at).
 */
class AccountAutoSyncService
{
    private PDO $db;
    private AccountSyncService $accountSync;
    private LoggingService $logger;

    public function __construct(?AccountSyncService $accountSync = null, ?LoggingService $logger = null)
    {
        $this->db = Database::getInstance();
        $this->accountSync = $accountSync ?? new AccountSyncService();
        $this->logger = $logger ?? new LoggingService();
    }

    /**
     * Idade máxima em horas sem sync (ACCOUNT_SYNC_MAX_AGE_HOURS, default 6).
     */
    public function getSyncMaxAgeHours(): int
    {
        $raw = $_ENV['ACCOUNT_SYNC_MAX_AGE_HOURS'] ?? getenv('ACCOUNT_SYNC_MAX_AGE_HOURS') ?: 6;
        return max(1, (int)$raw);
    }

    /**
     * Contas ativas candidatas ao sync automático.
     *
     * @return list<array{id:int,nickname:?string,last_synced_at:?string}>
     */
    public function getStaleActiveAccounts(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $maxAgeHours = $this->getSyncMaxAgeHours();

        $hasLastSynced = $this->columnExists('ml_accounts', 'last_synced_at');
        $lastSyncedSelect = $hasLastSynced ? 'last_synced_at' : 'NULL AS last_synced_at';

        if ($hasLastSynced) {
            $sql = "
                SELECT id, nickname, {$lastSyncedSelect}
                FROM ml_accounts
                WHERE status = 'active'
                  AND (
                    last_synced_at IS NULL
                    OR last_synced_at < DATE_SUB(NOW(), INTERVAL {$maxAgeHours} HOUR)
                  )
                ORDER BY last_synced_at IS NULL DESC, last_synced_at ASC
                LIMIT {$limit}
            ";
            $stmt = $this->db->query($sql);
            $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            $stmt = $this->db->prepare("
                SELECT id, nickname, {$lastSyncedSelect}
                FROM ml_accounts
                WHERE status = 'active'
                ORDER BY id ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'nickname' => isset($row['nickname']) ? (string)$row['nickname'] : null,
                'last_synced_at' => isset($row['last_synced_at']) ? (string)$row['last_synced_at'] : null,
            ];
        }, $rows);
    }

    /**
     * Executa sync automático nas contas desatualizadas elegíveis.
     *
     * @return array{
     *   max_age_hours:int,
     *   total:int,
     *   success:int,
     *   failed:int,
     *   skipped:int,
     *   details:list<array<string,mixed>>
     * }
     */
    public function run(int $limit = 20, ?int $onlyAccountId = null): array
    {
        $maxAgeHours = $this->getSyncMaxAgeHours();

        if ($onlyAccountId !== null && $onlyAccountId > 0) {
            $accounts = [['id' => $onlyAccountId, 'nickname' => null, 'last_synced_at' => null]];
        } else {
            $accounts = $this->getStaleActiveAccounts($limit);
        }

        $this->logger->info('ACCOUNT_AUTO_SYNC_START', 'Iniciando sync automático de contas', [
            'candidates' => count($accounts),
            'max_age_hours' => $maxAgeHours,
            'only_account_id' => $onlyAccountId,
        ]);

        $details = [];
        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            $accountId = (int)$account['id'];
            $status = $this->accountSync->getSyncStatus($accountId);

            if (empty($status['exists'])) {
                $skipped++;
                $details[] = [
                    'account_id' => $accountId,
                    'success' => false,
                    'skipped' => true,
                    'reason' => 'account_not_found',
                ];
                continue;
            }

            if (!empty($status['needs_reconnect']) || ($status['can_sync'] ?? false) === false) {
                $skipped++;
                $details[] = [
                    'account_id' => $accountId,
                    'nickname' => $status['nickname'] ?? $account['nickname'],
                    'success' => false,
                    'skipped' => true,
                    'reason' => 'needs_reconnect_or_cannot_sync',
                    'needs_reconnect' => (bool)($status['needs_reconnect'] ?? false),
                    'reconnect_url' => $status['reconnect_url'] ?? null,
                ];
                continue;
            }

            // Quando forçado por --account, sincroniza mesmo se ainda dentro do limiar.
            if ($onlyAccountId === null && ($status['needs_sync'] ?? true) === false) {
                $skipped++;
                $details[] = [
                    'account_id' => $accountId,
                    'nickname' => $status['nickname'] ?? $account['nickname'],
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'already_synced',
                ];
                continue;
            }

            $result = $this->accountSync->syncAccount($accountId);
            $details[] = $result;

            if (!empty($result['success'])) {
                $success++;
            } elseif (!empty($result['needs_reconnect'])) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        $summary = [
            'max_age_hours' => $maxAgeHours,
            'total' => count($accounts),
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'details' => $details,
        ];

        $this->logger->info('ACCOUNT_AUTO_SYNC_COMPLETE', 'Sync automático concluído', [
            'total' => $summary['total'],
            'success' => $summary['success'],
            'failed' => $summary['failed'],
            'skipped' => $summary['skipped'],
        ]);

        return $summary;
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
