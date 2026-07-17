<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Interface central da política de acesso a contas Mercado Livre (SEC-001).
 *
 * Regras:
 * - Recebe um ator autenticado e confiável, NÃO obtém identidade de
 *   $_GET, $_POST, $_SESSION ou headers.
 * - Retorna um contexto autorizado imutável se o acesso é permitido.
 * - Lança AccountAccessDeniedException (sem revelar dados internos) se negado.
 * - Toda decisão (allow/deny) deve ser auditada.
 */
interface AccountAccessPolicy
{
    /**
     * Autoriza o ator para a capability solicitada na conta indicada.
     *
     * @param AuthenticatedActor $actor      Ator autenticado de confiança.
     * @param int                $accountId  Conta Mercado Livre requisitada.
     * @param string             $capability Capacidade requerida (ex: 'items:read').
     *
     * @return AuthorizedAccountContext Contexto autorizado (sem tokens/segredos).
     *
     * @throws AccountAccessDeniedException Acesso negado com mensagem genérica.
     */
    public function authorize(
        AuthenticatedActor $actor,
        int $accountId,
        string $capability
    ): AuthorizedAccountContext;
}
