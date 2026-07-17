<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\DebugAgentLog;
use App\Services\UserService;

class AuthMiddleware
{
    private ?UserService $userService = null;

    public function __construct()
    {
        try {
            $this->userService = new UserService();
        } catch (\Throwable $e) {
            $this->userService = null;
        }
    }

    /**
     * Verifica se o usuário está autenticado
     * Redireciona para login se não estiver
     */
    public function handle(): void
    {
        try {
            if ($this->userService === null || !$this->userService->isAuthenticated()) {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }

                // Não redirecionar se já estiver na página de login
                $currentPath = $_SERVER['REQUEST_URI'] ?? '';
                if (strpos($currentPath, '/auth/login') === false && strpos($currentPath, '/login') === false) {
                    $_SESSION['redirect_after_login'] = $currentPath ?: '/dashboard';
                }

                // #region agent log
                if (str_contains($currentPath, '/dashboard/financials')) {
                    DebugAgentLog::write('L', 'AuthMiddleware::handle', 'financials_auth_redirect', [
                        'path' => $currentPath,
                        'hasSessionUserId' => !empty($_SESSION['user_id']),
                    ]);
                }
                // #endregion

                // Se for requisição AJAX, retornar JSON
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    http_response_code(401);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Não autenticado', 'redirect' => '/auth/login']);
                    exit;
                }

                header('Location: /login');
                exit;
            }
        } catch (\Exception $e) {
            log_error('AuthMiddleware error', ['service' => 'AuthMiddleware', 'error' => $e->getMessage()]);

            // SEGURANÇA: Em caso de erro, BLOQUEAR acesso (fail-closed)
            // Nunca permitir acesso quando a autenticação falha por erro
            $env = $_ENV['APP_ENV'] ?? 'production';
            if ($env === 'production' || $env === 'staging') {
                http_response_code(503);
                echo 'Serviço temporariamente indisponível. Tente novamente.';
                exit;
            }

            // Mesmo em desenvolvimento, redirecionar para login
            header('Location: /login');
            exit;
        }
    }

    /**
     * Verifica se o usuário está autenticado (sem redirecionar)
     * Retorna true/false
     */
    public function check(): bool
    {
        return $this->userService !== null && $this->userService->isAuthenticated();
    }
}
