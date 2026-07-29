<?php

declare(strict_types=1);

namespace App\Services\AI\ML;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;

/**
 * Data Collection & Pattern Analysis Engine
 * Coleta dados de otimizações e analisa padrões via contagem de frequência e médias
 *
 * Features:
 * - Training data collection
 * - Success pattern analysis
 * - Automatic prompt adjustment
 * - Personalized scoring model
 * - Feedback loop
 *
 * @author AI Development Team
 * @version 1.0.0
 */
class LearningEngine
{
    private PDO $db;
    private ?int $accountId;
    private ?MercadoLivreClient $mlClient = null;

    // Learning weights
    private const WEIGHTS = [
        'title_conversion' => 0.35,
        'description_engagement' => 0.25,
        'attribute_completeness' => 0.20,
        'image_quality' => 0.15,
        'price_competitiveness' => 0.05
    ];

    public function __construct(?int $accountId = null)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->mlClient = $accountId ? new MercadoLivreClient($accountId) : null;
        $this->ensureTablesExist();
    }

    /**
     * Ensure ML tables exist
     */
    private function ensureTablesExist(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ai_training_data (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NULL,
                    item_id VARCHAR(50) NOT NULL,
                    category_id VARCHAR(50) NULL,
                    data_type ENUM('title', 'description', 'attributes', 'image') NOT NULL,
                    original_content TEXT NOT NULL,
                    optimized_content TEXT NOT NULL,
                    score_before INT NULL,
                    score_after INT NULL,
                    conversion_before DECIMAL(5,4) DEFAULT 0,
                    conversion_after DECIMAL(5,4) DEFAULT 0,
                    is_successful TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_account (account_id),
                    INDEX idx_category (category_id),
                    INDEX idx_type (data_type),
                    INDEX idx_success (is_successful)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ai_success_patterns (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NULL,
                    category_id VARCHAR(50) NULL,
                    pattern_type VARCHAR(50) NOT NULL,
                    pattern_data JSON NOT NULL,
                    success_score DECIMAL(5,2) DEFAULT 0,
                    sample_size INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_category_type (category_id, pattern_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ai_prompt_adjustments (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NULL,
                    category_id VARCHAR(50) NULL,
                    prompt_type VARCHAR(50) NOT NULL,
                    adjustment_key VARCHAR(100) NOT NULL,
                    adjustment_value TEXT NOT NULL,
                    performance_score DECIMAL(5,2) DEFAULT 0,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_adjustment (account_id, category_id, prompt_type, adjustment_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ai_feedback (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NULL,
                    item_id VARCHAR(50) NOT NULL,
                    optimization_id INT NULL,
                    feedback_type ENUM('positive', 'negative', 'edit') NOT NULL,
                    feedback_data JSON NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_item (item_id),
                    INDEX idx_type (feedback_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            log_error('Falha ao criar tabelas do LearningEngine', [
                'service' => 'LearningEngine',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Collect training data from optimization results
     *
     * @param string $itemId
     * @param string $type
     * @param string $original
     * @param string $optimized
     * @param array $metrics
     * @return bool
     */
    public function collectTrainingData(string $itemId, string $type, string $original, string $optimized, array $metrics = []): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ai_training_data
                (account_id, item_id, category_id, data_type, original_content, optimized_content,
                 score_before, score_after, conversion_before, conversion_after, is_successful)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $isSuccessful = ($metrics['score_after'] ?? 0) > ($metrics['score_before'] ?? 0) ||
                ($metrics['conversion_after'] ?? 0) > ($metrics['conversion_before'] ?? 0);

            return $stmt->execute([
                $this->accountId,
                $itemId,
                $metrics['category_id'] ?? null,
                $type,
                $original,
                $optimized,
                $metrics['score_before'] ?? null,
                $metrics['score_after'] ?? null,
                $metrics['conversion_before'] ?? 0,
                $metrics['conversion_after'] ?? 0,
                $isSuccessful ? 1 : 0
            ]);
        } catch (\Exception $e) {
            log_warning('Falha ao coletar dados de treinamento', [
                'service' => 'LearningEngine',
                'item_id' => $itemId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Analyze success patterns from collected data
     *
     * @param string|null $categoryId
     * @return array
     */
    public function analyzeSuccessPatterns(?string $categoryId = null): array
    {
        $patterns = [];

        // Analyze titles
        $patterns['title'] = $this->analyzeTitlePatterns($categoryId);

        // Analyze descriptions
        $patterns['description'] = $this->analyzeDescriptionPatterns($categoryId);

        // Analyze attributes
        $patterns['attributes'] = $this->analyzeAttributePatterns($categoryId);

        // Save patterns
        foreach ($patterns as $type => $data) {
            $this->savePattern($categoryId, $type, $data);
        }

        return $patterns;
    }

    /**
     * Analyze successful title patterns
     */
    private function analyzeTitlePatterns(?string $categoryId): array
    {
        try {
            $sql = "
                SELECT
                    optimized_content,
                    conversion_after,
                    score_after
                FROM ai_training_data
                WHERE data_type = 'title'
                AND is_successful = 1
                " . ($categoryId ? "AND category_id = ?" : "") . "
                ORDER BY conversion_after DESC
                LIMIT 100
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($categoryId ? [$categoryId] : []);
            $successfulTitles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($successfulTitles)) {
                return ['sample_size' => 0, 'patterns' => []];
            }

            // Extract patterns
            $patterns = [
                'avg_length' => 0,
                'common_words' => [],
                'avg_word_count' => 0,
                'uses_numbers' => 0,
                'uses_brand' => 0,
                'capitalization_style' => []
            ];

            $wordFrequency = [];

            foreach ($successfulTitles as $title) {
                $content = $title['optimized_content'];
                $patterns['avg_length'] += mb_strlen($content);
                $patterns['avg_word_count'] += str_word_count($content);

                if (preg_match('/\d+/', $content)) {
                    $patterns['uses_numbers']++;
                }

                // Count word frequency
                $words = preg_split('/\s+/', mb_strtolower($content));
                foreach ($words as $word) {
                    if (mb_strlen($word) > 3) {
                        $wordFrequency[$word] = ($wordFrequency[$word] ?? 0) + 1;
                    }
                }
            }

            $count = count($successfulTitles);
            $patterns['avg_length'] = round($patterns['avg_length'] / $count);
            $patterns['avg_word_count'] = round($patterns['avg_word_count'] / $count);
            $patterns['uses_numbers_pct'] = round(($patterns['uses_numbers'] / $count) * 100, 1);

            // Get top words
            arsort($wordFrequency);
            $patterns['common_words'] = array_slice($wordFrequency, 0, 20, true);

            return [
                'sample_size' => $count,
                'patterns' => $patterns,
                'success_score' => 100
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Analyze successful description patterns
     */
    private function analyzeDescriptionPatterns(?string $categoryId): array
    {
        try {
            $sql = "
                SELECT
                    optimized_content,
                    conversion_after
                FROM ai_training_data
                WHERE data_type = 'description'
                AND is_successful = 1
                " . ($categoryId ? "AND category_id = ?" : "") . "
                ORDER BY conversion_after DESC
                LIMIT 50
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($categoryId ? [$categoryId] : []);
            $descriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($descriptions)) {
                return ['sample_size' => 0, 'patterns' => []];
            }

            $patterns = [
                'avg_length' => 0,
                'has_bullets' => 0,
                'has_emojis' => 0,
                'has_sections' => 0
            ];

            foreach ($descriptions as $desc) {
                $content = $desc['optimized_content'];
                $patterns['avg_length'] += mb_strlen($content);

                if (preg_match('/[•\-\*]/', $content)) {
                    $patterns['has_bullets']++;
                }

                if (preg_match('/[\x{1F300}-\x{1F9FF}]/u', $content)) {
                    $patterns['has_emojis']++;
                }

                if (stripos($content, '##') !== false || stripos($content, '**') !== false) {
                    $patterns['has_sections']++;
                }
            }

            $count = count($descriptions);
            $patterns['avg_length'] = round($patterns['avg_length'] / $count);
            $patterns['bullets_pct'] = round(($patterns['has_bullets'] / $count) * 100, 1);
            $patterns['emojis_pct'] = round(($patterns['has_emojis'] / $count) * 100, 1);

            return [
                'sample_size' => $count,
                'patterns' => $patterns
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Analyze attribute patterns using ML API + local sales data
     */
    private function analyzeAttributePatterns(?string $categoryId): array
    {
        if (!$categoryId) {
            try {
                $stmt = $this->db->prepare("\n                    SELECT category_id, COUNT(*) as total\n                    FROM items\n                    WHERE account_id = :account_id\n                      AND status = 'active'\n                      AND category_id IS NOT NULL\n                      AND category_id <> ''\n                    GROUP BY category_id\n                    ORDER BY total DESC\n                    LIMIT 1\n                ");
                $stmt->execute(['account_id' => $this->accountId]);
                $categoryId = $stmt->fetchColumn() ?: null;
            } catch (\Exception $e) {
                $categoryId = null;
            }

            if (!$categoryId) {
                return [
                    'sample_size' => 0,
                    'patterns' => ['most_impactful' => [], 'often_missing' => []],
                ];
            }
        }

        try {
            // 1. Obter atributos obrigatórios da categoria via ML API
            $requiredAttrs = [];
            $allAttrs = [];
            if ($this->mlClient) {
                $categoryAttrs = $this->mlClient->getCategoryAttributes($categoryId);
                foreach ($categoryAttrs as $attr) {
                    $attrId = $attr['id'] ?? '';
                    $attrName = $attr['name'] ?? $attrId;
                    $tags = $attr['tags'] ?? [];
                    $isRequired = is_array($tags) && (in_array('required', $tags) || isset($tags['required']));
                    $allAttrs[$attrId] = $attrName;
                    if ($isRequired) {
                        $requiredAttrs[$attrId] = $attrName;
                    }
                }
            }

            // 2. Analisar quais atributos os top sellers preenchem
            $topSellerAttrs = [];
            if ($this->mlClient) {
                try {
                    $searchResults = $this->mlClient->searchItems([
                        'category' => $categoryId,
                        'sort' => 'sold_quantity_desc',
                        'limit' => 10,
                    ]);

                    $filledCounts = [];
                    $itemCount = 0;
                    foreach ($searchResults['results'] ?? [] as $item) {
                        $itemCount++;
                        foreach ($item['attributes'] ?? [] as $attr) {
                            $attrId = $attr['id'] ?? '';
                            if (!empty($attr['value_name'] ?? $attr['value_id'] ?? null)) {
                                $filledCounts[$attrId] = ($filledCounts[$attrId] ?? 0) + 1;
                            }
                        }
                    }

                    // Atributos preenchidos por >70% dos top sellers são impactantes
                    if ($itemCount > 0) {
                        foreach ($filledCounts as $attrId => $count) {
                            $pct = ($count / $itemCount) * 100;
                            if ($pct >= 70) {
                                $topSellerAttrs[$attrId] = [
                                    'id' => $attrId,
                                    'name' => $allAttrs[$attrId] ?? $attrId,
                                    'filled_pct' => round($pct, 1),
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Continuar sem dados de top sellers
                }
            }

            // 3. Verificar quais atributos nossos itens estão perdendo
            $missingAttrs = [];
            try {
                $stmt = $this->db->prepare("
                    SELECT i.ml_item_id FROM items i
                    WHERE i.account_id = :account_id
                    AND i.category_id = :category_id
                    AND i.status = 'active'
                    LIMIT 20
                ");
                $stmt->execute([
                    'account_id' => $this->accountId,
                    'category_id' => $categoryId,
                ]);
                $myItems = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($myItems) && $this->mlClient) {
                    $myMissing = [];
                    foreach (array_slice($myItems, 0, 5) as $itemId) {
                        try {
                            $itemData = $this->mlClient->get("/items/{$itemId}", [], 600, true);
                            $filledIds = [];
                            foreach ($itemData['attributes'] ?? [] as $attr) {
                                if (!empty($attr['value_name'] ?? $attr['value_id'] ?? null)) {
                                    $filledIds[] = $attr['id'] ?? '';
                                }
                            }
                            // Atributos obrigatórios não preenchidos
                            foreach ($requiredAttrs as $reqId => $reqName) {
                                if (!in_array($reqId, $filledIds, true)) {
                                    $myMissing[$reqId] = ($myMissing[$reqId] ?? 0) + 1;
                                }
                            }
                            // Atributos dos top sellers não preenchidos
                            foreach ($topSellerAttrs as $tsId => $tsData) {
                                if (!in_array($tsId, $filledIds, true)) {
                                    $myMissing[$tsId] = ($myMissing[$tsId] ?? 0) + 1;
                                }
                            }
                        } catch (\Exception $e) {
                            // Pular item
                        }
                    }

                    arsort($myMissing);
                    foreach (array_slice($myMissing, 0, 10, true) as $attrId => $missCount) {
                        $missingAttrs[] = [
                            'id' => $attrId,
                            'name' => $allAttrs[$attrId] ?? $attrId,
                            'missing_in_items' => $missCount,
                            'is_required' => isset($requiredAttrs[$attrId]),
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Continuar sem dados locais
            }

            $sampleSize = count($allAttrs);

            return [
                'sample_size' => $sampleSize,
                'patterns' => [
                    'most_impactful' => array_values(array_slice($topSellerAttrs, 0, 10)),
                    'often_missing' => $missingAttrs,
                    'total_category_attrs' => count($allAttrs),
                    'required_attrs' => count($requiredAttrs),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'sample_size' => 0,
                'patterns' => ['most_impactful' => [], 'often_missing' => []],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Save pattern to database
     */
    private function savePattern(?string $categoryId, string $type, array $data): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ai_success_patterns
                (account_id, category_id, pattern_type, pattern_data, success_score, sample_size)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    pattern_data = VALUES(pattern_data),
                    success_score = VALUES(success_score),
                    sample_size = VALUES(sample_size),
                    updated_at = NOW()
            ");

            return $stmt->execute([
                $this->accountId,
                $categoryId,
                $type,
                json_encode($data),
                $data['success_score'] ?? 0,
                $data['sample_size'] ?? 0
            ]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get prompt adjustments based on learned patterns
     *
     * @param string $promptType
     * @param string|null $categoryId
     * @return array
     */
    public function getPromptAdjustments(string $promptType, ?string $categoryId = null): array
    {
        try {
            $sql = "
                SELECT adjustment_key, adjustment_value
                FROM ai_prompt_adjustments
                WHERE prompt_type = ?
                AND is_active = 1
                AND (category_id = ? OR category_id IS NULL)
                ORDER BY performance_score DESC
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$promptType, $categoryId]);

            $adjustments = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $adjustments[$row['adjustment_key']] = $row['adjustment_value'];
            }

            return $adjustments;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Record user feedback
     *
     * @param string $itemId
     * @param string $type
     * @param array $data
     * @param int|null $optimizationId
     * @return bool
     */
    public function recordFeedback(string $itemId, string $type, array $data = [], ?int $optimizationId = null): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ai_feedback
                (account_id, item_id, optimization_id, feedback_type, feedback_data)
                VALUES (?, ?, ?, ?, ?)
            ");

            return $stmt->execute([
                $this->accountId,
                $itemId,
                $optimizationId,
                $type,
                json_encode($data)
            ]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get personalized scoring weights based on account data
     *
     * @return array
     */
    public function getPersonalizedWeights(): array
    {
        // Start with default weights
        $weights = self::WEIGHTS;

        try {
            // Get success distribution by type
            $stmt = $this->db->prepare("
                SELECT
                    data_type,
                    AVG(CASE WHEN is_successful = 1 THEN conversion_after - conversion_before ELSE 0 END) as avg_improvement
                FROM ai_training_data
                WHERE account_id = ?
                GROUP BY data_type
            ");

            $stmt->execute([$this->accountId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Adjust weights based on what works for this account
            foreach ($results as $row) {
                if ($row['avg_improvement'] > 0) {
                    $key = $row['data_type'] . '_conversion';
                    if (isset($weights[$key])) {
                        $weights[$key] *= (1 + ($row['avg_improvement'] * 10));
                    }
                }
            }

            // Normalize weights to sum to 1
            $total = array_sum($weights);
            foreach ($weights as $key => $value) {
                $weights[$key] = round($value / $total, 4);
            }
        } catch (\Exception $e) {
            // Return defaults on error
        }

        return $weights;
    }

    /**
     * Get learning statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as total_samples,
                    SUM(is_successful) as successful_samples,
                    COUNT(DISTINCT category_id) as categories_learned
                FROM ai_training_data
                WHERE account_id = ? OR account_id IS NULL
            ");

            $stmt->execute([$this->accountId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            $total = intval($data['total_samples'] ?? 0);
            $successful = intval($data['successful_samples'] ?? 0);

            return [
                'total_samples' => $total,
                'successful_samples' => $successful,
                'success_rate' => $total > 0 ? round(($successful / $total) * 100, 1) : 0,
                'categories_learned' => intval($data['categories_learned'] ?? 0),
                'learning_maturity' => $this->calculateMaturity($total)
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Calculate learning maturity level
     */
    private function calculateMaturity(int $sampleCount): string
    {
        if ($sampleCount >= 1000) return 'expert';
        if ($sampleCount >= 500) return 'advanced';
        if ($sampleCount >= 100) return 'intermediate';
        if ($sampleCount >= 10) return 'learning';
        return 'novice';
    }
}
