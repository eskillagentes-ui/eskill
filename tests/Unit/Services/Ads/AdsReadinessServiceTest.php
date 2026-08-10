<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ads;

use App\Services\Ads\AdsReadinessService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdsReadinessServiceTest extends TestCase
{
    public function testQueueOrdersByWasteAndDisablesExecute(): void
    {
        $obs = new class {
            /**
             * @return array<string, mixed>
             */
            public function dashboard(int $accountId): array
            {
                return [
                    'skus' => [
                        [
                            'mlb_id' => 'MLB_A',
                            'gasto' => 50.0,
                            'acos' => 56.6,
                            'margem_liquida_pct' => 16.0,
                            'has_custo' => true,
                            'vendas_atribuidas' => 1,
                        ],
                        [
                            'mlb_id' => 'MLB_B',
                            'gasto' => 12.0,
                            'acos' => null,
                            'margem_liquida_pct' => null,
                            'has_custo' => false,
                            'vendas_atribuidas' => 0,
                        ],
                    ],
                ];
            }
        };

        $pdo = new PDO('sqlite::memory:');
        $svc = new AdsReadinessService($pdo, $obs);
        $q = $svc->recommendationQueue(1);
        self::assertGreaterThanOrEqual(2, $q['summary']['total']);
        self::assertFalse($q['recommendations'][0]['execute_enabled']);
        self::assertStringContainsString('Governança', $q['recommendations'][0]['execute_tooltip']);
        $byMlb = [];
        foreach ($q['recommendations'] as $r) {
            $byMlb[$r['mlb_id']] = $r;
        }
        self::assertSame('pausar', $byMlb['MLB_B']['action']);
        self::assertSame('pausar', $byMlb['MLB_A']['action']);
        self::assertSame('estimado', $byMlb['MLB_B']['cogs_confidence']);
    }
}
