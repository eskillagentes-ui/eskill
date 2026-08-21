<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Database;
use PDO;

/**
 * Helper para gerenciar dados de sessão relacionados ao usuário e suas contas ML
 */
class SessionHelper
{
    /**
     * Obtém o ID do usuário logado
     */
    public static function getUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Obtém o ID da conta ML ativa do usuário.
     * A lógica foi atualizada para buscar a preferência do usuário no banco de dados.
     */
    public static function getActiveAccountId(): ?int
    {
        // Se já há uma conta ativa definida na sessão, use-a.
        if (isset($_SESSION['active_ml_account_id'])) {
            return (int)$_SESSION['active_ml_account_id'];
        }

        $userId = self::getUserId();
        if (!$userId) {
            return null;
        }

        $db = Database::getInstance();

        // 1. Tente buscar a conta preferida do usuário no banco de dados.
        $stmt = $db->prepare("SELECT active_ml_account_id FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $preferredAccountId = $stmt->fetchColumn();

        if ($preferredAccountId) {
            // Verifica se a conta preferida ainda pertence ao usuário.
            $stmt = $db->prepare("SELECT id FROM ml_accounts WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $preferredAccountId, 'user_id' => $userId]);
            if ($stmt->fetch()) {
                $_SESSION['active_ml_account_id'] = (int)$preferredAccountId;
                return (int)$preferredAccountId;
            }
        }

        // 2. Se não houver preferência ou a preferida for inválida, retorne a primeira conta disponível.
        // Priorizar contas com tokens (conectadas) sobre contas desconectadas.
        $stmt = $db->prepare("
            SELECT id 
            FROM ml_accounts 
            WHERE user_id = :user_id 
            ORDER BY 
                CASE WHEN access_token != '' AND access_token IS NOT NULL THEN 0 ELSE 1 END ASC,
                FIELD(status, 'active', 'attention_required', 'revoked', 'expired', 'inactive'),
                created_at ASC 
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            // Armazenar na sessão para próximas requisições.
            $_SESSION['active_ml_account_id'] = (int)$account['id'];
            return (int)$account['id'];
        }

        return null;
    }

    /**
     * Define a conta ML ativa do usuário, persistindo a escolha no banco de dados.
     */
    public static function setActiveAccountId(?int $accountId): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            return;
        }

        if ($accountId === null) {
            unset($_SESSION['active_ml_account_id']);
            unset($_SESSION['current_account_id']);
            // Opcional: limpar a preferência no banco também.
            // $stmt = $db->prepare("UPDATE users SET active_ml_account_id = NULL WHERE id = :user_id");
            // $stmt->execute(['user_id' => $userId]);
            return;
        }

        $db = Database::getInstance();

        // Verificar se a conta pertence ao usuário antes de fazer qualquer alteração.
        $stmt = $db->prepare("SELECT id FROM ml_accounts WHERE id = :account_id AND user_id = :user_id");
        $stmt->execute(['account_id' => $accountId, 'user_id' => $userId]);

        if ($stmt->fetch()) {
            // 1. Atualizar a sessão (legacy current_account_id kept in sync, never read by getter)
            $_SESSION['active_ml_account_id'] = $accountId;
            $_SESSION['current_account_id'] = $accountId;

            // 2. Persistir a escolha no banco de dados
            $updateStmt = $db->prepare("UPDATE users SET active_ml_account_id = :account_id WHERE id = :user_id");
            $updateStmt->execute(['account_id' => $accountId, 'user_id' => $userId]);
        }
    }

    /**
     * Obtém todas as contas ML do usuário logado
     */
    public static function getUserAccounts(): array
    {
        $userId = self::getUserId();
        if (!$userId) {
            return [];
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, ml_user_id, nickname, email, site_id, status
            FROM ml_accounts 
            WHERE user_id = :user_id
            ORDER BY created_at ASC
        ");
        $stmt->execute(['user_id' => $userId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtém IDs de todas as contas ativas do usuário
     */
    public static function getUserAccountIds(): array
    {
        $userId = self::getUserId();
        if (!$userId) {
            return [];
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id 
            FROM ml_accounts 
            WHERE user_id = :user_id 
            AND status = 'active'
        ");
        $stmt->execute(['user_id' => $userId]);
        
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    }

    /**
     * Verifica se o usuário está autenticado
     */
    public static function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * Limpa todos os dados de sessão
     */
    public static function destroy(): void
    {
        session_unset();
        session_destroy();
    }
}
