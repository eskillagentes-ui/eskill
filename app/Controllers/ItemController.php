<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ItemService;
use App\Helpers\SessionHelper;

class ItemController extends BaseController
{
    private ItemService $itemService;
    private ?int $accountId = null;

    public function __construct()
    {
        parent::__construct();
        // Permite override via GET, senão usa a conta ativa da sessão
        $accountId = SessionHelper::getActiveAccountId();
        $this->accountId = $accountId ? (int)$accountId : null;
        $this->itemService = new ItemService($this->accountId);
    }

    /**
     * Lista anúncios do usuário
     * GET /api/items
     */
    public function index(): void
    {
        $filters = [
            'status' => $this->request->get('status'),
            'category' => $this->request->get('category'),
            'search' => $this->request->get('search') ?? $this->request->get('q'),
            'order' => $this->request->get('order'),
            'allow_local_cache' => $this->request->get('allow_local_cache'),
            'low_stock' => $this->request->getBool('low_stock', false),
            'high_sales' => $this->request->getBool('high_sales', false),
        ];

        // Remover filtros vazios opcionais
        $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');

        // Listagem padrão não chama visitas (skip_visits). Use skip_visits=0 para incluir.
        $filters['skip_visits'] = $this->request->getBool('skip_visits', true);

        $filters['limit'] = $this->request->getInt('limit', 50);
        $filters['page'] = max(1, $this->request->getInt('page', 1));
        $offset = $this->request->get('offset');
        if ($offset !== null) {
            $filters['offset'] = (int)$offset;
        }

        $results = $this->itemService->listItems($filters);

        if (($results['success'] ?? null) === false || isset($results['error'])) {
            $error = $results['error'] ?? null;
            if ($error === 'missing_seller_id') {
                http_response_code(409);
            } elseif ($error === 'local_cache_required') {
                http_response_code(422);
            } elseif ($error === 'ml_api_error') {
                http_response_code(502);
            } else {
                http_response_code(400);
            }
        }

        header('Content-Type: application/json');
        echo json_encode($results);
    }

    /**
     * Obtém detalhes de um anúncio
     * GET /api/items/{id}
     */
    public function show(string $id): void
    {
        $options = [];
        $allowLocalCache = $this->request->get('allow_local_cache');
        if ($allowLocalCache !== null) {
            $options['allow_local_cache'] = $allowLocalCache;
        }

        $item = $this->itemService->getItem($id, $options);

        if (isset($item['error']) || (($item['success'] ?? null) === false)) {
            $error = $item['error'] ?? null;
            if ($error === 'missing_seller_id') {
                http_response_code(409);
            } else {
                http_response_code(502);
            }
        }

        header('Content-Type: application/json');
        echo json_encode($item);
    }

    /**
     * Cria um novo anúncio
     * POST /api/items
     */
    public function create(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Dados inválidos']);
            return;
        }

        $result = $this->itemService->createItem($data);

        if (isset($result['error'])) {
            http_response_code(400);
        } else {
            http_response_code(201);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Atualiza um anúncio
     * PUT /api/items/{id}
     */
    public function update(string $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Dados inválidos']);
            return;
        }

        $result = [];
        
        // Atualizar Custo/Taxa (Local)
        if (array_key_exists('cost_price', $data) || array_key_exists('tax_rate', $data)) {
            $cost = isset($data['cost_price']) ? (float)$data['cost_price'] : null;
            $tax = isset($data['tax_rate']) ? (float)$data['tax_rate'] : null;
            $this->itemService->updateItemCost($id, $cost, $tax);
            $result['local_update'] = true;
        }

        // Atualizar Reprificação (Local)
        if (array_key_exists('pricing_strategy', $data) || array_key_exists('min_price', $data)) {
            $pricingData = [
                'pricing_strategy' => $data['pricing_strategy'] ?? null,
                'min_price' => isset($data['min_price']) ? (float)$data['min_price'] : null,
                'max_price' => isset($data['max_price']) ? (float)$data['max_price'] : null,
                'auto_reprice' => isset($data['auto_reprice']) ? (int)$data['auto_reprice'] : 0,
                'auto_negotiate' => isset($data['auto_negotiate']) ? (int)$data['auto_negotiate'] : 0,
            ];
            // Remove nulls if key didn't exist in input (partial updates), 
            // BUT we want to allow unsetting strategy, so we only filter if key missing from input
            // Let's filter based on array_key_exists in the input $data for safety in Service, 
            // but here we map specific keys.
            // Simplified: pass what is in data intersecting with allowed keys
            $updateData = array_intersect_key($data, array_flip(['pricing_strategy', 'min_price', 'max_price', 'auto_reprice', 'auto_negotiate']));
            
            if (!empty($updateData)) {
                $this->itemService->updateItemPricing($id, $updateData);
                $result['local_update'] = true;
            }
        }

        // Se houver campos do ML, atualizar lá também
        $mlFields = ['title', 'price', 'available_quantity', 'description', 'pictures', 'attributes', 'variations', 'shipping', 'sku', 'seller_custom_field'];
        
        // Map SKU to seller_custom_field for ML
        if (isset($data['sku'])) {
            $data['seller_custom_field'] = $data['sku'];
        }

        $hasMlFields = false;
        foreach ($mlFields as $field) {
            if (isset($data[$field])) {
                $hasMlFields = true;
                break;
            }
        }

        if ($hasMlFields) {
            $mlResult = $this->itemService->updateItem($id, $data);
            $result = array_merge($result, $mlResult);
            
            if (isset($mlResult['error'])) {
                http_response_code(400);
            }
        } else {
            $result['success'] = true;
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Deleta um anúncio
     * DELETE /api/items/{id}
     */
    public function delete(string $id): void
    {
        $result = $this->itemService->deleteItem($id);

        if (isset($result['error'])) {
            http_response_code(400);
        } else {
            http_response_code(204);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Pausa um anúncio
     * POST /api/items/{id}/pause
     */
    public function pause(string $id): void
    {
        $result = $this->itemService->pauseItem($id);

        if (isset($result['error'])) {
            http_response_code(400);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Reativa um anúncio
     * POST /api/items/{id}/activate
     */
    public function activate(string $id): void
    {
        $result = $this->itemService->activateItem($id);

        if (isset($result['error'])) {
            http_response_code(400);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Fecha um anúncio
     * POST /api/items/{id}/close
     */
    public function close(string $id): void
    {
        $result = $this->itemService->closeItem($id);

        if (isset($result['error'])) {
            http_response_code(400);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Atualiza preço de um anúncio
     * PUT /api/items/{id}/price
     */
    public function updatePrice(string $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['price'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Campo "price" é obrigatório']);
            return;
        }

        $result = $this->itemService->updatePrice($id, (float)$data['price']);

        if (isset($result['error'])) {
            http_response_code(400);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Atualiza estoque de um anúncio
     * PUT /api/items/{id}/stock
     */
    public function updateStock(string $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['quantity'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Campo "quantity" é obrigatório']);
            return;
        }

        $result = $this->itemService->updateStock($id, (int)$data['quantity']);

        if (isset($result['error'])) {
            http_response_code(400);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Lista anúncios por status
     * GET /api/items/status/{status}
     */
    public function byStatus(string $status): void
    {
        $results = $this->itemService->getItemsByStatus($status);

        header('Content-Type: application/json');
        echo json_encode($results);
    }

    /**
     * Lista anúncios por categoria
     * GET /api/items/category/{categoryId}
     */
    public function byCategory(string $categoryId): void
    {
        $results = $this->itemService->getItemsByCategory($categoryId);

        header('Content-Type: application/json');
        echo json_encode($results);
    }

    /**
     * Obtém estatísticas dos anúncios
     * GET /api/items/stats
     */
    public function stats(): void
    {
        $stats = $this->itemService->getItemsStats();

        if (($stats['success'] ?? null) === false || isset($stats['error'])) {
            $error = $stats['error'] ?? null;
            if ($error === 'missing_seller_id') {
                http_response_code(409);
            } else {
                http_response_code(400);
            }
        }

        header('Content-Type: application/json');
        echo json_encode($stats);
    }

    /**
     * Lista categorias utilizadas pelos anúncios do vendedor
     * GET /api/items/categories
     */
    public function categories(): void
    {
        $categories = $this->itemService->getSellerCategories();

        if (($categories['success'] ?? null) === false || isset($categories['error'])) {
            $error = $categories['error'] ?? null;
            if ($error === 'missing_seller_id') {
                http_response_code(409);
            } else {
                http_response_code(400);
            }
        }

        header('Content-Type: application/json');
        echo json_encode($categories);
    }

    /**
     * Sincroniza anúncios da conta com o banco local
     * POST /api/items/sync
     */
    public function sync(): void
    {
        $input = $this->request->json() ?? [];
        $limit = (int)($input['limit'] ?? 50);

        if (!$this->accountId) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'missing_account',
                'message' => 'Conta Mercado Livre não vinculada. Conecte a conta para sincronizar itens reais.'
            ]);
            return;
        }

        $result = $this->itemService->syncItems($limit);

        if (($result['success'] ?? null) === false || isset($result['error'])) {
            http_response_code(400);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Atualiza descrição de um anúncio
     * PUT /api/items/{id}/description
     */
    public function updateDescription(string $id): void
    {
        header('Content-Type: application/json');

        $data = json_decode(file_get_contents('php://input'), true);
        $plainText = $data['plain_text'] ?? null;

        if (empty($plainText)) {
            http_response_code(400);
            echo json_encode(['error' => 'Campo "plain_text" é obrigatório']);
            return;
        }

        try {
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            $result = $client->put("/items/{$id}/description", [
                'plain_text' => $plainText,
            ]);

            echo json_encode(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
