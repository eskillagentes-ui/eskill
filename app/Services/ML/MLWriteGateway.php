<?php

declare(strict_types=1);

namespace App\Services\ML;

use App\Database;
use App\Services\Sentinela\Sentinela;
use PDO;
use Throwable;

/**
 * Portão ÚNICO de escrita no Mercado Livre — fail-closed + dry-run default.
 *
 * Ordem das guardas (Onda 4 / T3):
 *  1. Kill switch global ML_WRITE_AUTOMATION
 *  2. Flag por tipo de ação (ML_WRITE_*)
 *  3. Veto Sentinela / governança TRAVADA
 *  4. Allowlist de MLBs
 *  5. Rate limit diário
 *  6. Dry-run (default true) — nunca chama API
 *  7. Audit log imutável + estado anterior para rollback
 *
 * Nenhuma escrita real nesta onda: dry_run default e kill switch false.
 */
final class MLWriteGateway
{
    public const ACTION_PAUSE = 'pause_item';
    public const ACTION_PRICE = 'update_price';
    public const ACTION_ATTRIBUTES = 'update_attributes';
    public const ACTION_ADS = 'ads_mutate';

    private PDO $db;
    /** @var object|null objeto com semaforoGlobal(int): string */
    private ?object $sentinela;
    /** @var callable|null fn(string $method, string $path, array $payload): mixed */
    private $apiCaller;
    private bool $forceDryRun;

    public function __construct(
        ?PDO $db = null,
        ?object $sentinela = null,
        ?callable $apiCaller = null,
        ?bool $forceDryRun = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->sentinela = $sentinela;
        $this->apiCaller = $apiCaller;
        $this->forceDryRun = $forceDryRun ?? true; // Onda 4: sempre dry-run por default no construtor de prod
        $this->ensureSchema();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{
     *   account_id: int,
     *   user_id?: int,
     *   mlb_id?: string,
     *   dry_run?: bool,
     *   before?: array<string, mixed>|null,
     *   expected_after?: array<string, mixed>|null
     * } $context
     * @return array<string, mixed>
     */
    public function execute(string $action, array $payload, array $context): array
    {
        $accountId = (int) ($context['account_id'] ?? 0);
        $userId = (int) ($context['user_id'] ?? 0);
        $mlbId = strtoupper((string) ($context['mlb_id'] ?? $payload['mlb_id'] ?? ''));
        $dryRun = $this->forceDryRun || (bool) ($context['dry_run'] ?? true);
        $before = is_array($context['before'] ?? null) ? $context['before'] : null;
        $expectedAfter = is_array($context['expected_after'] ?? null) ? $context['expected_after'] : null;

        $guards = [];

        // a) Kill switch
        if (!$this->isWriteAutomationEnabled()) {
            $guards[] = ['guard' => 'kill_switch', 'pass' => false, 'detail' => 'ML_WRITE_AUTOMATION=false'];
            return $this->blocked($action, $payload, $context, $guards, 'kill_switch', $dryRun, $before, $expectedAfter);
        }
        $guards[] = ['guard' => 'kill_switch', 'pass' => true];

        // b) Flag por ação
        $flag = $this->actionFlag($action);
        if (!$this->isActionFlagEnabled($flag)) {
            $guards[] = ['guard' => 'action_flag', 'pass' => false, 'detail' => $flag . '=false'];
            return $this->blocked($action, $payload, $context, $guards, 'action_flag', $dryRun, $before, $expectedAfter);
        }
        $guards[] = ['guard' => 'action_flag', 'pass' => true, 'detail' => $flag];

        // c) Sentinela / TRAVADA
        $gov = $this->governanceBlockReason($accountId);
        if ($gov !== null) {
            $guards[] = ['guard' => 'sentinela_governance', 'pass' => false, 'detail' => $gov];
            return $this->blocked($action, $payload, $context, $guards, 'sentinela_governance', $dryRun, $before, $expectedAfter);
        }
        $guards[] = ['guard' => 'sentinela_governance', 'pass' => true];

        // d) Allowlist
        if ($mlbId === '' || !$this->isMlbAllowed($accountId, $mlbId)) {
            $guards[] = ['guard' => 'allowlist', 'pass' => false, 'detail' => $mlbId !== '' ? $mlbId : 'mlb_missing'];
            return $this->blocked($action, $payload, $context, $guards, 'allowlist', $dryRun, $before, $expectedAfter);
        }
        $guards[] = ['guard' => 'allowlist', 'pass' => true, 'detail' => $mlbId];

        // e) Rate limit
        $daily = $this->countWritesToday($accountId);
        $maxDaily = $this->maxWritesPerDay();
        if ($daily >= $maxDaily) {
            $guards[] = ['guard' => 'rate_limit', 'pass' => false, 'detail' => "{$daily}/{$maxDaily}"];
            return $this->blocked($action, $payload, $context, $guards, 'rate_limit', $dryRun, $before, $expectedAfter);
        }
        $guards[] = ['guard' => 'rate_limit', 'pass' => true, 'detail' => "{$daily}/{$maxDaily}"];

        // f) Dry-run
        if ($dryRun) {
            $guards[] = ['guard' => 'dry_run', 'pass' => true, 'detail' => 'intention_logged_no_api'];
            $auditId = $this->persistAudit([
                'account_id' => $accountId,
                'user_id' => $userId,
                'mlb_id' => $mlbId,
                'action' => $action,
                'payload' => $payload,
                'before_state' => $before,
                'expected_after' => $expectedAfter,
                'result' => 'dry_run',
                'blocked_by' => null,
                'guards' => $guards,
                'api_called' => false,
            ]);

            return [
                'success' => true,
                'dry_run' => true,
                'api_called' => false,
                'blocked_by' => null,
                'audit_id' => $auditId,
                'guards' => $guards,
                'intention' => [
                    'action' => $action,
                    'mlb_id' => $mlbId,
                    'payload' => $payload,
                    'before' => $before,
                    'expected_after' => $expectedAfter,
                ],
            ];
        }

        // g) Execução real (só se dry_run=false E todas as flags — não usado na Onda 4)
        $guards[] = ['guard' => 'dry_run', 'pass' => false, 'detail' => 'live_execution'];
        if ($this->apiCaller === null) {
            return $this->blocked($action, $payload, $context, $guards, 'no_api_caller', false, $before, $expectedAfter);
        }

        try {
            $apiResult = ($this->apiCaller)($action, $payload, $context);
            $auditId = $this->persistAudit([
                'account_id' => $accountId,
                'user_id' => $userId,
                'mlb_id' => $mlbId,
                'action' => $action,
                'payload' => $payload,
                'before_state' => $before,
                'expected_after' => $expectedAfter,
                'result' => 'applied',
                'blocked_by' => null,
                'guards' => $guards,
                'api_called' => true,
                'api_result' => $apiResult,
            ]);

            return [
                'success' => true,
                'dry_run' => false,
                'api_called' => true,
                'audit_id' => $auditId,
                'guards' => $guards,
                'api_result' => $apiResult,
            ];
        } catch (Throwable $e) {
            $auditId = $this->persistAudit([
                'account_id' => $accountId,
                'user_id' => $userId,
                'mlb_id' => $mlbId,
                'action' => $action,
                'payload' => $payload,
                'before_state' => $before,
                'expected_after' => $expectedAfter,
                'result' => 'error',
                'blocked_by' => 'api_exception',
                'guards' => $guards,
                'api_called' => true,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'dry_run' => false,
                'api_called' => true,
                'error' => $e->getMessage(),
                'audit_id' => $auditId,
                'guards' => $guards,
            ];
        }
    }

    public function isWriteAutomationEnabled(): bool
    {
        $raw = $_ENV['ML_WRITE_AUTOMATION'] ?? getenv('ML_WRITE_AUTOMATION') ?: 'false';
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public function isDryRunDefault(): bool
    {
        return $this->forceDryRun || filter_var(
            $_ENV['ML_WRITE_DRY_RUN'] ?? getenv('ML_WRITE_DRY_RUN') ?: 'true',
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * @return array<string, bool>
     */
    public function actionFlags(): array
    {
        return [
            'ML_WRITE_PAUSE' => $this->isActionFlagEnabled('ML_WRITE_PAUSE'),
            'ML_WRITE_PRICE' => $this->isActionFlagEnabled('ML_WRITE_PRICE'),
            'ML_WRITE_ATTRIBUTES' => $this->isActionFlagEnabled('ML_WRITE_ATTRIBUTES'),
            'ML_WRITE_ADS' => $this->isActionFlagEnabled('ML_WRITE_ADS'),
        ];
    }

    public function maxWritesPerDay(): int
    {
        return max(1, (int) ($_ENV['ML_WRITE_MAX_PER_DAY'] ?? getenv('ML_WRITE_MAX_PER_DAY') ?: 10));
    }

    public function countWritesToday(int $accountId): int
    {
        try {
            $dayExpr = $this->isSqlite()
                ? "date(created_at) = date('now')"
                : 'created_at >= CURDATE()';
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM ml_write_audit
                 WHERE account_id = ? AND result IN ('applied','dry_run')
                   AND {$dayExpr}"
            );
            $stmt->execute([$accountId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<string>
     */
    public function listAllowlist(int $accountId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT mlb_id FROM ml_write_allowlist WHERE account_id = ? AND active = 1 ORDER BY mlb_id'
            );
            $stmt->execute([$accountId]);
            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    public function addToAllowlist(int $accountId, string $mlbId, int $userId): bool
    {
        $mlbId = strtoupper(trim($mlbId));
        if (!preg_match('/^MLB\d+$/', $mlbId)) {
            return false;
        }
        $now = $this->nowSql();
        if ($this->isSqlite()) {
            $stmt = $this->db->prepare(
                "INSERT INTO ml_write_allowlist (account_id, mlb_id, active, created_by, created_at, updated_at)
                 VALUES (?, ?, 1, ?, {$now}, {$now})
                 ON CONFLICT(account_id, mlb_id) DO UPDATE SET active = 1, updated_at = {$now}"
            );
            return $stmt->execute([$accountId, $mlbId, $userId]);
        }
        $stmt = $this->db->prepare(
            "INSERT INTO ml_write_allowlist (account_id, mlb_id, active, created_by, created_at)
             VALUES (?, ?, 1, ?, {$now})
             ON DUPLICATE KEY UPDATE active = 1, updated_at = {$now}"
        );
        return $stmt->execute([$accountId, $mlbId, $userId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentAudit(int $accountId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        try {
            $stmt = $this->db->prepare(
                "SELECT id, account_id, user_id, mlb_id, action, result, blocked_by,
                        api_called, dry_run, payload_json, before_json, expected_after_json,
                        guards_json, created_at
                 FROM ml_write_audit
                 WHERE account_id = ?
                 ORDER BY id DESC LIMIT {$limit}"
            );
            $stmt->execute([$accountId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row['payload'] = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
                $row['before'] = json_decode((string) ($row['before_json'] ?? 'null'), true);
                $row['expected_after'] = json_decode((string) ($row['expected_after_json'] ?? 'null'), true);
                $row['guards'] = json_decode((string) ($row['guards_json'] ?? '[]'), true) ?: [];
                unset($row['payload_json'], $row['before_json'], $row['expected_after_json'], $row['guards_json']);
            }
            unset($row);
            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    public function isMlbAllowed(int $accountId, string $mlbId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM ml_write_allowlist WHERE account_id = ? AND mlb_id = ? AND active = 1 LIMIT 1'
            );
            $stmt->execute([$accountId, strtoupper($mlbId)]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function actionFlag(string $action): string
    {
        return match ($action) {
            self::ACTION_PAUSE => 'ML_WRITE_PAUSE',
            self::ACTION_PRICE => 'ML_WRITE_PRICE',
            self::ACTION_ATTRIBUTES => 'ML_WRITE_ATTRIBUTES',
            self::ACTION_ADS => 'ML_WRITE_ADS',
            default => 'ML_WRITE_PAUSE',
        };
    }

    private function isActionFlagEnabled(string $flag): bool
    {
        $raw = $_ENV[$flag] ?? getenv($flag) ?: 'false';
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private function governanceBlockReason(int $accountId): ?string
    {
        // TRAVADA no último Raio X
        try {
            $stmt = $this->db->prepare(
                'SELECT account_status FROM account_xray_reports
                 WHERE account_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
            );
            $stmt->execute([$accountId]);
            $status = strtoupper((string) ($stmt->fetchColumn() ?: ''));
            if ($status === 'TRAVADA') {
                return 'governance_TRAVADA';
            }
        } catch (Throwable) {
            // ignore
        }

        try {
            $sentinela = $this->sentinela ?? new Sentinela();
            if (!is_callable([$sentinela, 'semaforoGlobal'])) {
                return 'sentinela_unavailable';
            }
            $semaforo = $sentinela->semaforoGlobal($accountId);
            if ($semaforo !== 'verde') {
                return 'sentinela_' . $semaforo;
            }
        } catch (Throwable $e) {
            return 'sentinela_unavailable';
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $guards
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $expectedAfter
     * @return array<string, mixed>
     */
    private function blocked(
        string $action,
        array $payload,
        array $context,
        array $guards,
        string $blockedBy,
        bool $dryRun,
        ?array $before,
        ?array $expectedAfter
    ): array {
        $auditId = $this->persistAudit([
            'account_id' => (int) ($context['account_id'] ?? 0),
            'user_id' => (int) ($context['user_id'] ?? 0),
            'mlb_id' => strtoupper((string) ($context['mlb_id'] ?? $payload['mlb_id'] ?? '')),
            'action' => $action,
            'payload' => $payload,
            'before_state' => $before,
            'expected_after' => $expectedAfter,
            'result' => 'blocked',
            'blocked_by' => $blockedBy,
            'guards' => $guards,
            'api_called' => false,
            'dry_run' => $dryRun,
        ]);

        return [
            'success' => false,
            'dry_run' => $dryRun,
            'api_called' => false,
            'blocked_by' => $blockedBy,
            'audit_id' => $auditId,
            'guards' => $guards,
            'intention' => [
                'action' => $action,
                'payload' => $payload,
                'before' => $before,
                'expected_after' => $expectedAfter,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function persistAudit(array $row): int
    {
        $now = $this->nowSql();
        $stmt = $this->db->prepare(
            "INSERT INTO ml_write_audit
             (account_id, user_id, mlb_id, action, result, blocked_by, api_called, dry_run,
              payload_json, before_json, expected_after_json, guards_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, {$now})"
        );
        $stmt->execute([
            (int) ($row['account_id'] ?? 0),
            (int) ($row['user_id'] ?? 0),
            (string) ($row['mlb_id'] ?? ''),
            (string) ($row['action'] ?? ''),
            (string) ($row['result'] ?? 'blocked'),
            $row['blocked_by'] ?? null,
            !empty($row['api_called']) ? 1 : 0,
            !empty($row['dry_run']) || (($row['result'] ?? '') === 'dry_run') ? 1 : 0,
            json_encode($row['payload'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($row['before_state'] ?? null, JSON_UNESCAPED_UNICODE),
            json_encode($row['expected_after'] ?? null, JSON_UNESCAPED_UNICODE),
            json_encode($row['guards'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function isSqlite(): bool
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    private function nowSql(): string
    {
        return $this->isSqlite() ? "datetime('now')" : 'NOW()';
    }

    private function ensureSchema(): void
    {
        if ($this->isSqlite()) {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS ml_write_audit (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INT NOT NULL,
                    user_id INT NOT NULL DEFAULT 0,
                    mlb_id TEXT NOT NULL DEFAULT "",
                    action TEXT NOT NULL,
                    result TEXT NOT NULL,
                    blocked_by TEXT NULL,
                    api_called INT NOT NULL DEFAULT 0,
                    dry_run INT NOT NULL DEFAULT 1,
                    payload_json TEXT NULL,
                    before_json TEXT NULL,
                    expected_after_json TEXT NULL,
                    guards_json TEXT NULL,
                    created_at TEXT NOT NULL
                )'
            );
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS ml_write_allowlist (
                    account_id INT NOT NULL,
                    mlb_id TEXT NOT NULL,
                    active INT NOT NULL DEFAULT 1,
                    created_by INT NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NULL,
                    PRIMARY KEY (account_id, mlb_id)
                )'
            );
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ml_write_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                user_id INT NOT NULL DEFAULT 0,
                mlb_id VARCHAR(32) NOT NULL DEFAULT "",
                action VARCHAR(64) NOT NULL,
                result VARCHAR(32) NOT NULL,
                blocked_by VARCHAR(64) NULL,
                api_called TINYINT(1) NOT NULL DEFAULT 0,
                dry_run TINYINT(1) NOT NULL DEFAULT 1,
                payload_json JSON NULL,
                before_json JSON NULL,
                expected_after_json JSON NULL,
                guards_json JSON NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_account_created (account_id, created_at),
                INDEX idx_mlb (mlb_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ml_write_allowlist (
                account_id INT NOT NULL,
                mlb_id VARCHAR(32) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (account_id, mlb_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
