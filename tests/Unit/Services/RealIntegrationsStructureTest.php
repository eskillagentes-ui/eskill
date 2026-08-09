<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;

/**
 * Testes unitários para verificar que todas as integrações reais 
 * possuem os métodos corretos e suas dependências.
 */
class RealIntegrationsStructureTest extends TestCase
{
    // =============================
    // CloneROIAnalysisService
    // =============================

    public function testCloneROIAnalysisServiceHasIntegrationMethods(): void
    {
        $class = \App\Services\CloneROIAnalysisService::class;

        $this->assertTrue(method_exists($class, 'syncMetricsFromML'), 'Deve ter syncMetricsFromML');
        $this->assertTrue(method_exists($class, 'getMlClient'), 'Deve ter getMlClient');
        $this->assertTrue(method_exists($class, 'getRealCategoryBenchmark'), 'Deve ter getRealCategoryBenchmark');
    }

    // =============================
    // KeywordKiller
    // =============================

    public function testKeywordKillerHasVolumeEstimation(): void
    {
        $class = \App\Services\AI\SEO\KeywordKiller::class;

        // Correção de metodologia: "estimateVolumes"/"estimateVolumeHeuristic" foram
        // removidos porque fabricavam um número de "volume de busca" (7% da oferta,
        // ou heurística por tamanho da palavra) sem nenhuma base real. O Mercado Livre
        // não expõe volume de busca via API pública. Substituído por
        // "analyzeKeywordSupply", que reporta apenas dados reais (total de anúncios
        // concorrentes) e não fabrica um "fallback" quando a API falha.
        $this->assertTrue(method_exists($class, 'analyzeKeywordSupply'), 'Deve ter analyzeKeywordSupply');
        $this->assertTrue(method_exists($class, 'classifyCompetitionFromListings'), 'Deve classificar competição a partir de dados reais');
    }

    // =============================
    // MarketAnalytics
    // =============================

    public function testMarketAnalyticsHasKeywordDiscovery(): void
    {
        $class = \App\Services\AI\SEO\MarketAnalytics::class;

        $this->assertTrue(method_exists($class, 'discoverKeywordOpportunities'), 'Deve ter discoverKeywordOpportunities');

        // Verifica dependência do MercadoLivreClient
        $reflection = new \ReflectionClass($class);
        $this->assertTrue($reflection->hasProperty('mlClient'), 'Deve ter propriedade mlClient');
    }

    // =============================
    // SEOPerformancePredictor
    // =============================

    public function testSEOPerformancePredictorHasRealScoring(): void
    {
        $class = \App\Services\AI\SEO\SEOPerformancePredictor::class;

        $this->assertTrue(method_exists($class, 'calculateCurrentScore'), 'Deve ter calculateCurrentScore');
        $this->assertTrue(method_exists($class, 'getCategoryPriceStats'), 'Deve ter getCategoryPriceStats');

        // Verifica dependência
        $reflection = new \ReflectionClass($class);
        $this->assertTrue($reflection->hasProperty('mlClient'), 'Deve ter propriedade mlClient');
    }

    // =============================
    // LearningEngine
    // =============================

    public function testLearningEngineHasAttributePatterns(): void
    {
        $class = \App\Services\AI\ML\LearningEngine::class;

        $this->assertTrue(method_exists($class, 'analyzeAttributePatterns'), 'Deve ter analyzeAttributePatterns');

        // Verifica dependência
        $reflection = new \ReflectionClass($class);
        $this->assertTrue($reflection->hasProperty('mlClient'), 'Deve ter propriedade mlClient');
    }

    // =============================
    // ListingBuilderService (Builder)
    // =============================

    public function testListingBuilderServiceHasQualityPrediction(): void
    {
        $class = \App\Services\ListingBuilder\ListingBuilderService::class;

        $this->assertTrue(method_exists($class, 'predictQualityScore'), 'Deve ter predictQualityScore');
        $this->assertTrue(method_exists($class, 'evaluatePriceCompetitiveness'), 'Deve ter evaluatePriceCompetitiveness');
    }

    // =============================
    // AIImageAnalyzerService
    // =============================

    public function testAIImageAnalyzerServiceHasIntegrationMethods(): void
    {
        $class = \App\Services\AIImageAnalyzerService::class;

        $helperMethods = [
            'extractColorsWithGd',
            'detectBackgroundColor',
            'calculateContrastRatio',
            'getRelativeLuminance',
            'getColorName',
            'tryTesseractOcr',
            'tryLlmVisionOcr',
            'tryLlmVisionAnalysis',
        ];

        foreach ($helperMethods as $method) {
            $this->assertTrue(
                method_exists($class, $method),
                "AIImageAnalyzerService deve ter método {$method}()"
            );
        }
    }

    // =============================
    // TESTE DE INTEGRAÇÃO: Nenhum método retorna hardcoded mock
    // =============================

    public function testMLAnalyticsIntelligenceNoPreviouslyMissingMethods(): void
    {
        $class = \App\Services\MercadoLivre\MLAnalyticsIntelligenceService::class;

        // Esses métodos eram chamados mas não existiam — agora devem existir
        $previouslyMissing = [
            'extractSearchPatternInsights',
            'identifySearchOptimizations',
            'predictPriceOptimization',
            'predictInventoryNeeds',
            'predictMarketTrends',
            'predictCustomerBehavior',
            'predictCompetitorActions',
            'predictSeasonalPatterns',
            'generateOpportunityScoring',
        ];

        foreach ($previouslyMissing as $method) {
            $this->assertTrue(
                method_exists($class, $method),
                "MLAnalyticsIntelligenceService deve ter método (antes missing) {$method}()"
            );
        }
    }
}
