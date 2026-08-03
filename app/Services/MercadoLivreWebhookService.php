<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\ClaimsService;
use App\Services\ItemService;
use App\Services\MessagingService;
use App\Services\MercadoLivreClient;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\QuestionService;
use App\Services\ShipmentSyncService;
use App\Services\StructuredLogService;
use App\Services\TechSheetService;
use PDO;

/**
 * Service to process Mercado Livre Webhooks
 * Handles routing of events to specific domain services.
 */
class MercadoLivreWebhookService
{
    private int $accountId;
    private StructuredLogService $logger;
    private ?OrderService $orderService = null;
    private ?ItemService $itemService = null;
    private ?QuestionService $questionService = null;
    private ?NotificationService $notificationService = null;
    private ?ClaimsService $claimsService = null;
    private ?MessagingService $messagingService = null;
    private ?TechSheetService $techSheetService = null;
    private ?MercadoLivreClient $mlClient = null;
    private ?ShipmentSyncService $shipmentSyncService = null;
    private ?PDO $db = null;
    private bool $skipDbAutoConnect;

    /**
     * @param int $accountId ID da conta ML
     * @param StructuredLogService|null $logger Logger (injetável para testes)
     * @param OrderService|null $orderService (injetável para testes)
     * @param ItemService|null $itemService (injetável para testes)
     * @param QuestionService|null $questionService (injetável para testes)
     * @param NotificationService|null $notificationService (injetável para testes)
     * @param ClaimsService|null $claimsService (injetável para testes)
     * @param MessagingService|null $messagingService (injetável para testes)
     * @param TechSheetService|null $techSheetService (injetável para testes)
     * @param MercadoLivreClient|null $mlClient (injetável para testes)
     * @param PDO|null $db Conexão ao banco (injetável para testes)
     * @param bool $skipDbAutoConnect Se true, não conecta ao DB automaticamente
     * @param ShipmentSyncService|null $shipmentSyncService (injetável para testes)
     */
    public function __construct(
        int $accountId,
        ?StructuredLogService $logger = null,
        ?OrderService $orderService = null,
        ?ItemService $itemService = null,
        ?QuestionService $questionService = null,
        ?NotificationService $notificationService = null,
        ?ClaimsService $claimsService = null,
        ?MessagingService $messagingService = null,
        ?TechSheetService $techSheetService = null,
        ?MercadoLivreClient $mlClient = null,
        ?PDO $db = null,
        bool $skipDbAutoConnect = false,
        ?ShipmentSyncService $shipmentSyncService = null
    ) {
        $this->accountId = $accountId;
        $this->logger = $logger ?? new StructuredLogService();
        $this->orderService = $orderService;
        $this->itemService = $itemService;
        $this->questionService = $questionService;
        $this->notificationService = $notificationService;
        $this->claimsService = $claimsService;
        $this->messagingService = $messagingService;
        $this->techSheetService = $techSheetService;
        $this->mlClient = $mlClient;
        $this->db = $db;
        $this->skipDbAutoConnect = $skipDbAutoConnect;
        $this->shipmentSyncService = $shipmentSyncService;
    }

    /**
     * Process a single webhook event
     *
     * @param array $payload Webhook payload from ML
     * @return array Result ['success' => bool, 'error' => ?string, 'event_id' => ?string]
     */
    public function processWebhookEvent(array $payload): array
    {
        $topic = $payload['topic'] ?? null;
        $resource = $payload['resource'] ?? null;
        $userId = $payload['user_id'] ?? null;
        $applicationId = $payload['application_id'] ?? null;

        // Basic validation
        if (!$topic || !$resource) {
            $this->logger->warning("Webhook received without topic or resource", ['payload' => $payload]);
            return ['success' => false, 'error' => 'Missing topic or resource'];
        }

        $eventId = uniqid('evt_');
        $this->logger->info("Processing Webhook Event [{$eventId}]", [
            'topic' => $topic,
            'resource' => $resource,
            'account_id' => $this->accountId
        ]);

        try {
            $handled = true;
            switch ($topic) {
                case 'orders_v2':
                case 'orders':
                    $this->handleOrderEvent($resource);
                    break;

                case 'items':
                    $this->handleItemEvent($resource);
                    break;

                case 'questions':
                    $this->handleQuestionEvent($resource);
                    break;

                case 'claims':
                    $this->handleClaimEvent($resource);
                    break;

                case 'messages':
                    $this->handleMessageEvent($resource);
                    break;

                case 'shipments':
                    $this->handleShipmentEvent($resource);
                    break;

                case 'payment':
                case 'payments':
                    $this->handlePaymentEvent($resource);
                    break;

                case 'feedback':
                case 'created_in_feedback':
                    $this->handleFeedbackEvent($resource);
                    break;

                default:
                    $handled = false;
                    break;
            }

            if (!$handled) {
                $strictMode = $this->isStrictUnknownTopicModeEnabled();
                $this->logger->warning('Webhook topic desconhecido', [
                    'topic' => $topic,
                    'resource' => $resource,
                    'account_id' => $this->accountId,
                    'strict_mode' => $strictMode,
                ]);

                if ($strictMode) {
                    return [
                        'success' => false,
                        'error' => 'Unknown webhook topic: ' . (string)$topic,
                        'event_id' => $eventId,
                        'ignored' => false,
                        'strict_mode' => true,
                    ];
                }

                return [
                    'success' => true,
                    'event_id' => $eventId,
                    'ignored' => true,
                    'ignored_reason' => 'unknown_topic',
                    'topic' => (string)$topic,
                ];
            }

            // Sentinela: observação read-only em tópicos com risco (nunca escreve no ML)
            if (in_array((string) $topic, ['items', 'claims', 'orders_v2', 'orders'], true)
                && is_int($this->accountId) && $this->accountId > 0) {
                try {
                    (new \App\Services\Sentinela\Sentinela())->onWebhook($this->accountId, (string) $topic);
                } catch (\Throwable $sentinelaErr) {
                    $this->logger->warning('Sentinela onWebhook falhou (não-bloqueante)', [
                        'account_id' => $this->accountId,
                        'topic' => $topic,
                        'error' => $sentinelaErr->getMessage(),
                    ]);
                }
            }

            return ['success' => true, 'event_id' => $eventId];
        } catch (\Throwable $e) {
            $this->logger->error("Error processing webhook [{$eventId}]", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function isStrictUnknownTopicModeEnabled(): bool
    {
        $value = $_ENV['ML_WEBHOOK_STRICT_TOPICS'] ?? getenv('ML_WEBHOOK_STRICT_TOPICS') ?? '0';
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * Processa evento de pedido do webhook.
     *
     * Persiste o pedido no banco e notifica o usuário apenas para status
     * significativos (paid → Nova Venda; cancelled → Pedido Cancelado).
     * Aplica-se a ambos os tópicos 'orders_v2' (atual) e 'orders' (legado).
     */
    private function handleOrderEvent(string $resource): void
    {
        // Resource format: /orders/{id}
        $parts = explode('/', $resource);
        $orderId = end($parts);

        if (!$orderId) {
            throw new \Exception("Invalid order resource: {$resource}");
        }

        $order = $this->getOrderService()->getOrder($orderId, ['allow_local_cache' => false]);
        if (!empty($order['error'])) {
            throw new \RuntimeException((string)($order['message'] ?? 'Falha ao carregar pedido do webhook'));
        }

        // Notificar apenas para status significativos; topic legado ('orders') ou
        // canônico ('orders_v2') ambos elegíveis — a distinção ocorre pelo status.
        $status = (string)($order['status'] ?? $order['data']['status'] ?? '');
        $userId = $this->getUserIdFromAccount();

        if (!$userId) {
            return;
        }

        if ($status === 'paid') {
            $total = $order['total_amount'] ?? ($order['data']['total_amount'] ?? '---');
            $this->getNotificationService()->create(
                $userId,
                'order_new',
                "Nova Venda #{$orderId}",
                "Valor: {$total}",
                ['order_id' => $orderId]
            );
            $this->emitPregaoSale($orderId, $order);
        } elseif ($status === 'cancelled') {
            $this->getNotificationService()->create(
                $userId,
                'order_cancelled',
                "Pedido #{$orderId} Cancelado",
                'O pedido foi cancelado no Mercado Livre.',
                ['order_id' => $orderId]
            );
        }
    }

    /**
     * Emite eventos do Pregão a partir de venda paga (somente leitura do pedido — sem escrita no ML).
     *
     * @param array<string, mixed> $order
     */
    private function emitPregaoSale(string $orderId, array $order): void
    {
        try {
            $data = isset($order['data']) && is_array($order['data']) ? $order['data'] : $order;
            $valor = (float) ($data['total_amount'] ?? $order['total_amount'] ?? 0);
            $titulo = '';
            $sku = null;
            $items = $data['order_items'] ?? $order['order_items'] ?? [];
            if (is_array($items) && isset($items[0]) && is_array($items[0])) {
                $item = $items[0]['item'] ?? $items[0];
                $titulo = (string) ($item['title'] ?? '');
                $sku = isset($item['id']) ? (string) $item['id'] : null;
            }

            if (!function_exists('pregao_emit_sale')) {
                require_once dirname(__DIR__) . '/Helpers/PregaoHelper.php';
            }

            pregao_emit_sale([
                'order_id' => $orderId,
                'valor' => $valor,
                'titulo' => $titulo !== '' ? $titulo : "Pedido #{$orderId}",
                'sku' => $sku,
            ], $this->accountId);
        } catch (\Throwable $e) {
            $this->logger->warning('Pregao emitSale falhou (não bloqueia webhook)', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleItemEvent(string $resource): void
    {
        // Resource format: /items/{id}
        $parts = explode('/', $resource);
        $itemId = end($parts);

        if (!$itemId) {
            throw new \Exception("Invalid item resource: {$resource}");
        }

        // 1. Force Sync Item to Local DB
        $this->getItemService()->syncItem($itemId);

        // 2. Refresh Tech Sheet Analysis
        try {
            $this->getTechSheetService()->getItem($itemId);
            $this->logger->info("TechSheet refreshed for item {$itemId}");
        } catch (\Exception $e) {
            $this->logger->warning("Failed to refresh TechSheet for {$itemId}", ['error' => $e->getMessage()]);
        }

    }

    private function handleQuestionEvent(string $resource): void
    {
        // Resource format: /questions/{id}
        $parts = explode('/', $resource);
        $questionId = end($parts);

        if (!$questionId) {
            throw new \Exception("Invalid question resource: {$resource}");
        }

        $qService = $this->getQuestionService();
        $question = $qService->syncSingleQuestion($questionId);
        if (!empty($question['error'])) {
            throw new \RuntimeException((string)($question['message'] ?? 'Falha ao sincronizar pergunta do webhook'));
        }

        // Notify User
        $userId = $this->getUserIdFromAccount();
        if ($userId) {
            $itemTitle = $question['item_title'] ?? ($question['item']['title'] ?? 'Anúncio');
            $text = $question['text'] ?? 'Nova pergunta';
            $this->getNotificationService()->create(
                $userId,
                'question_new',
                "Nova Pergunta",
                "Item: {$itemTitle}\nPgta: {$text}",
                ['question_id' => $questionId, 'item_id' => $question['item_id'] ?? null]
            );
        }

        $text = $question['text'] ?? '';
        $itemId = $question['item_id'] ?? '';
        $status = $question['status'] ?? '';

        // Passar para NLP se for pergunta não respondida
        if ($status === 'UNANSWERED' && !empty($text)) {
            $nlpService = new \App\Services\AI\ML\NLPIntegrationService($this->logger);

            // Buscar preço do item para passar ao modelo
            $itemDetails = $this->getItemService()->getItem($itemId);
            $price = isset($itemDetails['price']) ? (float)$itemDetails['price'] : 0.0;

            $prediction = $nlpService->predictIntent($questionId, $text, $itemId, $price);

            if ($prediction && $prediction['is_critical']) {
                $this->logger->warning("NLP Detectou Pergunta Crítica", [
                    'question_id' => $questionId,
                    'intent' => $prediction['intent'],
                    'urgency' => $prediction['urgency_score']
                ]);

                // Dispara alerta imediato se for crítico
                $userId = $this->getUserIdFromAccount();
                if ($userId) {
                    $this->getNotificationService()->create(
                        $userId,
                        'critical_question',
                        "⚠️ Pergunta Crítica Detectada",
                        "Intenção: {$prediction['intent']} | Urgência: {$prediction['urgency_score']}",
                        ['question_id' => $questionId, 'item_id' => $itemId]
                    );
                }
            }
        }

        // Attempt auto-reply or draft generation
        try {
            $qService->generateDraftAnswer($questionId);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to generate draft for question {$questionId}", ['error' => $e->getMessage()]);
        }
    }

    private function handleMessageEvent(string $resource): void
    {
        $this->logger->info("New Message event", ['resource' => $resource]);

        $parts = explode('/', $resource);
        $id = end($parts);

        if (!$id) {
            return;
        }

        $messagingService = $this->getMessagingService();
        $message = $messagingService->getMessage($id);
        if (!empty($message['error'])) {
            $this->logger->warning('Failed to fetch message', ['message_id' => $id, 'error' => $message['error']]);
            return;
        }

        $messagingService->processIncomingMessage([
            'text' => $message['text'] ?? '',
            'from' => ['user_id' => $message['from'] ?? null],
            'to' => ['user_id' => $message['to'] ?? null],
        ]);
    }

    private function handleShipmentEvent(string $resource): void
    {
        // Resource: /shipments/{shipmentId}
        $parts = explode('/', $resource);
        $shipmentId = end($parts);

        if (!$shipmentId) {
            throw new \Exception("Invalid shipment resource: {$resource}");
        }

        // Fetch from ML API and persist to shipments table
        $syncResult = $this->getShipmentSyncService()->syncShipment($shipmentId);

        if (!($syncResult['success'] ?? false)) {
            $this->logger->warning('ML_WEBHOOK_SHIPMENT_SYNC_FAILED', [
                'shipment_id' => $shipmentId,
                'error'       => $syncResult['error'] ?? 'unknown',
                'account_id'  => $this->accountId,
            ]);
            return;
        }

        $shipmentData    = $syncResult['data'] ?? [];
        $status          = $shipmentData['status'] ?? 'unknown';
        $substatus       = $shipmentData['substatus'] ?? null;
        $trackingNumber  = $shipmentData['tracking_number'] ?? null;

        $this->logger->info('ML_WEBHOOK_SHIPMENT_SYNCED', [
            'shipment_id'    => $shipmentId,
            'status'         => $status,
            'substatus'      => $substatus,
            'tracking_number' => $trackingNumber,
            'account_id'     => $this->accountId,
        ]);

        // Notify user only on significant status transitions
        $notifyStatuses = ['ready_to_ship', 'shipped', 'delivered', 'not_delivered', 'cancelled'];
        if (in_array($status, $notifyStatuses, true)) {
            $userId = $this->getUserIdFromAccount();
            if ($userId) {
                $statusLabels = [
                    'ready_to_ship' => '📦 Pronto para Envio',
                    'shipped'       => '🚚 Enviado',
                    'delivered'     => '✅ Entregue',
                    'not_delivered' => '⚠️ Não Entregue',
                    'cancelled'     => '❌ Cancelado',
                ];
                $label  = $statusLabels[$status] ?? ucfirst($status);
                $detail = $trackingNumber ? "Rastreio: {$trackingNumber}" : "Envio #{$shipmentId}";
                $this->getNotificationService()->create(
                    $userId,
                    'shipment_' . $status,
                    "Envio {$label}",
                    $detail,
                    ['shipment_id' => $shipmentId, 'status' => $status, 'tracking_number' => $trackingNumber]
                );
            }
        }
    }

    private function handlePaymentEvent(string $resource): void
    {
        // Resource: /collections/{paymentId} or /payments/{paymentId}
        $parts = explode('/', $resource);
        $paymentId = end($parts);

        if (!$paymentId) {
            $this->logger->warning('ML_WEBHOOK_PAYMENT_INVALID_RESOURCE', ['resource' => $resource]);
            return;
        }

        // Fetch payment details from ML
        $paymentData = [];
        try {
            // The ML payments API endpoint
            $response = $this->getMlClient()->get("/collections/notifications/{$paymentId}");
            if (!isset($response['error'])) {
                $paymentData = $response['collection'] ?? $response;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ML_WEBHOOK_PAYMENT_FETCH_FAILED', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
        }

        $status = $paymentData['status'] ?? 'unknown';
        $orderId = $paymentData['order_id'] ?? null;

        $this->logger->info('ML_WEBHOOK_PAYMENT_RECEIVED', [
            'payment_id' => $paymentId,
            'status' => $status,
            'order_id' => $orderId,
            'account_id' => $this->accountId,
        ]);

        // Persist payment data to ml_payments table
        if (!empty($paymentData)) {
            $this->persistPayment($paymentId, $paymentData);
        }

        // For approved payments, trigger order sync to get latest state
        if (in_array($status, ['approved', 'in_process'], true) && $orderId) {
            try {
                $this->getOrderService()->getOrder((string)$orderId);
            } catch (\Throwable $e) {
                $this->logger->warning('ML_WEBHOOK_PAYMENT_ORDER_SYNC_FAILED', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Notify user on approved payment
        if ($status === 'approved') {
            $userId = $this->getUserIdFromAccount();
            if ($userId) {
                $amount = isset($paymentData['transaction_amount'])
                    ? 'R$ ' . number_format((float)$paymentData['transaction_amount'], 2, ',', '.')
                    : '---';
                $this->getNotificationService()->create(
                    $userId,
                    'payment_approved',
                    '💰 Pagamento Aprovado',
                    "Valor: {$amount}" . ($orderId ? " | Pedido #{$orderId}" : ''),
                    ['payment_id' => $paymentId, 'order_id' => $orderId, 'amount' => $paymentData['transaction_amount'] ?? null]
                );
            }
        }
    }

    private function handleFeedbackEvent(string $resource): void
    {
        // Resource: /orders/{orderId}/feedbacks/{id} or /feedback/{id}
        $parts = explode('/', ltrim($resource, '/'));
        $feedbackId = end($parts);

        $this->logger->info('ML_WEBHOOK_FEEDBACK_RECEIVED', [
            'resource' => $resource,
            'feedback_id' => $feedbackId,
            'account_id' => $this->accountId,
        ]);

        // Fetch feedback data from ML API
        $feedbackData = [];
        try {
            $response = $this->getMlClient()->get("/feedback/{$feedbackId}");
            if (!isset($response['error'])) {
                $feedbackData = $response;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ML_WEBHOOK_FEEDBACK_FETCH_FAILED', [
                'feedback_id' => $feedbackId,
                'error'       => $e->getMessage(),
            ]);
        }

        // Persist to ml_feedback table
        if (!empty($feedbackData)) {
            $this->persistFeedback($feedbackId, $feedbackData);
        }

        // Notify user — feedbacks affect seller reputation
        $userId = $this->getUserIdFromAccount();
        if ($userId) {
            $rating      = $feedbackData['rating'] ?? null;
            $ratingLabel = $rating !== null ? " | Nota: {$rating}" : '';
            $this->getNotificationService()->create(
                $userId,
                'feedback_new',
                '⭐ Nova Avaliação Recebida',
                'Verifique sua reputação no painel.' . $ratingLabel,
                ['resource' => $resource, 'feedback_id' => $feedbackId, 'rating' => $rating]
            );
        }
    }

    private function handleClaimEvent(string $resource): void
    {
        // Resource: /v1/claims/{claimId}
        $parts = explode('/', $resource);
        $claimId = end($parts);

        if (!$claimId) {
            throw new \Exception("Invalid claim resource: {$resource}");
        }

        $this->getClaimsService()->syncClaim($claimId);

        // Notify Urgent
        $userId = $this->getUserIdFromAccount();
        if ($userId) {
            $this->getNotificationService()->create(
                $userId,
                'claim_new',
                "⚠️ Nova Reclamação #{$claimId}",
                "Responda imediatamente para evitar impacto na reputação.",
                ['claim_id' => $claimId, 'priority' => 'critical']
            );

            // External Alert
            $this->getNotificationService()->sendAlert(
                "Nova Reclamação #{$claimId}",
                "Conta {$this->accountId}: Nova reclamação recebida.",
                "CRITICAL"
            );
        }

        $this->logger->info("Claim Synced", ['claim_id' => $claimId]);
    }

    private function getUserIdFromAccount(): ?int
    {
        try {
            $db = $this->db;
            if ($db === null && !$this->skipDbAutoConnect) {
                $db = \App\Database::getInstance();
            }
            if ($db === null) {
                return null;
            }
            $stmt = $db->prepare("SELECT user_id FROM ml_accounts WHERE id = ?");
            $stmt->execute([$this->accountId]);
            $result = $stmt->fetchColumn();
            return $result !== false && $result !== null ? (int)$result : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // Lazy Loaders (respeitam instâncias injetadas via construtor)

    private function getOrderService(): OrderService
    {
        if ($this->orderService === null) {
            $this->orderService = new OrderService($this->accountId);
        }
        return $this->orderService;
    }

    private function getItemService(): ItemService
    {
        if ($this->itemService === null) {
            $this->itemService = new ItemService($this->accountId);
        }
        return $this->itemService;
    }

    private function getQuestionService(): QuestionService
    {
        if ($this->questionService === null) {
            $this->questionService = new QuestionService($this->accountId);
        }
        return $this->questionService;
    }

    private function getNotificationService(): NotificationService
    {
        if ($this->notificationService === null) {
            $this->notificationService = new NotificationService();
        }
        return $this->notificationService;
    }

    private function getClaimsService(): ClaimsService
    {
        if ($this->claimsService === null) {
            $this->claimsService = new ClaimsService($this->accountId);
        }
        return $this->claimsService;
    }

    private function getMessagingService(): MessagingService
    {
        if ($this->messagingService === null) {
            $this->messagingService = new MessagingService($this->accountId);
        }
        return $this->messagingService;
    }

    private function getTechSheetService(): TechSheetService
    {
        if ($this->techSheetService === null) {
            $this->techSheetService = new TechSheetService($this->accountId);
        }
        return $this->techSheetService;
    }

    private function getMlClient(): MercadoLivreClient
    {
        if ($this->mlClient === null) {
            $this->mlClient = new MercadoLivreClient($this->accountId);
        }
        return $this->mlClient;
    }

    private function getShipmentSyncService(): ShipmentSyncService
    {
        if ($this->shipmentSyncService === null) {
            $this->shipmentSyncService = new ShipmentSyncService($this->accountId);
        }
        return $this->shipmentSyncService;
    }

    /**
     * Resolve the PDO connection, auto-connecting if allowed.
     * Mirrors the logic in getUserIdFromAccount() but returns the connection directly.
     */
    private function getDb(): ?PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        if ($this->skipDbAutoConnect) {
            return null;
        }

        try {
            return \App\Database::getInstance();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Persists ML payment data to ml_payments table (INSERT … ON DUPLICATE KEY UPDATE).
     *
     * @param string $paymentId ML payment / collection ID
     * @param array<string, mixed> $data Raw payment payload from ML API
     */
    private function persistPayment(string $paymentId, array $data): void
    {
        $db = $this->getDb();
        if ($db === null) {
            $this->logger->warning('ML_WEBHOOK_PAYMENT_DB_UNAVAILABLE', ['payment_id' => $paymentId]);
            return;
        }

        try {
            $paidAt = isset($data['date_approved'])
                ? date('Y-m-d H:i:s', (int)strtotime((string)$data['date_approved']))
                : null;

            $stmt = $db->prepare(
                'INSERT INTO ml_payments
                    (ml_account_id, payment_id, order_id, status, amount, currency_id, payment_method, data, paid_at)
                 VALUES
                    (:account_id, :payment_id, :order_id, :status, :amount, :currency_id, :payment_method, :data, :paid_at)
                 ON DUPLICATE KEY UPDATE
                    status         = VALUES(status),
                    amount         = VALUES(amount),
                    order_id       = VALUES(order_id),
                    data           = VALUES(data),
                    paid_at        = VALUES(paid_at),
                    updated_at     = CURRENT_TIMESTAMP'
            );

            $stmt->execute([
                'account_id'     => $this->accountId,
                'payment_id'     => $paymentId,
                'order_id'       => $data['order_id'] ?? null,
                'status'         => $data['status'] ?? null,
                'amount'         => isset($data['transaction_amount']) ? (float)$data['transaction_amount'] : null,
                'currency_id'    => $data['currency_id'] ?? null,
                'payment_method' => $data['payment_type_id'] ?? null,
                'data'           => json_encode($data, JSON_UNESCAPED_UNICODE),
                'paid_at'        => $paidAt,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('ML_WEBHOOK_PAYMENT_PERSIST_FAILED', [
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persists ML feedback data to ml_feedback table (INSERT … ON DUPLICATE KEY UPDATE).
     *
     * @param string $feedbackId ML feedback ID
     * @param array<string, mixed> $data Raw feedback payload from ML API
     */
    private function persistFeedback(string $feedbackId, array $data): void
    {
        $db = $this->getDb();
        if ($db === null) {
            $this->logger->warning('ML_WEBHOOK_FEEDBACK_DB_UNAVAILABLE', ['feedback_id' => $feedbackId]);
            return;
        }

        try {
            $feedbackDate = isset($data['date_created'])
                ? date('Y-m-d H:i:s', (int)strtotime((string)$data['date_created']))
                : null;

            $fulfilled = isset($data['fulfilled']) ? ($data['fulfilled'] ? 1 : 0) : null;

            $stmt = $db->prepare(
                'INSERT INTO ml_feedback
                    (ml_account_id, feedback_id, order_id, rating, message, status, fulfilled, data, feedback_date)
                 VALUES
                    (:account_id, :feedback_id, :order_id, :rating, :message, :status, :fulfilled, :data, :feedback_date)
                 ON DUPLICATE KEY UPDATE
                    rating        = VALUES(rating),
                    message       = VALUES(message),
                    status        = VALUES(status),
                    fulfilled     = VALUES(fulfilled),
                    data          = VALUES(data),
                    updated_at    = CURRENT_TIMESTAMP'
            );

            $stmt->execute([
                'account_id'    => $this->accountId,
                'feedback_id'   => $feedbackId,
                'order_id'      => $data['order_id'] ?? null,
                'rating'        => isset($data['rating']) ? (int)$data['rating'] : null,
                'message'       => isset($data['message']) ? (string)$data['message'] : null,
                'status'        => isset($data['status']) ? (string)$data['status'] : null,
                'fulfilled'     => $fulfilled,
                'data'          => json_encode($data, JSON_UNESCAPED_UNICODE),
                'feedback_date' => $feedbackDate,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('ML_WEBHOOK_FEEDBACK_PERSIST_FAILED', [
                'feedback_id' => $feedbackId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
