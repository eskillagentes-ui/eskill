<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use PHPUnit\Framework\TestCase;

final class PregaoQaSystemdContractTest extends TestCase
{
    public function testWorkerUnitUsesReadOnlyProductionGateAndResourceHardening(): void
    {
        $root = dirname(__DIR__, 3);
        $unit = $root . '/config/systemd/pregao-qa-worker.service';
        self::assertFileExists($unit);
        $source = file_get_contents($unit);
        self::assertIsString($source);

        foreach ([
            'User=eskill',
            'Group=eskill',
            'PREGAO_QA_BASE_URL=https://eskill.com.br',
            'PREGAO_QA_ALLOW_PRODUCTION_READONLY=true',
            'PREGAO_QA_BROWSER_EXECUTABLE=/usr/bin/google-chrome-stable',
            'ExecStart=/usr/bin/php /home/eskill/htdocs/eskill.com.br/bin/pregao-qa-worker.php',
            'Restart=always',
            'StartLimitIntervalSec=0',
            'RuntimeMaxSec=180',
            'NoNewPrivileges=true',
            'PrivateTmp=true',
            'ProtectSystem=full',
            'UMask=0077',
            'MemoryMax=1200M',
            'CPUQuota=150%',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }

        foreach (['ML_WRITE_AUTOMATION=true', 'PREGAO_SEED=true', 'PASSWORD=', 'TOKEN='] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
}
