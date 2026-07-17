<?php

declare(strict_types=1);

namespace App\Security;

interface AccountAccessPolicy
{
    /**
     * Autoriza o ator a usar a conta ML com a capability informada.
     *
     * @throws AccountAccessException
     */
    public function authorize(
        int $actorUserId,
        int $accountId,
        string $capability
    ): AuthorizedAccountContext;
}
