<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ads;

use App\Services\Ads\RoasTrioCalculator;
use App\Services\Ads\SkuCustoService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Ads\SkuCustoService
 */
final class SkuCustoServiceTest extends TestCase
{
    public function testUpsertRejectsInvalidMlbId(): void
    {
        $svc = new SkuCustoService($this->createMock(PDO::class), new RoasTrioCalculator());
        $this->expectException(\InvalidArgumentException::class);
        $svc->upsert(1, ['mlb_id' => 'SKU-1', 'custo_produto' => 10, 'preco_minimo' => 50]);
    }

    public function testRoasTrioWithoutCustoReturnsNd(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $svc = new SkuCustoService($db, new RoasTrioCalculator());
        $trio = $svc->roasTrio(1, 'mlb123');

        $this->assertSame('MLB123', $trio['mlb_id']);
        $this->assertFalse($trio['has_custo']);
        $this->assertNull($trio['roas_breakeven']);
        $this->assertSame('custo_nao_cadastrado', $trio['reason']);
    }

    public function testRoasTrioWithCustoUsesCalculator(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'account_id' => 1,
            'mlb_id' => 'MLB1',
            'custo_produto' => 40,
            'comissao_pct' => 16,
            'frete_medio' => 10,
            'custos_operacionais_pct' => 5,
            'preco_minimo' => 100,
            'margem_bruta_pct' => 50.0,
            'margem_liquida_pct' => 30.0,
        ]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $svc = new SkuCustoService($db, new RoasTrioCalculator());
        $trio = $svc->roasTrio(1, 'MLB1');

        $this->assertTrue($trio['has_custo']);
        $this->assertSame(30.0, $trio['margem_liquida_pct']);
        $this->assertNotNull($trio['roas_breakeven']);
        $this->assertGreaterThan(0, (float) $trio['roas_breakeven']);
    }

    public function testCountByAccount(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('3');

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($stmt);

        $this->assertSame(3, (new SkuCustoService($db))->countByAccount(9));
    }
}
