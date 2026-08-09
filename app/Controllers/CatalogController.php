<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ItemService;
use App\Services\UserService;

class CatalogController extends BaseController
{
    private ItemService $itemService;
    private UserService $userService;

    public function __construct()
    {
        parent::__construct();
        $this->itemService = new ItemService($_SESSION['active_ml_account_id'] ?? null);
        $this->userService = new UserService();
    }

    /**
     * Render Competition Dashboard with proper layout
     */
    public function index(): void
    {
        // Ensure user is authenticated
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $viewPath = __DIR__ . '/../Views/dashboard/catalog/competition.php';

        // Capture view content
        ob_start();
        require $viewPath;
        $content = ob_get_clean();

        // Render with layout
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /**
     * API: Get Losing Items
     */
    public function listLosingItems(): void
    {
        header('Content-Type: application/json');
        
        try {
            // Get List of items with catalog_product_id
            $result = $this->itemService->listItems(['limit' => 100]);

            if (($result['success'] ?? null) === false || isset($result['error'])) {
                $error = $result['error'] ?? 'unknown_error';
                if ($error === 'missing_seller_id') {
                    http_response_code(409);
                } else {
                    http_response_code(502);
                }
                echo json_encode($result);
                return;
            }

            $items = $result['items'] ?? [];
            
            $competitionItems = [];
            
            foreach ($items as $item) {
                // Only process items with catalog_product_id
                if (empty($item['catalog_product_id'])) {
                    continue;
                }
                
                try {
                    // Get catalog details including buy box winner
                    $catalogData = $this->itemService->getCatalogDetails($item['catalog_product_id']);

                    // Correcao de bug: getCatalogDetails() agora retorna buy_box_winner
                    // null quando nao ha disputa real de catalogo (antes fabricava um
                    // vencedor ficticio com preco 0, que empty() nunca filtrava porque
                    // o array tinha chaves preenchidas). Sem winner real, nao ha como
                    // avaliar competicao -- pula o item em vez de reportar 'perdendo'.
                    if (!isset($catalogData['buy_box_winner']) || $catalogData['buy_box_winner'] === null) {
                        continue;
                    }

                    $myPrice = floatval($item['price']);
                    $winnerPrice = floatval($catalogData['buy_box_winner']['price']);
                    $isWinning = $catalogData['is_winner'] ?? null;

                    // Calculate suggested price to win (0.5% below winner)
                    $priceToWin = round($winnerPrice * 0.995, 2);
                    
                    $competitionItems[] = [
                        'id' => $item['id'],
                        'title' => $item['title'],
                        'thumbnail' => $item['thumbnail'] ?? null,
                        'permalink' => $item['permalink'],
                        'my_price' => $myPrice,
                        'buy_box_winner' => $catalogData['buy_box_winner'],
                        // is_winner vem direto da API (comparacao real de seller_id),
                        // nunca deve ser recalculado so por preco no frontend.
                        'is_winner' => $isWinning,
                        'price_to_win' => $priceToWin,
                        'catalog_product_id' => $item['catalog_product_id']
                    ];
                } catch (\Exception $e) {
                    // Skip items with errors
                    continue;
                }
            }
            
            echo json_encode([
                'success' => true,
                'items' => $competitionItems,
                'message' => empty($competitionItems) ? 'Nenhum item de catálogo encontrado para análise (sem demo/fallback).' : null,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
}
