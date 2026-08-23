<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

final class TechSheetDrawerViewTest extends TestCase
{
    public function testOpenDrawerStoresApiPayloadNotBareItemId(): void
    {
        $source = $this->viewSource();
        self::assertStringContainsString('this.state.currentItem = data;', $source);
        self::assertStringNotContainsString('this.state.currentItem = itemId;', $source);
        self::assertStringContainsString('currentDrawerItemId()', $source);
        self::assertStringContainsString("cur.indexOf('MLB') === 0", $source);
    }

    private function viewSource(): string
    {
        $path = dirname(__DIR__, 3) . '/app/Views/dashboard/tech-sheet/index.php';
        $contents = file_get_contents($path);
        self::assertNotFalse($contents);

        return $contents;
    }
}
