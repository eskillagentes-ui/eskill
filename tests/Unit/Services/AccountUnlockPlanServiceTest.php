<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AccountUnlockPlanService;
use App\Services\AccountGovernanceService;
use PHPUnit\Framework\TestCase;

final class AccountUnlockPlanServiceTest extends TestCase
{
    public function testBuildItemsPrioritizesToxicAndIncludesReasonFromFlags(): void
    {
        $service = new AccountUnlockPlanService(1335);

        $gov = [
            'account_status' => AccountGovernanceService::STATUS_TRAVADA,
            'items' => [
                [
                    'id' => 'MLB111',
                    'title' => 'Item tóxico',
                    'classification' => AccountGovernanceService::CLASS_TOXICO,
                    'visits_30d' => 500,
                    'sales_30d' => 0,
                    'flags' => [
                        'HIGH_TRAFFIC' => true,
                        'VERY_BAD_CONV' => true,
                        'NO_SALES_30' => true,
                    ],
                    'actions' => [
                        [
                            'tipo' => 'PAUSAR',
                            'porque' => 'Anúncio tóxico',
                        ],
                    ],
                ],
                [
                    'id' => 'MLB222',
                    'title' => 'Item fraco',
                    'classification' => AccountGovernanceService::CLASS_FRACO,
                    'visits_30d' => 10,
                    'sales_30d' => 0,
                    'flags' => [
                        'NO_SALES_30' => true,
                    ],
                    'actions' => [],
                ],
            ],
        ];

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('buildItems');
        $method->setAccessible(true);
        /** @var list<array<string, mixed>> $items */
        $items = $method->invoke($service, $gov);

        $this->assertNotEmpty($items);
        $this->assertSame('MLB111', $items[0]['mlb_id']);
        $this->assertGreaterThan((int) $items[1]['impact_score'], (int) $items[0]['impact_score']);
        $this->assertStringContainsString('TÓXICO', (string) $items[0]['reason']);
        $this->assertStringContainsString('alto tráfego', (string) $items[0]['reason']);
        $this->assertStringContainsString('PAUSAR', (string) $items[0]['recommended_action']);
        $this->assertTrue((bool) $items[0]['manual_execution']);
    }

    public function testReasonFromFlagsIncludesConversionMetrics(): void
    {
        $service = new AccountUnlockPlanService(1);
        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('reasonFromFlags');
        $method->setAccessible(true);

        $reason = $method->invoke(
            $service,
            AccountGovernanceService::CLASS_POLUIDOR,
            ['MED_TRAFFIC' => true, 'BAD_CONV' => true],
            ['visits_30d' => 80, 'sales_30d' => 1]
        );

        $this->assertStringContainsString('POLUIDOR', $reason);
        $this->assertStringContainsString('visitas 30d=80', $reason);
        $this->assertStringContainsString('vendas 30d=1', $reason);
        $this->assertStringContainsString('tráfego médio', $reason);
    }
}
