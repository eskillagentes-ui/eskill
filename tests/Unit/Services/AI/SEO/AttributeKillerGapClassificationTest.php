<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\SEO;

use App\Services\AI\SEO\AttributeKiller;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Classificação de gaps alinhada à doc ML de atributos (tags hidden/read_only/required).
 */
class AttributeKillerGapClassificationTest extends TestCase
{
    private AttributeKiller $killer;

    protected function setUp(): void
    {
        parent::setUp();
        // Evita bootstrap pesado: instancia via reflection sem construtor
        $ref = new ReflectionClass(AttributeKiller::class);
        $this->killer = $ref->newInstanceWithoutConstructor();
    }

    public function testHasTagSupportsObjectAndListFormats(): void
    {
        $this->assertTrue($this->killer->hasTag(['hidden' => true], 'hidden'));
        $this->assertFalse($this->killer->hasTag(['hidden' => false], 'hidden'));
        $this->assertTrue($this->killer->hasTag(['hidden', 'required'], 'required'));
        $this->assertFalse($this->killer->hasTag(['hidden'], 'required'));
    }

    public function testReadOnlyAttributesAreNotSellerFillable(): void
    {
        $this->assertFalse($this->killer->isSellerFillable(['hidden' => true, 'read_only' => true]));
        $this->assertFalse($this->killer->isSellerFillable(['fixed' => true]));
        $this->assertFalse($this->killer->isSellerFillable(['inferred' => true]));
        $this->assertFalse($this->killer->isSellerFillable(['others' => true]));
        $this->assertTrue($this->killer->isSellerFillable(['hidden' => true]));
        $this->assertTrue($this->killer->isSellerFillable(['required' => true]));
    }

    public function testHiddenOpsAttributesAreExcludedFromSeoBucket(): void
    {
        $this->assertFalse($this->killer->isHiddenSeoAttribute('PRODUCT_DATA_SOURCE'));
        $this->assertFalse($this->killer->isHiddenSeoAttribute('IS_KIT'));
        $this->assertFalse($this->killer->isHiddenSeoAttribute('SELLER_SKU'));
        $this->assertTrue($this->killer->isHiddenSeoAttribute('MODEL'));
        $this->assertTrue($this->killer->isHiddenSeoAttribute('MPN'));
        $this->assertTrue($this->killer->isHiddenSeoAttribute('POSITION'));
    }

    public function testAttributeFilledRequiresRealValue(): void
    {
        $byId = [
            'MODEL' => ['id' => 'MODEL', 'value_name' => '', 'value_id' => null],
            'BRAND' => ['id' => 'BRAND', 'value_name' => 'Cobreq', 'value_id' => '123'],
            'MPN' => ['id' => 'MPN', 'value_name' => 'N/A'],
            'OEM' => ['id' => 'OEM', 'values' => [['id' => '1', 'name' => '06455']]],
        ];

        $this->assertFalse($this->killer->isAttributeFilled($byId, 'MODEL'));
        $this->assertFalse($this->killer->isAttributeFilled($byId, 'MISSING'));
        $this->assertFalse($this->killer->isAttributeFilled($byId, 'MPN'));
        $this->assertTrue($this->killer->isAttributeFilled($byId, 'BRAND'));
        $this->assertTrue($this->killer->isAttributeFilled($byId, 'OEM'));
    }
}
