<?php

declare(strict_types=1);

namespace Tests\Unit\PregaoQa;

use App\Services\Pregao\PregaoQaWorkerEnvironment;
use PHPUnit\Framework\TestCase;

final class PregaoQaWorkerEnvironmentTest extends TestCase
{
    public function testRunnerReceivesAnExactMinimalAllowlistAndNeverApplicationSecrets(): void
    {
        $host = [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => '/home/eskill',
            'LANG' => 'C.UTF-8',
            'TMPDIR' => '/tmp',
            'PREGAO_QA_BROWSER_EXECUTABLE' => '/usr/bin/google-chrome-stable',
            'APP_KEY' => 'secret-app-key',
            'DB_PASSWORD' => 'secret-db',
            'REDIS_PASSWORD' => 'secret-redis',
            'ML_ACCESS_TOKEN' => 'secret-ml',
            'DATABASE_URL' => 'secret-url',
        ];
        $required = [
            'PREGAO_QA_RUN_ID' => '123e4567-e89b-42d3-a456-426614174000',
            'PREGAO_QA_BASE_URL' => 'http://127.0.0.1:8765',
            'PREGAO_QA_OUTPUT_DIR' => '/tmp/qa-output',
            'PREGAO_QA_SESSION_COOKIE' => 'PHPSESSID=ephemeral',
            'PREGAO_QA_ACCOUNT_ID' => '1335',
        ];

        $environment = PregaoQaWorkerEnvironment::build($host, $required);

        self::assertSame([
            'PATH',
            'HOME',
            'LANG',
            'TMPDIR',
            'PREGAO_QA_BROWSER_EXECUTABLE',
            'PREGAO_QA_RUN_ID',
            'PREGAO_QA_BASE_URL',
            'PREGAO_QA_OUTPUT_DIR',
            'PREGAO_QA_SESSION_COOKIE',
            'PREGAO_QA_ACCOUNT_ID',
        ], array_keys($environment));
        self::assertSame('/usr/bin/google-chrome-stable', $environment['PREGAO_QA_BROWSER_EXECUTABLE']);
        foreach (['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'ML_ACCESS_TOKEN', 'DATABASE_URL'] as $secret) {
            self::assertArrayNotHasKey($secret, $environment);
        }

        $worker = file_get_contents(dirname(__DIR__, 3) . '/bin/pregao-qa-worker.php');
        self::assertIsString($worker);
        self::assertStringContainsString('PregaoQaWorkerEnvironment::build', $worker);
        self::assertStringNotContainsString('array_merge($environment, $_ENV)', $worker);
    }
}
