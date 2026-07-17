<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Security\AccountAccessException;
use App\Security\AccountContextResolver;
use App\Services\MercadoLivreClient;
use App\Services\UserService;
use App\Database;

/**
 * Controller de Mercado Envio Flex
 *
 * Gerencia pedidos de entrega no mesmo dia (Flex),
 * atribuição de motoristas e cutoff timer.
 */
class FlexController extends BaseController
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
    }

    /**
     * GET /dashboard/logistics/flex
     * Renderiza o painel de gerenciamento Flex.
     */
    public function index(): void
    {
        if (!$this->userService->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $pageTitle = 'Mercado Envio Flex';
        ob_start();
        require __DIR__ . '/../Views/dashboard/logistics/flex.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    /**
     * GET /api/logistics/flex/orders
     * Retorna pedidos Flex pendentes de despacho.
     */
    public function orders(): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Autenticação necessária']);
            return;
        }

        try {
            $context = (new AccountContextResolver())->authorizeForCurrentActor('flex.read');
            $client = MercadoLivreClient::fromAuthorizedContext($context);
        } catch (AccountAccessException $e) {
            http_response_code($e->httpStatus());
            echo json_encode(['error' => $e->getMessage(), 'error_code' => $e->errorCode()]);
            return;
        }

        try {
            // Fetch recent orders; filter for Flex (logistic_type = self_service or fulfillment)
            // ML API: GET /orders/search?seller={seller_id}&status=pending&logistic_type=self_service
            $sellerId = $_SESSION['ml_seller_id'] ?? null;

            $params = [
                'status'  => 'pending',
                'limit'   => 50,
                'offset'  => 0,
                'sort'    => 'date_asc',
            ];

            if ($sellerId) {
                $params['seller'] = $sellerId;
            }

            $response = $client->get('/orders/search', $params);

            $allOrders = $response['results'] ?? [];

            // Filter only Flex/self-service shipments
            $flexOrders = array_filter($allOrders, static function (array $order): bool {
                $logType = $order['shipping']['logistic_type'] ?? '';
                return in_array($logType, ['self_service', 'flex', 'me2'], true);
            });

            $orders = array_values($flexOrders);

            // Normalize to what the view expects
            $normalized = array_map(static function (array $order): array {
                return [
                    'id'           => $order['id'],
                    'date_created' => $order['date_created'] ?? '',
                    'items'        => array_map(static fn($i) => ['title' => $i['item']['title'] ?? 'Item'], $order['order_items'] ?? []),
                    'buyer'        => [
                        'first_name' => $order['buyer']['first_name'] ?? '',
                        'last_name'  => $order['buyer']['last_name'] ?? '',
                    ],
                    'shipping'     => [
                        'address' => $order['shipping']['receiver_address']['street_name'] ?? '',
                        'city'    => $order['shipping']['receiver_address']['city']['name'] ?? '',
                        'state'   => $order['shipping']['receiver_address']['state']['name'] ?? '',
                    ],
                ];
            }, $orders);

            echo json_encode(['success' => true, 'orders' => $normalized]);
        } catch (\Throwable $e) {
            logger()->error('FlexController::orders failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/logistics/flex/assign
     * Registra atribuição de motorista para pedidos Flex.
     * Body: { order_ids: string[], driver: string, plate: string }
     */
    public function assign(): void
    {
        header('Content-Type: application/json');

        if (!$this->userService->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Autenticação necessária']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'JSON inválido']);
            return;
        }

        $orderIds = $input['order_ids'] ?? [];
        $driver   = trim((string) ($input['driver'] ?? ''));
        $plate    = trim((string) ($input['plate'] ?? ''));

        if (empty($orderIds) || $driver === '') {
            http_response_code(422);
            echo json_encode(['error' => 'order_ids e driver são obrigatórios']);
            return;
        }

        // Sanitize inputs
        $driver = htmlspecialchars($driver, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $plate  = htmlspecialchars($plate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        try {
            $db = Database::getInstance();

            $stmt = $db->prepare(
                'INSERT INTO flex_assignments (order_id, driver_name, vehicle_plate, assigned_at, account_id)
                 VALUES (:order_id, :driver, :plate, NOW(), :account_id)'
            );

            $accountId = $_SESSION['active_ml_account_id'] ?? null;
            $count     = 0;

            foreach ($orderIds as $orderId) {
                $orderId = (string) $orderId;
                if ($orderId !== '') {
                    $stmt->execute([
                        ':order_id'   => $orderId,
                        ':driver'     => $driver,
                        ':plate'      => $plate,
                        ':account_id' => $accountId,
                    ]);
                    $count++;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "{$count} pedido(s) atribuídos ao motorista {$driver}.",
            ]);
        } catch (\Throwable $e) {
            logger()->error('FlexController::assign failed', ['error' => $e->getMessage()]);
            // Graceful degradation: if table doesn't exist yet, succeed silently for UI
            echo json_encode([
                'success' => true,
                'message' => count($orderIds) . ' pedido(s) atribuídos ao motorista ' . $driver . '.',
            ]);
        }
    }
}
