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
        // Correlação com o request_id de recebimento (controller), quando disponível.
        $requestId = isset($payload['request_id']) ? (string)$payload['request_id'] : null;

        // Basic validation
        if (!$topic || !$resource) {
            $this->logger->warning("Webhook received without topic or resource", ['payload' => $payload, 'request_id' => $requestId]);
            return ['success' => false, 'error' => 'Missing topic or resource'];
        }

        $eventId = uniqid('evt_');
        $this->logger->info("Processing Webhook Event [{$eventId}]", [
            'topic' => $topic,
            'resource' => $resource,
            'account_id' => $this->accountId,
            'request_id' => $requestId,
        ]);

        try {
            $handled = true;
            switch ($topic) {
                case 'orders_v2':
                case 'orders':
                    $this->handleOrderEvent($resource);
                    break;

                case 'items':
                case 'items_prices':
                    // items_prices chega como /items/{id}/prices (não só /items/{id}).
                    // Ignorá-lo deixava preço local desatualizado até o próximo "items"/sync.
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

            return ['success' => true, 'event_id' => $eventId, 'request_id' => $requestId];
        } catch (\Throwable $e) {
            $this->logger->error("Error processing webhook [{$eventId}]", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId,
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'request_id' => $requestId];
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

        // Status local antes do sync — ML reenvia o mesmo pedido com _id diferente;
        // notificar em todo webhook gerava dezenas de "Nova Venda" iguais.
        $previousStatus = $this->getLocalOrderStatus($orderId);

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

        if ($status === 'paid' && $previousStatus !== 'paid') {
            $total = $order['total_amount'] ?? ($order['data']['total_amount'] ?? '---');
            $this->getNotificationService()->create(
                $userId,
                'order_new',
                "Nova Venda #{$orderId}",
                "Valor: {$total}",
                ['order_id' => $orderId]
            );
            $this->emitPregaoSale($orderId, $order);
        } elseif ($status === 'cancelled' && $previousStatus !== 'cancelled') {
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
        // Formats: /items/{id} | /items/{id}/prices (topic items_prices)
        $itemId = $this->extractItemIdFromResource($resource);

        if ($itemId === null || $itemId === '') {
            throw new \Exception("Invalid item resource: {$resource}");
        }

        // 1. Force Sync Item to Local DB
        $syncResult = $this->getItemService()->syncItem($itemId);
        if (isset($syncResult['error'])) {
            throw new \RuntimeException(
                'Falha ao sincronizar item do webhook: '
                . (string)($syncResult['message'] ?? $syncResult['error'] ?? 'API Error')
            );
        }

        // 2. Refresh Tech Sheet Analysis
        try {
            $this->getTechSheetService()->getItem($itemId);
            $this->logger->info("TechSheet refreshed for item {$itemId}");
        } catch (\Exception $e) {
            $this->logger->warning("Failed to refresh TechSheet for {$itemId}", ['error' => $e->getMessage()]);
        }

    }

    /**
     * Extrai o item_id de resources ML:
     * - /items/MLB123
     * - /items/MLB123/prices (items_prices)
     */
    private function extractItemIdFromResource(string $resource): ?string
    {
        $resource = trim($resource);
        if ($resource === '') {
            return null;
        }

        // Preferir ID canônico MLB… quando presente
        if (preg_match('#/(MLB[A-Z0-9]+|MLA[A-Z0-9]+|MLM[A-Z0-9]+)(?:/|$)#i', $resource, $matches)) {
            return strtoupper($matches[1]);
        }

        // Fallback: segmento imediatamente após /items/
        if (preg_match('#/items/([^/?#]+)#i', $resource, $matches)) {
            $candidate = trim($matches[1]);
            if ($candidate !== '' && strcasecmp($candidate, 'prices') !== 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function handleQuestionEvent(string $resource): void
    {
        // Resource format: /questions/{id}
        $parts = explode('/', $resource);
        $questionId = end($parts);

        if (!$questionId) {
            throw new \Exception("Invalid question resource: {$resource}");
        }

        // ML reenvia a mesma pergunta com _id diferente → sem dedupe spamava question_new.
        $alreadyKnown = $this->localQuestionExists($questionId);

        $qService = $this->getQuestionService();
        $question = $qService->syncSingleQuestion($questionId);
        if (!empty($question['error'])) {
            throw new \RuntimeException((string)($question['message'] ?? 'Falha ao sincronizar pergunta do webhook'));
        }

        // Notify User (apenas na primeira sincronização local)
        $userId = $this->getUserIdFromAccount();
        if ($userId && !$alreadyKnown) {
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
            throw new \InvalidArgumentException("Invalid message resource: {$resource}");
        }

        $messagingService = $this->getMessagingService();
        $message = $messagingService->getMessage($id);
        if (!empty($message['error'])) {
            $error = (string)$message['error'];
            $this->logger->warning('Failed to fetch message', ['message_id' => $id, 'error' => $error]);
            // Propagar falha para o job/inbox reprocessarem (não marcar como success).
            throw new \RuntimeException(
                (string)($message['message'] ?? "Falha ao carregar mensagem do webhook: {$error}")
            );
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

        // Status local antes do sync — ML reenvia o mesmo shipment com _id diferente;
        // notificar em todo webhook gerava dezenas/centenas de alertas iguais.
        $previousStatus = $this->getLocalShipmentStatus($shipmentId);

        // Fetch from ML API and persist to shipments table
        $syncResult = $this->getShipmentSyncService()->syncShipment($shipmentId);

        if (!($syncResult['success'] ?? false)) {
            $error = (string)($syncResult['error'] ?? 'unknown');
            $this->logger->warning('ML_WEBHOOK_SHIPMENT_SYNC_FAILED', [
                'shipment_id' => $shipmentId,
                'error'       => $error,
                'account_id'  => $this->accountId,
            ]);
            // Propagar falha para o job/inbox reprocessarem (não marcar como success).
            throw new \RuntimeException("Falha ao sincronizar envio do webhook: {$error}");
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
            'previous_status' => $previousStatus,
            'account_id'     => $this->accountId,
        ]);

        // Notify user only on significant status transitions (não em reentregas ML)
        $notifyStatuses = ['ready_to_ship', 'shipped', 'delivered', 'not_delivered', 'cancelled'];
        if ($previousStatus === $status) {
            return;
        }
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
            throw new \InvalidArgumentException("Invalid payment resource: {$resource}");
        }

        $previousStatus = $this->getLocalPaymentStatus($paymentId);

        // Fetch payment details from ML (endpoint canônico usado no restante do sistema).
        $paymentData = [];
        $lastError = null;
        try {
            $response = $this->getMlClient()->get("/collections/{$paymentId}");
            if (!isset($response['error'])) {
                $paymentData = $response['collection'] ?? $response;
            } else {
                $lastError = (string)($response['message'] ?? $response['error']);
                // Fallback Mercado Pago Payments API
                $fallback = $this->getMlClient()->get("/v1/payments/{$paymentId}");
                if (!isset($fallback['error'])) {
                    $paymentData = $fallback;
                    $lastError = null;
                } else {
                    $lastError = (string)($fallback['message'] ?? $fallback['error'] ?? $lastError);
                }
            }
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
            $this->logger->warning('ML_WEBHOOK_PAYMENT_FETCH_FAILED', [
                'payment_id' => $paymentId,
                'error' => $lastError,
            ]);
        }

        if (empty($paymentData)) {
            throw new \RuntimeException(
                'Falha ao carregar pagamento do webhook'
                . ($lastError ? ": {$lastError}" : '')
            );
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

        // Notify user on approved payment (só na transição para approved)
        if ($status === 'approved' && $previousStatus !== 'approved') {
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

        if (!$feedbackId) {
            throw new \InvalidArgumentException("Invalid feedback resource: {$resource}");
        }

        // Fetch feedback data from ML API
        $feedbackData = [];
        $lastError = null;
        try {
            $response = $this->getMlClient()->get("/feedback/{$feedbackId}");
            if (!isset($response['error'])) {
                $feedbackData = $response;
            } else {
                $lastError = (string)($response['message'] ?? $response['error']);
            }
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();
            $this->logger->warning('ML_WEBHOOK_FEEDBACK_FETCH_FAILED', [
                'feedback_id' => $feedbackId,
                'error'       => $lastError,
            ]);
        }

        if (empty($feedbackData)) {
            throw new \RuntimeException(
                'Falha ao carregar feedback do webhook'
                . ($lastError ? ": {$lastError}" : '')
            );
        }

        $alreadyKnown = $this->localFeedbackExists($feedbackId);

        // Persist to ml_feedback table
        $this->persistFeedback($feedbackId, $feedbackData);

        // Notify user — só na primeira vez (reentregas ML)
        if ($alreadyKnown) {
            return;
        }

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

        $alreadyKnown = $this->localClaimExists($claimId);

        $this->getClaimsService()->syncClaim($claimId);

        // Notify Urgent — ML reenvia o mesmo claim com _id diferente
        if ($alreadyKnown) {
            $this->logger->info('Claim Synced', ['claim_id' => $claimId, 'notify' => false]);
            return;
        }

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

    /**
     * Status local do envio antes do sync (para deduplicar notificações de reentrega ML).
     */
    private function getLocalShipmentStatus(string $shipmentId): ?string
    {
        try {
            $db = $this->db;
            if ($db === null && !$this->skipDbAutoConnect) {
                $db = \App\Database::getInstance();
            }
            if ($db === null) {
                return null;
            }

            $stmt = $db->prepare(
                'SELECT status FROM shipments WHERE account_id = ? AND shipment_id = ? LIMIT 1'
            );
            $stmt->execute([$this->accountId, $shipmentId]);
            $status = $stmt->fetchColumn();
            if ($status === false || $status === null) {
                return null;
            }

            $normalized = trim((string)$status);
            return $normalized !== '' ? $normalized : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Status local do pedido antes do sync (dedupe de notificações order_new/cancelled).
     */
    private function getLocalOrderStatus(string $orderId): ?string
    {
        try {
            $db = $this->db;
            if ($db === null && !$this->skipDbAutoConnect) {
                $db = \App\Database::getInstance();
            }
            if ($db === null) {
                return null;
            }

            $stmt = $db->prepare(
                'SELECT status FROM ml_orders WHERE ml_order_id = ? AND ml_account_id = ? LIMIT 1'
            );
            $stmt->execute([$orderId, $this->accountId]);
            $status = $stmt->fetchColumn();
            if ($status === false || $status === null) {
                return null;
            }

            $normalized = trim((string)$status);
            return $normalized !== '' ? $normalized : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function localQuestionExists(string $questionId): bool
    {
        try {
            $db = $this->db;
            if ($db === null && !$this->skipDbAutoConnect) {
                $db = \App\Database::getInstance();
            }
            if ($db === null) {
                return false;
            }

            $stmt = $db->prepare(
                'SELECT 1 FROM ml_questions WHERE question_id = ? AND account_id = ? LIMIT 1'
            );
            $stmt->execute([$questionId, $this->accountId]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null) {
                return false;
            }

            // SELECT 1 → 1/"1"; evita falso positivo de mocks/lookup que devolvem user_id
            return $value === 1 || $value === '1' || $value === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getLocalPaymentStatus(string $paymentId): ?string
    {
        try {
            $db = $this->db;
            if ($db === null && !$this->skipDbAutoConnect) {
                $db = \App\Database::getInstance();
            }
            if ($db === null) {
                return null;
            }

            $stmt = $db->prepare(
                'SELECT status FROM ml_payments WHERE payment_id = ? AND ml_account_id = ? LIMIT 1'
            );
            $stmt->execute([$paymentId, $this->accountId]);
            $status = $stmt->fetchColumn();
            if ($status === false || $status === null) {
                return null;
            }

            $normalized = trim((string)$status);
            return $normalized !== '' ? $normalized : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function localClaimExists(string $claimId): bool
    {
        try {
            $db = $this->db;
            if ($db === null && !$this->skipDbAutoConnect) {
                $db = \App\Database::getInstance();
            }
            if ($db === null) {
                return false;
            }

            $stmt = $db->prepare(
                'SELECT 1 FROM ml_claims WHERE id = ? AND account_id = ? LIMIT 1'
            );
            $stmt->execute([$claimId, $this->accountId]);
            $value = $stmt->fetchColumn();
            return $value === 1 || $value === '1' || $value === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function localFeedbackExists(string $feedbackId): bool
    {
        try {
            $db = $this->db;
            if ($db === null && !$this->skipDbAutoConnect) {
                $db = \App\Database::getInstance();
            }
            if ($db === null) {
                return false;
            }

            $stmt = $db->prepare(
                'SELECT 1 FROM ml_feedback WHERE feedback_id = ? AND ml_account_id = ? LIMIT 1'
            );
            $stmt->execute([$feedbackId, $this->accountId]);
            $value = $stmt->fetchColumn();
            return $value === 1 || $value === '1' || $value === true;
        } catch (\Throwable $e) {
            return false;
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
