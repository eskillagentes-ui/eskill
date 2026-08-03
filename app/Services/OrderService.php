<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Helpers\Log;
use App\Helpers\SessionHelper;
use PDO;
use Throwable;

class OrderService
{
    private MercadoLivreClient $client;
    private ?PDO $db;
    private ?int $accountId;

    public function __construct(
        ?int $accountId = null,
        ?MercadoLivreClient $client = null,
        ?PDO $db = null,
        bool $skipDbAutoConnect = false
    ) {
        $this->accountId = $accountId;

        if ($client !== null) {
            $this->client = $client;
        } else {
            $this->client = new MercadoLivreClient($accountId);
        }

        if ($db !== null) {
            $this->db = $db;
        } elseif ($skipDbAutoConnect) {
            $this->db = null;
        } else {
            try {
                $this->db = Database::getInstance();
            } catch (Throwable $e) {
                $this->db = null;
                log_warning('OrderService: DB indisponível, operando em modo API-only', [
                    'service' => 'OrderService',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Lista pedidos com filtros
     */
    public function listOrders(array $filters = []): array
    {
        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $page = max(1, (int)($filters['page'] ?? 1));

        if (isset($filters['offset'])) {
            $offset = max(0, (int)$filters['offset']);
            $page = (int)floor($offset / $limit) + 1;
        } else {
            $offset = ($page - 1) * $limit;
        }

        $params = [
            'limit' => $limit,
            'offset' => $offset,
            'sort' => 'date_desc',
        ];

        if (!empty($filters['status'])) {
            $status = (string)$filters['status'];
            $params['status'] = $status;
            $params['order.status'] = $status;
        }

        if (!empty($filters['date_from'])) {
            $params['order.date_created.from'] = $this->normalizeDateForMl((string)$filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $params['order.date_created.to'] = $this->normalizeDateForMl((string)$filters['date_to'], true);
        }

        $sellerId = null;
        if (!empty($filters['seller_id'])) {
            $sellerId = (string)$filters['seller_id'];
        } else {
            $sellerId = $this->client->getSellerId();
        }

        if ($sellerId) {
            $params['seller'] = $sellerId;
        } elseif ($this->shouldAllowLocalFallback($filters)) {
            return $this->listOrdersFromDatabase($filters, $limit, $page, $offset, [
                'warning' => 'Seller ID não disponível; exibindo cache local.',
                'reason' => 'missing_seller_id',
            ]);
        } else {
            return $this->emptyOrdersPayload($limit, $page, $offset, [
                'error' => 'missing_seller_id',
                'message' => 'Conta Mercado Livre não vinculada (seller_id ausente).',
                'source' => 'ml_api',
            ]);
        }

        $response = $this->unwrapMlResponse($this->client->get('/orders/search', $params));

        if (isset($response['error'])) {
            if ($this->isOrdersCapabilityUnavailable($response)) {
                if ($this->shouldAllowLocalFallback($filters)) {
                    return $this->listOrdersFromDatabase($filters, $limit, $page, $offset, [
                        'warning' => $this->formatMlApiErrorMessage(
                            $response,
                            'Acesso à API de pedidos indisponível para esta conta; exibindo cache local'
                        ),
                        'reason' => 'orders_access_unavailable',
                    ]);
                }

                return $this->emptyOrdersPayload($limit, $page, $offset, [
                    'error' => $response['error'],
                    'message' => $this->formatMlApiErrorMessage(
                        $response,
                        'Acesso à API de pedidos do Mercado Livre indisponível'
                    ),
                    'api_error' => $response,
                    'feature' => $response['feature'] ?? 'orders',
                    'feature_unavailable' => true,
                    'source' => 'ml_api',
                ]);
            }

            if ($this->shouldAllowLocalFallback($filters)) {
                return $this->listOrdersFromDatabase($filters, $limit, $page, $offset, [
                    'warning' => $this->formatMlApiErrorMessage(
                        $response,
                        'Falha na API do Mercado Livre; exibindo cache local'
                    ),
                    'reason' => 'ml_api_error',
                ]);
            }

            return $this->emptyOrdersPayload($limit, $page, $offset, [
                'error' => 'ml_api_error',
                'message' => $this->formatMlApiErrorMessage(
                    $response,
                    'Falha ao buscar pedidos na API do Mercado Livre'
                ),
                'api_error' => $response,
                'source' => 'ml_api',
            ]);
        }

        $ordersRaw = $response['results'] ?? [];
        if (!is_array($ordersRaw)) {
            $ordersRaw = [];
        }

        $orders = [];
        foreach ($ordersRaw as $orderData) {
            if (!is_array($orderData)) {
                continue;
            }

            $orders[] = $this->normalizeOrderSummary($orderData);

            try {
                $this->saveOrder($orderData);
            } catch (Throwable $e) {
                Log::warning('OrderService: falha ao salvar pedido no cache local', [
                    'order_id' => $orderData['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($filters['search'])) {
            $needle = strtolower((string)$filters['search']);
            $orders = array_values(array_filter($orders, function (array $order) use ($needle): bool {
                $id = strtolower((string)($order['id'] ?? ''));
                $buyer = strtolower((string)($order['buyer']['nickname'] ?? ''));
                return str_contains($id, $needle) || str_contains($buyer, $needle);
            }));
        }

        $sortField = $filters['sort'] ?? 'date_created';
        $sortOrder = strtoupper($filters['order'] ?? 'DESC');
        $allowedSortFields = ['date_created', 'total_amount', 'status', 'ml_order_id'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'date_created';
        }
        if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
            $sortOrder = 'DESC';
        }

        usort($orders, function (array $a, array $b) use ($sortField, $sortOrder): int {
            $left = $a[$sortField] ?? null;
            $right = $b[$sortField] ?? null;
            if ($sortField === 'ml_order_id') {
                $left = $a['id'] ?? null;
                $right = $b['id'] ?? null;
            }
            $cmp = ($left <=> $right);
            return $sortOrder === 'ASC' ? $cmp : -$cmp;
        });

        $total = $response['paging']['total'] ?? count($orders);
        if (!is_numeric($total)) {
            $total = count($orders);
        }
        $total = (int)$total;
        $pages = $total > 0 ? (int)ceil($total / $limit) : 1;

        return [
            'success' => true,
            'source' => 'ml_api',
            'results' => $orders,
            'orders' => $orders,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    /**
     * Obtém um pedido específico
     */
    public function getOrder(string $orderId, array $options = []): array
    {
        $response = $this->unwrapMlResponse($this->client->get("/orders/{$orderId}"));
        if (!isset($response['error'])) {
            try {
                $this->saveOrder($response);
            } catch (Throwable $e) {
                Log::warning('OrderService: falha ao atualizar cache local do pedido', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'source' => 'ml_api',
                'id' => $response['id'] ?? $orderId,
                'status' => $response['status'] ?? null,
                'total_amount' => isset($response['total_amount']) ? (float)$response['total_amount'] : 0.0,
                'date_created' => $response['date_created'] ?? null,
                'data' => $response,
            ];
        }

        if ($this->shouldAllowLocalFallback($options)) {
            $local = $this->getOrderFromDatabase($orderId);
            if ($local !== null) {
                $local['warning'] = $this->formatMlApiErrorMessage(
                    $response,
                    'Falha ao consultar pedido na API, retornando cache local'
                );
                $local['fallback_from'] = 'ml_api';
                return $local;
            }
        }

        return [
            'success' => false,
            'source' => 'ml_api',
            'error' => $response['error'] ?? 'order_not_found',
            'message' => $this->formatMlApiErrorMessage(
                $response,
                'Pedido não encontrado na API do Mercado Livre'
            ),
            'data' => [],
        ];
    }

    /**
     * Sincroniza pedidos do Mercado Livre
     */
    public function syncOrders(?int $accountId = null, int $limit = 50, array $options = []): array
    {
        if ($accountId !== null && $accountId !== $this->accountId) {
            $this->accountId = $accountId;
            $this->client = new MercadoLivreClient($accountId);
        }

        $sellerId = $this->client->getSellerId();

        if (!$sellerId) {
            throw new \Exception('Seller ID não encontrado');
        }

        $pageSize = max(1, min(50, (int)$limit));
        $pageLimit = max(1, min(1000, (int)($options['page_limit'] ?? 20)));
        $fullBackfill = (bool)($options['full_backfill'] ?? false);
        $persistCheckpoint = (bool)($options['persist_checkpoint'] ?? true);
        $cursor = isset($options['cursor']) ? max(0, (int)$options['cursor']) : 0;
        $overlapSeconds = max(0, min(86400, (int)($options['overlap_seconds'] ?? 300)));
        $resourceType = (string)($options['resource_type'] ?? SyncStatusService::RESOURCE_ORDERS);

        $syncStatusService = null;
        $existingSyncStatus = null;
        if ($this->db !== null && $this->accountId !== null) {
            try {
                $syncStatusService = new SyncStatusService($this->db);
                $existingSyncStatus = $syncStatusService->getStatus($resourceType, $this->accountId);
            } catch (Throwable $e) {
                Log::warning('OrderService: SyncStatusService indisponível durante syncOrders', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $since = isset($options['since']) ? (string)$options['since'] : null;
        $until = isset($options['until']) ? (string)$options['until'] : null;

        if (!$fullBackfill && $since === null && $existingSyncStatus !== null && !empty($existingSyncStatus['last_sync_at'])) {
            $checkpointTs = strtotime((string)$existingSyncStatus['last_sync_at']);
            if ($checkpointTs !== false) {
                $since = date('c', max(0, $checkpointTs - $overlapSeconds));
            }
        }

        if ($syncStatusService !== null && $persistCheckpoint && $this->accountId !== null) {
            try {
                $syncStatusService->markRunning($resourceType, $this->accountId);
            } catch (Throwable $e) {
                Log::warning('OrderService: falha ao marcar sync_status running', [
                    'account_id' => $this->accountId,
                    'resource_type' => $resourceType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $synced = 0;
        $errors = [];
        $pagesProcessed = 0;
        $offset = $cursor;
        $totalFromApi = null;
        $hasMore = false;
        $latestOrderId = null;
        $latestOrderDate = null;
        $latestOrderTs = null;

        try {
            do {
                $params = [
                    'seller' => $sellerId,
                    'sort' => 'date_desc',
                    'limit' => $pageSize,
                    'offset' => $offset,
                ];

                if ($since !== null && trim($since) !== '') {
                    $params['order.date_created.from'] = $this->normalizeDateForMl($since);
                }
                if ($until !== null && trim($until) !== '') {
                    $params['order.date_created.to'] = $this->normalizeDateForMl($until, true);
                }

                $response = $this->unwrapMlResponse($this->client->get('/orders/search', $params));
                if (isset($response['error'])) {
                    throw new \RuntimeException($response['message'] ?? 'Erro ao sincronizar pedidos');
                }

                $orders = isset($response['results']) && is_array($response['results'])
                    ? $response['results']
                    : [];

                if ($totalFromApi === null && isset($response['paging']['total']) && is_numeric($response['paging']['total'])) {
                    $totalFromApi = (int)$response['paging']['total'];
                }

                $pageCount = 0;
                foreach ($orders as $order) {
                    $pageCount++;
                    try {
                        if (!is_array($order)) {
                            throw new \RuntimeException('Payload inválido de pedido');
                        }

                        $this->saveOrder($order);
                        $synced++;

                        $orderId = isset($order['id']) ? (string)$order['id'] : null;
                        $orderDate = isset($order['date_created']) ? (string)$order['date_created'] : null;
                        $orderTs = $orderDate !== null ? strtotime($orderDate) : false;

                        if ($orderId !== null && $orderDate !== null && $orderTs !== false) {
                            if ($latestOrderTs === null || $orderTs > $latestOrderTs) {
                                $latestOrderTs = (int)$orderTs;
                                $latestOrderDate = $orderDate;
                                $latestOrderId = $orderId;
                            }
                        }
                    } catch (Throwable $e) {
                        $errors[] = [
                            'order_id' => is_array($order) ? ($order['id'] ?? 'unknown') : 'unknown',
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                $pagesProcessed++;
                $offset += $pageCount;

                if ($pageCount === 0) {
                    $hasMore = false;
                    break;
                }

                if ($totalFromApi !== null) {
                    $hasMore = $offset < $totalFromApi;
                } else {
                    $hasMore = $pageCount >= $pageSize;
                }
            } while ($hasMore && $pagesProcessed < $pageLimit);
        } catch (Throwable $e) {
            if ($syncStatusService !== null && $persistCheckpoint && $this->accountId !== null) {
                try {
                    $syncStatusService->markError($resourceType, $this->accountId, $e->getMessage());
                } catch (Throwable $inner) {
                    Log::warning('OrderService: falha ao marcar sync_status error', [
                        'account_id' => $this->accountId,
                        'resource_type' => $resourceType,
                        'error' => $inner->getMessage(),
                    ]);
                }
            }
            throw $e;
        }

        if ($syncStatusService !== null && $persistCheckpoint && $this->accountId !== null) {
            try {
                $syncStatusService->markSuccess(
                    $resourceType,
                    $this->accountId,
                    $synced,
                    $this->buildOrderSyncCheckpointId($latestOrderId, $offset, $hasMore),
                    $latestOrderDate !== null ? date('Y-m-d H:i:s', (int)strtotime($latestOrderDate)) : null
                );
            } catch (Throwable $e) {
                Log::warning('OrderService: falha ao persistir checkpoint em sync_status', [
                    'account_id' => $this->accountId,
                    'resource_type' => $resourceType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => true,
            'synced' => $synced,
            'total' => $totalFromApi ?? $synced,
            'errors' => $errors,
            'error_count' => count($errors),
            'pages_processed' => $pagesProcessed,
            'page_size' => $pageSize,
            'cursor' => $cursor,
            'next_cursor' => $hasMore ? $offset : null,
            'has_more' => $hasMore,
            'filters' => [
                'since' => $since,
                'until' => $until,
                'full_backfill' => $fullBackfill,
                'page_limit' => $pageLimit,
            ],
            'checkpoint' => [
                'last_sync_at' => $latestOrderDate,
                'last_sync_id' => $latestOrderId,
            ],
        ];
    }

    /**
     * Busca dados de um envio (Mercado Envios) pelo shipment_id
     *
     * @param string $shipmentId ID do envio retornado pela API ML
     * @return array{
     *   success: bool,
     *   source: string,
     *   shipment_id?: string,
     *   status?: string,
     *   substatus?: string|null,
     *   tracking_number?: string|null,
     *   tracking_method?: string|null,
     *   date_created?: string|null,
     *   last_updated?: string|null,
     *   shipping_items?: array<mixed>,
     *   destination?: array<mixed>|null,
     *   sender?: array<mixed>|null,
     *   data?: array<mixed>,
     *   error?: string,
     *   message?: string
     * }
     */
    public function getShipment(string $shipmentId, array $options = []): array
    {
        try {
            $response = $this->unwrapMlResponse(
                $this->client->get("/shipments/{$shipmentId}")
            );

            if (!isset($response['error'])) {
                // Atualiza status do envio no cache local do pedido associado
                try {
                    $this->updateOrderShippingStatus($shipmentId, $response);
                } catch (Throwable $e) {
                    Log::warning('OrderService: falha ao atualizar status de envio no cache', [
                        'shipment_id' => $shipmentId,
                        'error' => $e->getMessage(),
                    ]);
                }

                return [
                    'success' => true,
                    'source' => 'ml_api',
                    'shipment_id' => isset($response['id']) ? (string)$response['id'] : $shipmentId,
                    'status' => $response['status'] ?? null,
                    'substatus' => $response['substatus'] ?? null,
                    'tracking_number' => $response['tracking_number'] ?? null,
                    'tracking_method' => $response['tracking_method'] ?? null,
                    'date_created' => $response['date_created'] ?? null,
                    'last_updated' => $response['last_updated'] ?? null,
                    'shipping_items' => isset($response['shipping_items']) && is_array($response['shipping_items'])
                        ? $response['shipping_items']
                        : [],
                    'destination' => $response['destination'] ?? null,
                    'sender' => $response['sender'] ?? null,
                    'data' => $response,
                ];
            }

            return [
                'success' => false,
                'source' => 'ml_api',
                'error' => is_string($response['error']) ? $response['error'] : 'shipment_not_found',
                'message' => $this->formatMlApiErrorMessage(
                    $response,
                    'Envio não encontrado na API do Mercado Livre'
                ),
                'data' => [],
            ];
        } catch (Throwable $e) {
            Log::error('OrderService::getShipment falhou', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => 'exception', 'message' => $e->getMessage()];
        }
    }

    /**
     * Expõe o MercadoLivreClient para uso em outros contextos (ex: webhook handlers)
     */
    public function getClient(): MercadoLivreClient
    {
        return $this->client;
    }

    /**
     * Atualiza o shipping_status de pedidos associados ao shipment no cache local
     */
    private function updateOrderShippingStatus(string $shipmentId, array $shipmentData): void
    {
        if ($this->db === null || $this->accountId === null) {
            return;
        }

        $status = $shipmentData['status'] ?? null;
        if ($status === null || !is_string($status)) {
            return;
        }

        // Tenta atualizar pedidos que tenham o shipping.id correspondente no JSON
        $stmt = $this->db->prepare("
            UPDATE ml_orders
            SET synced_at = CURRENT_TIMESTAMP
            WHERE ml_account_id = :account_id
              AND JSON_UNQUOTE(JSON_EXTRACT(order_data, '\$.shipping.id')) = :shipment_id
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'shipment_id' => $shipmentId,
        ]);
    }

    /**
     * Salva ou atualiza um pedido no banco
     */
    private function saveOrder(array $orderData): void
    {
        if ($this->db === null) {
            return;
        }

        if (!isset($orderData['id'])) {
            throw new \Exception('ID do pedido não encontrado');
        }

        if ($this->accountId === null) {
            return;
        }

        $userId = SessionHelper::getUserId();

        // Se não houver usuário na sessão (CRON), buscar da conta vinculada
        if (!$userId && $this->accountId) {
            $userId = $this->getAccountUserId($this->accountId);
        }

        $financials = $this->extractFinancialColumns($orderData);

        $stmt = $this->db->prepare("
            INSERT INTO ml_orders (
                ml_order_id, ml_account_id, user_id, order_data, status,
                total_amount, subtotal, ml_commission, payment_fee, shipping_cost,
                discount_amount, net_profit, date_created
            )
            VALUES (
                :ml_order_id, :ml_account_id, :user_id, :order_data, :status,
                :total_amount, :subtotal, :ml_commission, :payment_fee, :shipping_cost,
                :discount_amount, :net_profit, :date_created
            )
            ON DUPLICATE KEY UPDATE
                order_data = :order_data_upd,
                status = :status_upd,
                total_amount = :total_amount_upd,
                subtotal = :subtotal_upd,
                ml_commission = :ml_commission_upd,
                payment_fee = :payment_fee_upd,
                shipping_cost = :shipping_cost_upd,
                discount_amount = :discount_amount_upd,
                net_profit = :net_profit_upd,
                synced_at = CURRENT_TIMESTAMP
        ");

        $orderJson = json_encode($orderData) ?: '{}';
        $status = $orderData['status'] ?? 'unknown';
        $total = $financials['total_amount'];
        $shippingCost = $financials['shipping_cost'];

        $stmt->execute([
            'ml_order_id' => $orderData['id'],
            'ml_account_id' => $this->accountId,
            'user_id' => $userId,
            'order_data' => $orderJson,
            'status' => $status,
            'total_amount' => $total,
            'subtotal' => $financials['subtotal'],
            'ml_commission' => $financials['ml_commission'],
            'payment_fee' => $financials['payment_fee'],
            'shipping_cost' => $shippingCost,
            'discount_amount' => $financials['discount_amount'],
            'net_profit' => $financials['net_profit'],
            'date_created' => $orderData['date_created'] ?? date('Y-m-d H:i:s'),
            'order_data_upd' => $orderJson,
            'status_upd' => $status,
            'total_amount_upd' => $total,
            'subtotal_upd' => $financials['subtotal'],
            'ml_commission_upd' => $financials['ml_commission'],
            'payment_fee_upd' => $financials['payment_fee'],
            'shipping_cost_upd' => $shippingCost,
            'discount_amount_upd' => $financials['discount_amount'],
            'net_profit_upd' => $financials['net_profit'],
        ]);
    }

    /**
     * Denormaliza campos financeiros do payload ML para colunas de ml_orders (DRE).
     *
     * @param array<string, mixed> $orderData
     * @return array{total_amount: float, subtotal: float, ml_commission: float, payment_fee: float, shipping_cost: float, discount_amount: float, net_profit: float}
     */
    private function extractFinancialColumns(array $orderData): array
    {
        $subtotal = 0.0;
        $mlCommission = 0.0;
        foreach ($orderData['order_items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = (int)($item['quantity'] ?? 1);
            $subtotal += (float)($item['unit_price'] ?? 0) * max(1, $qty);
            $mlCommission += (float)($item['sale_fee'] ?? 0);
        }

        $paymentFee = 0.0;
        foreach ($orderData['payments'] ?? [] as $payment) {
            if (!is_array($payment) || ($payment['status'] ?? '') !== 'approved') {
                continue;
            }
            foreach ($payment['fee_details'] ?? [] as $feeDetail) {
                if (is_array($feeDetail)) {
                    $paymentFee += (float)($feeDetail['amount'] ?? 0);
                }
            }
        }

        $totalAmount = (float)($orderData['total_amount'] ?? 0);
        $shippingCost = (float)($orderData['shipping']['cost'] ?? 0);
        $discountAmount = (float)($orderData['coupon']['amount'] ?? ($orderData['discount'] ?? 0));
        $netProfit = $totalAmount - $mlCommission - $paymentFee - $shippingCost - $discountAmount;

        return [
            'total_amount' => round($totalAmount, 2),
            'subtotal' => round($subtotal, 2),
            'ml_commission' => round($mlCommission, 2),
            'payment_fee' => round($paymentFee, 2),
            'shipping_cost' => round($shippingCost, 2),
            'discount_amount' => round($discountAmount, 2),
            'net_profit' => round($netProfit, 2),
        ];
    }

    private function getAccountUserId(int $accountId): ?int
    {
        if ($this->db === null) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT user_id FROM ml_accounts WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $accountId]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Obtém o nickname da conta ML
     */
    private function getAccountNickname(int $accountId): ?string
    {
        if ($this->db === null) {
            return null;
        }

        static $cache = [];

        if (isset($cache[$accountId])) {
            return $cache[$accountId];
        }

        $stmt = $this->db->prepare("SELECT nickname FROM ml_accounts WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $accountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $cache[$accountId] = $result['nickname'] ?? null;
        return $cache[$accountId];
    }

    private function getOrderFromDatabase(string $orderId): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $sql = "SELECT * FROM ml_orders WHERE ml_order_id = :order_id";
        $params = ['order_id' => $orderId];
        if ($this->accountId !== null && $this->accountId > 0) {
            $sql .= " AND ml_account_id = :account_id";
            $params['account_id'] = $this->accountId;
        }
        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $orderData = json_decode((string)($row['order_data'] ?? '{}'), true);
        if (!is_array($orderData)) {
            $orderData = [];
        }

        return [
            'success' => true,
            'source' => 'local',
            'id' => $row['ml_order_id'],
            'status' => $row['status'],
            'total_amount' => (float)$row['total_amount'],
            'date_created' => $row['date_created'],
            'synced_at' => $row['synced_at'],
            'data' => $orderData,
        ];
    }

    private function listOrdersFromDatabase(array $filters, int $limit, int $page, int $offset, array $context = []): array
    {
        if ($this->db === null) {
            return $this->emptyOrdersPayload($limit, $page, $offset, [
                'error' => 'db_unavailable',
                'message' => 'Banco indisponível para consultar cache local de pedidos.',
                'source' => 'local',
            ]);
        }

        $where = [];
        $params = [];

        $userId = SessionHelper::getUserId();
        $userFilterApplied = false;
        if ($userId) {
            $where[] = 'user_id = :user_id';
            $params['user_id'] = $userId;
            $userFilterApplied = true;
        }

        if ($this->accountId) {
            $where[] = 'ml_account_id = :account_id';
            $params['account_id'] = $this->accountId;
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'date_created >= :date_from';
            $params['date_from'] = $this->normalizeDateForLocalFilter((string)$filters['date_from'], false);
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'date_created <= :date_to';
            $params['date_to'] = $this->normalizeDateForLocalFilter((string)$filters['date_to'], true);
        }

        $searchTerm = $filters['search'] ?? null;
        if ($searchTerm) {
            $where[] = '(ml_order_id LIKE :search OR order_data LIKE :search)';
            $params['search'] = '%' . $searchTerm . '%';
        }

        $sortField = $filters['sort'] ?? 'date_created';
        $sortOrder = strtoupper($filters['order'] ?? 'DESC');

        $allowedSortFields = ['date_created', 'total_amount', 'status', 'ml_order_id'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'date_created';
        }
        if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
            $sortOrder = 'DESC';
        }

        $orderSql = "ORDER BY {$sortField} {$sortOrder}";
        $runLocalQuery = function (array $whereClauses, array $queryParams) use ($orderSql, $limit, $offset): array {
            $whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM ml_orders {$whereSql}");
            $countStmt->execute($queryParams);
            $total = (int)$countStmt->fetchColumn();

            $selectSql = "SELECT id, ml_order_id, ml_account_id, order_data, status, total_amount, date_created, synced_at "
                . "FROM ml_orders {$whereSql} {$orderSql} LIMIT {$limit} OFFSET {$offset}";

            $stmt = $this->db->prepare($selectSql);
            $stmt->execute($queryParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [$total, $rows];
        };

        try {
            [$total, $rows] = $runLocalQuery($where, $params);
        } catch (Throwable $e) {
            if ($userFilterApplied && $this->isUnknownColumnError($e, 'user_id')) {
                Log::warning('OrderService: coluna user_id ausente em ml_orders; retry sem filtro de usuário', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);

                $where = array_values(array_filter(
                    $where,
                    fn(string $clause): bool => $clause !== 'user_id = :user_id'
                ));
                unset($params['user_id']);

                if (!isset($context['warning']) || !is_string($context['warning']) || $context['warning'] === '') {
                    $context['warning'] = 'Filtro por usuário indisponível no cache local (schema legado).';
                }

                [$total, $rows] = $runLocalQuery($where, $params);
            } else {
                throw $e;
            }
        }

        $orders = [];
        foreach ($rows as $row) {
            $orderData = json_decode((string)($row['order_data'] ?? '{}'), true);
            if (!is_array($orderData)) {
                $orderData = [];
            }

            $orders[] = [
                'id' => $row['ml_order_id'],
                'status' => $row['status'],
                'total_amount' => (float)$row['total_amount'],
                'date_created' => $row['date_created'],
                'synced_at' => $row['synced_at'],
                'buyer' => $orderData['buyer'] ?? null,
                'order_items' => $orderData['order_items'] ?? [],
                'shipping' => $orderData['shipping'] ?? null,
                'payments' => $orderData['payments'] ?? [],
                'account_nickname' => $this->getAccountNickname((int)$row['ml_account_id']),
            ];
        }

        $pages = $total > 0 ? (int)ceil($total / $limit) : 1;

        return [
            'success' => true,
            'source' => 'local',
            'fallback_from' => 'ml_api',
            'warning' => $context['warning'] ?? null,
            'results' => $orders,
            'orders' => $orders,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    private function isUnknownColumnError(Throwable $e, string $column): bool
    {
        $message = strtolower($e->getMessage());
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'unknown column') && str_contains($message, strtolower($column));
    }

    private function unwrapMlResponse(array $response): array
    {
        if (isset($response['error'])) {
            return $response;
        }

        if (isset($response['body']) && is_array($response['body'])) {
            return $response['body'];
        }

        return $response;
    }

    private function isOrdersCapabilityUnavailable(array $response): bool
    {
        return ($response['error'] ?? null) === 'orders_access_unavailable'
            && ($response['feature'] ?? null) === 'orders'
            && ($response['optional_feature'] ?? false) === true;
    }

    private function shouldAllowLocalFallback(array $filters): bool
    {
        if (!empty($filters['allow_local_cache']) && filter_var($filters['allow_local_cache'], FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        if (!empty($filters['source']) && $filters['source'] === 'local') {
            return true;
        }

        $envAllow = $_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK'] ?? getenv('ML_ALLOW_LOCAL_CACHE_FALLBACK') ?? null;
        if (!filter_var($envAllow, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $appEnv = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production'));
        if (in_array($appEnv, ['production', 'prod', 'staging'], true)) {
            $prodAllow = $_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK_PRODUCTION']
                ?? getenv('ML_ALLOW_LOCAL_CACHE_FALLBACK_PRODUCTION')
                ?? null;

            return filter_var($prodAllow, FILTER_VALIDATE_BOOLEAN);
        }

        return true;
    }

    private function formatMlApiErrorMessage(array $error, string $prefix): string
    {
        $message = $prefix;

        $detail = $error['message'] ?? ($error['error'] ?? null);
        if (is_string($detail) && $detail !== '') {
            $message .= ': ' . $detail;
        }

        $status = $error['status'] ?? null;
        if (is_int($status) && $status > 0) {
            $message .= ' (HTTP ' . $status . ')';
        }

        $endpoint = $error['endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            $message .= ' [' . $endpoint . ']';
        }

        return $message;
    }

    private function emptyOrdersPayload(int $limit, int $page, int $offset, array $extra = []): array
    {
        return array_merge([
            'success' => false,
            'results' => [],
            'orders' => [],
            'page' => $page,
            'pages' => 1,
            'limit' => $limit,
            'total' => 0,
            'has_more' => false,
            'offset' => $offset,
        ], $extra);
    }

    private function normalizeDateForMl(string $date, bool $endOfDay = false): string
    {
        if (preg_match('/T\d{2}:\d{2}:\d{2}/', $date) === 1) {
            return $date;
        }

        $suffix = $endOfDay ? '23:59:59.000-03:00' : '00:00:00.000-03:00';
        return rtrim($date) . 'T' . $suffix;
    }

    private function normalizeDateForLocalFilter(string $date, bool $endOfDay): string
    {
        if (preg_match('/\d{2}:\d{2}:\d{2}/', $date) === 1) {
            return $date;
        }

        return $date . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }

    private function normalizeOrderSummary(array $orderData): array
    {
        return [
            'id' => $orderData['id'] ?? null,
            'status' => $orderData['status'] ?? null,
            'total_amount' => isset($orderData['total_amount']) ? (float)$orderData['total_amount'] : 0.0,
            'date_created' => $orderData['date_created'] ?? null,
            'buyer' => $orderData['buyer'] ?? null,
            'order_items' => $orderData['order_items'] ?? [],
            'shipping' => $orderData['shipping'] ?? null,
            'payments' => $orderData['payments'] ?? [],
            'account_nickname' => $this->accountId ? $this->getAccountNickname($this->accountId) : null,
        ];
    }

    private function buildOrderSyncCheckpointId(?string $latestOrderId, int $nextOffset, bool $hasMore): ?string
    {
        if ($latestOrderId === null || $latestOrderId === '') {
            return $hasMore ? ('offset:' . $nextOffset) : null;
        }

        $value = sprintf('order:%s|offset:%d|has_more:%d', $latestOrderId, $nextOffset, $hasMore ? 1 : 0);
        return mb_substr($value, 0, 100);
    }
}
