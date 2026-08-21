<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\AccountGovernanceService;

/**
 * @covers \App\Services\AccountGovernanceService
 */
class AccountGovernanceServiceTest extends TestCase
{
    private AccountGovernanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccountGovernanceService(
            defaultMinMarginPct: 0.05,
            maxPriceDropPct: 0.15
        );
    }

    // ── Helper: build minimal valid data ──────────────────

    private function buildAccountData(array $overrides = []): array
    {
        return array_merge([
            'seller_id'         => 'SELLER123',
            'reputation_level'  => 'green',
            'total_sales_60d'   => 100,
            'claims_rate'       => 0.01,
            'late_shipment_rate' => 0.02,
            'cancellation_rate' => 0.01,
        ], $overrides);
    }

    private function buildItem(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 'MLB' . mt_rand(100000, 999999),
            'title'              => 'Bagageiro CG 160 Titan',
            'price'              => 89.90,
            'status'             => 'active',
            'available_quantity' => 10,
            'visits_30d'         => 200,
            'visits_14d'         => 100,
            'sales_30d'          => 8,
            'sales_14d'          => 4,
            'margin_pct'         => 0.15,
            'category_id'       => 'MLB1234',
        ], $overrides);
    }

    // ── Constructor ───────────────────────────────────────

    public function testConstructorWithDefaults(): void
    {
        $service = new AccountGovernanceService();
        $this->assertInstanceOf(AccountGovernanceService::class, $service);
    }

    public function testConstructorWithCustomParams(): void
    {
        $service = new AccountGovernanceService(
            defaultMinMarginPct: 0.10,
            maxPriceDropPct: 0.20
        );
        $this->assertInstanceOf(AccountGovernanceService::class, $service);
    }

    // ── validateInput ─────────────────────────────────────

    public function testValidateInputMissingSellerId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('seller_id');

        $this->service->validateInput(
            ['reputation_level' => 'green', 'total_sales_60d' => 10],
            [$this->buildItem()]
        );
    }

    public function testValidateInputMissingReputationLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reputation_level');

        $this->service->validateInput(
            ['seller_id' => 'X', 'total_sales_60d' => 10],
            [$this->buildItem()]
        );
    }

    public function testValidateInputEmptyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vazia');

        $this->service->validateInput($this->buildAccountData(), []);
    }

    public function testValidateInputMissingItemField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('item [0]');

        $this->service->validateInput(
            $this->buildAccountData(),
            [['id' => 'X', 'title' => 'Y']] // missing price, status
        );
    }

    public function testValidateInputValid(): void
    {
        // Should not throw
        $this->service->validateInput(
            $this->buildAccountData(),
            [$this->buildItem()]
        );
        $this->assertTrue(true);
    }

    // ── calculateAccountMetrics ───────────────────────────

    public function testCalculateAccountMetrics(): void
    {
        $account = $this->buildAccountData();
        $items = [
            $this->buildItem(['status' => 'active', 'visits_30d' => 100, 'sales_30d' => 5, 'price' => 50.00, 'available_quantity' => 10]),
            $this->buildItem(['status' => 'active', 'visits_30d' => 200, 'sales_30d' => 10, 'price' => 80.00, 'available_quantity' => 0]),
            $this->buildItem(['status' => 'paused', 'visits_30d' => 0, 'sales_30d' => 0, 'price' => 30.00, 'available_quantity' => 5]),
        ];

        $metrics = $this->service->calculateAccountMetrics($account, $items);

        $this->assertSame(3, $metrics['total_items']);
        $this->assertSame(2, $metrics['active_items']);
        $this->assertSame(1, $metrics['paused_items']);
        $this->assertSame(300, $metrics['total_visits_30d']);
        $this->assertSame(15, $metrics['total_sales_30d']);
        $this->assertSame(1, $metrics['oos_count']); // 1 active with stock 0
        $this->assertEqualsWithDelta(0.05, $metrics['account_conv_30d'], 0.001);
        $this->assertSame('green', $metrics['reputation_level']);
    }

    // ── calculateFlags ────────────────────────────────────

    public function testCalculateFlagsHighTraffic(): void
    {
        $item = $this->buildItem(['visits_30d' => 500, 'sales_30d' => 20, 'sales_14d' => 10, 'available_quantity' => 10]);
        $metrics = ['active_items' => 10, 'total_visits_30d' => 1000];

        $flags = $this->service->calculateFlags($item, $metrics);

        $this->assertTrue($flags['HIGH_TRAFFIC']);
        $this->assertFalse($flags['LOW_TRAFFIC']);
        $this->assertFalse($flags['OOS']);
    }

    public function testCalculateFlagsOOS(): void
    {
        $item = $this->buildItem(['available_quantity' => 0]);
        $metrics = ['active_items' => 10, 'total_visits_30d' => 1000];

        $flags = $this->service->calculateFlags($item, $metrics);

        $this->assertTrue($flags['OOS']);
        $this->assertFalse($flags['LOW_STOCK']);
    }

    public function testCalculateFlagsLowStock(): void
    {
        $item = $this->buildItem(['available_quantity' => 2]);
        $metrics = ['active_items' => 10, 'total_visits_30d' => 1000];

        $flags = $this->service->calculateFlags($item, $metrics);

        $this->assertTrue($flags['LOW_STOCK']);
        $this->assertFalse($flags['OOS']);
    }

    public function testCalculateFlagsNoSales30(): void
    {
        $item = $this->buildItem(['sales_30d' => 0, 'sales_14d' => 0, 'visits_30d' => 5]);
        $metrics = ['active_items' => 10, 'total_visits_30d' => 1000];

        $flags = $this->service->calculateFlags($item, $metrics);

        $this->assertTrue($flags['NO_SALES_30']);
        $this->assertTrue($flags['STALE']); // visits < 10 and no sales
    }

    public function testCalculateFlagsVeryBadConv(): void
    {
        $item = $this->buildItem(['visits_30d' => 500, 'sales_30d' => 1, 'visits_14d' => 250]);
        $metrics = ['active_items' => 1, 'total_visits_30d' => 500];

        $flags = $this->service->calculateFlags($item, $metrics);

        // conv = 1/500 = 0.002 < 0.005, visits >= 100
        $this->assertTrue($flags['VERY_BAD_CONV']);
    }

    public function testCalculateFlagsFalling(): void
    {
        // visits_14d = 10 => projected = 10 * (30/14) ≈ 21.4
        // visits_30d = 50 => trend = (21.4 - 50) / 50 = -0.572 < -0.3
        $item = $this->buildItem(['visits_30d' => 50, 'visits_14d' => 10, 'sales_30d' => 3, 'sales_14d' => 1]);
        $metrics = ['active_items' => 1, 'total_visits_30d' => 50];

        $flags = $this->service->calculateFlags($item, $metrics);

        $this->assertTrue($flags['FALLING']);
    }

    // ── calculateItemScore ────────────────────────────────

    public function testCalculateItemScorePerfect(): void
    {
        $item = $this->buildItem([
            'sales_30d'  => 20,
            'visits_30d' => 300,
            'margin_pct' => 0.20,
        ]);
        $flags = [
            'HIGH_TRAFFIC' => true,
            'MED_TRAFFIC' => false,
            'LOW_TRAFFIC' => false,
            'LOW_STOCK' => false,
            'OOS' => false,
            'NO_SALES_30' => false,
            'NO_SALES_14' => false,
            'BAD_CONV' => false,
            'VERY_BAD_CONV' => false,
            'FALLING' => false,
            'STALE' => false,
        ];
        $metrics = ['active_items' => 1, 'total_visits_30d' => 300];

        $score = $this->service->calculateItemScore($item, $flags, $metrics);

        $this->assertSame(100, $score);
    }

    public function testCalculateItemScoreZeroSales(): void
    {
        $item = $this->buildItem(['sales_30d' => 0, 'visits_30d' => 5, 'margin_pct' => 0.15]);
        $flags = [
            'HIGH_TRAFFIC' => false,
            'MED_TRAFFIC' => false,
            'LOW_TRAFFIC' => true,
            'LOW_STOCK' => false,
            'OOS' => false,
            'NO_SALES_30' => true,
            'NO_SALES_14' => false,
            'BAD_CONV' => false,
            'VERY_BAD_CONV' => false,
            'FALLING' => false,
            'STALE' => true,
        ];
        $metrics = ['active_items' => 1, 'total_visits_30d' => 5];

        $score = $this->service->calculateItemScore($item, $flags, $metrics);

        // -30 (no sales) -7 (low traffic) -4 (stale) = 59
        $this->assertLessThan(70, $score);
        $this->assertGreaterThan(0, $score);
    }

    public function testCalculateItemScoreNeverBelowZero(): void
    {
        $item = $this->buildItem([
            'sales_30d' => 0,
            'visits_30d' => 200,
            'margin_pct' => -0.10,
            'available_quantity' => 0,
        ]);
        $flags = [
            'HIGH_TRAFFIC' => true,
            'MED_TRAFFIC' => false,
            'LOW_TRAFFIC' => false,
            'LOW_STOCK' => false,
            'OOS' => true,
            'NO_SALES_30' => true,
            'NO_SALES_14' => false,
            'BAD_CONV' => false,
            'VERY_BAD_CONV' => true,
            'FALLING' => true,
            'STALE' => true,
        ];
        $metrics = ['active_items' => 1, 'total_visits_30d' => 200];

        $score = $this->service->calculateItemScore($item, $flags, $metrics);

        $this->assertGreaterThanOrEqual(0, $score);
    }

    // ── classifyItem ──────────────────────────────────────

    public function testClassifyItemSemEstoque(): void
    {
        $item = $this->buildItem(['available_quantity' => 0, 'status' => 'active']);
        $flags = [
            'OOS' => true,
            'LOW_STOCK' => false,
            'HIGH_TRAFFIC' => false,
            'VERY_BAD_CONV' => false,
            'BAD_CONV' => false,
            'LOW_TRAFFIC' => false,
            'NO_SALES_30' => false,
            'STALE' => false,
            'FALLING' => false,
            'NO_SALES_14' => false
        ];

        $result = $this->service->classifyItem($item, $flags, 50);
        $this->assertSame(AccountGovernanceService::CLASS_SEM_ESTOQUE, $result);
    }

    public function testClassifyItemToxico(): void
    {
        $item = $this->buildItem(['available_quantity' => 10, 'status' => 'active']);
        $flags = [
            'OOS' => false,
            'LOW_STOCK' => false,
            'HIGH_TRAFFIC' => true,
            'VERY_BAD_CONV' => true,
            'BAD_CONV' => true,
            'LOW_TRAFFIC' => false,
            'NO_SALES_30' => false,
            'STALE' => false,
            'FALLING' => false,
            'NO_SALES_14' => false
        ];

        $result = $this->service->classifyItem($item, $flags, 20);
        $this->assertSame(AccountGovernanceService::CLASS_TOXICO, $result);
    }

    public function testClassifyItemPoluidor(): void
    {
        $item = $this->buildItem(['available_quantity' => 10, 'status' => 'active']);
        $flags = [
            'OOS' => false,
            'LOW_STOCK' => false,
            'HIGH_TRAFFIC' => false,
            'VERY_BAD_CONV' => false,
            'BAD_CONV' => true,
            'LOW_TRAFFIC' => false,
            'MED_TRAFFIC' => true,
            'NO_SALES_30' => false,
            'STALE' => false,
            'FALLING' => false,
            'NO_SALES_14' => false
        ];

        $result = $this->service->classifyItem($item, $flags, 40);
        $this->assertSame(AccountGovernanceService::CLASS_POLUIDOR, $result);
    }

    public function testClassifyItemMorto(): void
    {
        $item = $this->buildItem(['available_quantity' => 10, 'status' => 'active']);
        $flags = [
            'OOS' => false,
            'LOW_STOCK' => false,
            'HIGH_TRAFFIC' => false,
            'VERY_BAD_CONV' => false,
            'BAD_CONV' => false,
            'LOW_TRAFFIC' => true,
            'NO_SALES_30' => true,
            'STALE' => true,
            'FALLING' => false,
            'NO_SALES_14' => false
        ];

        $result = $this->service->classifyItem($item, $flags, 30);
        $this->assertSame(AccountGovernanceService::CLASS_MORTO, $result);
    }

    public function testClassifyItemFraco(): void
    {
        $item = $this->buildItem(['available_quantity' => 10, 'status' => 'active']);
        $flags = [
            'OOS' => false,
            'LOW_STOCK' => false,
            'HIGH_TRAFFIC' => false,
            'VERY_BAD_CONV' => false,
            'BAD_CONV' => false,
            'LOW_TRAFFIC' => false,
            'MED_TRAFFIC' => true,
            'NO_SALES_30' => true,
            'STALE' => false,
            'FALLING' => false,
            'NO_SALES_14' => false
        ];

        $result = $this->service->classifyItem($item, $flags, 40);
        $this->assertSame(AccountGovernanceService::CLASS_FRACO, $result);
    }

    public function testClassifyItemEmRisco(): void
    {
        $item = $this->buildItem(['available_quantity' => 10, 'status' => 'active']);
        $flags = [
            'OOS' => false,
            'LOW_STOCK' => false,
            'HIGH_TRAFFIC' => false,
            'VERY_BAD_CONV' => false,
            'BAD_CONV' => false,
            'LOW_TRAFFIC' => false,
            'NO_SALES_30' => false,
            'STALE' => false,
            'FALLING' => true,
            'NO_SALES_14' => false
        ];

        $result = $this->service->classifyItem($item, $flags, 60);
        $this->assertSame(AccountGovernanceService::CLASS_EM_RISCO, $result);
    }

    public function testClassifyItemAnchor(): void
    {
        $item = $this->buildItem(['available_quantity' => 10, 'status' => 'active']);
        $flags = [
            'OOS' => false,
            'LOW_STOCK' => false,
            'HIGH_TRAFFIC' => true,
            'VERY_BAD_CONV' => false,
            'BAD_CONV' => false,
            'LOW_TRAFFIC' => false,
            'NO_SALES_30' => false,
            'STALE' => false,
            'FALLING' => false,
            'NO_SALES_14' => false
        ];

        $result = $this->service->classifyItem($item, $flags, 85);
        $this->assertSame(AccountGovernanceService::CLASS_ANCHOR, $result);
    }

    public function testClassifyItemSaudavel(): void
    {
        $item = $this->buildItem(['available_quantity' => 10, 'status' => 'active']);
        $flags = [
            'OOS' => false,
            'LOW_STOCK' => false,
            'HIGH_TRAFFIC' => false,
            'VERY_BAD_CONV' => false,
            'BAD_CONV' => false,
            'LOW_TRAFFIC' => false,
            'NO_SALES_30' => false,
            'STALE' => false,
            'FALLING' => false,
            'NO_SALES_14' => false
        ];

        $result = $this->service->classifyItem($item, $flags, 65);
        $this->assertSame(AccountGovernanceService::CLASS_SAUDAVEL, $result);
    }

    // ── classifyAccount ──────────────────────────────────

    public function testClassifyAccountTravada(): void
    {
        $metrics = [
            'account_conv_30d' => 0.001,
            'reputation_level' => 'red',
            'claims_rate'      => 0.05,
            'late_shipment_rate' => 0.06,
            'cancellation_rate' => 0.03,
        ];
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO],
            ['classification' => AccountGovernanceService::CLASS_MORTO],
        ];

        $status = $this->service->classifyAccount($metrics, $items);
        $this->assertSame(AccountGovernanceService::STATUS_TRAVADA, $status);
    }

    public function testClassifyAccountTravadaByProblemRatio(): void
    {
        $metrics = [
            'account_conv_30d' => 0.02,
            'reputation_level' => 'green',
            'claims_rate'      => 0.01,
            'late_shipment_rate' => 0.01,
            'cancellation_rate' => 0.01,
        ];
        // 3 out of 4 items are problems = 75% > 50%
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO],
            ['classification' => AccountGovernanceService::CLASS_MORTO],
            ['classification' => AccountGovernanceService::CLASS_SEM_ESTOQUE],
            ['classification' => AccountGovernanceService::CLASS_ANCHOR],
        ];

        $status = $this->service->classifyAccount($metrics, $items);
        $this->assertSame(AccountGovernanceService::STATUS_TRAVADA, $status);
    }

    public function testClassifyAccountPenalizada(): void
    {
        $metrics = [
            'account_conv_30d' => 0.01,
            'reputation_level' => 'yellow',
            'claims_rate'      => 0.04,
            'late_shipment_rate' => 0.06,
            'cancellation_rate' => 0.01,
        ];
        // 2 out of 5 = 40% problems (>30% with bad rep)
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO],
            ['classification' => AccountGovernanceService::CLASS_POLUIDOR],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
            ['classification' => AccountGovernanceService::CLASS_ANCHOR],
            ['classification' => AccountGovernanceService::CLASS_ANCHOR],
        ];

        $status = $this->service->classifyAccount($metrics, $items);
        $this->assertSame(AccountGovernanceService::STATUS_PENALIZADA, $status);
    }

    public function testClassifyAccountForte(): void
    {
        $metrics = [
            'account_conv_30d' => 0.03,
            'reputation_level' => 'green',
            'claims_rate'      => 0.005,
            'late_shipment_rate' => 0.01,
            'cancellation_rate' => 0.005,
        ];
        // 8 out of 10 healthy > 70%
        $items = array_fill(0, 8, ['classification' => AccountGovernanceService::CLASS_ANCHOR]);
        $items[] = ['classification' => AccountGovernanceService::CLASS_SAUDAVEL];
        $items[] = ['classification' => AccountGovernanceService::CLASS_FRACO];

        $status = $this->service->classifyAccount($metrics, $items);
        $this->assertSame(AccountGovernanceService::STATUS_FORTE, $status);
    }

    public function testClassifyAccountEstavel(): void
    {
        $metrics = [
            'account_conv_30d' => 0.015,
            'reputation_level' => 'light_green',
            'claims_rate'      => 0.01,
            'late_shipment_rate' => 0.02,
            'cancellation_rate' => 0.01,
        ];
        $items = [
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
            ['classification' => AccountGovernanceService::CLASS_ANCHOR],
            ['classification' => AccountGovernanceService::CLASS_FRACO],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
        ];

        $status = $this->service->classifyAccount($metrics, $items);
        $this->assertSame(AccountGovernanceService::STATUS_ESTAVEL, $status);
    }

    // ── generateActions ──────────────────────────────────

    public function testGenerateActionsForToxico(): void
    {
        $item = $this->buildItem();
        $item['classification'] = AccountGovernanceService::CLASS_TOXICO;
        $item['flags'] = ['VERY_BAD_CONV' => true, 'HIGH_TRAFFIC' => true];

        $actions = $this->service->generateActions($item, []);
        $this->assertNotEmpty($actions);
        $this->assertSame(AccountGovernanceService::ACTION_PAUSAR, $actions[0]['tipo']);
        $this->assertSame(AccountGovernanceService::PRIORITY_CRITICA, $actions[0]['prioridade']);
    }

    public function testGenerateActionsForSemEstoque(): void
    {
        $item = $this->buildItem();
        $item['classification'] = AccountGovernanceService::CLASS_SEM_ESTOQUE;
        $item['flags'] = [];

        $actions = $this->service->generateActions($item, []);
        $this->assertNotEmpty($actions);
        $this->assertSame(AccountGovernanceService::ACTION_REPOR_ESTOQUE, $actions[0]['tipo']);
    }

    public function testGenerateActionsForAnchorWithLowStock(): void
    {
        $item = $this->buildItem();
        $item['classification'] = AccountGovernanceService::CLASS_ANCHOR;
        $item['flags'] = ['LOW_STOCK' => true];

        $actions = $this->service->generateActions($item, []);
        $this->assertCount(2, $actions);
        // Critical stock replenishment should be first (sorted by priority)
        $this->assertSame(AccountGovernanceService::ACTION_REPOR_ESTOQUE, $actions[0]['tipo']);
        $this->assertSame(AccountGovernanceService::PRIORITY_CRITICA, $actions[0]['prioridade']);
    }

    public function testGenerateActionsHaveRequiredFields(): void
    {
        $item = $this->buildItem();
        $item['classification'] = AccountGovernanceService::CLASS_POLUIDOR;
        $item['flags'] = ['BAD_CONV' => true];

        $actions = $this->service->generateActions($item, []);
        $this->assertNotEmpty($actions);

        foreach ($actions as $action) {
            $this->assertArrayHasKey('tipo', $action);
            $this->assertArrayHasKey('prioridade', $action);
            $this->assertArrayHasKey('motivo', $action);
            $this->assertArrayHasKey('impacto', $action);
            $this->assertArrayHasKey('risco', $action);
            $this->assertArrayHasKey('janela', $action);
            $this->assertArrayHasKey('rollback', $action);
        }
    }

    // ── applyGuardrails ──────────────────────────────────

    public function testApplyGuardrailsPauseLimit(): void
    {
        $metrics = ['paused_items' => 0];

        // 10 items, all with PAUSAR action — max 30% = 3 pauses allowed
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = [
                'id' => "MLB{$i}",
                'price' => 100,
                'actions' => [
                    [
                        'tipo'       => AccountGovernanceService::ACTION_PAUSAR,
                        'prioridade' => AccountGovernanceService::PRIORITY_CRITICA,
                        'motivo'     => 'test',
                        'impacto'    => 'test',
                        'risco'      => 'test',
                        'janela'     => 'test',
                        'rollback'   => 'test',
                    ],
                ],
            ];
        }

        $result = $this->service->applyGuardrails($items, $metrics);

        $pausedCount = 0;
        $blockedCount = 0;
        foreach ($result as $item) {
            foreach ($item['actions'] as $action) {
                if ($action['tipo'] === AccountGovernanceService::ACTION_PAUSAR) {
                    if (!($action['guardrail_blocked'] ?? false)) {
                        $pausedCount++;
                    }
                }
                if ($action['guardrail_blocked'] ?? false) {
                    $blockedCount++;
                }
            }
        }

        $this->assertSame(3, $pausedCount);
        $this->assertSame(7, $blockedCount);
    }

    // ── detectDensityRisks ──────────────────────────────

    public function testDetectDensityRisksHighDensity(): void
    {
        // 3 out of 10 toxic = 30% >= 15% threshold
        $items = array_fill(0, 3, ['classification' => AccountGovernanceService::CLASS_TOXICO, 'category_id' => 'C1']);
        $items = array_merge($items, array_fill(0, 7, ['classification' => AccountGovernanceService::CLASS_SAUDAVEL, 'category_id' => 'C2']));

        $risks = $this->service->detectDensityRisks($items, []);

        $this->assertNotEmpty($risks);
        $found = false;
        foreach ($risks as $risk) {
            if ($risk['type'] === 'HIGH_DENSITY' && $risk['category'] === AccountGovernanceService::CLASS_TOXICO) {
                $found = true;
                $this->assertSame(3, $risk['count']);
            }
        }
        $this->assertTrue($found, 'Expected HIGH_DENSITY risk for TOXICO');
    }

    public function testDetectDensityRisksGlobalContamination(): void
    {
        // 5 out of 10 problemáticos = 50% >= 40%
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO, 'category_id' => 'C1'],
            ['classification' => AccountGovernanceService::CLASS_TOXICO, 'category_id' => 'C1'],
            ['classification' => AccountGovernanceService::CLASS_MORTO, 'category_id' => 'C2'],
            ['classification' => AccountGovernanceService::CLASS_SEM_ESTOQUE, 'category_id' => 'C3'],
            ['classification' => AccountGovernanceService::CLASS_POLUIDOR, 'category_id' => 'C3'],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL, 'category_id' => 'C4'],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL, 'category_id' => 'C4'],
            ['classification' => AccountGovernanceService::CLASS_ANCHOR, 'category_id' => 'C4'],
            ['classification' => AccountGovernanceService::CLASS_ANCHOR, 'category_id' => 'C5'],
            ['classification' => AccountGovernanceService::CLASS_ANCHOR, 'category_id' => 'C5'],
        ];

        $risks = $this->service->detectDensityRisks($items, []);

        $globalRisk = array_filter($risks, fn(array $r) => $r['type'] === 'GLOBAL_CONTAMINATION');
        $this->assertNotEmpty($globalRisk, 'Expected GLOBAL_CONTAMINATION risk');
    }

    public function testDetectDensityRisksNoRisksInHealthyCatalog(): void
    {
        $items = array_fill(0, 10, ['classification' => AccountGovernanceService::CLASS_SAUDAVEL, 'category_id' => 'C1']);

        $risks = $this->service->detectDensityRisks($items, []);
        $this->assertEmpty($risks);
    }

    // ── generateRecoveryPlan ─────────────────────────────

    public function testGenerateRecoveryPlanHasThreePhases(): void
    {
        $items = [
            [
                'id' => '1',
                'title' => 'T1',
                'classification' => AccountGovernanceService::CLASS_TOXICO,
                'actions' => [['prioridade' => AccountGovernanceService::PRIORITY_CRITICA, 'tipo' => 'PAUSAR']]
            ],
            [
                'id' => '2',
                'title' => 'T2',
                'classification' => AccountGovernanceService::CLASS_POLUIDOR,
                'actions' => [['prioridade' => AccountGovernanceService::PRIORITY_ALTA, 'tipo' => 'OTIMIZAR']]
            ],
            [
                'id' => '3',
                'title' => 'T3',
                'classification' => AccountGovernanceService::CLASS_ANCHOR,
                'actions' => [['prioridade' => AccountGovernanceService::PRIORITY_BAIXA, 'tipo' => 'MONITORAR']]
            ],
        ];

        $plan = $this->service->generateRecoveryPlan(AccountGovernanceService::STATUS_TRAVADA, $items, []);

        $this->assertCount(3, $plan['phases']);
        $this->assertSame(AccountGovernanceService::PHASE_ESTANCAR, $plan['phases'][0]['name']);
        $this->assertSame(AccountGovernanceService::PHASE_ESTABILIZAR, $plan['phases'][1]['name']);
        $this->assertSame(AccountGovernanceService::PHASE_CRESCER, $plan['phases'][2]['name']);
    }

    // ── identifyTopCauses ────────────────────────────────

    public function testIdentifyTopCausesMaxFive(): void
    {
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO, 'flags' => ['FALLING' => true]],
            ['classification' => AccountGovernanceService::CLASS_SEM_ESTOQUE, 'flags' => ['OOS' => true]],
            ['classification' => AccountGovernanceService::CLASS_POLUIDOR, 'flags' => ['BAD_CONV' => true]],
            ['classification' => AccountGovernanceService::CLASS_MORTO, 'flags' => ['STALE' => true]],
            ['classification' => AccountGovernanceService::CLASS_MORTO, 'flags' => ['STALE' => true, 'FALLING' => true]],
        ];
        $metrics = ['claims_rate' => 0.05, 'late_shipment_rate' => 0.06];

        $causes = $this->service->identifyTopCauses($items, $metrics);

        $this->assertLessThanOrEqual(5, count($causes));
        $this->assertNotEmpty($causes);

        // Each cause has required fields
        foreach ($causes as $cause) {
            $this->assertArrayHasKey('cause', $cause);
            $this->assertArrayHasKey('impact_score', $cause);
            $this->assertArrayHasKey('fix', $cause);
        }
    }

    public function testIdentifyTopCausesOrderedByImpact(): void
    {
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO, 'flags' => []],
            ['classification' => AccountGovernanceService::CLASS_TOXICO, 'flags' => []],
            ['classification' => AccountGovernanceService::CLASS_SEM_ESTOQUE, 'flags' => []],
        ];
        $metrics = ['claims_rate' => 0, 'late_shipment_rate' => 0];

        $causes = $this->service->identifyTopCauses($items, $metrics);

        if (count($causes) >= 2) {
            $this->assertGreaterThanOrEqual(
                $causes[1]['impact_score'],
                $causes[0]['impact_score']
            );
        }
    }

    // ── generateWeekPlan ─────────────────────────────────

    public function testGenerateWeekPlanHasSevenDays(): void
    {
        $items = [
            [
                'id' => '1',
                'title' => 'T1',
                'classification' => AccountGovernanceService::CLASS_TOXICO,
                'actions' => [['tipo' => 'PAUSAR', 'prioridade' => 'CRITICA', 'motivo' => 'test', 'guardrail_blocked' => false]]
            ],
            [
                'id' => '2',
                'title' => 'T2',
                'classification' => AccountGovernanceService::CLASS_SAUDAVEL,
                'actions' => [['tipo' => 'MONITORAR', 'prioridade' => 'BAIXA', 'motivo' => 'test']]
            ],
        ];

        $plan = $this->service->generateWeekPlan($items, ['phases' => []]);

        $this->assertCount(7, $plan);
        for ($i = 0; $i < 7; $i++) {
            $this->assertSame($i + 1, $plan[$i]['day']);
            $this->assertArrayHasKey('theme', $plan[$i]);
            $this->assertArrayHasKey('actions', $plan[$i]);
            $this->assertArrayHasKey('kpi_check', $plan[$i]);
        }
    }

    // ── generateExecutiveSummary ─────────────────────────

    public function testGenerateExecutiveSummaryStructure(): void
    {
        $items = [
            [
                'classification' => AccountGovernanceService::CLASS_ANCHOR,
                'score' => 90,
                'actions' => [['prioridade' => 'BAIXA']]
            ],
            [
                'classification' => AccountGovernanceService::CLASS_TOXICO,
                'score' => 15,
                'actions' => [['prioridade' => 'CRITICA']]
            ],
            [
                'classification' => AccountGovernanceService::CLASS_SAUDAVEL,
                'score' => 70,
                'actions' => [['prioridade' => 'BAIXA']]
            ],
        ];
        $metrics = ['account_conv_30d' => 0.01, 'total_sales_30d' => 50, 'total_revenue_30d' => 5000.00];
        $causes = [['cause' => 'Itens tóxicos']];

        $summary = $this->service->generateExecutiveSummary(
            AccountGovernanceService::STATUS_PENALIZADA,
            $metrics,
            $items,
            $causes
        );

        $this->assertSame(AccountGovernanceService::STATUS_PENALIZADA, $summary['status']);
        $this->assertSame(3, $summary['total_items']);
        $this->assertSame(2, $summary['healthy_items']); // ANCHOR + SAUDAVEL
        $this->assertSame(1, $summary['problem_items']); // TOXICO
        $this->assertSame(1, $summary['critical_actions']);
        $this->assertSame('Itens tóxicos', $summary['top_cause']);
        $this->assertArrayHasKey('headline', $summary);
        $this->assertArrayHasKey('classification_breakdown', $summary);
    }

    // ── buildSuccessCriteria ─────────────────────────────

    public function testBuildSuccessCriteriaForAllStatuses(): void
    {
        $metrics = ['account_conv_30d' => 0.01, 'total_sales_30d' => 50];

        $statuses = [
            AccountGovernanceService::STATUS_TRAVADA,
            AccountGovernanceService::STATUS_PENALIZADA,
            AccountGovernanceService::STATUS_EM_RECUPERACAO,
            AccountGovernanceService::STATUS_ESTAVEL,
            AccountGovernanceService::STATUS_FORTE,
        ];

        foreach ($statuses as $status) {
            $criteria = $this->service->buildSuccessCriteria($status, $metrics);

            $this->assertArrayHasKey('success', $criteria, "Missing 'success' for {$status}");
            $this->assertArrayHasKey('rollback', $criteria, "Missing 'rollback' for {$status}");
            $this->assertNotEmpty($criteria['success'], "Empty success criteria for {$status}");
            $this->assertNotEmpty($criteria['rollback'], "Empty rollback criteria for {$status}");
        }
    }

    // ── Full pipeline (runFullDiagnostic) ────────────────

    public function testRunFullDiagnosticReturnsCompleteStructure(): void
    {
        $account = $this->buildAccountData();
        $items = [
            $this->buildItem(['id' => 'MLB1', 'visits_30d' => 500, 'sales_30d' => 1, 'visits_14d' => 250, 'available_quantity' => 10]),
            $this->buildItem(['id' => 'MLB2', 'visits_30d' => 200, 'sales_30d' => 15, 'visits_14d' => 100, 'available_quantity' => 20]),
            $this->buildItem(['id' => 'MLB3', 'visits_30d' => 5, 'sales_30d' => 0, 'visits_14d' => 2, 'available_quantity' => 5]),
            $this->buildItem(['id' => 'MLB4', 'visits_30d' => 100, 'sales_30d' => 0, 'visits_14d' => 50, 'available_quantity' => 0, 'status' => 'active']),
        ];

        $result = $this->service->runFullDiagnostic($account, $items);

        // Top-level keys
        $this->assertArrayHasKey('executive_summary', $result);
        $this->assertArrayHasKey('account_status', $result);
        $this->assertArrayHasKey('account_metrics', $result);
        $this->assertArrayHasKey('top_causes', $result);
        $this->assertArrayHasKey('week_plan', $result);
        $this->assertArrayHasKey('recovery_plan', $result);
        $this->assertArrayHasKey('density_risks', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('success_criteria', $result);
        $this->assertArrayHasKey('rollback_criteria', $result);
        $this->assertArrayHasKey('guardrails_applied', $result);
        $this->assertArrayHasKey('meta', $result);

        // Meta
        $this->assertSame(4, $result['meta']['total_items']);
        $this->assertSame(4, $result['meta']['processed_items']);
        $this->assertArrayHasKey('elapsed_ms', $result['meta']);
        $this->assertSame('1.0.0', $result['meta']['engine_version']);

        // Items are processed
        foreach ($result['items'] as $item) {
            $this->assertArrayHasKey('flags', $item);
            $this->assertArrayHasKey('score', $item);
            $this->assertArrayHasKey('classification', $item);
            $this->assertArrayHasKey('actions', $item);
        }

        // Week plan has 7 days
        $this->assertCount(7, $result['week_plan']);

        // Recovery plan has 3 phases
        $this->assertCount(3, $result['recovery_plan']['phases']);

        // Executive summary has key fields
        $this->assertArrayHasKey('headline', $result['executive_summary']);
        $this->assertArrayHasKey('status', $result['executive_summary']);
    }

    public function testRunFullDiagnosticCompletesQuickly(): void
    {
        $account = $this->buildAccountData();

        // Generate 100 items
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = $this->buildItem([
                'id'        => "MLB{$i}",
                'sales_30d' => mt_rand(0, 20),
                'visits_30d' => mt_rand(0, 500),
                'visits_14d' => mt_rand(0, 250),
                'available_quantity' => mt_rand(0, 30),
            ]);
        }

        $start = hrtime(true);
        $result = $this->service->runFullDiagnostic($account, $items);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        // Should complete in under 500ms for 100 items
        $this->assertLessThan(500, $elapsed, "Pipeline took {$elapsed}ms for 100 items");
        $this->assertSame(100, $result['meta']['total_items']);
    }

    // ── X-Ray enrich key aliases (_visits_30d → visits_30d) ──

    public function testNormalizeItemMetricKeysCopiesPrefixedXRayFields(): void
    {
        $normalized = AccountGovernanceService::normalizeItemMetricKeys([
            'id'            => 'MLB1',
            '_visits_30d'   => 400,
            '_sales_30d'    => 16,
            '_visits_14d'   => 180,
            '_sales_14d'    => 7,
        ]);

        $this->assertSame(400, $normalized['visits_30d']);
        $this->assertSame(16, $normalized['sales_30d']);
        $this->assertSame(180, $normalized['visits_14d']);
        $this->assertSame(7, $normalized['sales_14d']);
        $this->assertSame(400, $normalized['_visits_30d']);
        $this->assertSame(16, $normalized['_sales_30d']);
    }

    public function testNormalizeItemMetricKeysLeavesUnprefixedOnlyPayloadsUnchanged(): void
    {
        $normalized = AccountGovernanceService::normalizeItemMetricKeys([
            'visits_30d' => 200,
            'sales_30d'  => 8,
        ]);

        $this->assertSame(200, $normalized['visits_30d']);
        $this->assertSame(8, $normalized['sales_30d']);
        $this->assertArrayNotHasKey('_visits_30d', $normalized);
    }

    public function testCalculateFlagsReadsPrefixedXRayEnrichKeys(): void
    {
        $item = [
            'id'                 => 'MLB123',
            'title'              => 'Bagageiro CG 160 Titan',
            'price'              => 89.90,
            'status'             => 'active',
            'available_quantity' => 10,
            '_visits_30d'        => 500,
            '_sales_30d'         => 20,
            '_visits_14d'        => 250,
            '_sales_14d'         => 10,
        ];
        $metrics = ['active_items' => 10, 'total_visits_30d' => 1000];

        $flags = $this->service->calculateFlags($item, $metrics);

        $this->assertTrue($flags['HIGH_TRAFFIC']);
        $this->assertFalse($flags['LOW_TRAFFIC']);
        $this->assertFalse($flags['NO_SALES_30']);
        $this->assertFalse($flags['STALE']);
    }

    public function testCalculateAccountMetricsReadsPrefixedXRayEnrichKeys(): void
    {
        $account = $this->buildAccountData();
        $items = [
            [
                'id' => 'MLB1', 'title' => 'A', 'price' => 10.0, 'status' => 'active',
                'available_quantity' => 5, '_visits_30d' => 100, '_sales_30d' => 5,
            ],
            [
                'id' => 'MLB2', 'title' => 'B', 'price' => 10.0, 'status' => 'active',
                'available_quantity' => 5, '_visits_30d' => 200, '_sales_30d' => 10,
            ],
        ];

        $metrics = $this->service->calculateAccountMetrics($account, $items);

        $this->assertSame(300, $metrics['total_visits_30d']);
        $this->assertSame(15, $metrics['total_sales_30d']);
        $this->assertEqualsWithDelta(0.05, $metrics['account_conv_30d'], 0.0001);
    }

    public function testPrefixedXRayKeysDoNotClassifyLiveItemAsMorto(): void
    {
        $item = [
            'id'                 => 'MLB1',
            'title'              => 'Bagageiro CG 160 Titan',
            'price'              => 89.90,
            'status'             => 'active',
            'available_quantity' => 10,
            '_visits_30d'        => 200,
            '_sales_30d'         => 8,
            '_visits_14d'        => 100,
            '_sales_14d'         => 4,
        ];
        $metrics = ['active_items' => 1, 'total_visits_30d' => 200];

        $flags = $this->service->calculateFlags($item, $metrics);
        $score = $this->service->calculateItemScore($item, $flags, $metrics);
        $class = $this->service->classifyItem($item, $flags, $score);

        $this->assertFalse($flags['NO_SALES_30']);
        $this->assertFalse($flags['STALE']);
        $this->assertFalse($flags['LOW_TRAFFIC']);
        $this->assertNotSame(AccountGovernanceService::CLASS_MORTO, $class);
    }

    public function testRunFullDiagnosticWithPrefixedXRayKeysDoesNotMarkHealthyCatalogTravada(): void
    {
        $account = $this->buildAccountData(['reputation_level' => 'green']);
        $items = [];
        for ($i = 1; $i <= 6; $i++) {
            $items[] = [
                'id'                 => 'MLB' . $i,
                'title'              => 'Item ' . $i,
                'price'              => 89.90,
                'status'             => 'active',
                'available_quantity' => 10,
                '_visits_30d'        => 200,
                '_sales_30d'         => 8,
                '_visits_14d'        => 100,
                '_sales_14d'         => 4,
            ];
        }

        $result = $this->service->runFullDiagnostic($account, $items);

        $this->assertNotSame(AccountGovernanceService::STATUS_TRAVADA, $result['account_status']);
        foreach ($result['items'] as $item) {
            $this->assertSame(200, $item['visits_30d']);
            $this->assertSame(8, $item['sales_30d']);
            $this->assertNotSame(AccountGovernanceService::CLASS_MORTO, $item['classification']);
        }
    }

    public function testMissingVisitKeysAreNotMortoOrTravada(): void
    {
        $item = [
            'id' => 'MLB1',
            'title' => 'Peca',
            'price' => 10.0,
            'status' => 'active',
            'available_quantity' => 5,
        ];
        $flags = [
            'OOS' => false,
            'HIGH_TRAFFIC' => false,
            'VERY_BAD_CONV' => false,
            'BAD_CONV' => false,
            'LOW_TRAFFIC' => true,
            'NO_SALES_30' => true,
            'STALE' => true,
        ];
        $class = $this->service->classifyItem($item, $flags, 20);
        $this->assertSame(AccountGovernanceService::CLASS_PERFORMANCE_PENDING, $class);
        $this->assertNotSame(AccountGovernanceService::CLASS_MORTO, $class);
        $this->assertNotSame(AccountGovernanceService::CLASS_TOXICO, $class);

        $status = $this->service->classifyAccount(
            ['account_conv_30d' => 0, 'total_visits_30d' => 0, 'reputation_level' => 'green'],
            [['classification' => $class]]
        );
        $this->assertNotSame(AccountGovernanceService::STATUS_TRAVADA, $status);
    }

    public function testRealZeroVisitsStillAllowedAsMortoWhenKeysPresent(): void
    {
        $item = $this->buildItem([
            'visits_30d' => 0,
            'sales_30d' => 0,
            'visits_14d' => 0,
            'sales_14d' => 0,
        ]);
        $flags = $this->service->calculateFlags($item, ['total_visits_30d' => 1, 'active_items' => 1]);
        $class = $this->service->classifyItem($item, $flags, 20);
        $this->assertSame(AccountGovernanceService::CLASS_MORTO, $class);
    }
}
