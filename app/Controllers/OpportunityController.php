<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\SessionHelper;
use App\Services\OpportunityDetectorService;
use App\Services\SearchService;
use App\Services\UserService;

class OpportunityController extends BaseController
{
    private OpportunityDetectorService $opportunityService;
    private UserService $userService;

    public function __construct()
    {
        parent::__construct();
        $this->opportunityService = new OpportunityDetectorService();
        $this->userService = new UserService();
    }

    /**
     * GET /api/opportunities/scan
     * Contrato da view dashboard/opportunities.php (TC011).
     */
    public function scan(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');

        if (!$this->userService->isAuthenticated()) {
            $this->jsonError('Unauthorized', 401);
            return;
        }

        $category = trim((string) ($this->request->get('category') ?? ''));
        if ($category === '') {
            $this->json([
                'success' => true,
                'opportunities' => [],
                'message' => 'Selecione uma categoria para buscar oportunidades',
            ]);
            return;
        }

        if (!preg_match('/^MLB[0-9]+$/i', $category)) {
            $this->jsonError('Categoria inválida', 400, ['opportunities' => []]);
            return;
        }

        $minMargin = max(0, (float) ($this->request->get('min_margin') ?? 0));
        $minSales = max(0, (int) ($this->request->get('min_sales') ?? 0));

        try {
            $accountId = SessionHelper::getActiveAccountId();
            $search = new SearchService(
                ($accountId !== null && $accountId > 0) ? $accountId : null
            );
            $result = $search->scanForOpportunities($category);
        } catch (\Throwable $e) {
            log_warning('OpportunityController::scan falhou', [
                'category' => $category,
                'error' => $e->getMessage(),
            ]);
            $this->json([
                'success' => false,
                'opportunities' => [],
                'error' => 'scan_unavailable',
                'message' => 'Não foi possível buscar oportunidades. Verifique a conta ML.',
            ], 503);
            return;
        }

        $raw = $result['opportunities'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $mapped = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sold = (int) ($row['sold_quantity'] ?? 0);
            if ($minSales > 0 && $sold < $minSales) {
                continue;
            }
            // Margin real exige custo; filtro só aplica quando o scan já trouxer margin.
            $margin = isset($row['margin']) ? (float) $row['margin'] : null;
            if ($minMargin > 0 && $margin !== null && $margin < $minMargin) {
                continue;
            }
            $mapped[] = [
                'title' => (string) ($row['title'] ?? ''),
                'category_name' => $category,
                'estimated_sales' => $sold,
                'margin' => $margin ?? 0,
                'competitors' => (int) ($row['competitors'] ?? 0),
                'item_id' => $row['item_id'] ?? null,
                'link' => $row['link'] ?? null,
                'reasons' => $row['reasons'] ?? [],
            ];
        }

        $ok = ($result['success'] ?? true) !== false;
        // Degradação graciosa: UI trata lista vazia; HTTP 200 evita requestJson→catch
        // por PolicyAgent/datacenter (TC011 sem OAuth). Erro fica em success/message.
        $this->json([
            'success' => $ok,
            'opportunities' => $mapped,
            'message' => $ok
                ? null
                : (string) ($result['message'] ?? 'Busca de oportunidades indisponível — conecte conta ML ou tente mais tarde'),
            'error' => $ok ? null : ($result['error'] ?? 'search_unavailable'),
        ], 200);
    }

    /**
     * Detecta produtos sem catálogo
     */
    public function productsWithoutCatalog(): void
    {
        $categoryId = $this->request->get('category');
        $brand = $this->request->get('brand');

        if (!$categoryId || !$brand) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetros "category" e "brand" são obrigatórios']);
            return;
        }

        $result = $this->opportunityService->detectProductsWithoutCatalog($categoryId, $brand);

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Detecta categorias com pouca concorrência
     */
    public function lowCompetitionCategories(): void
    {
        $parentCategoryId = $this->request->get('parent_category');

        $result = $this->opportunityService->detectLowCompetitionCategories($parentCategoryId);

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Detecta produtos mais vendidos sem anúncio do usuário
     */
    public function bestSellersWithoutListing(): void
    {
        $categoryId = $this->request->get('category');
        $brand = $this->request->get('brand');
        $accountId = $this->request->getInt('account_id');

        if (!$categoryId || !$brand || !$accountId) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetros "category", "brand" e "account_id" são obrigatórios']);
            return;
        }

        $result = $this->opportunityService->detectBestSellersWithoutUserListing($categoryId, $brand, $accountId);

        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
