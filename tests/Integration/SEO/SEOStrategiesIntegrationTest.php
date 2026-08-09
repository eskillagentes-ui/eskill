<?php

declare(strict_types=1);

namespace Tests\Integration\SEO;

use PHPUnit\Framework\TestCase;
use App\Services\SEO\SEOStrategiesEngine;
use App\Services\SEO\SynonymExpansionService;
use App\Services\SEO\SemanticScoreService;
use App\Services\SEO\KeywordDistributionService;
use App\Services\SEO\DescriptionBuilderService;
use App\Services\SEO\ContextInjectorService;
use App\Services\SEO\LongTailGeneratorService;
use App\Services\SEO\SearchCoverageService;
use App\Services\SEO\CompatibilityService;
use App\Services\KeywordResearchService;

class SEOStrategiesIntegrationTest extends TestCase
{
    private SEOStrategiesEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new SEOStrategiesEngine();
    }

    public function testCompleteSEOWorkflow(): void
    {
        // Test a complete SEO optimization workflow
        $itemId = 'TEST_ITEM_123';
        
        // Run full optimization
        $result = $this->engine->optimizeFull($itemId);
        
        // Verify that all components returned valid results
        $this->assertArrayHasKey('synonym_expansion', $result);
        $this->assertArrayHasKey('keyword_distribution', $result);
        $this->assertArrayHasKey('description_building', $result);
        $this->assertArrayHasKey('coverage_analysis', $result);
        $this->assertArrayHasKey('semantic_scoring', $result);
        $this->assertArrayHasKey('overall_score', $result);
        $this->assertArrayHasKey('report', $result);
        
        // Verify that the overall score is calculated
        $this->assertIsInt($result['overall_score']);
        $this->assertGreaterThanOrEqual(0, $result['overall_score']);
        $this->assertLessThanOrEqual(100, $result['overall_score']);
        
        // Verify that the report is properly structured
        $this->assertArrayHasKey('item_id', $result['report']);
        $this->assertArrayHasKey('timestamp', $result['report']);
        $this->assertArrayHasKey('overall_score', $result['report']);
        $this->assertArrayHasKey('executed_strategies', $result['report']);
        $this->assertArrayHasKey('improvement_potential', $result['report']);
        $this->assertArrayHasKey('recommendations', $result['report']);
        
        $this->assertEquals($itemId, $result['report']['item_id']);
    }

    public function testSynonymExpansionService(): void
    {
        $service = new SynonymExpansionService();
        
        $title = "Bauleto 41L Universal";
        $categoryId = "MLB3530";
        
        $result = $service->expand($title, $categoryId);
        
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Test hierarchy retrieval
        $hierarchy = $service->getHierarchy($categoryId);
        $this->assertIsArray($hierarchy);
        
        // Test level identification
        $level = $service->identifyLevel($title);
        $this->assertIsString($level);
        
        // Test optimized model generation
        $model = $service->generateOptimizedModel($title, $categoryId);
        $this->assertIsArray($model);
        $this->assertArrayHasKey('model', $model);
        $this->assertArrayHasKey('synonyms_used', $model);
        $this->assertArrayHasKey('score', $model);
    }

    public function testSemanticScoreService(): void
    {
        $service = new SemanticScoreService();
        
        $word = "baú";
        $title = "Bauleto 41L Universal";
        $categoryId = "MLB3530";
        
        $score = $service->calculateScore($word, $title, $categoryId);
        
        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
        
        // Test scoring multiple words
        $words = ['baú', 'moto', 'entrega'];
        $scores = $service->scoreWords($words, $title, $categoryId);
        
        $this->assertIsArray($scores);
        $this->assertCount(count($words), $scores);
        
        foreach ($words as $word) {
            $this->assertArrayHasKey($word, $scores);
        }
        
        // Test ranking
        $ranked = $service->rankByScore($words, $title, $categoryId);
        $this->assertIsArray($ranked);
    }

    public function testKeywordDistributionService(): void
    {
        $service = new KeywordDistributionService();
        
        $item = [
            'title' => 'Bauleto 41L Universal',
            'category_id' => 'MLB3530'
        ];
        
        $categoryId = 'MLB3530';
        
        $distribution = $service->distribute($item, $categoryId);
        
        $this->assertIsArray($distribution);
        $this->assertArrayHasKey('title', $distribution);
        $this->assertArrayHasKey('model', $distribution);
        $this->assertArrayHasKey('description', $distribution);
        
        // Test classification
        $keywords = ['baú', 'moto', 'entrega', 'universal'];
        $classification = $service->classifyKeywords($keywords);
        
        $this->assertIsArray($classification);
        $this->assertArrayHasKey('core', $classification);
        $this->assertArrayHasKey('suporte', $classification);
        $this->assertArrayHasKey('tecnica', $classification);
        $this->assertArrayHasKey('contexto', $classification);
        
        // Test density validation
        $text = "Este é um bauleto para moto. O baú é universal.";
        $validation = $service->validateDensity($text, ['baú', 'moto']);
        
        $this->assertIsArray($validation);
    }

    public function testDescriptionBuilderService(): void
    {
        $service = new DescriptionBuilderService();
        
        $item = [
            'title' => 'Bauleto 41L Universal',
            'category_id' => 'MLB3530',
            'description' => 'Produto de qualidade.'
        ];
        
        $distribution = [
            'title' => ['keywords' => ['baú', 'moto']],
            'model' => ['keywords' => ['universal', '41L']],
            'description' => ['keywords' => ['produto', 'qualidade']]
        ];
        
        $result = $service->build($item, $distribution);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('blocks', $result);
        $this->assertArrayHasKey('full_description', $result);
        $this->assertArrayHasKey('word_count', $result);
        $this->assertArrayHasKey('score', $result);
        
        $this->assertIsArray($result['blocks']);
        $this->assertIsString($result['full_description']);
        $this->assertIsInt($result['word_count']);
        $this->assertIsInt($result['score']);
        
        // Test individual block generation
        $block = $service->generateBlock('beneficios', $item, $distribution);
        $this->assertIsString($block);
        
        // Test description validation
        $validation = $service->validateDescription($result['full_description']);
        $this->assertIsArray($validation);
    }

    public function testContextInjectorService(): void
    {
        $service = new ContextInjectorService();
        
        $item = [
            'title' => 'Bauleto 41L Universal',
            'category_id' => 'MLB3530'
        ];
        
        $contexts = $service->detectApplicableContexts($item);
        $this->assertIsArray($contexts);
        
        $text = "Este produto é excelente.";
        $injected = $service->inject($text, $contexts);
        $this->assertIsString($injected);
        
        $contextPhrases = $service->generateContextPhrases('profissional', $item);
        $this->assertIsArray($contextPhrases);
    }

    public function testLongTailGeneratorService(): void
    {
        $service = new LongTailGeneratorService();
        
        $title = "Bauleto 41L Universal";
        $categoryId = "MLB3530";
        
        $keywords = $service->generate($title, $categoryId);
        
        $this->assertIsArray($keywords);
        
        // Should contain some long-tail variations
        $this->assertNotEmpty($keywords);
    }

    public function testSearchCoverageService(): void
    {
        $service = new SearchCoverageService();
        
        $item = [
            'title' => 'Bauleto 41L Universal',
            'category_id' => 'MLB3530',
            'description' => str_repeat('Este é um bauleto de qualidade. ', 20) // Make sure it's long enough
        ];
        
        $coverage = $service->analyzeCoverage($item);
        $this->assertIsArray($coverage);
        
        $score = $service->calculateCoverageScore($coverage);
        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
        
        $gaps = $service->identifyGaps($coverage);
        $this->assertIsArray($gaps);
        
        $suggestions = $service->suggestImprovements($gaps);
        $this->assertIsArray($suggestions);
    }

    public function testCompatibilityService(): void
    {
        $service = new CompatibilityService();
        
        $categoryId = "MLB3530";
        $compatibilityList = $service->getCompatibilityList($categoryId);
        $this->assertIsArray($compatibilityList);
        
        $compatibilities = ['Honda', 'Yamaha'];
        $text = $service->generateCompatibilityText($compatibilities);
        $this->assertIsString($text);
        
        $detected = $service->detectFromTitle("Baú Honda Titan");
        $this->assertIsArray($detected);
    }

    public function testKeywordResearchService(): void
    {
        $service = new KeywordResearchService();
        
        $categoryId = "MLB3530";
        $baseKeyword = "baú moto";
        
        $keywords = $service->getKeywords($categoryId, $baseKeyword);
        $this->assertIsArray($keywords);
        
        $classification = $service->classifyByType($keywords, $categoryId);
        $this->assertIsArray($classification);
        $this->assertArrayHasKey('core', $classification);
        $this->assertArrayHasKey('suporte', $classification);
        $this->assertArrayHasKey('tecnica', $classification);
        $this->assertArrayHasKey('contexto', $classification);
        
        $volume = $service->estimateSearchVolume($baseKeyword, $categoryId);
        $this->assertIsArray($volume);
        $this->assertArrayHasKey('keyword', $volume);
        // 'total_listings' é o dado real (oferta via API ML); 'monthly_volume' foi
        // mantido apenas por compatibilidade de schema, mas nunca mais é fabricado
        // (ver correção de metodologia em KeywordResearchService::estimateSearchVolume).
        $this->assertArrayHasKey('total_listings', $volume);
        $this->assertArrayHasKey('competition', $volume);
        
        $withCompetition = $service->getWithCompetitionScore($keywords);
        $this->assertIsArray($withCompetition);
    }
}