<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ClaimsService;
use App\Services\UserService;

/**
 * Controller de Devoluções (RMA)
 *
 * Read-only: exibe reclamações/disputas do tipo devolução vindas da API do
 * Mercado Livre (via ClaimsService, mesma fonte única usada por
 * /dashboard/claims). ML_WRITE_AUTOMATION=false — nenhuma escrita no ML.
 */
class ReturnsController extends BaseController
{
    private ClaimsService $claimsService;
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
        $accountId = $_SESSION['active_ml_account_id'] ?? null;
        $this->claimsService = new ClaimsService($accountId);
    }

    /**
     * GET /dashboard/returns
     * Renderiza o painel de devoluções.
     */
    public function index(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $pendingRaw = $this->claimsService->getClaims('opened');
        $historyRaw = $this->claimsService->getClaims('closed');

        // BUG CORRIGIDO (Onda 1 / T4): a view assumia campos de um modelo
        // local de RMA (ml_order_id, sku, quantity, claim_id, condition_rating,
        // inspector_name) que nunca existiu — os dados reais são claims do ML
        // (id, resource_id, reason_id, date_created, status...). Passar o
        // claim raiz direto para strtotime($r['created_at']) com campo
        // inexistente (null) causava TypeError/HTTP 500 em PHP 8.
        $pendingError = isset($pendingRaw['error']) ? $pendingRaw : null;
        $historyError = isset($historyRaw['error']) ? $historyRaw : null;
        $pending = $pendingError ? [] : array_values($pendingRaw['data'] ?? ($pendingRaw['results'] ?? []));
        $history = $historyError ? [] : array_values($historyRaw['data'] ?? ($historyRaw['results'] ?? []));

        $error = $pendingError ?? $historyError;

        $pageTitle = 'Devoluções (Reclamações ML)';
        ob_start();
        require __DIR__ . '/../Views/dashboard/returns/index.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }
}
