<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\DashboardService;

/**
 * Testes estruturais e comportamentais para DashboardService
 *
 * Verifica: existencia, metodos publicos/privados, retorno de metricas,
 * modo degradado, unwrap ML response, e padroes de fallback.
 *
 * @covers \App\Services\DashboardService
 */
class DashboardServiceTest extends TestCase
{
    // ===========================
    // CLASS STRUCTURE
    // ===========================

    public function test_class_exists(): void
    {
        $this->assertTrue(
            class_exists(DashboardService::class),
            'DashboardService deve existir'
        );
    }

    public function test_can_instantiate(): void
    {
        $service = new DashboardService();
        $this->assertInstanceOf(DashboardService::class, $service);
    }

    // ===========================
    // PUBLIC METHODS
    // ===========================

    public function test_has_getMetrics_method(): void
    {
        $this->assertTrue(
            method_exists(DashboardService::class, 'getMetrics'),
            'DashboardService deve ter metodo getMetrics'
        );
    }

    public function test_getMetrics_accepts_nullable_account_id(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'getMetrics');
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $this->assertEquals('accountId', $params[0]->getName());
        $this->assertTrue($params[0]->allowsNull(), 'accountId deve ser nullable');
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertNull($params[0]->getDefaultValue());
    }

    public function test_getMetrics_returns_array(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'getMetrics');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    // ===========================
    // PRIVATE METHODS EXISTENCE
    // ===========================

    /**
     * @dataProvider privateMethodsProvider
     */
    public function test_has_private_method(string $method): void
    {
        $ref = new \ReflectionClass(DashboardService::class);
        $this->assertTrue(
            $ref->hasMethod($method),
            "DashboardService deve ter metodo privado: {$method}"
        );
        $this->assertTrue(
            (new \ReflectionMethod(DashboardService::class, $method))->isPrivate(),
            "{$method}() deve ser private"
        );
    }

    public static function privateMethodsProvider(): array
    {
        return [
            'ensureOrdersTable'       => ['ensureOrdersTable'],
            'resolvePendingQuestions'  => ['resolvePendingQuestions'],
            'buildDegradedMetrics'    => ['buildDegradedMetrics'],
            'unwrapMlResponse'        => ['unwrapMlResponse'],
        ];
    }

    // ===========================
    // DEGRADED MODE
    // ===========================

    public function test_buildDegradedMetrics_returns_all_expected_keys(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'buildDegradedMetrics');
        $ref->setAccessible(true);

        $service = new DashboardService();
        $result = $ref->invoke($service, null);

        $expectedKeys = [
            'recent_orders_count', 'total_revenue', 'net_profit',
            'has_real_cogs', 'cogs_status',
            'orders_by_status', 'sales_over_time',
            'total_items', 'active_items',
            'pending_questions', 'expiring_tokens',
            'reputation_metrics', 'warning',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result,
                "Metricas degradadas devem incluir chave: {$key}");
        }
    }

    public function test_buildDegradedMetrics_has_zero_financial_values(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'buildDegradedMetrics');
        $ref->setAccessible(true);

        $service = new DashboardService();
        $result = $ref->invoke($service, null);

        $this->assertEquals(0, $result['recent_orders_count']);
        $this->assertEquals(0.0, $result['total_revenue']);
        $this->assertNull($result['net_profit']);
        $this->assertFalse($result['has_real_cogs']);
        $this->assertSame('sem_cmv', $result['cogs_status']);
        $this->assertEmpty($result['orders_by_status']);
        $this->assertEmpty($result['sales_over_time']);
    }

    public function test_buildDegradedMetrics_includes_warning(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'buildDegradedMetrics');
        $ref->setAccessible(true);

        $service = new DashboardService();
        $result = $ref->invoke($service, null);

        $this->assertArrayHasKey('warning', $result);
        $this->assertNotEmpty($result['warning']);
        $this->assertStringContainsString('degradado', $result['warning']);
    }

    public function test_buildDegradedMetrics_with_account_id(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'buildDegradedMetrics');
        $ref->setAccessible(true);

        $service = new DashboardService();
        $result = $ref->invoke($service, 42);

        // Deve retornar as mesmas chaves independente do accountId
        $this->assertArrayHasKey('recent_orders_count', $result);
        $this->assertArrayHasKey('total_items', $result);
    }

    // ===========================
    // UNWRAP ML RESPONSE
    // ===========================

    public function test_unwrapMlResponse_extracts_body(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'unwrapMlResponse');
        $ref->setAccessible(true);

        $service = new DashboardService();
        $input = ['body' => ['seller_reputation' => ['level_id' => '5_green']]];
        $result = $ref->invoke($service, $input);

        $this->assertEquals(['seller_reputation' => ['level_id' => '5_green']], $result);
    }

    public function test_unwrapMlResponse_returns_raw_on_error(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'unwrapMlResponse');
        $ref->setAccessible(true);

        $service = new DashboardService();
        $input = ['error' => 'not_found', 'message' => 'User not found'];
        $result = $ref->invoke($service, $input);

        $this->assertEquals($input, $result);
    }

    public function test_unwrapMlResponse_returns_raw_when_no_body(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'unwrapMlResponse');
        $ref->setAccessible(true);

        $service = new DashboardService();
        $input = ['nickname' => 'SELLER123', 'id' => 12345];
        $result = $ref->invoke($service, $input);

        $this->assertEquals($input, $result);
    }

    public function test_unwrapMlResponse_handles_non_array_body(): void
    {
        $ref = new \ReflectionMethod(DashboardService::class, 'unwrapMlResponse');
        $ref->setAccessible(true);

        $service = new DashboardService();
        $input = ['body' => 'string_body', 'status' => 200];
        $result = $ref->invoke($service, $input);

        // body nao e array, deve retornar o raw
        $this->assertEquals($input, $result);
    }

    // ===========================
    // METRICS STRUCTURE — SOURCE ANALYSIS
    // ===========================

    public function test_getMetrics_queries_orders_data(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('ml_orders', $source,
            'getMetrics deve consultar tabela ml_orders');
        $this->assertStringContainsString('total_amount', $source);
        $this->assertStringContainsString('net_profit', $source);
    }

    public function test_getMetrics_uses_30_day_window(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('INTERVAL 30 DAY', $source,
            'getMetrics deve usar janela de 30 dias para pedidos');
    }

    public function test_getMetrics_includes_reputation(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('seller_reputation', $source,
            'getMetrics deve buscar reputacao do vendedor');
        $this->assertStringContainsString('reputation_metrics', $source);
    }

    public function test_getMetrics_includes_items_stats(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('ItemService', $source,
            'getMetrics deve usar ItemService para stats de itens');
        $this->assertStringContainsString('getItemsStats', $source);
    }

    public function test_getMetrics_includes_pending_questions(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('pending_questions', $source);
        $this->assertStringContainsString('UNANSWERED', $source,
            'Deve buscar perguntas com status UNANSWERED');
    }

    public function test_getMetrics_includes_expiring_tokens(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('expiring_tokens', $source);
        $this->assertStringContainsString('token_expires_at', $source);
    }

    // ===========================
    // FALLBACK PATTERNS
    // ===========================

    public function test_has_degraded_mode_fallback(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('buildDegradedMetrics', $source);
        $this->assertStringContainsString('modo degradado', $source);
    }

    public function test_resolvePendingQuestions_reads_local_ml_questions(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('ml_questions', $source,
            'Deve contar perguntas na tabela ml_questions');
        $this->assertStringNotContainsString('QuestionService', $source,
            'Card de perguntas do dashboard não é mais API-first');
        $this->assertStringContainsString('MissingCogsPolicy', $source);
        $this->assertStringContainsString('presentSalesOverTimeProfit', $source);
    }

    // ===========================
    // STRUCTURED LOGGING
    // ===========================

    public function test_uses_structured_logging(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('Log::warning', $source,
            'DashboardService deve usar Log::warning para erros nao-fatais');
    }

    // ===========================
    // STRICT TYPES
    // ===========================

    public function test_has_strict_types(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $source,
            'DashboardService deve ter declare(strict_types=1)');
    }

    // ===========================
    // PREPARED STATEMENTS
    // ===========================

    public function test_uses_prepared_statements(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        $prepareCount = substr_count($source, '->prepare(');
        $this->assertGreaterThan(3, $prepareCount,
            'DashboardService deve usar prepared statements (> 3x)');
        $this->assertStringContainsString(':account_id', $source,
            'Deve usar named parameters');
    }

    public function test_no_direct_variable_interpolation_in_sql(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/DashboardService.php');
        // Verifica que nao ha interpolacao direta de variaveis em queries SQL
        // Exceto $ordersWhere que e construido internamente com placeholders
        $this->assertDoesNotMatchRegularExpression(
            '/prepare\(["\'].*\$(?!ordersWhere|tWhere|sql)/',
            $source,
            'Nao deve ter interpolacao de variaveis externas em queries SQL'
        );
    }
}
