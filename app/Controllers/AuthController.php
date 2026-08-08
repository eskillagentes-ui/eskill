<?php

declare(strict_types=1);

namespace App\Controllers;


use App\Services\MercadoLivreAuthService;
use App\Services\UserService;
use App\Services\SecurityService;
use App\Services\AuditLogService;
use App\Services\TwoFactorService;
use App\Helpers\Log;
use App\Helpers\SessionHelper;
use App\Database;

class AuthController extends BaseController
{
    private MercadoLivreAuthService $authService;
    private UserService $userService;
    private SecurityService $security;
    private AuditLogService $auditLog;
    private TwoFactorService $twoFactorService;

    public function __construct(
        MercadoLivreAuthService $authService,
        UserService $userService,
        SecurityService $security,
        AuditLogService $auditLog,
        TwoFactorService $twoFactorService
    ) {
        parent::__construct();
        $this->authService = $authService;
        $this->userService = $userService;
        $this->security = $security;
        $this->auditLog = $auditLog;
        $this->twoFactorService = $twoFactorService;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Exibe página de login
     */
    public function login(): void
    {
        // Forçar status 200 para esta rota
        http_response_code(200);

        // Se já estiver logado, redireciona para dashboard
        if ($this->userService->isAuthenticated()) {
            header('Location: /dashboard');
            exit;
        }

        // Gerar token CSRF se não existir
        if (empty($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            $this->security->generateCsrfToken();
        }

        require __DIR__ . '/../Views/auth/login.php';
    }

    /**
     * Processa login
     */
    public function doLogin(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /login');
            exit;
        }

        $email = $this->request->post('email', '');
        $password = $this->request->post('password', '');
        $remember = $this->request->post('remember') !== null;

        $result = $this->userService->login($email, $password);

        if (isset($result['require_2fa']) && $result['require_2fa']) {
            $_SESSION['2fa_user_id'] = $result['user_id'];
            $_SESSION['2fa_remember'] = $remember;
            header('Location: /auth/2fa/verify');
            exit;
        }

        if ($result['success']) {
            // Preservar token CSRF antes de regenerar sessão
            $csrfToken = $_SESSION['csrf_token'] ?? null;
            $csrfTokenTime = $_SESSION['csrf_token_time'] ?? null;

            // Prevenir fixation de sessão ao autenticar
            session_regenerate_id(true);

            // Restaurar token CSRF após regeneração
            if ($csrfToken) {
                $_SESSION['csrf_token'] = $csrfToken;
                $_SESSION['csrf_token_time'] = $csrfTokenTime;
            }

            // Criar sessão
            $_SESSION['user_id'] = $result['user']['id'];
            $_SESSION['user_name'] = $result['user']['name'];
            $_SESSION['user_email'] = $result['user']['email'];
            $_SESSION['user_role'] = $this->normalizeUserRole($result['user']['role'] ?? null);
            $this->hydrateActiveMlAccountSession();

            // Log de segurança
            Log::security('User login successful', [
                'user_id' => $result['user']['id'],
                'email' => $email,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
                'remember' => $remember,
            ]);

            // Registrar atividade
            try {
                $this->auditLog->log('user_login', $result['user']['id'], null, [
                    'description' => "Login realizado",
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'remember' => $remember
                ]);
            } catch (\Exception $e) {
                log_warning('Falha ao registrar atividade de auditoria', [
                    'action' => 'user_login',
                    'error' => $e->getMessage(),
                ]);
            }

            // Se "lembrar-me" estiver marcado, criar cookie
            if ($remember) {
                $token = $this->userService->createRememberToken($result['user']['id']);
                // Cookie seguro, httpOnly, SameSite=Lax, 30 dias (A6)
                setcookie('remember_token', $token, [
                    'expires' => time() + (30 * 24 * 60 * 60),
                    'path' => '/',
                    'domain' => '',
                    'secure' => !empty($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            // Redirecionar para página original ou dashboard
            $redirect = $_SESSION['redirect_after_login'] ?? '/dashboard';
            unset($_SESSION['redirect_after_login']);

            // Security: validar redirect para prevenir Open Redirect (C1)
            // Só permitir paths internos (começa com / e não com //)
            if (!is_string($redirect)) {
                $redirect = '/dashboard';
            } else {
                $redirect = trim($redirect);

                // Só permitir paths internos: começa com / + char alfanumérico
                $isInternalPath = preg_match('#^/[a-zA-Z0-9]#', $redirect) === 1;
                // Bloquear esquemas no início (e.g. https:, javascript:, data:)
                $hasSchemePrefix = preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $redirect) === 1;
                // Bloquear protocol-relative (//evil.com)
                $isProtocolRelative = str_starts_with($redirect, '//');

                if (!$isInternalPath || $hasSchemePrefix || $isProtocolRelative) {
                    $redirect = '/dashboard';
                }
            }

            $_SESSION['success'] = 'Login realizado com sucesso!';
            header('Location: ' . $redirect);
            exit;
        } else {
            // Log de segurança - tentativa de login falhada
            Log::security('Login attempt failed', [
                'email' => $email,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
                'reason' => $result['message'] ?? 'unknown',
            ]);

            $_SESSION['error'] = $result['message'];
            header('Location: /login');
            exit;
        }
    }

    /**
     * Exibe página de registro
     */
    public function register(): void
    {
        // Se já estiver logado, redireciona para dashboard
        if ($this->userService->isAuthenticated()) {
            header('Location: /dashboard');
            exit;
        }

        // Gerar token CSRF se não existir
        if (empty($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            $this->security->generateCsrfToken();
        }

        require __DIR__ . '/../Views/auth/register.php';
    }

    /**
     * Processa registro
     */
    public function doRegister(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /auth/register');
            exit;
        }

        $name = $this->request->post('name', '');
        $email = $this->request->post('email', '');
        $password = $this->request->post('password', '');
        $passwordConfirm = $this->request->post('password_confirm', '');

        // Validar confirmação de senha
        if ($password !== $passwordConfirm) {
            $_SESSION['error'] = 'As senhas não coincidem';
            header('Location: /auth/register');
            exit;
        }

        $result = $this->userService->register($name, $email, $password);

        if ($result['success']) {
            // Registrar atividade
            try {
                $this->auditLog->log('user_registered', $result['user_id'], null, [
                    'description' => "Usuário {$name} ({$email}) registrado"
                ]);
            } catch (\Exception $e) {
                log_warning('Falha ao registrar atividade de auditoria', [
                    'action' => 'user_registered',
                    'error' => $e->getMessage(),
                ]);
            }

            $_SESSION['success'] = 'Conta criada com sucesso! Faça login para continuar.';
            header('Location: /login');
            exit;
        } else {
            $_SESSION['error'] = $result['message'];
            header('Location: /auth/register');
            exit;
        }
    }

    /**
     * Faz logout
     */
    public function logout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            // Harden logout against CSRF by only allowing POST side-effects.
            $_SESSION['warning'] = 'Use o botão de logout para encerrar a sessão com segurança.';
            header('Location: /login');
            exit;
        }

        $userId = $_SESSION['user_id'] ?? null;

        // Registrar atividade antes de fazer logout
        if ($userId) {
            try {
                $this->auditLog->log('user_logout', $userId, null, [
                    'description' => "Logout realizado"
                ]);
            } catch (\Exception $e) {
                log_warning('Falha ao registrar atividade de auditoria', [
                    'action' => 'user_logout',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Remover cookie de "lembrar-me"
        if (isset($_COOKIE['remember_token'])) {
            $this->userService->removeRememberToken($_COOKIE['remember_token']);
            setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        }

        $this->userService->logout();
        $_SESSION['success'] = 'Logout realizado com sucesso!';
        header('Location: /login');
        exit;
    }

    /**
     * Exibe página de recuperação de senha
     */
    public function forgotPassword(): void
    {
        // Gerar token CSRF se não existir
        if (empty($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            $this->security->generateCsrfToken();
        }

        require __DIR__ . '/../Views/auth/forgot_password.php';
    }

    /**
     * Processa solicitação de recuperação
     */
    public function doForgotPassword(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /auth/forgot-password');
            exit;
        }

        $email = $this->request->post('email', '');

        if (empty($email)) {
            $_SESSION['error'] = 'E-mail é obrigatório';
            header('Location: /auth/forgot-password');
            exit;
        }

        // Resolve password reset service via Container or helper
        // Since we didn't inject this one in constructor to keep example simple,
        // we can use $this->get() from BaseController if defined in service provider,
        // OR rely on auto-wiring if we ask for it.
        // Let's use auto-wiring via $this->container->get()
        $resetService = $this->container->get(\App\Services\PasswordResetService::class);
        $result = $resetService->requestReset($email);

        $_SESSION['success'] = $result['message'];
        header('Location: /auth/forgot-password');
        exit;
    }

    /**
     * Exibe página de redefinição de senha
     */
    public function resetPassword(): void
    {
        $token = $this->request->get('token', '');

        if (empty($token)) {
            $_SESSION['error'] = 'Token não fornecido';
            header('Location: /auth/forgot-password');
            exit;
        }

        $resetService = $this->container->get(\App\Services\PasswordResetService::class);
        $validation = $resetService->validateToken($token);

        if (!$validation['valid']) {
            $_SESSION['error'] = $validation['message'];
            header('Location: /auth/forgot-password');
            exit;
        }

        // Gerar token CSRF se não existir
        if (empty($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            $this->security->generateCsrfToken();
        }

        require __DIR__ . '/../Views/auth/reset_password.php';
    }

    /**
     * Processa redefinição de senha
     */
    public function doResetPassword(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /auth/forgot-password');
            exit;
        }

        $token = $this->request->post('token', '');
        $password = $this->request->post('password', '');
        $passwordConfirm = $this->request->post('password_confirm', '');

        if ($password !== $passwordConfirm) {
            $_SESSION['error'] = 'As senhas não coincidem';
            header('Location: /auth/reset-password?token=' . urlencode($token));
            exit;
        }

        $resetService = $this->container->get(\App\Services\PasswordResetService::class);
        $result = $resetService->resetPassword($token, $password);

        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
            header('Location: /login');
            exit;
        } else {
            $_SESSION['error'] = $result['message'];
            header('Location: /auth/reset-password?token=' . urlencode($token));
            exit;
        }
    }

    /**
     * Inicia processo de autorização OAuth2
     * Query params:
     * - reconnect: ID da conta para reconectar (opcional)
     */
    public function authorize(): void
    {
        if (!$this->userService->isAuthenticated()) {
            $_SESSION['error'] = 'Você precisa estar logado para vincular uma conta';
            $_SESSION['redirect_after_login'] = '/auth/authorize';
            header('Location: /login');
            exit;
        }

        $userId = $_SESSION['user_id'];

        // Verificar se é reconexão de conta existente
        $reconnectId = $this->request->get('reconnect');
        if ($reconnectId) {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT id FROM ml_accounts WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $reconnectId, 'user_id' => $userId]);
            if ($stmt->fetch()) {
                $_SESSION['reconnect_account_id'] = (int)$reconnectId;
            }
        }

        try {
            $authUrl = $this->authService->getAuthUrl($userId);
            header('Location: ' . $authUrl);
            exit;
        } catch (\Throwable $e) {
            $errorType = $this->classifyOAuthErrorType($e->getMessage());
            $userMessage = $this->buildOAuthUserMessage($errorType, $e->getMessage());
            $this->logOAuthCallbackEvent('error', 'OAuth authorize failed', [
                'error_type' => $errorType,
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
            $_SESSION['error'] = $userMessage;
            header('Location: /dashboard/accounts');
            exit;
        }
    }

    /**
     * Callback OAuth2 - recebe código e troca por tokens
     */
    public function callback(): void
    {
        $code = $this->request->get('code');
        $state = $this->request->get('state');
        $error = $this->request->get('error');

        if ($error) {
            $errorDescription = $this->request->get('error_description', 'Erro desconhecido');
            $this->logOAuthCallbackEvent('warning', 'OAuth callback provider error', [
                'error_type' => $this->classifyOAuthErrorType($errorDescription),
                'error' => $error,
                'description' => $errorDescription,
            ]);
            $_SESSION['error'] = "Erro na autorização: {$errorDescription}";
            header('Location: /dashboard/accounts');
            exit;
        }

        if (!$code || !$state) {
            $_SESSION['error'] = "Código de autorização não recebido";
            header('Location: /dashboard/accounts');
            exit;
        }

        try {
            $result = $this->authService->exchangeCodeForTokens($code, $state);

            // Limpar flag de reconexão
            $wasReconnect = isset($_SESSION['reconnect_account_id']);
            unset($_SESSION['reconnect_account_id']);

            // Registrar atividade
            try {
                $userId = $_SESSION['user_id'] ?? null;
                $nickname = $result['user_info']['nickname'] ?? 'N/A';
                $action = $wasReconnect ? 'account_reconnected' : 'account_linked';
                $this->auditLog->log($action, $userId, null, [
                    'description' => ($wasReconnect ? "Conta ML reconectada: " : "Conta ML vinculada: ") . $nickname,
                    'ml_user_id' => $result['user_info']['id'] ?? null,
                    'nickname' => $nickname
                ]);
            } catch (\Exception $e) {
                log_warning('Falha ao registrar atividade de auditoria', [
                    'action' => $action ?? 'account_linked',
                    'nickname' => $nickname ?? null,
                    'error' => $e->getMessage(),
                ]);
            }

            // Log de auditoria rápida para rastrear callbacks OAuth
            $this->logOAuthCallbackEvent('info', 'OAuth callback success', [
                'error_type' => null,
                'nickname' => $result['user_info']['nickname'] ?? 'N/A',
                'ml_user_id' => $result['user_info']['id'] ?? 'N/A',
            ]);

            $_SESSION['success'] = "Conta vinculada com sucesso! Usuário: " . ($result['user_info']['nickname'] ?? 'N/A');
            header('Location: /dashboard/accounts');
            exit;
        } catch (\Exception $e) {
            $errorType = $this->classifyOAuthErrorType($e->getMessage());
            $this->logOAuthCallbackEvent('error', 'OAuth callback failure', [
                'error_type' => $errorType,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = $this->buildOAuthUserMessage($errorType, $e->getMessage());
            header('Location: /dashboard/accounts');
            exit;
        }
    }

    /**
     * Lista contas vinculadas
     */
    public function accounts(): void
    {
        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Não autenticado']);
            exit;
        }

        $userId = $_SESSION['user_id'];

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, ml_user_id, nickname, email, status, token_expires_at,
                   site_id, created_at, updated_at
            FROM ml_accounts
            WHERE user_id = :user_id
            ORDER BY created_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Add thumbnail URLs
        foreach ($accounts as &$account) {
            $account['thumbnail'] = $account['ml_user_id']
                ? "https://http2.mlstatic.com/D_Q_NP_2X_" . substr($account['ml_user_id'], -7) . "-V.webp"
                : null;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'accounts' => $accounts,
            'total' => count($accounts)
        ]);
    }

    public function oauthConfigStatus(): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            return;
        }

        try {
            $diagnostics = $this->authService->getOAuthConfigDiagnostics();
            $statusCode = $diagnostics['ready'] ? 200 : 503;
            http_response_code($statusCode);
            echo json_encode([
                'success' => true,
                'data' => $diagnostics,
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Não foi possível verificar a configuração OAuth',
                'details' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verifica status de conexão com Mercado Livre
     */
    public function status(): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            echo json_encode([
                'success' => true,
                'connected' => false,
                'reason' => 'not_logged_in'
            ]);
            return;
        }

        $userId = $_SESSION['user_id'];

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT id, ml_user_id, nickname, status, token_expires_at, access_token
            FROM ml_accounts
            WHERE user_id = :user_id AND status = 'active'
            ORDER BY token_expires_at DESC
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $account = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$account) {
            echo json_encode([
                'success' => true,
                'connected' => false,
                'reason' => 'no_account'
            ]);
            return;
        }

        // Verificar se o token está expirado
        $tokenExpired = false;
        if ($account['token_expires_at']) {
            $expiresAt = new \DateTime($account['token_expires_at']);
            $now = new \DateTime();
            $tokenExpired = $expiresAt < $now;
        }

        // Verificar se tem token
        $hasToken = !empty($account['access_token']);

        $connected = $hasToken && !$tokenExpired && $account['status'] === 'active';

        echo json_encode([
            'success' => true,
            'connected' => $connected,
            'account' => [
                'id' => $account['id'],
                'nickname' => $account['nickname'],
                'ml_user_id' => $account['ml_user_id']
            ],
            'token_expired' => $tokenExpired,
            'reason' => !$connected ? ($tokenExpired ? 'token_expired' : 'no_token') : null,
            'reconnect_url' => '/auth/authorize'
        ]);
    }

    /**
     * Verifica e-mail do usuário
     */
    public function verifyEmail(): void
    {
        $token = $this->request->get('token', '');

        if (empty($token)) {
            $_SESSION['error'] = 'Token de verificação inválido.';
            header('Location: /login');
            exit;
        }

        if ($this->userService->verifyEmail($token)) {
            $_SESSION['success'] = 'E-mail verificado com sucesso! Você já pode fazer login.';
        } else {
            $_SESSION['error'] = 'Token de verificação inválido ou expirado.';
        }

        header('Location: /login');
        exit;
    }

    /**
     * Exibe página de verificação 2FA
     */
    public function verifyTwoFactor(): void
    {
        if (!isset($_SESSION['2fa_user_id'])) {
            header('Location: /login');
            exit;
        }

        require __DIR__ . '/../Views/auth/2fa_verify.php';
    }

    /**
     * Processa verificação 2FA
     */
    public function doVerifyTwoFactor(): void
    {
        if (!isset($_SESSION['2fa_user_id'])) {
            header('Location: /login');
            exit;
        }

        $code = $this->request->post('code', '');
        $userId = $_SESSION['2fa_user_id'];

        $secret = $this->userService->getTwoFactorSecret($userId);

        if ($secret && $this->twoFactorService->verifyCode($secret, $code)) {
            // Preservar token CSRF antes de regenerar sessão
            $csrfToken = $_SESSION['csrf_token'] ?? null;
            $csrfTokenTime = $_SESSION['csrf_token_time'] ?? null;

            // Renovar ID da sessão ao concluir autenticação 2FA
            session_regenerate_id(true);

            // Restaurar token CSRF após regeneração
            if ($csrfToken) {
                $_SESSION['csrf_token'] = $csrfToken;
                $_SESSION['csrf_token_time'] = $csrfTokenTime;
            }

            // Login sucesso
            $user = $this->userService->getUserById($userId);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $this->normalizeUserRole($user['role'] ?? null);
            $this->hydrateActiveMlAccountSession();

            unset($_SESSION['2fa_user_id']);

            $remember = $_SESSION['2fa_remember'] ?? false;
            unset($_SESSION['2fa_remember']);

            if ($remember) {
                $token = $this->userService->createRememberToken($user['id']);
                setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', isset($_SERVER['HTTPS']), true);
            }

            $this->auditLog->log('user_login_2fa', $user['id'], null, ['description' => 'Login 2FA realizado']);

            header('Location: /dashboard');
            exit;
        } else {
            $_SESSION['error'] = 'Código inválido';
            header('Location: /auth/2fa/verify');
            exit;
        }
    }

    /**
     * Configuração de 2FA
     */
    public function setupTwoFactor(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $user = $this->userService->getCurrentUser();
        $secret = $this->twoFactorService->generateSecret();
        $qrCodeUrl = $this->twoFactorService->getQrCodeUrl('MercadoLivreManager', $user['email'], $secret);

        require __DIR__ . '/../Views/auth/2fa_setup.php';
    }

    /**
     * Ativar 2FA
     */
    public function doSetupTwoFactor(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $secret = $this->request->post('secret', '');
        $code = $this->request->post('code', '');
        $userId = $_SESSION['user_id'];

        if ($this->twoFactorService->verifyCode($secret, $code)) {
            $this->userService->enableTwoFactor($userId, $secret);
            $_SESSION['success'] = 'Autenticação de dois fatores ativada com sucesso!';
            header('Location: /profile');
        } else {
            $_SESSION['error'] = 'Código inválido. Tente novamente.';
            // Pass secret back to view or regenerate? Ideally pass back.
            // For simplicity, redirect to setup which regenerates.
            // Better UX would be to keep secret.
            header('Location: /auth/2fa/setup');
        }
        exit;
    }
    /**
     * Desconecta/Remove uma conta do Mercado Livre
     * POST /auth/disconnect/{accountId}
     */
    public function disconnect(int $accountId): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $userId = $_SESSION['user_id'];

        try {
            $db = Database::getInstance();

            // Verificar se a conta pertence ao usuário
            $stmt = $db->prepare("\n                SELECT id, nickname, ml_user_id
                FROM ml_accounts
                WHERE id = :account_id AND user_id = :user_id
            ");
            $stmt->execute([
                'account_id' => $accountId,
                'user_id' => $userId
            ]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$account) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Conta não encontrada ou não autorizada']);
                exit;
            }

            // Desativar a conta (soft delete)
            $stmt = $db->prepare("\n                UPDATE ml_accounts
                SET status = 'inactive',
                    access_token = NULL,
                    refresh_token = NULL,
                    updated_at = NOW()
                WHERE id = :account_id
            ");
            $stmt->execute(['account_id' => $accountId]);

            // Registrar atividade
            try {
                $this->auditLog->log('account_disconnected', $userId, null, [
                    'description' => "Conta ML desconectada: {$account['nickname']}",
                    'account_id' => $accountId,
                    'ml_user_id' => $account['ml_user_id'],
                    'nickname' => $account['nickname']
                ]);
            } catch (\Exception $e) {
                log_warning('Falha ao registrar atividade de auditoria', [
                    'action' => 'account_disconnected',
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Log de segurança
            Log::security('ML account disconnected', [
                'user_id' => $userId,
                'account_id' => $accountId,
                'ml_user_id' => $account['ml_user_id'],
                'nickname' => $account['nickname'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            // Se era a conta ativa na sessão, remover
            if (isset($_SESSION['active_ml_account_id']) && $_SESSION['active_ml_account_id'] == $accountId) {
                unset($_SESSION['active_ml_account_id']);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Conta desconectada com sucesso'
            ]);
            exit;
        } catch (\Exception $e) {
            Log::error('Error disconnecting ML account', [
                'user_id' => $userId,
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao desconectar conta: ' . $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * Sincroniza uma conta específica
     * POST /api/accounts/{accountId}/sync
     */
    public function syncAccount(int $accountId): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $userId = $_SESSION['user_id'];

        try {
            $db = Database::getInstance();

            // Verificar propriedade da conta
            $stmt = $db->prepare("\n                SELECT id, nickname, status, last_refresh_error FROM ml_accounts
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$accountId, $userId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$account) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Conta não encontrada']);
                exit;
            }

            $accountStatus = strtolower(trim((string)($account['status'] ?? 'unknown')));
            $lastRefreshError = strtolower(trim((string)($account['last_refresh_error'] ?? '')));
            $reconnectUrl = '/auth/authorize?reconnect=' . (int)$accountId;

            if ($accountStatus === 'disconnected' || str_contains($lastRefreshError, 'invalid_grant')) {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'error' => 'Conta desconectada — reautorização OAuth necessária antes da sincronização.',
                    'error_code' => 'account_disconnected',
                    'needs_reconnect' => true,
                    'reconnect_url' => $reconnectUrl,
                ]);
                exit;
            }

            if ($accountStatus !== 'active') {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'error' => 'Conta não elegível para sincronização no status atual. Reautorize a conta para continuar.',
                    'error_code' => 'account_not_eligible',
                    'needs_reconnect' => true,
                    'reconnect_url' => $reconnectUrl,
                ]);
                exit;
            }

            $syncService = new \App\Services\AccountSyncService();
            $result = $syncService->syncAccount($accountId);

            if (($result['success'] ?? false) !== true) {
                $errorCode = strtolower(trim((string)($result['error_code'] ?? '')));
                $needsReconnect = (bool)($result['needs_reconnect'] ?? false);
                $errorMessage = (string)($result['error'] ?? '');
                if (
                    $needsReconnect
                    || in_array($errorCode, ['account_disconnected', 'account_not_eligible', 'account_reauth_required'], true)
                    || ($errorMessage !== '' && (stripos($errorMessage, 'Token inválido') !== false || stripos($errorMessage, 'Reconecte a conta') !== false))
                ) {
                    http_response_code(401);
                    $result['needs_reconnect'] = true;
                    $result['error_code'] = $errorCode !== '' ? $errorCode : 'account_reauth_required';
                    $result['reconnect_url'] = (string)($result['reconnect_url'] ?? $reconnectUrl);
                }
            }

            // Registrar atividade
            try {
                $this->auditLog->log('account_synced', $userId, null, [
                    'description' => "Conta ML sincronizada: {$account['nickname']}",
                    'account_id' => $accountId,
                    'stats' => $result['stats'] ?? []
                ]);
            } catch (\Exception $e) {
                log_warning('Falha ao registrar atividade de auditoria', [
                    'action' => 'account_synced',
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            echo json_encode($result);
            exit;
        } catch (\Exception $e) {
            Log::error('Error syncing account', [
                'user_id' => $userId,
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao sincronizar: ' . $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * Obtém status de sincronização de uma conta
     * GET /api/accounts/{accountId}/sync/status
     */
    public function getSyncStatus(int $accountId): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $userId = $_SESSION['user_id'];

        try {
            $db = Database::getInstance();

            // Verificar propriedade
            $stmt = $db->prepare("\n                SELECT id FROM ml_accounts WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$accountId, $userId]);

            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Conta não encontrada']);
                exit;
            }

            $syncService = new \App\Services\AccountSyncService();
            $status = $syncService->getSyncStatus($accountId);

            echo json_encode(['success' => true, 'data' => $status]);
            exit;
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao obter status: ' . $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * Sincroniza todas as contas do usuário
     * POST /api/accounts/sync-all
     */
    public function syncAllAccounts(): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $userId = $_SESSION['user_id'];

        try {
            $syncService = new \App\Services\AccountSyncService();
            $result = $syncService->syncAllUserAccounts($userId);

            // Registrar atividade
            try {
                $this->auditLog->log('all_accounts_synced', $userId, null, [
                    'description' => "Todas as contas sincronizadas",
                    'total' => $result['total'],
                    'success' => $result['success'],
                    'failed' => $result['failed']
                ]);
            } catch (\Exception $e) {
                log_warning('Falha ao registrar atividade de auditoria', [
                    'action' => 'all_accounts_synced',
                    'error' => $e->getMessage(),
                ]);
            }

            echo json_encode(['success' => true, 'data' => $result]);
            exit;
        } catch (\Exception $e) {
            Log::error('Error syncing all accounts', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao sincronizar contas: ' . $e->getMessage()
            ]);
            exit;
        }
    }

    /**
     * Login via API for Mobile App
     * POST /api/auth/mobile/login
     */
    public function mobileLogin(): void
    {
        $input = $this->request->json();
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        $deviceName = trim((string)($input['device_name'] ?? 'Mobile Device'));

        $result = $this->userService->login($email, $password);

        if (($result['require_2fa'] ?? false) === true) {
            $this->json([
                'success' => false,
                'error' => 'Autenticação de dois fatores necessária',
                'code' => '2FA_REQUIRED',
            ], 403);
            return;
        }

        if ($result['success']) {
            $user = $result['user'];
            $safeDeviceName = $deviceName !== '' ? $deviceName : 'Mobile Device';
            $tokenTtlDays = (int)($_ENV['MOBILE_API_TOKEN_TTL_DAYS'] ?? 30);
            if ($tokenTtlDays < 1) {
                $tokenTtlDays = 1;
            } elseif ($tokenTtlDays > 90) {
                $tokenTtlDays = 90;
            }

            // Mobile token with restricted scope and short expiration window.
            $tokenService = new \App\Services\ApiTokenService();
            $tokenData = $tokenService->createToken(
                $user['id'],
                "Mobile App - " . substr($safeDeviceName, 0, 50),
                ['mobile'],
                $tokenTtlDays
            );

            // Log activity
            try {
                $this->auditLog->log('user_login_mobile', $user['id'], null, [
                    'description' => "Login Mobile realizado: {$deviceName}",
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            } catch (\Exception $e) {
                log_warning('Falha ao registrar atividade de login mobile', [
                    'action' => 'user_login_mobile',
                    'error' => $e->getMessage(),
                ]);
            }

            $this->json([
                'success' => true,
                'token' => $tokenData['token'],
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $this->normalizeUserRole($user['role'] ?? null)
                ]
            ]);
        } else {
            $this->json([
                'success' => false,
                'error' => $result['message'] ?? 'E-mail ou senha incorretos'
            ], 401);
        }
    }

    /**
     * Exclui permanentemente uma conta do Mercado Livre (Hard Delete)
     * DELETE /auth/account/{accountId}
     * ATENÇÃO: Esta ação é irreversível!
     */
    public function deleteAccount(int $accountId): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $userId = $_SESSION['user_id'];

        try {
            $db = Database::getInstance();

            // Verificar se a conta pertence ao usuário
            $stmt = $db->prepare("\n                SELECT id, nickname, ml_user_id
                FROM ml_accounts
                WHERE id = :account_id AND user_id = :user_id
            ");
            $stmt->execute([
                'account_id' => $accountId,
                'user_id' => $userId
            ]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$account) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Conta não encontrada ou não autorizada']);
                exit;
            }

            // Iniciar transação
            $db->beginTransaction();

            try {
                // Deletar dados relacionados do módulo de precificação
                $tables = [
                    'product_costs' => 'account_id',
                    'pricing_history' => 'account_id',
                    'pricing_rules' => 'account_id',
                    'competitor_pricing_cache' => 'account_id',
                    'promotion_simulations' => 'account_id',
                    'pricing_ranking_alerts' => 'account_id'
                ];

                foreach ($tables as $table => $column) {
                    try {
                        $deleteStmt = $db->prepare("DELETE FROM {$table} WHERE {$column} = :account_id");
                        $deleteStmt->execute(['account_id' => $accountId]);
                    } catch (\PDOException $e) {
                        log_warning('Falha ao limpar dados relacionados na exclusão de conta', [
                            'table' => $table,
                            'account_id' => $accountId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Deletar a conta permanentemente
                $stmt = $db->prepare("DELETE FROM ml_accounts WHERE id = :account_id");
                $stmt->execute(['account_id' => $accountId]);

                // Registrar atividade antes de commitar
                $this->auditLog->log('account_deleted', $userId, null, [
                    'description' => "Conta ML excluída permanentemente: {$account['nickname']}",
                    'account_id' => $accountId,
                    'ml_user_id' => $account['ml_user_id'],
                    'nickname' => $account['nickname']
                ]);

                $db->commit();

                // Log de segurança
                Log::security('ML account permanently deleted', [
                    'user_id' => $userId,
                    'account_id' => $accountId,
                    'ml_user_id' => $account['ml_user_id'],
                    'nickname' => $account['nickname'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);

                // Se era a conta ativa na sessão, remover
                if (isset($_SESSION['active_ml_account_id']) && $_SESSION['active_ml_account_id'] == $accountId) {
                    unset($_SESSION['active_ml_account_id']);
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Conta excluída permanentemente com sucesso'
                ]);
            } catch (\Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error deleting ML account', [
                'user_id' => $userId,
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao excluir conta: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    private function classifyOAuthErrorType(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'proxy')) {
            return 'proxy';
        }

        if (str_contains($normalized, 'ssl') || str_contains($normalized, 'certificate')) {
            return 'ssl';
        }

        if (str_contains($normalized, 'timed out') || str_contains($normalized, 'timeout')) {
            return 'timeout';
        }

        if (
            str_contains($normalized, 'ml_app_id')
            || str_contains($normalized, 'ml_redirect_uri')
            || str_contains($normalized, 'ml_client_secret')
            || str_contains($normalized, 'app_key')
            || str_contains($normalized, 'placeholder')
        ) {
            return 'configuration';
        }

        if (
            str_contains($normalized, 'invalid_grant')
            || str_contains($normalized, 'unauthorized')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'estado oauth')
            || str_contains($normalized, 'code_verifier')
        ) {
            return 'authentication';
        }

        if (preg_match('/http\s+(4\d\d|5\d\d)/i', $message) === 1 || str_contains($normalized, 'api')) {
            return 'api';
        }

        return 'unknown';
    }

    private function buildOAuthUserMessage(string $errorType, string $message): string
    {
        if ($errorType === 'configuration') {
            return 'Falha de configuração OAuth da loja virtual. Revise ML_APP_ID, ML_CLIENT_SECRET, ML_REDIRECT_URI e APP_KEY antes de tentar novamente.';
        }

        if ($errorType === 'proxy') {
            return 'Falha de rede ao conectar com a API da loja virtual. Foi detectado um proxy inválido ou não resolvido.';
        }

        if ($errorType === 'ssl') {
            return 'Falha SSL ao conectar com a loja virtual. Verifique certificados e cadeia HTTPS.';
        }

        if ($errorType === 'timeout') {
            return 'Tempo limite excedido ao conectar com a loja virtual. Verifique firewall, DNS e disponibilidade do endpoint.';
        }

        if ($errorType === 'authentication') {
            return 'Falha de autenticação com a loja virtual. Reconecte a conta e valide o fluxo OAuth.';
        }

        if ($errorType === 'api') {
            return 'A API da loja virtual rejeitou a conexão. Revise credenciais, redirect URI e disponibilidade do endpoint.';
        }

        return $message;
    }

    private function logOAuthCallbackEvent(string $level, string $message, array $context = []): void
    {
        $logContext = array_merge([
            'user_id' => $_SESSION['user_id'] ?? null,
            'route' => $_SERVER['REQUEST_URI'] ?? null,
        ], $context);

        if ($level === 'info') {
            log_info($message, $logContext);
        } elseif ($level === 'warning') {
            log_warning($message, $logContext);
        } else {
            log_error($message, $logContext);
        }

        $logPath = defined('ROOT_PATH')
            ? ROOT_PATH . '/storage/logs/tokens.log'
            : dirname(__DIR__, 2) . '/storage/logs/tokens.log';
        $logDir = dirname($logPath);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $encoded = json_encode($logContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = '{}';
        }

        file_put_contents(
            $logPath,
            sprintf("[%s] %s %s %s\n", date('c'), strtoupper($level), $message, $encoded),
            FILE_APPEND
        );
    }

    /**
     * Após login, hidrata $_SESSION['active_ml_account_id'] a partir da preferência DB /
     * primeira conta do usuário — evita APIs que só leem a sessão (BaseController).
     */
    private function hydrateActiveMlAccountSession(): void
    {
        try {
            SessionHelper::getActiveAccountId();
        } catch (\Throwable $e) {
            log_warning('Falha ao hidratar active_ml_account_id no login', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeUserRole(?string $role): string
    {
        $normalizedRole = strtolower(trim((string)$role));
        $allowedRoles = ['admin', 'manager', 'user', 'viewer'];

        return in_array($normalizedRole, $allowedRoles, true) ? $normalizedRole : 'user';
    }
}
