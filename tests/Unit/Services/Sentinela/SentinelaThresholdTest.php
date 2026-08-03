<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinela;

use App\Services\Sentinela\Sentinela;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Cobertura dos limiares 50%/80% nos riscos percentuais (S2).
 *
 * @covers \App\Services\Sentinela\Sentinela
 */
class SentinelaThresholdTest extends TestCase
{
    /**
     * @return array{0: string, 1: float, 2: string}
     */
    private function statusFromPct(string $key, float $value, ?float $limit): array
    {
        $s = new Sentinela($this->createMock(\PDO::class), null);
        $m = new ReflectionMethod(Sentinela::class, 'statusFromPct');
        $m->setAccessible(true);
        /** @var array{0: string, 1: float, 2: string} $out */
        $out = $m->invoke($s, $key, $value, $limit);
        return $out;
    }

    public function testReclamacoesAmareloEm1Pct(): void
    {
        [$status, $pct] = $this->statusFromPct('reclamacoes', 1.0, 2.0);
        $this->assertSame('amarelo', $status);
        $this->assertSame(50.0, $pct);
    }

    public function testAtrasosVermelhoEm12Pct(): void
    {
        [$status] = $this->statusFromPct('atrasos', 12.0, 15.0);
        $this->assertSame('vermelho', $status);
    }

    public function testCancelamentosVerdeAbaixoDe1Pct(): void
    {
        [$status, $pct] = $this->statusFromPct('cancelamentos', 0.5, 2.5);
        $this->assertSame('verde', $status);
        $this->assertSame(20.0, $pct);
    }
}
