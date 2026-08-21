<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AI\Answers\AnswerGeneratorService;
use App\Services\AI\Answers\QuestionAnalyzerService;
use App\Services\CacheService;
use App\Services\ItemService;
use App\Services\MercadoLivreClient;
use App\Services\QuestionService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuestionService — DB-free via DI mocking
 *
 * @covers \App\Services\QuestionService
 */
class QuestionServiceTest extends TestCase
{
    private function createMockClient(array $getMap = [], array $postMap = [], ?string $sellerId = '12345'): MockObject
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getSellerId')->willReturn($sellerId);
        $client->method('getMe')->willReturn(['id' => $sellerId ?? '12345', 'nickname' => 'AWA']);

        if (!empty($getMap)) {
            $client->method('get')->willReturnCallback(function (string $endpoint, array $params = []) use ($getMap) {
                foreach ($getMap as $pattern => $response) {
                    if (str_contains($endpoint, $pattern)) {
                        return $response;
                    }
                }
                return ['error' => 'not_found', 'message' => 'Endpoint not mocked: ' . $endpoint];
            });
        } else {
            $client->method('get')->willReturn(['error' => 'not_mocked']);
        }

        if (!empty($postMap)) {
            $client->method('post')->willReturnCallback(function (string $endpoint, array $data = []) use ($postMap) {
                foreach ($postMap as $pattern => $response) {
                    if (str_contains($endpoint, $pattern)) {
                        return $response;
                    }
                }
                return ['error' => 'not_found', 'message' => 'Post endpoint not mocked: ' . $endpoint];
            });
        } else {
            $client->method('post')->willReturn(['error' => 'not_mocked']);
        }

        return $client;
    }

    private function createMockCache(): MockObject
    {
        return $this->createMock(CacheService::class);
    }

    private function createMockAnswerGenerator(array $draftResult = []): MockObject
    {
        $gen = $this->createMock(AnswerGeneratorService::class);
        if (!empty($draftResult)) {
            $gen->method('generateDraft')->willReturn($draftResult);
        }
        return $gen;
    }

    private function createMockAnalyzer(array $analysisResult = []): MockObject
    {
        $analyzer = $this->createMock(QuestionAnalyzerService::class);
        if (!empty($analysisResult)) {
            $analyzer->method('analyze')->willReturn($analysisResult);
        }
        return $analyzer;
    }

    private function createMockItemService(array $itemResult = []): MockObject
    {
        $svc = $this->createMock(ItemService::class);
        if (!empty($itemResult)) {
            $svc->method('getItem')->willReturn($itemResult);
        }
        return $svc;
    }

    private function createMockDb(?array $fetchResult = null, int $rowCount = 0): MockObject
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn($rowCount);

        if ($fetchResult !== null) {
            $stmt->method('fetch')->willReturn($fetchResult);
            $stmt->method('fetchAll')->willReturn([$fetchResult]);
            $stmt->method('fetchColumn')->willReturn(1);
        } else {
            $stmt->method('fetch')->willReturn(false);
            $stmt->method('fetchAll')->willReturn([]);
            $stmt->method('fetchColumn')->willReturn(0);
        }

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        return $db;
    }

    private function buildService(
        ?MockObject $client = null,
        ?MockObject $cache = null,
        ?MockObject $answerGen = null,
        ?MockObject $analyzer = null,
        ?MockObject $itemService = null,
        ?MockObject $db = null
    ): QuestionService {
        return new QuestionService(
            accountId: 1,
            client: $client ?? $this->createMockClient(),
            cache: $cache ?? $this->createMockCache(),
            answerGenerator: $answerGen,
            questionAnalyzer: $analyzer,
            itemService: $itemService,
            db: $db,
            skipDbAutoConnect: true
        );
    }

    // -------------------------------------------------------
    // Constructor / DI Tests
    // -------------------------------------------------------

    public function testConstructorWithAllDependenciesInjected(): void
    {
        $service = $this->buildService(
            $this->createMockClient(),
            $this->createMockCache(),
            $this->createMockAnswerGenerator(),
            $this->createMockAnalyzer(),
            $this->createMockItemService(),
            $this->createMockDb()
        );

        $this->assertInstanceOf(QuestionService::class, $service);
    }

    public function testConstructorWithSkipDbAutoConnect(): void
    {
        $service = new QuestionService(
            accountId: 1,
            client: $this->createMockClient(),
            cache: $this->createMockCache(),
            skipDbAutoConnect: true
        );

        $this->assertInstanceOf(QuestionService::class, $service);
    }

    // -------------------------------------------------------
    // syncQuestions Tests
    // -------------------------------------------------------

    public function testSyncQuestionsSuccess(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => [
                    ['id' => 'Q1', 'item_id' => 'MLB111', 'status' => 'UNANSWERED', 'text' => 'Serve na CG?', 'from' => ['id' => 100]],
                    ['id' => 'Q2', 'item_id' => 'MLB222', 'status' => 'ANSWERED', 'text' => 'Qual peso?', 'from' => ['id' => 200]],
                ],
                'paging' => ['total' => 2, 'limit' => 50, 'offset' => 0],
            ],
        ]);

        $db = $this->createMockDb();
        $service = $this->buildService(client: $client, db: $db);

        $result = $service->syncQuestions(50);

        $this->assertArrayHasKey('synced', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertEquals(2, $result['synced']);
        $this->assertEquals(0, $result['errors']);
    }

    public function testSyncQuestionsApiError(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => ['error' => 'unauthorized', 'message' => 'Token expirado'],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->syncQuestions();

        $this->assertArrayHasKey('errors', $result);
        $this->assertGreaterThan(0, $result['errors']);
    }

    public function testSyncQuestionsNoSellerId(): void
    {
        $client = $this->createMockClient([], [], null);
        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getSellerId')->willReturn(null);
        $client->method('getMe')->willReturn(['error' => 'unauthorized']);

        $service = $this->buildService(client: $client);
        $result = $service->syncQuestions();

        $this->assertArrayHasKey('errors', $result);
        $this->assertGreaterThan(0, $result['errors']);
    }

    public function testSyncQuestionsDbSaveError(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => [
                    ['id' => 'Q1', 'item_id' => 'MLB111', 'status' => 'UNANSWERED', 'text' => 'Teste?', 'from' => ['id' => 100]],
                ],
                'paging' => ['total' => 1, 'limit' => 50, 'offset' => 0],
            ],
        ]);

        // No DB = saveQuestionToDatabase will throw
        $service = $this->buildService(client: $client);
        $result = $service->syncQuestions();

        $this->assertArrayHasKey('errors', $result);
        // Should have 1 error because DB is null
        $this->assertEquals(1, $result['errors']);
    }

    public function testSyncQuestionsEmptyList(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => [],
                'paging' => ['total' => 0, 'limit' => 50, 'offset' => 0],
            ],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->syncQuestions();

        $this->assertEquals(0, $result['synced']);
        $this->assertEquals(0, $result['errors']);
    }

    public function testSyncQuestionsLimitClamped(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => [],
                'paging' => ['total' => 0, 'limit' => 200, 'offset' => 0],
            ],
        ]);

        $service = $this->buildService(client: $client);

        // Limit > 200 should be clamped
        $result = $service->syncQuestions(999);
        $this->assertIsArray($result);

        // Limit < 1 should be clamped to 1
        $result = $service->syncQuestions(-5);
        $this->assertIsArray($result);
    }

    // -------------------------------------------------------
    // getQuestions Tests
    // -------------------------------------------------------

    public function testGetQuestionsFromApi(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => [
                    ['id' => 'Q1', 'item_id' => 'MLB111', 'status' => 'UNANSWERED', 'text' => 'Serve?'],
                ],
                'paging' => ['total' => 1, 'limit' => 50, 'offset' => 0],
            ],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->getQuestions();

        $this->assertTrue($result['success']);
        $this->assertEquals('ml_api', $result['source']);
        $this->assertCount(1, $result['questions']);
    }

    public function testGetQuestionsApiErrorNoFallback(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => ['error' => 'internal_error', 'message' => 'Server error'],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->getQuestions();

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetQuestionsWithLocalFallback(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => ['error' => 'internal_error', 'message' => 'Server error'],
        ]);

        $dbRow = [
            'question_id' => 'Q1',
            'question_text' => 'Serve na moto?',
            'status' => 'UNANSWERED',
            'item_id' => 'MLB111',
            'from_user_id' => 100,
            'date_created' => '2026-01-01 00:00:00',
            'account_id' => 1,
            'seller_id' => 12345,
            'sentiment' => null,
            'intent' => null,
            'urgency' => null,
            'ai_draft' => null,
            'answer_text' => null,
            'answer_date' => null,
        ];

        $db = $this->createMockDb($dbRow);
        $service = $this->buildService(client: $client, db: $db);
        $result = $service->getQuestions(['allow_local_cache' => true]);

        $this->assertTrue($result['success']);
        $this->assertEquals('local', $result['source']);
    }

    public function testGetQuestionsAllAccounts(): void
    {
        $dbRow = [
            'question_id' => 'Q1',
            'question_text' => 'Serve?',
            'status' => 'UNANSWERED',
            'item_id' => 'MLB111',
            'from_user_id' => 100,
            'date_created' => '2026-01-01 00:00:00',
            'account_id' => 1,
            'seller_id' => 12345,
            'sentiment' => null,
            'intent' => null,
            'urgency' => null,
            'ai_draft' => null,
            'answer_text' => null,
            'answer_date' => null,
        ];

        $db = $this->createMockDb($dbRow);
        $service = $this->buildService(db: $db);
        $result = $service->getQuestions(['account_id' => 'all']);

        $this->assertTrue($result['success']);
        $this->assertEquals('local', $result['source']);
    }

    public function testGetQuestionsWithEnrichedLocalData(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => [
                    ['id' => 'Q1', 'item_id' => 'MLB111', 'status' => 'UNANSWERED', 'text' => 'Serve?'],
                ],
                'paging' => ['total' => 1, 'limit' => 50, 'offset' => 0],
            ],
        ]);

        $enrichedRow = [
            'question_id' => 'Q1',
            'sentiment' => 'positive',
            'intent' => 'purchase',
            'urgency' => 'high',
            'ai_draft' => 'Sim, serve perfeitamente!',
        ];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $callCount = 0;
        $stmt->method('fetch')->willReturnCallback(function () use ($enrichedRow, &$callCount) {
            $callCount++;
            if ($callCount <= 1) {
                return $enrichedRow;
            }
            return false;
        });

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $service = $this->buildService(client: $client, db: $db);
        $result = $service->getQuestions();

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['questions']);
    }

    public function testGetQuestionsFilterByStatus(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => [
                    ['id' => 'Q1', 'item_id' => 'MLB111', 'status' => 'UNANSWERED', 'text' => 'Serve?'],
                ],
                'paging' => ['total' => 1, 'limit' => 50, 'offset' => 0],
            ],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->getQuestions(['status' => 'UNANSWERED']);

        $this->assertTrue($result['success']);
    }

    public function testGetQuestionsFilterByItemId(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => [],
                'paging' => ['total' => 0, 'limit' => 50, 'offset' => 0],
            ],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->getQuestions(['item_id' => 'MLB111']);

        $this->assertTrue($result['success']);
    }

    // -------------------------------------------------------
    // generateDraftAnswer Tests
    // -------------------------------------------------------

    public function testGenerateDraftAnswerSuccess(): void
    {
        $client = $this->createMockClient([
            '/questions/Q1' => [
                'id' => 'Q1',
                'item_id' => 'MLB111',
                'status' => 'UNANSWERED',
                'text' => 'Serve na CG 160?',
            ],
        ]);

        $gen = $this->createMockAnswerGenerator([
            'success' => true,
            'draft' => 'Sim, serve perfeitamente na CG 160!',
        ]);

        $db = $this->createMockDb();
        $service = $this->buildService(client: $client, answerGen: $gen, db: $db);
        $result = $service->generateDraftAnswer('Q1');

        $this->assertTrue($result['success']);
        $this->assertEquals('Sim, serve perfeitamente na CG 160!', $result['draft']);
    }

    public function testGenerateDraftAnswerNoGenerator(): void
    {
        $service = $this->buildService();
        $result = $service->generateDraftAnswer('Q1');

        $this->assertFalse($result['success']);
        $this->assertEquals('service_unavailable', $result['error']);
    }

    public function testGenerateDraftAnswerQuestionNotFound(): void
    {
        $client = $this->createMockClient([
            '/questions/Q999' => ['error' => 'not_found', 'message' => 'Question not found'],
        ]);

        $gen = $this->createMockAnswerGenerator();
        $service = $this->buildService(client: $client, answerGen: $gen);
        $result = $service->generateDraftAnswer('Q999');

        $this->assertArrayHasKey('error', $result);
    }

    // -------------------------------------------------------
    // analyzeQuestion Tests
    // -------------------------------------------------------

    public function testAnalyzeQuestionSuccess(): void
    {
        $client = $this->createMockClient([
            '/questions/Q1' => [
                'id' => 'Q1',
                'item_id' => 'MLB111',
                'status' => 'UNANSWERED',
                'text' => 'Qual o prazo de entrega?',
            ],
        ]);

        $analyzer = $this->createMockAnalyzer([
            'success' => true,
            'sentiment' => 'neutral',
            'intent' => 'logistics',
            'urgency' => 'normal',
        ]);

        $itemSvc = $this->createMockItemService(['title' => 'Bagageiro CG 160']);
        $db = $this->createMockDb();

        $service = $this->buildService(
            client: $client,
            analyzer: $analyzer,
            itemService: $itemSvc,
            db: $db
        );

        $result = $service->analyzeQuestion('Q1');

        $this->assertTrue($result['success']);
        $this->assertEquals('neutral', $result['sentiment']);
    }

    public function testAnalyzeQuestionNoAnalyzer(): void
    {
        $service = $this->buildService();
        $result = $service->analyzeQuestion('Q1');

        $this->assertFalse($result['success']);
        $this->assertEquals('service_unavailable', $result['error']);
    }

    public function testAnalyzeQuestionQuestionNotFound(): void
    {
        $client = $this->createMockClient([
            '/questions/Q999' => ['error' => 'not_found'],
        ]);

        $analyzer = $this->createMockAnalyzer();
        $service = $this->buildService(client: $client, analyzer: $analyzer);
        $result = $service->analyzeQuestion('Q999');

        $this->assertArrayHasKey('error', $result);
    }

    // -------------------------------------------------------
    // getQuestion Tests
    // -------------------------------------------------------

    public function testGetQuestionFromApi(): void
    {
        $client = $this->createMockClient([
            '/questions/Q1' => [
                'id' => 'Q1',
                'item_id' => 'MLB111',
                'status' => 'UNANSWERED',
                'text' => 'Serve?',
            ],
        ]);

        $db = $this->createMockDb();
        $service = $this->buildService(client: $client, db: $db);
        $result = $service->getQuestion('Q1');

        $this->assertTrue($result['success']);
        $this->assertEquals('ml_api', $result['source']);
        $this->assertEquals('Q1', $result['id']);
    }

    public function testGetQuestionFallbackToLocal(): void
    {
        $client = $this->createMockClient([
            '/questions/Q1' => ['error' => 'internal_error'],
        ]);

        $dbRow = [
            'question_id' => 'Q1',
            'question_text' => 'Serve na moto?',
            'status' => 'UNANSWERED',
            'item_id' => 'MLB111',
            'from_user_id' => 100,
            'date_created' => '2026-01-01 00:00:00',
            'account_id' => 1,
            'seller_id' => 12345,
            'sentiment' => null,
            'intent' => null,
            'urgency' => null,
            'ai_draft' => null,
            'answer_text' => null,
            'answer_date' => null,
        ];

        $db = $this->createMockDb($dbRow);
        $service = $this->buildService(client: $client, db: $db);
        $result = $service->getQuestion('Q1', ['allow_local_cache' => true]);

        $this->assertTrue($result['success']);
        $this->assertEquals('local', $result['source']);
    }

    public function testGetQuestionApiErrorNoFallback(): void
    {
        $client = $this->createMockClient([
            '/questions/Q1' => ['error' => 'internal_error'],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->getQuestion('Q1');

        $this->assertFalse($result['success']);
    }

    // -------------------------------------------------------
    // getQuestionFromDatabase Tests
    // -------------------------------------------------------

    public function testGetQuestionFromDatabaseFound(): void
    {
        $dbRow = [
            'question_id' => 'Q1',
            'question_text' => 'Funciona?',
            'status' => 'ANSWERED',
            'item_id' => 'MLB111',
            'from_user_id' => 100,
            'date_created' => '2026-01-01 00:00:00',
            'account_id' => 1,
            'seller_id' => 12345,
            'sentiment' => 'positive',
            'intent' => 'purchase',
            'urgency' => 'low',
            'ai_draft' => null,
            'answer_text' => 'Sim!',
            'answer_date' => '2026-01-01 01:00:00',
        ];

        $db = $this->createMockDb($dbRow);
        $service = $this->buildService(db: $db);
        $result = $service->getQuestionFromDatabase('Q1');

        $this->assertNotNull($result);
        $this->assertEquals('Q1', $result['id']);
        $this->assertEquals('Funciona?', $result['text']);
        $this->assertEquals('ANSWERED', $result['status']);
        $this->assertEquals('Sim!', $result['answer']['text']);
    }

    public function testGetQuestionFromDatabaseNotFound(): void
    {
        $db = $this->createMockDb(null);
        $service = $this->buildService(db: $db);
        $result = $service->getQuestionFromDatabase('Q999');

        $this->assertNull($result);
    }

    public function testGetQuestionFromDatabaseNoDb(): void
    {
        $service = $this->buildService();
        $result = $service->getQuestionFromDatabase('Q1');

        $this->assertNull($result);
    }

    // -------------------------------------------------------
    // answerQuestion Tests
    // -------------------------------------------------------

    public function testAnswerQuestionSuccess(): void
    {
        $client = $this->createMockClient(
            ['/questions/Q1' => ['id' => 'Q1', 'item_id' => 'MLB111', 'status' => 'UNANSWERED', 'text' => 'Serve?']],
            ['/answers' => ['id' => 'A1', 'text' => 'Sim, serve!', 'question_id' => 'Q1']]
        );

        $db = $this->createMockDb();
        $service = $this->buildService(client: $client, db: $db);
        $result = $service->answerQuestion('Q1', 'Sim, serve!');

        $this->assertTrue($result['success']);
    }

    public function testAnswerQuestionEmptyText(): void
    {
        $service = $this->buildService();
        $result = $service->answerQuestion('Q1', '');

        $this->assertFalse($result['success']);
        $this->assertEquals('validation_error', $result['error']);
    }

    public function testAnswerQuestionWhitespaceOnlyText(): void
    {
        $service = $this->buildService();
        $result = $service->answerQuestion('Q1', '   ');

        $this->assertFalse($result['success']);
        $this->assertEquals('validation_error', $result['error']);
    }

    public function testAnswerQuestionApiError(): void
    {
        $client = $this->createMockClient(
            [],
            ['/answers' => ['error' => 'forbidden', 'message' => 'Cannot answer']]
        );

        $service = $this->buildService(client: $client);
        $result = $service->answerQuestion('Q1', 'Testando');

        $this->assertFalse($result['success']);
    }

    public function testAnswerQuestionForbiddenAccountDoesNotPost(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->expects($this->never())->method('post');

        $service = new QuestionService(
            accountId: 1335,
            client: $client,
            cache: $this->createMockCache(),
            skipDbAutoConnect: true
        );
        $result = $service->answerQuestion('Q1', 'Sim, serve!');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['apply_blocked']);
        $this->assertStringContainsString('1335', (string) $result['error']);
    }

    public function testAnswerQuestionWithoutAccountDoesNotPost(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->expects($this->never())->method('post');

        $service = new QuestionService(
            accountId: null,
            client: $client,
            cache: $this->createMockCache(),
            skipDbAutoConnect: true
        );
        $result = $service->answerQuestion('Q1', 'Sim, serve!');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['apply_blocked']);
    }

    // -------------------------------------------------------
    // syncSingleQuestion Tests
    // -------------------------------------------------------

    public function testSyncSingleQuestionSuccess(): void
    {
        $client = $this->createMockClient([
            '/questions/Q1' => [
                'id' => 'Q1',
                'item_id' => 'MLB111',
                'status' => 'ANSWERED',
                'text' => 'Serve?',
                'answer' => ['text' => 'Sim!', 'date_created' => '2026-01-01'],
            ],
        ]);

        $db = $this->createMockDb();
        $service = $this->buildService(client: $client, db: $db);
        $result = $service->syncSingleQuestion('Q1');

        $this->assertTrue($result['success']);
        $this->assertEquals('Q1', $result['id']);
    }

    public function testSyncSingleQuestionApiError(): void
    {
        $client = $this->createMockClient([
            '/questions/Q999' => ['error' => 'not_found'],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->syncSingleQuestion('Q999');

        $this->assertArrayHasKey('error', $result);
    }

    // -------------------------------------------------------
    // deleteQuestion Tests
    // -------------------------------------------------------

    public function testDeleteQuestionSuccess(): void
    {
        $db = $this->createMockDb(null, 1);
        $service = $this->buildService(db: $db);
        $result = $service->deleteQuestion('Q1');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['deleted']);
    }

    public function testDeleteQuestionNotFound(): void
    {
        $db = $this->createMockDb(null, 0);
        $service = $this->buildService(db: $db);
        $result = $service->deleteQuestion('Q999');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['deleted']);
    }

    public function testDeleteQuestionNoDb(): void
    {
        $service = $this->buildService();
        $result = $service->deleteQuestion('Q1');

        $this->assertFalse($result['success']);
        $this->assertEquals('db_unavailable', $result['error']);
    }

    // -------------------------------------------------------
    // getUnansweredCount Tests
    // -------------------------------------------------------

    public function testGetUnansweredCountFromApi(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(7);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $service = $this->buildService(db: $db);
        $result = $service->getUnansweredCount();

        $this->assertEquals(7, $result);
    }

    public function testGetUnansweredCountApiErrorWithFallback(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => ['error' => 'internal_error'],
        ]);

        // DB returns 3 unanswered
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(3);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        // Need to set env for local fallback
        $_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK'] = 'true';
        $_ENV['APP_ENV'] = 'development';

        $service = $this->buildService(client: $client, db: $db);
        $result = $service->getUnansweredCount();

        unset($_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK']);
        unset($_ENV['APP_ENV']);

        $this->assertEquals(3, $result);
    }

    public function testGetUnansweredCountApiErrorNoFallback(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => ['error' => 'internal_error'],
        ]);

        // Ensure no fallback allowed
        $_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK'] = 'false';

        $service = $this->buildService(client: $client);
        $result = $service->getUnansweredCount();

        unset($_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK']);

        $this->assertEquals(0, $result);
    }

    // -------------------------------------------------------
    // saveQuestionToDatabase Tests
    // -------------------------------------------------------

    public function testSaveQuestionToDatabaseSuccess(): void
    {
        $db = $this->createMockDb();
        $service = $this->buildService(db: $db);

        $question = [
            'id' => 'Q1',
            'item_id' => 'MLB111',
            'status' => 'UNANSWERED',
            'text' => 'Serve na CG 160?',
            'from' => ['id' => 100],
            'date_created' => '2026-01-01 00:00:00',
        ];

        $service->saveQuestionToDatabase($question);

        // If we reach here without exception, it passed
        $this->assertTrue(true);
    }

    public function testSaveQuestionToDatabaseNoDb(): void
    {
        $service = $this->buildService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB indisponível');

        $service->saveQuestionToDatabase([
            'id' => 'Q1',
            'item_id' => 'MLB111',
            'status' => 'UNANSWERED',
            'text' => 'Teste',
        ]);
    }

    public function testSaveQuestionToDatabaseInvalidPayload(): void
    {
        $db = $this->createMockDb();
        $service = $this->buildService(db: $db);

        $this->expectException(\InvalidArgumentException::class);

        $service->saveQuestionToDatabase(['id' => 'Q1']);
    }

    // -------------------------------------------------------
    // Edge Cases
    // -------------------------------------------------------

    public function testSyncQuestionsNonArrayQuestionsSkipped(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => ['not_an_array', null, 42],
                'paging' => ['total' => 3, 'limit' => 50, 'offset' => 0],
            ],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->syncQuestions();

        $this->assertEquals(0, $result['synced']);
    }

    public function testGetQuestionsNonArrayQuestionsInResponse(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => 'invalid',
                'paging' => ['total' => 0, 'limit' => 50, 'offset' => 0],
            ],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->getQuestions();

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['questions']);
    }

    public function testGetQuestionsWithPaginationParams(): void
    {
        $client = $this->createMockClient([
            '/questions/search' => [
                'questions' => [],
                'paging' => ['total' => 0, 'limit' => 10, 'offset' => 20],
            ],
        ]);

        $service = $this->buildService(client: $client);
        $result = $service->getQuestions(['limit' => 10, 'offset' => 20]);

        $this->assertTrue($result['success']);
    }

    public function testAnswerQuestionSyncsAfterSuccess(): void
    {
        $getCallCount = 0;
        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getSellerId')->willReturn('12345');
        $client->method('getMe')->willReturn(['id' => '12345']);
        $client->method('post')->willReturn(['id' => 'A1', 'text' => 'Sim!', 'question_id' => 'Q1']);
        $client->method('get')->willReturnCallback(function (string $endpoint) use (&$getCallCount) {
            $getCallCount++;
            if (str_contains($endpoint, '/questions/Q1')) {
                return ['id' => 'Q1', 'item_id' => 'MLB111', 'status' => 'ANSWERED', 'text' => 'Serve?'];
            }
            return ['error' => 'not_found'];
        });

        $db = $this->createMockDb();
        $service = $this->buildService(client: $client, db: $db);
        $result = $service->answerQuestion('Q1', 'Sim!');

        $this->assertTrue($result['success']);
        // After successful answer, syncSingleQuestion should be called (which calls getQuestion)
        $this->assertGreaterThanOrEqual(1, $getCallCount);
    }

    public function testGetQuestionSavesToDatabaseOnApiSuccess(): void
    {
        $client = $this->createMockClient([
            '/questions/Q1' => [
                'id' => 'Q1',
                'item_id' => 'MLB111',
                'status' => 'UNANSWERED',
                'text' => 'Teste?',
            ],
        ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->atLeastOnce())->method('execute')->willReturn(true);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $service = $this->buildService(client: $client, db: $db);
        $result = $service->getQuestion('Q1');

        $this->assertTrue($result['success']);
    }

    public function testDeleteQuestionNoteAboutApiLimitation(): void
    {
        $db = $this->createMockDb(null, 1);
        $service = $this->buildService(db: $db);
        $result = $service->deleteQuestion('Q1');

        $this->assertArrayHasKey('note', $result);
        $this->assertStringContainsString('API do Mercado Livre', $result['note']);
    }

    public function testSaveQuestionWithAnswerData(): void
    {
        $db = $this->createMockDb();
        $service = $this->buildService(db: $db);

        $question = [
            'id' => 'Q1',
            'item_id' => 'MLB111',
            'status' => 'ANSWERED',
            'text' => 'Serve?',
            'from' => ['id' => 100],
            'date_created' => '2026-01-01 00:00:00',
            'answer' => [
                'text' => 'Sim, serve!',
                'date_created' => '2026-01-01 01:00:00',
            ],
        ];

        $service->saveQuestionToDatabase($question);
        $this->assertTrue(true);
    }

    public function testSaveQuestionMissingFromId(): void
    {
        $db = $this->createMockDb();
        $service = $this->buildService(db: $db);

        $question = [
            'id' => 'Q1',
            'item_id' => 'MLB111',
            'status' => 'UNANSWERED',
            'text' => 'Teste?',
            'from' => ['id' => 'invalid'],
            'date_created' => '2026-01-01 00:00:00',
        ];

        // from_user_id should default to 0 for non-numeric from.id
        $service->saveQuestionToDatabase($question);
        $this->assertTrue(true);
    }

    public function testNormalizeLocalQuestionRowWithAnswer(): void
    {
        $dbRow = [
            'question_id' => 'Q1',
            'question_text' => 'Funciona?',
            'status' => 'ANSWERED',
            'item_id' => 'MLB111',
            'from_user_id' => 100,
            'date_created' => '2026-01-01 00:00:00',
            'account_id' => 1,
            'seller_id' => 12345,
            'sentiment' => 'neutral',
            'intent' => 'info',
            'urgency' => 'low',
            'ai_draft' => null,
            'answer_text' => 'Sim, funciona!',
            'answer_date' => '2026-01-01 01:00:00',
        ];

        $db = $this->createMockDb($dbRow);
        $service = $this->buildService(db: $db);
        $result = $service->getQuestionFromDatabase('Q1');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertEquals('Sim, funciona!', $result['answer']['text']);
    }

    public function testNormalizeLocalQuestionRowWithoutAnswer(): void
    {
        $dbRow = [
            'question_id' => 'Q1',
            'question_text' => 'Disponível?',
            'status' => 'UNANSWERED',
            'item_id' => 'MLB111',
            'from_user_id' => 100,
            'date_created' => '2026-01-01 00:00:00',
            'account_id' => 1,
            'seller_id' => 12345,
            'sentiment' => null,
            'intent' => null,
            'urgency' => null,
            'ai_draft' => null,
            'answer_text' => null,
            'answer_date' => null,
        ];

        $db = $this->createMockDb($dbRow);
        $service = $this->buildService(db: $db);
        $result = $service->getQuestionFromDatabase('Q1');

        $this->assertNotNull($result);
        $this->assertArrayNotHasKey('answer', $result);
    }
}
