<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

/**
 * Implementação padrão de AccountAccessPolicy (SEC-001).
 *
 * Regras de segurança:
 * - Query SEMPRE filtra por id E user_id simultaneamente — nunca só id.
 * - Não retorna access_token, refresh_token nem qualquer credencial.
 * - Auditoria de TODAS as decisões (allow e deny) via SecurityAuditLogger.
 * - Mensagem pública de negação é sempre genérica.
 */
final class DefaultAccountAccessPolicy implements AccountAccessPolicy
{
    private const STATUS_ACTIVE = 'active';

    public function __construct(
        private readonly PDO                  $db,
        private readonly SecurityAuditLogger  $auditLogger,
    ) {}

    public function authorize(
        AuthenticatedActor $actor,
        int $accountId,
        string $capability
    ): AuthorizedAccountContext {
        if ($accountId <= 0) {
            $this->denyAndLog($actor, $accountId, $capability, 'INVALID_ACCOUNT_ID');
        }

        $account = $this->fetchAccount($accountId, $actor->getUserId());

        if ($account === null) {
            $this->denyAndLog($actor, $accountId, $capability, 'ACCOUNT_NOT_FOUND_OR_NOT_OWNED');
        }

        if ($account['status'] !== self::STATUS_ACTIVE) {
            $this->denyAndLog($actor, $accountId, $capability, 'ACCOUNT_NOT_ACTIVE');
        }

        // ADR-001: organizationId == owner_user_id
        if ((int) $account['user_id'] !== $actor->getOrganizationId()) {
            $this->denyAndLog($actor, $accountId, $capability, 'ORGANIZATION_MISMATCH');
        }

        $capabilities = $this->resolveCapabilities($account);

        if (!in_array($capability, $capabilities, true)) {
            $this->denyAndLog($actor, $accountId, $capability, 'CAPABILITY_NOT_AUTHORIZED');
        }

        $context = AuthorizedAccountContext::create(
            accountId:      $accountId,
            ownerUserId:    (int) $account['user_id'],
            organizationId: (int) $account['user_id'],   // ADR-001
            marketplace:    'mercadolivre',
            siteId:         $account['site_id'] ?? null,
            status:         $account['status'],
            capabilities:   $capabilities,
        );

        $this->auditLogger->logDecision(
            actor:      $actor,
            accountId:  $accountId,
            capability: $capability,
            decision:   'allow',
            auditCode:  'ACCESS_ALLOWED',
        );

        return $context;
    }

    /**
     * Consulta a conta validando id E user_id simultaneamente.
     * Nunca retorna tokens ou credenciais.
     *
     * @return array<string, mixed>|null
     */
    private function fetchAccount(int $accountId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, site_id, status, nickname, ml_user_id
               FROM ml_accounts
              WHERE id = :id
                AND user_id = :userId'
        );
        $stmt->execute([
            'id'     => $accountId,
            'userId' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Resolve as capabilities disponíveis para a conta.
     * Atualmente todas as contas ativas têm o conjunto base de capacidades.
     *
     * @param array<string, mixed> $account
     * @return string[]
     */
    private function resolveCapabilities(array $account): array
    {
        return [
            'items:read',
            'items:write',
            'orders:read',
            'catalog:read',
            'catalog:write',
            'questions:read',
            'questions:write',
        ];
    }

    /**
     * Registra negação no audit e lança AccountAccessDeniedException.
     *
     * @throws AccountAccessDeniedException sempre.
     */
    private function denyAndLog(
        AuthenticatedActor $actor,
        int $accountId,
        string $capability,
        string $auditCode
    ): never {
        $this->auditLogger->logDecision(
            actor:      $actor,
            accountId:  $accountId,
            capability: $capability,
            decision:   'deny',
            auditCode:  $auditCode,
        );

        throw AccountAccessDeniedException::withAuditCode($auditCode);
    }
}
