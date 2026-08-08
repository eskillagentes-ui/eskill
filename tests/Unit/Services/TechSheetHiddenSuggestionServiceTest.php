<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TechSheetHiddenSuggestionService;
use App\Services\TechSheetService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\TechSheetHiddenSuggestionService
 */
class TechSheetHiddenSuggestionServiceTest extends TestCase
{
    private function buildService(): TechSheetHiddenSuggestionService
    {
        $ref = new \ReflectionClass(TechSheetHiddenSuggestionService::class);
        /** @var TechSheetHiddenSuggestionService $service */
        $service = $ref->newInstanceWithoutConstructor();

        $accountProp = $ref->getProperty('accountId');
        $accountProp->setAccessible(true);
        $accountProp->setValue($service, 9999);

        return $service;
    }

    private function invoke(object $service, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($service, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($service, $args);
    }

    public function testLooksLikeMpnAcceptsAlphanumericCode(): void
    {
        $service = $this->buildService();
        $this->assertTrue($service->looksLikeMpn('PF-FAN125-01'));
        $this->assertTrue($service->looksLikeMpn('ABC1234'));
    }

    public function testLooksLikeMpnRejectsKeywordStuffing(): void
    {
        $service = $this->buildService();
        $this->assertFalse($service->looksLikeMpn('honda'));
        $this->assertFalse($service->looksLikeMpn('123'));
        $this->assertFalse($service->looksLikeMpn('pastilha freio dianteira original'));
    }

    public function testExtractLineFromTitleSingleSignal(): void
    {
        $service = $this->buildService();
        $result = $this->invoke($service, 'extractLineFromTitle', ['Pastilha Freio Fan 125 Honda']);
        $this->assertIsArray($result);
        $this->assertSame('Fan', $result['value']);
        $this->assertSame('title', $result['evidence_source']);
        $this->assertGreaterThanOrEqual(80, $result['confidence']);
    }

    public function testExtractLineFromTitleAmbiguousMultiSkips(): void
    {
        $service = $this->buildService();
        $result = $this->invoke($service, 'extractLineFromTitle', ['Guidão CG 160 Titan Fan Start Preto']);
        $this->assertNull($result);
    }

    public function testExtractMpnFromTitleWithExplicitLabel(): void
    {
        $service = $this->buildService();
        $result = $this->invoke($service, 'extractMpnFromTitle', ['Pastilha Freio MPN: PF-FAN125-01 Honda']);
        $this->assertIsArray($result);
        $this->assertSame('PF-FAN125-01', $result['value']);
        $this->assertSame('title', $result['evidence_source']);
        $this->assertSame(90, $result['confidence']);
    }

    public function testExtractMpnFromTitlePrefersFullHyphenatedCode(): void
    {
        $service = $this->buildService();
        $result = $this->invoke($service, 'extractMpnFromTitle', [
            'Fixture Staging Peça Moto 15 Fan 125 MPN: STG-MPN-15-Fan125',
        ]);
        $this->assertIsArray($result);
        $this->assertSame('STG-MPN-15-FAN125', $result['value']);
    }

    public function testExtractHandleRiserYesFromTitle(): void
    {
        $service = $this->buildService();
        $result = $this->invoke($service, 'extractHandleRiserFromTitle', [
            'Alongador de Guidão Riser Preto',
            ['Não', 'Sim'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame('Sim', $result['value']);
    }

    public function testExtractHandleRiserNoFromTitle(): void
    {
        $service = $this->buildService();
        $result = $this->invoke($service, 'extractHandleRiserFromTitle', [
            'Guidão Original Sem Riser',
            ['Não', 'Sim'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame('Não', $result['value']);
    }

    public function testMatchAllowedInTitleSingleHit(): void
    {
        $service = $this->buildService();
        $result = $this->invoke($service, 'matchAllowedInTitle', [
            'Baú Lateral Monokey 45L',
            ['Monokey', 'Monolock'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame('Monokey', $result['value']);
        $this->assertSame(92, $result['confidence']);
    }

    public function testMatchAllowedInTitleAmbiguousSkips(): void
    {
        $service = $this->buildService();
        $result = $this->invoke($service, 'matchAllowedInTitle', [
            'Baú Monokey Monolock Kit',
            ['Monokey', 'Monolock'],
        ]);
        $this->assertNull($result);
    }

    public function testExtractFromSameItemUsesSellerSkuForMpn(): void
    {
        $service = $this->buildService();
        $itemData = [
            'attributes' => [
                ['id' => 'SELLER_SKU', 'value_name' => 'SKU-CG160-99'],
            ],
        ];
        $result = $this->invoke($service, 'extractFromSameItem', [
            'MPN',
            $itemData,
            ['id' => 'MPN', 'value_type' => 'string'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame('SKU-CG160-99', $result['value']);
        $this->assertSame('same_item', $result['evidence_source']);
    }

    public function testNeverInventMpnWithoutEvidence(): void
    {
        $service = $this->buildService();
        $fromTitle = $this->invoke($service, 'extractMpnFromTitle', ['Pastilha Freio Dianteira Fan 125 Honda']);
        $this->assertNull($fromTitle);

        $fromItem = $this->invoke($service, 'extractFromSameItem', [
            'MPN',
            ['attributes' => [['id' => 'BRAND', 'value_name' => 'Honda']]],
            ['id' => 'MPN'],
        ]);
        $this->assertNull($fromItem);
    }

    public function testSourceConstantIsHiddenSeo(): void
    {
        $this->assertSame('hidden_seo', TechSheetHiddenSuggestionService::SOURCE_HIDDEN_SEO);
        $this->assertSame('hidden_seo', TechSheetService::SOURCE_HIDDEN_SEO);
        $this->assertContains(TechSheetService::SOURCE_HIDDEN_SEO, TechSheetService::ALL_SOURCES);
    }

    public function testPriorityIncludesLineMpnHandleRiser(): void
    {
        $priority = TechSheetHiddenSuggestionService::PRIORITY_ATTRIBUTE_IDS;
        $this->assertSame('LINE', $priority[0]);
        $this->assertSame('MPN', $priority[1]);
        $this->assertSame('HANDLE_RISER', $priority[2]);
        $this->assertContains('MODEL', TechSheetHiddenSuggestionService::SKIP_ATTRIBUTE_IDS);
        $this->assertContains('GTIN', TechSheetHiddenSuggestionService::SKIP_ATTRIBUTE_IDS);
    }
}
