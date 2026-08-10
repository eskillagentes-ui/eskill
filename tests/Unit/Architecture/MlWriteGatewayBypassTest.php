<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Garante que mutações ML futuras passam pelo MLWriteGateway
 * (nenhum client legado de escrita fora do gateway nas Services novas).
 */
final class MlWriteGatewayBypassTest extends TestCase
{
    public function testNoDirectPutPatchDeleteToMlApiInNewWritePaths(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = [
            $root . '/app/Services/ML/MLWriteGateway.php',
            $root . '/bin/ml-write-dry-run-demo.php',
            $root . '/app/Controllers/WriteGovernanceController.php',
        ];
        foreach ($paths as $path) {
            self::assertFileExists($path);
            $src = (string) file_get_contents($path);
            // Demo e controller não devem instanciar HTTP write direto
            if (str_contains($path, 'MLWriteGateway.php')) {
                self::assertStringContainsString('ML_WRITE_AUTOMATION', $src);
                self::assertStringContainsString('forceDryRun', $src);
                continue;
            }
            self::assertStringNotContainsString('curl_setopt', $src);
            self::assertDoesNotMatchRegularExpression('/->(put|patch|delete)\\s*\\(/i', $src);
        }
    }

    public function testGatewayIsSoleWriteEntrypointDocumented(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Services/ML/MLWriteGateway.php');
        self::assertStringContainsString('Portão ÚNICO', $src);
        self::assertStringContainsString('kill_switch', $src);
        self::assertStringContainsString('allowlist', $src);
        self::assertStringContainsString('dry_run', $src);
    }
}
