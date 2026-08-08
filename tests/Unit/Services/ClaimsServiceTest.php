<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Services\ClaimsService;
use App\Services\MercadoLivreClient;
use PDO;
use PDOStatement;

/**
 * @covers \App\Services\ClaimsService
 */
class ClaimsServiceTest extends TestCase
{
    private ClaimsService $service;
    /** @var MercadoLivreClient&MockObject */
    private MercadoLivreClient $mockClient;
    /** @var PDO&MockObject */
    private PDO $mockDb;
    /** @var PDOStatement&MockObject */
    private PDOStatement $mockStmt;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(MercadoLivreClient::class);
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);

        $this->service = new ClaimsService(
            accountId: 123,
            client: $this->mockClient,
            db: $this->mockDb,
            skipDbAutoConnect: true
        );
    }

    // ── Constructor / DI ────────────────────────────────────────────

    public function testConstructorWithAllDependencies(): void
    {
        $service = new ClaimsService(
            accountId: 99,
            client: $this->mockClient,
            db: $this->mockDb,
            skipDbAutoConnect: true
        );
        $this->assertInstanceOf(ClaimsService::class, $service);
    }

    public function testConstructorWithSkipDbAutoConnect(): void
    {
        $service = new ClaimsService(
            accountId: 99,
            client: $this->mockClient,
            skipDbAutoConnect: true
        );
        $this->assertInstanceOf(ClaimsService::class, $service);
    }

    public function testConstructorWithNullAccountId(): void
    {
        $service = new ClaimsService(
            accountId: null,
            client: $this->mockClient,
            skipDbAutoConnect: true
        );
        $this->assertInstanceOf(ClaimsService::class, $service);
    }

    // ── getClaims ───────────────────────────────────────────────────

    public function testGetClaimsReturnsApiResponse(): void
    {
        $expected = [
            'data' => [
                ['id' => 'claim1', 'status' => 'opened'],
                ['id' => 'claim2', 'status' => 'opened'],
            ],
            'paging' => ['total' => 2, 'offset' => 0, 'limit' => 50],
        ];

        $this->mockClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $endpoint, array $params = []) use ($expected) {
                if ($endpoint === '/users/me') {
                    return ['id' => 3058804121];
                }
                $this->assertSame('/post-purchase/v1/claims/search', $endpoint);
                $this->assertSame([
                    'limit' => 50,
                    'offset' => 0,
                    'status' => 'opened',
                    'players.user_id' => '3058804121',
                    'players.role' => 'respondent',
                ], $params);
                return $expected;
            });

        $result = $this->service->getClaims();
        $this->assertSame($expected, $result);
    }

    public function testGetClaimsWithCustomParams(): void
    {
        $expected = ['data' => [], 'paging' => ['total' => 0]];

        $this->mockClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $endpoint, array $params = []) use ($expected) {
                if ($endpoint === '/users/me') {
                    return ['id' => 1];
                }
                $this->assertSame('/post-purchase/v1/claims/search', $endpoint);
                $this->assertSame([
                    'limit' => 10,
                    'offset' => 20,
                    'status' => 'opened',
                    'players.user_id' => '1',
                    'players.role' => 'respondent',
                ], $params);
                return $expected;
            });

        $result = $this->service->getClaims('to_seller', 10, 20);
        $this->assertSame($expected, $result);
    }

    public function testGetClaimsReturnsErrorOnApiError(): void
    {
        $this->mockClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $endpoint) {
                if ($endpoint === '/users/me') {
                    return ['id' => 1];
                }
                return ['error' => 'forbidden', 'message' => 'Access denied'];
            });

        $result = $this->service->getClaims();
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Access denied', $result['error']);
    }

    public function testGetClaimsReturnsErrorOnApiErrorWithoutMessage(): void
    {
        $this->mockClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $endpoint) {
                if ($endpoint === '/users/me') {
                    return ['id' => 1];
                }
                return ['error' => 'unknown'];
            });

        $result = $this->service->getClaims();
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Failed to fetch claims', $result['error']);
    }

    public function testGetClaimsReturnsErrorOnException(): void
    {
        $this->mockClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $endpoint) {
                if ($endpoint === '/users/me') {
                    return ['id' => 1];
                }
                throw new \RuntimeException('Connection timeout');
            });

        $result = $this->service->getClaims();
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Connection timeout', $result['error']);
    }

    public function testGetClaimsEmptyResponse(): void
    {
        $this->mockClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $endpoint) {
                if ($endpoint === '/users/me') {
                    return ['id' => 1];
                }
                return [];
            });

        $result = $this->service->getClaims();
        $this->assertSame([], $result);
    }

    // ── getClaim ────────────────────────────────────────────────────

    public function testGetClaimReturnsClaimAndSyncsToDb(): void
    {
        $claim = [
            'id' => 'CLM123',
            'order_id' => 'ORD456',
            'type' => 'mediations',
            'status' => 'opened',
            'stage' => 'claim',
            'reason' => 'ITEM_NOT_AS_DESCRIBED',
            'amount_claimed' => ['amount' => 150.00, 'currency_id' => 'BRL'],
            'date_created' => '2025-01-15T10:00:00.000-04:00',
            'last_updated' => '2025-01-16T12:00:00.000-04:00',
        ];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->with('/post-purchase/v1/claims/CLM123')
            ->willReturn($claim);

        $this->mockDb->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);

        $this->mockStmt->expects($this->once())
            ->method('execute');

        $result = $this->service->getClaim('CLM123');
        $this->assertSame($claim, $result);
    }

    public function testGetClaimDoesNotSyncIfNoId(): void
    {
        $this->mockClient->expects($this->once())
            ->method('get')
            ->with('/post-purchase/v1/claims/CLM999')
            ->willReturn(['error' => 'not_found']);

        $this->mockDb->expects($this->never())
            ->method('prepare');

        $result = $this->service->getClaim('CLM999');
        $this->assertSame(['error' => 'not_found'], $result);
    }

    public function testGetClaimSyncsCorrectData(): void
    {
        $claim = [
            'id' => 'CLM789',
            'order_id' => 'ORD111',
            'type' => 'returns',
            'status' => 'closed',
            'stage' => 'resolution',
            'reason' => 'MISSING_PARTS',
            'amount_claimed' => ['amount' => 99.90, 'currency_id' => 'BRL'],
            'date_created' => '2025-02-01T08:00:00.000-04:00',
            'last_updated' => '2025-02-05T14:00:00.000-04:00',
        ];

        $capturedParams = [];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn($claim);

        $this->mockDb->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);

        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });

        $this->service->getClaim('CLM789');

        $this->assertSame('CLM789', $capturedParams[':id']);
        $this->assertSame('ORD111', $capturedParams[':order_id']);
        $this->assertSame(123, $capturedParams[':account_id']);
        $this->assertSame('returns', $capturedParams[':type']);
        $this->assertSame('closed', $capturedParams[':status']);
        $this->assertSame('resolution', $capturedParams[':stage']);
        $this->assertSame('MISSING_PARTS', $capturedParams[':reason']);
        $this->assertSame(99.90, $capturedParams[':amount']);
        $this->assertSame('BRL', $capturedParams[':currency_id']);
    }

    public function testGetClaimHandlesDbErrorGracefully(): void
    {
        $claim = [
            'id' => 'CLM_ERR',
            'order_id' => 'ORD_ERR',
            'type' => 'mediations',
            'status' => 'opened',
            'stage' => 'claim',
            'reason' => 'DEFECTIVE',
            'amount_claimed' => ['amount' => 50.00, 'currency_id' => 'BRL'],
            'date_created' => '2025-01-20T10:00:00.000-04:00',
            'last_updated' => '2025-01-20T12:00:00.000-04:00',
        ];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn($claim);

        $this->mockDb->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('DB error'));

        // Should still return the claim, not throw
        $result = $this->service->getClaim('CLM_ERR');
        $this->assertSame($claim, $result);
    }

    // ── syncClaim ───────────────────────────────────────────────────

    public function testSyncClaimReturnsTrueOnSuccess(): void
    {
        $claim = [
            'id' => 'CLM_SYNC',
            'order_id' => 'ORD_S',
            'type' => 'mediations',
            'status' => 'opened',
            'stage' => 'claim',
            'reason' => 'DEFECTIVE',
            'amount_claimed' => ['amount' => 30.00, 'currency_id' => 'BRL'],
            'date_created' => '2025-01-10T00:00:00.000-04:00',
            'last_updated' => '2025-01-11T00:00:00.000-04:00',
        ];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn($claim);

        $this->mockDb->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);

        $this->mockStmt->expects($this->once())
            ->method('execute');

        $result = $this->service->syncClaim('CLM_SYNC');
        $this->assertTrue($result);
    }

    public function testSyncClaimReturnsFalseOnNotFound(): void
    {
        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn(['error' => 'not_found']);

        $result = $this->service->syncClaim('CLM_NONE');
        $this->assertFalse($result);
    }

    // ── sendMessage ─────────────────────────────────────────────────

    public function testSendMessageWithTextOnly(): void
    {
        $expected = ['id' => 'msg1', 'status' => 'sent'];

        $this->mockClient->expects($this->once())
            ->method('post')
            ->with('/post-purchase/v1/claims/CLM123/messages', [
                'receiver_role' => 'complainant',
                'message' => 'Olá, vamos resolver isso.',
                'attachments' => [],
            ])
            ->willReturn($expected);

        $result = $this->service->sendMessage('CLM123', 'Olá, vamos resolver isso.');
        $this->assertSame($expected, $result);
    }

    public function testSendMessageWithAttachments(): void
    {
        $attachments = ['file1.jpg', 'file2.pdf'];
        $expected = ['id' => 'msg2', 'status' => 'sent'];

        $this->mockClient->expects($this->once())
            ->method('post')
            ->with('/post-purchase/v1/claims/CLM456/messages', [
                'receiver_role' => 'complainant',
                'message' => 'Segue comprovante.',
                'attachments' => $attachments,
            ])
            ->willReturn($expected);

        $result = $this->service->sendMessage('CLM456', 'Segue comprovante.', $attachments);
        $this->assertSame($expected, $result);
    }

    public function testSendMessageEmptyAttachments(): void
    {
        $this->mockClient->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, array $payload) {
                $this->assertSame([], $payload['attachments']);
                return ['id' => 'msg3'];
            });

        $result = $this->service->sendMessage('CLM789', 'Test');
        $this->assertArrayHasKey('id', $result);
    }

    // ── syncClaimToDatabase (via getClaim) ──────────────────────────

    public function testSyncClaimToDatabaseSkipsWhenDbIsNull(): void
    {
        $service = new ClaimsService(
            accountId: 123,
            client: $this->mockClient,
            skipDbAutoConnect: true
        );

        $claim = [
            'id' => 'CLM_NULL_DB',
            'order_id' => 'ORD_X',
            'type' => 'mediations',
            'status' => 'opened',
            'stage' => 'claim',
            'reason' => 'LOST',
            'amount_claimed' => ['amount' => 75.00, 'currency_id' => 'BRL'],
            'date_created' => '2025-01-01T00:00:00.000-04:00',
            'last_updated' => '2025-01-02T00:00:00.000-04:00',
        ];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn($claim);

        // Should not throw or error — just silently skip DB
        $result = $service->getClaim('CLM_NULL_DB');
        $this->assertSame('CLM_NULL_DB', $result['id']);
    }

    public function testSyncClaimToDatabaseContainsRawDataJson(): void
    {
        $claim = [
            'id' => 'CLM_JSON',
            'order_id' => 'ORD_J',
            'type' => 'returns',
            'status' => 'opened',
            'stage' => 'claim',
            'reason' => 'DAMAGED',
            'amount_claimed' => ['amount' => 200.00, 'currency_id' => 'BRL'],
            'date_created' => '2025-03-01T00:00:00.000-04:00',
            'last_updated' => '2025-03-02T00:00:00.000-04:00',
        ];

        $capturedParams = [];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn($claim);

        $this->mockDb->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);

        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });

        $this->service->getClaim('CLM_JSON');

        $decoded = json_decode($capturedParams[':raw_data'], true);
        $this->assertSame('CLM_JSON', $decoded['id']);
        $this->assertSame('DAMAGED', $decoded['reason']);
    }

    // ── Edge Cases ──────────────────────────────────────────────────

    public function testGetClaimsDefaultParams(): void
    {
        $this->mockClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $url, array $params = []) {
                if ($url === '/users/me') {
                    return ['id' => 1];
                }
                $this->assertSame('/post-purchase/v1/claims/search', $url);
                $this->assertSame(50, $params['limit']);
                $this->assertSame(0, $params['offset']);
                $this->assertSame('opened', $params['status']);
                $this->assertSame('1', $params['players.user_id']);
                $this->assertSame('respondent', $params['players.role']);
                return ['data' => []];
            });

        $this->service->getClaims();
    }

    public function testGetClaimWithLargeClaimData(): void
    {
        $claim = [
            'id' => 'CLM_BIG',
            'order_id' => 'ORD_BIG',
            'type' => 'mediations',
            'status' => 'opened',
            'stage' => 'claim',
            'reason' => 'ITEM_NOT_RECEIVED',
            'amount_claimed' => ['amount' => 9999.99, 'currency_id' => 'BRL'],
            'date_created' => '2025-06-01T00:00:00.000-04:00',
            'last_updated' => '2025-06-15T23:59:59.000-04:00',
            'extra_field_1' => str_repeat('x', 1000),
            'extra_field_2' => range(1, 100),
        ];

        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn($claim);

        $this->mockDb->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);

        $this->mockStmt->expects($this->once())
            ->method('execute');

        $result = $this->service->getClaim('CLM_BIG');
        $this->assertSame('CLM_BIG', $result['id']);
    }

    public function testSendMessageApiErrorResponse(): void
    {
        $this->mockClient->expects($this->once())
            ->method('post')
            ->willReturn(['error' => 'claim_closed', 'message' => 'Cannot send message']);

        $result = $this->service->sendMessage('CLM_CLOSED', 'Test');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Cannot send message', $result['error']);
    }

    public function testSendMessageApiErrorWithoutMessage(): void
    {
        $this->mockClient->expects($this->once())
            ->method('post')
            ->willReturn(['error' => 'claim_closed']);

        $result = $this->service->sendMessage('CLM_CLOSED', 'Test');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('claim_closed', $result['error']);
    }

    public function testSendMessageReturnsErrorOnException(): void
    {
        $this->mockClient->expects($this->once())
            ->method('post')
            ->willThrowException(new \RuntimeException('Network failure'));

        $result = $this->service->sendMessage('CLM123', 'Hello');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Network failure', $result['error']);
    }

    // ── getClaim error handling ─────────────────────────────────────

    public function testGetClaimReturnsErrorOnException(): void
    {
        $this->mockClient->expects($this->once())
            ->method('get')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->service->getClaim('CLM_FAIL');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Connection refused', $result['error']);
    }

    public function testGetClaimReturnsErrorOnApiErrorWithMessage(): void
    {
        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn(['error' => 'forbidden', 'message' => 'Access denied']);

        $this->mockDb->expects($this->never())
            ->method('prepare');

        $result = $this->service->getClaim('CLM_FORBIDDEN');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Access denied', $result['error']);
    }

    public function testGetClaimReturnsErrorOnApiErrorWithoutMessage(): void
    {
        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn(['error' => 'not_found']);

        $this->mockDb->expects($this->never())
            ->method('prepare');

        $result = $this->service->getClaim('CLM_404');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('not_found', $result['error']);
    }

    public function testSyncClaimReturnsFalseOnEmptyResponse(): void
    {
        $this->mockClient->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $result = $this->service->syncClaim('CLM_EMPTY');
        $this->assertFalse($result);
    }
}
