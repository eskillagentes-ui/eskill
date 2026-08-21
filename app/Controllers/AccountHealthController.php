<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Database;
use App\Services\AccountHealthService;
use App\Helpers\AccountScopeHelper;
use App\Helpers\SessionHelper;
use App\Services\UserService;

/**
 * AccountHealthController - Diagnóstico completo da conta ML
 *
 * Página única com score geral, 5 pilares, ações prioritárias,
 * e itens que precisam de atenção.
 */
class AccountHealthController
{
    private ?UserService $userService = null;
    private Request $request;

    public function __construct()
    {
        try {
            $this->userService = new UserService();
        } catch (\Throwable $e) {
            $this->userService = null;
        }
        $this->request = new Request();
    }

    /**
     * Página principal - Diagnóstico da Conta
     * GET /dashboard/account-health
     */
    public function index(): void
    {
        if ($this->userService === null || !$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $accountId = $this->resolveScopedAccountId();

        $pageTitle = 'Diagnóstico da Conta';
        $currentPage = 'account-health';

        ob_start();
        require __DIR__ . '/../Views/dashboard/account-health.php';
        $content = ob_get_clean();

        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /**
     * API - Diagnóstico completo (JSON)
     * GET /api/account-health/diagnostic
     */
    public function getDiagnostic(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $accountId = $this->resolveScopedAccountId();

        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada']);
            exit;
        }

        // Verificar se a conta tem tokens válidos antes de rodar diagnóstico
        $accountStatus = $this->checkAccountTokens((int) $accountId);
        if ($accountStatus !== null) {
            echo json_encode($accountStatus);
            exit;
        }

        try {
            $startTime = microtime(true);
            $service = new AccountHealthService((int) $accountId);
            $diagnostic = $service->getFullDiagnostic();
            $elapsed = round((microtime(true) - $startTime) * 1000);

            header("X-Diagnostic-Time: {$elapsed}ms");

            echo json_encode([
                'success' => true,
                'data'    => $diagnostic,
            ]);
        } catch (\Exception $e) {
            log_error('Erro ao gerar diagnóstico', [
                'controller' => 'AccountHealthController',
                'account_id' => $accountId,
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000),
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Erro ao gerar diagnóstico. Tente novamente em alguns instantes.',
            ]);
        }
        exit;
    }

    /**
     * API - Apenas um pilar específico
     * GET /api/account-health/pillar/{pillarName}
     */
    public function getPillar(string $pillarName): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $accountId = $this->resolveScopedAccountId();
        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada']);
            exit;
        }

        $allowedPillars = ['reputation', 'seo_quality', 'competitiveness', 'operation', 'sales'];
        if (!in_array($pillarName, $allowedPillars)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Pilar inválido']);
            exit;
        }

        try {
            $service = new AccountHealthService((int) $accountId);

            $pillarData = match ($pillarName) {
                'reputation'      => $service->getReputationPillar(),
                'seo_quality'     => $service->getSeoQualityPillar(),
                'competitiveness' => $service->getCompetitivenessPillar(),
                'operation'       => $service->getOperationPillar(),
                'sales'           => $service->getSalesPillar(),
            };

            echo json_encode(['success' => true, 'data' => $pillarData]);
        } catch (\Exception $e) {
            log_error('Erro ao carregar pilar', [
                'controller' => 'AccountHealthController',
                'pillar' => $pillarName,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao carregar dados do pilar.']);
        }
        exit;
    }

    /**
     * API - Refresh do cache
     * POST /api/account-health/refresh
     */
    public function refresh(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $accountId = $this->resolveScopedAccountId();
        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada']);
            exit;
        }

        // Verificar se a conta tem tokens válidos antes de atualizar
        $accountStatus = $this->checkAccountTokens((int) $accountId);
        if ($accountStatus !== null) {
            echo json_encode($accountStatus);
            exit;
        }

        try {
            // Limpar cache e recarregar
            $service = new AccountHealthService((int) $accountId);
            $service->clearCache();
            $diagnostic = $service->getFullDiagnostic();

            echo json_encode(['success' => true, 'data' => $diagnostic]);
        } catch (\Exception $e) {
            log_error('Erro ao atualizar diagnóstico', [
                'controller' => 'AccountHealthController',
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao atualizar diagnóstico. Tente novamente.']);
        }
        exit;
    }

    /**
     * Verifica se a conta ML possui tokens de acesso válidos.
     * Retorna null se OK, ou array com erro amigável se desconectada.
     */
    private function checkAccountTokens(int $accountId): ?array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                "SELECT nickname, access_token, refresh_token, tokens_encrypted, token_expires_at
                 , status, last_refresh_error, refresh_failure_count
                 FROM ml_accounts WHERE id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $accountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$account) {
                http_response_code(404);
                return [
                    'success' => false,
                    'error'   => 'account_not_found',
                    'message' => 'Conta não encontrada.',
                ];
            }

            $accessToken = $account['access_token'] ?? '';
            $refreshToken = $account['refresh_token'] ?? '';

            $status = (string)($account['status'] ?? '');
            $lastRefreshError = (string)($account['last_refresh_error'] ?? '');

            // Conta marcada como desconectada no backend (ex.: invalid_grant no refresh)
            if ($status === 'disconnected' || stripos($lastRefreshError, 'invalid_grant') !== false) {
                return [
                    'success' => false,
                    'error' => 'account_disconnected',
                    'message' => 'A conta ' . ($account['nickname'] ?? "#$accountId")
                        . ' precisa ser reconectada ao Mercado Livre.',
                    'nickname' => $account['nickname'] ?? null,
                    'account_id' => $accountId,
                    'reconnect_url' => '/auth/authorize?reconnect=' . $accountId,
                ];
            }

            // Descriptografar tokens se necessário para validação real
            if (!empty($account['tokens_encrypted']) && ($accessToken !== '' || $refreshToken !== '')) {
                try {
                    $enc = new \App\Services\EncryptionService();
                    $accessToken = $accessToken !== '' ? $enc->decrypt($accessToken) : '';
                    $refreshToken = $refreshToken !== '' ? $enc->decrypt($refreshToken) : '';
                } catch (\Throwable $e) {
                    // Tokens corrompidos = conta desconectada
                    log_error('Falha ao descriptografar tokens da conta', [
                        'controller' => 'AccountHealthController',
                        'account_id' => $accountId,
                        'error' => $e->getMessage(),
                    ]);
                    return [
                        'success' => false,
                        'error'   => 'account_disconnected',
                        'message' => 'Tokens da conta ' . ($account['nickname'] ?? "#$accountId")
                            . ' estão corrompidos. Reconecte a conta.',
                        'nickname' => $account['nickname'] ?? null,
                        'account_id' => $accountId,
                    ];
                }
            }

            $hasAccessToken = !empty($accessToken);
            $hasRefreshToken = !empty($refreshToken);

            // Sem nenhum token = desconectada
            if (!$hasAccessToken && !$hasRefreshToken) {
                return [
                    'success' => false,
                    'error'   => 'account_disconnected',
                    'message' => 'A conta ' . ($account['nickname'] ?? "#$accountId")
                        . ' está desconectada do Mercado Livre.',
                    'nickname' => $account['nickname'] ?? null,
                    'account_id' => $accountId,
                    'reconnect_url' => '/auth/authorize?reconnect=' . $accountId,
                ];
            }

            // Access token expirado E sem refresh_token = não há como renovar
            $expiresAt = $account['token_expires_at'] ?? null;
            if ($expiresAt && !$hasRefreshToken) {
                $secondsLeft = strtotime($expiresAt) - time();
                if ($secondsLeft <= 0) {
                    return [
                        'success' => false,
                        'error'   => 'account_disconnected',
                        'message' => 'O token da conta ' . ($account['nickname'] ?? "#$accountId")
                            . ' expirou e não pode ser renovado. Reconecte a conta.',
                        'nickname' => $account['nickname'] ?? null,
                        'account_id' => $accountId,
                        'reconnect_url' => '/auth/authorize?reconnect=' . $accountId,
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            log_error('Erro ao verificar tokens da conta', [
                'controller' => 'AccountHealthController',
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            return null; // Em caso de erro, deixar o fluxo normal tratar
        }
    }

    /**
     * API - Histórico de scores para gráfico de tendência
     * GET /api/account-health/history?days=30
     */
    public function getHistory(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, max-age=600');
        header('X-Content-Type-Options: nosniff');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $accountId = $this->resolveScopedAccountId();
        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada']);
            exit;
        }

        $days = min(90, max(7, $this->request->getInt('days', 30)));

        try {
            $service = new AccountHealthService((int) $accountId);
            $history = $service->getScoreHistory($days);

            echo json_encode(['success' => true, 'data' => $history]);
        } catch (\Exception $e) {
            log_error('Erro ao carregar histórico de scores', [
                'controller' => 'AccountHealthController',
                'account_id' => $accountId,
                'days' => $days,
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao carregar histórico.']);
        }
        exit;
    }

    /**
     * 🆕 API - Advanced Diagnostic: Account Status
     * GET /api/account-health/advanced/status
     */
    public function getAdvancedStatus(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, max-age=600');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $accountId = $this->resolveScopedAccountId();
        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada']);
            exit;
        }

        try {
            $service = new AccountHealthService((int) $accountId);
            $diagnostic = $service->getAccountStatusDiagnostic();

            echo json_encode(['success' => true, 'data' => $diagnostic]);
        } catch (\Exception $e) {
            log_error('Erro no diagnóstico avançado de status', [
                'controller' => 'AccountHealthController',
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao gerar diagnóstico avançado.']);
        }
        exit;
    }

    /**
     * 🆕 API - Advanced Diagnostic: Customer Service
     * GET /api/account-health/advanced/customer-service
     */
    public function getAdvancedCustomerService(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, max-age=300');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $accountId = $this->resolveScopedAccountId();
        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada']);
            exit;
        }

        try {
            $service = new AccountHealthService((int) $accountId);
            $diagnostic = $service->getCustomerServiceDiagnostic();

            echo json_encode(['success' => true, 'data' => $diagnostic]);
        } catch (\Exception $e) {
            log_error('Erro no diagnóstico de atendimento ao cliente', [
                'controller' => 'AccountHealthController',
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao analisar atendimento.']);
        }
        exit;
    }

    /**
     * 🆕 API - Advanced Diagnostic: Catalog Health
     * GET /api/account-health/advanced/catalog
     */
    public function getAdvancedCatalog(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, max-age=600');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $accountId = $this->resolveScopedAccountId();
        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada']);
            exit;
        }

        try {
            $service = new AccountHealthService((int) $accountId);
            $diagnostic = $service->getCatalogHealthDiagnostic();

            echo json_encode(['success' => true, 'data' => $diagnostic]);
        } catch (\Exception $e) {
            log_error('Erro no diagnóstico de saúde do catálogo', [
                'controller' => 'AccountHealthController',
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao analisar catálogo.']);
        }
        exit;
    }

    /**
     * 🆕 API - Complete Advanced Diagnostic (All new diagnostics)
     * GET /api/account-health/advanced/complete
     */
    public function getAdvancedComplete(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: private, max-age=600');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autenticado']);
            exit;
        }

        $accountId = $this->resolveScopedAccountId();
        if (!$accountId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta selecionada']);
            exit;
        }

        try {
            $startTime = microtime(true);
            $service = new AccountHealthService((int) $accountId);

            $diagnostics = [
                'account_status' => $service->getAccountStatusDiagnostic(),
                'customer_service' => $service->getCustomerServiceDiagnostic(),
                'catalog_health' => $service->getCatalogHealthDiagnostic(),
            ];

            // Calculate overall advanced score
            $scores = array_column($diagnostics, 'score');
            $overallScore = count($scores) > 0 ? array_sum($scores) / count($scores) : 0;

            $elapsed = round((microtime(true) - $startTime) * 1000);

            echo json_encode([
                'success' => true,
                'data' => [
                    'overall_score' => round($overallScore),
                    'diagnostics' => $diagnostics,
                    'generated_at' => date('Y-m-d H:i:s'),
                    'execution_time_ms' => $elapsed,
                ],
            ]);
        } catch (\Exception $e) {
            log_error('Erro no diagnóstico avançado completo', [
                'controller' => 'AccountHealthController',
                'account_id' => $accountId,
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000),
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao gerar diagnóstico completo.']);
        }
        exit;
    }

    /**
     * Diagnóstico always runs against the session active ML account.
     * A body/query account_id that differs (e.g. Falcão 1336 while FACILYTY 1335
     * is active) is ignored so catalogs never mix.
     */
    private function resolveScopedAccountId(): ?int
    {
        $active = AccountScopeHelper::activeAccountId();
        $requested = 0;
        try {
            $requested = (int) ($this->request->get('account_id') ?? 0);
        } catch (\Throwable) {
            $requested = (int) ($_GET['account_id'] ?? $_POST['account_id'] ?? 0);
        }
        if ($requested <= 0) {
            $requested = (int) ($_GET['account_id'] ?? 0);
        }

        if ($active === null || $active <= 0) {
            return ($requested > 0) ? $requested : null;
        }

        return $active;
    }
}
