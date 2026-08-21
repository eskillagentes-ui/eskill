<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class PregaoWatchlistCliTest extends TestCase
{
    private function scriptPath(): string
    {
        return dirname(__DIR__, 3) . '/bin/pregao-watchlist.php';
    }

    public function testCollectIsReadOnlyAndFailSoft(): void
    {
        $src = (string) file_get_contents($this->scriptPath());
        $this->assertStringContainsString('--collect', $src);
        $this->assertStringContainsString('PregaoWatchlistCollector', $src);
        $this->assertStringContainsString('$service->collect($accountId)', $src);
        $this->assertDoesNotMatchRegularExpression('/exit\(\$result\[\'errors\'\] === \[\] \? 0 : 2\)/', $src);
        $this->assertMatchesRegularExpression('/isset\(\$opts\[\'collect\'\]\).*exit\(0\);/s', $src);
        $this->assertStringContainsString('account-id inválido', $src);
        $this->assertStringContainsString('exit(2)', $src);
        $this->assertStringNotContainsString('->put(', $src);
        $this->assertStringNotContainsString('->post(', $src);
        $this->assertStringNotContainsString('->delete(', $src);
        $this->assertStringNotContainsString('MLWriteGateway', $src);
        $this->assertStringNotContainsString('ML_WRITE', $src);
        $this->assertStringNotContainsString('RANK_TRACKER_ENABLED=true', $src);
        $this->assertStringNotContainsString('seedFromKeywords', $src);
    }

    public function testInvalidAccountIdExitsTwoWithoutCollect(): void
    {
        $script = $this->scriptPath();
        $this->assertFileExists($script);
        $vendor = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (!is_file($vendor)) {
            $this->markTestSkipped('vendor/autoload.php ausente neste worktree');
        }

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --account-id=0 --collect 2>&1', $lines, $exitCode);
        $text = implode("\n", $lines);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('account-id inválido', $text);
        $this->assertStringNotContainsString('checked=', $text);
    }
}
