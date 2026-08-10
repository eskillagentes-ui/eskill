<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ML;

use App\Services\ML\MLWriteGateway;
use PDO;
use PHPUnit\Framework\TestCase;

final class MLWriteGatewayTest extends TestCase
{
    private PDO $pdo;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'ml_write_gw_' . bin2hex(random_bytes(4));
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Schema via gateway ensureSchema uses MySQL DDL — use simplified SQLite compatible tables
        $this->pdo->exec(
            'CREATE TABLE ml_write_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INT NOT NULL,
                user_id INT NOT NULL DEFAULT 0,
                mlb_id TEXT NOT NULL DEFAULT "",
                action TEXT NOT NULL,
                result TEXT NOT NULL,
                blocked_by TEXT NULL,
                api_called INT NOT NULL DEFAULT 0,
                dry_run INT NOT NULL DEFAULT 1,
                payload_json TEXT NULL,
                before_json TEXT NULL,
                expected_after_json TEXT NULL,
                guards_json TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE ml_write_allowlist (
                account_id INT NOT NULL,
                mlb_id TEXT NOT NULL,
                active INT NOT NULL DEFAULT 1,
                created_by INT NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NULL,
                PRIMARY KEY (account_id, mlb_id)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE account_xray_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INT,
                account_status TEXT,
                created_at TEXT
            )'
        );
        putenv('ML_WRITE_AUTOMATION=false');
        $_ENV['ML_WRITE_AUTOMATION'] = 'false';
        putenv('ML_WRITE_PAUSE=false');
        $_ENV['ML_WRITE_PAUSE'] = 'false';
        putenv('ML_WRITE_MAX_PER_DAY=10');
        $_ENV['ML_WRITE_MAX_PER_DAY'] = '10';
    }

    public function testKillSwitchBlocksFirst(): void
    {
        $apiCalls = 0;
        $gw = new MLWriteGateway(
            $this->pdo,
            null,
            static function () use (&$apiCalls) {
                $apiCalls++;
                return ['ok' => true];
            },
            true
        );
        $r = $gw->execute(MLWriteGateway::ACTION_PAUSE, ['mlb_id' => 'MLB1'], [
            'account_id' => 1,
            'mlb_id' => 'MLB1',
            'dry_run' => false,
        ]);
        self::assertFalse($r['success']);
        self::assertSame('kill_switch', $r['blocked_by']);
        self::assertFalse($r['api_called']);
        self::assertSame(0, $apiCalls);
    }

    public function testActionFlagBlocksAfterKillSwitchPass(): void
    {
        putenv('ML_WRITE_AUTOMATION=true');
        $_ENV['ML_WRITE_AUTOMATION'] = 'true';
        putenv('ML_WRITE_PAUSE=false');
        $_ENV['ML_WRITE_PAUSE'] = 'false';

        $gw = $this->gatewayWithoutGovernance();
        $r = $gw->execute(MLWriteGateway::ACTION_PAUSE, ['mlb_id' => 'MLB1'], [
            'account_id' => 1,
            'mlb_id' => 'MLB1',
            'dry_run' => true,
        ]);
        self::assertSame('action_flag', $r['blocked_by']);
        self::assertFalse($r['api_called']);
    }

    public function testAllowlistBlocksWhenMissing(): void
    {
        putenv('ML_WRITE_AUTOMATION=true');
        $_ENV['ML_WRITE_AUTOMATION'] = 'true';
        putenv('ML_WRITE_PAUSE=true');
        $_ENV['ML_WRITE_PAUSE'] = 'true';

        $gw = $this->gatewayWithoutGovernance();
        $r = $gw->execute(MLWriteGateway::ACTION_PAUSE, ['mlb_id' => 'MLB999'], [
            'account_id' => 1,
            'mlb_id' => 'MLB999',
            'dry_run' => true,
        ]);
        self::assertSame('allowlist', $r['blocked_by']);
    }

    public function testDryRunLogsIntentionWithoutApiCall(): void
    {
        putenv('ML_WRITE_AUTOMATION=true');
        $_ENV['ML_WRITE_AUTOMATION'] = 'true';
        putenv('ML_WRITE_PAUSE=true');
        $_ENV['ML_WRITE_PAUSE'] = 'true';

        $apiCalls = 0;
        $gw = new MLWriteGateway(
            $this->pdo,
            $this->fakeSentinelaVerde(),
            static function () use (&$apiCalls) {
                $apiCalls++;
                return ['ok' => true];
            },
            true
        );
        $gw->addToAllowlist(1, 'MLB123', 9);
        // Clear TRAVADA
        $this->pdo->exec("INSERT INTO account_xray_reports (account_id, account_status, created_at) VALUES (1, 'OK', datetime('now'))");

        $r = $gw->execute(MLWriteGateway::ACTION_PAUSE, [
            'mlb_id' => 'MLB123',
            'status' => 'paused',
        ], [
            'account_id' => 1,
            'user_id' => 9,
            'mlb_id' => 'MLB123',
            'dry_run' => true,
            'before' => ['status' => 'active'],
            'expected_after' => ['status' => 'paused'],
        ]);

        self::assertTrue($r['success']);
        self::assertTrue($r['dry_run']);
        self::assertFalse($r['api_called']);
        self::assertSame(0, $apiCalls);
        self::assertNotEmpty($r['audit_id']);
        self::assertSame('paused', $r['intention']['expected_after']['status']);
    }

    public function testGuardOrderKillSwitchBeforeActionFlag(): void
    {
        putenv('ML_WRITE_AUTOMATION=false');
        $_ENV['ML_WRITE_AUTOMATION'] = 'false';
        putenv('ML_WRITE_PAUSE=true');
        $_ENV['ML_WRITE_PAUSE'] = 'true';

        $gw = $this->gatewayWithoutGovernance();
        $r = $gw->execute(MLWriteGateway::ACTION_PAUSE, ['mlb_id' => 'MLB1'], [
            'account_id' => 1,
            'mlb_id' => 'MLB1',
        ]);
        self::assertSame('kill_switch', $r['blocked_by']);
        self::assertSame('kill_switch', $r['guards'][0]['guard']);
        self::assertFalse($r['guards'][0]['pass']);
    }

    private function gatewayWithoutGovernance(): MLWriteGateway
    {
        return new MLWriteGateway($this->pdo, $this->fakeSentinelaVerde(), null, true);
    }

    private function fakeSentinelaVerde(): object
    {
        return new class {
            public function semaforoGlobal(int $accountId): string
            {
                return 'verde';
            }
        };
    }
}
