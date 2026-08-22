<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ListingApply;

use App\Services\HiddenSeo\SafetyGuard;
use App\Services\ListingApply\ListingApplyJobService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\ListingApply\ListingApplyJobService
 */
final class ListingApplyJobServiceTest extends TestCase
{
    public function testDryRunPersistsWithoutCallingPutter(): void
    {
        $db = $this->sqlite();
        $this->insertMirror($db, 1335, 'MLB1234567890');
        $called = 0;
        $svc = new ListingApplyJobService($db, new SafetyGuard(true, [1335]), static function () use (&$called) {
            $called++;
            return ['success' => true, 'api_called' => true];
        });
        $row = $svc->run(1335, 'MLB1234567890', false);
        self::assertSame(0, $called);
        self::assertSame(ListingApplyJobService::STATUS_DRY_RUN, $row['status']);
        self::assertFalse($row['ml_write']);
        self::assertFalse($row['api_called']);
        self::assertArrayHasKey('title', $row['payload']);
        self::assertSame('Espelho 100x40', $row['payload']['title']);
        self::assertArrayNotHasKey('listing_type_id', $row['payload']);
        self::assertArrayNotHasKey('original_price', $row['payload']);
        self::assertArrayNotHasKey('shipping', $row['payload']);
        self::assertSame(1, (int) $db->query('SELECT COUNT(*) FROM listing_apply_jobs')->fetchColumn());
        self::assertSame('dry_run', (string) $db->query('SELECT status FROM listing_apply_jobs')->fetchColumn());
    }

    public function testCatalogListingOnlyWhenOfficialCatalogIdExists(): void
    {
        $db = $this->sqlite();
        $this->insertMirror($db, 1335, 'MLB1234567891', 'MLB-CAT-1', false);
        $svc = new ListingApplyJobService($db, new SafetyGuard(true, [1335]));
        $row = $svc->run(1335, 'MLB1234567891', false);
        self::assertTrue($row['payload']['catalog_listing']);
        $this->insertMirror($db, 1335, 'MLB1234567892', null, false);
        $row2 = $svc->run(1335, 'MLB1234567892', false);
        self::assertArrayNotHasKey('catalog_listing', $row2['payload']);
    }

    public function testApplyWithoutAutomationDoesNotPut(): void
    {
        putenv('ML_WRITE_AUTOMATION=false');
        $_ENV['ML_WRITE_AUTOMATION'] = 'false';
        $db = $this->sqlite();
        $this->insertMirror($db, 1335, 'MLB1234567890');
        $called = 0;
        $svc = new ListingApplyJobService($db, new SafetyGuard(true, [1335]), static function () use (&$called) {
            $called++;
            return ['success' => true, 'api_called' => true];
        });
        $row = $svc->run(1335, 'MLB1234567890', true);
        self::assertSame(0, $called);
        self::assertSame(ListingApplyJobService::STATUS_BLOCKED, $row['status']);
        self::assertSame('ml_write_automation_false', $row['blocked_by']);
        self::assertFalse($row['ml_write']);
        self::assertFalse($row['api_called']);
    }

    public function testApplyAllowlistedPutsOnlyAllowedFields(): void
    {
        putenv('ML_WRITE_AUTOMATION=true');
        $_ENV['ML_WRITE_AUTOMATION'] = 'true';
        $db = $this->sqlite();
        $this->insertMirror($db, 1335, 'MLB1234567890', 'MLB-CAT-9', false);
        $seen = null;
        $svc = new ListingApplyJobService($db, new SafetyGuard(true, [1335]), static function (int $acc, string $mlb, array $payload) use (&$seen) {
            $seen = ['acc' => $acc, 'mlb' => $mlb, 'payload' => $payload];
            return ['success' => true, 'api_called' => true];
        });
        $row = $svc->run(1335, 'MLB1234567890', true);
        self::assertSame(ListingApplyJobService::STATUS_APPLIED, $row['status']);
        self::assertTrue($row['ml_write']);
        self::assertSame(1335, $seen['acc']);
        self::assertSame(['title', 'catalog_listing'], array_keys($seen['payload']));
        self::assertArrayNotHasKey('listing_type_id', $seen['payload']);
        putenv('ML_WRITE_AUTOMATION=false');
        $_ENV['ML_WRITE_AUTOMATION'] = 'false';
    }

    public function testInvalidMlbBatchRejected(): void
    {
        $db = $this->sqlite();
        $svc = new ListingApplyJobService($db, new SafetyGuard(true, [1335]));
        $row = $svc->run(1335, 'MLB1,MLB2', true);
        self::assertSame(ListingApplyJobService::STATUS_BLOCKED, $row['status']);
        self::assertFalse($row['ml_write']);
    }

    public function testCliRejectsBatchAndHasNoToxico(): void
    {
        $cli = (string) file_get_contents(dirname(__DIR__, 4) . '/bin/listing-apply-job.php');
        self::assertStringContainsString('lote sem allowlist falha', $cli);
        self::assertStringContainsString('MLWriteGateway', $cli);
        self::assertStringContainsString('--apply', $cli);
        self::assertStringNotContainsString('TOXICO', $cli);
        self::assertStringNotContainsString('apply-recovery', $cli);
        self::assertStringNotContainsString('POST /answers', $cli);
        self::assertStringNotContainsString('gold_pro', $cli);
    }

    public function testPregaoSimulateHasNoHiddenApplyPut(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 4) . '/public/js/pregao.js');
        $routes = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Routes/api/pregao.php');
        $ctrl = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/PregaoController.php');
        self::assertStringContainsString('listing-apply/simulate', $js);
        self::assertStringContainsString('listing-apply/simulate', $routes);
        self::assertStringContainsString('listing-apply/apply', $routes);
        self::assertStringContainsString('runFromWeb', $ctrl);
        self::assertStringContainsString('web_apply_requires_cli_flag', $ctrl);
        self::assertStringNotContainsString('PUT /items', $js);
        self::assertStringContainsString('Dry-run (sem PUT)', $js);
        self::assertStringContainsString('Sem PUT (flag so no CLI --apply)', $js);
    }

    public function testStuffedModelNeverInPayload(): void
    {
        $db = $this->sqlite();
        $this->insertMirror($db, 1335, 'MLB1234567890');
        $svc = new ListingApplyJobService($db, new SafetyGuard(true, [1335]));
        $row = $svc->run(1335, 'MLB1234567890', false);
        $title = (string) ($row['payload']['title'] ?? '');
        self::assertNotSame('', $title);
        self::assertStringNotContainsString('espelho quarto', strtolower($title));
        self::assertStringNotContainsString('espelho sala', strtolower($title));
        self::assertStringNotContainsString('nao tem', strtolower($title));
        $json = json_encode($row['payload'], JSON_UNESCAPED_UNICODE);
        self::assertIsString($json);
        self::assertStringNotContainsString('espelho quarto, espelho sala', $json);
        self::assertArrayNotHasKey('attributes', $row['payload']);
        self::assertArrayNotHasKey('original_price', $row['payload']);
    }

    public function testApplyWithoutMlbAllowlistFails(): void
    {
        $db = $this->sqlite();
        $called = 0;
        $svc = new ListingApplyJobService($db, new SafetyGuard(true, [1335]), static function () use (&$called) {
            $called++;
            return ['success' => true, 'api_called' => true];
        });
        $row = $svc->run(1335, '', true);
        self::assertSame(0, $called);
        self::assertSame(ListingApplyJobService::STATUS_BLOCKED, $row['status']);
        self::assertSame('apply_requires_mlb_allowlist', $row['blocked_by']);
        self::assertFalse($row['ml_write']);
    }

    public function testWebApplyNeverPutsEvenIfRequested(): void
    {
        $db = $this->sqlite();
        $this->insertMirror($db, 1335, 'MLB1234567890');
        $called = 0;
        $svc = new ListingApplyJobService($db, new SafetyGuard(true, [1335]), static function () use (&$called) {
            $called++;
            return ['success' => true, 'api_called' => true];
        });
        $row = $svc->runFromWeb(1335, 'MLB1234567890', true);
        self::assertSame(0, $called);
        self::assertSame(ListingApplyJobService::STATUS_BLOCKED, $row['status']);
        self::assertSame('web_apply_requires_cli_flag', $row['blocked_by']);
        self::assertFalse($row['ml_write']);
        self::assertFalse($row['api_called']);
    }

    public function testDoesNotMixAccount1336Item(): void
    {
        $db = $this->sqlite();
        $this->insertMirror($db, 1336, 'MLB1234567890');
        $svc = new ListingApplyJobService($db, new SafetyGuard(true, [1335]));
        $row = $svc->run(1335, 'MLB1234567890', false);
        self::assertSame('item_not_found_local', $row['blocked_by']);
    }

    /**
     * @param PDO $db
     */
    private function insertMirror(PDO $db, int $accountId, string $mlb, ?string $catalogId = null, bool $catalogListing = false): void
    {
        $data = [
            'title' => 'Espelho Para Colar Guarda Roupas 100x40',
            'domain_id' => 'MLB-MIRRORS',
            'catalog_listing' => $catalogListing,
            'catalog_product_id' => $catalogId,
            'attributes' => [
                ['id' => 'BRAND', 'value_name' => 'Espelho'],
                ['id' => 'MODEL', 'value_name' => 'espelho quarto, espelho sala'],
                ['id' => 'HEIGHT', 'value_name' => '1 m'],
                ['id' => 'WIDTH', 'value_name' => '40 cm'],
                ['id' => 'VOLTAGE', 'value_name' => 'nao tem'],
            ],
        ];
        $stmt = $db->prepare(
            'INSERT INTO items (account_id, ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data)
             VALUES (?, ?, ?, ?, 2, 0, ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $mlb,
            $data['title'],
            'active',
            $catalogId,
            json_encode($data, JSON_THROW_ON_ERROR),
        ]);
    }

    private function sqlite(): PDO
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
