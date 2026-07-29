<?php

declare(strict_types=1);

namespace App\Services\SEO;

use App\Services\MercadoLivreClient;
use App\Services\SEO\AIClient;

/**
 * Serviço de análise de lacunas de keywords vs concorrentes
 * Identifica oportunidades visíveis e ocultas no marketplace
 */
class KeywordGapAnalyzerService
{
    private AIClient $ai;
    private MercadoLivreClient $mlClient;
    private ?string $accountId;

    public function __construct(?string $accountId = null)
    {
        $this->ai = new AIClient();
        $this->mlClient = new MercadoLivreClient();
        $this->accountId = $accountId;
    }

    /**
     * Análise completa de lacunas de keywords
     */
    public function analyzeKeywordGaps(string $productId, array $context = []): array
    {
        // 1. Obtém dados do produto atual
        $myProduct = $this->getProductData($productId);

        // 2. Busca concorrentes diretos
        $competitors = $this->getDirectCompetitors($myProduct, $context);

        // 3. Análise de keywords
        $myKeywords = $this->extractProductKeywords($myProduct);
        $competitorKeywords = $this->extractCompetitorKeywords($competitors);

        // 4. Identificação de lacunas
        $gapAnalysis = $this->performGapAnalysis($myKeywords, $competitorKeywords);

        // 5. Análise semântica com IA
        $semanticGaps = $this->analyzeSemanticGaps($myProduct, $competitors);

        // 6. Oportunidades de cauda longa
        $longTailOpportunities = $this->findLongTailOpportunities($myProduct, $competitors);

        $result = [
            'success' => true,
            'product_id' => $productId,
            'my_keywords' => $myKeywords,
            'competitor_analysis' => [
                'competitors_count' => count($competitors),
                'total_competitor_keywords' => count($competitorKeywords),
                'keyword_overlap' => count(array_intersect($myKeywords, $competitorKeywords))
            ],
            'gap_analysis' => $gapAnalysis,
            'semantic_gaps' => $semanticGaps,
            'long_tail_opportunities' => $longTailOpportunities,
            'actionable_insights' => $this->generateActionableInsights($gapAnalysis, $semanticGaps, $longTailOpportunities),
            'estimated_impact' => $this->estimateImpact($gapAnalysis)
        ];

        // Salvar análise no cache para monitoramento futuro
        try {
            $cache = new \App\Services\CacheService();
            $cache->set("keyword_gap_analysis:{$productId}", $result, 86400);
        } catch (\Exception $e) {
            // Cache falhou, continuar sem salvar
        }

        return $result;
    }

    /**
     * Análise competitiva de keywords
     */
    public function competitiveKeywordAnalysis(string $category, array $filters = []): array
    {
        // Busca top produtos da categoria
        $topProducts = $this->getTopProductsByCategory($category, $filters);

        // Extrai keywords de todos
        $allKeywords = [];
        $productKeywords = [];

        foreach ($topProducts as $product) {
            $keywords = $this->extractProductKeywords($product);
            $productKeywords[$product['id']] = $keywords;
            $allKeywords = array_merge($allKeywords, $keywords);
        }

        // Análise de frequência e importância
        $keywordFrequency = array_count_values($allKeywords);
        arsort($keywordFrequency);

        // Identifica padrões de sucesso
        $successPatterns = $this->identifySuccessPatterns($productKeywords, $topProducts);

        return [
            'category' => $category,
            'products_analyzed' => count($topProducts),
            'total_keywords' => count($allKeywords),
            'keyword_frequency' => array_slice($keywordFrequency, 0, 50), // Top 50
            'success_patterns' => $successPatterns,
            'missing_opportunities' => $this->findMissingOpportunities($keywordFrequency, $category),
            'trending_keywords' => $this->identifyTrendingKeywords($category),
            'recommendations' => $this->generateCategoryRecommendations($successPatterns, $keywordFrequency)
        ];
    }

    /**
     * Monitoramento contínuo de lacunas
     */
    public function monitorKeywordGaps(array $productIds): array
    {
        $monitoringData = [];

        foreach ($productIds as $productId) {
            $currentAnalysis = $this->analyzeKeywordGaps($productId);
            $previousAnalysis = $this->getPreviousAnalysis($productId);

            $monitoringData[$productId] = [
                'current' => $currentAnalysis,
                'changes' => $this->detectChanges($currentAnalysis, $previousAnalysis),
                'trend' => $this->calculateTrend($productId, $currentAnalysis, $previousAnalysis),
                'alerts' => $this->generateAlerts($currentAnalysis, $previousAnalysis)
            ];
        }

        return [
            'monitoring_date' => date('Y-m-d H:i:s'),
            'products_monitored' => count($productIds),
            'data' => $monitoringData,
            'summary' => $this->generateMonitoringSummary($monitoringData)
        ];
    }

    /**
     * Obtém dados do produto
     */
    private function getProductData(string $productId): array
    {
        try {
            $response = $this->mlClient->get("/items/{$productId}");

            if ($response) {
                return [
                    'id' => $response['id'],
                    'title' => $response['title'],
                    'category_id' => $response['category_id'],
                    'price' => $response['price'],
                    'description' => $response['description'] ?? '',
                    'attributes' => $response['attributes'] ?? [],
                    'sold_quantity' => $response['sold_quantity'] ?? 0,
                    'available_quantity' => $response['available_quantity'] ?? 0
                ];
            }
        } catch (\Exception $e) {
            // Log erro
        }

        return [];
    }

    /**
     * Busca concorrentes diretos
     */
    private function getDirectCompetitors(array $myProduct, array $context): array
    {
        try {
            $categoryId = $myProduct['category_id'];
            $priceRange = $this->getPriceRange($myProduct['price'], $context['price_tolerance'] ?? 0.3);

            $params = [
                'category' => $categoryId,
                'price' => "{$priceRange['min']}-{$priceRange['max']}",
                'limit' => 20,
                'sort' => 'sold_quantity_desc'
            ];

            // Extrai keywords do título para busca mais específica
            $keywords = $this->extractBasicKeywords($myProduct['title']);
            if (!empty($keywords)) {
                $params['q'] = implode(' ', array_slice($keywords, 0, 3));
            }

            $response = $this->mlClient->get('/sites/MLB/search', $params);

            $competitors = [];
            foreach ($response['results'] ?? [] as $item) {
                if ($item['id'] !== $myProduct['id']) {
                    $competitors[] = [
                        'id' => $item['id'],
                        'title' => $item['title'],
                        'price' => $item['price'],
                        'sold_quantity' => $item['sold_quantity'] ?? 0,
                        'attributes' => $item['attributes'] ?? [],
                        'relevance_score' => $this->calculateRelevanceScore($myProduct, $item)
                    ];
                }
            }

            // Ordena por relevância
            usort($competitors, function ($a, $b) {
                return $b['relevance_score'] <=> $a['relevance_score'];
            });

            return array_slice($competitors, 0, 10); // Top 10 concorrentes

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Extrai keywords do produto
     */
    private function extractProductKeywords(array $product): array
    {
        $keywords = [];

        // 1. Keywords do título
        $titleKeywords = $this->extractBasicKeywords($product['title']);
        $keywords = array_merge($keywords, $titleKeywords);

        // 2. Keywords dos atributos
        foreach ($product['attributes'] as $attr) {
            if (isset($attr['value_name'])) {
                $attrKeywords = $this->extractBasicKeywords($attr['value_name']);
                $keywords = array_merge($keywords, $attrKeywords);
            }
        }

        // 3. Keywords da descrição (limitado)
        if (!empty($product['description'])) {
            $descKeywords = $this->extractBasicKeywords(mb_substr($product['description'], 0, 500));
            $keywords = array_merge($keywords, $descKeywords);
        }

        // Remove duplicatas e filtra
        $keywords = array_unique($keywords);
        $keywords = array_filter($keywords, function ($keyword) {
            return mb_strlen($keyword) > 2 && !in_array(mb_strtolower($keyword), $this->getStopWords());
        });

        return array_values($keywords);
    }

    /**
     * Extrai keywords dos concorrentes
     */
    private function extractCompetitorKeywords(array $competitors): array
    {
        $allKeywords = [];

        foreach ($competitors as $competitor) {
            $keywords = $this->extractProductKeywords($competitor);
            $allKeywords = array_merge($allKeywords, $keywords);
        }

        return array_unique($allKeywords);
    }

    /**
     * Realiza análise de lacunas
     */
    private function performGapAnalysis(array $myKeywords, array $competitorKeywords): array
    {
        $myKeywordsLower = array_map('strtolower', $myKeywords);
        $competitorKeywordsLower = array_map('strtolower', $competitorKeywords);

        // Lacunas (o que concorrentes têm e eu não)
        $missingKeywords = array_diff($competitorKeywordsLower, $myKeywordsLower);

        // Vantagens (o que eu tenho e concorrentes não)
        $uniqueKeywords = array_diff($myKeywordsLower, $competitorKeywordsLower);

        // Overlap (em comum)
        $overlapKeywords = array_intersect($myKeywordsLower, $competitorKeywordsLower);

        // Análise de importância das lacunas
        $keywordImportance = $this->calculateKeywordImportance($missingKeywords, $competitorKeywords);

        return [
            'missing_keywords' => array_values($missingKeywords),
            'unique_keywords' => array_values($uniqueKeywords),
            'overlap_keywords' => array_values($overlapKeywords),
            'gap_severity' => [
                'critical' => $keywordImportance['critical'],
                'high' => $keywordImportance['high'],
                'medium' => $keywordImportance['medium'],
                'low' => $keywordImportance['low']
            ],
            'gap_score' => $this->calculateGapScore(count($missingKeywords), count($competitorKeywords)),
            'opportunity_score' => $this->calculateOpportunityScore($missingKeywords, $keywordImportance)
        ];
    }

    /**
     * Análise semântica com IA
     */
    private function analyzeSemanticGaps(array $myProduct, array $competitors): array
    {
        $competitorSample = array_slice($competitors, 0, 5);
        $competitorText = implode(' ', array_column($competitorSample, 'title'));

        $prompt = "Análise semântica avançada para identificar lacunas ocultas:

MEU PRODUTO:
Título: {$myProduct['title']}
Atributos: " . json_encode($myProduct['attributes'], JSON_UNESCAPED_UNICODE) . "

CONCORRENTES (amostra):
{$competitorText}

Analise e retorne JSON:
{
    \"semantic_gaps\": [\"lacunas semânticas identificadas\"],
    \"conceptual_opportunities\": [\"oportunidades conceituais\"],
    \"latent_intent\": [\"intenções latentes não exploradas\"],
    \"contextual_keywords\": [\"keywords contextuais ausentes\"],
    \"semantic_clusters_missing\": [\"agrupamentos semânticos faltando\"],
    \"user_intent_gaps\": [\"lacunas de intenção do usuário\"],
    \"semantic_score_gap\": (diferença percentual),
    \"recommendations\": [\"recomendações semânticas específicas\"]
}";

        $response = $this->ai->chatJSON($prompt, [
            'cache_ttl' => 3600,
            'temperature' => 0.7
        ]);

        return $response['success'] ? $response['data'] : [];
    }

    /**
     * Encontra oportunidades de cauda longa
     */
    private function findLongTailOpportunities(array $myProduct, array $competitors): array
    {
        // Combina informações para análise
        $context = [
            'product' => $myProduct,
            'competitors' => array_slice($competitors, 0, 3)
        ];

        $prompt = "Identifique oportunidades de palavras-chave de cauda longa:

PRODUTO: {$myProduct['title']}
CATEGORIA: {$myProduct['category_id']}
CONCORRENTES: " . json_encode(array_column($competitors, 'title'), JSON_UNESCAPED_UNICODE) . "

Retorne JSON com oportunidades de cauda longa:
{
    \"long_tail_keywords\": [
        {
            \"keyword\": \"keyword de cauda longa\",
            \"search_intent\": \"intenção de busca\",
            \"competition_level\": \"alta/média/baixa\",
            \"estimated_traffic\": \"estimativa de tráfego\",
            \"conversion_potential\": \"potencial de conversão\"
        }
    ],
    \"question_keywords\": [\"keywords baseadas em perguntas\"],
    \"local_opportunities\": [\"oportunidades locais se aplicável\"],
    \"seasonal_opportunities\": [\"oportunidades sazonais\"],
    \"niche_opportunities\": [oportunidades de nicho],
    \"implementation_priority\": \"prioridade de implementação\"
}";

        $response = $this->ai->chatJSON($prompt, [
            'cache_ttl' => 7200,
            'temperature' => 0.8
        ]);

        return $response['success'] ? $response['data'] : [];
    }

    /**
     * Gera insights acionáveis
     */
    private function generateActionableInsights(array $gapAnalysis, array $semanticGaps, array $longTail): array
    {
        $insights = [];

        // Insights das lacunas principais
        if (!empty($gapAnalysis['gap_severity']['critical'])) {
            $insights[] = [
                'type' => 'critical_gap',
                'priority' => 'immediate',
                'action' => 'Adicionar keywords críticas ausentes: ' . implode(', ', array_slice($gapAnalysis['gap_severity']['critical'], 0, 3)),
                'expected_impact' => 'Alto - aumento significativo de visibilidade'
            ];
        }

        // Insights semânticos
        if (!empty($semanticGaps['semantic_gaps'])) {
            $insights[] = [
                'type' => 'semantic_opportunity',
                'priority' => 'high',
                'action' => 'Expandir semanticamente com: ' . implode(', ', array_slice($semanticGaps['semantic_gaps'], 0, 3)),
                'expected_impact' => 'Médio - melhora relevância semântica'
            ];
        }

        // Insights de cauda longa
        if (!empty($longTail['long_tail_keywords'])) {
            $insights[] = [
                'type' => 'long_tail',
                'priority' => 'medium',
                'action' => 'Implementar keywords de cauda longa específicas',
                'expected_impact' => 'Médio - captura tráfego qualificado'
            ];
        }

        return $insights;
    }

    /**
     * Estima impacto das otimizações
     */
    private function estimateImpact(array $gapAnalysis): array
    {
        $gapScore = $gapAnalysis['gap_score'] ?? 0;
        $opportunityScore = $gapAnalysis['opportunity_score'] ?? 0;

        $estimatedImprovement = [
            'visibility_increase' => min(50, $gapScore * 0.8),
            'traffic_increase' => min(40, $opportunityScore * 0.6),
            'conversion_improvement' => min(25, $opportunityScore * 0.4),
            'ranking_improvement' => min(30, $gapScore * 0.7)
        ];

        return [
            'overall_impact_score' => ($gapScore + $opportunityScore) / 2,
            'estimated_improvements' => $estimatedImprovement,
            'implementation_effort' => $gapScore > 50 ? 'high' : 'medium',
            'time_to_results' => '2-4 semanas'
        ];
    }

    /**
     * Métodos auxiliares
     */
    private function extractBasicKeywords(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', trim($text));

        return array_filter($words, function ($word) {
            return mb_strlen($word) > 2 && !in_array($word, $this->getStopWords());
        });
    }

    private function getStopWords(): array
    {
        return ['de', 'da', 'do', 'em', 'para', 'com', 'sem', 'a', 'o', 'as', 'os', 'e', 'ou', 'um', 'uma', 'uns', 'umas'];
    }

    private function getPriceRange(float $price, float $tolerance): array
    {
        $min = $price * (1 - $tolerance);
        $max = $price * (1 + $tolerance);
        return ['min' => round($min, 2), 'max' => round($max, 2)];
    }

    private function calculateRelevanceScore(array $myProduct, array $competitor): float
    {
        $score = 0;

        // Similaridade de preço
        $priceDiff = $myProduct['price'] > 0 ? abs($myProduct['price'] - $competitor['price']) / $myProduct['price'] : 0;
        $score += (1 - $priceDiff) * 30;

        // Similaridade de vendas
        if (isset($competitor['sold_quantity'])) {
            $score += min($competitor['sold_quantity'] / 100, 1) * 40;
        }

        // Score base de relevância
        $score += 30;

        return $score;
    }

    private function calculateKeywordImportance(array $missingKeywords, array $competitorKeywords): array
    {
        $frequency = array_count_values($competitorKeywords);

        $critical = [];
        $high = [];
        $medium = [];
        $low = [];

        foreach ($missingKeywords as $keyword) {
            $freq = $frequency[$keyword] ?? 0;

            if ($freq >= 8) $critical[] = $keyword;
            elseif ($freq >= 5) $high[] = $keyword;
            elseif ($freq >= 3) $medium[] = $keyword;
            else $low[] = $keyword;
        }

        return compact('critical', 'high', 'medium', 'low');
    }

    private function calculateGapScore(int $missingCount, int $totalCompetitor): int
    {
        if ($totalCompetitor === 0) return 0;
        return min(100, ($missingCount / $totalCompetitor) * 100);
    }

    private function calculateOpportunityScore(array $missingKeywords, array $importance): int
    {
        $criticalCount = count($importance['critical'] ?? []);
        $highCount = count($importance['high'] ?? []);

        return min(100, ($criticalCount * 20) + ($highCount * 10));
    }

    private function getTopProductsByCategory(string $category, array $filters): array
    {
        try {
            $params = [
                'category' => $category,
                'sort' => 'sold_quantity_desc',
                'limit' => $filters['limit'] ?? 20,
            ];

            if (isset($filters['price_min'])) {
                $params['price'] = ($filters['price_min'] ?? 0) . '-' . ($filters['price_max'] ?? 999999);
            }

            $response = $this->mlClient->get('/sites/MLB/search', $params);

            $products = [];
            foreach ($response['results'] ?? [] as $item) {
                $products[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'price' => $item['price'] ?? 0,
                    'sold_quantity' => $item['sold_quantity'] ?? 0,
                    'attributes' => $item['attributes'] ?? [],
                    'description' => '',
                ];
            }

            return $products;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function identifySuccessPatterns(array $productKeywords, array $products): array
    {
        // Correlacionar keywords com vendas para identificar padrões vencedores
        $keywordSales = [];

        foreach ($products as $product) {
            $pid = $product['id'];
            $soldQty = $product['sold_quantity'] ?? 0;
            $keywords = $productKeywords[$pid] ?? [];

            foreach ($keywords as $keyword) {
                $kw = mb_strtolower($keyword);
                if (!isset($keywordSales[$kw])) {
                    $keywordSales[$kw] = ['total_sales' => 0, 'product_count' => 0, 'products' => []];
                }
                $keywordSales[$kw]['total_sales'] += $soldQty;
                $keywordSales[$kw]['product_count']++;
                $keywordSales[$kw]['products'][] = $pid;
            }
        }

        // Ordenar por vendas totais
        uasort($keywordSales, fn(array $a, array $b): int => $b['total_sales'] <=> $a['total_sales']);

        $patterns = [];
        foreach (array_slice($keywordSales, 0, 20, true) as $keyword => $data) {
            $avgSales = $data['product_count'] > 0
                ? round($data['total_sales'] / $data['product_count'])
                : 0;

            $patterns[] = [
                'keyword' => $keyword,
                'total_sales' => $data['total_sales'],
                'product_count' => $data['product_count'],
                'avg_sales_per_product' => $avgSales,
                'strength' => $data['product_count'] >= 5 ? 'strong' : ($data['product_count'] >= 2 ? 'moderate' : 'weak'),
            ];
        }

        return $patterns;
    }

    private function findMissingOpportunities(array $keywordFrequency, string $category): array
    {
        $opportunities = [];

        // Keywords com alta frequência entre concorrentes = oportunidades
        $topKeywords = array_slice($keywordFrequency, 0, 30, true);

        foreach ($topKeywords as $keyword => $frequency) {
            if ($frequency >= 3) {
                $opportunities[] = [
                    'keyword' => $keyword,
                    'competitor_frequency' => $frequency,
                    'priority' => $frequency >= 8 ? 'critical' : ($frequency >= 5 ? 'high' : 'medium'),
                    'reason' => "{$frequency} concorrentes utilizam esta keyword",
                    'suggested_action' => 'Incluir no título ou descrição do produto',
                ];
            }
        }

        usort($opportunities, fn(array $a, array $b): int => $b['competitor_frequency'] <=> $a['competitor_frequency']);

        return $opportunities;
    }

    private function identifyTrendingKeywords(string $category): array
    {
        try {
            $response = $this->mlClient->get('/trends/MLB', [
                'category' => $category,
                'limit' => 20,
            ]);

            $trending = [];
            foreach ($response['keywords'] ?? $response['trends'] ?? [] as $trend) {
                if (is_string($trend)) {
                    $trending[] = ['keyword' => $trend, 'source' => 'ml_trends'];
                } elseif (is_array($trend)) {
                    $trending[] = [
                        'keyword' => $trend['keyword'] ?? $trend['query'] ?? '',
                        'volume' => $trend['volume'] ?? null,
                        'growth' => $trend['growth'] ?? null,
                        'source' => 'ml_trends',
                    ];
                }
            }

            return array_filter($trending, fn(array $t): bool => ($t['keyword'] ?? '') !== '');
        } catch (\Exception $e) {
            // Fallback: extrair tendências dos top produtos
            try {
                $topProducts = $this->getTopProductsByCategory($category, ['limit' => 10]);
                $allKeywords = [];
                foreach ($topProducts as $product) {
                    $allKeywords = array_merge($allKeywords, $this->extractBasicKeywords($product['title']));
                }
                $freq = array_count_values($allKeywords);
                arsort($freq);

                $trending = [];
                foreach (array_slice($freq, 0, 10, true) as $kw => $count) {
                    $trending[] = ['keyword' => $kw, 'frequency' => $count, 'source' => 'search_fallback'];
                }
                return $trending;
            } catch (\Exception $e2) {
                return [];
            }
        }
    }

    private function generateCategoryRecommendations(array $patterns, array $frequency): array
    {
        $recommendations = [];

        // Recomendar keywords de padrões fortes que são frequentes
        $strongPatterns = array_filter($patterns, fn(array $p): bool => ($p['strength'] ?? '') === 'strong');
        if (!empty($strongPatterns)) {
            $kwList = implode(', ', array_column(array_slice($strongPatterns, 0, 5), 'keyword'));
            $recommendations[] = [
                'type' => 'must_have_keywords',
                'priority' => 'high',
                'description' => "Keywords dominantes na categoria: {$kwList}",
                'keywords' => array_column($strongPatterns, 'keyword'),
            ];
        }

        // Keywords frequentes que podem ser oportunidades de diferenciação
        $topFreq = array_slice($frequency, 0, 10, true);
        $lowFreq = array_slice($frequency, -10, 10, true);

        if (!empty($lowFreq)) {
            $nicheKws = implode(', ', array_slice(array_keys($lowFreq), 0, 5));
            $recommendations[] = [
                'type' => 'niche_opportunity',
                'priority' => 'medium',
                'description' => "Keywords de nicho com menos competição: {$nicheKws}",
                'keywords' => array_keys($lowFreq),
            ];
        }

        // Recomendação de volume
        $totalKeywords = array_sum($frequency);
        $uniqueKeywords = count($frequency);
        if ($uniqueKeywords > 0) {
            $avgFreq = $totalKeywords / $uniqueKeywords;
            $recommendations[] = [
                'type' => 'market_overview',
                'priority' => 'info',
                'description' => "Mercado com {$uniqueKeywords} keywords únicas, frequência média de " . round($avgFreq, 1),
                'avg_frequency' => round($avgFreq, 1),
                'total_unique' => $uniqueKeywords,
            ];
        }

        return $recommendations;
    }

    private function getPreviousAnalysis(string $productId): array
    {
        try {
            $cache = new \App\Services\CacheService();
            $cacheKey = "keyword_gap_analysis:{$productId}";
            $cached = $cache->get($cacheKey);
            return is_array($cached) ? $cached : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function detectChanges(array $current, array $previous): array
    {
        if (empty($previous)) {
            return ['status' => 'first_analysis', 'changes' => []];
        }

        $changes = [];

        // Comparar gap_score
        $currentGapScore = $current['gap_analysis']['gap_score'] ?? 0;
        $previousGapScore = $previous['gap_analysis']['gap_score'] ?? 0;
        $gapDiff = $currentGapScore - $previousGapScore;

        if (abs($gapDiff) > 5) {
            $changes[] = [
                'metric' => 'gap_score',
                'previous' => $previousGapScore,
                'current' => $currentGapScore,
                'direction' => $gapDiff > 0 ? 'worsened' : 'improved',
                'delta' => round($gapDiff, 2),
            ];
        }

        // Comparar keywords ausentes
        $currentMissing = $current['gap_analysis']['missing_keywords'] ?? [];
        $previousMissing = $previous['gap_analysis']['missing_keywords'] ?? [];

        $newMissing = array_diff($currentMissing, $previousMissing);
        $resolved = array_diff($previousMissing, $currentMissing);

        if (!empty($newMissing)) {
            $changes[] = [
                'metric' => 'new_missing_keywords',
                'keywords' => array_values($newMissing),
                'direction' => 'worsened',
            ];
        }

        if (!empty($resolved)) {
            $changes[] = [
                'metric' => 'resolved_keywords',
                'keywords' => array_values($resolved),
                'direction' => 'improved',
            ];
        }

        return [
            'status' => empty($changes) ? 'no_change' : 'changed',
            'changes' => $changes,
        ];
    }

    private function calculateTrend(string $productId, array $current, array $previous): string
    {
        if (empty($previous)) {
            return 'new';
        }

        $currentScore = $current['gap_analysis']['gap_score'] ?? 0;
        $previousScore = $previous['gap_analysis']['gap_score'] ?? 0;

        $currentOpp = $current['gap_analysis']['opportunity_score'] ?? 0;
        $previousOpp = $previous['gap_analysis']['opportunity_score'] ?? 0;

        // Gap score alto = pior, então se diminuiu = melhorando
        $gapDelta = $currentScore - $previousScore;
        $oppDelta = $currentOpp - $previousOpp;

        if ($gapDelta < -10 && $oppDelta > 10) {
            return 'improving_fast';
        }
        if ($gapDelta < -3) {
            return 'improving';
        }
        if ($gapDelta > 10) {
            return 'declining_fast';
        }
        if ($gapDelta > 3) {
            return 'declining';
        }

        return 'stable';
    }

    private function generateAlerts(array $current, array $previous): array
    {
        $alerts = [];

        $gapScore = $current['gap_analysis']['gap_score'] ?? 0;
        $criticalGaps = $current['gap_analysis']['gap_severity']['critical'] ?? [];

        // Alerta: gap score muito alto
        if ($gapScore > 70) {
            $alerts[] = [
                'level' => 'critical',
                'message' => "Gap score alto ({$gapScore}%): muitas keywords importantes ausentes",
                'action' => 'Revisar título e descrição urgentemente',
            ];
        } elseif ($gapScore > 40) {
            $alerts[] = [
                'level' => 'warning',
                'message' => "Gap score moderado ({$gapScore}%): oportunidades de melhoria",
                'action' => 'Adicionar keywords de alta prioridade ao anúncio',
            ];
        }

        // Alerta: keywords críticas faltando
        if (count($criticalGaps) > 3) {
            $sample = implode(', ', array_slice($criticalGaps, 0, 3));
            $alerts[] = [
                'level' => 'critical',
                'message' => count($criticalGaps) . " keywords críticas ausentes: {$sample}...",
                'action' => 'Incluir imediatamente essas keywords no título',
            ];
        }

        // Alerta: piora em relação à análise anterior
        if (!empty($previous)) {
            $prevGapScore = $previous['gap_analysis']['gap_score'] ?? 0;
            if ($gapScore > $prevGapScore + 15) {
                $alerts[] = [
                    'level' => 'warning',
                    'message' => "Gap score piorou de {$prevGapScore}% para {$gapScore}%",
                    'action' => 'Concorrentes adicionaram novas keywords — adaptar rapidamente',
                ];
            }
        }

        return $alerts;
    }

    private function generateMonitoringSummary(array $monitoringData): array
    {
        $totalProducts = count($monitoringData);
        $improving = 0;
        $declining = 0;
        $stable = 0;
        $totalAlerts = 0;
        $criticalAlerts = 0;

        foreach ($monitoringData as $data) {
            $trend = $data['trend'] ?? 'stable';
            if (in_array($trend, ['improving', 'improving_fast'], true)) {
                $improving++;
            } elseif (in_array($trend, ['declining', 'declining_fast'], true)) {
                $declining++;
            } else {
                $stable++;
            }

            $alerts = $data['alerts'] ?? [];
            $totalAlerts += count($alerts);
            foreach ($alerts as $alert) {
                if (($alert['level'] ?? '') === 'critical') {
                    $criticalAlerts++;
                }
            }
        }

        return [
            'total_products' => $totalProducts,
            'trends' => [
                'improving' => $improving,
                'stable' => $stable,
                'declining' => $declining,
            ],
            'alerts' => [
                'total' => $totalAlerts,
                'critical' => $criticalAlerts,
            ],
            'overall_health' => $declining === 0 && $criticalAlerts === 0
                ? 'healthy'
                : ($criticalAlerts > 0 || $declining > $improving ? 'needs_attention' : 'moderate'),
        ];
    }
}
