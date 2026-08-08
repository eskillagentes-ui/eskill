<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TechSheetModelSuggestionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\TechSheetModelSuggestionPolicy
 */
class TechSheetModelSuggestionPolicyTest extends TestCase
{
    private TechSheetModelSuggestionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TechSheetModelSuggestionPolicy();
    }

    public function testRejectsKeywordStuffing(): void
    {
        // Sem modelo extraível → rejeita
        $this->assertNull(
            $this->policy->cleanCandidate('kit original promoção frete barato envio')
        );
        // Com ruído mas modelo extraível → só o identificador limpo
        $this->assertSame(
            'Fan 125',
            $this->policy->cleanCandidate('capacete moto fan 125 barato envio gratis')
        );
    }

    public function testAcceptsCleanIdentifiers(): void
    {
        $this->assertSame('Fan 125', $this->policy->cleanCandidate('fan 125'));
        $this->assertSame('Biz 125', $this->policy->cleanCandidate('Biz 125'));
        $this->assertSame('CG Titan', $this->policy->cleanCandidate('CG Titan'));
        $this->assertSame('CG 160', $this->policy->cleanCandidate('honda cg 160'));
    }

    public function testExtractsIdentifierFromNoisyQuery(): void
    {
        $clean = $this->policy->cleanCandidate(
            'manete embreagem honda cg 160 titan barato',
            'Manete Embreagem Honda CG 160 Titan'
        );
        $this->assertSame('CG 160 Titan', $clean);
    }

    public function testSelectBestDropsMiningOnlyNotInTitle(): void
    {
        $result = $this->policy->selectBest([
            [
                'value' => 'PCX 150',
                'score' => 80,
                'sources' => ['trends', 'autocomplete'],
            ],
            [
                'value' => 'Fan 125',
                'score' => 40,
                'sources' => ['title'],
            ],
        ], 'Pastilha Freio Fan 125 Dianteira');

        $this->assertCount(1, $result);
        $this->assertSame('Fan 125', $result[0]['value']);
        $this->assertContains('title', $result[0]['sources']);
    }

    public function testSelectBestPrefersCanonicalOverStuffing(): void
    {
        $result = $this->policy->selectBest([
            [
                'value' => 'capacete fan 125 barato',
                'score' => 99,
                'sources' => ['ml_keyword_api'],
            ],
            [
                'value' => 'Fan 125',
                'score' => 50,
                'sources' => ['local_catalog', 'title'],
            ],
        ], 'Capacete Fechado Fan 125 Preto');

        $this->assertNotEmpty($result);
        $this->assertSame('Fan 125', $result[0]['value']);
    }

    public function testRejectsBrandOnly(): void
    {
        $this->assertNull($this->policy->cleanCandidate('Honda'));
        $this->assertNull($this->policy->cleanCandidate('Yamaha'));
    }

    public function testRejectsYearOnly(): void
    {
        $this->assertNull($this->policy->cleanCandidate('2025'));
        $this->assertNull($this->policy->cleanCandidate('2024'));
    }

    public function testNormalizesLetterDigitBoundary(): void
    {
        $this->assertSame('Fan 160', $this->policy->cleanCandidate('Fan160'));
        $this->assertSame('CG 160', $this->policy->cleanCandidate('CG160'));
        $this->assertSame('F800', $this->policy->cleanCandidate('F800'));
    }

    public function testDetectsAmbiguousMultiModelTitle(): void
    {
        $this->assertTrue($this->policy->isAmbiguousMultiModel(
            'Guidão Honda Cg 160 Titan Fan Start Rosca Preto'
        ));
        $this->assertFalse($this->policy->isAmbiguousMultiModel(
            'Pastilha Freio Dianteira Fan 125 Honda'
        ));
    }

    public function testResolveUmbrellaModelForCgFamily(): void
    {
        $u = $this->policy->resolveUmbrellaModel(
            'Guidão Honda Cg 160 Titan Fan Start Rosca Preto'
        );
        $this->assertNotNull($u);
        $this->assertSame('CG 160', $u['value']);
        $this->assertSame('cg', $u['family']);
        $this->assertSame('160', $u['displacement']);
    }

    public function testResolveUmbrellaRequiresDisplacement(): void
    {
        $this->assertNull($this->policy->resolveUmbrellaModel(
            'Guidão Titan Fan Start Preto'
        ));
    }

    public function testIsModelAttribute(): void
    {
        $this->assertTrue($this->policy->isModelAttribute('MODEL'));
        $this->assertTrue($this->policy->isModelAttribute('ALPHANUMERIC_MODEL'));
        $this->assertFalse($this->policy->isModelAttribute('GTIN'));
    }
}
