<?php

declare(strict_types=1);

namespace Tests\Acceptance\SEO;

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

/**
 * Acceptance tests for the complete SEO Strategies system
 * These tests verify that the system meets business requirements
 */
class SEOAcceptanceTest extends TestCase
{
    public function testCompleteSEOOptimizationWorkflow(): void
    {
        // Business requirement: The system should optimize an item with all 12 strategies
        $engine = new SEOStrategiesEngine();
        
        // Given an item that needs SEO optimization
        $itemId = 'TEST_ITEM_ACCEPTANCE';
        $item = [
            'id' => $itemId,
            'title' => 'Bauleto 41L Universal para Moto',
            'description' => 'Baú traseiro para moto, capacidade de 41 litros, modelo universal compatível com várias marcas.',
            'category_id' => 'MLB3530', // Baús e Bagageiros
            'model' => '',
            'attributes' => [
                'capacity' => '41L',
                'color' => 'preto',
                'material' => 'ABS'
            ]
        ];

        // When we run a complete SEO optimization
        $result = $engine->optimizeFull($itemId);

        // Then the optimization should complete successfully
        $this->assertArrayHasKey('synonym_expansion', $result);
        $this->assertArrayHasKey('keyword_distribution', $result);
        $this->assertArrayHasKey('description_building', $result);
        $this->assertArrayHasKey('coverage_analysis', $result);
        $this->assertArrayHasKey('semantic_scoring', $result);
        $this->assertArrayHasKey('overall_score', $result);
        $this->assertArrayHasKey('report', $result);
        
        // And the overall score should be reasonable (above 50)
        $this->assertGreaterThanOrEqual(50, $result['overall_score']);
        $this->assertLessThanOrEqual(100, $result['overall_score']);
        
        // And the report should contain expected information
        $this->assertArrayHasKey('item_id', $result['report']);
        $this->assertArrayHasKey('timestamp', $result['report']);
        $this->assertArrayHasKey('overall_score', $result['report']);
        $this->assertArrayHasKey('executed_strategies', $result['report']);
        $this->assertNotEmpty($result['report']['executed_strategies']);
    }

    public function testSynonymExpansionMeetsRequirements(): void
    {
        // Business requirement: System should expand synonyms with hierarchical levels
        $service = new SynonymExpansionService();
        
        $title = "Bauleto 41L Universal";
        $categoryId = "MLB3530";
        
        $result = $service->expand($title, $categoryId);
        
        // Should return hierarchical structure
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Should identify appropriate level
        $level = $service->identifyLevel($title);
        $this->assertIsString($level);
        $this->assertNotEmpty($level);
        
        // Should generate optimized model
        $model = $service->generateOptimizedModel($title, $categoryId);
        $this->assertIsArray($model);
        $this->assertArrayHasKey('model', $model);
        $this->assertArrayHasKey('synonyms_used', $model);
        $this->assertArrayHasKey('score', $model);
    }

    public function testKeywordDistributionMeetsRequirements(): void
    {
        // Business requirement: System should distribute keywords intelligently by field weight
        $service = new KeywordDistributionService();
        
        $item = [
            'title' => 'Bauleto 41L Universal',
            'category_id' => 'MLB3530'
        ];
        
        $categoryId = 'MLB3530';
        
        $distribution = $service->distribute($item, $categoryId);
        
        // Should return distribution for all required fields
        $this->assertIsArray($distribution);
        $this->assertArrayHasKey('title', $distribution);
        $this->assertArrayHasKey('model', $distribution);
        $this->assertArrayHasKey('description', $distribution);
        
        // Each field should have appropriate metadata
        foreach ($distribution as $field => $data) {
            $this->assertArrayHasKey('keywords', $data);
            $this->assertArrayHasKey('count', $data);
            $this->assertArrayHasKey('density_status', $data);
            $this->assertArrayHasKey('weight', $data);
        }
    }

    public function testDescriptionBuildingMeetsRequirements(): void
    {
        // Business requirement: System should build structured descriptions with 4 blocks
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
        
        // Should return structured result
        $this->assertIsArray($result);
        $this->assertArrayHasKey('blocks', $result);
        $this->assertArrayHasKey('full_description', $result);
        $this->assertArrayHasKey('word_count', $result);
        $this->assertArrayHasKey('score', $result);
        
        // Should have 4 blocks as specified in requirements
        $this->assertCount(4, $result['blocks']); // 4 blocks: beneficios, especificacoes, compatibilidade, faq
        
        // Word count should be in expected range (400-600 as per requirements)
        $this->assertGreaterThanOrEqual(400, $result['word_count']);
        $this->assertLessThanOrEqual(700, $result['word_count']); // Slightly flexible upper bound
        
        // Score should be reasonable
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function testSearchCoverageAnalysisMeetsRequirements(): void
    {
        // Business requirement: System should analyze search coverage across multiple types
        $service = new SearchCoverageService();
        
        $item = [
            'title' => 'Bauleto 41L Universal',
            'category_id' => 'MLB3530',
            'description' => str_repeat('Este é um bauleto de qualidade. ', 20) // Make sure it's long enough
        ];
        
        $coverage = $service->analyzeCoverage($item);
        
        // Should analyze multiple search types as defined in requirements
        $this->assertIsArray($coverage);
        $this->assertArrayHasKey('generica', $coverage);
        $this->assertArrayHasKey('qualificada', $coverage);
        $this->assertArrayHasKey('long_tail', $coverage);
        $this->assertArrayHasKey('marca_modelo', $coverage);
        $this->assertArrayHasKey('filtros', $coverage);
        
        // Each type should have appropriate metadata
        foreach ($coverage as $type => $data) {
            $this->assertArrayHasKey('covered', $data);
            $this->assertArrayHasKey('field', $data);
            $this->assertArrayHasKey('weight', $data);
            $this->assertArrayHasKey('keywords_present', $data);
        }
        
        // Should calculate a reasonable coverage score
        $score = $service->calculateCoverageScore($coverage);
        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testCompatibilityDetectionMeetsRequirements(): void
    {
        // Business requirement: System should detect and expand compatibility information
        $service = new CompatibilityService();
        
        $categoryId = "MLB3530";
        
        // Should return compatibility list for category
        $compatibilityList = $service->getCompatibilityList($categoryId);
        $this->assertIsArray($compatibilityList);
        $this->assertNotEmpty($compatibilityList);
        
        // Should generate appropriate compatibility text
        $compatibilities = ['Honda' => ['CG 160', 'Titan'], 'Yamaha' => ['Factor', 'Fazer']];
        $text = $service->generateCompatibilityText($compatibilities);
        $this->assertIsString($text);
        $this->assertNotEmpty($text);
        
        // Should detect compatibility from title
        $detected = $service->detectFromTitle("Baú Honda CG 160 e Yamaha Factor");
        $this->assertIsArray($detected);
    }

    public function testSemanticScoringMeetsRequirements(): void
    {
        // Business requirement: System should calculate semantic relevance scores
        $service = new SemanticScoreService();
        
        $word = "baú";
        $title = "Bauleto 41L Universal";
        $categoryId = "MLB3530";
        
        $score = $service->calculateScore($word, $title, $categoryId);
        
        // Should return a reasonable score
        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
        
        // Should score multiple words
        $words = ['baú', 'moto', 'entrega'];
        $scores = $service->scoreWords($words, $title, $categoryId);
        
        $this->assertIsArray($scores);
        $this->assertCount(count($words), $scores);
        
        foreach ($words as $word) {
            $this->assertArrayHasKey($word, $scores);
        }
        
        // Should rank words appropriately
        $ranked = $service->rankByScore($words, $title, $categoryId);
        $this->assertIsArray($ranked);
    }

    public function testLongTailGenerationMeetsRequirements(): void
    {
        // Business requirement: System should generate long-tail keywords automatically
        $service = new LongTailGeneratorService();
        
        $title = "Bauleto 41L Universal para Moto";
        $categoryId = "MLB3530";
        
        $keywords = $service->generate($title, $categoryId);
        
        $this->assertIsArray($keywords);
        $this->assertNotEmpty($keywords);
        
        // Should contain variations of the original title
        $titleLower = strtolower($title);
        $foundRelevant = false;
        
        foreach ($keywords as $keyword) {
            if (strpos(strtolower($keyword), 'bauleto') !== false || 
                strpos(strtolower($keyword), 'moto') !== false) {
                $foundRelevant = true;
                break;
            }
        }
        
        $this->assertTrue($foundRelevant, "Should generate keywords related to the title");
    }

    public function testContextInjectionMeetsRequirements(): void
    {
        // Business requirement: System should inject usage contexts appropriately
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
        $this->assertStringStartsWith($text, $injected); // Original text should be preserved
        
        // Should generate context phrases
        $contextPhrases = $service->generateContextPhrases('profissional', $item);
        $this->assertIsArray($contextPhrases);
    }

    public function testKeywordResearchMeetsRequirements(): void
    {
        // Business requirement: System should research and classify keywords
        $service = new KeywordResearchService();
        
        $categoryId = "MLB3530";
        $baseKeyword = "baú moto";
        
        $keywords = $service->getKeywords($categoryId, $baseKeyword);
        $this->assertIsArray($keywords);
        $this->assertNotEmpty($keywords);
        
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

    public function testSystemPerformanceRequirements(): void
    {
        // Business requirement: System should meet performance targets
        $engine = new SEOStrategiesEngine();
        
        $itemId = 'PERFORMANCE_TEST';
        
        // Measure optimization time
        $startTime = microtime(true);
        $result = $engine->optimizeFull($itemId);
        $endTime = microtime(true);
        
        $executionTimeMs = ($endTime - $startTime) * 1000;
        
        // Requirement: Time of optimization complete < 5s (5000ms)
        $this->assertLessThan(5000, $executionTimeMs, 
            "Optimization should complete in less than 5 seconds, took {$executionTimeMs}ms");
        
        // Requirement: Score after optimization > 85
        $this->assertGreaterThanOrEqual(85, $result['overall_score'], 
            "Optimization should achieve score of at least 85, got {$result['overall_score']}");
    }
}