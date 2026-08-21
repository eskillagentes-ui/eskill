<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Catalog;

use App\Services\Catalog\ListingIrregularityScanService;
use App\Services\Catalog\MlListingReadClient;
use App\Services\Catalog\SalesBlockerStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ListingIrregularityScanServiceTest extends TestCase
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

    public function testScanPersistsPendingModerationAndResolvesMissing(): void
    {
        $this->store->upsert(7, ['item_id' => 'MLB1111111111', 'reason' => 'stale']);

        $client = new FakeMlListingReadClient();
        $client->pendingIds = ['MLB1234567890'];
        $client->moderations['MLB1234567890'] = [[
            'name' => 'POOR_QUALITY_THUMBNAIL',
            'id' => '99',
            'wordings' => [
                ['type' => 'REASON', 'value' => 'Foto de capa inválida'],
                ['type' => 'REMEDY', 'value' => 'Troque a thumbnail'],
            ],
        ]];
        $client->items['MLB1234567890'] = [
            'id' => 'MLB1234567890',
            'title' => 'Capacete',
            'status' => 'under_review',
            'sub_status' => ['waiting_for_patch'],
        ];

        $scanner = new ListingIrregularityScanService(
            $this->pdo,
            $this->store,
            static fn (int $accountId): MlListingReadClient => $client
        );

        $result = $scanner->scanAccount(7, 50, 'test');
        $this->assertSame(1, $result['upserted']);
        $this->assertSame(1, $result['resolved']);

        $open = $this->store->listOpen(7);
        $this->assertCount(1, $open);
        $this->assertSame('MLB1234567890', $open[0]['item_id']);
        $this->assertSame('Foto de capa inválida', $open[0]['reason']);
        $this->assertSame('Troque a thumbnail', $open[0]['remedy']);
        $this->assertSame('under_review', $open[0]['item_status']);
    }

    public function testFailedPendingSearchDoesNotResolveOpenRows(): void
    {
        $this->store->upsert(7, ['item_id' => 'MLB2222222222', 'reason' => 'keep']);
        $client = new FakeMlListingReadClient();
        $client->pendingError = 'rate_limited';

        $scanner = new ListingIrregularityScanService(
            $this->pdo,
            $this->store,
            static fn (int $accountId): MlListingReadClient => $client
        );

        $result = $scanner->scanAccount(7, 10, 'test');
        $this->assertSame(0, $result['resolved']);
        $this->assertCount(1, $this->store->listOpen(7));
    }

    public function testNumericOrInternalIdsAreSkippedWithoutTypeError(): void
    {
        $client = new FakeMlListingReadClient();
        $client->pendingIds = [1, 'MLB1234567890'];
        $client->infractions = [
            ['element_type' => 'ITM', 'element_id' => 1],
        ];
        $client->moderations['MLB1234567890'] = [[
            'name' => 'DUPLICATED',
            'wordings' => [
                ['type' => 'REASON', 'value' => 'É igual a outro anúncio.'],
            ],
        ]];
        $client->items['MLB1234567890'] = [
            'id' => 'MLB1234567890',
            'title' => 'Peça',
            'status' => 'inactive',
        ];

        $scanner = new ListingIrregularityScanService(
            $this->pdo,
            $this->store,
            static fn (int $accountId): MlListingReadClient => $client
        );

        $result = $scanner->scanAccount(7, 50, 'test');
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['upserted']);
        $this->assertSame('MLB1234567890', $this->store->listOpen(7)[0]['item_id']);
    }

    public function testPendingSearchPaginatesUntilCatalogCovered(): void
    {
        $client = new FakeMlListingReadClient();
        $ids = [];
        for ($i = 1; $i <= 80; $i++) {
            $id = sprintf('MLB%010d', $i);
            $ids[] = $id;
            $client->moderations[$id] = [['name' => 'TEST', 'wordings' => [['type' => 'REASON', 'value' => 'x']]]];
            $client->items[$id] = ['id' => $id, 'title' => 'Item', 'status' => 'pending'];
        }
        $client->pendingIds = $ids;

        $scanner = new ListingIrregularityScanService(
            $this->pdo,
            $this->store,
            static fn (int $accountId): MlListingReadClient => $client
        );

        $result = $scanner->scanAccount(7, 400, 'test');
        $this->assertSame(80, $result['upserted']);
        $this->assertGreaterThan(1, count($client->pendingCalls));
        $this->assertSame(0, $client->pendingCalls[0]['offset']);
        $this->assertSame(50, $client->pendingCalls[1]['offset']);
        $this->assertCount(80, $this->store->listOpen(7));
    }

    public function testScanAccountCapsLimitAtFourHundred(): void
    {
        $client = new FakeMlListingReadClient();
        $ids = [];
        for ($i = 1; $i <= 450; $i++) {
            $id = sprintf('MLB%010d', 1000 + $i);
            $ids[] = $id;
            $client->moderations[$id] = [['name' => 'TEST', 'wordings' => [['type' => 'REASON', 'value' => 'x']]]];
            $client->items[$id] = ['id' => $id, 'title' => 'Item', 'status' => 'pending'];
        }
        $client->pendingIds = $ids;

        $scanner = new ListingIrregularityScanService(
            $this->pdo,
            $this->store,
            static fn (int $accountId): MlListingReadClient => $client
        );
        $result = $scanner->scanAccount(7, 9999, 'test');
        $this->assertSame(400, $result['scanned']);
        $this->assertSame(400, $result['upserted']);
        $this->assertGreaterThan(1, count($client->pendingCalls));
    }
}

final class FakeMlListingReadClient implements MlListingReadClient
{
    /** @var list<int|string> */
    public array $pendingIds = [];

    /** @var list<array{offset: int, limit: int}> */
    public array $pendingCalls = [];

    public ?string $pendingError = null;

    /** @var array<string, array<string, mixed>|list<array<string, mixed>>> */
    public array $moderations = [];

    /** @var array<string, array<string, mixed>> */
    public array $items = [];

    /** @var list<array<string, mixed>> */
    public array $infractions = [];

    public function getMyItems(array $params = []): array
    {
        if ($this->pendingError !== null) {
            return ['error' => $this->pendingError, 'results' => []];
        }

        $offset = max(0, (int) ($params['offset'] ?? 0));
        $limit = max(1, (int) ($params['limit'] ?? 50));
        $this->pendingCalls[] = ['offset' => $offset, 'limit' => $limit];

        return [
            'results' => array_slice($this->pendingIds, $offset, $limit),
            'paging' => [
                'total' => count($this->pendingIds),
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    public function get(string $endpoint, array $params = []): array
    {
        if (str_contains($endpoint, '/items/')) {
            $id = strtoupper((string) substr($endpoint, strrpos($endpoint, '/') + 1));
            return $this->items[$id] ?? ['error' => 'not_found'];
        }
        throw new RuntimeException('GET inesperado: ' . $endpoint);
    }

    public function getMlUserId(): ?string
    {
        return '999';
    }

    public function getLastModeration(string $itemId): array
    {
        $id = strtoupper(trim($itemId));

        return $this->moderations[$id] ?? [];
    }

    public function getSellerInfractions(array $params = []): array
    {
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $limit = max(1, (int) ($params['limit'] ?? 20));
        $slice = array_slice($this->infractions, $offset, $limit);

        return [
            'infractions' => $slice,
            'paging' => ['total' => count($this->infractions), 'limit' => $limit, 'offset' => $offset],
        ];
    }
}
