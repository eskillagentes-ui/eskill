<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\AIPredictiveAnalyticsService;
use App\Services\DecisionEngineService;
use App\Services\AI\Optimizers\TechSheetOptimizer;
use App\Services\SEO\SynonymExpansionService;

class AIIntegrationTest extends TestCase
{
    private function skipIfNoEnvVars(): void
    {
        $hasRealKey = static function (string $key): bool {
            $value = (string)($_ENV[$key] ?? getenv($key) ?: '');
            return $value !== '' && !str_contains($value, 'phpunit-fake-key-not-real');
        };
        if (!$hasRealKey('OPENAI_API_KEY') && !$hasRealKey('ANTHROPIC_API_KEY') && !$hasRealKey('GEMINI_API_KEY')) {
            $this->markTestSkipped('No real AI API keys configured (placeholder keys do not run integration tests)');
        }
    }

    private function skipIfNoTable(string $tableName): void
    {
        try {
            $db = \App\Database::getInstance();
            $db->query("SELECT 1 FROM {$tableName} LIMIT 1");
        } catch (\PDOException $e) {
            $this->markTestSkipped("Tabela {$tableName} não existe no banco de teste. Execute as migrations.");
        }
    }

    public function testPredictiveAnalyticsServiceIntegration(): void
    {
        $this->skipIfNoEnvVars();
        $this->skipIfNoTable('item_metrics_history');
        
        $service = new AIPredictiveAnalyticsService();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('analyzeCompetitorPricing');
        $method->setAccessible(true);
        
        $result = $method->invoke($service,
            ['title' => 'Smartphone Test', 'category_id' => 'MLB1055', 'price' => 1000],
            ['market_type' => 'competitive']
        );
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('avg_price', $result);
        $this->assertArrayHasKey('price_range', $result);
    }

    public function testDecisionEngineServiceIntegration(): void
    {
        $this->skipIfNoEnvVars();
        
        $service = new DecisionEngineService();
        
        $factors = [
            'price_competitiveness' => 0.7,
            'demand_level' => 0.8,
            'inventory_pressure' => 0.3,
            'seasonal_multiplier' => 1.2
        ];
        
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('simulatePricingPrediction');
        $method->setAccessible(true);
        
        $result = $method->invoke($service, $factors);
        
        $this->assertIsFloat($result);
        $this->assertGreaterThanOrEqual(-0.2, $result);
        $this->assertLessThanOrEqual(0.2, $result);
    }

    public function testSynonymExpansionServiceIntegration(): void
    {
        $this->skipIfNoEnvVars();
        
        $service = new SynonymExpansionService();
        
        $result = $service->generateAISynonyms('smartphone', 'MLB1055', 5);
        
        $this->assertIsArray($result);
        $this->assertLessThanOrEqual(5, count($result));
        
        // Verify we got some meaningful synonyms
        if (!empty($result)) {
            foreach ($result as $synonym) {
                $this->assertIsString($synonym);
                $this->assertNotEmpty(trim($synonym));
            }
        }
    }

    public function testFallbackBehaviorWhenAIFails(): void
    {
        $this->skipIfNoTable('item_metrics_history');

        // Test that services gracefully handle AI unavailability
        $service = new AIPredictiveAnalyticsService();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('analyzeCompetitorPricing');
        $method->setAccessible(true);
        
        // This should work even without AI due to fallbacks
        $result = $method->invoke($service,
            ['title' => 'Test Product'],
            []
        );
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('avg_price', $result);
    }
}