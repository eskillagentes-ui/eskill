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
}
