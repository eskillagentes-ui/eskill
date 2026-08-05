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
        $tmpfiles = $root . '/config/tmpfiles.d/pregao-qa.conf';
        self::assertFileExists($tmpfiles);
        $tmpfilesSource = file_get_contents($tmpfiles);
        self::assertIsString($tmpfilesSource);
        self::assertStringContainsString(
            'd /home/eskill/htdocs/eskill.com.br/storage/private/pregao-qa 0700 eskill eskill - -',
            $tmpfilesSource
        );

        foreach ([
            'User=eskill',
            'Group=eskill',
            'PREGAO_QA_BASE_URL=https://eskill.com.br',
            'PREGAO_QA_ALLOW_PRODUCTION_READONLY=true',
            'PREGAO_QA_BROWSER_EXECUTABLE=/usr/bin/google-chrome-stable',
            'Environment=PREGAO_QA_PRIVATE_ROOT=/home/eskill/htdocs/eskill.com.br/storage/private/pregao-qa',
            'Environment=HOME=/run/pregao-qa',
            'RuntimeDirectory=pregao-qa',
            'RuntimeDirectoryMode=0700',
            'ExecStartPre=/usr/bin/test -d /home/eskill/htdocs/eskill.com.br/storage/private/pregao-qa',
            'ExecStart=/usr/bin/php /home/eskill/htdocs/eskill.com.br/bin/pregao-qa-worker.php',
            'Restart=always',
            'StartLimitIntervalSec=0',
            'RuntimeMaxSec=180',
            'NoNewPrivileges=true',
            'PrivateTmp=true',
            'PrivateDevices=true',
            'PrivateMounts=true',
            'ProtectSystem=strict',
            'ProtectHome=read-only',
            'ProtectHostname=true',
            'ProtectClock=true',
            'ProtectKernelTunables=true',
            'ProtectKernelModules=true',
            'ProtectKernelLogs=true',
            'ProtectControlGroups=true',
            'ProtectProc=invisible',
            'RestrictSUIDSGID=true',
            'RestrictRealtime=true',
            'LockPersonality=true',
            'RemoveIPC=true',
            'KeyringMode=private',
            'CapabilityBoundingSet=',
            'AmbientCapabilities=',
            'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6',
            'SystemCallArchitectures=native',
            'UMask=0077',
            'MemoryMax=1200M',
            'CPUQuota=150%',
            'ReadWritePaths=/home/eskill/htdocs/eskill.com.br/storage/private/pregao-qa',
            'StandardOutput=journal',
            'StandardError=journal',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }

        foreach ([
            'ML_WRITE_AUTOMATION=true',
            'PREGAO_SEED=true',
            'PASSWORD=',
            'TOKEN=',
            'StandardOutput=append:',
            'StandardError=append:',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
}
