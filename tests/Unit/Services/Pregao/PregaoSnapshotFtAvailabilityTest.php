<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\AccountIndexCalculator;
use App\Services\Pregao\PregaoSnapshotService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \App\Services\Pregao\PregaoSnapshotService
 */
class PregaoSnapshotFtAvailabilityTest extends TestCase
{
    private function serviceWithoutDatabase(): PregaoSnapshotService
    {
        return (new ReflectionClass(PregaoSnapshotService::class))->newInstanceWithoutConstructor();
    }

    public function testFtAtivoQuandoTacosAvailableTrue(): void
    {
        $svc = $this->serviceWithoutDatabase();
        $method = new ReflectionMethod(PregaoSnapshotService::class, 'factorAvailability');
        $method->setAccessible(true);

        $available = $method->invoke($svc, [
            'available' => ['Fv' => true, 'Fe' => true, 'Fh' => true, 'Fr' => true, 'Ft' => true],
            'metrics' => ['tacos' => ['available' => true, 'value' => 7.0]],
        ]);

        $this->assertTrue($available['Ft']);
        $this->assertTrue($available['Fv']);
    }

    public function testFtInativoQuandoTacosUnavailable(): void
    {
        $svc = $this->serviceWithoutDatabase();
        $method = new ReflectionMethod(PregaoSnapshotService::class, 'factorAvailability');
        $method->setAccessible(true);

        $available = $method->invoke($svc, [
            'available' => ['Fv' => true, 'Fe' => true, 'Fh' => true, 'Fr' => true, 'Ft' => true],
            'metrics' => ['tacos' => ['available' => false, 'reason' => 'ads_pending']],
        ]);

        $this->assertFalse($available['Ft']);
    }

    public function testFtInativoSemMetaTacos(): void
    {
        $svc = $this->serviceWithoutDatabase();
        $method = new ReflectionMethod(PregaoSnapshotService::class, 'factorAvailability');
        $method->setAccessible(true);

        $available = $method->invoke($svc, [
            'available' => ['Fv' => true, 'Ft' => true],
            'metrics' => [],
        ]);

        $this->assertFalse($available['Ft']);
    }

    public function testFtInativoENaoContribuiQuandoTacosNulo(): void
    {
        $svc = $this->serviceWithoutDatabase();
        $method = new ReflectionMethod(PregaoSnapshotService::class, 'factorAvailability');
        $method->setAccessible(true);

        $available = $method->invoke($svc, [
            'available' => ['Fv' => true, 'Ft' => true],
            'metrics' => ['tacos' => ['available' => true, 'value' => null]],
        ]);
        $result = (new AccountIndexCalculator())->calculate([
            'vendas_7d' => 10,
            'vendas_7d_baseline' => 10,
            'available' => $available,
        ]);

        $this->assertFalse($available['Ft']);
        $this->assertNull($result['factors']['Ft']);
        $this->assertSame(1, $result['factors_active']);
    }
}
