<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ItemPerformanceSyncWorkerTest extends TestCase
{
    private function scriptPath(): string
    {
        return dirname(__DIR__, 3) . '/bin/item-performance-sync-worker.php';
    }

    public function testHelpRunsWithoutDatabase(): void
    {
        $script = $this->scriptPath();
        $this->assertFileExists($script);

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help 2>&1', $lines, $exitCode);
        $text = implode("\n", $lines);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('--account=ID', $text);
        $this->assertStringContainsString('--limit', $text);
        $this->assertStringContainsString('padrão: 120', $text);
    }

    public function testCliIsReadOnlyGetPerformanceAndPrefersActive(): void
    {
        $src = (string) file_get_contents($this->scriptPath());
        $this->assertStringContainsString('ItemPerformanceService', $src);
        $this->assertStringContainsString('getItemPerformance', $src);
        $this->assertStringContainsString("status = 'active'", $src);
        $this->assertStringContainsString('usleep($delayUs)', $src);
        $this->assertStringContainsString('250000', $src);
        $this->assertStringContainsString('? (int)$options[\'limit\'] : 120', $src);
        $this->assertStringContainsString('$maxRuntimeSeconds = 110', $src);
        $this->assertStringNotContainsString('getMultiItemVisits', $src);
        $this->assertStringNotContainsString('->put(', $src);
        $this->assertStringNotContainsString('->post(', $src);
        $this->assertStringNotContainsString('->delete(', $src);
        $this->assertStringNotContainsString('MLWriteGateway', $src);
        $this->assertStringNotContainsString('ML_WRITE', $src);
    }
}
