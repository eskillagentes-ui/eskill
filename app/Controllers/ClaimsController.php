<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ClaimsService;

class ClaimsController extends BaseController
{
    private ClaimsService $claimsService;

    public function __construct()
    {
        parent::__construct();
        $this->claimsService = new ClaimsService();
    }

    /**
     * Render Claims View
     */
    public function index(): void
    {
        $this->renderView('dashboard/claims/index', [
            'pageTitle' => 'Reclamações',
            'activePage' => 'claims',
        ]);
    }

    /**
     * API: Get Claims
     */
    public function list(): void
    {
        header('Content-Type: application/json');
        try {
            $claims = $this->claimsService->getClaims();

            // Erro estruturado (Onda 1): getClaims() pode retornar
            // ['error' => ..., 'message' => ..., 'requires_reconnect' => ...]
            // em vez de lançar exceção. Antes disso não era checado aqui e a
            // view recebia success:true com um payload de erro, renderizando
            // linhas com #undefined/undefined/Invalid Date.
            if (isset($claims['error'])) {
                $requiresReconnect = (bool) ($claims['requires_reconnect'] ?? false);
                http_response_code($requiresReconnect ? 401 : 502);
                echo json_encode([
                    'success' => false,
                    'error_code' => $claims['error_code'] ?? 'claims_fetch_failed',
                    'message' => $claims['message'] ?? $claims['error'] ?? 'Falha ao consultar reclamações no Mercado Livre.',
                    'requires_reconnect' => $requiresReconnect,
                ]);
                return;
            }

            $list = $claims['data'] ?? ($claims['results'] ?? []);
            echo json_encode([
                'success' => true,
                'claims' => array_values(is_array($list) ? $list : []),
                'paging' => $claims['paging'] ?? null,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error_code' => 'exception',
                'message' => $e->getMessage(),
                'requires_reconnect' => false,
            ]);
        }
    }
    
    /**
     * API: Send Message
     */
    public function sendMessage(): void
    {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        
        $claimId = $data['claim_id'] ?? null;
        $message = $data['message'] ?? null;
        
        if (!$claimId || !$message) {
             http_response_code(400);
             echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
             return;
        }
        
        try {
            $this->claimsService->sendMessage($claimId, $message);
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
             http_response_code(500);
             echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
