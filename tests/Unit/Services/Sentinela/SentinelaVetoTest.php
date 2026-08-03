<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinela;

use App\Services\Sentinela\Sentinela;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Sentinela\Sentinela
 */
class SentinelaVetoTest extends TestCase
{
    private function service(): Sentinela
    {
        return new Sentinela($this->createMock(PDO::class), null);
    }

    public function testSemaforoVerdePermiteExpandir(): void
    {
        $s = $this->service();
        $risks = [
            ['status' => 'verde', 'pct_of_limit' => 10.0, 'label' => 'Reclamações', 'reason' => 'ok'],
            ['status' => 'verde', 'pct_of_limit' => 5.0, 'label' => 'Atrasos', 'reason' => 'ok'],
            ['status' => 'nd', 'pct_of_limit' => null, 'label' => 'NF pendente', 'reason' => 'n/d'],
        ];
        $this->assertSame('verde', $s->evaluateSemaforo($risks));
        $this->assertTrue($s->evaluateSemaforo($risks) === 'verde');
    }

    public function testSemaforoAmareloVeta(): void
    {
        $s = $this->service();
        $risks = [
            ['status' => 'verde', 'pct_of_limit' => 10.0, 'label' => 'Reputação', 'reason' => 'ok'],
            ['status' => 'amarelo', 'pct_of_limit' => 55.0, 'label' => 'Reclamações', 'reason' => '1%'],
            ['status' => 'nd', 'pct_of_limit' => null, 'label' => 'NF pendente', 'reason' => 'n/d'],
        ];
        $this->assertSame('amarelo', $s->evaluateSemaforo($risks));
        $this->assertFalse($s->evaluateSemaforo($risks) === 'verde');
    }

    public function testSemaforoVermelhoVeta(): void
    {
        $s = $this->service();
        $risks = [
            ['status' => 'verde', 'pct_of_limit' => 10.0, 'label' => 'Reputação', 'reason' => 'ok'],
            ['status' => 'vermelho', 'pct_of_limit' => 90.0, 'label' => 'Moderação', 'reason' => '3 itens'],
        ];
        $this->assertSame('vermelho', $s->evaluateSemaforo($risks));
        $this->assertFalse($s->evaluateSemaforo($risks) === 'verde');
    }

    public function testNdNaoPioraSemaforoSozinho(): void
    {
        $s = $this->service();
        $risks = [
            ['status' => 'nd', 'pct_of_limit' => null, 'label' => 'NF', 'reason' => 'n/d'],
            ['status' => 'nd', 'pct_of_limit' => null, 'label' => 'Chargeback', 'reason' => 'n/d'],
        ];
        $this->assertSame('verde', $s->evaluateSemaforo($risks));
    }

    public function testPctAcimaDe80ForcaVermelho(): void
    {
        $s = $this->service();
        $risks = [
            ['status' => 'amarelo', 'pct_of_limit' => 85.0, 'label' => 'Rate limit', 'reason' => '3x'],
        ];
        $this->assertSame('vermelho', $s->evaluateSemaforo($risks));
    }
}
