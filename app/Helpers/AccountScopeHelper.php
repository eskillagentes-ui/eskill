<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Dashboard queries must always run against ONE active ML account.
 * Never allow an unscoped "all stores" fallback (last-write / mixed totals).
 */
final class AccountScopeHelper
{
    public static function activeAccountId(): ?int
    {
        $id = SessionHelper::getActiveAccountId();
        return ($id !== null && $id > 0) ? $id : null;
    }

    /**
     * @return array{sql: string, params: array<string, int>}
     */
    public static function constrain(string $column, ?int $accountId, string $placeholder = 'account_id'): array
    {
        if ($accountId === null || $accountId <= 0) {
            return ['sql' => ' AND 1 = 0', 'params' => []];
        }

        return [
            'sql' => ' AND ' . $column . ' = :' . $placeholder,
            'params' => [$placeholder => $accountId],
        ];
    }
}
