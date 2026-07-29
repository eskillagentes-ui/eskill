<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\SecurityService;
use App\Services\EmailService;

class PasswordResetService
{
    private \PDO $db;
    private SecurityService $security;
    private EmailService $email;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->security = new SecurityService();
        $this->email = new EmailService();
        
        $this->ensureTableExists();
    }
    
    /**
     * Garante que tabela existe
     */
    private function ensureTableExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INT PRIMARY KEY AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_token (token),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * Solicita recuperação de senha
     */
    public function requestReset(string $email): array
    {
        // Verificar se e-mail existe
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Por segurança, não revelar se e-mail existe ou não
            return [
                'success' => true,
                'message' => 'Se o e-mail existir, você receberá instruções para recuperar sua senha.'
            ];
        }
        
        // Gerar token
        $token = $this->security->generateSecureToken(32);
        $tokenHash = hash('sha256', $token); // Armazenar apenas o hash — nunca o token original
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hora
        
        // Limpar tokens antigos do mesmo e-mail
        $stmt = $this->db->prepare("DELETE FROM password_resets WHERE email = :email");
        $stmt->execute(['email' => $email]);
        
        // Salvar hash do token (o token original só é enviado por e-mail)
        $stmt = $this->db->prepare("
            INSERT INTO password_resets (email, token, expires_at)
            VALUES (:email, :token, :expires_at)
        ");
        $stmt->execute([
            'email' => $email,
            'token' => $tokenHash,
            'expires_at' => $expiresAt
        ]);
        
        // Enviar e-mail
        $config = \App\Core\Config::getInstance()->all();
        $baseUrl = $config['url'];
        $resetUrl = $baseUrl . '/auth/reset-password?token=' . $token;
        
        $this->email->sendPasswordReset($email, $user['name'], $token, $resetUrl);
        
        return [
            'success' => true,
            'message' => 'Se o e-mail existir, você receberá instruções para recuperar sua senha.'
        ];
    }
    
    /**
     * Valida token de recuperação
     */
    public function validateToken(string $token): array
    {
        $tokenHash = hash('sha256', $token);
        $stmt = $this->db->prepare("
            SELECT email, expires_at, used_at
            FROM password_resets
            WHERE token = :token
        ");
        $stmt->execute(['token' => $tokenHash]);
        $reset = $stmt->fetch();
        
        if (!$reset) {
            return ['valid' => false, 'message' => 'Token inválido'];
        }
        
        if ($reset['used_at']) {
            return ['valid' => false, 'message' => 'Token já foi utilizado'];
        }
        
        if (strtotime($reset['expires_at']) < time()) {
            return ['valid' => false, 'message' => 'Token expirado'];
        }
        
        return [
            'valid' => true,
            'email' => $reset['email']
        ];
    }
    
    /**
     * Redefine senha usando token
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        // Validar token
        $validation = $this->validateToken($token);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message']
            ];
        }
        
        $email = $validation['email'];
        
        // Validar senha
        if (strlen($newPassword) < 6) {
            return [
                'success' => false,
                'message' => 'A senha deve ter no mínimo 6 caracteres'
            ];
        }
        
        // Atualizar senha
        $hashedPassword = $this->security->hashPassword($newPassword);
        $stmt = $this->db->prepare("UPDATE users SET password = :password WHERE email = :email");
        $stmt->execute([
            'password' => $hashedPassword,
            'email' => $email
        ]);
        
        // Marcar token como usado (buscar pelo hash)
        $tokenHash = hash('sha256', $token);
        $stmt = $this->db->prepare("
            UPDATE password_resets 
            SET used_at = NOW() 
            WHERE token = :token
        ");
        $stmt->execute(['token' => $tokenHash]);
        
        return [
            'success' => true,
            'message' => 'Senha redefinida com sucesso!'
        ];
    }
    
    /**
     * Limpa tokens expirados (manutenção)
     */
    public function cleanExpiredTokens(): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM password_resets 
            WHERE expires_at < NOW() OR used_at IS NOT NULL
        ");
        $stmt->execute();
        
        return $stmt->rowCount();
    }
}
