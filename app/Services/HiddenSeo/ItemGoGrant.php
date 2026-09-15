<?php

declare(strict_types=1);

namespace App\Services\HiddenSeo;

use App\Database;
use App\Exception\UnsafeOperationException;
use PDO;
use Throwable;

/**
 * Autorização GO item a item: uma MLB ativa por conta, TTL, consume no apply.
 *
 * FACILYTY (1335) só escreve no ML com grant ativa para aquele MLB.
 */
final class ItemGoGrant implements ItemGoGrantLookup
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';
    public const DEFAULT_TTL_SECONDS = 900;

    private PDO $db;
    private int $now;

    public function __construct(?PDO $db = null, ?int $now = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->now = $now ?? time();
        $this->ensureSchema();
    }

    public function isValidMlb(string $mlbId): bool
    {
        return preg_match('/^MLB[0-9]{6,}$/', strtoupper(trim($mlbId))) === 1;
    }

    /**
     * Emite uma grant para um MLB. Revoga qualquer grant ativa da mesma conta.
     *
     * @return array<string, mixed>
     */
    public function issue(int $accountId, string $mlbId, ?int $ttlSeconds = null, ?int $issuedBy = null): array
    {
        if ($accountId <= 0) {
            throw new UnsafeOperationException('ItemGoGrant exige account_id válido.');
        }
        $mlbId = strtoupper(trim($mlbId));
        if (!$this->isValidMlb($mlbId)) {
            throw new UnsafeOperationException('ItemGoGrant exige um MLB válido (um por vez).');
        }

        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds();
        $ttl = max(1, $ttl);
        $issuedAt = $this->nowSql();
        $expiresAt = date('Y-m-d H:i:s', $this->now + $ttl);

        $this->revokeActive($accountId);

        $stmt = $this->db->prepare(
            'INSERT INTO item_go_grants
                (account_id, mlb_id, status, issued_at, expires_at, consumed_at, issued_by)
             VALUES (?, ?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([
            $accountId,
            $mlbId,
            self::STATUS_ACTIVE,
            $issuedAt,
            $expiresAt,
            $issuedBy,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'account_id' => $accountId,
            'mlb_id' => $mlbId,
            'status' => self::STATUS_ACTIVE,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'issued_by' => $issuedBy,
            'ttl_seconds' => $ttl,
        ];
    }

    public function hasActive(int $accountId, string $mlbId): bool
    {
        return $this->findActive($accountId, $mlbId) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(int $accountId, string $mlbId): ?array
    {
        $mlbId = strtoupper(trim($mlbId));
        if ($accountId <= 0 || !$this->isValidMlb($mlbId)) {
            return null;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, account_id, mlb_id, status, issued_at, expires_at, consumed_at, issued_by
                 FROM item_go_grants
                 WHERE account_id = ? AND mlb_id = ? AND status = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute([$accountId, $mlbId, self::STATUS_ACTIVE]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }
        if (!$this->rowIsLive($row)) {
            $this->markExpired((int) $row['id']);
            return null;
        }

        return $row;
    }

    /**
     * Grant ativa da conta (no máximo uma). Null se expirada/ausente.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveForAccount(int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, account_id, mlb_id, status, issued_at, expires_at, consumed_at, issued_by
                 FROM item_go_grants
                 WHERE account_id = ? AND status = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute([$accountId, self::STATUS_ACTIVE]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }
        if (!$this->rowIsLive($row)) {
            $this->markExpired((int) $row['id']);
            return null;
        }

        return $row;
    }

    public function consume(int $accountId, string $mlbId): bool
    {
        $row = $this->findActive($accountId, $mlbId);
        if ($row === null) {
            return false;
        }

        $consumedAt = $this->nowSql();
        try {
            $stmt = $this->db->prepare(
                'UPDATE item_go_grants
                 SET status = ?, consumed_at = ?
                 WHERE id = ? AND status = ? AND consumed_at IS NULL'
            );
            $stmt->execute([
                self::STATUS_CONSUMED,
                $consumedAt,
                (int) $row['id'],
                self::STATUS_ACTIVE,
            ]);
        } catch (Throwable) {
            return false;
        }

        return $stmt->rowCount() > 0;
    }

    public function revokeActive(int $accountId): int
    {
        if ($accountId <= 0) {
            return 0;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE item_go_grants SET status = ? WHERE account_id = ? AND status = ?'
            );
            $stmt->execute([self::STATUS_REVOKED, $accountId, self::STATUS_ACTIVE]);
        } catch (Throwable) {
            return 0;
        }

        return $stmt->rowCount();
    }

    public function defaultTtlSeconds(): int
    {
        $raw = getenv('ITEM_GO_GRANT_TTL_SECONDS');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['ITEM_GO_GRANT_TTL_SECONDS'] ?? (string) self::DEFAULT_TTL_SECONDS;
        }
        $ttl = (int) $raw;

        return $ttl > 0 ? $ttl : self::DEFAULT_TTL_SECONDS;
    }

    public function ensureSchema(): void
    {
        $driver = '';
        try {
            $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            $driver = '';
        }

        if ($driver === 'sqlite') {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS item_go_grants (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL,
                    mlb_id TEXT NOT NULL,
                    status TEXT NOT NULL,
                    issued_at TEXT NOT NULL,
                    expires_at TEXT NOT NULL,
                    consumed_at TEXT NULL,
                    issued_by INTEGER NULL
                )'
            );
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS item_go_grants (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                mlb_id VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL,
                issued_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL,
                issued_by INT NULL,
                KEY idx_igg_account_status (account_id, status),
                KEY idx_igg_account_mlb (account_id, mlb_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowIsLive(array $row): bool
    {
        if ((string) ($row['status'] ?? '') !== self::STATUS_ACTIVE) {
            return false;
        }
        if (($row['consumed_at'] ?? null) !== null && (string) $row['consumed_at'] !== '') {
            return false;
        }
        $expires = strtotime((string) ($row['expires_at'] ?? ''));
        if ($expires === false || $expires <= $this->now) {
            return false;
        }

        return true;
    }

    private function markExpired(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE item_go_grants SET status = ? WHERE id = ? AND status = ?'
            );
            $stmt->execute([self::STATUS_EXPIRED, $id, self::STATUS_ACTIVE]);
        } catch (Throwable) {
            return;
        }
    }

    private function nowSql(): string
    {
        return date('Y-m-d H:i:s', $this->now);
    }
}
