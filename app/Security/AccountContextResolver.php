<?php

declare(strict_types=1);

namespace App\Security;

use App\Database;
use PDO;

/**
 * Resolve conta ML solicitada (sessão / header / query / body) e autoriza via policy.
 * Header/GET/POST nunca contornam a policy — apenas indicam o account_id candidato.
 */
final class AccountContextResolver
{
    public function __construct(
        private readonly AccountAccessPolicy $policy = new OwnerUserAccountAccessPolicy(),
        private readonly ?PDO $pdo = null,
    ) {}

    /**
     * Resolve ator atual: sessão web ou API Bearer (API_USER_ID).
     */
    public function resolveActorUserId(): ?int
    {
        if (isset($_SERVER['API_USER_ID'])) {
            $apiUserId = (int) $_SERVER['API_USER_ID'];
            if ($apiUserId > 0) {
                return $apiUserId;
            }
        }

        if (isset($_SESSION['user_id'])) {
            $sessionUserId = (int) $_SESSION['user_id'];
            if ($sessionUserId > 0) {
                return $sessionUserId;
            }
        }

        return null;
    }

    /**
     * Candidato de conta: sessão → header → query/body. Sem ownership ainda.
     */
    public function resolveRequestedAccountId(): ?int
    {
        $sessionId = $_SESSION['active_ml_account_id']
            ?? $_SESSION['current_account_id']
            ?? $_SESSION['account_id']
            ?? null;
        if ($sessionId !== null) {
            $id = (int) $sessionId;
            if ($id > 0) {
                return $id;
            }
        }

        $header = $_SERVER['HTTP_X_ML_ACCOUNT_ID'] ?? '';
        if (is_string($header) && $header !== '') {
            $id = (int) $header;
            if ($id > 0) {
                return $id;
            }
        }

        $fromGet = (int) ($_GET['ml_account_id'] ?? $_GET['account_id'] ?? 0);
        if ($fromGet > 0) {
            return $fromGet;
        }

        $fromPost = (int) ($_POST['ml_account_id'] ?? $_POST['account_id'] ?? 0);
        if ($fromPost > 0) {
            return $fromPost;
        }

        return null;
    }

    /**
     * Autoriza conta para o ator HTTP/API atual.
     *
     * @throws AccountAccessException
     */
    public function authorizeForCurrentActor(
        string $capability = 'read',
        ?int $explicitAccountId = null
    ): AuthorizedAccountContext {
        $actorUserId = $this->resolveActorUserId();
        if ($actorUserId === null) {
            throw AccountAccessException::missingActor();
        }

        $accountId = $explicitAccountId ?? $this->resolveRequestedAccountId();
        if ($accountId === null || $accountId <= 0) {
            $accountId = $this->resolveDefaultOwnedAccountId($actorUserId);
        }

        if ($accountId === null || $accountId <= 0) {
            throw AccountAccessException::denied();
        }

        return $this->policy->authorize($actorUserId, $accountId, $capability);
    }

    /**
     * Workers/CLI: exigem account_id explícito + actor de serviço.
     *
     * @throws AccountAccessException
     */
    public function authorizeForWorker(
        int $serviceActorUserId,
        int $accountId,
        string $capability = 'read'
    ): AuthorizedAccountContext {
        if ($accountId <= 0) {
            throw AccountAccessException::missingAccountForWorker();
        }
        if ($serviceActorUserId <= 0) {
            throw AccountAccessException::missingActor();
        }

        return $this->policy->authorize($serviceActorUserId, $accountId, $capability);
    }

    /**
     * Troca de conta ativa na sessão — só se autorizada; gera auditoria.
     *
     * @throws AccountAccessException
     */
    public function switchActiveAccount(int $actorUserId, int $accountId): AuthorizedAccountContext
    {
        $previous = isset($_SESSION['active_ml_account_id'])
            ? (int) $_SESSION['active_ml_account_id']
            : null;

        $context = $this->policy->authorize($actorUserId, $accountId, 'switch');

        $_SESSION['active_ml_account_id'] = $context->accountId();
        $_SESSION['current_account_id'] = $context->accountId();
        $_SESSION['account_id'] = $context->accountId();

        $this->auditAccountSwitch($actorUserId, $previous, $context);

        return $context;
    }

    private function resolveDefaultOwnedAccountId(int $actorUserId): ?int
    {
        try {
            $pdo = $this->pdo ?? Database::getInstance();
            $stmt = $pdo->prepare(
                "SELECT id FROM ml_accounts
                 WHERE user_id = :user_id AND status = 'active'
                 ORDER BY updated_at DESC
                 LIMIT 1"
            );
            $stmt->execute(['user_id' => $actorUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? (int) $row['id'] : null;
        } catch (\Throwable $e) {
            log_error('AccountContextResolver default account failed', [
                'actor_user_id' => $actorUserId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function auditAccountSwitch(
        int $actorUserId,
        ?int $previousAccountId,
        AuthorizedAccountContext $context
    ): void {
        try {
            $pdo = $this->pdo ?? Database::getInstance();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, ml_account_id, action, resource, ip_address, user_agent, data, details)
                 VALUES (:user_id, :ml_account_id, :action, :resource, :ip, :ua, :data, :details)'
            );
            $stmt->execute([
                'user_id' => $actorUserId,
                'ml_account_id' => $context->accountId(),
                'action' => 'account_switch',
                'resource' => 'ml_accounts',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => isset($_SERVER['HTTP_USER_AGENT'])
                    ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500)
                    : null,
                'data' => json_encode([
                    'previous_account_id' => $previousAccountId,
                    'new_account_id' => $context->accountId(),
                    'organization_id' => $context->organizationId(),
                ], JSON_UNESCAPED_UNICODE),
                'details' => 'SEC-001 authorized account switch',
            ]);
        } catch (\Throwable $e) {
            log_warning('SEC-001 account switch audit failed', [
                'actor_user_id' => $actorUserId,
                'account_id' => $context->accountId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
