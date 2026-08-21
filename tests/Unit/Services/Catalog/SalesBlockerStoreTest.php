<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Catalog;

use App\Services\Catalog\SalesBlockerStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class SalesBlockerStoreTest extends TestCase
{
    private PDO $pdo;
    private SalesBlockerStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE ml_sales_blockers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                item_id TEXT NOT NULL,
                reason TEXT NULL,
                remedy TEXT NULL,
                filter_subgroup TEXT NULL,
                item_status TEXT NULL,
                sub_status_json TEXT NULL,
                infraction_id TEXT NULL,
                performance_json TEXT NULL,
                scanned_by TEXT NULL,
                scanned_at TEXT NOT NULL,
                resolved_at TEXT NULL,
                UNIQUE(account_id, item_id)
            )'
        );
        $this->store = new SalesBlockerStore($this->pdo);
    }

    public function testUpsertThenListOpen(): void
    {
        $this->store->upsert(10, [
            'item_id' => 'mlb123',
            'reason' => 'Foto ruim',
            'remedy' => 'Trocar thumbnail',
            'filter_subgroup' => 'PQT',
            'item_status' => 'under_review',
            'sub_status' => ['waiting_for_patch'],
            'scanned_by' => 'test',
        ]);

        $open = $this->store->listOpen(10);
        $this->assertCount(1, $open);
        $this->assertSame('MLB123', $open[0]['item_id']);
        $this->assertSame('Foto ruim', $open[0]['reason']);
        $this->assertNull($open[0]['resolved_at']);
    }

    public function testUpsertReopensResolvedRow(): void
    {
        $this->store->upsert(10, ['item_id' => 'MLB1', 'reason' => 'a']);
        $this->store->markResolvedIfMissing(10, []);
        $this->assertSame([], $this->store->listOpen(10));

        $this->store->upsert(10, ['item_id' => 'MLB1', 'reason' => 'b']);
        $open = $this->store->listOpen(10);
        $this->assertCount(1, $open);
        $this->assertSame('b', $open[0]['reason']);
    }

    public function testMarkResolvedIfMissingKeepsSeenItems(): void
    {
        $this->store->upsert(10, ['item_id' => 'MLB1', 'reason' => 'keep']);
        $this->store->upsert(10, ['item_id' => 'MLB2', 'reason' => 'drop']);

        $resolved = $this->store->markResolvedIfMissing(10, ['MLB1']);
        $this->assertSame(1, $resolved);

        $open = $this->store->listOpen(10);
        $this->assertCount(1, $open);
        $this->assertSame('MLB1', $open[0]['item_id']);
    }

    public function testListOpenIsAccountScoped(): void
    {
        $this->store->upsert(10, ['item_id' => 'MLB1', 'reason' => 'a']);
        $this->store->upsert(20, ['item_id' => 'MLB1', 'reason' => 'b']);

        $this->assertCount(1, $this->store->listOpen(10));
        $this->assertCount(1, $this->store->listOpen(20));
        $this->assertSame('a', $this->store->listOpen(10)[0]['reason']);
    }

    public function testSchemaReadyFalseWhenTableMissing(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $store = new SalesBlockerStore($pdo);

        $this->assertFalse($store->schemaReady());
        $this->assertSame([], $store->listOpen(10));
    }

    public function testListOpenOnMinimalSchemaWithoutScannedAt(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE ml_sales_blockers (
                account_id INTEGER,
                item_id TEXT,
                resolved_at TEXT
            )'
        );
        $pdo->exec("INSERT INTO ml_sales_blockers VALUES (10, 'MLB1', NULL)");
        $pdo->exec("INSERT INTO ml_sales_blockers VALUES (10, 'MLB2', '2026-08-01 00:00:00')");
        $pdo->exec("INSERT INTO ml_sales_blockers VALUES (20, 'MLB9', NULL)");

        $store = new SalesBlockerStore($pdo);
        $this->assertTrue($store->schemaReady());
        $open = $store->listOpen(10);
        $this->assertCount(1, $open);
        $this->assertSame('MLB1', $open[0]['item_id']);
    }

    public function testListOpenAndUpsertOnLegacyProductionSchema(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE ml_sales_blockers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                item_id TEXT NOT NULL,
                queue TEXT NOT NULL DEFAULT \'urgent\',
                source_status TEXT NOT NULL DEFAULT \'unknown\',
                severity TEXT NOT NULL DEFAULT \'high\',
                reason TEXT NULL,
                remedy TEXT NULL,
                wordings_json TEXT NULL,
                performance_json TEXT NULL,
                scanned_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                resolved_at TEXT NULL,
                UNIQUE(account_id, item_id, queue)
            )'
        );
        $store = new SalesBlockerStore($pdo);
        $store->upsert(10, [
            'item_id' => 'mlb999',
            'reason' => 'Catálogo',
            'remedy' => 'Vincular',
            'item_status' => 'under_review',
            'filter_subgroup' => 'OPT_OBEY',
        ]);

        $open = $store->listOpen(10);
        $this->assertCount(1, $open);
        $this->assertSame('MLB999', $open[0]['item_id']);
        $this->assertSame('Catálogo', $open[0]['reason']);
        $this->assertSame('under_review', $open[0]['item_status']);
        $this->assertSame('OPT_OBEY', $open[0]['filter_subgroup']);
        $this->assertNull($open[0]['resolved_at']);
    }
}
