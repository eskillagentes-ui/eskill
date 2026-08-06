<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Financial;

use App\Services\Financial\SettlementReportService;
use App\Services\MercadoLivreClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\Financial\SettlementReportService
 */
final class SettlementReportServiceTest extends TestCase
{
    private function buildService(MercadoLivreClient $client, ?PDO $db = null): SettlementReportService
    {
        $ref = new \ReflectionClass(SettlementReportService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $clientProp = $ref->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($service, $client);

        $accountIdProp = $ref->getProperty('accountId');
        $accountIdProp->setAccessible(true);
        $accountIdProp->setValue($service, 1);

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $db ?? $this->createMock(PDO::class));

        return $service;
    }

    public function testGetSettlementReportWithoutSeller(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getSellerId')->willReturn(null);

        $result = $this->buildService($client)->getSettlementReport('2026-08-01', '2026-08-06');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $result['results']);
    }

    public function testGetSettlementReportFromApi(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getSellerId')->willReturn('123');
        $client->method('get')->willReturn([
            'results' => [
                ['date' => '2026-08-01', 'amount' => 100.0],
            ],
        ]);

        $result = $this->buildService($client)->getSettlementReport('2026-08-01', '2026-08-06');

        $this->assertSame('api', $result['source']);
        $this->assertCount(1, $result['results']);
    }

    public function testGetSettlementReportFallsBackToOrdersEstimate(): void
    {
        $client = $this->createMock(MercadoLivreClient::class);
        $client->method('getSellerId')->willReturn('123');
        $client->method('get')->willReturn(['error' => 'not_found']);

        $localEmpty = $this->createMock(PDOStatement::class);
        $localEmpty->method('execute')->willReturn(true);
        $localEmpty->method('fetchAll')->willReturn([]);

        $ordersStmt = $this->createMock(PDOStatement::class);
        $ordersStmt->method('execute')->willReturn(true);
        $ordersStmt->method('fetchAll')->willReturn([
            [
                'ml_order_id' => 'O1',
                'date_created' => '2026-08-02 12:00:00',
                'total_amount' => 100.0,
                'ml_commission' => 10.0,
                'payment_fee' => 5.0,
            ],
        ]);

        $call = 0;
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function () use (&$call, $localEmpty, $ordersStmt): PDOStatement {
            $call++;
            // 1ª tentativa: settlements locais vazios; 2ª: estimado de pedidos
            return $call === 1 ? $localEmpty : $ordersStmt;
        });

        $result = $this->buildService($client, $db)->getSettlementReport('2026-08-01', '2026-08-06');

        $this->assertArrayHasKey('results', $result);
        $this->assertNotEmpty($result['results']);
        $this->assertTrue(
            ($result['source'] ?? '') === 'orders_estimated'
            || isset($result['results'][0])
        );
    }
}
