<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdsService;
use App\Services\AdsWizardService;

class AdsController extends BaseController
{
    private ?AdsService $adsService = null;
    private ?AdsWizardService $wizardService = null;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Lazy-init dos serviços com o accountId correto da sessão.
     */
    private function getAdsService(): AdsService
    {
        if ($this->adsService === null) {
            $this->adsService = new AdsService($this->getActiveAccountId());
        }
        return $this->adsService;
    }

    private function getWizardService(): AdsWizardService
    {
        if ($this->wizardService === null) {
            $this->wizardService = new AdsWizardService($this->getActiveAccountId());
        }
        return $this->wizardService;
    }

    // ==================== PAGES ====================

    /**
     * Dashboard de Ads — observação read-only (Bloco 5)
     * GET /dashboard/ads
     */
    public function index(): void
    {
        $pageTitle = 'Ads · Observação';
        $activePage = 'ads';
        $accountId = (int) ($this->getActiveAccountId() ?? 0);
        $adsObs = $accountId > 0
            ? (new \App\Services\Ads\AdsObservationService())->dashboard($accountId)
            : [
                'read_only' => true,
                'active_campaigns' => 0,
                'has_campaigns' => false,
                'message' => 'nenhuma conta ativa',
                'tacos' => null,
                'acos' => null,
                'gasto_hoje' => null,
                'sku_custos_count' => 0,
                'campaigns' => [],
                'skus' => [],
                'cpc_health_series' => [],
                'recovery' => [],
            ];

        ob_start();
        require __DIR__ . '/../Views/dashboard/ads/observation.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /**
     * Dados do painel de observação
     * GET /api/ads/observation
     */
    public function getObservationData(): void
    {
        $this->withErrorHandling(function () {
            $accountId = $this->getActiveAccountId();
            if (!$accountId) {
                $this->jsonError('Nenhuma conta do Mercado Livre configurada.', 400);
                return;
            }
            $data = (new \App\Services\Ads\AdsObservationService())->dashboard((int) $accountId);
            $this->jsonSuccess($data);
        }, 'AdsController::getObservationData');
    }

    /**
     * Wizard de criação de campanha
     * GET /dashboard/ads/criar
     */
    public function createWizard(): void
    {
        $pageTitle = 'Criar Anúncio';
        $activePage = 'ads';

        ob_start();
        require __DIR__ . '/../Views/dashboard/ads/wizard.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    // ==================== API ENDPOINTS ====================

    /**
     * Dashboard data — diagnóstico + campanhas + métricas
     * GET /api/ads/dashboard
     */
    public function getDashboardData(): void
    {
        $this->withErrorHandling(function () {
            $accountId = $this->getActiveAccountId();
            if (!$accountId) {
                $this->jsonError('Nenhuma conta do Mercado Livre configurada. Vá em Configurações para vincular.', 400);
                return;
            }

            $ads = $this->getAdsService();
            $wizard = $this->getWizardService();

            $campaigns = $ads->getCampaigns('all');
            $diagnostic = $wizard->getDiagnostic();
            $glossary = $wizard->getGlossary();

            $this->jsonSuccess([
                'campaigns' => $campaigns,
                'diagnostic' => $diagnostic,
                'glossary' => $glossary,
                '_meta' => [
                    'account_id' => $accountId,
                    'fetched_at' => date('c'),
                    'data_source' => $campaigns['_meta']['data_source'] ?? 'unknown',
                ],
            ]);
        }, 'AdsController::getDashboardData');
    }

    /**
     * Produtos elegíveis para anunciar
     * GET /api/ads/products
     */
    public function getProducts(): void
    {
        $this->withErrorHandling(function () {
            $limit = min($this->request->getInt('limit', 30), 100);
            $products = $this->getWizardService()->getEligibleProducts($limit);
            $this->jsonSuccess(['products' => $products]);
        }, 'AdsController::getProducts');
    }

    /**
     * Sugestão de orçamento
     * POST /api/ads/suggest-budget
     */
    public function suggestBudget(): void
    {
        $this->withErrorHandling(function () {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $items = $input['items'] ?? [];

            if (!is_array($items)) {
                $this->jsonError('Envie uma lista de IDs de produtos.', 400);
                return;
            }

            // Sanitizar IDs
            $items = array_map('trim', array_filter($items, 'is_string'));

            $suggestion = $this->getWizardService()->suggestBudget($items);
            $this->jsonSuccess(['budget' => $suggestion]);
        }, 'AdsController::suggestBudget');
    }

    /**
     * Criar campanha simplificada via wizard
     * POST /api/ads/create
     */
    public function createCampaign(): void
    {
        $this->withErrorHandling(function () {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $items = $input['items'] ?? [];
            $budget = (float)($input['budget'] ?? 0);
            $name = trim($input['name'] ?? '');

            if (empty($items) || !is_array($items)) {
                $this->jsonError('Selecione pelo menos um produto para anunciar.', 400);
                return;
            }

            if ($budget < 5) {
                $this->jsonError('O orçamento mínimo é R$ 5,00 por dia.', 400);
                return;
            }

            if ($budget > 5000) {
                $this->jsonError('O orçamento máximo é R$ 5.000,00 por dia.', 400);
                return;
            }

            $result = $this->getWizardService()->createSimpleCampaign([
                'items' => array_map('trim', $items),
                'budget' => $budget,
                'name' => $name ?: null,
            ]);

            if ($result['success'] ?? false) {
                $this->jsonSuccess([
                    'campaign_id' => $result['campaign_id'] ?? null,
                    'message' => $result['message'] ?? 'Campanha criada com sucesso!',
                    'tips' => $result['tips'] ?? [],
                ]);
            } else {
                $this->jsonError($result['error'] ?? 'Erro ao criar campanha. Tente novamente.', 422);
            }
        }, 'AdsController::createCampaign');
    }

    /**
     * Ação rápida (pausar prejuízo / ativar tudo / otimizar)
     * POST /api/ads/quick-action
     */
    public function quickAction(): void
    {
        $this->withErrorHandling(function () {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $action = trim($input['action'] ?? '');

            $allowed = ['pause_unprofitable', 'activate_all', 'optimize'];
            if (!in_array($action, $allowed, true)) {
                $this->jsonError('Ação inválida. Use: ' . implode(', ', $allowed), 400);
                return;
            }

            $result = $this->getWizardService()->executeQuickAction($action);
            $this->json(array_merge(['success' => true], $result));
        }, 'AdsController::quickAction');
    }

    /**
     * Pausar/Ativar campanha individual
     * POST /api/ads/toggle/{campaignId}
     */
    public function toggleCampaign(string $campaignId): void
    {
        $this->withErrorHandling(function () use ($campaignId) {
            $campaignId = trim($campaignId);
            if (empty($campaignId) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $campaignId)) {
                $this->jsonError('ID de campanha inválido.', 400);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $newStatus = ($input['status'] ?? '') === 'active' ? 'active' : 'paused';

            $result = $this->getAdsService()->updateCampaignStatus($campaignId, $newStatus);

            if ($result['success'] ?? false) {
                $label = $newStatus === 'active' ? 'ativada' : 'pausada';
                $this->jsonSuccess([
                    'campaign_id' => $campaignId,
                    'status' => $newStatus,
                    'message' => "Campanha {$label} com sucesso!",
                ]);
            } else {
                $this->jsonError($result['error'] ?? 'Erro ao alternar campanha.', 422);
            }
        }, 'AdsController::toggleCampaign');
    }

    /**
     * Atualizar orçamento de campanha
     * POST /api/ads/budget/{campaignId}
     */
    public function updateBudget(string $campaignId): void
    {
        $this->withErrorHandling(function () use ($campaignId) {
            $campaignId = trim($campaignId);
            if (empty($campaignId) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $campaignId)) {
                $this->jsonError('ID de campanha inválido.', 400);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $budget = (float)($input['budget'] ?? 0);

            if ($budget < 5) {
                $this->jsonError('Orçamento mínimo: R$ 5,00/dia.', 400);
                return;
            }

            if ($budget > 5000) {
                $this->jsonError('Orçamento máximo: R$ 5.000,00/dia.', 400);
                return;
            }

            $result = $this->getAdsService()->updateCampaignBudget($campaignId, $budget);

            if ($result['success'] ?? false) {
                $this->jsonSuccess([
                    'campaign_id' => $campaignId,
                    'budget' => $budget,
                    'message' => 'Orçamento atualizado para R$ ' . number_format($budget, 2, ',', '.') . '/dia.',
                ]);
            } else {
                $this->jsonError($result['error'] ?? 'Erro ao atualizar orçamento.', 422);
            }
        }, 'AdsController::updateBudget');
    }

    /**
     * Glossário de termos
     * GET /api/ads/glossary
     */
    public function getGlossary(): void
    {
        $this->jsonSuccess(['glossary' => $this->getWizardService()->getGlossary()]);
    }
}
