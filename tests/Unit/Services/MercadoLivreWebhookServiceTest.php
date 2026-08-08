<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MercadoLivreWebhookService;
use App\Services\StructuredLogService;
use App\Services\OrderService;
use App\Services\ItemService;
use App\Services\QuestionService;
use App\Services\NotificationService;
use App\Services\MessagingService;
use App\Services\ShipmentSyncService;
use App\Services\MercadoLivreClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitarios DB-free para MercadoLivreWebhookService
 *
 * @covers \App\Services\MercadoLivreWebhookService
 */
class MercadoLivreWebhookServiceTest extends TestCase
{
    private const ACCOUNT_ID = 42;
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }

        $this->envBackup = [];
        parent::tearDown();
    }

    private function createMockLogger(): MockObject
    {
        $logger = $this->createMock(StructuredLogService::class);
        return $logger;
    }

    private function createMockOrderService(array $getOrderReturn = []): MockObject
    {
        $svc = $this->createMock(OrderService::class);
        $svc->method('getOrder')->willReturn($getOrderReturn);
        return $svc;
    }

    private function createMockItemService(array $syncItemReturn = []): MockObject
    {
        $svc = $this->createMock(ItemService::class);
        $svc->method('syncItem')->willReturn($syncItemReturn);
        return $svc;
    }

    private function createMockQuestionService(array $syncReturn = [], array $draftReturn = []): MockObject
    {
        $svc = $this->createMock(QuestionService::class);
        $svc->method('syncSingleQuestion')->willReturn($syncReturn);
        $svc->method('generateDraftAnswer')->willReturn($draftReturn);
        return $svc;
    }

    private function createMockNotificationService(): MockObject
    {
        $svc = $this->createMock(NotificationService::class);
        $svc->method('create')->willReturn(1);
        $svc->method('sendAlert')->willReturn(['success' => true]);
        return $svc;
    }

    private function createMockDb(?int $userId = 10): MockObject
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($userId !== null ? $userId : false);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);
        return $db;
    }

    private function buildService(
        ?MockObject $logger = null,
        ?MockObject $orderService = null,
        ?MockObject $itemService = null,
        ?MockObject $questionService = null,
        ?MockObject $notificationService = null,
        ?MockObject $db = null,
        ?MockObject $messagingService = null,
        ?MockObject $shipmentSyncService = null,
        ?MockObject $mlClient = null
    ): MercadoLivreWebhookService {
        return new MercadoLivreWebhookService(
            self::ACCOUNT_ID,
            $logger ?? $this->createMockLogger(),
            $orderService,
            $itemService,
            $questionService,
            $notificationService,
            null, // claimsService — injected per-test when needed
            $messagingService, // messagingService — injected per-test when needed
            null, // techSheetService — injected per-test when needed
            $mlClient, // mlClient — injected per-test when needed
            $db ?? $this->createMockDb(),
            true,
            $shipmentSyncService
        );
    }

    // === Constructor / DI ===

    public function testConstructorWithAllDependencies(): void
    {
        $service = $this->buildService();
        $this->assertInstanceOf(MercadoLivreWebhookService::class, $service);
    }

    public function testConstructorWithMinimalDependencies(): void
    {
        $service = new MercadoLivreWebhookService(
            self::ACCOUNT_ID,
            $this->createMockLogger(),
            null, null, null, null,
            null, null, null, null, // claimsService, messagingService, techSheetService, mlClient
            null,
            true
        );
        $this->assertInstanceOf(MercadoLivreWebhookService::class, $service);
    }

    // === processWebhookEvent validation ===

    public function testMissingTopic(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent(['resource' => '/orders/123']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Missing topic or resource', $result['error']);
    }

    public function testMissingResource(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent(['topic' => 'orders']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Missing topic or resource', $result['error']);
    }

    public function testEmptyPayload(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent([]);
        $this->assertFalse($result['success']);
    }

    public function testMissingBothTopicAndResource(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent(['user_id' => 12345, 'application_id' => '999']);
        $this->assertFalse($result['success']);
    }

    public function testUnknownTopicIsIgnoredByDefaultWithExplicitMetadata(): void
    {
        $this->setEnv('ML_WEBHOOK_STRICT_TOPICS', '0');
        $service = $this->buildService();

        $result = $service->processWebhookEvent([
            'topic' => 'topic_unmapped_for_test',
            'resource' => '/foo/123',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue((bool)($result['ignored'] ?? false));
        $this->assertSame('unknown_topic', $result['ignored_reason'] ?? null);
    }

    public function testUnknownTopicCanFailInStrictMode(): void
    {
        $this->setEnv('ML_WEBHOOK_STRICT_TOPICS', '1');
        $service = $this->buildService();

        $result = $service->processWebhookEvent([
            'topic' => 'topic_unmapped_strict',
            'resource' => '/foo/999',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown webhook topic', (string)($result['error'] ?? ''));
        $this->assertFalse((bool)($result['ignored'] ?? true));
    }

    // === Order events ===

    public function testOrderEventSuccess(): void
    {
        $orderService = $this->createMockOrderService([
            'id' => '123456789',
            'total_amount' => 299.90,
            'status' => 'paid',
        ]);

        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->once())
            ->method('create')
            ->with(
                $this->equalTo(10),
                $this->equalTo('order_new'),
                $this->stringContains('123456789'),
                $this->anything(),
                $this->anything()
            );

        $service = $this->buildService(null, $orderService, null, null, $notifService);
        $result = $service->processWebhookEvent([
            'topic' => 'orders',
            'resource' => '/orders/123456789',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('event_id', $result);
        $this->assertStringStartsWith('evt_', $result['event_id']);
    }

    public function testOrderEventDoesNotRenotifySamePaidStatus(): void
    {
        $orderService = $this->createMockOrderService([
            'id' => '2000017426963518',
            'total_amount' => 99.90,
            'status' => 'paid',
        ]);

        $userStmt = $this->createMock(PDOStatement::class);
        $userStmt->method('execute')->willReturn(true);
        $userStmt->method('fetchColumn')->willReturn(10);

        $orderStmt = $this->createMock(PDOStatement::class);
        $orderStmt->method('execute')->willReturn(true);
        $orderStmt->method('fetchColumn')->willReturn('paid');

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(static function (string $sql) use ($userStmt, $orderStmt) {
            return str_contains($sql, 'FROM ml_orders') ? $orderStmt : $userStmt;
        });

        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->never())->method('create');

        $service = $this->buildService(null, $orderService, null, null, $notifService, $db);
        $result = $service->processWebhookEvent([
            'topic' => 'orders_v2',
            'resource' => '/orders/2000017426963518',
        ]);

        $this->assertTrue($result['success']);
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $this->envBackup[$key] = getenv($key);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    public function testOrderV2EventSuccess(): void
    {
        $orderService = $this->createMockOrderService([
            'id' => '987654321',
            'total_amount' => 150.00,
        ]);

        $service = $this->buildService(null, $orderService, null, null, $this->createMockNotificationService());
        $result = $service->processWebhookEvent([
            'topic' => 'orders_v2',
            'resource' => '/orders/987654321',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testOrderEventApiError(): void
    {
        $orderService = $this->createMockOrderService([
            'error' => 'not_found',
            'message' => 'Order not found',
        ]);

        $service = $this->buildService(null, $orderService, null, null, $this->createMockNotificationService());
        $result = $service->processWebhookEvent([
            'topic' => 'orders',
            'resource' => '/orders/999',
        ]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function testOrderEventWithNestedTotal(): void
    {
        $orderService = $this->createMockOrderService([
            'id' => '111',
            'status' => 'paid',
            'data' => ['total_amount' => 500.00],
        ]);

        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->once())
            ->method('create')
            ->with(
                $this->anything(), $this->anything(), $this->anything(),
                $this->stringContains('500'), $this->anything()
            );

        $service = $this->buildService(null, $orderService, null, null, $notifService);
        $result = $service->processWebhookEvent([
            'topic' => 'orders',
            'resource' => '/orders/111',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testOrderEventNoUserIdSkipsNotification(): void
    {
        $orderService = $this->createMockOrderService([
            'id' => '222',
            'total_amount' => 100.00,
        ]);

        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->never())->method('create');

        $service = $this->buildService(null, $orderService, null, null, $notifService, $this->createMockDb(null));
        $result = $service->processWebhookEvent([
            'topic' => 'orders',
            'resource' => '/orders/222',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testOrderEventFallbackTotal(): void
    {
        $orderService = $this->createMockOrderService(['id' => '333', 'status' => 'paid']);
        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->once())
            ->method('create')
            ->with(
                $this->anything(), $this->anything(), $this->anything(),
                $this->stringContains('---'), $this->anything()
            );

        $service = $this->buildService(null, $orderService, null, null, $notifService);
        $service->processWebhookEvent(['topic' => 'orders', 'resource' => '/orders/333']);
    }

    // === Item events ===

    public function testItemEventSuccess(): void
    {
        $itemService = $this->createMockItemService(['id' => 'MLB123456', 'title' => 'Bagageiro CG 160']);
        $itemService->expects($this->once())->method('syncItem')->with('MLB123456');

        $service = $this->buildService(null, null, $itemService);
        $result = $service->processWebhookEvent([
            'topic' => 'items',
            'resource' => '/items/MLB123456',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testItemsPricesTopicSyncsItemLikeItemsTopic(): void
    {
        $itemService = $this->createMockItemService(['id' => 'MLB999']);
        // Payload real do ML: /items/{id}/prices (não /items/{id})
        $itemService->expects($this->once())->method('syncItem')->with('MLB999');

        $service = $this->buildService(null, null, $itemService);
        $result = $service->processWebhookEvent([
            'topic' => 'items_prices',
            'resource' => '/items/MLB999/prices',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('ignored', $result);
    }

    public function testItemsPricesDoesNotTreatPricesSuffixAsItemId(): void
    {
        $itemService = $this->createMockItemService(['id' => 'MLB4435126877']);
        $itemService->expects($this->once())->method('syncItem')->with('MLB4435126877');

        $service = $this->buildService(null, null, $itemService);
        $result = $service->processWebhookEvent([
            'topic' => 'items_prices',
            'resource' => '/items/MLB4435126877/prices',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testItemEventResourceParsing(): void
    {
        $itemService = $this->createMockItemService(['id' => 'MLB789']);
        $itemService->expects($this->once())->method('syncItem')->with('MLB789');

        $service = $this->buildService(null, null, $itemService);
        $result = $service->processWebhookEvent([
            'topic' => 'items',
            'resource' => '/items/MLB789',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testItemSyncApiErrorFailsWebhookProcessing(): void
    {
        $itemService = $this->createMockItemService(['error' => 'API Error', 'message' => 'Item not found']);
        $itemService->expects($this->once())->method('syncItem')->with('MLB404');

        $service = $this->buildService(null, null, $itemService);
        $result = $service->processWebhookEvent([
            'topic' => 'items',
            'resource' => '/items/MLB404',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Item not found', (string)($result['error'] ?? ''));
    }

    // === Question events ===

    public function testQuestionEventSuccess(): void
    {
        $questionService = $this->createMockQuestionService(
            ['id' => '555', 'text' => 'Serve na CG 160?', 'item_id' => 'MLB111', 'item_title' => 'Bagageiro CG 160'],
            ['success' => true, 'draft' => 'Sim, serve!']
        );
        $questionService->expects($this->once())->method('syncSingleQuestion')->with('555');
        $questionService->expects($this->once())->method('generateDraftAnswer')->with('555');

        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->once())
            ->method('create')
            ->with($this->equalTo(10), $this->equalTo('question_new'), $this->anything(), $this->anything(), $this->anything());

        $service = $this->buildService(null, null, null, $questionService, $notifService);
        $result = $service->processWebhookEvent([
            'topic' => 'questions',
            'resource' => '/questions/555',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testQuestionEventSyncError(): void
    {
        $questionService = $this->createMockQuestionService([
            'error' => 'not_found',
            'message' => 'Question not found',
        ]);

        $service = $this->buildService(null, null, null, $questionService, $this->createMockNotificationService());
        $result = $service->processWebhookEvent([
            'topic' => 'questions',
            'resource' => '/questions/999',
        ]);

        $this->assertFalse($result['success']);
    }

    public function testQuestionEventDraftFailsGracefully(): void
    {
        $questionService = $this->createMock(QuestionService::class);
        $questionService->method('syncSingleQuestion')->willReturn([
            'id' => '777', 'text' => 'Qual o peso?', 'item_id' => 'MLB222',
        ]);
        $questionService->method('generateDraftAnswer')
            ->willThrowException(new \RuntimeException('AI service unavailable'));

        $service = $this->buildService(null, null, null, $questionService, $this->createMockNotificationService());
        $result = $service->processWebhookEvent([
            'topic' => 'questions',
            'resource' => '/questions/777',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testQuestionEventNoUserIdSkipsNotification(): void
    {
        $questionService = $this->createMockQuestionService([
            'id' => '888', 'text' => 'Funciona?', 'item_id' => 'MLB333',
        ]);

        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->never())->method('create');

        $service = $this->buildService(null, null, null, $questionService, $notifService, $this->createMockDb(null));
        $result = $service->processWebhookEvent([
            'topic' => 'questions',
            'resource' => '/questions/888',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testQuestionEventDoesNotRenotifyWhenAlreadyLocal(): void
    {
        $questionService = $this->createMockQuestionService(
            ['id' => '13620643760', 'text' => 'Já conhecida', 'item_id' => 'MLB1', 'item_title' => 'Peça', 'status' => 'ANSWERED'],
            ['success' => true, 'draft' => 'ok']
        );

        $userStmt = $this->createMock(PDOStatement::class);
        $userStmt->method('execute')->willReturn(true);
        $userStmt->method('fetchColumn')->willReturn(10);

        $qStmt = $this->createMock(PDOStatement::class);
        $qStmt->method('execute')->willReturn(true);
        $qStmt->method('fetchColumn')->willReturn(1);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(static function (string $sql) use ($userStmt, $qStmt) {
            return str_contains($sql, 'FROM ml_questions') ? $qStmt : $userStmt;
        });

        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->never())->method('create');

        $service = $this->buildService(null, null, null, $questionService, $notifService, $db);
        $result = $service->processWebhookEvent([
            'topic' => 'questions',
            'resource' => '/questions/13620643760',
        ]);

        $this->assertTrue($result['success']);
    }

    // === Claims / Messages ===

    public function testClaimEventIsHandledGracefully(): void
    {
        $logger = $this->createMockLogger();
        $logger->expects($this->atLeastOnce())->method('info');

        $service = $this->buildService($logger);
        $result = $service->processWebhookEvent([
            'topic' => 'claims',
            'resource' => '/v1/claims/CLM123',
        ]);

        $this->assertArrayHasKey('success', $result);
        $this->assertIsBool($result['success']);
        if ($result['success'] === false) {
            $this->assertNotEmpty($result['error']);
        }
    }

    public function testMessageEventIsHandledGracefully(): void
    {
        $logger = $this->createMockLogger();
        $logger->expects($this->atLeastOnce())->method('info');

        $messaging = $this->createMock(MessagingService::class);
        $messaging->method('getMessage')->willReturn([
            'text' => 'Olá',
            'from' => 1,
            'to' => 2,
        ]);
        $messaging->expects($this->once())->method('processIncomingMessage');

        $service = $this->buildService($logger, null, null, null, null, null, $messaging);
        $result = $service->processWebhookEvent([
            'topic' => 'messages',
            'resource' => '/messages/MSG456',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testMessageEventFetchFailureMarksWebhookAsFailed(): void
    {
        $logger = $this->createMockLogger();
        $logger->expects($this->atLeastOnce())->method('warning');

        $messaging = $this->createMock(MessagingService::class);
        $messaging->method('getMessage')->willReturn([
            'error' => 'not_found',
            'message' => 'Mensagem indisponível',
        ]);
        $messaging->expects($this->never())->method('processIncomingMessage');

        $service = $this->buildService($logger, null, null, null, null, null, $messaging);
        $result = $service->processWebhookEvent([
            'topic' => 'messages',
            'resource' => '/messages/MSG456',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Mensagem indisponível', (string)$result['error']);
    }

    public function testShipmentEventSyncFailureMarksWebhookAsFailed(): void
    {
        $logger = $this->createMockLogger();
        $logger->expects($this->atLeastOnce())->method('warning');

        $shipmentSync = $this->createMock(ShipmentSyncService::class);
        $shipmentSync->method('syncShipment')->willReturn([
            'success' => false,
            'error' => 'api_timeout',
        ]);

        $service = $this->buildService($logger, null, null, null, null, null, null, $shipmentSync);
        $result = $service->processWebhookEvent([
            'topic' => 'shipments',
            'resource' => '/shipments/SHIP123',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('api_timeout', (string)$result['error']);
    }

    public function testShipmentEventSyncSuccess(): void
    {
        $shipmentSync = $this->createMock(ShipmentSyncService::class);
        $shipmentSync->method('syncShipment')->willReturn([
            'success' => true,
            'data' => [
                'status' => 'shipped',
                'tracking_number' => 'BR123',
            ],
        ]);

        $notification = $this->createMockNotificationService();
        $notification->expects($this->once())->method('create');

        $service = $this->buildService(null, null, null, null, $notification, null, null, $shipmentSync);
        $result = $service->processWebhookEvent([
            'topic' => 'shipments',
            'resource' => '/shipments/SHIP123',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testShipmentEventDoesNotRenotifySameStatus(): void
    {
        $shipmentSync = $this->createMock(ShipmentSyncService::class);
        $shipmentSync->method('syncShipment')->willReturn([
            'success' => true,
            'data' => [
                'status' => 'ready_to_ship',
                'tracking_number' => null,
            ],
        ]);

        $userStmt = $this->createMock(PDOStatement::class);
        $userStmt->method('execute')->willReturn(true);
        $userStmt->method('fetchColumn')->willReturn(10);

        $shipStmt = $this->createMock(PDOStatement::class);
        $shipStmt->method('execute')->willReturn(true);
        $shipStmt->method('fetchColumn')->willReturn('ready_to_ship');

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(static function (string $sql) use ($userStmt, $shipStmt) {
            return str_contains($sql, 'FROM shipments') ? $shipStmt : $userStmt;
        });

        $notification = $this->createMockNotificationService();
        $notification->expects($this->never())->method('create');

        $service = $this->buildService(null, null, null, null, $notification, $db, null, $shipmentSync);
        $result = $service->processWebhookEvent([
            'topic' => 'shipments',
            'resource' => '/shipments/SHIP_DUP',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testPaymentEventFetchFailureMarksWebhookAsFailed(): void
    {
        $mlClient = $this->createMock(MercadoLivreClient::class);
        $mlClient->method('get')->willReturn([
            'error' => 'not_found',
            'message' => 'Payment not found',
        ]);

        $service = $this->buildService(null, null, null, null, null, null, null, null, $mlClient);
        $result = $service->processWebhookEvent([
            'topic' => 'payments',
            'resource' => '/collections/PAY123',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Payment not found', (string)$result['error']);
    }

    public function testPaymentEventUsesCollectionsEndpoint(): void
    {
        $mlClient = $this->createMock(MercadoLivreClient::class);
        $mlClient->expects($this->once())
            ->method('get')
            ->with('/collections/PAY123')
            ->willReturn([
                'id' => 'PAY123',
                'status' => 'approved',
                'transaction_amount' => 10.5,
                'order_id' => 99,
            ]);

        $orderService = $this->createMockOrderService(['id' => 99, 'status' => 'paid']);
        $notification = $this->createMockNotificationService();
        $notification->expects($this->once())->method('create');

        $service = $this->buildService(
            null,
            $orderService,
            null,
            null,
            $notification,
            $this->createMockDb(10),
            null,
            null,
            $mlClient
        );
        $result = $service->processWebhookEvent([
            'topic' => 'payments',
            'resource' => '/collections/PAY123',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testFeedbackEventFetchFailureMarksWebhookAsFailed(): void
    {
        $mlClient = $this->createMock(MercadoLivreClient::class);
        $mlClient->method('get')->willReturn([
            'error' => 'not_found',
            'message' => 'Feedback missing',
        ]);

        $service = $this->buildService(null, null, null, null, null, null, null, null, $mlClient);
        $result = $service->processWebhookEvent([
            'topic' => 'feedback',
            'resource' => '/feedback/FB1',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Feedback missing', (string)$result['error']);
    }

    // === Unhandled topics ===

    public function testUnhandledTopicReturnsSuccess(): void
    {
        $logger = $this->createMockLogger();
        $logger->expects($this->atLeastOnce())->method('info');

        $service = $this->buildService($logger);
        $result = $service->processWebhookEvent([
            'topic' => 'unknown_topic',
            'resource' => '/unknown/123',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('event_id', $result);
    }

    public function testVariousUnhandledTopics(): void
    {
        $service = $this->buildService();
        // 'payments' é tópico tratado — não deve figurar como unhandled.
        $topics = ['invoices', 'campaigns', 'listings'];
        foreach ($topics as $topic) {
            $result = $service->processWebhookEvent([
                'topic' => $topic,
                'resource' => "/some/{$topic}/resource",
            ]);
            $this->assertTrue($result['success'], "Topic '{$topic}' should return success");
        }
    }

    // === Event ID ===

    public function testEventIdGenerated(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent(['topic' => 'unknown', 'resource' => '/test/1']);
        $this->assertArrayHasKey('event_id', $result);
        $this->assertStringStartsWith('evt_', $result['event_id']);
    }

    public function testEventIdsAreUnique(): void
    {
        $service = $this->buildService();
        $r1 = $service->processWebhookEvent(['topic' => 'unknown', 'resource' => '/test/1']);
        $r2 = $service->processWebhookEvent(['topic' => 'unknown', 'resource' => '/test/2']);
        $this->assertNotEquals($r1['event_id'], $r2['event_id']);
    }

    // === Error handling ===

    public function testCatchesExceptions(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->method('getOrder')
            ->willThrowException(new \RuntimeException('Connection timeout'));

        $service = $this->buildService(null, $orderService, null, null, $this->createMockNotificationService());
        $result = $service->processWebhookEvent([
            'topic' => 'orders',
            'resource' => '/orders/123',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection timeout', $result['error']);
    }

    public function testLogsErrorOnException(): void
    {
        $logger = $this->createMockLogger();
        $logger->expects($this->atLeastOnce())->method('error');

        $orderService = $this->createMock(OrderService::class);
        $orderService->method('getOrder')
            ->willThrowException(new \RuntimeException('API down'));

        $service = $this->buildService($logger, $orderService, null, null, $this->createMockNotificationService());
        $service->processWebhookEvent(['topic' => 'orders', 'resource' => '/orders/123']);
    }

    // === Resource parsing ===

    public function testResourceParsingExtractsLastSegment(): void
    {
        $orderService = $this->createMockOrderService(['id' => '456', 'total_amount' => 100.00]);
        $orderService->expects($this->once())->method('getOrder')->with('456', $this->anything());

        $service = $this->buildService(null, $orderService, null, null, $this->createMockNotificationService());
        $service->processWebhookEvent(['topic' => 'orders', 'resource' => '/orders/456']);
    }

    public function testResourceParsingDeepPath(): void
    {
        $orderService = $this->createMockOrderService(['id' => '789', 'total_amount' => 50.00]);
        $orderService->expects($this->once())->method('getOrder')->with('789', $this->anything());

        $service = $this->buildService(null, $orderService, null, null, $this->createMockNotificationService());
        $service->processWebhookEvent(['topic' => 'orders', 'resource' => '/api/v2/orders/789']);
    }

    // === DB user lookup ===

    public function testUsesInjectedDbForUserLookup(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(77);

        $db = $this->createMock(PDO::class);
        $db->expects($this->atLeastOnce())
            ->method('prepare')
            ->with($this->stringContains('ml_accounts'))
            ->willReturn($stmt);

        $orderService = $this->createMockOrderService(['id' => '100', 'total_amount' => 200.00, 'status' => 'paid']);
        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->once())
            ->method('create')
            ->with($this->equalTo(77), $this->anything(), $this->anything(), $this->anything(), $this->anything());

        $service = $this->buildService(null, $orderService, null, null, $notifService, $db);
        $service->processWebhookEvent(['topic' => 'orders', 'resource' => '/orders/100']);
    }

    public function testDbExceptionInUserLookupReturnsNull(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willThrowException(new \Exception('Connection refused'));

        $orderService = $this->createMockOrderService(['id' => '200', 'total_amount' => 300.00]);
        $notifService = $this->createMockNotificationService();
        $notifService->expects($this->never())->method('create');

        $service = $this->buildService(null, $orderService, null, null, $notifService, $db);
        $result = $service->processWebhookEvent(['topic' => 'orders', 'resource' => '/orders/200']);
        $this->assertTrue($result['success']);
    }

    // === Response structure ===

    public function testSuccessResponseStructure(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent(['topic' => 'unknown', 'resource' => '/test/1']);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('event_id', $result);
        $this->assertTrue($result['success']);
    }

    public function testErrorResponseStructure(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent([]);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['success']);
    }

    // === Topic routing ===

    public function testTopicRoutingOrdersCallsOrderService(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getOrder')
            ->willReturn(['id' => '1', 'total_amount' => 10.00]);

        $service = $this->buildService(null, $orderService, null, null, $this->createMockNotificationService());
        $service->processWebhookEvent(['topic' => 'orders', 'resource' => '/orders/1']);
    }

    public function testTopicRoutingItemsCallsItemService(): void
    {
        $itemService = $this->createMock(ItemService::class);
        $itemService->expects($this->once())
            ->method('syncItem')
            ->with('MLB1')
            ->willReturn(['id' => 'MLB1']);

        $service = $this->buildService(null, null, $itemService);
        $service->processWebhookEvent(['topic' => 'items', 'resource' => '/items/MLB1']);
    }

    public function testTopicRoutingQuestionsCallsQuestionService(): void
    {
        $questionService = $this->createMock(QuestionService::class);
        $questionService->expects($this->once())
            ->method('syncSingleQuestion')
            ->with('Q1')
            ->willReturn(['id' => 'Q1', 'text' => 'Oi']);

        $service = $this->buildService(null, null, null, $questionService, $this->createMockNotificationService());
        $service->processWebhookEvent(['topic' => 'questions', 'resource' => '/questions/Q1']);
    }

    // === Multiple events ===

    public function testMultipleEventsProcessedSequentially(): void
    {
        $orderService = $this->createMockOrderService(['id' => '1', 'total_amount' => 100.00]);
        $service = $this->buildService(
            null, $orderService,
            $this->createMockItemService(['id' => 'MLB1']),
            null,
            $this->createMockNotificationService()
        );

        $r1 = $service->processWebhookEvent(['topic' => 'orders', 'resource' => '/orders/1']);
        $r2 = $service->processWebhookEvent(['topic' => 'items', 'resource' => '/items/MLB1']);
        $r3 = $service->processWebhookEvent(['topic' => 'unknown_topic', 'resource' => '/anything']);

        $this->assertTrue($r1['success']);
        $this->assertTrue($r2['success']);
        $this->assertTrue($r3['success']);
        $this->assertNotEquals($r1['event_id'], $r2['event_id']);
        $this->assertNotEquals($r2['event_id'], $r3['event_id']);
    }

    // === Security ===

    public function testParameterizedQueriesForUserLookup(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        // Pedidos também consultam status local (dedupe); o lookup de user_id deve
        // continuar parametrizado com account_id.
        $stmt->expects($this->atLeastOnce())
            ->method('execute')
            ->with($this->callback(static function (array $params): bool {
                return in_array(self::ACCOUNT_ID, $params, true);
            }));
        $stmt->method('fetchColumn')->willReturn(10);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $orderService = $this->createMockOrderService(['id' => '1', 'total_amount' => 10.00, 'status' => 'paid']);
        $service = $this->buildService(null, $orderService, null, null, $this->createMockNotificationService(), $db);
        $service->processWebhookEvent(['topic' => 'orders', 'resource' => '/orders/1']);
    }

    public function testStrictTypesEnabled(): void
    {
        $file = file_get_contents(__DIR__ . '/../../../app/Services/MercadoLivreWebhookService.php');
        $this->assertNotFalse($file);
        $this->assertStringContainsString('declare(strict_types=1)', $file);
    }

    public function testControllerStrictTypesEnabled(): void
    {
        $file = file_get_contents(__DIR__ . '/../../../app/Controllers/MercadoLivreWebhookController.php');
        $this->assertNotFalse($file);
        $this->assertStringContainsString('declare(strict_types=1)', $file);
    }

    // === Edge cases ===

    public function testNullTopicInPayload(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent(['topic' => null, 'resource' => '/orders/1']);
        $this->assertFalse($result['success']);
    }

    public function testEmptyStringTopic(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent(['topic' => '', 'resource' => '/orders/1']);
        $this->assertFalse($result['success']);
    }

    public function testEmptyStringResource(): void
    {
        $service = $this->buildService();
        $result = $service->processWebhookEvent(['topic' => 'orders', 'resource' => '']);
        $this->assertFalse($result['success']);
    }

    public function testPayloadDataLogged(): void
    {
        $loggedContexts = [];
        $logger = $this->createMock(StructuredLogService::class);
        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->willReturnCallback(function (string $msg, array $ctx = []) use (&$loggedContexts): void {
                $loggedContexts[] = $ctx;
            });

        $service = $this->buildService($logger);
        $service->processWebhookEvent([
            'topic' => 'unknown',
            'resource' => '/test/1',
            'user_id' => 123,
            'application_id' => '456',
        ]);

        $found = false;
        foreach ($loggedContexts as $ctx) {
            if (isset($ctx['topic']) && isset($ctx['resource'])) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'At least one info() call should contain topic and resource in context');
    }
}
