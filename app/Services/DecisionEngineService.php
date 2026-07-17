<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\AdvancedRedisCacheService;
use App\Services\CentralizedLogService;
use Exception;
use PDO;

/**
 * Decision Engine Service
 * Motor de decisões autônomas: usa LLM (via LLMService) para pricing
 * e fórmulas heurísticas para inventário/campanhas.
 * NÃO possui modelos ML treinados — os "model stats" são configuração estática.
 */
class DecisionEngineService {
    private PDO $db;
    private AdvancedRedisCacheService $cache;
    private CentralizedLogService $logger;
    private LLMService $llm;
    private array $config;
    private array $models;
    private array $decisionFactors;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->cache = new AdvancedRedisCacheService();
        $this->logger = new CentralizedLogService();
        $this->llm = new LLMService();

        $this->config = [
            'ml_enabled' => $_ENV['ML_ENABLED'] ?? true,
            'decision_confidence_threshold' => $_ENV['DECISION_CONFIDENCE_THRESHOLD'] ?? 0.7,
            'max_price_change_percent' => $_ENV['MAX_PRICE_CHANGE_PERCENT'] ?? 15.0,
            'learning_rate' => $_ENV['ML_LEARNING_RATE'] ?? 0.001,
            'model_retrain_interval' => $_ENV['MODEL_RETRAIN_INTERVAL'] ?? 3600, // 1 hour
        ];

        $this->initializeModels();
        $this->initializeDecisionFactors();
        $this->ensureDecisionTables();
    }

    /**
     * Tomar decisão autônoma de precificação
     */
    public function makePricingDecision(string $itemId, array $context = []): array {
        $startTime = microtime(true);

        try {
            // Coletar dados para decisão
            $decisionData = $this->collectDecisionData($itemId, $context);

            // Analisar fatores de decisão
            $factors = $this->analyzeDecisionFactors($decisionData);

            // Aplicar modelo de ML para decisão
            $mlPrediction = $this->applyMlModel('pricing', $factors);

            // Calcular preço otimizado
            $optimizedPrice = $this->calculateOptimizedPrice($decisionData, $mlPrediction);

            // Validar decisão
            $decision = $this->validateDecision($decisionData['current_price'], $optimizedPrice, $mlPrediction['confidence']);

            // Registrar decisão
            $decisionId = $this->logDecision($itemId, $decision, $factors, $mlPrediction);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $result = [
                'decision_id' => $decisionId,
                'item_id' => $itemId,
                'decision_type' => 'pricing',
                'current_price' => $decisionData['current_price'],
                'recommended_price' => $decision['price'],
                'price_change' => $decision['change_amount'],
                'price_change_percent' => $decision['change_percent'],
                'confidence' => $mlPrediction['confidence'],
                'factors' => $factors,
                'reasoning' => $decision['reasoning'],
                'should_apply' => $decision['should_apply'],
                'execution_time_ms' => $executionTime,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            $this->cache->set("decision_result:{$itemId}", $result, 300);

            $this->logger->log('info', 'AI Decision Made', [
                'type' => 'ai_decision',
                'decision_type' => 'pricing',
                'item_id' => $itemId,
                'confidence' => $mlPrediction['confidence'],
                'should_apply' => $decision['should_apply'],
                'execution_time' => $executionTime
            ]);

            return $result;

        } catch (Exception $e) {
            $this->logger->log('error', 'AI Decision Failed', [
                'type' => 'ai_decision_error',
                'item_id' => $itemId,
                'error' => $e->getMessage()
            ]);

            throw new Exception("Decision engine failed: " . $e->getMessage());
        }
    }

    /**
     * Tomar decisão de gestão de estoque
     */
    public function makeInventoryDecision(string $itemId, array $context = []): array {
        try {
            $decisionData = $this->collectInventoryData($itemId, $context);
            $factors = $this->analyzeInventoryFactors($decisionData);
            $mlPrediction = $this->applyMlModel('inventory', $factors);

            $decision = [
                'action' => $this->determineInventoryAction($factors, $mlPrediction),
                'quantity_recommended' => $this->calculateOptimalQuantity($decisionData, $mlPrediction),
                'urgency' => $this->calculateUrgency($factors),
                'confidence' => $mlPrediction['confidence'],
                'reasoning' => $this->generateInventoryReasoning($factors, $mlPrediction)
            ];

            $decisionId = $this->logDecision($itemId, $decision, $factors, $mlPrediction, 'inventory');

            return [
                'decision_id' => $decisionId,
                'item_id' => $itemId,
                'decision_type' => 'inventory',
                'current_stock' => $decisionData['current_stock'],
                'recommended_action' => $decision['action'],
                'recommended_quantity' => $decision['quantity_recommended'],
                'urgency' => $decision['urgency'],
                'confidence' => $decision['confidence'],
                'reasoning' => $decision['reasoning'],
                'factors' => $factors,
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->logger->log('error', 'Inventory Decision Failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Tomar decisão de otimização de campanha
     */
    public function makeCampaignDecision(string $campaignId, array $context = []): array {
        try {
            $decisionData = $this->collectCampaignData($campaignId, $context);
            $factors = $this->analyzeCampaignFactors($decisionData);
            $mlPrediction = $this->applyMlModel('campaign', $factors);

            $decision = [
                'action' => $this->determineCampaignAction($factors, $mlPrediction),
                'budget_adjustment' => $this->calculateBudgetAdjustment($decisionData, $mlPrediction),
                'bid_adjustment' => $this->calculateBidAdjustment($decisionData, $mlPrediction),
                'confidence' => $mlPrediction['confidence'],
                'reasoning' => $this->generateCampaignReasoning($factors, $mlPrediction)
            ];

            $decisionId = $this->logDecision($campaignId, $decision, $factors, $mlPrediction, 'campaign');

            return [
                'decision_id' => $decisionId,
                'campaign_id' => $campaignId,
                'decision_type' => 'campaign',
                'current_budget' => $decisionData['current_budget'],
                'current_bid' => $decisionData['current_bid'],
                'recommended_action' => $decision['action'],
                'budget_adjustment' => $decision['budget_adjustment'],
                'bid_adjustment' => $decision['bid_adjustment'],
                'confidence' => $decision['confidence'],
                'reasoning' => $decision['reasoning'],
                'factors' => $factors,
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->logger->log('error', 'Campaign Decision Failed', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Obter histórico de decisões
     */
    public function getDecisionHistory(array $filters = [], int $limit = 50): array {
        try {
            $sql = "SELECT * FROM ai_decisions WHERE 1=1";
            $params = [];

            $limitSql = max(1, min((int)$limit, 500));

            if (isset($filters['item_id'])) {
                $sql .= " AND target_id = :item_id";
                $params['item_id'] = $filters['item_id'];
            }

            if (isset($filters['decision_type'])) {
                $sql .= " AND decision_type = :decision_type";
                $params['decision_type'] = $filters['decision_type'];
            }

            if (isset($filters['applied_only'])) {
                $sql .= " AND applied = 1";
            }

            if (isset($filters['start_date'])) {
                $sql .= " AND created_at >= :start_date";
                $params['start_date'] = $filters['start_date'];
            }

            $sql .= " ORDER BY created_at DESC LIMIT {$limitSql}";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }
            $stmt->execute();

            $decisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Parse JSON fields
            foreach ($decisions as &$decision) {
                $decision['decision_data'] = json_decode($decision['decision_data'], true);
                $decision['factors'] = json_decode($decision['factors'], true);
                $decision['ml_prediction'] = json_decode($decision['ml_prediction'], true);
            }

            return $decisions;

        } catch (Exception $e) {
            $this->logger->log('error', 'Get Decision History Failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Aplicar decisão automaticamente
     */
    public function applyDecision(string $decisionId): array {
        try {
            $guard = \App\Helpers\MlWriteAutomation::guard('DecisionEngineService::applyDecision');
            if (!$guard['allowed']) {
                return [
                    'applied' => false,
                    'blocked' => true,
                    'error' => $guard['reason'],
                    'mode' => 'recommend_only',
                ];
            }

            // Buscar decisão
            $stmt = $this->db->prepare("SELECT * FROM ai_decisions WHERE id = :id");
            $stmt->execute(['id' => $decisionId]);
            $decision = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$decision) {
                throw new Exception("Decision not found: {$decisionId}");
            }

            $decisionData = json_decode($decision['decision_data'], true);
            $result = ['applied' => false, 'error' => null];

            // Aplicar baseado no tipo
            switch ($decision['decision_type']) {
                case 'pricing':
                    $result = $this->applyPricingDecision($decision['target_id'], $decisionData);
                    break;
                case 'inventory':
                    $result = $this->applyInventoryDecision($decision['target_id'], $decisionData);
                    break;
                case 'campaign':
                    $result = $this->applyCampaignDecision($decision['target_id'], $decisionData);
                    break;
                default:
                    throw new Exception("Unknown decision type: {$decision['decision_type']}");
            }

            // Atualizar status da decisão
            $stmt = $this->db->prepare("
                UPDATE ai_decisions
                SET applied = :applied, applied_at = :applied_at, apply_result = :result
                WHERE id = :id
            ");
            $stmt->execute([
                'applied' => $result['applied'] ? 1 : 0,
                'applied_at' => date('Y-m-d H:i:s'),
                'result' => json_encode($result),
                'id' => $decisionId
            ]);

            $this->logger->log('info', 'AI Decision Applied', [
                'decision_id' => $decisionId,
                'decision_type' => $decision['decision_type'],
                'success' => $result['applied']
            ]);

            return $result;

        } catch (Exception $e) {
            $this->logger->log('error', 'Apply Decision Failed', [
                'decision_id' => $decisionId,
                'error' => $e->getMessage()
            ]);

            return ['applied' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obter estatísticas de performance do motor
     */
    public function getPerformanceStats(): array {
        try {
            // Estatísticas gerais
            $stmt = $this->db->query("
                SELECT
                    decision_type,
                    COUNT(*) as total_decisions,
                    SUM(applied) as applied_decisions,
                    AVG(confidence) as avg_confidence,
                    AVG(execution_time_ms) as avg_execution_time
                FROM ai_decisions
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY decision_type
            ");
            $typeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Performance por dia
            $stmt = $this->db->query("
                SELECT
                    DATE(created_at) as date,
                    COUNT(*) as decisions,
                    SUM(applied) as applied,
                    AVG(confidence) as avg_confidence
                FROM ai_decisions
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");
            $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top fatores de decisão
            $stmt = $this->db->query("
                SELECT
                    JSON_EXTRACT(factors, '$.top_factor') as factor,
                    COUNT(*) as frequency
                FROM ai_decisions
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND JSON_EXTRACT(factors, '$.top_factor') IS NOT NULL
                GROUP BY factor
                ORDER BY frequency DESC
                LIMIT 10
            ");
            $topFactors = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'period' => '7 days',
                'by_type' => $typeStats,
                'daily_performance' => $dailyStats,
                'top_factors' => $topFactors,
                'ml_models' => $this->getModelStats(),
                'generated_at' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            $this->logger->log('error', 'Get Performance Stats Failed', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get dashboard-formatted performance metrics
     * Used by AICenterController for the AI Dashboard
     */
    public function getPerformanceMetrics(): array
    {
        try {
            // Total decisions count
            $stmt = $this->db->query("
                SELECT COUNT(*) as total FROM ai_decisions
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $totalDecisions = $stmt->fetchColumn() ?: 0;

            // Pricing updates count
            $stmt = $this->db->query("
                SELECT COUNT(*) as total FROM ai_decisions
                WHERE decision_type = 'pricing'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $pricingUpdates = $stmt->fetchColumn() ?: 0;

            // Inventory alerts count
            $stmt = $this->db->query("
                SELECT COUNT(*) as total FROM ai_decisions
                WHERE decision_type = 'inventory'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $inventoryAlerts = $stmt->fetchColumn() ?: 0;

            // Calculate accuracy (applied vs total)
            $stmt = $this->db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN applied = 1 THEN 1 ELSE 0 END) as applied
                FROM ai_decisions
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $accuracyData = $stmt->fetch(PDO::FETCH_ASSOC);
            $accuracy = $accuracyData['total'] > 0
                ? round(($accuracyData['applied'] / $accuracyData['total']) * 100)
                : 0;

            return [
                'total_decisions' => (int)$totalDecisions,
                'pricing_updates' => (int)$pricingUpdates,
                'inventory_alerts' => (int)$inventoryAlerts,
                'accuracy' => $accuracy . '%'
            ];

        } catch (Exception $e) {
            // Return zeros on error
            return [
                'total_decisions' => 0,
                'pricing_updates' => 0,
                'inventory_alerts' => 0,
                'accuracy' => '0%'
            ];
        }
    }

    /**
     * Coletar dados para decisão de preço
     */
    private function collectDecisionData(string $itemId, array $context): array {
        // Dados básicos do item
        $stmt = $this->db->prepare("SELECT * FROM items WHERE ml_item_id = :item_id");
        $stmt->execute(['item_id' => $itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception("Item not found: {$itemId}");
        }

        $marketData = $this->getMarketData($itemId);

        $salesHistory = $this->getSalesHistory($itemId);

        // Dados sazonais
        $seasonalData = $this->getSeasonalData($item['category_id']);

        return [
            'item_id' => $itemId,
            'current_price' => (float)$item['price'],
            'category_id' => $item['category_id'],
            'listing_type' => $item['listing_type_id'] ?? 'bronze',
            'condition' => $item['condition'] ?? 'new',
            'available_quantity' => (int)($item['available_quantity'] ?? 1),
            'sold_quantity' => (int)($item['sold_quantity'] ?? 0),
            'market_data' => $marketData,
            'sales_history' => $salesHistory,
            'seasonal_data' => $seasonalData,
            'context' => $context
        ];
    }

    /**
     * Analisar fatores de decisão
     */
    private function analyzeDecisionFactors(array $data): array {
        $factors = [];

        // Fator de competitividade
        $avgCompetitorPrice = $data['market_data']['avg_competitor_price'] ?? $data['current_price'];
        $factors['price_competitiveness'] = $this->calculatePriceCompetitiveness($data['current_price'], $avgCompetitorPrice);

        // Fator de demanda
        $factors['demand_level'] = $this->calculateDemandLevel($data['sales_history'], $data['seasonal_data']);

        // Fator de estoque
        $factors['inventory_pressure'] = $this->calculateInventoryPressure($data['available_quantity'], $data['sold_quantity']);

        // Fator sazonal
        $factors['seasonal_multiplier'] = $data['seasonal_data']['current_multiplier'] ?? 1.0;

        // Fator de posicionamento de listagem
        $factors['listing_boost'] = $this->getListingBoost($data['listing_type']);

        // Fator de performance histórica
        $factors['performance_score'] = $this->calculatePerformanceScore($data['sales_history']);

        // Identificar fator dominante
        $factors['top_factor'] = $this->identifyTopFactor($factors);
        $factors['factor_strength'] = $this->calculateFactorStrength($factors);

        return $factors;
    }

    /**
     * Aplicar predição (LLM para pricing, heurística para inventory/campaign)
     */
    private function applyMlModel(string $modelType, array $factors): array {
        $baseConfidence = 0.8;
        $factorWeight = $factors['factor_strength'] ?? 0.5;

        $prediction = 0.0;
        $confidence = $baseConfidence;

        switch ($modelType) {
            case 'pricing':
                $prediction = $this->simulatePricingPrediction($factors);
                $confidence = min(0.95, $baseConfidence + ($factorWeight * 0.2));
                break;

            case 'inventory':
                $prediction = $this->calculateInventoryScore($factors);
                $confidence = min(0.90, $baseConfidence + ($factorWeight * 0.15));
                break;

            case 'campaign':
                $prediction = $this->calculateCampaignScore($factors);
                $confidence = min(0.85, $baseConfidence + ($factorWeight * 0.1));
                break;
        }

        return [
            'model_type' => $modelType,
            'prediction' => $prediction,
            'confidence' => round($confidence, 3),
            'factors_used' => array_keys($factors),
            'model_version' => '1.0.0-heuristic', // Transparent labeling
            'engine' => 'Rule-Based Heuristic',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Calcular preço otimizado
     */
    private function calculateOptimizedPrice(array $data, array $mlPrediction): float {
        $currentPrice = $data['current_price'];
        $prediction = $mlPrediction['prediction'];
        $confidence = $mlPrediction['confidence'];

        // Aplicar predição com base na confiança
        $priceChange = $prediction * $confidence;
        $newPrice = $currentPrice * (1 + $priceChange);

        // Aplicar limites de segurança
        $maxChange = $this->config['max_price_change_percent'] / 100;
        $minPrice = $currentPrice * (1 - $maxChange);
        $maxPrice = $currentPrice * (1 + $maxChange);

        return max($minPrice, min($maxPrice, $newPrice));
    }

    /**
     * Validar decisão antes de aplicar
     */
    private function validateDecision(float $currentPrice, float $newPrice, float $confidence): array {
        $changeAmount = $newPrice - $currentPrice;
        $changePercent = ($changeAmount / $currentPrice) * 100;

        $shouldApply = (
            $confidence >= $this->config['decision_confidence_threshold'] &&
            abs($changePercent) <= $this->config['max_price_change_percent'] &&
            $newPrice > 0
        );

        $reasoning = $this->generatePricingReasoning($currentPrice, $newPrice, $confidence, $changePercent);

        return [
            'price' => round($newPrice, 2),
            'change_amount' => round($changeAmount, 2),
            'change_percent' => round($changePercent, 2),
            'confidence' => $confidence,
            'should_apply' => $shouldApply,
            'reasoning' => $reasoning
        ];
    }

    /**
     * Registrar decisão no banco
     */
    private function logDecision(string $targetId, array $decision, array $factors, array $mlPrediction, string $type = 'pricing'): string {
        $decisionId = uniqid('dec_', true);

        $stmt = $this->db->prepare("
            INSERT INTO ai_decisions (
                id, target_id, decision_type, decision_data, factors, ml_prediction,
                confidence, execution_time_ms, created_at
            ) VALUES (
                :id, :target_id, :decision_type, :decision_data, :factors, :ml_prediction,
                :confidence, :execution_time, NOW()
            )
        ");

        $stmt->execute([
            'id' => $decisionId,
            'target_id' => $targetId,
            'decision_type' => $type,
            'decision_data' => json_encode($decision),
            'factors' => json_encode($factors),
            'ml_prediction' => json_encode($mlPrediction),
            'confidence' => $mlPrediction['confidence'],
            'execution_time' => 0 // Will be updated
        ]);

        return $decisionId;
    }

    /**
     * Inicializar modelos de ML
     */
    private function initializeModels(): void {
        $this->models = [
            'pricing' => [
                'version' => '1.0.0',
                'type' => 'llm-assisted', // Usa LLMService com fallback heurístico
                'features' => ['price_competitiveness', 'demand_level', 'inventory_pressure', 'seasonal_multiplier']
            ],
            'inventory' => [
                'version' => '1.0.0',
                'type' => 'heuristic', // Fórmula baseada em regras, sem ML
                'features' => ['demand_forecast', 'lead_time', 'safety_stock', 'cost_trend']
            ],
            'campaign' => [
                'version' => '1.0.0',
                'type' => 'heuristic', // Fórmula baseada em regras, sem ML
                'features' => ['roi_trend', 'bid_efficiency', 'competition_level', 'conversion_rate']
            ]
        ];
    }

    /**
     * Inicializar fatores de decisão
     */
    private function initializeDecisionFactors(): void {
        $this->decisionFactors = [
            'price_competitiveness' => ['weight' => 0.25, 'type' => 'ratio'],
            'demand_level' => ['weight' => 0.30, 'type' => 'score'],
            'inventory_pressure' => ['weight' => 0.15, 'type' => 'score'],
            'seasonal_multiplier' => ['weight' => 0.20, 'type' => 'multiplier'],
            'performance_score' => ['weight' => 0.10, 'type' => 'score'],
            'roi_trend' => ['weight' => 0.25, 'type' => 'ratio'],
            'bid_efficiency' => ['weight' => 0.20, 'type' => 'score'],
            'competition_level' => ['weight' => 0.15, 'type' => 'score'],
            'conversion_rate' => ['weight' => 0.20, 'type' => 'score'],
            'budget_utilization' => ['weight' => 0.20, 'type' => 'score']
        ];
    }

    /**
     * Garantir que tabelas existem
     */
    private function ensureDecisionTables(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ai_decisions (
                id VARCHAR(50) PRIMARY KEY,
                target_id VARCHAR(50) NOT NULL,
                decision_type VARCHAR(20) NOT NULL,
                decision_data JSON NOT NULL,
                factors JSON NOT NULL,
                ml_prediction JSON NOT NULL,
                confidence DECIMAL(5,3) NOT NULL,
                execution_time_ms INT DEFAULT 0,
                applied BOOLEAN DEFAULT FALSE,
                applied_at DATETIME NULL,
                apply_result JSON NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_target_id (target_id),
                INDEX idx_decision_type (decision_type),
                INDEX idx_confidence (confidence),
                INDEX idx_created_at (created_at)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ml_model_performance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_type VARCHAR(20) NOT NULL,
                model_version VARCHAR(10) NOT NULL,
                accuracy DECIMAL(5,3) NOT NULL,
                precision_score DECIMAL(5,3),
                recall_score DECIMAL(5,3),
                f1_score DECIMAL(5,3),
                training_samples INT,
                last_trained DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_model_type (model_type)
            )
        ");
    }

    private function getMarketData(string $itemId): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as competitor_count,
                AVG(competitor_price) as avg_competitor_price,
                MIN(competitor_price) as min_competitor_price,
                MAX(competitor_price) as max_competitor_price
            FROM competitor_tracking
            WHERE my_item_id = :item_id
        ");
        $stmt->execute(['item_id' => $itemId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data || $data['competitor_count'] == 0) {
            return [
                'avg_competitor_price' => 0,
                'min_competitor_price' => 0,
                'max_competitor_price' => 0,
                'competitor_count' => 0,
                'market_trend' => null
            ];
        }

        return [
            'avg_competitor_price' => (float)$data['avg_competitor_price'],
            'min_competitor_price' => (float)$data['min_competitor_price'],
            'max_competitor_price' => (float)$data['max_competitor_price'],
            'competitor_count' => (int)$data['competitor_count'],
            'market_trend' => null
        ];
    }

    private function getSalesHistory(string $itemId): array {
        // Fetch real metrics from history
        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN date >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN sold_quantity ELSE 0 END) as last_7_days,
                SUM(CASE WHEN date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN sold_quantity ELSE 0 END) as last_30_days,
                COUNT(DISTINCT date) as days_recorded
            FROM item_metrics_history
            WHERE item_id = :item_id AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute(['item_id' => $itemId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $last30 = (int)($data['last_30_days'] ?? 0);
        $days = (int)($data['days_recorded'] ?? 1);
        $avgDaily = $days > 0 ? $last30 / $days : 0;

        // Calculate real conversion rate if visits data is available
        $totalVisits = 0; // Would need to SUM(visits) in query above

        // Let's improve the query to get visits too
        $stmtVisits = $this->db->prepare("
            SELECT COALESCE(SUM(visits), 0) as total_visits
            FROM item_metrics_history
            WHERE item_id = :item_id AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmtVisits->execute(['item_id' => $itemId]);
        $totalVisits = (int)$stmtVisits->fetchColumn();

        $realConversion = $totalVisits > 0 ? ($last30 / $totalVisits) * 100 : 0.0;

        return [
            'last_7_days' => (int)($data['last_7_days'] ?? 0),
            'last_30_days' => $last30,
            'avg_daily_sales' => round($avgDaily, 2),
            'conversion_rate' => round($realConversion, 2),
            'trend' => $last30 > 0 ? 'active' : 'no_sales'
        ];
    }

    private function getSeasonalData(string $categoryId): array {
        $month = (int)date('m');
        $currentMultiplier = 1.0;
        $isPeak = false;

        $stmt = $this->db->prepare("
            SELECT MONTH(imh.date) as month, AVG(imh.sold_quantity) as avg_sales
            FROM item_metrics_history imh
            JOIN items i ON i.ml_item_id = imh.item_id
            WHERE i.category_id = :category_id
              AND imh.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            GROUP BY MONTH(imh.date)
        ");
        $stmt->execute(['category_id' => $categoryId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $monthly = [];
            $total = 0;
            $count = 0;
            foreach ($rows as $row) {
                $avg = (float)($row['avg_sales'] ?? 0);
                $monthly[(int)$row['month']] = $avg;
                $total += $avg;
                $count++;
            }
            $overallAvg = $count > 0 ? $total / $count : 0;
            if ($overallAvg > 0 && isset($monthly[$month])) {
                $currentMultiplier = $monthly[$month] / $overallAvg;
                $isPeak = $currentMultiplier > 1.2;
            }
        }

        return [
            'current_multiplier' => round($currentMultiplier, 2),
            'season_name' => $this->getSeasonName($month),
            'is_peak_season' => $isPeak
        ];
    }

    private function calculatePriceCompetitiveness(float $currentPrice, float $avgCompetitorPrice): float {
        if ($avgCompetitorPrice <= 0) return 0.5;
        return min(1.0, max(0.0, ($avgCompetitorPrice - $currentPrice) / $avgCompetitorPrice + 0.5));
    }

    private function calculateDemandLevel(array $salesHistory, array $seasonalData): float {
        $baseDemand = min(1.0, $salesHistory['avg_daily_sales'] / 10);
        $seasonalBoost = $seasonalData['current_multiplier'] - 1.0;
        return min(1.0, max(0.0, $baseDemand + $seasonalBoost * 0.3));
    }

    private function calculateInventoryPressure(int $available, int $sold): float {
        if ($available <= 0) return 1.0; // Máxima pressão se sem estoque
        $turnover = $sold > 0 ? $sold / ($available + $sold) : 0;
        return min(1.0, max(0.0, $turnover * 2)); // Normalizar para 0-1
    }

    private function getListingBoost(string $listingType): float {
        $boosts = [
            'free' => 0.0,
            'bronze' => 0.1,
            'silver' => 0.2,
            'gold' => 0.3,
            'premium' => 0.4
        ];
        return $boosts[$listingType] ?? 0.0;
    }

    private function calculatePerformanceScore(array $salesHistory): float {
        $conversionRate = $salesHistory['conversion_rate'] ?? 0.05;
        return min(1.0, $conversionRate * 10); // Normalizar
    }

    private function identifyTopFactor(array $factors): string {
        $weighted = [];
        foreach ($factors as $key => $value) {
            if (isset($this->decisionFactors[$key])) {
                $weighted[$key] = $value * $this->decisionFactors[$key]['weight'];
            }
        }
        return array_keys($weighted, max($weighted))[0] ?? 'unknown';
    }

    private function calculateFactorStrength(array $factors): float {
        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($factors as $key => $value) {
            if (isset($this->decisionFactors[$key])) {
                $weight = $this->decisionFactors[$key]['weight'];
                $totalWeight += $weight;
                $weightedSum += $value * $weight;
            }
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0.5;
    }

    private function simulatePricingPrediction(array $factors): float {
        try {
            $prompt = $this->buildPricingPredictionPrompt($factors);

            $response = $this->llm->generate($prompt,
                "You are a machine learning pricing prediction model. Analyze the factors and predict the optimal price adjustment percentage.
                Return a single float value between -0.2 (20% price decrease) and 0.2 (20% price increase).
                Consider: price competitiveness, demand level, inventory pressure, and seasonal factors.
                Return only the number, no explanation."
            );

            if ($response['success']) {
                $prediction = (float) trim($response['content']);
                // Ensure prediction is within reasonable bounds
                return max(-0.2, min(0.2, $prediction));
            }
        } catch (Exception $e) {
            $this->logger->log('error', 'AI_PRICING_PREDICTION_FAILED', [
                'factors' => $factors,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback to basic calculation
        $prediction = 0;
        $prediction += ($factors['price_competitiveness'] - 0.5) * 0.15;
        $prediction += ($factors['demand_level'] - 0.5) * 0.1;
        $prediction -= ($factors['inventory_pressure'] - 0.5) * 0.08;
        $prediction += ($factors['seasonal_multiplier'] - 1.0) * 0.05;

        return max(-0.2, min(0.2, $prediction));
    }

    private function generatePricingReasoning(float $currentPrice, float $newPrice, float $confidence, float $changePercent): string {
        $direction = $newPrice > $currentPrice ? 'aumento' : 'redução';
        $confidence_text = $confidence > 0.8 ? 'alta confiança' : ($confidence > 0.6 ? 'média confiança' : 'baixa confiança');

        return "Recomendação de {$direction} de " . abs(round($changePercent, 1)) . "% no preço com {$confidence_text} (R$ " . number_format($currentPrice, 2, ',', '.') . " → R$ " . number_format($newPrice, 2, ',', '.') . ")";
    }

    private function getSeasonName(int $month): string {
        $seasons = [
            1 => 'Pós-Natal', 2 => 'Carnaval', 3 => 'Outono', 4 => 'Páscoa',
            5 => 'Dia das Mães', 6 => 'Festa Junina', 7 => 'Férias', 8 => 'Dia dos Pais',
            9 => 'Primavera', 10 => 'Dia das Crianças', 11 => 'Black Friday', 12 => 'Natal'
        ];
        return $seasons[$month] ?? 'Regular';
    }

    private function getModelStats(): array {
        return [
            'engines_loaded' => count($this->models),
            'engines' => array_map(fn($m) => $m['type'] ?? 'unknown', $this->models),
            'status' => 'operational',
            'note' => 'Pricing usa LLM; inventory/campaign usam heurísticas'
        ];
    }

    private function collectInventoryData(string $itemId, array $context): array {
        $stmt = $this->db->prepare("
            SELECT ml_item_id, available_quantity, sold_quantity, category_id, price
            FROM items
            WHERE ml_item_id = ?
        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception("Item não encontrado: {$itemId}");
        }

        $salesHistory = $this->getSalesHistory($itemId);
        $seasonalData = $this->getSeasonalData($item['category_id']);
        $marketData = $this->getMarketData($itemId);

        return array_merge($item, [
            'current_stock' => (int)$item['available_quantity'],
            'sales_history' => $salesHistory,
            'seasonal_data' => $seasonalData,
            'market_data' => $marketData
        ], $context);
    }

    private function analyzeInventoryFactors(array $data): array {
        $price = (float)($data['price'] ?? 0);
        $marketData = $data['market_data'] ?? [];
        $salesHistory = $data['sales_history'] ?? [];
        $seasonalData = $data['seasonal_data'] ?? [];
        $available = (int)($data['available_quantity'] ?? 0);
        $sold = (int)($data['sold_quantity'] ?? 0);

        $factors = [];
        $factors['price_competitiveness'] = $this->calculatePriceCompetitiveness($price, $marketData);
        $factors['demand_level'] = $this->calculateDemandLevel($salesHistory, $seasonalData);
        $factors['inventory_pressure'] = $this->calculateInventoryPressure($available, $sold);
        $factors['seasonal_multiplier'] = $seasonalData['current_multiplier'] ?? 1.0;
        $factors['performance_score'] = $this->calculatePerformanceScore($salesHistory);
        $factors['top_factor'] = $this->identifyTopFactor($factors);
        $factors['factor_strength'] = $this->calculateFactorStrength($factors);

        return $factors;
    }

    private function determineInventoryAction(array $factors, array $prediction): string {
        $demand = $factors['demand_level'] ?? 0.5;
        $pressure = $factors['inventory_pressure'] ?? 0.5;
        if ($demand >= 0.6 && $pressure <= 0.4) {
            return 'replenish';
        }
        if ($demand <= 0.3 && $pressure >= 0.7) {
            return 'reduce';
        }
        return 'maintain';
    }

    private function calculateOptimalQuantity(array $data, array $prediction): int {
        $currentStock = (int)($data['current_stock'] ?? 0);
        $avgDailySales = (float)($data['sales_history']['avg_daily_sales'] ?? 0);
        $targetDays = 30;
        $desiredStock = (int)round($avgDailySales * $targetDays);
        return max(0, $desiredStock - $currentStock);
    }

    private function calculateUrgency(array $factors): string {
        $demand = $factors['demand_level'] ?? 0.5;
        $pressure = $factors['inventory_pressure'] ?? 0.5;
        if ($demand >= 0.7 && $pressure <= 0.3) {
            return 'high';
        }
        if ($demand <= 0.3 && $pressure >= 0.7) {
            return 'low';
        }
        return 'medium';
    }

    private function generateInventoryReasoning(array $factors, array $prediction): string {
        $demand = round(($factors['demand_level'] ?? 0) * 100);
        $pressure = round(($factors['inventory_pressure'] ?? 0) * 100);
        $seasonal = $factors['seasonal_multiplier'] ?? 1.0;
        return "Demanda estimada {$demand}%, pressão de estoque {$pressure}%, fator sazonal {$seasonal}.";
    }

    private function collectCampaignData(string $campaignId, array $context): array {
        if (empty($context)) {
            throw new Exception('Contexto da campanha é obrigatório');
        }

        return [
            'campaign_id' => $campaignId,
            'current_budget' => (float)($context['current_budget'] ?? 0),
            'current_bid' => (float)($context['current_bid'] ?? 0),
            'spend_30d' => (float)($context['spend_30d'] ?? 0),
            'revenue_30d' => (float)($context['revenue_30d'] ?? 0),
            'clicks_30d' => (int)($context['clicks_30d'] ?? 0),
            'impressions_30d' => (int)($context['impressions_30d'] ?? 0),
            'conversions_30d' => (int)($context['conversions_30d'] ?? 0),
            'target_roas' => (float)($context['target_roas'] ?? 0),
            'competition_level' => (float)($context['competition_level'] ?? 0)
        ];
    }

    private function analyzeCampaignFactors(array $data): array {
        $spend = $data['spend_30d'] ?? 0;
        $revenue = $data['revenue_30d'] ?? 0;
        $clicks = $data['clicks_30d'] ?? 0;
        $impressions = $data['impressions_30d'] ?? 0;
        $conversions = $data['conversions_30d'] ?? 0;
        $currentBid = $data['current_bid'] ?? 0;
        $currentBudget = $data['current_budget'] ?? 0;

        $roi = $spend > 0 ? $revenue / $spend : 0;
        $ctr = $impressions > 0 ? $clicks / $impressions : 0;
        $conversionRate = $clicks > 0 ? $conversions / $clicks : 0;
        $budgetUtilization = $currentBudget > 0 ? $spend / $currentBudget : 0;
        $bidEfficiency = $currentBid > 0 ? $conversionRate / $currentBid : $conversionRate;

        $factors = [
            'roi_trend' => $roi,
            'conversion_rate' => $conversionRate,
            'bid_efficiency' => $bidEfficiency,
            'budget_utilization' => $budgetUtilization,
            'competition_level' => $data['competition_level'] ?? 0
        ];

        $factors['top_factor'] = $this->identifyTopFactor($factors);
        $factors['factor_strength'] = $this->calculateFactorStrength($factors);

        return $factors;
    }

    private function determineCampaignAction(array $factors, array $prediction): string {
        $roi = $factors['roi_trend'] ?? 0;
        $budgetUtilization = $factors['budget_utilization'] ?? 0;
        if ($roi >= 1.2 && $budgetUtilization >= 0.8) {
            return 'increase';
        }
        if ($roi <= 0.8 && $budgetUtilization >= 0.5) {
            return 'decrease';
        }
        return 'maintain';
    }

    private function calculateBudgetAdjustment(array $data, array $prediction): float {
        $spend = $data['spend_30d'] ?? 0;
        $revenue = $data['revenue_30d'] ?? 0;
        $target = $data['target_roas'] ?? 0;
        $roi = $spend > 0 ? $revenue / $spend : 0;
        if ($target > 0 && $roi >= $target * 1.2) {
            return 0.15;
        }
        if ($target > 0 && $roi <= $target * 0.8) {
            return -0.15;
        }
        return 0.0;
    }

    private function calculateBidAdjustment(array $data, array $prediction): float {
        $clicks = $data['clicks_30d'] ?? 0;
        $impressions = $data['impressions_30d'] ?? 0;
        $conversions = $data['conversions_30d'] ?? 0;
        $ctr = $impressions > 0 ? $clicks / $impressions : 0;
        $conversionRate = $clicks > 0 ? $conversions / $clicks : 0;

        if ($conversionRate >= 0.03 && $ctr < 0.02) {
            return 0.1;
        }
        if ($conversionRate < 0.01 && $ctr >= 0.03) {
            return -0.1;
        }
        return 0.0;
    }

    private function generateCampaignReasoning(array $factors, array $prediction): string {
        $roi = round($factors['roi_trend'] ?? 0, 2);
        $conversion = round(($factors['conversion_rate'] ?? 0) * 100, 2);
        $budgetUtil = round(($factors['budget_utilization'] ?? 0) * 100, 1);
        return "ROAS {$roi}, conversão {$conversion}%, uso do orçamento {$budgetUtil}%.";
    }

    private function calculateInventoryScore(array $factors): float {
        $demand = $factors['demand_level'] ?? 0.5;
        $pressure = $factors['inventory_pressure'] ?? 0.5;
        $seasonal = $factors['seasonal_multiplier'] ?? 1.0;
        $prediction = ($demand - $pressure) + (($seasonal - 1.0) * 0.5);
        return max(-1, min(1, $prediction));
    }

    private function calculateCampaignScore(array $factors): float {
        $roi = $factors['roi_trend'] ?? 0;
        $conversion = $factors['conversion_rate'] ?? 0;
        $competition = $factors['competition_level'] ?? 0;
        $prediction = ($roi - 1) + ($conversion * 5) - ($competition * 0.5);
        return max(-1, min(1, $prediction));
    }
    private function applyPricingDecision(string $itemId, array $decision): array
    {
        // Update real item price
        $stmt = $this->db->prepare("UPDATE items SET price = :price, updated_at = NOW() WHERE ml_item_id = :item_id");
        $success = $stmt->execute([
            'price' => $decision['price'],
            'item_id' => $itemId
        ]);

        return [
            'applied' => $success,
            'new_price' => $decision['price'],
            'message' => $success ? 'Price updated successfully' : 'Failed to update price'
        ];
    }
    private function applyInventoryDecision(string $itemId, array $decision): array {
        $quantity = $decision['quantity_recommended'] ?? null;
        if ($quantity === null) {
            return ['applied' => false, 'message' => 'Quantidade recomendada ausente'];
        }
        $stmt = $this->db->prepare("UPDATE items SET available_quantity = :qty, updated_at = NOW() WHERE ml_item_id = :item_id");
        $success = $stmt->execute([
            'qty' => (int)$quantity,
            'item_id' => $itemId
        ]);
        return [
            'applied' => $success,
            'updated_quantity' => (int)$quantity,
            'message' => $success ? 'Estoque atualizado' : 'Falha ao atualizar estoque'
        ];
    }

    private function applyCampaignDecision(string $campaignId, array $decision): array {
        $budgetAdjustment = $decision['budget_adjustment'] ?? 0;
        $bidAdjustment = $decision['bid_adjustment'] ?? 0;
        $hasAdsCampaigns = $this->db->query("SHOW TABLES LIKE 'ads_campaigns'")->fetchColumn();
        if ($hasAdsCampaigns) {
            $stmt = $this->db->prepare("
                UPDATE ads_campaigns
                SET daily_budget = daily_budget * (1 + :budget),
                    bid = bid * (1 + :bid)
                WHERE campaign_id = :campaign_id
            ");
            $success = $stmt->execute([
                'budget' => (float)$budgetAdjustment,
                'bid' => (float)$bidAdjustment,
                'campaign_id' => $campaignId
            ]);
            return [
                'applied' => $success,
                'message' => $success ? 'Campanha atualizada' : 'Falha ao atualizar campanha'
            ];
        }

        return ['applied' => false, 'message' => 'Tabela de campanhas não encontrada'];
    }

    private function buildPricingPredictionPrompt(array $factors): string
    {
        return sprintf(
            "Predict optimal price adjustment percentage based on these factors:\n\n" .
            "Price Competitiveness: %.2f (0-1 scale, higher = more competitive)\n" .
            "Demand Level: %.2f (0-1 scale, higher = more demand)\n" .
            "Inventory Pressure: %.2f (0-1 scale, higher = more inventory pressure)\n" .
            "Seasonal Multiplier: %.2f (1.0 = normal, >1.0 = high season)\n\n" .
            "Return a single float between -0.2 and 0.2 representing the recommended price change percentage.",
            $factors['price_competitiveness'] ?? 0.5,
            $factors['demand_level'] ?? 0.5,
            $factors['inventory_pressure'] ?? 0.5,
            $factors['seasonal_multiplier'] ?? 1.0
        );
    }
}
