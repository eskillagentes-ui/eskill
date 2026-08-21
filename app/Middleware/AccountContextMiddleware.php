<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Account Context Middleware
 * Ensures all requests have proper account context for multi-tenant isolation
 */
class AccountContextMiddleware
{
    /**
     * Handle the request and inject account context
     */
    public function handle(): void
    {
        $accountId = \App\Helpers\SessionHelper::getActiveAccountId();
        if ($accountId) {
            $_SESSION['current_account_id'] = $accountId;
        }
        
        // Store in global context for easy access
        if (!defined('CURRENT_ACCOUNT_ID')) {
            define('CURRENT_ACCOUNT_ID', $accountId);
        }
        
        // Log account context for debugging
        if ($accountId) {
            log_debug('Request context', ['service' => 'AccountContextMiddleware', 'user_id' => $_SESSION['user_id'], 'account_id' => $accountId]);
        }
    }
    
    /**
     * Get default account for a user
     */
    private function getDefaultAccountForUser(int $userId): ?int
    {
        try {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("
                SELECT id FROM ml_accounts 
                WHERE user_id = :user_id 
                ORDER BY created_at ASC 
                LIMIT 1
            ");
            $stmt->execute(['user_id' => $userId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return $account['id'] ?? null;
        } catch (\Exception $e) {
            log_error('Failed to get default account', ['service' => 'AccountContextMiddleware', 'error' => $e->getMessage()]);
            return null;
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
     * Switch to a different account
     */
    public static function switchAccount(int $accountId, int $userId): bool
    {
        try {
            // Verify user has access to this account
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("
                SELECT id FROM ml_accounts 
                WHERE id = :account_id AND user_id = :user_id
            ");
            $stmt->execute([
                'account_id' => $accountId,
                'user_id' => $userId
            ]);
            
            if ($stmt->fetch()) {
                \App\Helpers\SessionHelper::setActiveAccountId($accountId);
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            log_error('Failed to switch account', ['service' => 'AccountContextMiddleware', 'error' => $e->getMessage()]);
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
                'current_id' => \App\Helpers\SessionHelper::getActiveAccountId() ?? 0
            ]);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('Failed to get user accounts', ['service' => 'AccountContextMiddleware', 'error' => $e->getMessage()]);
            return [];
        }
    }
}
