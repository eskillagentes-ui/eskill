<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CacheService;
use App\Services\MercadoLivreClient;
use App\Services\QuestionService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fila SLA local: conta ativa, COUNT real, unanswered case-insensitive, ≥1h, 403 fail-soft.
 *
 * @covers \App\Services\QuestionService
 */
final class QuestionLocalSlaQueueTest extends TestCase
{
    private function sqlite(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE ml_questions (
                question_id TEXT PRIMARY KEY,
                account_id INTEGER,
                seller_id INTEGER,
                item_id TEXT,
                status TEXT,
                question_text TEXT,
                answer_text TEXT,
                from_user_id INTEGER,
                from_user_nickname TEXT,
                date_created TEXT,
                answer_date TEXT,
                sentiment TEXT,
                intent TEXT,
                urgency INTEGER,
                ai_draft TEXT
            )'
        );
        return $db;
    }

    private function insert(PDO $db, int $accountId, string $id, string $status, string $created): void
    {
        $stmt = $db->prepare(
            'INSERT INTO ml_questions (
                question_id, account_id, seller_id, item_id, status,
                question_text, date_created, from_user_id
            ) VALUES (?, ?, 3058804121, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$id, $accountId, 'MLB' . $id, $status, 'Serve na CG?', $created]);
    }

    private function service(PDO $db, int $accountId, ?MercadoLivreClient $client = null): QuestionService
    {
        $ml = $client ?? $this->createMock(MercadoLivreClient::class);
        return new QuestionService(
            accountId: $accountId,
            client: $ml,
            cache: $this->createMock(CacheService::class),
            skipDbAutoConnect: true,
            db: $db
        );
    }

    private function hoursAgo(int $hours): string
    {
        return (new \DateTimeImmutable('now'))->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s');
    }

    public function testStatsCountNotSampleAndIgnoreNeighborAccount(): void
    {
        $db = $this->sqlite();
        for ($i = 1; $i <= 12; $i++) {
            $this->insert($db, 1335, 'F' . $i, 'ANSWERED', $this->hoursAgo(24));
        }
        $this->insert($db, 1335, 'U1', 'UNANSWERED', $this->hoursAgo(3));
        $this->insert($db, 1335, 'U2', 'UNANSWERED', $this->hoursAgo(0));
        $this->insert($db, 1336, 'X1', 'UNANSWERED', $this->hoursAgo(10));
        $this->insert($db, 1336, 'X2', 'ANSWERED', $this->hoursAgo(2));

        $stats = $this->service($db, 1335)->getLocalStats();

        self::assertSame('local', $stats['source']);
        self::assertSame(14, $stats['total']);
        self::assertSame(2, $stats['pending']);
        self::assertSame(12, $stats['answered']);
        self::assertSame(1, $stats['unanswered_ge_1h']);
        self::assertArrayNotHasKey('error', $stats);
    }

    public function testUnansweredFilterAcceptsLowercaseAndOrdersOldestFirst(): void
    {
        $db = $this->sqlite();
        $this->insert($db, 1335, 'NEW', 'UNANSWERED', $this->hoursAgo(1));
        $this->insert($db, 1335, 'OLD', 'UNANSWERED', $this->hoursAgo(5));
        $this->insert($db, 1335, 'DONE', 'ANSWERED', $this->hoursAgo(2));
        $this->insert($db, 1336, 'FALCAO', 'UNANSWERED', $this->hoursAgo(8));

        $result = $this->service($db, 1335)->getQuestionsLocal([
            'status' => 'unanswered',
            'limit' => 50,
        ]);

        self::assertTrue($result['success']);
        self::assertSame('local', $result['source']);
        self::assertCount(2, $result['questions']);
        self::assertSame(2, $result['paging']['total']);
        self::assertSame('OLD', $result['questions'][0]['id']);
        self::assertSame('NEW', $result['questions'][1]['id']);
        self::assertTrue($result['questions'][0]['sla_overdue']);
        self::assertGreaterThanOrEqual(3600, $result['questions'][0]['waiting_seconds']);
    }

    public function testMissingAccountDoesNotMixAllStores(): void
    {
        $db = $this->sqlite();
        $this->insert($db, 1335, 'A', 'UNANSWERED', $this->hoursAgo(4));
        $this->insert($db, 1336, 'B', 'UNANSWERED', $this->hoursAgo(4));

        $result = $this->service($db, 0)->getQuestionsLocal(['account_id' => 'all']);

        self::assertFalse($result['success'] ?? true);
        self::assertSame('missing_account', $result['error']);
        self::assertSame([], $result['questions']);
        self::assertSame(0, $result['paging']['total']);
    }

    public function testApi403FailsSoftToLocalNotBlank(): void
    {
        $db = $this->sqlite();
        $this->insert($db, 1335, 'LIVE', 'UNANSWERED', $this->hoursAgo(2));

        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getSellerId')->willReturn('3058804121');
        $client->method('get')->willReturn([
            'error' => 'forbidden',
            'status' => 403,
            'message' => 'forbidden',
        ]);

        $result = $this->service($db, 1335, $client)->getQuestions([
            'status' => 'UNANSWERED',
        ]);

        self::assertTrue($result['success']);
        self::assertSame('local', $result['source']);
        self::assertSame('ml_api', $result['fallback_from']);
        self::assertCount(1, $result['questions']);
        self::assertSame('LIVE', $result['questions'][0]['id']);
        self::assertNotSame([], $result['questions']);
    }

    public function testUnansweredCountIsLocalAndScoped(): void
    {
        $db = $this->sqlite();
        $this->insert($db, 1335, 'U1', 'UNANSWERED', $this->hoursAgo(2));
        $this->insert($db, 1335, 'U2', 'unanswered', $this->hoursAgo(9));
        $this->insert($db, 1336, 'X', 'UNANSWERED', $this->hoursAgo(9));

        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('get')->willReturn(['error' => 'forbidden', 'status' => 403]);

        self::assertSame(2, $this->service($db, 1335, $client)->getUnansweredCount());
        self::assertSame(1, $this->service($db, 1336, $client)->getUnansweredCount());
    }

    public function testControllerNoLongerSamplesTwoHundred(): void
    {
        $path = dirname(__DIR__, 3) . '/app/Controllers/QuestionController.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('getLocalStats()', $source);
        self::assertStringContainsString('AccountScopeHelper::activeAccountId', $source);
        self::assertStringContainsString('unanswered_ge_1h', $source);
        self::assertStringNotContainsString("'limit' => 200", $source);
        self::assertStringContainsString('assertCanApply', $source);
    }

    public function testQuestionsViewIsScopedSlaQueue(): void
    {
        $path = dirname(__DIR__, 3) . '/app/Views/dashboard/questions.php';
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString('Todas as Contas', $source);
        self::assertStringContainsString('SLA ≥ 1h', $source);
        self::assertStringContainsString('unanswered_ge_1h', $source);
        self::assertStringContainsString('apply_blocked', $source);
        self::assertStringNotContainsString('account_id=${accountFilter}', $source);
    }
}
