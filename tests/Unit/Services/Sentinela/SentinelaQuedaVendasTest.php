<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinela;

use App\Services\Sentinela\Sentinela;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * S6.1 — calibração de queda_vendas.
 *
 * @covers \App\Services\Sentinela\Sentinela
 */
class SentinelaQuedaVendasTest extends TestCase
{
    private function service(): Sentinela
    {
        return new Sentinela($this->createMock(PDO::class), null);
    }

    /**
     * @param array<string, int> $seed
     * @return array<string, int>
     */
    private function fillSpan(array $seed, string $from, string $to): array
    {
        $out = [];
        $t = strtotime($from);
        $end = strtotime($to);
        while ($t <= $end) {
            $d = date('Y-m-d', $t);
            $out[$d] = $seed[$d] ?? 0;
            $t = strtotime('+1 day', $t);
        }
        return $out;
    }

    public function testDomingoZeradoComDomingosAnterioresBaixosNuncaVermelho(): void
    {
        // today = segunda 2026-08-03; ontem = domingo 2026-08-02
        $today = '2026-08-03';
        $seed = [
            // domingos anteriores também baixos (0–1)
            '2026-07-05' => 1,
            '2026-07-12' => 0,
            '2026-07-19' => 1,
            '2026-07-26' => 0,
            '2026-08-02' => 0, // domingo sob análise
            // dias úteis normais
            '2026-07-28' => 4,
            '2026-07-29' => 3,
            '2026-07-30' => 5,
            '2026-07-31' => 3,
        ];
        $byDate = $this->fillSpan($seed, '2026-07-01', '2026-08-02');
        foreach ($seed as $d => $c) {
            $byDate[$d] = $c;
        }

        $eval = $this->service()->evaluateQuedaVendasFromHistory($byDate, $today);
        $this->assertNotSame('vermelho', $eval['status'], 'domingo estruturalmente fraco não pode ser vermelho isolado');
        $this->assertContains($eval['status'], ['verde', 'amarelo']);
        $this->assertSame('2026-08-02', $eval['last_closed_day']);
    }

    public function testTresDiasUteisConsecutivosComQuedaGeraVermelho(): void
    {
        // today = quinta 2026-08-06; fechados: qua 05, ter 04, seg 03 — todos com queda >40% vs mesmo DOW
        $today = '2026-08-06';
        $byDate = $this->fillSpan([], '2026-06-01', '2026-08-05');

        // Baseline forte nos mesmos DOWs das 4 semanas anteriores
        foreach (['2026-07-06', '2026-07-13', '2026-07-20', '2026-07-27'] as $d) { // segundas
            $byDate[$d] = 10;
        }
        foreach (['2026-07-07', '2026-07-14', '2026-07-21', '2026-07-28'] as $d) { // terças
            $byDate[$d] = 10;
        }
        foreach (['2026-07-08', '2026-07-15', '2026-07-22', '2026-07-29'] as $d) { // quartas
            $byDate[$d] = 10;
        }
        // Semana atual: queda brusca
        $byDate['2026-08-03'] = 1; // segunda −90%
        $byDate['2026-08-04'] = 1; // terça −90%
        $byDate['2026-08-05'] = 1; // quarta −90%

        $eval = $this->service()->evaluateQuedaVendasFromHistory($byDate, $today);
        $this->assertSame('vermelho', $eval['status']);
        $this->assertGreaterThanOrEqual(3, $eval['consecutive_below']);
    }

    public function testAmostraReduzidaSinalizadaQuandoMenosDe4Ocorrencias(): void
    {
        $today = '2026-08-03';
        // só 2 segundas anteriores no span
        $byDate = [
            '2026-07-20' => 5, // segunda
            '2026-07-27' => 5, // segunda
            '2026-08-02' => 0, // domingo — poucos peers
            '2026-07-26' => 1, // domingo peer
        ];
        // preencher para span conhecido
        $byDate = $this->fillSpan($byDate, '2026-07-20', '2026-08-02');
        $byDate['2026-07-20'] = 5;
        $byDate['2026-07-27'] = 5;
        $byDate['2026-07-26'] = 1;
        $byDate['2026-08-02'] = 0;

        $eval = $this->service()->evaluateQuedaVendasFromHistory($byDate, $today);
        $this->assertTrue($eval['amostra_reduzida']);
        $this->assertLessThan(4, $eval['amostra_n']);
        $this->assertStringContainsString('amostra reduzida', $eval['reason']);
    }

    public function testDiaCorrenteIncompletoNaoInfluencia(): void
    {
        $today = '2026-08-03';
        $byDate = $this->fillSpan([
            '2026-07-27' => 8,
            '2026-07-28' => 8,
            '2026-07-29' => 8,
            '2026-07-30' => 8,
            '2026-07-31' => 8,
            '2026-08-01' => 8,
            '2026-08-02' => 8,
            // dia corrente com 0 — se entrasse, distorceria
            '2026-08-03' => 0,
        ], '2026-07-01', '2026-08-03');

        $withToday = $this->service()->evaluateQuedaVendasFromHistory($byDate, $today);
        unset($byDate['2026-08-03']);
        $withoutToday = $this->service()->evaluateQuedaVendasFromHistory($byDate, $today);

        $this->assertSame($withoutToday['status'], $withToday['status']);
        $this->assertSame($withoutToday['last_closed_day'], $withToday['last_closed_day']);
        $this->assertSame('2026-08-02', $withToday['last_closed_day']);
        $this->assertSame($withoutToday['drop_pct'], $withToday['drop_pct']);
    }

    public function testUmUnicoDiaAbaixoFicaAmareloNaoVermelho(): void
    {
        $today = '2026-08-04'; // terça
        $byDate = $this->fillSpan([], '2026-06-01', '2026-08-03');
        foreach (['2026-07-06', '2026-07-13', '2026-07-20', '2026-07-27'] as $d) {
            $byDate[$d] = 10; // segundas fortes
        }
        // só ontem (segunda) caiu; terça anterior normal implícita 0 vs 0?
        $byDate['2026-08-03'] = 1; // segunda fraca
        // terças anteriores OK e 2026-08-04 excluído (today)
        foreach (['2026-07-07', '2026-07-14', '2026-07-21', '2026-07-28'] as $d) {
            $byDate[$d] = 10;
        }

        $eval = $this->service()->evaluateQuedaVendasFromHistory($byDate, $today);
        $this->assertSame('amarelo', $eval['status']);
        $this->assertSame(1, $eval['consecutive_below']);
    }
}
