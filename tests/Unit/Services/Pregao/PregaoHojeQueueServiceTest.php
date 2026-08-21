<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\AccountIndexCalculator;
use App\Services\Pregao\PregaoHojeQueueService;
use App\Services\Pregao\PregaoSnapshotService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Pregao\PregaoHojeQueueService
 * @covers \App\Services\Pregao\PregaoSnapshotService
 */
final class PregaoHojeQueueServiceTest extends TestCase
{
    public function testContaAusenteNaoInventaFilaNemTravadaNemDinheiro(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $queue = (new PregaoHojeQueueService($db))->build(0);

        self::assertTrue($queue['read_only']);
        self::assertTrue($queue['apply_blocked']);
        self::assertFalse($queue['ml_write']);
        self::assertSame('local', $queue['source']);
        self::assertSame(0, $queue['open_count']);
        self::assertSame(PregaoHojeQueueService::BUCKETS, array_column($queue['items'], 'id'));
        foreach ($queue['items'] as $item) {
            self::assertFalse($item['available']);
            self::assertSame('nd', $item['severity']);
            self::assertTrue($item['apply_blocked']);
            self::assertSame(0, $item['count']);
        }
        $json = json_encode($queue, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('TRAVADA', $json);
        self::assertStringNotContainsString('8,7', $json);
        self::assertStringNotContainsString('R$', $json);
        self::assertStringNotContainsString('access_token', $json);
        self::assertStringNotContainsString('APP_USR', $json);
    }

    public function testTabelaAusenteEFailSoftNdNaoZeroOk(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $queue = (new PregaoHojeQueueService($db))->build(1335);
        $byId = $this->byId($queue);

        self::assertFalse($byId['visits_no_sales']['available']);
        self::assertSame('nd', $byId['visits_no_sales']['severity']);
        self::assertNotSame('ok', $byId['visits_no_sales']['severity']);
        self::assertFalse($byId['ficha']['available']);
        self::assertSame('nd', $byId['ficha']['severity']);
        self::assertFalse($byId['cmv']['available']);
        self::assertSame('nd', $byId['cmv']['severity']);
        self::assertSame(0, $queue['open_count']);
        self::assertTrue($queue['apply_blocked']);
        $json = json_encode($queue, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('TRAVADA', $json);
        self::assertStringNotContainsString('R$', $json);
    }

    public function testVisitasSemVendaUsaPerformanceLocalEIgnoraContaVizinha(): void
    {
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 1335, 'MLB-VISITS', 'active', 0, [
            'visits_30d' => 40,
            'sales_30d' => 0,
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
        ]);
        $this->insertItem($db, 1335, 'MLB-SOLD', 'active', 4, [
            'visits_30d' => 10,
            'sales_30d' => 2,
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
        ]);
        $this->insertItem($db, 1335, 'MLB-PENDING', 'active', 0, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
        ]);
        $this->insertItem($db, 1336, 'MLB-FALCAO', 'active', 0, [
            'visits_30d' => 999,
            'sales_30d' => 0,
            'pictures' => [1],
            'shipping' => ['free_shipping' => false],
        ]);

        $queue = (new PregaoHojeQueueService($db))->build(1335);
        $byId = $this->byId($queue);

        self::assertSame(1, $byId['visits_no_sales']['count']);
        self::assertSame('alto', $byId['visits_no_sales']['severity']);
        self::assertTrue($byId['visits_no_sales']['available']);
        self::assertStringContainsString('visitas n/d', $byId['visits_no_sales']['hint']);
        self::assertDoesNotMatchRegularExpression('/999/', $byId['visits_no_sales']['hint']);
    }

    public function testVisitasUnderscoreKeyCountsAndUnknownIsNotZero(): void
    {
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 77, 'MLB-U', 'active', 0, [
            '_visits_30d' => 12,
            '_sales_30d' => 0,
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
        ]);
        $this->insertItem($db, 77, 'MLB-NONE', 'active', 0, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
        ]);

        $visits = $this->byId((new PregaoHojeQueueService($db))->build(77))['visits_no_sales'];
        self::assertSame(1, $visits['count']);
        self::assertStringContainsString('1 visitas n/d', $visits['hint']);
    }

    public function testFichaGapsMatchSeoKillerPhotosShippingCatalog(): void
    {
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 77, 'MLB-PHOTO', 'active', 0, [
            'pictures' => [1, 2],
            'shipping' => ['free_shipping' => true],
        ]);
        $this->insertItem($db, 77, 'MLB-OK', 'active', 0, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
        ]);
        $this->insertItem($db, 77, 'MLB-SHIP', 'active', 0, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => false],
        ]);
        $this->insertItem($db, 77, 'MLB-CAT', 'active', 0, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
            'catalog_product_id' => 'MLB123',
            'catalog_listing' => false,
        ], 'MLB123');
        $this->insertItem($db, 77, 'MLB-CLOSED', 'closed', 0, [
            'pictures' => [1],
            'shipping' => ['free_shipping' => false],
        ]);
        $this->insertItem($db, 99, 'MLB-OTHER', 'active', 0, [
            'pictures' => [1],
            'shipping' => ['free_shipping' => false],
        ]);

        $ficha = $this->byId((new PregaoHojeQueueService($db))->build(77))['ficha'];
        self::assertSame(3, $ficha['count']);
        self::assertSame('alto', $ficha['severity']);
        self::assertTrue($ficha['apply_blocked']);
        self::assertSame('/dashboard/seo-killer#technical-sheet', $ficha['href']);
        self::assertStringContainsString('fotos<3', $ficha['hint']);
        self::assertStringContainsString('frete', $ficha['hint']);
        self::assertStringContainsString('catálogo', $ficha['hint']);
        self::assertStringContainsString('sem apply', $ficha['hint']);
    }

    public function testCmvCountsSoldMissingAsNdNeverZeroCost(): void
    {
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 77, 'MLB-SOLD-GAP', 'active', 3, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
            'sales_30d' => 3,
        ]);
        $this->insertItem($db, 77, 'MLB-SOLD-OK', 'active', 2, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
            'sales_30d' => 2,
        ]);
        $this->insertItem($db, 77, 'MLB-UNSOLD', 'active', 0, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
            'sales_30d' => 0,
            'visits_30d' => 5,
        ]);
        $db->exec("INSERT INTO sku_custos (account_id, mlb_id, custo_produto) VALUES (77, 'MLB-SOLD-OK', 12.5)");
        $db->exec("INSERT INTO sku_custos (account_id, mlb_id, custo_produto) VALUES (77, 'MLB-SOLD-GAP', 0)");
        $db->exec("INSERT INTO sku_custos (account_id, mlb_id, custo_produto) VALUES (99, 'MLB-OTHER', 9)");

        $cmv = $this->byId((new PregaoHojeQueueService($db))->build(77))['cmv'];
        self::assertSame(1, $cmv['count']);
        self::assertSame('alto', $cmv['severity']);
        self::assertTrue($cmv['available']);
        self::assertStringContainsString('n/d', $cmv['hint']);
        self::assertStringNotContainsString('R$', $cmv['hint']);
        self::assertTrue($cmv['apply_blocked']);
    }

    public function testCmvUsesMlOrdersAndDoesNotMixAccounts(): void
    {
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 1335, 'MLB-LOCAL', 'active', 0, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
        ]);
        $orderFacilyty = json_encode([
            'order_items' => [['item' => ['id' => 'MLB-ORDER-1335'], 'quantity' => 1]],
        ], JSON_THROW_ON_ERROR);
        $orderFalcao = json_encode([
            'order_items' => [['item' => ['id' => 'MLB-ORDER-1336'], 'quantity' => 8]],
        ], JSON_THROW_ON_ERROR);
        $db->exec("INSERT INTO ml_orders (ml_account_id, account_id, order_data) VALUES (1335, 1335, '{$orderFacilyty}')");
        $db->exec("INSERT INTO ml_orders (ml_account_id, account_id, order_data) VALUES (1336, 1336, '{$orderFalcao}')");
        $db->exec("INSERT INTO sku_custos (account_id, mlb_id, custo_produto) VALUES (1336, 'MLB-ORDER-1336', 50)");

        $cmv = $this->byId((new PregaoHojeQueueService($db))->build(1335))['cmv'];
        self::assertSame(1, $cmv['count']);
        self::assertSame('critico', $cmv['severity']);
        $json = json_encode($cmv, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('MLB-ORDER-1336', $json);
        self::assertStringNotContainsString('TRAVADA', $json);
    }

    public function testSkuCustosAusenteNaoInventaCmvZero(): void
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
        $this->insertItem($db, 77, 'MLB-SOLD', 'active', 2, [
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
        ]);

        $cmv = $this->byId((new PregaoHojeQueueService($db))->build(77))['cmv'];
        self::assertFalse($cmv['available']);
        self::assertSame('nd', $cmv['severity']);
        self::assertSame(0, $cmv['count']);
        self::assertStringContainsString('n/d', $cmv['hint']);
    }

    public function testPausedClosedItemsStayOutOfVisitsAndFicha(): void
    {
        $db = $this->sqliteCatalog();
        $this->insertItem($db, 77, 'MLB-PAUSED', 'paused', 0, [
            'visits_30d' => 80,
            'sales_30d' => 0,
            'pictures' => [1],
            'shipping' => ['free_shipping' => false],
        ]);
        $this->insertItem($db, 77, 'MLB-ACTIVE-OK', 'active', 0, [
            'visits_30d' => 0,
            'sales_30d' => 0,
            'pictures' => [1, 2, 3],
            'shipping' => ['free_shipping' => true],
        ]);

        $byId = $this->byId((new PregaoHojeQueueService($db))->build(77));
        self::assertSame(0, $byId['visits_no_sales']['count']);
        self::assertSame('ok', $byId['visits_no_sales']['severity']);
        self::assertSame(0, $byId['ficha']['count']);
        self::assertSame('ok', $byId['ficha']['severity']);
    }

    public function testSourceIsLocalAndDoesNotCallMl(): void
    {
        $src = file_get_contents(dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoHojeQueueService.php');
        self::assertIsString($src);
        self::assertStringContainsString("source' => 'local'", $src);
        self::assertStringNotContainsString('ItemService', $src);
        self::assertStringNotContainsString('MercadoLivre', $src);
        self::assertStringNotContainsString('auto-pause', $src);
        self::assertStringNotContainsString('auto-reprice', $src);
        self::assertStringNotContainsString('PATCH', $src);
        self::assertStringContainsString('ml_write', $src);
    }

    public function testSnapshotExpoeHojeComTresBaldesSemTravada(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(
            'CREATE TABLE account_index_metrics (
                account_id INTEGER PRIMARY KEY,
                vendas_7d REAL,
                visitas_7d REAL,
                health_medio REAL,
                reputacao_cor TEXT,
                tacos REAL,
                indice_atual REAL,
                metrics_meta TEXT,
                updated_at TEXT DEFAULT \'2026-08-21 15:00:00\'
            )'
        );
        $db->exec(
            'CREATE TABLE account_index_baselines (
                account_id INTEGER PRIMARY KEY,
                vendas_7d_baseline REAL,
                pos_baseline REAL,
                visitas_baseline REAL,
                tacos_baseline REAL
            )'
        );
        $db->exec(
            'CREATE TABLE account_index_daily (
                account_id INTEGER,
                `date` TEXT,
                o REAL, h REAL, l REAL, c REAL,
                updated_at TEXT DEFAULT \'2026-08-21 15:00:00\'
            )'
        );
        $db->exec(
            'CREATE TABLE pregao_events (
                account_id INTEGER, type TEXT, ts TEXT, payload TEXT, source TEXT
            )'
        );
        $meta = json_encode([
            'available' => ['Fv' => true],
            'metrics' => ['vendas_7d' => ['available' => true]],
        ], JSON_THROW_ON_ERROR);
        $db->prepare(
            'INSERT INTO account_index_metrics
             (account_id, vendas_7d, visitas_7d, health_medio, reputacao_cor, tacos, indice_atual, metrics_meta)
             VALUES (88, 11, 0, 0, NULL, NULL, NULL, ?)'
        )->execute([$meta]);
        $db->exec(
            'INSERT INTO account_index_baselines
             (account_id, vendas_7d_baseline, pos_baseline, visitas_baseline, tacos_baseline)
             VALUES (88, 10, 10, 1, 10)'
        );

        $snapshot = (new PregaoSnapshotService(
            $db,
            new AccountIndexCalculator(),
            ['seed_enabled' => false, 'rank_tracker_enabled' => false]
        ))->getSnapshot(88);

        self::assertArrayHasKey('hoje', $snapshot);
        self::assertTrue($snapshot['hoje']['apply_blocked']);
        self::assertTrue($snapshot['hoje']['read_only']);
        self::assertSame('local', $snapshot['hoje']['source']);
        self::assertCount(3, $snapshot['hoje']['items']);
        self::assertSame(PregaoHojeQueueService::BUCKETS, array_column($snapshot['hoje']['items'], 'id'));
        self::assertTrue($snapshot['read_only']);
        $json = json_encode($snapshot['hoje'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('TRAVADA', $json);
        self::assertStringNotContainsString('R$', $json);
        self::assertSame(88, $snapshot['account_id']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $queue): array
    {
        $byId = [];
        foreach ($queue['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        return $byId;
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
        $db->exec(
            'CREATE TABLE sku_custos (
                account_id INTEGER,
                mlb_id TEXT,
                custo_produto REAL
            )'
        );
        $db->exec(
            'CREATE TABLE ml_orders (
                ml_account_id INTEGER,
                account_id INTEGER,
                order_data TEXT
            )'
        );

        return $db;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertItem(
        PDO $db,
        int $accountId,
        string $mlb,
        string $status,
        int $sold,
        array $data,
        ?string $catalog = null
    ): void {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $stmt = $db->prepare(
            'INSERT INTO items (account_id, ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $mlb, $mlb, $status, $sold, $catalog, $json]);
    }
}
