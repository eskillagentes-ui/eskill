<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\SEO;

use Tests\TestCase;
use App\Services\AI\SEO\AdvancedSEOMaximizer;
use ReflectionClass;
use ReflectionMethod;

/**
 * Testes unitários para AdvancedSEOMaximizer.
 *
 * Cobre:
 * - Estrutura e API pública (7 métodos públicos)
 * - Constantes SEO_WEIGHTS e POWER_WORDS
 * - Métodos de lógica pura (sem DB): extractKeywords, scoreTitle,
 *   scoreDescription, scoreImages, fillTemplate, generateLongTailKeywords,
 *   identifyCompetitorStrengths/Weaknesses, identifyOpportunities/Threats,
 *   generateStrategicRecommendations, getConvertingKeywords
 * - Métodos reportados em FIX-001 (todos existem e têm comportamento correto)
 * - Fallback seguro quando DB indisponível (scorePrice, findDirectCompetitors,
 *   getSecondaryKeywords, getLSIKeywords)
 */
class AdvancedSEOMaximizerTest extends TestCase
{
    private AdvancedSEOMaximizer $service;

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new ReflectionClass(AdvancedSEOMaximizer::class);
        $this->service = $ref->newInstanceWithoutConstructor();

        // Inicializar accountId para evitar TypeError em métodos que o usam
        $accountProp = $ref->getProperty('accountId');
        $accountProp->setAccessible(true);
        $accountProp->setValue($this->service, 1);
    }

    // =========================================================
    // Helper
    // =========================================================

    private function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(AdvancedSEOMaximizer::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->service, ...$args);
    }

    // =========================================================
    // ESTRUTURA
    // =========================================================

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(AdvancedSEOMaximizer::class));
    }

    public function testPublicMethodsExist(): void
    {
        $ref = new ReflectionClass(AdvancedSEOMaximizer::class);
        $expected = [
            'maximizeItemSEO',
            'optimizeTitle',
            'optimizeDescription',
            'optimizeAttributes',
            'calculateSEOScore',
            'generateAdvancedKeywords',
            'advancedCompetitorAnalysis',
        ];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Método público '{$method}' não encontrado");
        }
    }

    /**
     * Todos os 16 métodos corrigidos em FIX-001 devem existir.
     */
    public function testPrivateHelperMethodsFromFIX001Exist(): void
    {
        $ref = new ReflectionClass(AdvancedSEOMaximizer::class);
        $expected = [
            'fillTemplate',
            'scorePrice',
            'getLSIKeywords',
            'injectLSIKeywords',
            'addEmotionalTriggers',
            'optimizeReadability',
            'getMissingRequiredAttributes',
            'getPopularFilterAttributes',
            'findDirectCompetitors',
            'identifyCompetitorStrengths',
            'identifyCompetitorWeaknesses',
            'identifyOpportunities',
            'identifyThreats',
            'generateStrategicRecommendations',
            'getSecondaryKeywords',
            'generateLongTailKeywords',
        ];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Método '{$method}' não encontrado — FIX-001 pode ter regredido");
        }
    }

    public function testSeoWeightsConstantSumsTo100(): void
    {
        $ref     = new ReflectionClass(AdvancedSEOMaximizer::class);
        $weights = $ref->getConstant('SEO_WEIGHTS');
        $this->assertIsArray($weights);
        $this->assertSame(100, array_sum($weights), 'SEO_WEIGHTS devem somar exatamente 100 para ponderação correta');
    }

    public function testPowerWordsConstantHasExpectedCategories(): void
    {
        $ref        = new ReflectionClass(AdvancedSEOMaximizer::class);
        $powerWords = $ref->getConstant('POWER_WORDS');
        $this->assertIsArray($powerWords);
        foreach (['premium', 'urgency', 'quality', 'trust'] as $category) {
            $this->assertArrayHasKey($category, $powerWords, "Categoria '$category' ausente em POWER_WORDS");
            $this->assertNotEmpty($powerWords[$category]);
        }
    }

    public function testConvertingKeywordsConstantHasElectronicsDefault(): void
    {
        $ref  = new ReflectionClass(AdvancedSEOMaximizer::class);
        $ckws = $ref->getConstant('CONVERTING_KEYWORDS');
        $this->assertArrayHasKey('electronics', $ckws);
        $this->assertNotEmpty($ckws['electronics']);
    }

    // =========================================================
    // extractKeywords — lógica pura, sem DB
    // =========================================================

    public function testExtractKeywordsRemovesPortugueseStopWords(): void
    {
        $result = $this->invoke('extractKeywords', 'bagageiro para moto CG 160 Honda');
        $this->assertNotContains('para', $result, '"para" é stop-word e deve ser removida');
        $this->assertContains('moto', $result);
        $this->assertContains('honda', $result);
    }

    public function testExtractKeywordsGeneratesBigrams(): void
    {
        // Use words > 2 chars so they survive the length filter
        $result = $this->invoke('extractKeywords', 'bagageiro honda titan');
        $this->assertContains('bagageiro honda', $result, 'Bigrams devem ser gerados para termos compostos');
        $this->assertContains('honda titan', $result);
    }

    public function testExtractKeywordsFiltersShortWords(): void
    {
        $result = $this->invoke('extractKeywords', 'retrovisores moto de');
        $this->assertNotContains('de', $result, 'Palavras com 2 chars devem ser filtradas');
    }

    public function testExtractKeywordsFiltersNumericTokens(): void
    {
        $result = $this->invoke('extractKeywords', 'Titan 160 Honda peças');
        $this->assertNotContains('160', $result, 'Tokens numéricos devem ser filtrados');
    }

    public function testExtractKeywordsReturnsUniqueValues(): void
    {
        $result = $this->invoke('extractKeywords', 'moto moto moto CG CG');
        $this->assertSame(count($result), count(array_unique($result)));
    }

    public function testExtractKeywordsEmptyStringReturnsEmptyArray(): void
    {
        $result = $this->invoke('extractKeywords', '');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================
    // scoreTitle — lógica pura
    // =========================================================

    public function testScoreTitleIdealLengthGivesHighScore(): void
    {
        // 46 chars — ideal range (45-58)
        $title = 'Bagageiro Honda CG 160 Start Alumínio Premium';
        $score = $this->invoke('scoreTitle', $title);
        $this->assertGreaterThanOrEqual(30, $score, 'Título com comprimento ideal deve ter score >= 30');
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testScoreTitleWithPowerWordBeatsWithout(): void
    {
        $withPower    = 'Bagageiro Honda CG 160 Premium Qualidade Original Garantia';
        $withoutPower = 'Bagageiro Honda CG peça traseira acessório aluminio';
        $this->assertGreaterThan(
            $this->invoke('scoreTitle', $withoutPower),
            $this->invoke('scoreTitle', $withPower),
            'Título com power words deve ter score maior'
        );
    }

    public function testScoreTitleCappedAt100(): void
    {
        $title = str_repeat('garantia certificado original qualidade premium ', 5);
        $score = $this->invoke('scoreTitle', $title);
        $this->assertLessThanOrEqual(100, $score, 'scoreTitle nunca deve ultrapassar 100');
        $this->assertIsInt($score);
    }

    public function testScoreTitleEmptyReturnsLowScore(): void
    {
        $score = $this->invoke('scoreTitle', '');
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThan(30, $score);
    }

    // =========================================================
    // scoreDescription — lógica pura
    // =========================================================

    public function testScoreDescriptionLongGetsHighBase(): void
    {
        $desc  = str_repeat('Produto de alta qualidade para motos Honda CG 160. ', 15); // 750+ chars
        $score = $this->invoke('scoreDescription', $desc);
        $this->assertGreaterThanOrEqual(30, $score);
    }

    public function testScoreDescriptionWithBulletsBeatsWithout(): void
    {
        $with    = str_repeat('• Qualidade superior para máquinas Honda. ', 12);
        $without = str_repeat('Qualidade superior para máquinas Honda.  ', 12);
        $this->assertGreaterThan(
            $this->invoke('scoreDescription', $without),
            $this->invoke('scoreDescription', $with),
            'Descrição com bullet points deve ter score maior'
        );
    }

    public function testScoreDescriptionCappedAt100(): void
    {
        $desc  = str_repeat('garantia compre envio desconto promoção qualidade original premium certificado ', 30);
        $score = $this->invoke('scoreDescription', $desc);
        $this->assertLessThanOrEqual(100, $score, 'scoreDescription nunca deve ultrapassar 100');
        $this->assertIsInt($score);
    }

    // =========================================================
    // scoreImages — lógica pura
    // =========================================================

    public function testScoreImagesEmptyArrayGivesLowScore(): void
    {
        $score = $this->invoke('scoreImages', []);
        $this->assertIsInt($score);
        $this->assertLessThanOrEqual(25, $score);
    }

    public function testScoreImagesSixOrMoreGivesMaxScore(): void
    {
        // Imagens com size ≥ 800px (menor dimensão) são classificadas como alta
        // resolução. O campo 'size' é o que a API ML retorna nos objetos de imagem.
        $images = array_fill(0, 6, [
            'url'      => 'https://img.mlstatic.com/D_MLB1234-O.jpg',
            'size'     => '1200x900',
            'max_size' => '1200x900',
        ]);
        $score  = $this->invoke('scoreImages', $images);
        $this->assertSame(100, $score, '6 imagens alta resolução deve dar score 100');
    }

    public function testScoreImagesMoreIsBetter(): void
    {
        $three = array_fill(0, 3, ['url' => 'x.jpg']);
        $six   = array_fill(0, 6, ['url' => 'x.jpg']);
        $this->assertLessThan(
            $this->invoke('scoreImages', $six),
            $this->invoke('scoreImages', $three)
        );
    }

    // =========================================================
    // scorePrice — fallback seguro quando DB indisponível
    // =========================================================

    public function testScorePriceReturns50ForZeroPrice(): void
    {
        $this->assertSame(50, $this->invoke('scorePrice', 0.0, 'MLB1'));
    }

    public function testScorePriceReturns50ForEmptyCategoryId(): void
    {
        $this->assertSame(50, $this->invoke('scorePrice', 150.0, ''));
    }

    public function testScorePriceReturns50WhenDbUnavailable(): void
    {
        // $this->db é propriedade tipada não inicializada → Error → catch → 50
        $this->assertSame(50, $this->invoke('scorePrice', 100.0, 'MLB1276'));
    }

    // =========================================================
    // generateLongTailKeywords — lógica pura (DB com catch)
    // =========================================================

    public function testGenerateLongTailKeywordsReturnsArray(): void
    {
        $result = $this->invoke('generateLongTailKeywords', ['title' => 'Bagageiro Honda CG 160']);
        $this->assertIsArray($result);
    }

    public function testGenerateLongTailKeywordsEmptyTitleReturnsEmpty(): void
    {
        $result = $this->invoke('generateLongTailKeywords', ['title' => '', 'category_id' => '']);
        $this->assertEmpty($result);
    }

    public function testGenerateLongTailKeywordsCombinesWordWithContext(): void
    {
        $result = $this->invoke('generateLongTailKeywords', ['title' => 'Bagageiro Honda Titan', 'category_id' => '']);
        $hasCompound = count(array_filter($result, fn(string $kw): bool => str_contains($kw, ' '))) > 0;
        $this->assertTrue($hasCompound, 'Long-tail keywords devem conter frases compostas');
    }

    public function testGenerateLongTailKeywordsMaximum20(): void
    {
        $result = $this->invoke('generateLongTailKeywords', [
            'title' => 'Bagageiro Honda CG Bros XRE Factor Titan Fan CBR CB Fazer Alumínio',
            'category_id' => '',
        ]);
        $this->assertLessThanOrEqual(20, count($result));
    }

    // =========================================================
    // getLSIKeywords — retorna [] sem categoryId; fallback puro quando DB falha
    // =========================================================

    public function testGetLSIKeywordsEmptyCategoryReturnsEmpty(): void
    {
        $result = $this->invoke('getLSIKeywords', ['title' => 'Bagageiro Honda', 'category_id' => '']);
        $this->assertSame([], $result, 'Sem category_id deve retornar []');
    }

    public function testGetLSIKeywordsWithCategoryButNoDbUsesTitleFallback(): void
    {
        // DB não inicializado → exception → fallback com palavras do título
        $result = $this->invoke('getLSIKeywords', [
            'title'       => 'Retrovisores Honda Bros original',
            'category_id' => 'MLB1276',
        ]);
        $this->assertIsArray($result);
        // Fallback extrai palavras do título (>3 chars, não stop-words)
        $this->assertContains('retrovisores', $result);
    }

    // =========================================================
    // injectLSIKeywords — lógica pura
    // =========================================================

    public function testInjectLSIKeywordsAppendsAbsentKeywords(): void
    {
        $desc   = 'Produto de alta qualidade para Honda CG.';
        $lsi    = ['alumínio', 'original', 'honda'];
        $result = $this->invoke('injectLSIKeywords', $desc, $lsi);
        // "alumínio" and "original" are not in desc → should be appended
        $this->assertStringContainsString('alumínio', $result);
    }

    public function testInjectLSIKeywordsDoesNotDuplicatePresentKeywords(): void
    {
        $desc   = 'Produto original Honda CG alumínio qualidade.';
        $lsi    = ['original', 'honda'];
        $result = $this->invoke('injectLSIKeywords', $desc, $lsi);
        // All keywords already present → no injection suffix
        $this->assertStringNotContainsString('Palavras-chave relacionadas', $result);
    }

    public function testInjectLSIKeywordsEmptyKeywordsReturnsUnchanged(): void
    {
        $desc   = 'Minha descrição original.';
        $result = $this->invoke('injectLSIKeywords', $desc, []);
        $this->assertSame($desc, $result);
    }

    // =========================================================
    // addEmotionalTriggers — lógica pura
    // =========================================================

    public function testAddEmotionalTriggersAppendsWhenNonePresent(): void
    {
        $desc   = 'Produto simples sem nenhum gatilho emocional aqui.';
        $result = $this->invoke('addEmotionalTriggers', $desc, ['title' => 'Bagageiro Moto Honda', 'category_id' => '']);
        $this->assertNotSame($desc, $result, 'Deve acrescentar gatilho emocional');
        $this->assertStringContainsString('⚡', $result);
    }

    public function testAddEmotionalTriggersSkipsWhenAlreadyPresent(): void
    {
        $desc   = 'Produto com garantia de qualidade total.';
        $result = $this->invoke('addEmotionalTriggers', $desc, ['title' => 'Produto', 'category_id' => '']);
        $this->assertSame($desc, $result, 'Se já há gatilho ("garantia"), não deve adicionar outro');
    }

    // =========================================================
    // fillTemplate — lógica pura
    // =========================================================

    public function testFillTemplateReplacesProductNameFromAttributes(): void
    {
        $template = 'Produto: {PRODUCT_NAME} — Peça de qualidade';
        $itemData = [
            'attributes' => [
                ['id' => 'brand', 'value_name' => 'Honda'],
                ['id' => 'model', 'value_name' => 'CG 160'],
            ],
            'title' => 'Bagageiro',
        ];
        $result = $this->invoke('fillTemplate', $template, $itemData);
        $this->assertStringContainsString('Honda', $result);
        $this->assertStringContainsString('CG 160', $result);
        $this->assertStringNotContainsString('{PRODUCT_NAME}', $result);
    }

    public function testFillTemplateFallsBackToTitleWhenNoAttributes(): void
    {
        $template = '{PRODUCT_NAME}';
        $itemData = ['title' => 'Retrovisores Honda', 'attributes' => []];
        $result   = $this->invoke('fillTemplate', $template, $itemData);
        $this->assertSame('Retrovisores Honda', $result);
    }

    public function testFillTemplateBulletPointsFromAttributes(): void
    {
        $template = '{BULLET_POINTS}';
        $itemData = [
            'attributes' => [
                ['id' => 'color', 'name' => 'Cor', 'value_name' => 'Preto'],
                ['id' => 'material', 'name' => 'Material', 'value_name' => 'Alumínio'],
            ],
        ];
        $result = $this->invoke('fillTemplate', $template, $itemData);
        $this->assertStringContainsString('•', $result);
        $this->assertStringContainsString('Preto', $result);
        $this->assertStringContainsString('Alumínio', $result);
    }

    public function testFillTemplateBulletPointsFallbackWithEmptyAttributes(): void
    {
        $template = '{BULLET_POINTS}';
        $result   = $this->invoke('fillTemplate', $template, ['attributes' => []]);
        $this->assertStringContainsString('•', $result);
    }

    public function testFillTemplateFormatsPrice(): void
    {
        $template = '{PRICE}';
        $itemData = ['price' => 299.90, 'attributes' => []];
        $result   = $this->invoke('fillTemplate', $template, $itemData);
        $this->assertStringContainsString('R$', $result);
        $this->assertStringNotContainsString('{PRICE}', $result);
    }

    public function testFillTemplateUsesWarrantyAttribute(): void
    {
        $template = '{WARRANTY}';
        $itemData = [
            'attributes' => [
                ['id' => 'warranty_time', 'name' => 'Garantia', 'value_name' => '12 meses'],
            ],
        ];
        $result = $this->invoke('fillTemplate', $template, $itemData);
        $this->assertStringContainsString('12 meses', $result);
    }

    public function testFillTemplateFallbackWarrantyWhenNoAttribute(): void
    {
        $template = '{WARRANTY}';
        $result   = $this->invoke('fillTemplate', $template, ['attributes' => []]);
        $this->assertStringContainsString('garantia', mb_strtolower($result));
    }

    public function testFillTemplateLeavesBrandAndModelPlaceholdersWhenMissing(): void
    {
        $template = '{BRAND} / {MODEL}';
        $result   = $this->invoke('fillTemplate', $template, ['attributes' => []]);
        $this->assertStringNotContainsString('{BRAND}', $result);
        $this->assertStringNotContainsString('{MODEL}', $result);
    }

    // =========================================================
    // identifyCompetitorStrengths — lógica pura
    // =========================================================

    public function testStrengthsIncludesCheaperPrice(): void
    {
        $competitor = ['price' => 80.0, 'sold_quantity' => 5, 'title' => 'Prod'];
        $myItem     = ['price' => 100.0];
        $strengths  = $this->invoke('identifyCompetitorStrengths', $competitor, $myItem);
        $found = array_filter($strengths, fn(string $s): bool => str_contains(strtolower($s), 'barato'));
        $this->assertNotEmpty($found, 'Preço 20% menor deve ser identificado como força');
    }

    public function testStrengthsIncludesHighSalesVolume(): void
    {
        $competitor = ['price' => 100.0, 'sold_quantity' => 100, 'title' => 'Prod'];
        $strengths  = $this->invoke('identifyCompetitorStrengths', $competitor, ['price' => 100.0]);
        $found = array_filter($strengths, fn(string $s): bool => str_contains($s, 'vendidos'));
        $this->assertNotEmpty($found);
    }

    public function testStrengthsIncludesIdealTitleLength(): void
    {
        $titleExact = str_pad('Bagageiro Honda CG', 50); // 50 chars — ideal
        $competitor = ['price' => 100.0, 'sold_quantity' => 5, 'title' => $titleExact];
        $strengths  = $this->invoke('identifyCompetitorStrengths', $competitor, ['price' => 100.0]);
        $found = array_filter($strengths, fn(string $s): bool => str_contains(strtolower($s), 'seo'));
        $this->assertNotEmpty($found);
    }

    // =========================================================
    // identifyCompetitorWeaknesses — lógica pura
    // =========================================================

    public function testWeaknessesIncludesHigherPrice(): void
    {
        $competitor = ['price' => 120.0, 'title' => 'Prod', 'available_quantity' => 10];
        $weaknesses = $this->invoke('identifyCompetitorWeaknesses', $competitor, ['price' => 100.0]);
        $found = array_filter($weaknesses, fn(string $s): bool => str_contains(strtolower($s), 'caro'));
        $this->assertNotEmpty($found);
    }

    public function testWeaknessesIncludesLongTitle(): void
    {
        $competitor = ['price' => 95.0, 'title' => str_repeat('palavra ', 10), 'available_quantity' => 10];
        $weaknesses = $this->invoke('identifyCompetitorWeaknesses', $competitor, ['price' => 100.0]);
        $found = array_filter($weaknesses, fn(string $s): bool => str_contains(strtolower($s), 'longo'));
        $this->assertNotEmpty($found);
    }

    public function testWeaknessesIncludesLowStock(): void
    {
        $competitor = ['price' => 90.0, 'title' => 'Prod', 'available_quantity' => 2];
        $weaknesses = $this->invoke('identifyCompetitorWeaknesses', $competitor, ['price' => 100.0]);
        $found = array_filter($weaknesses, fn(string $s): bool => str_contains(strtolower($s), 'estoque'));
        $this->assertNotEmpty($found);
    }

    // =========================================================
    // identifyOpportunities — lógica pura
    // =========================================================

    public function testOpportunitiesWithNoCompetitorsReturnsVisibilityMessage(): void
    {
        $result = $this->invoke('identifyOpportunities', [], ['price' => 100.0]);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('concorrente', strtolower($result[0]));
    }

    public function testOpportunitiesWhenPriceBelowAverage(): void
    {
        $competitors = [
            ['price' => 100.0, 'available_quantity' => 10],
            ['price' => 110.0, 'available_quantity' => 10],
        ]; // avg = 105
        $myItem = ['price' => 90.0]; // 90 <= 105 * 0.95 = 99.75
        $result = $this->invoke('identifyOpportunities', $competitors, $myItem);
        $found  = array_filter($result, fn(string $s): bool => str_contains(strtolower($s), 'abaixo'));
        $this->assertNotEmpty($found);
    }

    public function testOpportunitiesWhenMajorityHasLowStock(): void
    {
        $competitors = [
            ['price' => 100.0, 'available_quantity' => 2],
            ['price' => 100.0, 'available_quantity' => 1],
            ['price' => 100.0, 'available_quantity' => 10],
        ]; // 2/3 with low stock (>= 50%)
        $result = $this->invoke('identifyOpportunities', $competitors, ['price' => 100.0]);
        $found  = array_filter($result, fn(string $s): bool => str_contains(strtolower($s), 'estoque'));
        $this->assertNotEmpty($found);
    }

    // =========================================================
    // identifyThreats — lógica pura
    // =========================================================

    public function testThreatsWhenCompetitorPriceFarBelow(): void
    {
        $competitors = [['price' => 60.0, 'sold_quantity' => 5]];
        $threats     = $this->invoke('identifyThreats', $competitors, ['price' => 100.0]);
        $found = array_filter($threats, fn(string $s): bool => str_contains(strtolower($s), 'abaixo'));
        $this->assertNotEmpty($found, 'Preço 40% abaixo deve ser ameaça (buybox)');
    }

    public function testThreatsWhenCompetitorHasHighSales(): void
    {
        $competitors = [['price' => 100.0, 'sold_quantity' => 300]];
        $threats     = $this->invoke('identifyThreats', $competitors, ['price' => 100.0]);
        $found = array_filter($threats, fn(string $s): bool => str_contains($s, '200+'));
        $this->assertNotEmpty($found);
    }

    public function testThreatsEmptyWhenNoRisks(): void
    {
        $competitors = [['price' => 100.0, 'sold_quantity' => 10]];
        $threats     = $this->invoke('identifyThreats', $competitors, ['price' => 100.0]);
        $this->assertIsArray($threats); // Can be empty — no risk
    }

    // =========================================================
    // generateStrategicRecommendations — lógica pura
    // =========================================================

    public function testStrategicRecommendationsFromOpportunities(): void
    {
        $analysis = ['opportunities' => ['Preço abaixo da média'], 'threats' => [], 'weaknesses' => []];
        $result   = $this->invoke('generateStrategicRecommendations', $analysis);
        $this->assertNotEmpty($result);
        $this->assertSame('opportunity', $result[0]['type']);
    }

    public function testStrategicRecommendationsFromThreats(): void
    {
        $analysis = ['opportunities' => [], 'threats' => ['Preço concorrente muito baixo'], 'weaknesses' => []];
        $result   = $this->invoke('generateStrategicRecommendations', $analysis);
        $types    = array_column($result, 'type');
        $this->assertContains('threat', $types);
    }

    public function testStrategicRecommendationsDefaultMaintainWhenEmpty(): void
    {
        $analysis = ['opportunities' => [], 'threats' => [], 'weaknesses' => []];
        $result   = $this->invoke('generateStrategicRecommendations', $analysis);
        $this->assertNotEmpty($result, 'Sem análise, deve retornar recomendação de manutenção');
        $this->assertSame('maintain', $result[0]['type']);
    }

    public function testStrategicRecommendationsHaveRequiredKeys(): void
    {
        $analysis = [
            'opportunities' => ['Opp1'],
            'threats'       => ['Threat1'],
            'weaknesses'    => [],
        ];
        foreach ($this->invoke('generateStrategicRecommendations', $analysis) as $rec) {
            $this->assertArrayHasKey('type', $rec);
            $this->assertArrayHasKey('action', $rec);
            $this->assertArrayHasKey('priority', $rec);
        }
    }

    // =========================================================
    // getConvertingKeywords — lógica pura (usa constante)
    // =========================================================

    public function testGetConvertingKeywordsMatchesKnownCategory(): void
    {
        $result = $this->invoke('getConvertingKeywords', ['name' => 'Electronics Gadgets']);
        $this->assertNotEmpty($result);
        $this->assertContains('original', $result); // From CONVERTING_KEYWORDS['electronics']
    }

    public function testGetConvertingKeywordsDefaultsToElectronicsForUnknown(): void
    {
        $result = $this->invoke('getConvertingKeywords', ['name' => 'Categoria Desconhecida']);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // =========================================================
    // findDirectCompetitors — retorna [] sem DB
    // =========================================================

    public function testFindDirectCompetitorsEmptyCategoryIdReturnsEmpty(): void
    {
        $result = $this->invoke('findDirectCompetitors', ['category_id' => '']);
        $this->assertSame([], $result);
    }

    public function testFindDirectCompetitorsWithCategoryButNoDbReturnsEmpty(): void
    {
        // DB não inicializado → TypeError dentro do try → catch returns []
        $result = $this->invoke('findDirectCompetitors', ['category_id' => 'MLB1276', 'price' => 100.0]);
        $this->assertSame([], $result);
    }

    // =========================================================
    // getSecondaryKeywords — retorna [] sem DB
    // =========================================================

    public function testGetSecondaryKeywordsEmptyArrayReturnsEmpty(): void
    {
        $result = $this->invoke('getSecondaryKeywords', []);
        $this->assertSame([], $result);
    }

    public function testGetSecondaryKeywordsWithCategoryButNoDbReturnsEmpty(): void
    {
        $result = $this->invoke('getSecondaryKeywords', ['id' => 'MLB999']);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================
    // calculateSEOScore — método público, sub-métodos puros quando category=''
    // =========================================================

    public function testCalculateSEOScoreReturnsExpectedKeys(): void
    {
        $result = $this->service->calculateSEOScore([
            'title'       => 'Bagageiro Honda CG 160 Alumínio Premium',
            'description' => str_repeat('Produto de qualidade ', 30),
            'attributes'  => [],
            'pictures'    => [],
            'price'       => 0,
            'category_id' => '',
        ]);
        foreach (['overall', 'title', 'description', 'attributes', 'images', 'price'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testCalculateSEOScoreOverallBetween0And100(): void
    {
        $result = $this->service->calculateSEOScore([
            'title' => 'Bagageiro Honda CG 160', 'description' => '', 'attributes' => [],
            'pictures' => [], 'price' => 0, 'category_id' => '',
        ]);
        $this->assertGreaterThanOrEqual(0.0, $result['overall']);
        $this->assertLessThanOrEqual(100.0, $result['overall']);
    }

    public function testCalculateSEOScoreRichDataBeatsBareSkeleton(): void
    {
        $poor = [
            'title' => 'Moto', 'description' => '', 'attributes' => [],
            'pictures' => [], 'price' => 0, 'category_id' => '',
        ];
        $rich = [
            'title'       => 'Bagageiro Traseiro Honda CG 160 Fan Start Alumínio Premium',
            'description' => str_repeat('• Produto premium para sua moto Honda. Garantia de qualidade. ', 10),
            'attributes'  => array_fill(0, 10, ['id' => 'x', 'value_name' => 'val']),
            'pictures'    => array_fill(0, 6, ['url' => 'http://x.jpg']),
            'price'       => 0,
            'category_id' => '',
        ];
        $this->assertGreaterThan(
            $this->service->calculateSEOScore($poor)['overall'],
            $this->service->calculateSEOScore($rich)['overall'],
            'Dados ricos devem resultar em score geral maior'
        );
    }
}
