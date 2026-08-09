<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\AccountGovernanceService;
use App\Services\MercadoLivre\AccountGovernanceIntegrationService;
use App\Services\UserService;

/**
 * AccountGovernanceController - API endpoints for Account Governance & Recovery
 *
 * Provides JSON API for:
 * - Full diagnostic pipeline
 * - Input validation
 * - Classification reference
 */
class AccountGovernanceController
{
    private UserService $userService;
    private Request $request;

    public function __construct()
    {
        $this->userService = new UserService();
        $this->request = new Request();
    }

    /**
     * Dashboard page
     * GET /dashboard/account-governance
     */
    public function index(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $pageTitle = 'Governança da Conta';
        $currentPage = 'account-governance';

        ob_start();
        require __DIR__ . '/../Views/dashboard/account-governance.php';
        $content = ob_get_clean();

        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /**
     * Run full diagnostic pipeline
     * POST /api/account-governance/diagnostic
     *
     * Body: {
     *   "account_data": { "seller_id": "...", "reputation_level": "...", ... },
     *   "items": [ { "id": "...", "title": "...", ... }, ... ],
     *   "seller_context": { ... } // optional
     * }
     */
    public function runDiagnostic(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado', 'code' => 'UNAUTHORIZED']);
            return;
        }

        try {
            $input = $this->getJsonInput();

            $accountData = $input['account_data'] ?? [];
            $items = $input['items'] ?? [];
            $sellerContext = $input['seller_context'] ?? [];

            $service = new AccountGovernanceService();
            $result = $service->runFullDiagnostic($accountData, $items, $sellerContext);

            echo json_encode([
                'success' => true,
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'VALIDATION_ERROR',
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro interno no processamento',
                'code' => 'INTERNAL_ERROR',
            ], JSON_UNESCAPED_UNICODE);

            // Log the actual error
            error_log("[AccountGovernance] Error: " . $e->getMessage());
        }
    }

    /**
     * Validate input only (no full processing)
     * POST /api/account-governance/validate
     */
    public function validateInput(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado']);
            return;
        }

        try {
            $input = $this->getJsonInput();

            $accountData = $input['account_data'] ?? [];
            $items = $input['items'] ?? [];

            $service = new AccountGovernanceService();
            $service->validateInput($accountData, $items);

            echo json_encode([
                'success' => true,
                'valid' => true,
                'message' => 'Input válido',
                'item_count' => count($items),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'valid' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Get classification constants reference
     * GET /api/account-governance/classifications
     */
    public function getClassifications(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=86400');

        echo json_encode([
            'success' => true,
            'data' => [
                'item_classifications' => [
                    AccountGovernanceService::CLASS_SEM_ESTOQUE => [
                        'name' => 'Sem Estoque',
                        'description' => 'Item ativo sem estoque disponível',
                        'severity' => 'critical',
                    ],
                    AccountGovernanceService::CLASS_TOXICO => [
                        'name' => 'Tóxico',
                        'description' => 'Alto tráfego com conversão muito baixa',
                        'severity' => 'critical',
                    ],
                    AccountGovernanceService::CLASS_POLUIDOR => [
                        'name' => 'Poluidor',
                        'description' => 'Tráfego médio com conversão baixa',
                        'severity' => 'warning',
                    ],
                    AccountGovernanceService::CLASS_MORTO => [
                        'name' => 'Morto',
                        'description' => 'Sem tráfego e sem vendas',
                        'severity' => 'low',
                    ],
                    AccountGovernanceService::CLASS_FRACO => [
                        'name' => 'Fraco',
                        'description' => 'Tem tráfego mas não converte',
                        'severity' => 'warning',
                    ],
                    AccountGovernanceService::CLASS_EM_RISCO => [
                        'name' => 'Em Risco',
                        'description' => 'Tendência de queda detectada',
                        'severity' => 'warning',
                    ],
                    AccountGovernanceService::CLASS_SAUDAVEL => [
                        'name' => 'Saudável',
                        'description' => 'Performance normal',
                        'severity' => 'ok',
                    ],
                    AccountGovernanceService::CLASS_ANCHOR => [
                        'name' => 'Âncora',
                        'description' => 'Alto tráfego e boa conversão',
                        'severity' => 'excellent',
                    ],
                ],
                'account_statuses' => [
                    AccountGovernanceService::STATUS_TRAVADA => [
                        'name' => 'Travada',
                        'description' => 'Conta com problemas críticos',
                        'color' => '#dc2626',
                    ],
                    AccountGovernanceService::STATUS_PENALIZADA => [
                        'name' => 'Penalizada',
                        'description' => 'Conta sofrendo penalidades',
                        'color' => '#ea580c',
                    ],
                    AccountGovernanceService::STATUS_EM_RECUPERACAO => [
                        'name' => 'Em Recuperação',
                        'description' => 'Conta em processo de melhoria',
                        'color' => '#ca8a04',
                    ],
                    AccountGovernanceService::STATUS_ESTAVEL => [
                        'name' => 'Estável',
                        'description' => 'Conta operando normalmente',
                        'color' => '#16a34a',
                    ],
                    AccountGovernanceService::STATUS_FORTE => [
                        'name' => 'Forte',
                        'description' => 'Conta com excelente performance',
                        'color' => '#2563eb',
                    ],
                ],
                'action_types' => [
                    AccountGovernanceService::ACTION_PAUSAR,
                    AccountGovernanceService::ACTION_REATIVAR,
                    AccountGovernanceService::ACTION_REPOR_ESTOQUE,
                    AccountGovernanceService::ACTION_OTIMIZAR_TITULO,
                    AccountGovernanceService::ACTION_OTIMIZAR_PRECO,
                    AccountGovernanceService::ACTION_MELHORAR_FOTOS,
                    AccountGovernanceService::ACTION_MONITORAR,
                    AccountGovernanceService::ACTION_PROTEGER,
                ],
                'priorities' => [
                    AccountGovernanceService::PRIORITY_CRITICA,
                    AccountGovernanceService::PRIORITY_ALTA,
                    AccountGovernanceService::PRIORITY_MEDIA,
                    AccountGovernanceService::PRIORITY_BAIXA,
                ],
                'phases' => [
                    AccountGovernanceService::PHASE_ESTANCAR,
                    AccountGovernanceService::PHASE_ESTABILIZAR,
                    AccountGovernanceService::PHASE_CRESCER,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Run diagnostic with real data from Mercado Livre API
     * POST /api/account-governance/diagnostic-ml
     *
     * Body: {
     *   "max_items": 200,        // optional, default 200, max 500
     *   "include_paused": true,  // optional, default true
     *   "fetch_visits": true,    // optional, default true
     *   "fetch_sales": true      // optional, default true
     * }
     */
    public function runDiagnosticFromML(): void
    {
        // Visitas são buscadas em batches paralelos de 10 (Guzzle async), reduzindo
        // de ~60 s para ~8 s com 183 itens. Ainda assim aumentamos o limite para
        // 120 s como margem de segurança para contas maiores + orders pagination.
        @set_time_limit(120);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Não autenticado', 'code' => 'UNAUTHORIZED']);
            return;
        }

        try {
            $input = $this->getJsonInput();
        } catch (\InvalidArgumentException $e) {
            // Permitir body vazio - usar defaults
            $input = [];
        }

        try {
            // Get account ID from session
            $accountId = $_SESSION['active_ml_account_id'] ?? null;

            $options = [
                'max_items' => (int) ($input['max_items'] ?? 200),
                'include_paused' => (bool) ($input['include_paused'] ?? true),
                'fetch_visits' => (bool) ($input['fetch_visits'] ?? true),
                'fetch_sales' => (bool) ($input['fetch_sales'] ?? true),
            ];

            $integrationService = new AccountGovernanceIntegrationService($accountId);
            $result = $integrationService->runDiagnosticFromAPI($options);

            if (isset($result['error']) && $result['error'] === true) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result['message'] ?? 'Erro na integração',
                    'code' => $result['error_code'] ?? 'INTEGRATION_ERROR',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                'success' => true,
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro interno na integração',
                'code' => 'INTERNAL_ERROR',
            ], JSON_UNESCAPED_UNICODE);

            error_log("[AccountGovernance ML] Error: " . $e->getMessage());
        }
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $rawInput = file_get_contents('php://input');
        if (empty($rawInput)) {
            throw new \InvalidArgumentException('Request body vazio');
        }

        $data = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON inválido: ' . json_last_error_msg());
        }

        return $data;
    }
}
