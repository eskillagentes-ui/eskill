<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Facade para resolução de contexto de acesso a contas Mercado Livre (SEC-001).
 *
 * Responsabilidades:
 * - Recebe AuthenticatedActor (já confiável) + accountId + capability.
 * - Delega autorização para AccountAccessPolicy.
 * - Retorna AuthorizedAccountContext imutável.
 *
 * Proibições explícitas:
 * - NÃO lê $_GET, $_POST, $_SESSION, cookies ou headers.
 * - NÃO conecta diretamente à base de dados.
 * - NÃO conhece MercadoLivreClient, controllers, workers.
 * - NÃO silencia AccountAccessDeniedException.
 */
final class AccountContextResolver
{
    public function __construct(
        private readonly AccountAccessPolicy $policy,
    ) {}

    /**
     * Resolve um contexto autorizado para o ator e conta indicados.
     *
     * @param AuthenticatedActor $actor      Ator autenticado (de confiança, nunca de headers/GET).
     * @param int                $accountId  ID da conta ML a acessar.
     * @param string             $capability Capacidade requerida para a operação.
     *
     * @return AuthorizedAccountContext Contexto autorizado (sem tokens/segredos).
     *
     * @throws AccountAccessDeniedException Acesso negado com mensagem genérica.
     * @throws \InvalidArgumentException    accountId inválido (≤ 0).
     */
    public function resolve(
        AuthenticatedActor $actor,
        int                $accountId,
        string             $capability
    ): AuthorizedAccountContext {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('accountId deve ser um inteiro positivo.');
        }

        if ($capability === '') {
            throw new \InvalidArgumentException('capability não pode ser vazia.');
        }

        return $this->policy->authorize($actor, $accountId, $capability);
    }
}
