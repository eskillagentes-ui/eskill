<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ML;

use App\Services\ML\WriteEnablementChecklist;
use App\Services\Rank\RankTrackerService;
use PDO;
use PHPUnit\Framework\TestCase;

final class WriteEnablementChecklistTest extends TestCase
{
    public function testEvaluateReturnsSevenItems(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE account_xray_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INT,
                account_status TEXT,
                created_at TEXT
            )'
        );
        $pdo->exec(
            "INSERT INTO account_xray_reports (account_id, account_status, created_at)
             VALUES (1, 'TRAVADA', datetime('now'))"
        );
        $pdo->exec(
            'CREATE TABLE account_unlock_plan_items (
                account_id INT, status TEXT, priority TEXT, impact_score INT
            )'
        );
        $pdo->exec(
            "INSERT INTO account_unlock_plan_items VALUES (1, 'pending', 'CRITICA', 50)"
        );

        $ranks = new RankTrackerService($pdo, static fn () => []);
        $sentinela = new class {
            public function semaforoGlobal(int $accountId): string
            {
                return 'amarelo';
            }
        };

        $checklist = new WriteEnablementChecklist($pdo, $ranks, $sentinela);
        $r = $checklist->evaluate(1);
        self::assertSame(7, $r['total']);
        self::assertCount(7, $r['items']);
        self::assertFalse($r['can_enable_flags']);
        $byId = [];
        foreach ($r['items'] as $item) {
            $byId[$item['id']] = $item;
        }
        self::assertFalse($byId['not_travada']['pass']);
        self::assertSame('TRAVADA', $byId['not_travada']['value']);
        self::assertFalse($byId['unlock_no_critical']['pass']);
    }

    public function testQaChecklistReadsPregaoEventsNotMissingTable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE account_xray_reports (id INTEGER PRIMARY KEY, account_id INT, account_status TEXT, created_at TEXT)');
        $pdo->exec("INSERT INTO account_xray_reports VALUES (1, 1, 'OK', datetime('now'))");
        $pdo->exec('CREATE TABLE account_unlock_plan_items (account_id INT, status TEXT, priority TEXT, impact_score INT)');
        $pdo->exec(
            'CREATE TABLE pregao_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INT,
                type TEXT,
                source TEXT,
                payload TEXT,
                ts TEXT
            )'
        );
        $payload = json_encode([
            'result' => 'passed',
            'observed_at' => '2026-08-10T01:28:10.999Z',
            'suite' => 'pregao-live',
            'run_id' => '2632b6a4-3836-4181-ac2d-05bc6bf655d9',
        ], JSON_THROW_ON_ERROR);
        $ins = $pdo->prepare(
            "INSERT INTO pregao_events (account_id, type, source, payload, ts)
             VALUES (1, 'qa.status', 'live', ?, datetime('now'))"
        );
        $ins->execute([$payload]);

        $ranks = new RankTrackerService($pdo, static fn () => []);
        $sentinela = new class {
            public function semaforoGlobal(int $accountId): string
            {
                return 'verde';
            }
        };
        $checklist = new WriteEnablementChecklist($pdo, $ranks, $sentinela);
        $r = $checklist->evaluate(1);
        $byId = [];
        foreach ($r['items'] as $item) {
            $byId[$item['id']] = $item;
        }
        self::assertTrue($byId['qa_playwright']['pass']);
        self::assertStringContainsString('OK · última execução', (string) $byId['qa_playwright']['value']);
    }
}
