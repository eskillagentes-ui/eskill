<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CacheService;
use App\Services\MercadoLivreClient;
use App\Services\QuestionService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Read-only questions sync: unanswered + recent, paginate, 403 fail-soft, account scope.
 *
 * @covers \App\Services\QuestionService
 */
final class QuestionSyncReadOnlyTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $inserted
     */
    private function recordingDb(array &$inserted): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function ($params = null) use (&$inserted) {
            if (is_array($params)) {
                $inserted[] = $params;
            }
            return true;
        });

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        return $db;
    }

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $responder
     */
    private function clientWith(callable $responder, string $sellerId = '3058804121'): MercadoLivreClient
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getSellerId')->willReturn($sellerId);
        $client->method('get')->willReturnCallback(
            function (string $endpoint, array $params = []) use ($responder) {
                return $responder($endpoint, $params);
            }
        );
        $client->expects($this->never())->method('post');

        return $client;
    }

    private function service(PDO $db, int $accountId, MercadoLivreClient $client): QuestionService
    {
        return new QuestionService(
            accountId: $accountId,
            client: $client,
            cache: $this->createMock(CacheService::class),
            skipDbAutoConnect: true,
            db: $db
        );
    }

    public function testSyncFetchesUnansweredThenRecentPaginatesAndScopesAccount(): void
    {
        $inserted = [];
        $calls = [];

        $client = $this->clientWith(function (string $endpoint, array $params) use (&$calls) {
            $this->assertSame('/questions/search', $endpoint);
            $this->assertSame(4, $params['api_version'] ?? null);
            $this->assertSame('3058804121', (string) ($params['seller_id'] ?? ''));
            $this->assertSame('date_created', $params['sort_fields'] ?? null);
            $calls[] = $params;

            $status = $params['status'] ?? null;
            $offset = (int) ($params['offset'] ?? 0);
            $limit = (int) ($params['limit'] ?? 50);

            if ($status === 'UNANSWERED') {
                if ($offset === 0) {
                    return [
                        'total' => 3,
                        'limit' => $limit,
                        'questions' => [
                            ['id' => 'U1', 'item_id' => 'MLB1', 'status' => 'UNANSWERED', 'text' => 'Tem preto?'],
                            ['id' => 'U2', 'item_id' => 'MLB2', 'status' => 'UNANSWERED', 'text' => 'Serve na CG?'],
                        ],
                    ];
                }

                return [
                    'total' => 3,
                    'limit' => $limit,
                    'questions' => [
                        ['id' => 'U3', 'item_id' => 'MLB3', 'status' => 'UNANSWERED', 'text' => 'Qual voltagem?'],
                    ],
                ];
            }

            return [
                'total' => 1,
                'limit' => $limit,
                'questions' => [
                    ['id' => 'R1', 'item_id' => 'MLB9', 'status' => 'ANSWERED', 'text' => 'Qual prazo?', 'answer' => ['text' => '24h']],
                ],
            ];
        });

        $result = $this->service($this->recordingDb($inserted), 1335, $client)->syncQuestions(2);

        self::assertSame(4, $result['synced']);
        self::assertSame(0, $result['errors']);
        self::assertFalse($result['forbidden']);
        self::assertSame(1335, $result['account_id']);
        self::assertSame(3, $result['unanswered_fetched']);
        self::assertSame(1, $result['recent_fetched']);
        self::assertGreaterThanOrEqual(3, $result['pages']);

        $unansweredOffsets = [];
        $sawUnanswered = false;
        $sawRecent = false;
        foreach ($calls as $call) {
            if (($call['status'] ?? null) === 'UNANSWERED') {
                $sawUnanswered = true;
                $unansweredOffsets[] = (int) ($call['offset'] ?? 0);
            } else {
                $sawRecent = true;
            }
        }
        self::assertTrue($sawUnanswered);
        self::assertTrue($sawRecent);
        self::assertContains(0, $unansweredOffsets);
        self::assertContains(2, $unansweredOffsets);

        $ids = array_map(static fn (array $row) => (string) $row[':question_id'], $inserted);
        sort($ids);
        self::assertSame(['R1', 'U1', 'U2', 'U3'], $ids);
        foreach ($inserted as $row) {
            self::assertSame(1335, $row[':account_id']);
        }
    }

    public function testSync403OnUnansweredStillPullsRecentAndDoesNotThrow(): void
    {
        $inserted = [];

        $client = $this->clientWith(function (string $endpoint, array $params) {
            if (($params['status'] ?? null) === 'UNANSWERED') {
                return [
                    'error' => 'forbidden',
                    'status' => 403,
                    'message' => 'forbidden',
                ];
            }

            return [
                'total' => 1,
                'questions' => [
                    ['id' => 'R1', 'item_id' => 'MLB1', 'status' => 'ANSWERED', 'text' => 'ok'],
                ],
            ];
        });

        $result = $this->service($this->recordingDb($inserted), 1335, $client)->syncQuestions(50);

        self::assertTrue($result['forbidden']);
        self::assertGreaterThan(0, $result['errors']);
        self::assertSame(1, $result['synced']);
        self::assertSame('R1', $inserted[0][':question_id']);
        self::assertArrayHasKey('last_error', $result);
        self::assertStringContainsString('403', (string) $result['last_error']);
    }

    public function testSyncStampsConstructorAccountNotPayloadAndAllowsEmptyText(): void
    {
        $inserted = [];

        $client = $this->clientWith(function () {
            return [
                'total' => 1,
                'questions' => [
                    ['id' => 'BANNED1', 'item_id' => 'MLB1', 'status' => 'ANSWERED', 'account_id' => 1336],
                ],
            ];
        });

        $result = $this->service($this->recordingDb($inserted), 1335, $client)->syncQuestions(10);

        self::assertSame(1, $result['synced']);
        self::assertSame('BANNED1', $inserted[0][':question_id']);
        self::assertSame(1335, $inserted[0][':account_id']);
        self::assertSame('', $inserted[0][':text']);
    }

    public function testWorkerIsReadOnlyFailSoftAndTokenScoped(): void
    {
        $path = dirname(__DIR__, 3) . '/bin/questions-sync-worker.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString("TRIM(access_token) != ''", $source);
        self::assertStringContainsString("isset(\$options['limit']) ? (int) \$options['limit'] : 200", $source);
        self::assertStringContainsString('forbidden', $source);
        self::assertStringContainsString('exit(0)', $source);
        self::assertStringNotContainsString('exit(2)', $source);
        self::assertStringNotContainsString('->post(', $source);
        self::assertStringNotContainsString('answerQuestion', $source);
        self::assertStringContainsString('new QuestionService($mlAccountId)', $source);
        self::assertStringContainsString('Database::getInstance()', $source);
        self::assertStringContainsString('Dotenv', $source);

        $svc = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Services/QuestionService.php');
        self::assertStringContainsString('account_id = VALUES(account_id)', $svc);
    }
}
