<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use PDO;

/**
 * 🤖 Intelligent Auto-Optimizer
 * 
 * Sistema que otimiza automaticamente anúncios
 * usando heurísticas de scoring, A/B testing e feedback loop contínuo.
 * Delega para AdvancedSEOMaximizer e SEOPerformancePredictor (ambos heurísticos).
 */
class IntelligentAutoOptimizer
{
    private int $accountId;
    private PDO $db;
    private AdvancedSEOMaximizer $maximizer;
    private SEOPerformancePredictor $predictor;
    
    // Configurações de otimização
    private const OPTIMIZATION_CONFIG = [
        'auto_apply_threshold' => 85,    // Aplicar automaticamente se score ≥ 85%
        'min_improvement' => 10,          // Melhoria mínima para aplicar
        'max_daily_optimizations' => 50,  // Limite de otimizações por dia
        'test_duration_days' => 7,        // Duração dos testes A/B
        'success_rate_threshold' => 75,   // Taxa de sucesso mínima
    ];
    
    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = Database::getInstance();
        $this->maximizer = new AdvancedSEOMaximizer($accountId);
        $this->predictor = new SEOPerformancePredictor($accountId);
    }
    
    /**
     * 🚀 Otimização automática completa
     */
    public function intelligentAutoOptimize(array $options = []): array
    {
        $results = [
            'started_at' => date('Y-m-d H:i:s'),
            'processed_items' => 0,
            'optimized_items' => 0,
            'created_tests' => 0,
            'applied_optimizations' => 0,
            'errors' => [],
            'summary' => [],
        ];
        
        try {
            // Verificar limites diários
            if ($this->reachedDailyLimit()) {
                $results['errors'][] = 'Limite diário de otimizações atingido';
                return $results;
            }
            
            // Obter itens para otimizar
            $itemsToOptimize = $this->getItemsForOptimization($options);
            $results['total_items'] = count($itemsToOptimize);
            
            foreach ($itemsToOptimize as $item) {
                try {
                    $optimizationResult = $this->optimizeSingleItem($item, $options);
                    
                    $results['processed_items']++;
                    
                    if ($optimizationResult['optimized']) {
                        $results['optimized_items']++;
                        
                        if ($optimizationResult['auto_applied']) {
                            $results['applied_optimizations']++;
                        }
                        
                        if ($optimizationResult['test_created']) {
                            $results['created_tests']++;
                        }
                    }
                    
                    // Pausa entre otimizações para evitar rate limiting
                    usleep(200000); // 0.2 segundos
                    
                } catch (\Exception $e) {
                    $results['errors'][] = "Item {$item['id']}: " . $e->getMessage();
                }
            }
            
            // Gerar resumo
            $results['summary'] = $this->generateOptimizationSummary($results);
            
            // Salvar estatísticas da sessão
            $this->saveOptimizationSession($results);
            
        } catch (\Exception $e) {
            $results['errors'][] = "Erro geral: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * 📊 Otimização inteligente de um item específico
     */
    public function optimizeSingleItem(array $item, array $options = []): array
    {
        $result = [
            'item_id' => $item['id'],
            'optimized' => false,
            'auto_applied' => false,
            'test_created' => false,
            'optimizations' => [],
            'predictions' => [],
            'decisions' => [],
        ];
        
        // Passo 1: Maximização SEO
        $seoOptimization = $this->maximizer->maximizeItemSEO($item['id'], $item);
        $result['optimizations'] = $seoOptimization;
        
        // Passo 2: Predição de performance
        $currentPrediction = $this->predictor->predictPerformance($item);
        $result['predictions']['current'] = $currentPrediction;
        
        // AdvancedSEOMaximizer::calculateSEOScore() retorna um array de scores
        // (overall/title/description/attributes/images/price), não um número.
        // Usamos 'overall' como score agregado 0-100 para as comparações abaixo.
        $scoreAfterOverall = $seoOptimization['score_after']['overall'] ?? 0;
        $scoreBeforeOverall = $seoOptimization['score_before']['overall'] ?? 0;

        if ($scoreAfterOverall > $scoreBeforeOverall) {
            // Simular item otimizado
            $optimizedItem = $this->simulateOptimizedItem($item, $seoOptimization);
            $optimizedPrediction = $this->predictor->predictPerformance($optimizedItem);
            $result['predictions']['optimized'] = $optimizedPrediction;
            
            // Passo 3: Análise de decisão
            $decision = $this->makeOptimizationDecision(
                $seoOptimization, 
                $currentPrediction, 
                $optimizedPrediction, 
                $options
            );
            $result['decisions'] = $decision;
            
            if ($decision['should_optimize']) {
                $result['optimized'] = true;
                
                // Passo 4: Executar ação
                if ($decision['auto_apply']) {
                    $applyResult = $this->autoApplyOptimization($item['id'], $seoOptimization);
                    $result['auto_applied'] = $applyResult['success'];
                    $result['apply_result'] = $applyResult;
                } elseif ($decision['create_test']) {
                    $testResult = $this->createABTest($item['id'], $seoOptimization);
                    $result['test_created'] = $testResult['success'];
                    $result['test_result'] = $testResult;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * 🧮 Tomada de decisão inteligente
     */
    private function makeOptimizationDecision(
        array $seoOptimization, 
        array $currentPrediction, 
        array $optimizedPrediction, 
        array $options
    ): array {
        
        $decision = [
            'should_optimize' => false,
            'auto_apply' => false,
            'create_test' => false,
            'reasoning' => [],
            'risk_level' => 'low',
            'expected_roi' => 0,
        ];
        
        // Calcular melhoria esperada (score_after/score_before são arrays de
        // AdvancedSEOMaximizer::calculateSEOScore(); usamos o agregado 'overall').
        $scoreImprovement = ($seoOptimization['score_after']['overall'] ?? 0)
            - ($seoOptimization['score_before']['overall'] ?? 0);
        $salesImprovement = $optimizedPrediction['predicted_sales'] - $currentPrediction['predicted_sales'];
        $viewsImprovement = $optimizedPrediction['predicted_views'] - $currentPrediction['predicted_views'];
        
        // Critério 1: Melhoria mínima
        if ($scoreImprovement < self::OPTIMIZATION_CONFIG['min_improvement']) {
            $decision['reasoning'][] = "Melhoria de score ({$scoreImprovement}) abaixo do mínimo (" . self::OPTIMIZATION_CONFIG['min_improvement'] . ")";
            return $decision;
        }
        
        // Critério 2: Confiança da predição
        if ($optimizedPrediction['confidence_level'] < 70) {
            $decision['reasoning'][] = "Baixa confiança na predição ({$optimizedPrediction['confidence_level']}%)";
            $decision['risk_level'] = 'medium';
        }
        
        // Critério 3: ROI esperado
        $itemPrice = $currentPrediction['item_data']['price'] ?? 0;
        $expectedRevenue = $salesImprovement * $itemPrice;
        $decision['expected_roi'] = $expectedRevenue;
        
        if ($expectedRevenue < 100) { // Mínimo de R$100 de ROI esperado
            $decision['reasoning'][] = "ROI esperado baixo (R$" . number_format($expectedRevenue, 2) . ")";
        }
        
        // Critério 4: Fatores de risco
        $riskFactors = count($optimizedPrediction['risk_factors'] ?? []);
        if ($riskFactors > 2) {
            $decision['risk_level'] = 'high';
            $decision['reasoning'][] = "Muitos fatores de risco identificados ({$riskFactors})";
        }
        
        // Decisão final
        $shouldOptimize = (
            $scoreImprovement >= self::OPTIMIZATION_CONFIG['min_improvement'] &&
            $optimizedPrediction['confidence_level'] >= 60 &&
            $riskFactors <= 2
        );
        
        if ($shouldOptimize) {
            $decision['should_optimize'] = true;
            
            // Auto-aplicar se baixo risco e alta confiança
            $decision['auto_apply'] = (
                $decision['risk_level'] === 'low' &&
                $optimizedPrediction['confidence_level'] >= self::OPTIMIZATION_CONFIG['auto_apply_threshold'] &&
                $scoreImprovement >= 15
            );
            
            // Criar teste A/B se médio risco ou confiança média
            $decision['create_test'] = (
                !$decision['auto_apply'] &&
                ($decision['risk_level'] === 'medium' || $optimizedPrediction['confidence_level'] >= 75)
            );
            
            if ($decision['auto_apply']) {
                $decision['reasoning'][] = "Aplicação automática aprovada: baixo risco e alta confiança";
            } elseif ($decision['create_test']) {
                $decision['reasoning'][] = "Teste A/B criado: médio risco ou confiança média";
            }
        }
        
        return $decision;
    }
    
    /**
     * 🔧 Aplicar otimização automaticamente
     */
    private function autoApplyOptimization(string $itemId, array $seoOptimization): array
    {
        $result = [
            'success' => false,
            'applied_changes' => [],
            'errors' => [],
        ];
        
        try {
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            
            // Aplicar mudanças no título
            if (isset($seoOptimization['optimizations']['title']['optimized_title'])) {
                $titleData = [
                    'title' => $seoOptimization['optimizations']['title']['optimized_title']
                ];
                
                $response = $client->put("/items/{$itemId}", $titleData);
                if (!isset($response['error'])) {
                    $result['applied_changes'][] = 'Título atualizado';
                } else {
                    $result['errors'][] = "Erro no título: " . $response['error'];
                }
            }
            
            // Aplicar mudanças na descrição
            if (isset($seoOptimization['optimizations']['description']['optimized_description'])) {
                $descData = [
                    'description' => $seoOptimization['optimizations']['description']['optimized_description']
                ];
                
                $response = $client->put("/items/{$itemId}", $descData);
                if (!isset($response['error'])) {
                    $result['applied_changes'][] = 'Descrição atualizada';
                } else {
                    $result['errors'][] = "Erro na descrição: " . $response['error'];
                }
            }
            
            // Aplicar mudanças nos atributos
            if (isset($seoOptimization['optimizations']['attributes']['optimized_attributes'])) {
                $attrs = $seoOptimization['optimizations']['attributes']['optimized_attributes'];
                
                foreach ($attrs as $attr) {
                    $attrData = [
                        'id' => $attr['id'],
                        'value_name' => $attr['value_name']
                    ];
                    
                    $response = $client->post("/items/{$itemId}/attributes", $attrData);
                    if (!isset($response['error'])) {
                        $result['applied_changes'][] = "Atributo {$attr['id']} atualizado";
                    }
                }
            }
            
            $result['success'] = empty($result['errors']) && !empty($result['applied_changes']);
            
            // Salvar log da aplicação
            $this->saveOptimizationApplication($itemId, $result);
            
        } catch (\Exception $e) {
            $result['errors'][] = "Exceção: " . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * 🧪 Criar teste A/B
     */
    private function createABTest(string $itemId, array $seoOptimization): array
    {
        $result = [
            'success' => false,
            'test_id' => null,
            'variants' => [],
            'errors' => [],
        ];
        
        try {
            // Criar variante A (atual) e B (otimizado)
            $variantA = [
                'type' => 'original',
                'title' => $seoOptimization['optimizations']['title']['current_title'] ?? '',
                'description' => $seoOptimization['optimizations']['description']['current_description'] ?? '',
            ];
            
            $variantB = [
                'type' => 'optimized',
                'title' => $seoOptimization['optimizations']['title']['optimized_title'] ?? '',
                'description' => $seoOptimization['optimizations']['description']['optimized_description'] ?? '',
            ];
            
            // Salvar teste no banco
            $stmt = $this->db->prepare("
                INSERT INTO seo_ab_tests 
                (account_id, item_id, variant_a_data, variant_b_data, status, 
                 created_at, start_date, expected_end_date)
                VALUES (?, ?, ?, ?, 'created', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))
            ");
            
            $stmt->execute([
                $this->accountId,
                $itemId,
                json_encode($variantA),
                json_encode($variantB),
                self::OPTIMIZATION_CONFIG['test_duration_days']
            ]);
            
            $result['test_id'] = $this->db->lastInsertId();
            $result['variants'] = [$variantA, $variantB];
            $result['success'] = true;
            
        } catch (\Exception $e) {
            $result['errors'][] = "Erro ao criar teste: " . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * 📋 Obter itens para otimização
     */
    private function getItemsForOptimization(array $options): array
    {
        $limit = $options['limit'] ?? 20;
        $categoryId = $options['category_id'] ?? null;
        $scoreThreshold = $options['score_threshold'] ?? 70;

        $limitSql = max(1, min(200, (int)$limit));
        
        $sql = "
            SELECT i.*, 
                   COALESCE(seo.score, 0) as current_seo_score,
                   COALESCE(sold_quantity, 0) as total_sales
            FROM items i
            LEFT JOIN seo_scores seo ON i.id = seo.item_id
            WHERE i.account_id = ?
              AND i.status = 'active'
              AND (seo.score IS NULL OR seo.score < ?)
        ";
        
        $params = [$this->accountId, $scoreThreshold];
        
        if ($categoryId) {
            $sql .= " AND i.category_id = ?";
            $params[] = $categoryId;
        }
        
        $sql .= " ORDER BY COALESCE(seo.score, 0) ASC, i.total_visits DESC LIMIT {$limitSql}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * ✅ Verificar limite diário
     */
    private function reachedDailyLimit(): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM optimization_sessions 
            WHERE account_id = ? 
              AND DATE(created_at) = CURDATE()
        ");
        
        $stmt->execute([$this->accountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] >= self::OPTIMIZATION_CONFIG['max_daily_optimizations'];
    }
    
    /**
     * 🎭 Simular item otimizado
     */
    private function simulateOptimizedItem(array $originalItem, array $seoOptimization): array
    {
        $optimized = $originalItem;
        
        // Aplicar otimizações simuladas
        if (isset($seoOptimization['optimizations']['title']['optimized_title'])) {
            $optimized['title'] = $seoOptimization['optimizations']['title']['optimized_title'];
        }
        
        if (isset($seoOptimization['optimizations']['description']['optimized_description'])) {
            $optimized['description'] = $seoOptimization['optimizations']['description']['optimized_description'];
        }
        
        if (isset($seoOptimization['optimizations']['attributes']['optimized_attributes'])) {
            $optimized['attributes'] = $seoOptimization['optimizations']['attributes']['optimized_attributes'];
        }
        
        return $optimized;
    }
    
    /**
     * 📊 Gerar resumo da otimização
     */
    private function generateOptimizationSummary(array $results): array
    {
        $successRate = $results['processed_items'] > 0 
            ? ($results['optimized_items'] / $results['processed_items']) * 100 
            : 0;
        
        return [
            'success_rate' => round($successRate, 2),
            'optimization_rate' => $successRate,
            'auto_apply_rate' => $results['optimized_items'] > 0 
                ? ($results['applied_optimizations'] / $results['optimized_items']) * 100 
                : 0,
            'test_creation_rate' => $results['optimized_items'] > 0 
                ? ($results['created_tests'] / $results['optimized_items']) * 100 
                : 0,
            'error_rate' => $results['processed_items'] > 0 
                ? (count($results['errors']) / $results['processed_items']) * 100 
                : 0,
            'performance' => $successRate >= self::OPTIMIZATION_CONFIG['success_rate_threshold'] 
                ? 'excellent' 
                : ($successRate >= 50 ? 'good' : 'needs_improvement'),
        ];
    }
    
    /**
     * 💾 Salvar sessão de otimização
     */
    private function saveOptimizationSession(array $results): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO optimization_sessions 
            (account_id, processed_items, optimized_items, applied_optimizations, 
             created_tests, errors_count, summary_data, session_data, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $this->accountId,
            $results['processed_items'],
            $results['optimized_items'],
            $results['applied_optimizations'],
            $results['created_tests'],
            count($results['errors']),
            json_encode($results['summary']),
            json_encode($results)
        ]);
    }
    
    /**
     * 💾 Salvar aplicação de otimização
     */
    private function saveOptimizationApplication(string $itemId, array $result): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO optimization_applications 
            (account_id, item_id, applied_changes, errors, success, applied_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $this->accountId,
            $itemId,
            json_encode($result['applied_changes']),
            json_encode($result['errors']),
            $result['success']
        ]);
    }
    
    /**
     * 📈 Obter estatísticas de otimizações
     */
    public function getOptimizationStats(array $filters = []): array
    {
        $whereClause = "WHERE os.account_id = ?";
        $params = [$this->accountId];
        
        if (!empty($filters['date_from'])) {
            $whereClause .= " AND os.created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $whereClause .= " AND os.created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql = "
            SELECT 
                COUNT(*) as total_sessions,
                SUM(processed_items) as total_processed,
                SUM(optimized_items) as total_optimized,
                SUM(applied_optimizations) as total_applied,
                SUM(created_tests) as total_tests,
                AVG(JSON_EXTRACT(summary_data, '$.success_rate')) as avg_success_rate,
                MAX(created_at) as last_optimization
            FROM optimization_sessions os
            {$whereClause}
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}