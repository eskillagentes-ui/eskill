<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoWatchlistCollector;
use PHPUnit\Framework\TestCase;

final class PregaoWatchlistCollectorTest extends TestCase
{
    public function testCatalogIdFromApelido(): void
    {
        $this->assertSame(
            'MLB74774133',
            PregaoWatchlistCollector::catalogIdFromApelido('MLB7209201812 (catálogo MLB74774133)')
        );
        $this->assertSame(
            'MLB61442262',
            PregaoWatchlistCollector::catalogIdFromApelido('MLB6722976368 (catalogo MLB61442262)')
        );
        $this->assertNull(PregaoWatchlistCollector::catalogIdFromApelido('MLB1406414243'));
        $this->assertNull(PregaoWatchlistCollector::catalogIdFromApelido(null));
    }

    public function testItemBodyFromProductRowKeepsPriceOmitsListingStatus(): void
    {
        $body = PregaoWatchlistCollector::itemBodyFromProductRow([
            'item_id' => 'MLB7160611456',
            'seller_id' => 1206897246,
            'price' => 99.99,
            'condition' => 'new',
            'status' => '403',
        ]);
        $this->assertIsArray($body);
        $this->assertSame('MLB7160611456', $body['id']);
        $this->assertSame(99.99, $body['price']);
        $this->assertArrayNotHasKey('status', $body);
        $this->assertArrayNotHasKey('condition', $body);
        $this->assertNull(PregaoWatchlistCollector::itemBodyFromProductRow(['item_id' => 'x']));
    }

    public function testUsableItemBodyRejectsAccessDenied(): void
    {
        $this->assertFalse(PregaoWatchlistCollector::isUsableItemBody([
            'id' => 'MLB1406414243',
            'error' => 'access_denied',
            'status' => 403,
        ]));
        $this->assertTrue(PregaoWatchlistCollector::isUsableItemBody([
            'id' => 'MLB1406414243',
            'price' => 10.5,
            'status' => 'active',
        ]));
    }

    public function testCollectFallsBackToCatalogAndDoesNotEnableRankOrSeed(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Pregao/PregaoWatchlistCollector.php');
        $this->assertStringContainsString('/products/', $src);
        $this->assertStringContainsString('lookupViaCatalog', $src);
        $this->assertStringContainsString('SET price = COALESCE(?, price)', $src);
        $this->assertStringNotContainsString('RANK_TRACKER', $src);
        $this->assertStringNotContainsString('seedFromKeywords($accountId', $src);
        $this->assertStringNotContainsString('MLWriteGateway', $src);
        $this->assertStringNotContainsString('ML_WRITE', $src);
    }
}
