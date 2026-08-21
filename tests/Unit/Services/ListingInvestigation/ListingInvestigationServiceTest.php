<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ListingInvestigation;

use App\Services\ListingInvestigation\DashScopeClient;
use App\Services\ListingInvestigation\ListingInvestigationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\ListingInvestigation\ListingInvestigationService
 * @covers \App\Services\ListingInvestigation\DashScopeClient
 */
final class ListingInvestigationServiceTest extends TestCase
{
    public function testMissingKeyRulesPathStillProducesRow(): void
    {
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 1335, 'MLB-PHOTO', 'Farol CG 160 Honda', [
            'pictures' => [1],
            'shipping' => ['free_shipping' => false],
            'listing_type_id' => 'gold_special',
            'available_quantity' => 4,
            'visits_30d' => 0,
            'sales_30d' => 0,
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'Honda'],
                ['id' => 'MODEL', 'value_name' => 'CG 160'],
            ],
            'domain_id' => 'MLB-MOTORCYCLE_HEADLIGHTS',
        ], 4);

        $llm = new DashScopeClient('', DashScopeClient::DEFAULT_BASE, static function (): ?array {
            self::fail('DashScope must not be called without a key');
        });
        $service = new ListingInvestigationService($db, $llm, false);
        $result = $service->run(1335, 5);

        self::assertTrue($result['apply_blocked']);
        self::assertFalse($result['ml_write']);
        self::assertCount(1, $result['investigated']);
        $row = $result['investigated'][0];
        self::assertSame('rules', $row['model_used']);
        self::assertSame('MLB-PHOTO', $row['mlb_id']);
        self::assertFalse($row['published']);
        $codes = array_column($row['blockers'], 'code');
        self::assertContains('photos_lt3', $codes);
        self::assertContains('no_free_shipping', $codes);
        self::assertContains('not_premium', $codes);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM listing_investigations')->fetchColumn());
    }

    public function testDoesNotMixAccount1336(): void
    {
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 1335, 'MLB-FAC', 'Peça Facilyty', [
            'pictures' => [1],
            'shipping' => ['free_shipping' => true],
            'listing_type_id' => 'gold_pro',
            'available_quantity' => 2,
            'attributes' => [['id' => 'MODEL', 'value_name' => 'X']],
        ], 2);
        $this->insertItem($db, 1336, 'MLB-FALCAO', 'Peça Falcao com gap enorme', [
            'pictures' => [],
            'shipping' => ['free_shipping' => false],
            'listing_type_id' => 'gold_special',
            'available_quantity' => 0,
            'visits_30d' => 999,
            'sales_30d' => 0,
            'attributes' => [['id' => 'MODEL', 'value_name' => 'STUFFED TITAN FAN START TODAY']],
        ], 0);

        $service = new ListingInvestigationService($db, null, true);
        $result = $service->run(1335, 5);
        $mlbs = array_column($result['investigated'], 'mlb_id');
        self::assertSame(['MLB-FAC'], $mlbs);
        $all = $db->query('SELECT mlb_id FROM listing_investigations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['MLB-FAC'], $all);
    }

    public function testModelStuffingForbiddenInPromptAndOutputContract(): void
    {
        self::assertStringContainsString('Nunca reescreva MODEL', ListingInvestigationService::SYSTEM_PROMPT);
        self::assertStringContainsString('Nunca coloque long-tail', ListingInvestigationService::SYSTEM_PROMPT);
        self::assertStringContainsString('compatibilities', ListingInvestigationService::SYSTEM_PROMPT);
        self::assertStringContainsString('apply_blocked=true', ListingInvestigationService::SYSTEM_PROMPT);
        self::assertStringNotContainsString('PUT /items', ListingInvestigationService::SYSTEM_PROMPT);

        $db = $this->sqliteCatalog();
        $this->insertItem($db, 1335, 'MLB-MODEL', 'Kit Farol Titan Fan Start Honda', [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
            'listing_type_id' => 'gold_pro',
            'available_quantity' => 3,
            'visits_30d' => 12,
            'sales_30d' => 0,
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'Honda'],
                ['id' => 'MODEL', 'value_name' => 'CG 160'],
            ],
            'domain_id' => 'MLB-MOTORCYCLE_HEADLIGHTS',
        ], 3);

        $transport = static function (string $url, array $headers, array $payload): array {
            self::assertStringContainsString('chat/completions', $url);
            $joined = json_encode($payload, JSON_UNESCAPED_UNICODE);
            self::assertStringContainsString('Nunca reescreva MODEL', $joined);
            self::assertStringContainsString('CG 160', $joined);
            self::assertSame('qwen3.8-max', $payload['model']);
            return [
                'model' => 'qwen3.8-max',
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'blockers' => [['code' => 'visits_no_sales', 'label' => 'visitas']],
                            'draft_title' => 'Farol Honda CG 160',
                            'draft_notes' => 'ok',
                            'model_attribute' => 'CG 160 Titan Fan Start Today Kit Farol',
                            'published' => true,
                        ]),
                    ],
                ]],
            ];
        };
        $llm = new DashScopeClient('sk-test-not-real-key-for-unit', DashScopeClient::DEFAULT_BASE, $transport);
        $service = new ListingInvestigationService($db, $llm, false);
        $result = $service->run(1335, 1);
        $row = $result['investigated'][0];
        self::assertStringContainsString('MODEL não reescrito', $row['draft_notes']);
        self::assertStringNotContainsString('Titan Fan Start Today Kit Farol', $row['draft_title']);
        self::assertFalse($row['published']);
        self::assertTrue($service->looksLikeLongTail('CG 160 Titan Fan Start Today Kit Farol'));
    }

    public function testInvestigationDoesNotCallMlWrite(): void
    {
        $src = file_get_contents(dirname(__DIR__, 4) . '/app/Services/ListingInvestigation/ListingInvestigationService.php');
        $worker = file_get_contents(dirname(__DIR__, 4) . '/bin/listing-investigation-worker.php');
        self::assertIsString($src);
        self::assertIsString($worker);
        foreach ([$src, $worker] as $code) {
            self::assertStringNotContainsString('MLWriteGateway', $code);
            self::assertStringNotContainsString('PUT /items', $code);
            self::assertStringNotContainsString('POST /answers', $code);
            self::assertStringNotContainsString('->putItem', $code);
            self::assertStringNotContainsString('pauseItem', $code);
            self::assertStringContainsString('ml_write', $code);
        }
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 1335, 'MLB-OKGAP', 'Peça', [
            'pictures' => [1],
            'shipping' => ['free_shipping' => true],
            'listing_type_id' => 'gold_pro',
            'available_quantity' => 1,
        ], 1);
        $result = (new ListingInvestigationService($db, null, true))->run(1335, 5);
        self::assertFalse($result['ml_write']);
        self::assertTrue($result['apply_blocked']);
    }

    public function testHardCaseUsesMaxAndNormalUsesPlus(): void
    {
        $itemHard = [
            'visits_30d' => 8,
            'sales_30d' => 0,
            'status' => 'active',
        ];
        $itemNormal = [
            'visits_30d' => 0,
            'sales_30d' => 0,
            'status' => 'active',
        ];
        $svc = new ListingInvestigationService($this->sqliteCatalog(), null, true);
        self::assertTrue($svc->isHardCase($itemHard));
        self::assertFalse($svc->isHardCase($itemNormal));
        self::assertSame('qwen3.7-plus', DashScopeClient::MODEL_PLUS);
        self::assertSame('qwen3.8-max', DashScopeClient::MODEL_MAX);
    }

    public function testPregaoSnapshotIgnores1336AndMarksNotPublished(): void
    {
        $db = $this->sqliteCatalog();
        $svc = new ListingInvestigationService($db, null, true);
        $svc->ensureTable();
        $db->exec("INSERT INTO listing_investigations (account_id, mlb_id, status, blockers, draft_title, draft_notes, model_used)
                   VALUES (1335, 'MLB-A', 'open', '[{\"code\":\"photos_lt3\"}]', 'Farol Honda CG 160', 'x', 'rules')");
        $db->exec("INSERT INTO listing_investigations (account_id, mlb_id, status, blockers, draft_title, draft_notes, model_used)
                   VALUES (1336, 'MLB-FALCAO', 'open', '[{\"code\":\"stock_0\"}]', 'nope', 'x', 'rules')");
        $snap = $svc->pregaoSnapshot(1335);
        self::assertTrue($snap['apply_blocked']);
        self::assertFalse($snap['ml_write']);
        self::assertFalse($snap['published']);
        self::assertSame(1, $snap['count']);
        self::assertSame('MLB-A', $snap['items'][0]['mlb']);
        self::assertTrue($snap['items'][0]['nao_publicado']);
        self::assertFalse($snap['items'][0]['published']);
    }

    public function testWorkerCliRefusesStaging1335AndIsReadOnly(): void
    {
        $worker = file_get_contents(dirname(__DIR__, 4) . '/bin/listing-investigation-worker.php');
        self::assertIsString($worker);
        self::assertStringContainsString('staging.eskill.com.br', $worker);
        self::assertStringContainsString('must not point at FACILYTY 1335', $worker);
        self::assertStringContainsString('apply_blocked=true', $worker);
        self::assertStringContainsString('--once', $worker);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertItem(PDO $db, int $accountId, string $mlb, string $title, array $data, int $qty): void
    {
        $stmt = $db->prepare(
            'INSERT INTO items (account_id, ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $mlb,
            $title,
            'active',
            $qty,
            $data['catalog_product_id'] ?? null,
            json_encode($data, JSON_THROW_ON_ERROR),
        ]);
    }

    private function sqliteCatalog(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE items (
                account_id INTEGER,
                ml_item_id TEXT,
                title TEXT,
                status TEXT,
                available_quantity INTEGER,
                sold_quantity INTEGER,
                catalog_product_id TEXT,
                data TEXT
            )'
        );

        return $db;
    }
}
