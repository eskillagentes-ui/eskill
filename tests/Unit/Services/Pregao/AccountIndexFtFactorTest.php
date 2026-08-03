<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\AccountIndexCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Ft (TACOS) — reativação do 5º fator.
 *
 * @covers \App\Services\Pregao\AccountIndexCalculator::factorTacos
 */
class AccountIndexFtFactorTest extends TestCase
{
    private AccountIndexCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new AccountIndexCalculator();
    }

    public function testComTacosAtivaCincoFatores(): void
    {
        $result = $this->calc->calculate([
            'vendas_7d' => 10,
            'vendas_7d_baseline' => 10,
            'visitas_7d' => 100,
            'visitas_baseline' => 100,
            'health_medio' => 1.0,
            'reputacao' => 'verde-escuro',
            'tacos_baseline' => 10,
            'tacos_atual' => 10,
            'available' => ['Fv' => true, 'Fe' => true, 'Fh' => true, 'Fr' => true, 'Ft' => true],
        ]);
        $this->assertSame(5, $result['factors_active']);
        $this->assertSame('5 de 5 fatores ativos', $result['label']);
        $this->assertNotNull($result['factors']['Ft']);
    }

    public function testSemTacosRenormalizaQuatro(): void
    {
        $result = $this->calc->calculate([
            'vendas_7d' => 10,
            'vendas_7d_baseline' => 10,
            'visitas_7d' => 100,
            'visitas_baseline' => 100,
            'health_medio' => 1.0,
            'reputacao' => 'verde-escuro',
            'available' => ['Fv' => true, 'Fe' => true, 'Fh' => true, 'Fr' => true, 'Ft' => false],
        ]);
        $this->assertSame(4, $result['factors_active']);
        $this->assertNull($result['factors']['Ft']);
    }

    public function testTacosMelhorandoFtMaiorQueUm(): void
    {
        // baseline 10, atual 5 → Ft = 2.0
        $ft = $this->calc->factorTacos(10.0, 5.0);
        $this->assertGreaterThan(1.0, $ft);
        $this->assertEqualsWithDelta(2.0, $ft, 0.001);
    }

    public function testTacosPiorandoFtMenorQueUm(): void
    {
        // baseline 10, atual 20 → Ft = 0.5
        $ft = $this->calc->factorTacos(10.0, 20.0);
        $this->assertLessThan(1.0, $ft);
        $this->assertEqualsWithDelta(0.5, $ft, 0.001);
    }

    public function testTacosZeroUsaMax01(): void
    {
        $ft = $this->calc->factorTacos(10.0, 0.0);
        $this->assertEqualsWithDelta(2.0, $ft, 0.001);
    }

    public function testTacosNuloTratadoComoZeroNoFator(): void
    {
        // calculate com Ft ativo e tacos_atual ausente → usa 0.1 no fator
        $result = $this->calc->calculate([
            'tacos_baseline' => 10,
            'available' => ['Ft' => true],
        ]);
        $this->assertEqualsWithDelta(2.0, (float) $result['factors']['Ft'], 0.001);
    }
}
