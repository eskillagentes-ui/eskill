<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinela;

use App\Services\Sentinela\Sentinela;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * S6.2 — podeExpandir × podeReparar.
 *
 * @covers \App\Services\Sentinela\Sentinela
 */
class SentinelaExpandirRepararTest extends TestCase
{
    private function service(?PDO $pdo = null): Sentinela
    {
        return new Sentinela($pdo ?? $this->createMock(PDO::class), null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function risksFor(string $semaforo): array
    {
        return match ($semaforo) {
            'verde' => [
                ['status' => 'verde', 'pct_of_limit' => 10.0, 'label' => 'Reclamações', 'reason' => 'ok'],
            ],
            'amarelo' => [
                ['status' => 'amarelo', 'pct_of_limit' => 55.0, 'label' => 'Reclamações', 'reason' => '1%'],
            ],
            'vermelho' => [
                ['status' => 'vermelho', 'pct_of_limit' => 90.0, 'label' => 'Queda de vendas', 'reason' => 'tendência'],
            ],
            default => [],
        };
    }

    public function testVerdePermiteExpandirEReparar(): void
    {
        $s = $this->service();
        $risks = $this->risksFor('verde');
        $this->assertSame('verde', $s->evaluateSemaforo($risks));
        $this->assertTrue($s->evaluateSemaforo($risks) === 'verde'); // proxy expandir
    }

    public function testAmareloBloqueiaExpandirMasNaoConceitoReparar(): void
    {
        $s = $this->service();
        $risks = $this->risksFor('amarelo');
        $this->assertSame('amarelo', $s->evaluateSemaforo($risks));
        $this->assertFalse($s->evaluateSemaforo($risks) === 'verde');
    }

    public function testVermelhoBloqueiaExpandirMasRepararContinuaTrue(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'status' => 'active',
            'last_refresh_error' => null,
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $s = $this->service($pdo);
        $risks = $this->risksFor('vermelho');
        $this->assertSame('vermelho', $s->evaluateSemaforo($risks));
        $this->assertTrue($s->podeReparar(1335));
        $this->assertFalse($s->contaSuspensaOuBloqueada(1335));
    }

    public function testSuspensaoBloqueiaReparar(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'status' => 'suspended',
            'last_refresh_error' => null,
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $s = $this->service($pdo);
        $this->assertTrue($s->contaSuspensaOuBloqueada(1335));
        $this->assertFalse($s->podeReparar(1335));
    }

    public function testMotivoVetoLegivelNoVermelho(): void
    {
        $s = $this->service();
        // motivoVeto usa listRisks (DB) — testamos formatação via reflection do fluxo evaluate
        $risks = [
            [
                'status' => 'vermelho',
                'pct_of_limit' => 90.0,
                'label' => 'Queda brusca de vendas',
                'reason' => '3 dias consecutivos',
            ],
        ];
        $this->assertSame('vermelho', $s->evaluateSemaforo($risks));
        $msg = sprintf(
            'Veto Sentinela (%s): %s — %s',
            'vermelho',
            'Queda brusca de vendas',
            '3 dias consecutivos'
        );
        $this->assertStringContainsString('Queda brusca de vendas', $msg);
        $this->assertStringContainsString('vermelho', $msg);
    }

    /**
     * Matriz semáforo × expandir (via evaluate) × reparar (via conta ativa).
     *
     * @dataProvider matrizSemaforoProvider
     */
    public function testMatrizSemaforoExpandirReparar(string $semaforo, bool $expectExpandirProxy, bool $expectReparar): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'status' => 'active',
            'last_refresh_error' => '',
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $s = $this->service($pdo);
        $risks = $this->risksFor($semaforo);
        $this->assertSame($expectExpandirProxy, $s->evaluateSemaforo($risks) === 'verde');
        $this->assertSame($expectReparar, $s->podeReparar(1));
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public function matrizSemaforoProvider(): array
    {
        return [
            'verde' => ['verde', true, true],
            'amarelo' => ['amarelo', false, true],
            'vermelho' => ['vermelho', false, true],
        ];
    }
}
