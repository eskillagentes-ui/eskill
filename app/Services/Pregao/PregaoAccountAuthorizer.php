<?php

declare(strict_types=1);

namespace App\Services\Pregao;

/**
 * Resolve uma conta do Pregão exclusivamente dentro da lista do usuário autenticado.
 */
final class PregaoAccountAuthorizer
{
    /**
     * @param list<array{id?: int|string}> $userAccounts
     */
    public function resolve(?int $requestedId, ?int $activeId, array $userAccounts): ?int
    {
        if ($requestedId !== null) {
            if ($requestedId <= 0) {
                return null;
            }
            $candidate = $requestedId;
        } elseif ($activeId !== null && $activeId > 0) {
            $candidate = $activeId;
        } else {
            $candidate = null;
            foreach ($userAccounts as $account) {
                if (!isset($account['id'])) {
                    continue;
                }
                $accountId = filter_var(
                    $account['id'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($accountId !== false) {
                    $candidate = $accountId;
                    break;
                }
            }
            if ($candidate === null) {
                return null;
            }
        }

        foreach ($userAccounts as $account) {
            if (!isset($account['id'])) {
                continue;
            }

            $accountId = filter_var(
                $account['id'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($accountId !== false && $accountId === $candidate) {
                return $candidate;
            }
        }

        return null;
    }
}
