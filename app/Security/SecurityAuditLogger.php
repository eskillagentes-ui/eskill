<?php

declare(strict_types=1);

namespace App\Security;

use App\Services\AuditLogService;

/**
 * Serviço de auditoria especializado para decisões de autorização (SEC-001).
 *
 * Regras de segurança:
 * - NUNCA loga: access_token, refresh_token, client_secret, cabeçalho Authorization,
 *   sessão completa, ou qualquer credencial.
 * - Loga: ator, conta requisitada, capability, decisão, datetime, código interno.
 * - Toda decisão (allow e deny) deve ser auditada.
 */
final class SecurityAuditLogger
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Registra uma decisão de autorização de acesso a conta.
     *
     * @param string $decision   'allow' ou 'deny'
     * @param string $auditCode  Código interno para rastreabilidade.
     */
    public function logDecision(
        AuthenticatedActor $actor,
        int                $accountId,
        string             $capability,
        string             $decision,
        string             $auditCode,
    ): void {
        // Dados auditáveis — sem tokens, sem segredos
        $data = [
            'actor_user_id'     => $actor->getUserId(),
            'actor_org_id'      => $actor->getOrganizationId(),
            'actor_type'        => $actor->getActorType(),
            'actor_api_token_id'=> $actor->getApiTokenId(),
            'requested_account' => $accountId,
            'capability'        => $capability,
            'decision'          => $decision,
            'audit_code'        => $auditCode,
            'decided_at'        => date('Y-m-d H:i:s'),
        ];

        $action = sprintf(
            'sec001.account_access.%s.%s',
            $decision,
            strtolower($auditCode)
        );

        $this->auditLogService->log(
            action:    $action,
            userId:    $actor->getUserId(),
            accountId: $accountId,
            data:      $data,
            resource:  'ml_account',
        );
    }
}
