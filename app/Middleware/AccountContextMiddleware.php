<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Security\AccountAccessException;
use App\Security\AccountContextResolver;

/**
 * Account Context Middleware
 * Ensures all requests have proper account context for multi-tenant isolation (SEC-001).
 */
class AccountContextMiddleware
{
    /**
     * Handle the request and inject account context
     */
    public function handle(): void
    {
        $accountId = null;
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

        if ($userId > 0) {
            try {
                $context = (new AccountContextResolver())->authorizeForCurrentActor('session.context');
                $accountId = $context->accountId();
                $_SESSION['current_account_id'] = $accountId;
                $_SESSION['active_ml_account_id'] = $accountId;
            } catch (AccountAccessException $e) {
                $accountId = null;
            }
        }

        if (!defined('CURRENT_ACCOUNT_ID')) {
            define('CURRENT_ACCOUNT_ID', $accountId);
        }

        if ($accountId) {
            log_debug('Request context', [
                'service' => 'AccountContextMiddleware',
                'user_id' => $userId,
                'account_id' => $accountId,
            ]);
        }
    }

    /**
     * Get current account ID
     */
    public static function getCurrentAccountId(): ?int
    {
        return defined('CURRENT_ACCOUNT_ID') ? CURRENT_ACCOUNT_ID : null;
    }

    /**
     * Switch to a different account (SEC-001 + audit).
     */
    public static function switchAccount(int $accountId, int $userId): bool
    {
        try {
            (new AccountContextResolver())->switchActiveAccount($userId, $accountId);
            return true;
        } catch (AccountAccessException $e) {
            log_warning('Account switch denied', [
                'service' => 'AccountContextMiddleware',
                'user_id' => $userId,
                'account_id' => $accountId,
                'error_code' => $e->errorCode(),
            ]);
            return false;
        } catch (\Throwable $e) {
            log_error('Failed to switch account', [
                'service' => 'AccountContextMiddleware',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get all accounts for current user
     */
    public static function getUserAccounts(int $userId): array
    {
        try {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("
                SELECT
                    id,
                    ml_user_id,
                    nickname,
                    email,
                    created_at,
                    (id = :current_id) as is_current
                FROM ml_accounts
                WHERE user_id = :user_id
                ORDER BY created_at ASC
            ");
            $stmt->execute([
                'user_id' => $userId,
                'current_id' => $_SESSION['current_account_id'] ?? 0
            ]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('Failed to get user accounts', ['service' => 'AccountContextMiddleware', 'error' => $e->getMessage()]);
            return [];
        }
    }
}
