<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\AccountIndexCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Pregao\AccountIndexCalculator
 */
class AccountIndexCalculatorTest extends TestCase
{
    private AccountIndexCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new AccountIndexCalculator();
    }

    /** @return array{Fv: bool, Fe: bool, Fh: bool, Fr: bool, Ft: bool} */
    private function allActive(): array
    {
        return ['Fv' => true, 'Fe' => true, 'Fh' => true, 'Fr' => true, 'Ft' => true];
    }

    public function testContaPerfeitaAtingeIndiceAlto(): void
    {
        $result = $this->calc->calculate([
            'vendas_7d' => 28,
            'vendas_7d_baseline' => 14, // Fv = 2.0
            'visitas_7d' => 2000,
            'visitas_baseline' => 1000, // Fe = 2.0
            'health_medio' => 1.0,
            'reputacao' => 'verde-escuro',
            'tacos_baseline' => 10,
            'tacos_atual' => 5, // Ft = 2.0
            'available' => $this->allActive(),
        ]);

        // 1000 * (0.4*2 + 0.2*2 + 0.15*1 + 0.15*1 + 0.1*2) = 1700
        $this->assertEqualsWithDelta(1700.0, $result['indice'], 0.01);
        $this->assertSame(5, $result['factors_active']);
        $this->assertSame('5 de 5 fatores ativos', $result['label']);
        $this->assertArrayHasKey('Fe', $result['factors']);
        $this->assertArrayNotHasKey('Fp', $result['factors']);
    }

    public function testContaEmQuedaProduzIndiceBaixo(): void
    {
        $result = $this->calc->calculate([
            'vendas_7d' => 2,
            'vendas_7d_baseline' => 20, // Fv = 0.1
            'visitas_7d' => 100,
            'visitas_baseline' => 1000, // Fe clamp 0.5
            'health_medio' => 0.4,
            'reputacao' => 'vermelho',
            'tacos_baseline' => 8,
            'tacos_atual' => 40,
            'available' => $this->allActive(),
        ]);

        // 1000 * (0.4*0.1 + 0.2*0.5 + 0.15*0.4 + 0.15*0.2 + 0.1*0.5) = 280
        $this->assertEqualsWithDelta(280.0, $result['indice'], 0.01);
        $this->assertLessThan(500.0, $result['indice']);
    }

    public function testSemFatoresAtivosRetornaIndiceNull(): void
    {
        $result = $this->calc->calculate([]);
        $this->assertNull($result['indice']);
        $this->assertSame(0, $result['factors_active']);
        $this->assertSame('0 de 5 fatores ativos', $result['label']);
        $this->assertNull($result['factors']['Fe']);
    }

    public function testRenormalizaSemTacos(): void
    {
        $result = $this->calc->calculate([
            'vendas_7d' => 28,
            'vendas_7d_baseline' => 14,
            'visitas_7d' => 2000,
            'visitas_baseline' => 1000,
            'health_medio' => 1.0,
            'reputacao' => 'verde-escuro',
            'available' => ['Fv' => true, 'Fe' => true, 'Fh' => true, 'Fr' => true, 'Ft' => false],
        ]);

        $this->assertEqualsWithDelta(1666.6667, (float) $result['indice'], 0.01);
        $this->assertSame(4, $result['factors_active']);
        $this->assertNull($result['factors']['Ft']);
        $this->assertSame('4 de 5 fatores ativos', $result['label']);
    }

    public function testFactorExposicaoClamp(): void
    {
        $this->assertEqualsWithDelta(2.0, $this->calc->factorExposicao(9999, 100), 0.001);
        $this->assertEqualsWithDelta(0.5, $this->calc->factorExposicao(1, 1000), 0.001);
        $this->assertEqualsWithDelta(1.0, $this->calc->factorExposicao(100, 100), 0.001);
        // baseline 0 → max(1)
        $this->assertEqualsWithDelta(2.0, $this->calc->factorExposicao(50, 0), 0.001);
    }

    public function testCompatMetaFpMapeiaParaFe(): void
    {
        $result = $this->calc->calculate([
            'visitas_7d' => 200,
            'visitas_baseline' => 100,
            'available' => ['Fp' => true],
        ]);
        $this->assertTrue($result['active']['Fe']);
        $this->assertEqualsWithDelta(2.0, (float) $result['factors']['Fe'], 0.001);
    }

    public function testRenormalizaSoReputacaoEHealth(): void
    {
        $result = $this->calc->calculate([
            'health_medio' => 0.62,
            'reputacao' => '5_green',
            'available' => ['Fv' => false, 'Fe' => false, 'Fh' => true, 'Fr' => true, 'Ft' => false],
        ]);

        $this->assertEqualsWithDelta(810.0, (float) $result['indice'], 0.01);
        $this->assertSame(2, $result['factors_active']);
    }

    public function testMapLevelIdToCor(): void
    {
        $this->assertSame('verde-escuro', $this->calc->mapLevelIdToCor('5_green'));
        $this->assertSame('amarelo', $this->calc->mapLevelIdToCor('3_yellow'));
    }

    public function testDivisaoPorZeroBaselineVendasUsaMax1(): void
    {
        $fv = $this->calc->factorVendas(10, 0);
        $this->assertEqualsWithDelta(10.0, $fv, 0.001);
    }

    public function testDivisaoPorZeroTacosUsaMax01(): void
    {
        $ft = $this->calc->factorTacos(5, 0);
        $this->assertEqualsWithDelta(2.0, $ft, 0.001);
    }

    public function testSemaforoVerdeQuandoAbaixoDe50Pct(): void
    {
        $status = $this->calc->resolveSemaforo(
            ['reclamacoes_pct' => 0.8, 'atrasos_pct' => 3.0, 'cancelamentos_pct' => 0.5],
            ['reclamacoes_pct' => 2.0, 'atrasos_pct' => 15.0, 'cancelamentos_pct' => 2.5]
        );
        $this->assertSame('verde', $status);
    }

    public function testSemaforoAmareloEntre50e80(): void
    {
        $status = $this->calc->resolveSemaforo(
            ['reclamacoes_pct' => 1.2, 'atrasos_pct' => 3.0, 'cancelamentos_pct' => 0.5],
            ['reclamacoes_pct' => 2.0, 'atrasos_pct' => 15.0, 'cancelamentos_pct' => 2.5]
        );
        $this->assertSame('amarelo', $status);
    }

    public function testSemaforoVermelhoAcimaDe80(): void
    {
        $status = $this->calc->resolveSemaforo(
            ['reclamacoes_pct' => 1.8, 'atrasos_pct' => 3.0, 'cancelamentos_pct' => 0.5],
            ['reclamacoes_pct' => 2.0, 'atrasos_pct' => 15.0, 'cancelamentos_pct' => 2.5]
        );
        $this->assertSame('vermelho', $status);
    }

    public function testClamp(): void
    {
        $this->assertSame(0.5, $this->calc->clamp(0.1, 0.5, 2.0));
        $this->assertSame(2.0, $this->calc->clamp(9.0, 0.5, 2.0));
        $this->assertSame(1.2, $this->calc->clamp(1.2, 0.5, 2.0));
    }
}
