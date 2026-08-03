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

    public function testContaPerfeitaAtingeIndiceAlto(): void
    {
        $result = $this->calc->calculate([
            'vendas_7d' => 28,
            'vendas_7d_baseline' => 14, // Fv = 2.0
            'pos_baseline' => 10,
            'pos_media_atual' => 5, // Fp = 2.0
            'health_medio' => 1.0, // Fh = 1.0
            'reputacao' => 'verde-escuro', // Fr = 1.0
            'tacos_baseline' => 10,
            'tacos_atual' => 5, // Ft = 2.0
        ]);

        // 1000 * (0.4*2 + 0.2*2 + 0.15*1 + 0.15*1 + 0.1*2) = 1000 * 1.7 = 1700
        $this->assertEqualsWithDelta(1700.0, $result['indice'], 0.01);
        $this->assertEqualsWithDelta(2.0, $result['factors']['Fv'], 0.001);
        $this->assertEqualsWithDelta(2.0, $result['factors']['Fp'], 0.001);
        $this->assertEqualsWithDelta(1.0, $result['factors']['Fh'], 0.001);
        $this->assertEqualsWithDelta(1.0, $result['factors']['Fr'], 0.001);
        $this->assertEqualsWithDelta(2.0, $result['factors']['Ft'], 0.001);
    }

    public function testContaEmQuedaProduzIndiceBaixo(): void
    {
        $result = $this->calc->calculate([
            'vendas_7d' => 2,
            'vendas_7d_baseline' => 20, // Fv = 0.1
            'pos_baseline' => 5,
            'pos_media_atual' => 20, // Fp clamp 0.5
            'health_medio' => 0.4,
            'reputacao' => 'vermelho', // Fr = 0.2
            'tacos_baseline' => 8,
            'tacos_atual' => 40, // Ft clamp 0.5
        ]);

        // 1000 * (0.4*0.1 + 0.2*0.5 + 0.15*0.4 + 0.15*0.2 + 0.1*0.5)
        // = 1000 * (0.04 + 0.1 + 0.06 + 0.03 + 0.05) = 280
        $this->assertEqualsWithDelta(280.0, $result['indice'], 0.01);
        $this->assertLessThan(500.0, $result['indice']);
    }

    public function testDivisaoPorZeroBaselineVendasUsaMax1(): void
    {
        $fv = $this->calc->factorVendas(10, 0);
        $this->assertEqualsWithDelta(10.0, $fv, 0.001);
    }

    public function testDivisaoPorZeroPosicaoNaoQuebra(): void
    {
        $fp = $this->calc->factorPosicao(10, 0);
        $this->assertEqualsWithDelta(2.0, $fp, 0.001); // clamp max
    }

    public function testDivisaoPorZeroTacosUsaMax01(): void
    {
        $ft = $this->calc->factorTacos(5, 0);
        // 5 / 0.1 = 50 → clamp 2.0
        $this->assertEqualsWithDelta(2.0, $ft, 0.001);
    }

    public function testCalculateComInputsVaziosNaoLanca(): void
    {
        $result = $this->calc->calculate([]);
        $this->assertIsFloat($result['indice']);
        $this->assertGreaterThan(0.0, $result['indice']);
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
        // 1.2/2.0 = 0.6 → amarelo
        $this->assertSame('amarelo', $status);
    }

    public function testSemaforoVermelhoAcimaDe80(): void
    {
        $status = $this->calc->resolveSemaforo(
            ['reclamacoes_pct' => 1.8, 'atrasos_pct' => 3.0, 'cancelamentos_pct' => 0.5],
            ['reclamacoes_pct' => 2.0, 'atrasos_pct' => 15.0, 'cancelamentos_pct' => 2.5]
        );
        // 1.8/2.0 = 0.9 → vermelho
        $this->assertSame('vermelho', $status);
    }

    public function testClamp(): void
    {
        $this->assertSame(0.5, $this->calc->clamp(0.1, 0.5, 2.0));
        $this->assertSame(2.0, $this->calc->clamp(9.0, 0.5, 2.0));
        $this->assertSame(1.2, $this->calc->clamp(1.2, 0.5, 2.0));
    }
}
