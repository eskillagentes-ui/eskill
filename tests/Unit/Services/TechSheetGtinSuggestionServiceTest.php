<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EanService;
use App\Services\TechSheetGtinSuggestionService;
use App\Services\TechSheetService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\TechSheetGtinSuggestionService
 */
class TechSheetGtinSuggestionServiceTest extends TestCase
{
    private function buildService(?EanService $ean = null, ?TechSheetService $tech = null): TechSheetGtinSuggestionService
    {
        $ref = new \ReflectionClass(TechSheetGtinSuggestionService::class);
        /** @var TechSheetGtinSuggestionService $service */
        $service = $ref->newInstanceWithoutConstructor();

        $accountProp = $ref->getProperty('accountId');
        $accountProp->setAccessible(true);
        $accountProp->setValue($service, 9999);

        if ($ean !== null) {
            $eanProp = $ref->getProperty('eanService');
            $eanProp->setAccessible(true);
            $eanProp->setValue($service, $ean);
        }

        if ($tech !== null) {
            $techProp = $ref->getProperty('techSheet');
            $techProp->setAccessible(true);
            $techProp->setValue($service, $tech);
        }

        return $service;
    }

    private function invoke(object $service, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($service, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($service, $args);
    }

    public function testResolveEmptyGtinReasonPrefersNoRegistrado(): void
    {
        $service = $this->buildService();
        $attrs = [[
            'id' => 'EMPTY_GTIN_REASON',
            'name' => 'Motivo',
            'values' => [
                ['id' => '1', 'name' => 'Artesanal'],
                ['id' => '2', 'name' => 'No registrado'],
                ['id' => '3', 'name' => 'Kit'],
            ],
        ]];

        $reason = $this->invoke($service, 'resolveEmptyGtinReason', [$attrs]);

        $this->assertIsArray($reason);
        $this->assertSame('2', $reason['id']);
        $this->assertSame('No registrado', $reason['name']);
    }

    public function testResolveEmptyGtinReasonPrefersPortugueseCadastrado(): void
    {
        $service = $this->buildService();
        $attrs = [[
            'id' => 'EMPTY_GTIN_REASON',
            'values' => [
                ['id' => '17055158', 'name' => 'O produto é uma peça artesanal'],
                ['id' => '17055159', 'name' => 'O produto é um kit ou pack'],
                ['id' => '17055160', 'name' => 'O produto não tem código cadastrado'],
                ['id' => '17055161', 'name' => 'Outro motivo'],
            ],
        ]];

        $reason = $this->invoke($service, 'resolveEmptyGtinReason', [$attrs]);

        $this->assertIsArray($reason);
        $this->assertSame('17055160', $reason['id']);
        $this->assertSame('O produto não tem código cadastrado', $reason['name']);
    }

    public function testItemHasGtinDetectsFilledAttribute(): void
    {
        $service = $this->buildService();
        $with = ['attributes' => [['id' => 'GTIN', 'value_name' => '7891234567895']]];
        $without = ['attributes' => [['id' => 'GTIN', 'value_name' => '']]];

        $this->assertTrue($this->invoke($service, 'itemHasGtin', [$with]));
        $this->assertFalse($this->invoke($service, 'itemHasGtin', [$without]));
    }

    public function testNeverInventedEanWhenBalanceZeroUsesEmptyPolicyPath(): void
    {
        $ean = $this->createMock(EanService::class);
        $ean->method('getBalance')->willReturn(['available' => 0, 'reserved' => 0, 'sold' => 0]);
        $ean->expects($this->never())->method('reserveEanForItem');
        $ean->expects($this->never())->method('getNextAvailableEan');

        $tech = $this->createMock(TechSheetService::class);
        $tech->expects($this->once())->method('persistSuggestion')->willReturn(true);

        $service = $this->buildService($ean, $tech);

        $db = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $db->method('prepare')->willReturn($stmt);

        $ref = new \ReflectionClass($service);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $db);

        $attrs = [
            ['id' => 'GTIN', 'name' => 'GTIN'],
            [
                'id' => 'EMPTY_GTIN_REASON',
                'values' => [['id' => '99', 'name' => 'No registrado']],
            ],
        ];

        $result = $service->suggestForItem('MLB1', $attrs, ['attributes' => []], 'MLB438146', 'Peça teste');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['skipped'] ?? false);
        $this->assertSame('EMPTY_GTIN_REASON', $result['suggestion']['attribute_id']);
        $this->assertSame(TechSheetGtinSuggestionService::SOURCE_EMPTY_GTIN, $result['suggestion']['source']);
        $this->assertSame('No registrado', $result['suggestion']['suggested_value']);
        $this->assertDoesNotMatchRegularExpression('/^\d{8,14}$/', (string)$result['suggestion']['suggested_value']);
    }

    public function testVariationsForceEmptyWithoutPoolReserve(): void
    {
        $ean = $this->createMock(EanService::class);
        $ean->method('getBalance')->willReturn(['available' => 5]);
        $ean->expects($this->never())->method('reserveEanForItem');

        $tech = $this->createMock(TechSheetService::class);
        $tech->expects($this->once())->method('persistSuggestion')->willReturn(true);

        $service = $this->buildService($ean, $tech);

        $db = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $db->method('prepare')->willReturn($stmt);
        $ref = new \ReflectionClass($service);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $db);

        $attrs = [
            ['id' => 'GTIN', 'name' => 'GTIN'],
            ['id' => 'EMPTY_GTIN_REASON', 'values' => [['id' => '1', 'name' => 'No registrado']]],
        ];

        $result = $service->suggestForItem(
            'MLB2',
            $attrs,
            ['attributes' => [], 'variations' => [['id' => 1]]],
            'MLB438146'
        );

        $this->assertSame(TechSheetGtinSuggestionService::SOURCE_EMPTY_GTIN, $result['suggestion']['source']);
    }

    public function testDryRunPoolDoesNotReserveOrPersist(): void
    {
        $ean = $this->createMock(EanService::class);
        $ean->method('getBalance')->willReturn(['available' => 3]);
        $ean->method('getNextAvailableEan')->willReturn(['ean' => '7891234567895', 'id' => 10]);
        $ean->method('validateEan')->willReturn(true);
        $ean->expects($this->never())->method('reserveEanForItem');

        $tech = $this->createMock(TechSheetService::class);
        $tech->expects($this->never())->method('persistSuggestion');

        $service = $this->buildService($ean, $tech);

        $db = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $db->method('prepare')->willReturn($stmt);
        $ref = new \ReflectionClass($service);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $db);

        $attrs = [['id' => 'GTIN', 'name' => 'GTIN']];
        $result = $service->suggestForItem('MLB3', $attrs, ['attributes' => []], 'MLB1', 'T', true);

        $this->assertTrue($result['dry_run'] ?? false);
        $this->assertSame(TechSheetGtinSuggestionService::SOURCE_EAN_POOL, $result['suggestion']['source']);
        $this->assertSame('7891234567895', $result['suggestion']['suggested_value']);
    }

    public function testReleaseReservationOnlyForGtinWithAssignment(): void
    {
        $ean = $this->createMock(EanService::class);
        $ean->expects($this->once())
            ->method('releaseEanReservation')
            ->with(9999, 'MLB9', 42)
            ->willReturn(true);

        $service = $this->buildService($ean);
        $service->releaseReservationForRejectedSuggestion('MLB9', 'GTIN', ['ean_assignment_id' => 42]);
        $service->releaseReservationForRejectedSuggestion('MLB9', 'MODEL', ['ean_assignment_id' => 42]);
        $service->releaseReservationForRejectedSuggestion('MLB9', 'GTIN', []);
    }

    public function testConfirmPoolAfterApplySkipsWhenGtinNotApplied(): void
    {
        $ean = $this->createMock(EanService::class);
        $ean->expects($this->never())->method('confirmEanReservationAfterApply');

        $service = $this->buildService($ean);
        $service->confirmPoolAfterApply('MLB1', [['id' => 'MODEL', 'value_name' => 'X']]);
    }

    public function testValidateEanRejectsInventedGarbage(): void
    {
        $ean = new EanService();
        $ref = new \ReflectionClass($ean);
        $svc = $ref->newInstanceWithoutConstructor();

        $this->assertFalse($svc->validateEan('0000000000001'));
        $this->assertFalse($svc->validateEan('123'));
        $this->assertFalse($svc->validateEan('ABCDEFGHIJKLM'));
    }
}
