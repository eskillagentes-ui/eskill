<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ItemSyncService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\ItemSyncService
 */
class ItemSyncServiceDataMergeTest extends TestCase
{
    public function testMergeKeepsPerformanceScoreWhenIncomingMlJsonOmitsIt(): void
    {
        $existing = json_encode([
            'id' => 'MLB123',
            'title' => 'Old title',
            'performance_score' => 77,
            'performance_level' => 'good',
            'performance_level_wording' => 'Bom',
            'performance_updated_at' => '2026-08-21 12:00:00',
            'seo_cache' => ['foo' => 'bar'],
        ], JSON_UNESCAPED_UNICODE);

        $incoming = [
            'id' => 'MLB123',
            'title' => 'New from ML',
            'price' => 99.9,
            'status' => 'active',
        ];

        $saved = ItemSyncService::mergeItemDataJson($incoming, $existing);
        $decoded = json_decode($saved, true);

        $this->assertIsArray($decoded);
        $this->assertSame(77, $decoded['performance_score']);
        $this->assertSame('good', $decoded['performance_level']);
        $this->assertSame('Bom', $decoded['performance_level_wording']);
        $this->assertSame('2026-08-21 12:00:00', $decoded['performance_updated_at']);
        $this->assertSame(['foo' => 'bar'], $decoded['seo_cache']);
        $this->assertSame('New from ML', $decoded['title']);
        $this->assertSame(99.9, $decoded['price']);
        $this->assertArrayNotHasKey('error', $decoded);
    }

    public function testMergeLetsIncomingExplicitPerformanceScoreWin(): void
    {
        $existing = json_encode(['performance_score' => 77], JSON_UNESCAPED_UNICODE);
        $incoming = [
            'id' => 'MLB123',
            'title' => 'From ML',
            'performance_score' => 40,
        ];

        $saved = json_decode(ItemSyncService::mergeItemDataJson($incoming, $existing), true);

        $this->assertIsArray($saved);
        $this->assertSame(40, $saved['performance_score']);
        $this->assertSame('From ML', $saved['title']);
    }

    public function testSyncToItemsTableSourceMergesLocalDataInsteadOfBlindReplace(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/../app/Services/ItemSyncService.php'
        );

        $this->assertStringContainsString('mergeItemDataJson', $source);
        $this->assertStringContainsString('loadExistingItemDataJsonByMlIds', $source);
        $this->assertStringContainsString('data = VALUES(data)', $source);
        $this->assertStringNotContainsString("':data' => json_encode(\$itemData)", $source);
    }
}
