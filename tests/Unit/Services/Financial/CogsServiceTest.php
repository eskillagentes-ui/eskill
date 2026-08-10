<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\CogsService;
use PHPUnit\Framework\TestCase;

final class CogsServiceTest extends TestCase
{
    public function testUpsertRejectsInvalidMlb(): void
    {
        $service = new CogsService();
        $result = $service->upsertUnitCost(1335, 'INVALID', 10.0);

        $this->assertFalse($result['success']);
        $this->assertSame('none', $result['fonte']);
        $this->assertSame('MLB inválido', $result['message'] ?? null);
    }

    public function testUpsertRejectsNegativeCost(): void
    {
        $service = new CogsService();
        $result = $service->upsertUnitCost(1335, 'MLB4435358255', -1.0);

        $this->assertFalse($result['success']);
        $this->assertSame('Custo não pode ser negativo', $result['message'] ?? null);
    }

    public function testImportCsvSkipsHeaderAndReportsInvalidRows(): void
    {
        $service = new CogsService();
        $result = $service->importCsv(1335, "mlb,custo\nMLB123,abc\nnot-a-row\n");

        $this->assertSame(0, $result['imported']);
        $this->assertGreaterThanOrEqual(2, count($result['failed']));
        $this->assertFalse($result['success']);
    }
}
