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
    public function testRulesPathDoesNotCallDashScopeEvenWithKey(): void
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

        $called = 0;
        $llm = new DashScopeClient('sk-test-not-real-key-for-unit', DashScopeClient::DEFAULT_BASE, static function () use (&$called): ?array {
            $called++;
            self::fail('DashScope must not be called on the default rules path');
        });
        self::assertTrue($llm->isConfigured());
        self::assertFalse($llm->hasKnownWorkingModel());

        $service = new ListingInvestigationService($db, $llm);
        $result = $service->run(1335, 50);

        self::assertSame(0, $called);
        self::assertTrue($result['apply_blocked']);
        self::assertFalse($result['ml_write']);
        self::assertSame('rules', $result['model_key']);
        self::assertCount(1, $result['investigated']);
        $row = $result['investigated'][0];
        self::assertSame('rules', $row['model_used']);
        self::assertSame('MLB-PHOTO', $row['mlb_id']);
        self::assertFalse($row['published']);
        $codes = array_column($row['blockers'], 'code');
        $labels = array_column($row['blockers'], 'label');
        self::assertContains('photos_lt3', $codes);
        self::assertContains('no_free_shipping', $codes);
        self::assertContains('not_premium', $codes);
        self::assertContains('menos de 3 fotos', $labels);
        self::assertContains('sem frete grátis', $labels);
        self::assertContains('sem Premium', $labels);
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

        $called = 0;
        $llm = new DashScopeClient('sk-test-not-real-key-for-unit', DashScopeClient::DEFAULT_BASE, static function () use (&$called): ?array {
            $called++;
            return null;
        });
        $service = new ListingInvestigationService($db, $llm);
        $result = $service->run(1335, 50);
        self::assertSame(0, $called);
        $mlbs = array_column($result['investigated'], 'mlb_id');
        self::assertSame(['MLB-FAC'], $mlbs);
        $all = $db->query('SELECT mlb_id FROM listing_investigations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['MLB-FAC'], $all);
    }

    public function testModelStuffingForbiddenWithoutCallingDashScope(): void
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

        $called = 0;
        $llm = new DashScopeClient('sk-test-not-real-key-for-unit', DashScopeClient::DEFAULT_BASE, static function () use (&$called): ?array {
            $called++;
            self::fail('rules path must not call DashScope to enforce MODEL contract');
        });
        $service = new ListingInvestigationService($db, $llm);
        $result = $service->run(1335, 1);
        self::assertSame(0, $called);
        $row = $result['investigated'][0];
        self::assertSame('rules', $row['model_used']);
        self::assertStringNotContainsString('Titan Fan Start', $row['draft_title']);
        self::assertFalse($row['published']);
        self::assertTrue($service->looksLikeLongTail('CG 160 Titan Fan Start Today Kit Farol'));
        self::assertNull($service->realModel([
            'attributes' => [['id' => 'MODEL', 'value_name' => 'Awa Preto Texturizado Modelo Original Rosca Interna Sem Peso']],
        ]));
        self::assertSame('CG 160', $service->realModel([
            'attributes' => [['id' => 'MODEL', 'value_name' => 'CG 160']],
        ]));
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
        $result = (new ListingInvestigationService($db))->run(1335, 50);
        self::assertFalse($result['ml_write']);
        self::assertTrue($result['apply_blocked']);
        self::assertSame('rules', $result['model_key']);
    }

    public function testDefaultLimitCoversFichaUniverseWithoutDashScope(): void
    {
        self::assertSame(50, ListingInvestigationService::DEFAULT_LIMIT);
        self::assertGreaterThanOrEqual(30, ListingInvestigationService::DEFAULT_LIMIT);

        $db = $this->sqliteCatalog();
        for ($i = 1; $i <= 35; $i++) {
            $this->insertItem($db, 1335, sprintf('MLB-%03d', $i), 'Peça ' . $i, [
                'pictures' => [1],
                'shipping' => ['free_shipping' => false],
                'listing_type_id' => 'gold_special',
                'available_quantity' => 2,
            ], 2);
        }
        $this->insertItem($db, 1336, 'MLB-FALCAO', 'Outra loja', [
            'pictures' => [],
            'shipping' => ['free_shipping' => false],
            'listing_type_id' => 'gold_special',
            'available_quantity' => 0,
        ], 0);

        $called = 0;
        $llm = new DashScopeClient('sk-test-not-real-key-for-unit', DashScopeClient::DEFAULT_BASE, static function () use (&$called): ?array {
            $called++;
            return null;
        });
        $result = (new ListingInvestigationService($db, $llm))->run(1335);
        self::assertSame(0, $called);
        self::assertCount(35, $result['investigated']);
        self::assertSame('rules', $result['model_key']);
        $mlbs = array_column($result['investigated'], 'mlb_id');
        self::assertNotContains('MLB-FALCAO', $mlbs);
        foreach ($result['investigated'] as $row) {
            self::assertSame('rules', $row['model_used']);
            self::assertFalse($row['published']);
        }
    }

    public function testDashScopeDoesNotHuntIntlThenCn(): void
    {
        $src = file_get_contents(dirname(__DIR__, 4) . '/app/Services/ListingInvestigation/DashScopeClient.php');
        self::assertIsString($src);
        self::assertStringContainsString('hasKnownWorkingModel', $src);
        self::assertStringContainsString('Never hunt intl', $src);
        self::assertStringNotContainsString('foreach ([self::INTL_BASE, self::CN_BASE]', $src);
        $client = new DashScopeClient('sk-test-not-real-key-for-unit');
        self::assertFalse($client->hasKnownWorkingModel());
    }

    public function testHardCaseDetectionDoesNotEnableLlm(): void
    {
        $itemHard = [
            'visits_30d' => 8,
            'sales_30d' => 0,
            'status' => 'active',
        ];
        $svc = new ListingInvestigationService($this->sqliteCatalog());
        self::assertTrue($svc->isHardCase($itemHard));
        self::assertFalse($svc->isHardCase(['visits_30d' => 0, 'sales_30d' => 0, 'status' => 'active']));
    }

    public function testPregaoSnapshotShowsAllOpenPortugueseLabelsAndIgnores1336(): void
    {
        $db = $this->sqliteCatalog();
        $svc = new ListingInvestigationService($db);
        $svc->ensureTable();
        for ($i = 1; $i <= 8; $i++) {
            $db->exec(
                "INSERT INTO listing_investigations (account_id, mlb_id, status, blockers, draft_title, draft_notes, model_used)
                 VALUES (1335, 'MLB-A{$i}', 'open', '[{\"code\":\"not_premium\",\"label\":\"sem Premium\"}]', 'Peça {$i}', 'x', 'rules')"
            );
        }
        $db->exec("INSERT INTO listing_investigations (account_id, mlb_id, status, blockers, draft_title, draft_notes, model_used)
                   VALUES (1336, 'MLB-FALCAO', 'open', '[{\"code\":\"stock_0\"}]', 'nope', 'x', 'rules')");
        $snap = $svc->pregaoSnapshot(1335);
        self::assertTrue($snap['apply_blocked']);
        self::assertFalse($snap['ml_write']);
        self::assertFalse($snap['published']);
        self::assertSame(8, $snap['count']);
        self::assertCount(8, $snap['items']);
        $mlbs = array_column($snap['items'], 'mlb');
        self::assertNotContains('MLB-FALCAO', $mlbs);
        self::assertTrue($snap['items'][0]['nao_publicado']);
        self::assertFalse($snap['items'][0]['published']);
    }

    public function testCatalogClassicAndPremiumLabels(): void
    {
        $svc = new ListingInvestigationService($this->sqliteCatalog());
        $blockers = $svc->officialBlockers([
            'pictures' => [1, 2],
            'available_quantity' => 1,
            'catalog_product_id' => 'MLB123',
            'catalog_listing' => false,
            'shipping' => ['free_shipping' => false],
            'listing_type_id' => 'gold_special',
        ]);
        $byCode = [];
        foreach ($blockers as $b) {
            $byCode[$b['code']] = $b['label'];
        }
        self::assertSame('menos de 3 fotos', $byCode['photos_lt3']);
        self::assertSame('catálogo no clássico', $byCode['catalog_not_listing']);
        self::assertSame('sem frete grátis', $byCode['no_free_shipping']);
        self::assertSame('sem Premium', $byCode['not_premium']);
    }

    public function testWorkerCliRulesFirstHighLimitReadOnly(): void
    {
        $worker = file_get_contents(dirname(__DIR__, 4) . '/bin/listing-investigation-worker.php');
        self::assertIsString($worker);
        self::assertStringContainsString('staging.eskill.com.br', $worker);
        self::assertStringContainsString('must not point at FACILYTY 1335', $worker);
        self::assertStringContainsString('apply_blocked=true', $worker);
        self::assertStringContainsString('--once', $worker);
        self::assertStringContainsString('default 50', $worker);
        self::assertStringContainsString('DASHSCOPE_MODEL_OK', $worker);
        self::assertStringContainsString('Alibaba not required', $worker);
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
