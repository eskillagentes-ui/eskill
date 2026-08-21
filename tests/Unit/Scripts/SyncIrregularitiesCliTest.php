<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class SyncIrregularitiesCliTest extends TestCase
{
    public function testHelpRunsWithoutDatabase(): void
    {
        $script = dirname(__DIR__, 3) . '/bin/sync-irregularities.php';
        $this->assertFileExists($script);

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help 2>&1', $lines, $exitCode);
        $text = implode("\n", $lines);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('--all-active', $text);
        $this->assertStringContainsString('--actor-user-id', $text);
        $this->assertStringContainsString('--limit', $text);
        $this->assertStringContainsString('padrão: 400', $text);
    }

    public function testCliIsReadOnlyAndUsesSec001(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/sync-irregularities.php');
        $this->assertStringContainsString('OwnerAccountAccessPolicy', $src);
        $this->assertStringContainsString('authorizeWorker', $src);
        $this->assertStringContainsString('ListingIrregularityScanService', $src);
        $this->assertStringNotContainsString('->put(', $src);
        $this->assertStringNotContainsString('->post(', $src);
        $this->assertStringNotContainsString('->delete(', $src);
        $this->assertStringNotContainsString('MLWriteGateway', $src);
    }

    public function testMissingAccountFlagFailsBeforeScan(): void
    {
        $script = dirname(__DIR__, 3) . '/bin/sync-irregularities.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --limit=1 2>&1', $lines, $exitCode);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--account=ID ou --all-active', implode("\n", $lines));
    }
}
