<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ads;

use App\Services\Ads\RoasTrioCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Ads\RoasTrioCalculator
 */
class RoasTrioCalculatorTest extends TestCase
{
    private RoasTrioCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new RoasTrioCalculator();
    }

    public function testMargem12Percent(): void
    {
        $trio = $this->calc->fromMargemLiquida(12.0);
        $this->assertTrue($trio['available']);
        $this->assertEqualsWithDelta(8.3333, (float) $trio['roas_breakeven'], 0.01);
        $this->assertEqualsWithDelta(12.5, (float) $trio['roas_objetivo'], 0.01);
        $this->assertEqualsWithDelta(16.6667, (float) $trio['roas_escala'], 0.01);
    }

    public function testMargem18Percent(): void
    {
        $trio = $this->calc->fromMargemLiquida(18.0);
        $this->assertTrue($trio['available']);
        $this->assertEqualsWithDelta(5.5556, (float) $trio['roas_breakeven'], 0.01);
        $this->assertEqualsWithDelta(8.3333, (float) $trio['roas_objetivo'], 0.01);
        $this->assertEqualsWithDelta(11.1111, (float) $trio['roas_escala'], 0.01);
    }

    public function testMargemZeroRetornaNd(): void
    {
        $trio = $this->calc->fromMargemLiquida(0.0);
        $this->assertFalse($trio['available']);
        $this->assertNull($trio['roas_breakeven']);
        $this->assertNull($trio['roas_objetivo']);
        $this->assertNull($trio['roas_escala']);
        $this->assertSame('margem_nao_positiva', $trio['reason']);
    }

    public function testMargemNegativaRetornaNd(): void
    {
        $trio = $this->calc->fromMargemLiquida(-5.0);
        $this->assertFalse($trio['available']);
        $this->assertNull($trio['roas_breakeven']);
    }

    public function testMargemNullRetornaNd(): void
    {
        $trio = $this->calc->fromMargemLiquida(null);
        $this->assertFalse($trio['available']);
        $this->assertSame('margem_ausente', $trio['reason']);
    }

    public function testMarginsFromCustos(): void
    {
        // preco 100, custo 70, frete 5 → bruta 25%; comissão 11 + op 2 → líquida 12%
        $m = $this->calc->marginsFromCustos(70.0, 11.0, 5.0, 2.0, 100.0);
        $this->assertTrue($m['available']);
        $this->assertEqualsWithDelta(25.0, (float) $m['margem_bruta_pct'], 0.01);
        $this->assertEqualsWithDelta(12.0, (float) $m['margem_liquida_pct'], 0.01);
    }

    public function testPrecoInvalido(): void
    {
        $m = $this->calc->marginsFromCustos(10.0, 1.0, 0.0, 0.0, 0.0);
        $this->assertFalse($m['available']);
        $this->assertNull($m['margem_liquida_pct']);
    }
}
