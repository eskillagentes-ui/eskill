<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OrderService;
use App\Security\AccountAccessException;
use App\Security\AccountContextResolver;

class OrderController extends BaseController
{
    private OrderService $orderService;

    public function __construct()
    {
        parent::__construct();
        // SEC-001: nunca confiar em account_id cru sem policy
        $accountId = null;
        try {
            $accountId = (new AccountContextResolver())
                ->authorizeForCurrentActor('orders.read')
                ->accountId();
        } catch (AccountAccessException $e) {
            $accountId = null;
        }
        $this->orderService = new OrderService($accountId);
    }

    /**
     * Lista todos os pedidos com filtros
     * GET /api/orders/all
     */
    public function all(): void
    {
        $filters = [
            'status' => $this->request->get('status'),
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
            'search' => $this->request->get('search') ?? $this->request->get('q'),
            'sort' => $this->request->get('sort', 'date_created'),
            'order' => $this->request->get('order', 'DESC'),
            'allow_local_cache' => $this->request->get('allow_local_cache'),
            'source' => $this->request->get('source'),
        ];

        // Remover filtros vazios opcionais
        $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');

        $filters['limit'] = $this->request->getInt('limit', 50);
        $filters['page'] = max(1, $this->request->getInt('page', 1));
        $offset = $this->request->get('offset');
        if ($offset !== null) {
            $filters['offset'] = (int)$offset;
        }

        try {
            $results = $this->orderService->listOrders($filters);
            if (isset($results['error'])) {
                $error = (string)$results['error'];
                if (in_array($error, ['missing_seller_id', 'local_cache_required'], true)) {
                    http_response_code(422);
                    if ($error === 'missing_seller_id' && empty($results['message'])) {
                        $results['message'] = 'Nenhuma conta de vendedor ativa. Conecte uma conta do Mercado Livre para acessar seus pedidos.';
                        $results['action'] = 'connect_account';
                    }
                } elseif (in_array($error, ['db_unavailable', 'network_disabled', 'circuit_breaker_open'], true)) {
                    http_response_code(503);
                } elseif ($error === 'missing_token') {
                    http_response_code(401);
                } else {
                    http_response_code(502);
                }
            }
            header('Content-Type: application/json');
            echo json_encode($results);
        } catch (\Exception $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Lista pedidos (alias para all)
     * GET /api/orders
     */
    public function index(): void
    {
        $this->all();
    }

    /**
     * Obtém detalhes de um pedido
     * GET /api/orders/{id}
     */
    public function show(string $id): void
    {
        try {
            $options = [];
            $allowLocalCache = $this->request->get('allow_local_cache');
            if ($allowLocalCache !== null) {
                $options['allow_local_cache'] = $allowLocalCache;
            }

            $source = $this->request->get('source');
            if ($source !== null) {
                $options['source'] = $source;
            }

            $order = $this->orderService->getOrder($id, $options);
            if (isset($order['error'])) {
                $error = (string)$order['error'];
                if (in_array($error, ['not_found', 'order_not_found'], true)) {
                    http_response_code(404);
                } elseif (in_array($error, ['db_unavailable', 'network_disabled', 'circuit_breaker_open'], true)) {
                    http_response_code(503);
                } elseif ($error === 'missing_token') {
                    http_response_code(401);
                } else {
                    http_response_code(502);
                }
            }
            header('Content-Type: application/json');
            echo json_encode($order);
        } catch (\Exception $e) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Sincroniza pedidos do Mercado Livre
     * POST /api/orders/sync
     * GET /api/orders/sync
     */
    public function sync(): void
    {
        try {
            $result = $this->orderService->syncOrders();
            header('Content-Type: application/json');
            echo json_encode($result);
        } catch (\Exception $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => true,
                'message' => $e->getMessage()
            ]);
        }
    }
}
