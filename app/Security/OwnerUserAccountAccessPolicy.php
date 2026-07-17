<?php

declare(strict_types=1);

namespace App\Security;

use App\Database;
use PDO;

/**
 * Política SEC-001: ownership via ml_accounts.user_id.
 * organizationId = ownerUserId (ADR-001 — limite transitório).
 */
final class OwnerUserAccountAccessPolicy implements AccountAccessPolicy
{
    private const ACTIVE_STATUSES = ['active'];

    public function __construct(private readonly ?PDO $pdo = null) {}

    public function authorize(
        int $actorUserId,
        int $accountId,
        string $capability
    ): AuthorizedAccountContext {
        if ($actorUserId <= 0) {
            $this->logDenial($actorUserId, $accountId, $capability, 'missing_actor');
            throw AccountAccessException::missingActor();
        }

        if ($accountId <= 0) {
            $this->logDenial($actorUserId, $accountId, $capability, 'invalid_account_id');
            throw AccountAccessException::notFound();
        }

        $row = $this->fetchAccount($accountId);
        if ($row === null) {
            $this->logDenial($actorUserId, $accountId, $capability, 'not_found');
            throw AccountAccessException::notFound();
        }

        $ownerUserId = (int) $row['user_id'];
        // ADR-001: organization_id transitório = owner_user_id
        $organizationId = $ownerUserId;
        $actorOrganizationId = $actorUserId;

        if ($ownerUserId !== $actorUserId || $organizationId !== $actorOrganizationId) {
            $this->logDenial($actorUserId, $accountId, $capability, 'ownership_mismatch');
            throw AccountAccessException::denied();
        }

        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, self::ACTIVE_STATUSES, true)) {
            $this->logDenial($actorUserId, $accountId, $capability, 'inactive_status:' . $status);
            throw AccountAccessException::inactive();
        }

        return new AuthorizedAccountContext(
            accountId: $accountId,
            ownerUserId: $ownerUserId,
            organizationId: $organizationId,
            actorUserId: $actorUserId,
            capability: $capability,
            status: $status,
            mlUserId: isset($row['ml_user_id']) ? (string) $row['ml_user_id'] : null,
            nickname: isset($row['nickname']) ? (string) $row['nickname'] : null,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAccount(int $accountId): ?array
    {
        try {
            $pdo = $this->pdo ?? Database::getInstance();
            $stmt = $pdo->prepare(
                'SELECT id, user_id, ml_user_id, nickname, status
                 FROM ml_accounts
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            log_error('AccountAccessPolicy DB failure', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            throw AccountAccessException::denied();
        }
    }

    private function logDenial(int $actorUserId, int $accountId, string $capability, string $reason): void
    {
        // Nunca logar tokens. Mensagem genérica no cliente; detalhe só em log interno.
        if (function_exists('log_warning')) {
            log_warning('SEC-001 account access denied', [
                'actor_user_id' => $actorUserId,
                'account_id' => $accountId,
                'capability' => $capability,
                'reason' => $reason,
            ]);
        }
    }
}
