<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HiddenSeo;

use App\Entity\HiddenSeo\HiddenSeoGap;
use App\Services\HiddenSeo\TechSheetMapper;
use App\ValueObjects\HiddenSeo\SuggestionSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\HiddenSeo\TechSheetMapper
 */
class TechSheetMapperTest extends TestCase
{
    public function testLineFromFicha(): void
    {
        $mapper = new TechSheetMapper();
        $gap = new HiddenSeoGap('MLB1', 1336, 'Peça X', 'PF-1', null);
        $list = $mapper->map($gap, ['line' => 'Titan']);
        $this->assertCount(1, $list);
        $this->assertSame('LINE', $list[0]->attributeId());
        $this->assertSame('Titan', $list[0]->newValue());
        $this->assertSame(SuggestionSource::FICHA_TECNICA, $list[0]->source());
    }

    public function testMpnFromSellerSku(): void
    {
        $mapper = new TechSheetMapper();
        $gap = new HiddenSeoGap('MLB1', 1336, 'Peça sem codigo', null, 'Fan', [
            ['id' => 'SELLER_SKU', 'value_name' => 'SKU-FAN125-01'],
        ]);
        $list = $mapper->map($gap, []);
        $mpn = null;
        foreach ($list as $s) {
            if ($s->attributeId() === 'MPN') {
                $mpn = $s;
            }
        }
        $this->assertNotNull($mpn);
        $this->assertSame('SKU-FAN125-01', $mpn->newValue());
        $this->assertSame(SuggestionSource::SELLER_SKU, $mpn->source());
    }

    public function testSkipWhenAlreadyFilled(): void
    {
        $mapper = new TechSheetMapper();
        $gap = new HiddenSeoGap('MLB1', 1336, 'Fan 125', 'ABC-1', 'Fan');
        $this->assertSame([], $mapper->map($gap, ['line' => 'Titan', 'mpn' => 'XYZ-9']));
    }

    public function testSkipNoEvidence(): void
    {
        $mapper = new TechSheetMapper();
        $gap = new HiddenSeoGap('MLB1', 1336, 'Peça genérica sem sinal', null, null);
        $this->assertSame([], $mapper->map($gap, []));
    }

    public function testDeriveLineFromTitleSingleSignal(): void
    {
        $mapper = new TechSheetMapper();
        $gap = new HiddenSeoGap('MLB1', 1336, 'Pastilha Freio Fan 125', 'X-1', null);
        $list = $mapper->map($gap, []);
        $this->assertNotEmpty($list);
        $this->assertSame('LINE', $list[0]->attributeId());
        $this->assertSame('Fan', $list[0]->newValue());
    }

    public function testRejectAmbiguousMultiLineTitle(): void
    {
        $mapper = new TechSheetMapper();
        $gap = new HiddenSeoGap('MLB1', 1336, 'Guidão CG Titan Fan Start', 'X-1', null);
        $list = $mapper->map($gap, []);
        $lineSuggestions = array_filter($list, static fn($s) => $s->attributeId() === 'LINE');
        $this->assertCount(0, $lineSuggestions);
    }
}
