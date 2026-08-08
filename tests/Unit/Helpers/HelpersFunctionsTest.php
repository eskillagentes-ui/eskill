<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

final class HelpersFunctionsTest extends TestCase
{
    public function testDeduplicateItemsNormalizesCaseAndWhitespaceAndPreservesOrder(): void
    {
        $items = ['Alpha', ' alpha ', 'BETA', 'beta', 'Gamma', ''];

        $result = deduplicate_items($items);

        $this->assertSame(['Alpha', 'BETA', 'Gamma'], $result);
    }

    public function testDeduplicateItemsAcceptsCustomNormalizer(): void
    {
        $items = [
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'a'],
            ['id' => 3, 'name' => 'B'],
        ];

        $result = deduplicate_items($items, static function (array $item): string {
            return (string) ($item['name'] ?? '');
        });

        $this->assertSame([
            ['id' => 1, 'name' => 'A'],
            ['id' => 3, 'name' => 'B'],
        ], $result);
    }

    public function testDeduplicateItemsCanKeepTheLastOccurrence(): void
    {
        $items = ['alpha', 'beta', 'ALPHA'];

        $result = deduplicate_items($items, null, false);

        $this->assertSame(['beta', 'ALPHA'], $result);
    }
}
