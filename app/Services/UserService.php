<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\SecurityService;
use App\Services\AuditLogService;
use App\Services\CacheService;
use App\Services\EmailService;
use PDO;

class UserService
{
    private ?PDO $db;
    private SecurityService $security;
    private AuditLogService $auditLog;
    private CacheService $cache;
    private EmailService $email;

    public function __construct()
    {
        // Lazy DB connection: this allows routes like GET /login to render even when
        // the database is unavailable (e.g., sandbox / CI environments).
        $this->db = null;
        $this->security = new SecurityService();
        // Avoid connecting to DB in constructor; will be re-bound when DB becomes available.
        $this->auditLog = new AuditLogService(db: null, skipDbAutoConnect: true);
        $this->cache = new CacheService();
        $this->email = new EmailService();
    }

    private function db(): PDO
    {
        if ($this->db instanceof PDO) {
            return $this->db;
        }

        $this->db = Database::getInstance();

        // Ensure audit logs use the same PDO once DB is available.
        $this->auditLog = new AuditLogService(db: $this->db, skipDbAutoConnect: true);

        return $this->db;
    }

    /**
     * Registra um novo usuário
     */
    public function register(string $name, string $email, string $password): array
    {
        // Validações
        if (empty($name) || empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Todos os campos são obrigatórios'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'E-mail inválido'];
        }

        if (strlen($password) < 8) {
            return ['success' => false, 'message' => 'A senha deve ter no mínimo 8 caracteres'];
        }

        // Exigir pelo menos: 1 maiúscula, 1 minúscula, 1 número
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return ['success' => false, 'message' => 'A senha deve conter letras maiúsculas, minúsculas e números'];
        }

        try {
            // Verificar se e-mail já existe
            $stmt = $this->db()->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'E-mail já cadastrado'];
            }

            // Criar usuário
            $hashedPassword = $this->security->hashPassword($password);
            $verificationToken = bin2hex(random_bytes(32));

            $stmt = $this->db()->prepare("
                INSERT INTO users (name, email, password, verification_token, created_at, updated_at)
                VALUES (:name, :email, :password, :verification_token, NOW(), NOW())
            ");

            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'password' => $hashedPassword,
                'verification_token' => $verificationToken
            ]);

            $userId = (int) $this->db()->lastInsertId();
            if ($userId <= 0) {
                throw new \RuntimeException('Falha ao obter ID do usuário recém-criado');
            }

            // Log de atividade
            $this->auditLog->log('user_register', $userId, null, ['description' => 'Novo usuário registrado']);

            // Enviar e-mail de verificação
            try {
                $emailService = new \App\Services\EmailService();
                if ($emailService->isEnabled()) {
                    $emailService->sendVerification($email, $name, $verificationToken);
                }
            } catch (\Exception $e) {
                log_warning('Erro ao enviar e-mail de verificação', ['service' => 'UserService', 'error' => $e->getMessage()]);
            }

            return [
                'success' => true,
                'message' => 'Usuário registrado com sucesso. Verifique seu e-mail.',
                'user_id' => $userId
            ];
        } catch (\Throwable $e) {
            log_warning('Erro ao registrar usuário', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erro ao registrar usuário. Tente novamente em instantes.'];
        }
    }

    /**
     * Verifica o e-mail do usuário
     */
    public function verifyEmail(string $token): bool
    {
        try {
            $stmt = $this->db()->prepare("SELECT id FROM users WHERE verification_token = :token");
            $stmt->execute(['token' => $token]);
            $user = $stmt->fetch();
        } catch (\Throwable $e) {
            log_warning('Erro ao verificar e-mail (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return false;
        }

        if (!$user) {
            return false;
        }

        try {
            $stmt = $this->db()->prepare("
            UPDATE users
            SET email_verified_at = NOW(), verification_token = NULL
            WHERE id = :id
            ");

            $success = $stmt->execute(['id' => $user['id']]);
        } catch (\Throwable $e) {
            log_warning('Erro ao atualizar verificação de e-mail', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return false;
        }

        if ($success) {
            $this->auditLog->log('email_verified', $user['id'], null, ['description' => 'E-mail verificado com sucesso']);
        }

        return $success;
    }

    /**
     * Busca usuário por ID
     */
    public function getUserById(int $id): ?array
    {
        try {
            $stmt = $this->db()->prepare("SELECT id, name, email, password, email_verified_at, two_factor_enabled FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ?: null;
        } catch (\Throwable $e) {
            log_warning('Erro ao buscar usuário por ID (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Autentica um usuário
     */
    public function login(string $email, string $password): array
    {
        $email = trim(strtolower($email));

        if (empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'E-mail e senha são obrigatórios'];
        }

        try {
            // Buscar usuário
            $stmt = $this->db()->prepare("SELECT id, name, email, password, role, email_verified_at, two_factor_enabled FROM users WHERE LOWER(email) = :email");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
        } catch (\Throwable $e) {
            log_warning('Erro ao autenticar (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Serviço temporariamente indisponível. Tente novamente em instantes.'];
        }

        if (!$user) {
            return ['success' => false, 'message' => 'E-mail ou senha incorretos'];
        }

        // Verificar senha
        if (!$this->security->verifyPassword($password, $user['password'])) {
            // Incrementar contador de falhas
            $cacheKey = 'login_fails_' . md5($email);
            $fails = ($this->cache->get($cacheKey) ?? 0) + 1;
            $this->cache->set($cacheKey, $fails, 900); // 15 minutos

            // Log de falha de login
            $this->auditLog->log('login_failed', null, null, [
                'email' => $email,
                'fails' => $fails
            ]);

            // Alerta de segurança se exceder limite (5 tentativas)
            if ($fails >= 5) {
                // Enviar e-mail apenas uma vez por ciclo de 15 min (quando fails == 5)
                // ou a cada múltiplo de 5 para persistir o alerta
                if ($fails == 5 || $fails % 10 == 0) {
                    $this->email->send(
                        $email,
                        'Alerta de Segurança: Tentativas de Login Falhas',
                        "Detectamos {$fails} tentativas de login falhas na sua conta. Se não foi você, recomendamos redefinir sua senha imediatamente.",
                        'text'
                    );

                    $this->auditLog->log('security_alert', $user['id'], null, [
                        'type' => 'brute_force_attempt',
                        'failures' => $fails
                    ]);
                }
            }

            return ['success' => false, 'message' => 'E-mail ou senha incorretos'];
        }

        // Resetar contador de falhas após sucesso
        $this->cache->delete('login_fails_' . md5($email));

        // Verificar se e-mail foi verificado
        if (empty($user['email_verified_at'])) {
            return ['success' => false, 'message' => 'Por favor, verifique seu e-mail antes de fazer login.'];
        }

        // Verificar 2FA
        if (!empty($user['two_factor_enabled'])) {
            return [
                'success' => false,
                'require_2fa' => true,
                'user_id' => $user['id'],
                'message' => 'Autenticação de dois fatores necessária'
            ];
        }

        // Log de sucesso
        $this->auditLog->log('user_login', $user['id'], null, ['description' => 'Login realizado com sucesso']);

        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $this->normalizeRole($user['role'] ?? null)
            ]
        ];
    }

    /**
     * Obtém dados do usuário logado
     */
    public function getCurrentUser(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Garantir que tentamos restaurar a sessão via cookie se necessário
        if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
            $this->isAuthenticated();
        }

        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        try {
            $stmt = $this->db()->prepare("SELECT id, name, email, role, phone, created_at, two_factor_enabled FROM users WHERE id = :id");
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            return $user ?: null;
        } catch (\Throwable $e) {
            log_warning('Erro ao obter usuário atual (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Verifica se usuário está autenticado
     */
    public function isAuthenticated(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            return true;
        }

        // Tentar recuperar via cookie
        if (isset($_COOKIE['remember_token'])) {
            $userFromToken = $this->validateRememberToken($_COOKIE['remember_token']);
            if ($userFromToken) {
                $_SESSION['user_id'] = $userFromToken['id'];
                $_SESSION['user_name'] = $userFromToken['name'];
                $_SESSION['user_email'] = $userFromToken['email'];

                // Log de login via cookie
                $this->auditLog->log('user_login_cookie', $userFromToken['id'], null, ['description' => 'Login automático via cookie']);

                return true;
            }
        }

        return false;
    }

    /**
     * Faz logout do usuário
     */
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Prevenir reutilização do ID de sessão após logout
        session_regenerate_id(true);

        unset($_SESSION['user_id']);
        unset($_SESSION['user_name']);
        unset($_SESSION['user_email']);

        // Limpa todos os dados de sessão, mantendo a sessão ativa para possíveis mensagens flash
        session_unset();
    }

    /**
     * Atualiza perfil do usuário
     */
    public function updateProfile(int $userId, array $data): array
    {
        $allowedFields = ['name', 'email', 'phone'];
        $updateFields = [];
        $params = ['id' => $userId];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($updateFields)) {
            return ['success' => false, 'message' => 'Nenhum campo para atualizar'];
        }

        // Verificar se e-mail já existe (se estiver sendo alterado)
        if (isset($data['email'])) {
            try {
                $stmt = $this->db()->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
                $stmt->execute(['email' => $data['email'], 'id' => $userId]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'message' => 'E-mail já está em uso'];
                }
            } catch (\Throwable $e) {
                log_warning('Erro ao validar e-mail (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
                return ['success' => false, 'message' => 'Serviço temporariamente indisponível. Tente novamente em instantes.'];
            }
        }

        $updateFields[] = "updated_at = NOW()";
        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";

        try {
            $stmt = $this->db()->prepare($sql);
            $stmt->execute($params);

            return ['success' => true, 'message' => 'Perfil atualizado com sucesso'];
        } catch (\Throwable $e) {
            log_warning('Erro ao atualizar perfil', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erro ao atualizar perfil. Tente novamente em instantes.'];
        }
    }

    /**
     * Altera senha do usuário
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'A nova senha deve ter no mínimo 6 caracteres'];
        }

        try {
            // Verificar senha atual
            $stmt = $this->db()->prepare("SELECT password FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();
        } catch (\Throwable $e) {
            log_warning('Erro ao alterar senha (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Serviço temporariamente indisponível. Tente novamente em instantes.'];
        }

        if (!$user || !$this->security->verifyPassword($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'Senha atual incorreta'];
        }

        // Atualizar senha
        $hashedPassword = $this->security->hashPassword($newPassword);
        $stmt = $this->db()->prepare("UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id");

        try {
            $stmt->execute([
                'password' => $hashedPassword,
                'id' => $userId
            ]);

            return ['success' => true, 'message' => 'Senha alterada com sucesso'];
        } catch (\Throwable $e) {
            log_warning('Erro ao alterar senha', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erro ao alterar senha. Tente novamente em instantes.'];
        }
    }

    /**
     * Cria token de "Lembrar-me"
     */
    public function createRememberToken(int $userId): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $token = $selector . ':' . $validator;
        $hashedValidator = hash('sha256', $validator);
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 dias

        try {
            $stmt = $this->db()->prepare("
                INSERT INTO remember_tokens (user_id, selector, hashed_validator, expires_at)
                VALUES (:user_id, :selector, :hashed_validator, :expires_at)
            ");

            $stmt->execute([
                'user_id' => $userId,
                'selector' => $selector,
                'hashed_validator' => $hashedValidator,
                'expires_at' => $expiresAt
            ]);
        } catch (\Throwable $e) {
            log_warning('Erro ao criar remember_token (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return '';
        }

        return $token;
    }

    /**
     * Valida token de "Lembrar-me"
     */
    public function validateRememberToken(string $token): ?array
    {
        $parts = explode(':', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$selector, $validator] = $parts;

        try {
            $stmt = $this->db()->prepare("
                SELECT t.*, u.email, u.name
                FROM remember_tokens t
                JOIN users u ON t.user_id = u.id
                WHERE t.selector = :selector AND t.expires_at > NOW()
            ");

            $stmt->execute(['selector' => $selector]);
            $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            log_warning('Erro ao validar remember_token (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return null;
        }

        if (!$tokenData) {
            return null;
        }

        if (hash_equals($tokenData['hashed_validator'], hash('sha256', $validator))) {
            return [
                'id' => $tokenData['user_id'],
                'name' => $tokenData['name'],
                'email' => $tokenData['email']
            ];
        }

        return null;
    }

    /**
     * Remove token de "Lembrar-me"
     */
    public function removeRememberToken(string $token): void
    {
        $parts = explode(':', $token);
        if (count($parts) !== 2) {
            return;
        }

        [$selector] = $parts;

        try {
            $stmt = $this->db()->prepare("DELETE FROM remember_tokens WHERE selector = :selector");
            $stmt->execute(['selector' => $selector]);
        } catch (\Throwable $e) {
            log_warning('Erro ao remover remember_token (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Ativa 2FA para o usuário
     */
    public function enableTwoFactor(int $userId, string $secret): bool
    {
        try {
            $stmt = $this->db()->prepare("UPDATE users SET two_factor_secret = :secret, two_factor_enabled = 1 WHERE id = :id");
            return $stmt->execute(['secret' => $secret, 'id' => $userId]);
        } catch (\Throwable $e) {
            log_warning('Erro ao ativar 2FA (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Desativa 2FA para o usuário
     */
    public function disableTwoFactor(int $userId): bool
    {
        try {
            $stmt = $this->db()->prepare("UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0 WHERE id = :id");
            return $stmt->execute(['id' => $userId]);
        } catch (\Throwable $e) {
            log_warning('Erro ao desativar 2FA (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Obtém o segredo 2FA do usuário
     */
    public function getTwoFactorSecret(int $userId): ?string
    {
        try {
            $stmt = $this->db()->prepare("SELECT two_factor_secret FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['two_factor_secret'] : null;
        } catch (\Throwable $e) {
            log_warning('Erro ao obter segredo 2FA (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Salva preferências do dashboard
     */
    public function saveDashboardPreferences(int $userId, array $preferences): bool
    {
        $json = json_encode($preferences);
        try {
            $stmt = $this->db()->prepare("UPDATE users SET dashboard_preferences = :prefs WHERE id = :id");
            return $stmt->execute(['prefs' => $json, 'id' => $userId]);
        } catch (\Throwable $e) {
            log_warning('Erro ao salvar preferências (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Obtém preferências do dashboard
     */
    public function getDashboardPreferences(int $userId): array
    {
        try {
            $stmt = $this->db()->prepare("SELECT dashboard_preferences FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            log_warning('Erro ao obter preferências (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return [];
        }

        if ($result && !empty($result['dashboard_preferences'])) {
            return json_decode($result['dashboard_preferences'], true) ?: [];
        }

        return []; // Default preferences
    }

    /**
     * Atualiza o tema do usuário
     */
    public function updateTheme(int $userId, string $theme): bool
    {
        try {
            $stmt = $this->db()->prepare("UPDATE users SET theme = :theme WHERE id = :id");
            return $stmt->execute(['theme' => $theme, 'id' => $userId]);
        } catch (\Throwable $e) {
            log_warning('Erro ao atualizar tema (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Obtém o tema do usuário
     */
    public function getTheme(int $userId): string
    {
        try {
            $stmt = $this->db()->prepare("SELECT theme FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? ($result['theme'] ?? 'light') : 'light';
        } catch (\Throwable $e) {
            log_warning('Erro ao obter tema (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return 'light';
        }
    }

    /**
     * Obtém todos os dados do usuário para exportação
     */
    public function getUserDataForExport(int $userId): array
    {
        try {
            // 1. Dados do Usuário
            $stmt = $this->db()->prepare("SELECT id, name, email, phone, created_at, updated_at, email_verified_at, two_factor_enabled, theme, dashboard_preferences FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            log_warning('Erro ao exportar dados do usuário (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return [];
        }

        if (!$user) return [];

        // 2. Contas Vinculadas
        $stmt = $this->db()->prepare("SELECT id, nickname, email, status, created_at FROM ml_accounts WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Logs de Atividade
        $stmt = $this->db()->prepare("SELECT action, entity_type, entity_id, details, ip_address, created_at FROM activity_logs WHERE user_id = :id ORDER BY created_at DESC LIMIT 1000");
        $stmt->execute(['id' => $userId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'profile' => $user,
            'accounts' => $accounts,
            'activity_logs' => $logs,
            'exported_at' => date('Y-m-d H:i:s')
        ];
    }
    /**
     * Obtém o ID da conta ML ativa do usuário
     */
    public function getActiveAccountId(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['active_ml_account_id'])) {
            return (int)$_SESSION['active_ml_account_id'];
        }

        $userId = $this->getCurrentUser()['id'] ?? null;
        if (!$userId) {
            return null;
        }

        // Tentar ler preferência salva no banco
        try {
            $stmt = $this->db()->prepare(
                "SELECT active_ml_account_id FROM users WHERE id = :user_id LIMIT 1"
            );
            $stmt->execute(['user_id' => $userId]);
            $savedId = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            log_warning('Erro ao obter active_ml_account_id (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return null;
        }

        if ($savedId) {
            $_SESSION['active_ml_account_id'] = (int)$savedId;
            return (int)$savedId;
        }

        // Fallback: preferir contas com tokens válidos (status='active' primeiro)
        $stmt = $this->db()->prepare("
            SELECT id
            FROM ml_accounts
            WHERE user_id = :user_id
            ORDER BY
                CASE WHEN access_token != '' AND access_token IS NOT NULL THEN 0 ELSE 1 END ASC,
                CASE status WHEN 'active' THEN 0 WHEN 'inactive' THEN 1 ELSE 2 END ASC,
                created_at ASC
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            $_SESSION['active_ml_account_id'] = (int)$account['id'];
            return (int)$account['id'];
        }

        return null;
    }

    /**
     * Define a conta ML ativa do usuário
     */
    public function setActiveAccountId(?int $accountId): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($accountId === null) {
            unset($_SESSION['active_ml_account_id']);
            return true;
        }

        $userId = $this->getCurrentUser()['id'] ?? null;
        if (!$userId) {
            return false;
        }

        try {
            $stmt = $this->db()->prepare("
            SELECT id
            FROM ml_accounts
            WHERE id = :account_id
            AND user_id = :user_id
            ");
            $stmt->execute([
                'account_id' => $accountId,
                'user_id' => $userId
            ]);
        } catch (\Throwable $e) {
            log_warning('Erro ao validar conta ativa (DB indisponível)', ['service' => 'UserService', 'error' => $e->getMessage()]);
            return false;
        }

        if ($stmt->fetch()) {
            $_SESSION['active_ml_account_id'] = $accountId;

            // Persistir a preferência no banco de dados
            try {
                $update = $this->db()->prepare(
                    "UPDATE users SET active_ml_account_id = :account_id WHERE id = :user_id"
                );
                $update->execute([
                    'account_id' => $accountId,
                    'user_id'    => $userId,
                ]);
            } catch (\Exception $e) {
                // Não fatal: sessão já foi atualizada
                log_warning('Erro ao persistir active_ml_account_id', ['service' => 'UserService', 'error' => $e->getMessage()]);
            }

            return true;
        }

        return false;
    }

    /**
     * Obtém todas as contas ML do usuário logado
     */
    public function getUserAccounts(): array
    {
        $userId = $this->getCurrentUser()['id'] ?? null;
        if (!$userId) {
            return [];
        }

        try {
            $stmt = $this->db()->prepare("
                SELECT id, ml_user_id, nickname, email, site_id, status
                FROM ml_accounts
                WHERE user_id = :user_id
                ORDER BY created_at ASC
            ");
            $stmt->execute(['user_id' => $userId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            log_warning('Erro ao obter contas do usuário (DB indisponível)', [
                'service' => 'UserService',
                'reason' => 'account_list_unavailable',
            ]);
            return [];
        }
    }

    // ==================== PERMISSION & ROLE METHODS ====================

    /**
     * Check if user is an admin
     * @param int|null $userId Optional user ID (defaults to current user)
     * @return bool
     */
    public function isAdmin(?int $userId = null): bool
    {
        $user = $userId ? $this->getUserById($userId) : $this->getCurrentUser();

        if (!$user) {
            return false;
        }

        return ($user['role'] ?? '') === 'admin';
    }

    /**
     * Check if user has a specific permission
     * Permission hierarchy: admin > manager > user
     *
     * @param string $permission Permission to check (admin, manager, user, audit, reports, settings)
     * @param int|null $userId Optional user ID (defaults to current user)
     * @return bool
     */
    public function hasPermission(string $permission, ?int $userId = null): bool
    {
        $user = $userId ? $this->getUserById($userId) : $this->getCurrentUser();

        if (!$user) {
            return false;
        }

        $role = $user['role'] ?? 'user';

        // Admin has all permissions
        if ($role === 'admin') {
            return true;
        }

        // Permission mappings
        $permissions = [
            'admin' => ['admin'],
            'manager' => ['admin', 'manager'],
            'audit' => ['admin', 'manager', 'viewer'],
            'reports' => ['admin', 'manager', 'user', 'viewer'],
            'settings' => ['admin'],
            'user' => ['admin', 'manager', 'user'],
        ];

        $allowedRoles = $permissions[$permission] ?? ['admin'];

        return in_array($role, $allowedRoles, true);
    }

    /**
     * Get user role
     * @param int|null $userId Optional user ID (defaults to current user)
     * @return string
     */
    public function getRole(?int $userId = null): string
    {
        $user = $userId ? $this->getUserById($userId) : $this->getCurrentUser();

        return $this->normalizeRole($user['role'] ?? null);
    }

    /**
     * Require admin permission or redirect
     * @throws \Exception if not authorized
     */
    public function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            header('HTTP/1.1 403 Forbidden');
            throw new \Exception('Acesso negado. Permissão de administrador necessária.');
        }
    }

    /**
     * Require specific permission or redirect
     * @param string $permission
     * @throws \Exception if not authorized
     */
    public function requirePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            header('HTTP/1.1 403 Forbidden');
            throw new \Exception("Acesso negado. Permissão '{$permission}' necessária.");
        }
    }

    private function normalizeRole(?string $role): string
    {
        $normalizedRole = strtolower(trim((string)$role));
        $allowedRoles = ['admin', 'manager', 'user', 'viewer'];

        return in_array($normalizedRole, $allowedRoles, true) ? $normalizedRole : 'user';
    }
}
