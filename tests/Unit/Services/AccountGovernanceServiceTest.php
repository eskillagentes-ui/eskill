<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AccountGovernanceService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\AccountGovernanceService
 */
class AccountGovernanceServiceTest extends TestCase
{
    private AccountGovernanceService $service;

    protected function setUp(): void
    {
        $this->service = new AccountGovernanceService();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // VALIDATION
    // ═══════════════════════════════════════════════════════════════════════

    public function testValidateInputThrowsOnMissingSellerId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('seller_id');

        $this->service->validateInput(
            ['reputation_level' => 'green'],
            [['id' => '1', 'title' => 'X', 'price' => 10, 'status' => 'active', 'available_quantity' => 5]]
        );
    }

    public function testValidateInputThrowsOnMissingReputation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reputation_level');

        $this->service->validateInput(
            ['seller_id' => '123'],
            [['id' => '1', 'title' => 'X', 'price' => 10, 'status' => 'active', 'available_quantity' => 5]]
        );
    }

    public function testValidateInputThrowsOnEmptyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vazia');

        $this->service->validateInput(['seller_id' => '123', 'reputation_level' => 'green'], []);
    }

    public function testValidateInputThrowsOnMissingItemField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('item [0]: price');

        $this->service->validateInput(
            ['seller_id' => '123', 'reputation_level' => 'green'],
            [['id' => '1', 'title' => 'X', 'status' => 'active', 'available_quantity' => 5]]
        );
    }

    public function testValidateInputPassesWithValidData(): void
    {
        $this->service->validateInput(
            ['seller_id' => '123', 'reputation_level' => 'green'],
            [$this->makeItem()]
        );
        $this->assertTrue(true); // No exception thrown
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ACCOUNT METRICS
    // ═══════════════════════════════════════════════════════════════════════

    public function testCalculateAccountMetricsCountsActiveAndPaused(): void
    {
        $items = [
            $this->makeItem(['status' => 'active', 'visits_30d' => 100, 'sales_30d' => 5]),
            $this->makeItem(['status' => 'active', 'visits_30d' => 200, 'sales_30d' => 10]),
            $this->makeItem(['status' => 'paused', 'visits_30d' => 0, 'sales_30d' => 0]),
        ];

        $metrics = $this->service->calculateAccountMetrics(
            ['seller_id' => '123', 'reputation_level' => 'green'],
            $items
        );

        $this->assertSame(3, $metrics['total_items']);
        $this->assertSame(2, $metrics['active_items']);
        $this->assertSame(1, $metrics['paused_items']);
        $this->assertSame(300, $metrics['total_visits_30d']);
        $this->assertSame(15, $metrics['total_sales_30d']);
    }

    public function testCalculateAccountMetricsCalculatesConversion(): void
    {
        $items = [
            $this->makeItem(['visits_30d' => 1000, 'sales_30d' => 20]),
        ];

        $metrics = $this->service->calculateAccountMetrics(
            ['seller_id' => '123', 'reputation_level' => 'green'],
            $items
        );

        $this->assertEqualsWithDelta(0.02, $metrics['account_conv_30d'], 0.001);
    }

    public function testCalculateAccountMetricsCountsOOS(): void
    {
        $items = [
            $this->makeItem(['status' => 'active', 'available_quantity' => 0]),
            $this->makeItem(['status' => 'active', 'available_quantity' => 5]),
            $this->makeItem(['status' => 'active', 'available_quantity' => 0]),
        ];

        $metrics = $this->service->calculateAccountMetrics(
            ['seller_id' => '123', 'reputation_level' => 'green'],
            $items
        );

        $this->assertSame(2, $metrics['oos_count']);
    }

    public function testCalculateAccountMetricsPassesThroughRates(): void
    {
        $metrics = $this->service->calculateAccountMetrics(
            [
                'seller_id' => '123',
                'reputation_level' => 'yellow',
                'claims_rate' => 0.04,
                'late_shipment_rate' => 0.06,
                'cancellation_rate' => 0.02,
            ],
            [$this->makeItem()]
        );

        $this->assertSame('yellow', $metrics['reputation_level']);
        $this->assertSame(0.04, $metrics['claims_rate']);
        $this->assertSame(0.06, $metrics['late_shipment_rate']);
        $this->assertSame(0.02, $metrics['cancellation_rate']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FLAGS CALCULATION
    // ═══════════════════════════════════════════════════════════════════════

    public function testCalculateFlagsHighTraffic(): void
    {
        // Item with 150+ visits out of 1000 total = 15%+ share
        $flags = $this->service->calculateFlags(
            $this->makeItem(['visits_30d' => 200, 'sales_30d' => 10]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['HIGH_TRAFFIC']);
        $this->assertFalse($flags['LOW_TRAFFIC']);
        $this->assertFalse($flags['MED_TRAFFIC']);
    }

    public function testCalculateFlagsLowTraffic(): void
    {
        $flags = $this->service->calculateFlags(
            $this->makeItem(['visits_30d' => 10, 'sales_30d' => 0]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['LOW_TRAFFIC']);
        $this->assertFalse($flags['HIGH_TRAFFIC']);
    }

    public function testCalculateFlagsMedTraffic(): void
    {
        $flags = $this->service->calculateFlags(
            $this->makeItem(['visits_30d' => 80, 'sales_30d' => 5]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['MED_TRAFFIC']);
        $this->assertFalse($flags['HIGH_TRAFFIC']);
        $this->assertFalse($flags['LOW_TRAFFIC']);
    }

    public function testCalculateFlagsOOS(): void
    {
        $flags = $this->service->calculateFlags(
            $this->makeItem(['available_quantity' => 0]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['OOS']);
        $this->assertFalse($flags['LOW_STOCK']);
    }

    public function testCalculateFlagsLowStock(): void
    {
        $flags = $this->service->calculateFlags(
            $this->makeItem(['available_quantity' => 2]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['LOW_STOCK']);
        $this->assertFalse($flags['OOS']);
    }

    public function testCalculateFlagsVeryBadConversion(): void
    {
        // 100+ visits with < 0.5% conversion
        $flags = $this->service->calculateFlags(
            $this->makeItem(['visits_30d' => 200, 'sales_30d' => 0]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['VERY_BAD_CONV']);
        $this->assertFalse($flags['BAD_CONV']);
    }

    public function testCalculateFlagsBadConversion(): void
    {
        // 50+ visits, conv < 1.5% but not very bad
        $flags = $this->service->calculateFlags(
            $this->makeItem(['visits_30d' => 100, 'sales_30d' => 1]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['BAD_CONV']);
        $this->assertFalse($flags['VERY_BAD_CONV']);
    }

    public function testCalculateFlagsFallingTrend(): void
    {
        // visits_14d projected much less than 30d actual → falling
        $flags = $this->service->calculateFlags(
            $this->makeItem(['visits_30d' => 100, 'visits_14d' => 20, 'sales_30d' => 5]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['FALLING']);
    }

    public function testCalculateFlagsStale(): void
    {
        $flags = $this->service->calculateFlags(
            $this->makeItem(['visits_30d' => 5, 'sales_30d' => 0]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['STALE']);
    }

    public function testCalculateFlagsNoSales(): void
    {
        $flags = $this->service->calculateFlags(
            $this->makeItem(['sales_30d' => 0, 'sales_14d' => 0]),
            ['total_visits_30d' => 1000]
        );

        $this->assertTrue($flags['NO_SALES_30']);
        $this->assertTrue($flags['NO_SALES_14']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ITEM SCORING
    // ═══════════════════════════════════════════════════════════════════════

    public function testCalculateItemScorePerfect(): void
    {
        $flags = $this->allFlagsFalse();
        $score = $this->service->calculateItemScore(
            $this->makeItem(['margin_pct' => 0.10]),
            $flags,
            []
        );

        $this->assertSame(100, $score);
    }

    public function testCalculateItemScoreWithMultiplePenalties(): void
    {
        $flags = $this->allFlagsFalse();
        $flags['NO_SALES_30'] = true;  // -30
        $flags['LOW_STOCK'] = true;    // -10
        $flags['STALE'] = true;        // -4

        $score = $this->service->calculateItemScore($this->makeItem(['margin_pct' => 0.10]), $flags, []);

        $this->assertSame(56, $score);
    }

    public function testCalculateItemScoreNeverBelowZero(): void
    {
        // All penalties active
        $flags = [
            'NO_SALES_30' => true,    // -30
            'VERY_BAD_CONV' => true,  // -25
            'OOS' => true,            // -20
            'BAD_CONV' => true,       // -15
            'LOW_STOCK' => true,      // -10
            'FALLING' => true,        // -12
            'LOW_TRAFFIC' => true,    // -7
            'STALE' => true,          // -4
            'NO_SALES_14' => true,    // -5
        ];

        $score = $this->service->calculateItemScore(
            $this->makeItem(['margin_pct' => 0.01]),  // -8 margin
            $flags,
            []
        );

        $this->assertSame(0, $score);
    }

    public function testCalculateItemScoreMarginPenalty(): void
    {
        $flags = $this->allFlagsFalse();

        $score = $this->service->calculateItemScore(
            $this->makeItem(['margin_pct' => 0.02]),  // Below 5% default
            $flags,
            []
        );

        $this->assertSame(92, $score);  // 100 - 8
    }

    public function testCalculateItemScoreCustomMinMargin(): void
    {
        $service = new AccountGovernanceService(0.10);  // 10% min margin

        $flags = $this->allFlagsFalse();
        $score = $service->calculateItemScore(
            $this->makeItem(['margin_pct' => 0.07]),  // Below 10%
            $flags,
            []
        );

        $this->assertSame(92, $score);  // 100 - 8
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ITEM CLASSIFICATION
    // ═══════════════════════════════════════════════════════════════════════

    public function testClassifyItemSemEstoque(): void
    {
        $flags = $this->allFlagsFalse();
        $flags['OOS'] = true;

        $class = $this->service->classifyItem(
            $this->makeItem(['status' => 'active']),
            $flags,
            50
        );

        $this->assertSame(AccountGovernanceService::CLASS_SEM_ESTOQUE, $class);
    }

    public function testClassifyItemToxico(): void
    {
        $flags = $this->allFlagsFalse();
        $flags['HIGH_TRAFFIC'] = true;
        $flags['VERY_BAD_CONV'] = true;

        $class = $this->service->classifyItem($this->makeItem(), $flags, 50);

        $this->assertSame(AccountGovernanceService::CLASS_TOXICO, $class);
    }

    public function testClassifyItemPoluidor(): void
    {
        $flags = $this->allFlagsFalse();
        $flags['MED_TRAFFIC'] = true;
        $flags['BAD_CONV'] = true;

        $class = $this->service->classifyItem($this->makeItem(), $flags, 50);

        $this->assertSame(AccountGovernanceService::CLASS_POLUIDOR, $class);
    }

    public function testClassifyItemMorto(): void
    {
        $flags = $this->allFlagsFalse();
        $flags['LOW_TRAFFIC'] = true;
        $flags['NO_SALES_30'] = true;
        $flags['STALE'] = true;

        $class = $this->service->classifyItem($this->makeItem(), $flags, 20);

        $this->assertSame(AccountGovernanceService::CLASS_MORTO, $class);
    }

    public function testClassifyItemFraco(): void
    {
        $flags = $this->allFlagsFalse();
        $flags['NO_SALES_30'] = true;

        $class = $this->service->classifyItem($this->makeItem(), $flags, 50);

        $this->assertSame(AccountGovernanceService::CLASS_FRACO, $class);
    }

    public function testClassifyItemEmRisco(): void
    {
        $flags = $this->allFlagsFalse();
        $flags['FALLING'] = true;

        $class = $this->service->classifyItem($this->makeItem(), $flags, 60);

        $this->assertSame(AccountGovernanceService::CLASS_EM_RISCO, $class);
    }

    public function testClassifyItemAnchor(): void
    {
        $flags = $this->allFlagsFalse();
        $flags['HIGH_TRAFFIC'] = true;

        $class = $this->service->classifyItem($this->makeItem(), $flags, 85);

        $this->assertSame(AccountGovernanceService::CLASS_ANCHOR, $class);
    }

    public function testClassifyItemSaudavel(): void
    {
        $flags = $this->allFlagsFalse();

        $class = $this->service->classifyItem($this->makeItem(), $flags, 60);

        $this->assertSame(AccountGovernanceService::CLASS_SAUDAVEL, $class);
    }

    public function testClassifyItemDefaultEmRisco(): void
    {
        $flags = $this->allFlagsFalse();

        // Score below healthy threshold (55) but no specific flags
        $class = $this->service->classifyItem($this->makeItem(), $flags, 40);

        $this->assertSame(AccountGovernanceService::CLASS_EM_RISCO, $class);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ACCOUNT CLASSIFICATION
    // ═══════════════════════════════════════════════════════════════════════

    public function testClassifyAccountTravadaLowConversion(): void
    {
        $metrics = [
            'account_conv_30d' => 0.002,
            'total_visits_30d' => 1000,
            'reputation_level' => 'green',
        ];
        $items = [['classification' => AccountGovernanceService::CLASS_SAUDAVEL]];

        $this->assertSame(
            AccountGovernanceService::STATUS_TRAVADA,
            $this->service->classifyAccount($metrics, $items)
        );
    }

    public function testClassifyAccountTravadaHighProblemRatio(): void
    {
        $metrics = ['account_conv_30d' => 0.02, 'reputation_level' => 'green'];
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO],
            ['classification' => AccountGovernanceService::CLASS_POLUIDOR],
            ['classification' => AccountGovernanceService::CLASS_MORTO],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
        ];

        $this->assertSame(
            AccountGovernanceService::STATUS_TRAVADA,
            $this->service->classifyAccount($metrics, $items)
        );
    }

    public function testClassifyAccountPenalizada(): void
    {
        $metrics = [
            'account_conv_30d' => 0.01,
            'reputation_level' => 'yellow',
            'claims_rate' => 0.04,
            'late_shipment_rate' => 0.01,
        ];
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
        ];

        $this->assertSame(
            AccountGovernanceService::STATUS_PENALIZADA,
            $this->service->classifyAccount($metrics, $items)
        );
    }

    public function testClassifyAccountForte(): void
    {
        $metrics = ['account_conv_30d' => 0.03, 'reputation_level' => 'green'];
        $items = array_fill(0, 8, ['classification' => AccountGovernanceService::CLASS_SAUDAVEL])
            + [8 => ['classification' => AccountGovernanceService::CLASS_ANCHOR],
               9 => ['classification' => AccountGovernanceService::CLASS_FRACO]];

        $this->assertSame(
            AccountGovernanceService::STATUS_FORTE,
            $this->service->classifyAccount($metrics, array_values($items))
        );
    }

    public function testClassifyAccountEstavel(): void
    {
        $metrics = ['account_conv_30d' => 0.01, 'reputation_level' => 'light_green'];
        $items = [
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
            ['classification' => AccountGovernanceService::CLASS_SAUDAVEL],
            ['classification' => AccountGovernanceService::CLASS_FRACO],
        ];

        $this->assertSame(
            AccountGovernanceService::STATUS_ESTAVEL,
            $this->service->classifyAccount($metrics, $items)
        );
    }

    public function testClassifyAccountEmRecuperacaoEmpty(): void
    {
        $this->assertSame(
            AccountGovernanceService::STATUS_EM_RECUPERACAO,
            $this->service->classifyAccount([], [])
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ACTIONS
    // ═══════════════════════════════════════════════════════════════════════

    public function testGenerateActionsToxico(): void
    {
        $item = ['classification' => AccountGovernanceService::CLASS_TOXICO, 'flags' => []];
        $actions = $this->service->generateActions($item, []);

        $this->assertNotEmpty($actions);
        $this->assertSame(AccountGovernanceService::ACTION_PAUSAR, $actions[0]['tipo']);
        $this->assertSame(AccountGovernanceService::PRIORITY_CRITICA, $actions[0]['prioridade']);
    }

    public function testGenerateActionsSemEstoque(): void
    {
        $item = ['classification' => AccountGovernanceService::CLASS_SEM_ESTOQUE, 'flags' => []];
        $actions = $this->service->generateActions($item, []);

        $this->assertSame(AccountGovernanceService::ACTION_REPOR_ESTOQUE, $actions[0]['tipo']);
        $this->assertSame(AccountGovernanceService::PRIORITY_CRITICA, $actions[0]['prioridade']);
    }

    public function testGenerateActionsPoluidorMultipleActions(): void
    {
        $item = ['classification' => AccountGovernanceService::CLASS_POLUIDOR, 'flags' => []];
        $actions = $this->service->generateActions($item, []);

        $tipos = array_column($actions, 'tipo');
        $this->assertContains(AccountGovernanceService::ACTION_OTIMIZAR_TITULO, $tipos);
        $this->assertContains(AccountGovernanceService::ACTION_OTIMIZAR_PRECO, $tipos);
    }

    public function testGenerateActionsMorto(): void
    {
        $item = ['classification' => AccountGovernanceService::CLASS_MORTO, 'flags' => []];
        $actions = $this->service->generateActions($item, []);

        $this->assertSame(AccountGovernanceService::ACTION_PAUSAR, $actions[0]['tipo']);
        $this->assertSame(AccountGovernanceService::PRIORITY_MEDIA, $actions[0]['prioridade']);
    }

    public function testGenerateActionsAnchorWithLowStock(): void
    {
        $item = [
            'classification' => AccountGovernanceService::CLASS_ANCHOR,
            'flags' => ['LOW_STOCK' => true],
        ];
        $actions = $this->service->generateActions($item, []);

        // Should have REPOR_ESTOQUE (CRITICA) + PROTEGER (BAIXA)
        $tipos = array_column($actions, 'tipo');
        $this->assertContains(AccountGovernanceService::ACTION_REPOR_ESTOQUE, $tipos);
        $this->assertContains(AccountGovernanceService::ACTION_PROTEGER, $tipos);
        // CRITICA should be first after sorting
        $this->assertSame(AccountGovernanceService::PRIORITY_CRITICA, $actions[0]['prioridade']);
    }

    public function testGenerateActionsSaudavel(): void
    {
        $item = ['classification' => AccountGovernanceService::CLASS_SAUDAVEL, 'flags' => []];
        $actions = $this->service->generateActions($item, []);

        $this->assertSame(AccountGovernanceService::ACTION_MONITORAR, $actions[0]['tipo']);
        $this->assertSame(AccountGovernanceService::PRIORITY_BAIXA, $actions[0]['prioridade']);
    }

    public function testGenerateActionsLowStockAddsExtraAction(): void
    {
        $item = [
            'classification' => AccountGovernanceService::CLASS_FRACO,
            'flags' => ['LOW_STOCK' => true],
        ];
        $actions = $this->service->generateActions($item, []);

        $tipos = array_column($actions, 'tipo');
        $this->assertContains(AccountGovernanceService::ACTION_REPOR_ESTOQUE, $tipos);
    }

    public function testGenerateActionsSortedByPriority(): void
    {
        $item = [
            'classification' => AccountGovernanceService::CLASS_POLUIDOR,
            'flags' => ['LOW_STOCK' => true],
        ];
        $actions = $this->service->generateActions($item, []);

        // All ALTA actions first, then others
        $priorities = array_column($actions, 'prioridade');
        for ($i = 1; $i < count($priorities); $i++) {
            $this->assertTrue(
                $this->priorityOrder($priorities[$i - 1]) <= $this->priorityOrder($priorities[$i]),
                'Actions should be sorted by priority'
            );
        }
    }

    public function testActionStructure(): void
    {
        $item = ['classification' => AccountGovernanceService::CLASS_TOXICO, 'flags' => []];
        $actions = $this->service->generateActions($item, []);

        $action = $actions[0];
        $this->assertArrayHasKey('tipo', $action);
        $this->assertArrayHasKey('prioridade', $action);
        $this->assertArrayHasKey('motivo', $action);
        $this->assertArrayHasKey('impacto', $action);
        $this->assertArrayHasKey('risco', $action);
        $this->assertArrayHasKey('janela', $action);
        $this->assertArrayHasKey('rollback', $action);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GUARDRAILS
    // ═══════════════════════════════════════════════════════════════════════

    public function testApplyGuardrailsBlocksExcessivePauses(): void
    {
        // 4 items, 30% = 1 pause max, 0 already paused → 1 allowed
        $items = [
            ['actions' => [['tipo' => AccountGovernanceService::ACTION_PAUSAR]]],
            ['actions' => [['tipo' => AccountGovernanceService::ACTION_PAUSAR]]],
            ['actions' => [['tipo' => AccountGovernanceService::ACTION_PAUSAR]]],
            ['actions' => [['tipo' => AccountGovernanceService::ACTION_MONITORAR]]],
        ];

        $result = $this->service->applyGuardrails($items, ['paused_items' => 0]);

        // First pause should be allowed
        $this->assertFalse($result[0]['actions'][0]['guardrail_blocked']);
        // Second+ should be blocked
        $this->assertTrue($result[1]['actions'][0]['guardrail_blocked']);
        $this->assertTrue($result[2]['actions'][0]['guardrail_blocked']);
    }

    public function testApplyGuardrailsRespectAlreadyPaused(): void
    {
        // 10 items, 30% = 3 max pauses. 2 already paused → 1 more allowed
        $items = array_fill(0, 10, ['actions' => [['tipo' => AccountGovernanceService::ACTION_PAUSAR]]]);

        $result = $this->service->applyGuardrails($items, ['paused_items' => 2]);

        $blockedCount = 0;
        foreach ($result as $item) {
            if ($item['actions'][0]['guardrail_blocked'] ?? false) {
                $blockedCount++;
            }
        }

        // 3 max - 2 already = 1 allowed, 9 blocked
        $this->assertSame(9, $blockedCount);
    }

    public function testApplyGuardrailsDoesNotBlockNonPauseActions(): void
    {
        $items = [
            ['actions' => [['tipo' => AccountGovernanceService::ACTION_OTIMIZAR_TITULO]]],
        ];

        $result = $this->service->applyGuardrails($items, ['paused_items' => 100]);

        $this->assertArrayNotHasKey('guardrail_blocked', $result[0]['actions'][0]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DENSITY RISKS
    // ═══════════════════════════════════════════════════════════════════════

    public function testDetectDensityRisksHighDensity(): void
    {
        // 4 of 10 items are TOXICO = 40% → HIGH_DENSITY + critical
        $items = array_merge(
            array_fill(0, 4, ['classification' => AccountGovernanceService::CLASS_TOXICO]),
            array_fill(0, 6, ['classification' => AccountGovernanceService::CLASS_SAUDAVEL])
        );

        $risks = $this->service->detectDensityRisks($items, []);

        $types = array_column($risks, 'type');
        $this->assertContains('HIGH_DENSITY', $types);

        $highDensity = array_filter($risks, fn($r) => $r['type'] === 'HIGH_DENSITY');
        $risk = reset($highDensity);
        $this->assertSame('critical', $risk['severity']);
    }

    public function testDetectDensityRisksGlobalContamination(): void
    {
        // > 40% problem items
        $items = array_merge(
            array_fill(0, 3, ['classification' => AccountGovernanceService::CLASS_TOXICO]),
            array_fill(0, 2, ['classification' => AccountGovernanceService::CLASS_MORTO]),
            array_fill(0, 5, ['classification' => AccountGovernanceService::CLASS_SAUDAVEL])
        );

        $risks = $this->service->detectDensityRisks($items, []);

        $types = array_column($risks, 'type');
        $this->assertContains('GLOBAL_CONTAMINATION', $types);
    }

    public function testDetectDensityRisksNoneForHealthy(): void
    {
        $items = array_fill(0, 10, ['classification' => AccountGovernanceService::CLASS_SAUDAVEL]);
        $risks = $this->service->detectDensityRisks($items, []);

        $this->assertEmpty($risks);
    }

    public function testDetectDensityRisksEmptyItems(): void
    {
        $risks = $this->service->detectDensityRisks([], []);
        $this->assertEmpty($risks);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // RECOVERY PLAN
    // ═══════════════════════════════════════════════════════════════════════

    public function testGenerateRecoveryPlanHasThreePhases(): void
    {
        $plan = $this->service->generateRecoveryPlan('TRAVADA', [], []);

        $this->assertArrayHasKey('phases', $plan);
        $this->assertCount(3, $plan['phases']);

        $names = array_column($plan['phases'], 'name');
        $this->assertSame(
            [AccountGovernanceService::PHASE_ESTANCAR, AccountGovernanceService::PHASE_ESTABILIZAR, AccountGovernanceService::PHASE_CRESCER],
            $names
        );
    }

    public function testGenerateRecoveryPlanAssignsItemsByPriority(): void
    {
        $items = [
            ['id' => '1', 'title' => 'A', 'actions' => [['prioridade' => AccountGovernanceService::PRIORITY_CRITICA, 'tipo' => 'PAUSAR']]],
            ['id' => '2', 'title' => 'B', 'actions' => [['prioridade' => AccountGovernanceService::PRIORITY_ALTA, 'tipo' => 'OTIMIZAR']]],
            ['id' => '3', 'title' => 'C', 'actions' => [['prioridade' => AccountGovernanceService::PRIORITY_BAIXA, 'tipo' => 'MONITORAR']]],
        ];

        $plan = $this->service->generateRecoveryPlan('TRAVADA', $items, []);

        // CRITICA → phase 0 (ESTANCAR)
        $this->assertCount(1, $plan['phases'][0]['items']);
        $this->assertSame('1', $plan['phases'][0]['items'][0]['id']);

        // ALTA → phase 1 (ESTABILIZAR)
        $this->assertCount(1, $plan['phases'][1]['items']);
        $this->assertSame('2', $plan['phases'][1]['items'][0]['id']);

        // BAIXA → phase 2 (CRESCER)
        $this->assertCount(1, $plan['phases'][2]['items']);
        $this->assertSame('3', $plan['phases'][2]['items'][0]['id']);
    }

    public function testGenerateRecoveryPlanEstimatesRecoveryTime(): void
    {
        $plan = $this->service->generateRecoveryPlan('TRAVADA', [], []);
        $this->assertSame('30-45 dias', $plan['estimated_recovery']);

        $plan = $this->service->generateRecoveryPlan('FORTE', [], []);
        $this->assertSame('Manutenção contínua', $plan['estimated_recovery']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TOP CAUSES
    // ═══════════════════════════════════════════════════════════════════════

    public function testIdentifyTopCausesReturnsMax5(): void
    {
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO, 'flags' => ['FALLING' => true]],
            ['classification' => AccountGovernanceService::CLASS_SEM_ESTOQUE, 'flags' => []],
            ['classification' => AccountGovernanceService::CLASS_POLUIDOR, 'flags' => []],
            ['classification' => AccountGovernanceService::CLASS_MORTO, 'flags' => []],
        ];

        $metrics = ['claims_rate' => 0.05, 'late_shipment_rate' => 0.08];

        $causes = $this->service->identifyTopCauses($items, $metrics);

        $this->assertLessThanOrEqual(5, count($causes));
    }

    public function testIdentifyTopCausesSortedByImpact(): void
    {
        $items = [
            ['classification' => AccountGovernanceService::CLASS_TOXICO, 'flags' => []],
            ['classification' => AccountGovernanceService::CLASS_MORTO, 'flags' => []],
        ];

        $causes = $this->service->identifyTopCauses($items, []);

        // Toxic impact (10) > Dead impact (3)
        $this->assertSame('Itens tóxicos', $causes[0]['cause']);
    }

    public function testIdentifyTopCausesIncludesClaimsAndShipping(): void
    {
        $items = [];
        $metrics = ['claims_rate' => 0.05, 'late_shipment_rate' => 0.08];

        $causes = $this->service->identifyTopCauses($items, $metrics);

        $causeNames = array_column($causes, 'cause');
        $this->assertContains('Alta taxa de reclamações', $causeNames);
        $this->assertContains('Atrasos de envio', $causeNames);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WEEK PLAN
    // ═══════════════════════════════════════════════════════════════════════

    public function testGenerateWeekPlanHas7Days(): void
    {
        $weekPlan = $this->service->generateWeekPlan([], []);
        $this->assertCount(7, $weekPlan);

        $days = array_column($weekPlan, 'day');
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $days);
    }

    public function testGenerateWeekPlanDayStructure(): void
    {
        $weekPlan = $this->service->generateWeekPlan([], []);

        foreach ($weekPlan as $day) {
            $this->assertArrayHasKey('day', $day);
            $this->assertArrayHasKey('theme', $day);
            $this->assertArrayHasKey('focus', $day);
            $this->assertArrayHasKey('actions', $day);
            $this->assertArrayHasKey('kpi_check', $day);
        }
    }

    public function testGenerateWeekPlanCriticalActionsOnDays1And2(): void
    {
        $items = [
            [
                'id' => 'critical1',
                'title' => 'Critical',
                'actions' => [
                    ['prioridade' => AccountGovernanceService::PRIORITY_CRITICA, 'tipo' => 'PAUSAR', 'motivo' => 'test'],
                ],
            ],
        ];

        $weekPlan = $this->service->generateWeekPlan($items, []);

        // Critical action should appear on day 1 or 2
        $day1Actions = $weekPlan[0]['actions'];
        $this->assertNotEmpty($day1Actions);
        $this->assertSame('critical1', $day1Actions[0]['item_id']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // EXECUTIVE SUMMARY
    // ═══════════════════════════════════════════════════════════════════════

    public function testGenerateExecutiveSummaryStructure(): void
    {
        $summary = $this->service->generateExecutiveSummary(
            AccountGovernanceService::STATUS_TRAVADA,
            ['account_conv_30d' => 0.01],
            [
                ['classification' => AccountGovernanceService::CLASS_TOXICO, 'actions' => []],
                ['classification' => AccountGovernanceService::CLASS_SAUDAVEL, 'actions' => []],
            ],
            [['cause' => 'test']]
        );

        $this->assertArrayHasKey('status', $summary);
        $this->assertArrayHasKey('headline', $summary);
        $this->assertArrayHasKey('total_items', $summary);
        $this->assertArrayHasKey('healthy_items', $summary);
        $this->assertArrayHasKey('problem_items', $summary);
        $this->assertArrayHasKey('critical_actions', $summary);
        $this->assertArrayHasKey('top_cause', $summary);
        $this->assertArrayHasKey('account_conv', $summary);
        $this->assertArrayHasKey('classification_breakdown', $summary);
    }

    public function testGenerateExecutiveSummaryCountsCorrectly(): void
    {
        $summary = $this->service->generateExecutiveSummary(
            AccountGovernanceService::STATUS_ESTAVEL,
            ['account_conv_30d' => 0.025],
            [
                ['classification' => AccountGovernanceService::CLASS_ANCHOR, 'actions' => []],
                ['classification' => AccountGovernanceService::CLASS_SAUDAVEL, 'actions' => []],
                ['classification' => AccountGovernanceService::CLASS_TOXICO, 'actions' => [
                    ['prioridade' => AccountGovernanceService::PRIORITY_CRITICA],
                ]],
            ],
            []
        );

        $this->assertSame(3, $summary['total_items']);
        $this->assertSame(2, $summary['healthy_items']);
        $this->assertSame(1, $summary['problem_items']);
        $this->assertSame(1, $summary['critical_actions']);
        $this->assertSame('2.5%', $summary['account_conv']);
    }

    public function testGenerateExecutiveSummaryHeadlineMatchesStatus(): void
    {
        $summary = $this->service->generateExecutiveSummary(
            AccountGovernanceService::STATUS_FORTE,
            [],
            [],
            []
        );

        $this->assertStringContainsString('FORTE', $summary['headline']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SUCCESS CRITERIA
    // ═══════════════════════════════════════════════════════════════════════

    public function testBuildSuccessCriteriaTravada(): void
    {
        $criteria = $this->service->buildSuccessCriteria(
            AccountGovernanceService::STATUS_TRAVADA,
            ['account_conv_30d' => 0.001, 'total_sales_30d' => 100]
        );

        $this->assertArrayHasKey('success', $criteria);
        $this->assertArrayHasKey('rollback', $criteria);
        $this->assertNotEmpty($criteria['success']);
        $this->assertNotEmpty($criteria['rollback']);
    }

    public function testBuildSuccessCriteriaRollbackIncludesSalesThreshold(): void
    {
        $criteria = $this->service->buildSuccessCriteria(
            AccountGovernanceService::STATUS_ESTAVEL,
            ['account_conv_30d' => 0.02, 'total_sales_30d' => 50]
        );

        // Rollback should mention 80% of current sales (40)
        $rollbackStr = implode(' ', $criteria['rollback']);
        $this->assertStringContainsString('40', $rollbackStr);
    }

    public function testBuildSuccessCriteriaVariesByStatus(): void
    {
        $forteSuccess = $this->service->buildSuccessCriteria(
            AccountGovernanceService::STATUS_FORTE,
            ['account_conv_30d' => 0.03, 'total_sales_30d' => 200]
        )['success'];

        $travadaSuccess = $this->service->buildSuccessCriteria(
            AccountGovernanceService::STATUS_TRAVADA,
            ['account_conv_30d' => 0.001, 'total_sales_30d' => 10]
        )['success'];

        $this->assertNotSame($forteSuccess, $travadaSuccess);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FULL PIPELINE
    // ═══════════════════════════════════════════════════════════════════════

    public function testRunFullDiagnosticReturnsCompleteResult(): void
    {
        $accountData = [
            'seller_id' => '123',
            'reputation_level' => 'green',
            'claims_rate' => 0.01,
            'late_shipment_rate' => 0.02,
        ];

        $items = [
            $this->makeItem(['id' => 'MLB1', 'title' => 'Bagageiro CG 160', 'visits_30d' => 500, 'visits_14d' => 250, 'sales_30d' => 20, 'sales_14d' => 10, 'available_quantity' => 15]),
            $this->makeItem(['id' => 'MLB2', 'title' => 'Retrovisor Bros 160', 'visits_30d' => 50, 'visits_14d' => 25, 'sales_30d' => 0, 'sales_14d' => 0, 'available_quantity' => 0]),
            $this->makeItem(['id' => 'MLB3', 'title' => 'Baú 45L Universal', 'visits_30d' => 200, 'visits_14d' => 100, 'sales_30d' => 8, 'sales_14d' => 4, 'available_quantity' => 10]),
        ];

        $result = $this->service->runFullDiagnostic($accountData, $items);

        // Check all top-level keys present
        $expectedKeys = [
            'executive_summary', 'account_status', 'account_metrics',
            'top_causes', 'week_plan', 'recovery_plan', 'density_risks',
            'items', 'success_criteria', 'rollback_criteria',
            'guardrails_applied', 'meta',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }

        // Meta
        $this->assertSame(3, $result['meta']['total_items']);
        $this->assertSame(3, $result['meta']['processed_items']);
        $this->assertSame('1.0.0', $result['meta']['engine_version']);
        $this->assertIsFloat($result['meta']['elapsed_ms']);

        // Processed items have flags/score/classification
        foreach ($result['items'] as $item) {
            $this->assertArrayHasKey('flags', $item);
            $this->assertArrayHasKey('score', $item);
            $this->assertArrayHasKey('classification', $item);
            $this->assertArrayHasKey('actions', $item);
        }
    }

    public function testRunFullDiagnosticThrowsOnInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->runFullDiagnostic(['seller_id' => '123'], [
            $this->makeItem(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function makeItem(array $overrides = []): array
    {
        return array_merge([
            'id' => 'MLB' . mt_rand(1000, 9999),
            'title' => 'Bagageiro CG 160 Titan Fan',
            'price' => 89.90,
            'status' => 'active',
            'available_quantity' => 10,
            'visits_30d' => 100,
            'visits_14d' => 50,
            'sales_30d' => 5,
            'sales_14d' => 3,
            'margin_pct' => 0.10,
        ], $overrides);
    }

    private function allFlagsFalse(): array
    {
        return [
            'HIGH_TRAFFIC' => false,
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
    }

    private function priorityOrder(string $priority): int
    {
        return match ($priority) {
            AccountGovernanceService::PRIORITY_CRITICA => 1,
            AccountGovernanceService::PRIORITY_ALTA => 2,
            AccountGovernanceService::PRIORITY_MEDIA => 3,
            AccountGovernanceService::PRIORITY_BAIXA => 4,
            default => 5,
        };
    }
}
