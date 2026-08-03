<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pregao;

use App\Services\Pregao\PregaoSnapshotService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \App\Services\Pregao\PregaoSnapshotService
 */
class PregaoSnapshotFtAvailabilityTest extends TestCase
{
    public function testFtAtivoQuandoTacosAvailableTrue(): void
    {
        $svc = new PregaoSnapshotService();
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
        $svc = new PregaoSnapshotService();
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
        $svc = new PregaoSnapshotService();
        $method = new ReflectionMethod(PregaoSnapshotService::class, 'factorAvailability');
        $method->setAccessible(true);

        $available = $method->invoke($svc, [
            'available' => ['Fv' => true, 'Ft' => true],
            'metrics' => [],
        ]);

        $this->assertFalse($available['Ft']);
    }
}
