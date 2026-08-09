<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use Exception;
use App\Services\LLMService;
use App\Services\UnifiedAIService;
use App\Services\AI\Core\RetryService;
use App\Services\AI\Core\LoggingService;
use App\Services\AI\Prompts\SEOPrompts;
use App\Services\GoogleKeywordPlannerService;

/**
 * Serviço de Otimização SEO por Inteligência Artificial
 *
 * Sistema avançado para otimização automática de SEO no Mercado Livre:
 * - Análise de keywords e rankeamento
 * - Otimização de títulos para busca interna
 * - Análise de concorrência SEO
 * - Sugestões de melhorias baseadas em IA
 * - Score de otimização em tempo real
 * - Tracking de performance SEO
 *
 * @author Sistema ML Manager V8.0
 * @version 8.0.0
 */
class AISEOOptimizerService
{
    private \PDO $db;
    private LogService $logger;
    private CacheManagerService $cache;
    private LLMService $ai;
    private UnifiedAIService $unifiedAi;
    private RetryService $retryService;
    private GoogleKeywordPlannerService $keywordPlanner;
    private array $seoRules;
    private array $keywords;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = new LogService();
        $this->cache = new CacheManagerService();
        $this->ai = new LLMService();
        $accountId = $_SESSION['active_ml_account_id'] ?? null;
        $this->unifiedAi = new UnifiedAIService($accountId);
        $this->retryService = new RetryService(new LoggingService($accountId));
        $this->keywordPlanner = new GoogleKeywordPlannerService(null);
        $this->initializeSEORules();
        $this->loadKeywordDatabase();
    }

    // ========== ANÁLISE E OTIMIZAÇÃO PRINCIPAL ==========

    /**
     * Análise completa de SEO de um produto
     */
    public function analyzeSEO(array $productData, array $options = []): array
    {
        try {
            $cacheKey = 'ai_seo_analysis_' . md5(json_encode($productData));
            $cached = $this->cache->get($cacheKey, 'ai_seo');
            if ($cached && !($options['force_refresh'] ?? false)) {
                return $cached;
            }

            // Análises paralelas
            $analyses = [
                'title_analysis' => $this->analyzeTitleSEO($productData),
                'description_analysis' => $this->analyzeDescriptionSEO($productData),
                'keywords_analysis' => $this->analyzeKeywords($productData),
                'attributes_analysis' => $this->analyzeAttributes($productData),
                'category_analysis' => $this->analyzeCategoryOptimization($productData),
                'images_analysis' => $this->analyzeImagesSEO($productData),
                'competition_analysis' => $this->analyzeCompetition($productData),
                'ml_algorithm_analysis' => $this->analyzeMLAlgorithmCompatibility($productData)
            ];

            // Score geral de SEO
            $overallScore = $this->calculateOverallSEOScore($analyses);

            // Oportunidades de melhoria
            $opportunities = $this->identifyOptimizationOpportunities($analyses);

            // Plano de ação automatizado
            $actionPlan = $this->generateActionPlan($analyses, $opportunities);

            // Previsão de impacto
            $impactPrediction = $this->predictOptimizationImpact($analyses, $actionPlan, $productData);

            $result = [
                'success' => true,
                'overall_seo_score' => $overallScore,
                'detailed_analysis' => $analyses,
                'optimization_opportunities' => $opportunities,
                'action_plan' => $actionPlan,
                'impact_prediction' => $impactPrediction,
                'competitor_benchmarks' => $this->getCompetitorBenchmarks($productData),
                'seo_trends' => $this->getSEOTrends($productData['category_id'] ?? null),
                'analyzed_at' => date('Y-m-d H:i:s')
            ];

            // Cache por 2 horas
            $this->cache->set($cacheKey, $result, 'ai_seo', 7200);

            // Log da análise
            $this->logger->info('AI SEO analysis completed', [
                'product_id' => $productData['id'] ?? null,
                'seo_score' => $overallScore,
                'opportunities_count' => count($opportunities)
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error('AI SEO analysis failed', [
                'error' => $e->getMessage(),
                'product' => $productData['id'] ?? 'unknown'
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    /**
     * Otimiza automaticamente produto para SEO
     */
    public function optimizeProduct(array $productData, array $options = []): array
    {
        try {
            // Análise prévia
            $analysis = $this->analyzeSEO($productData, ['force_refresh' => true]);

            if (!$analysis['success']) {
                return $analysis;
            }

            $optimizations = [];

            // Otimização de título
            if ($analysis['detailed_analysis']['title_analysis']['score'] < 80) {
                $titleOpt = $this->optimizeTitle($productData, $analysis);
                $optimizations['title'] = $titleOpt;
            }

            // Otimização de descrição
            if ($analysis['detailed_analysis']['description_analysis']['score'] < 75) {
                $descOpt = $this->optimizeDescription($productData, $analysis);
                $optimizations['description'] = $descOpt;
            }

            // Otimização de keywords
            $keywordOpt = $this->optimizeKeywords($productData, $analysis);
            $optimizations['keywords'] = $keywordOpt;

            // Otimização de atributos
            $attrOpt = $this->optimizeAttributes($productData, $analysis);
            $optimizations['attributes'] = $attrOpt;

            // Produto otimizado
            $optimizedProduct = $this->applyOptimizations($productData, $optimizations);

            // Nova análise pós-otimização
            $postAnalysis = $this->analyzeSEO($optimizedProduct, ['force_refresh' => true]);

            $result = [
                'success' => true,
                'original_product' => $productData,
                'optimized_product' => $optimizedProduct,
                'optimizations_applied' => $optimizations,
                'before_score' => $analysis['overall_seo_score'],
                'after_score' => $postAnalysis['overall_seo_score'],
                'improvement' => $postAnalysis['overall_seo_score'] - $analysis['overall_seo_score'],
                'estimated_visibility_increase' => $this->calculateVisibilityIncrease(
                    $analysis['overall_seo_score'],
                    $postAnalysis['overall_seo_score']
                ),
                'optimization_summary' => $this->generateOptimizationSummary($optimizations),
                'optimized_at' => date('Y-m-d H:i:s')
            ];

            // Log da otimização
            $this->logger->info('AI SEO optimization completed', [
                'product_id' => $productData['id'] ?? null,
                'score_improvement' => $result['improvement'],
                'optimizations_count' => count($optimizations)
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error('AI SEO optimization failed', [
                'error' => $e->getMessage(),
                'product' => $productData['id'] ?? 'unknown'
            ]);

            return $this->createErrorResponse($e->getMessage());
        }
    }

    // ========== ANÁLISES ESPECÍFICAS ==========

    /**
     * Analisa SEO do título com IA
     */
    private function analyzeTitleSEO(array $productData): array
    {
        $title = $productData['title'] ?? '';
        $category = $productData['category_id'] ?? null;

        // Check cache first
        $cacheKey = 'title_seo_analysis_' . md5($title . '_' . $category);
        $cached = $this->cache->get($cacheKey, 'ai_seo');
        if ($cached) {
            return $cached;
        }

        try {
            // Prepare context for AI
            $context = [
                'title' => $title,
                'category' => $category,
                'product_data' => $productData,
                'forbidden_words' => $this->getForbiddenWords(),
                'category_keywords' => $this->getCategoryKeywords($category)
            ];

            // Build prompt for AI analysis
            $prompt = $this->buildTitleAnalysisPrompt($context);

            // Call AI with retry mechanism
            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em SEO e otimização de títulos para marketplaces. Analise o título fornecido e forneça uma análise detalhada.", 'advanced'),
                'analyze_title_seo',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Parse AI response
                $parsedResult = $this->parseTitleAnalysisResponse($aiResponse, $title);

                // Cache the result
                $this->cache->set($cacheKey, $parsedResult, 'ai_seo', 7200); // 2 hours

                return $parsedResult;
            } else {
                // Fallback to basic analysis if AI fails
                return $this->analyzeTitleSEOFallback($productData);
            }
        } catch (\Exception $e) {
            $this->logger->error('Title SEO analysis failed', [
                'error' => $e->getMessage(),
                'title' => $title,
                'category' => $category
            ]);

            // Return fallback analysis
            return $this->analyzeTitleSEOFallback($productData);
        }
    }

    /**
     * Build prompt for title analysis
     */
    private function buildTitleAnalysisPrompt(array $context): string
    {
        return SEOPrompts::analyzeTitle($context);
    }

    /**
     * Parse AI response for title analysis
     */
    private function parseTitleAnalysisResponse(string $aiResponse, string $originalTitle): array
    {
        // Try to extract JSON from AI response
        $jsonStart = strpos($aiResponse, '{');
        $jsonEnd = strrpos($aiResponse, '}');

        if ($jsonStart !== false && $jsonEnd !== false) {
            $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
            $parsed = json_decode($jsonStr, true);

            if ($parsed && is_array($parsed)) {
                // Ensure the original title is preserved
                $parsed['title'] = $originalTitle;
                return $parsed;
            }
        }

        return $this->analyzeTitleSEOFallback([
            'title' => $originalTitle,
            'category_id' => null
        ]);
    }

    /**
     * Fallback analysis when AI is not available
     */
    private function analyzeTitleSEOFallback(array $productData): array
    {
        $title = $productData['title'] ?? '';
        $category = $productData['category_id'] ?? null;

        $analysis = [
            'title' => $title,
            'length' => mb_strlen($title),
            'word_count' => str_word_count($title),
            'keywords_found' => [],
            'readability' => $this->calculateTextReadability($title),
            'score' => 0,
            'issues' => [],
            'suggestions' => []
        ];

        // Verificar comprimento
        if ($analysis['length'] < 30) {
            $analysis['issues'][] = 'Título muito curto';
            $analysis['suggestions'][] = 'Adicionar mais palavras-chave descritivas';
        } elseif ($analysis['length'] > 60) {
            $analysis['issues'][] = 'Título muito longo';
            $analysis['suggestions'][] = 'Reduzir para máximo 60 caracteres';
        }

        // Análise de keywords
        $categoryKeywords = $this->getCategoryKeywords($category);
        foreach ($categoryKeywords as $keyword) {
            if (stripos($title, $keyword) !== false) {
                $analysis['keywords_found'][] = $keyword;
            }
        }

        // Verificar palavras proibidas ML
        $forbiddenWords = $this->getForbiddenWords();
        foreach ($forbiddenWords as $word) {
            if (stripos($title, $word) !== false) {
                $analysis['issues'][] = "Palavra proibida encontrada: {$word}";
                $analysis['suggestions'][] = "Remover '{$word}' do título";
            }
        }

        // Calcular score
        $analysis['score'] = $this->calculateTitleScore($analysis);

        return $analysis;
    }

    /**
     * Analisa SEO da descrição com IA
     */
    private function analyzeDescriptionSEO(array $productData): array
    {
        $description = $productData['description'] ?? '';

        // Check cache first
        $cacheKey = 'description_seo_analysis_' . md5($description);
        $cached = $this->cache->get($cacheKey, 'ai_seo');
        if ($cached) {
            return $cached;
        }

        try {
            // Prepare context for AI
            $context = [
                'description' => $description,
                'product_data' => $productData,
                'category' => $productData['category_id'] ?? null,
                'title' => $productData['title'] ?? ''
            ];

            // Build prompt for AI analysis
            $prompt = $this->buildDescriptionAnalysisPrompt($context);

            // Call AI with retry mechanism
            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em SEO e copywriting para marketplaces. Analise a descrição fornecida e forneça uma análise detalhada.", 'advanced'),
                'analyze_description_seo',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Parse AI response
                $parsedResult = $this->parseDescriptionAnalysisResponse($aiResponse, $description);

                // Cache the result
                $this->cache->set($cacheKey, $parsedResult, 'ai_seo', 7200); // 2 hours

                return $parsedResult;
            } else {
                // Fallback to basic analysis if AI fails
                return $this->analyzeDescriptionSEOFallback($productData);
            }
        } catch (\Exception $e) {
            $this->logger->error('Description SEO analysis failed', [
                'error' => $e->getMessage(),
                'product_id' => $productData['id'] ?? null
            ]);

            // Return fallback analysis
            return $this->analyzeDescriptionSEOFallback($productData);
        }
    }

    /**
     * Build prompt for description analysis
     */
    private function buildDescriptionAnalysisPrompt(array $context): string
    {
        return SEOPrompts::analyzeDescription($context);
    }

    /**
     * Parse AI response for description analysis
     */
    private function parseDescriptionAnalysisResponse(string $aiResponse, string $originalDescription): array
    {
        // Try to extract JSON from AI response
        $jsonStart = strpos($aiResponse, '{');
        $jsonEnd = strrpos($aiResponse, '}');

        if ($jsonStart !== false && $jsonEnd !== false) {
            $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
            $parsed = json_decode($jsonStr, true);

            if ($parsed && is_array($parsed)) {
                return $parsed;
            }
        }

        return $this->analyzeDescriptionSEOFallback([
            'description' => $originalDescription
        ]);
    }

    /**
     * Fallback analysis when AI is not available
     */
    private function analyzeDescriptionSEOFallback(array $productData): array
    {
        $description = $productData['description'] ?? '';

        $analysis = [
            'description_length' => mb_strlen($description),
            'word_count' => str_word_count($description),
            'keyword_density' => [],
            'structure_score' => 0,
            'readability_score' => $this->calculateTextReadability($description),
            'call_to_action' => false,
            'bullets_found' => 0,
            'score' => 0,
            'issues' => [],
            'suggestions' => []
        ];

        // Verificar estrutura
        if (strpos($description, '•') !== false || strpos($description, '-') !== false) {
            $analysis['bullets_found'] = substr_count($description, '•') + substr_count($description, '-');
            $analysis['structure_score'] += 20;
        }

        if ($analysis['description_length'] >= 300) {
            $analysis['structure_score'] += 20;
        }
        if ($analysis['description_length'] >= 600) {
            $analysis['structure_score'] += 20;
        }

        // Verificar call-to-action
        $ctaWords = ['compre', 'aproveite', 'garanta', 'adquira'];
        foreach ($ctaWords as $cta) {
            if (stripos($description, $cta) !== false) {
                $analysis['call_to_action'] = true;
                break;
            }
        }

        // Densidade de keywords
        $keywords = $this->extractKeywordsFromText($description);
        $analysis['keyword_density'] = $this->calculateKeywordDensity($description, $keywords);

        // Calcular score
        $analysis['score'] = $this->calculateDescriptionScore($analysis);

        return $analysis;
    }

    /**
     * Analisa keywords e relevância com IA
     */
    private function analyzeKeywords(array $productData): array
    {
        $title = $productData['title'] ?? '';
        $description = $productData['description'] ?? '';
        $category = $productData['category_id'] ?? null;

        // Check cache first
        $cacheKey = 'keywords_analysis_' . md5($title . '_' . $description . '_' . $category);
        $cached = $this->cache->get($cacheKey, 'keywords');
        if ($cached) {
            return $cached;
        }

        try {
            // Prepare context for AI
            $context = [
                'title' => $title,
                'description' => $description,
                'category' => $category,
                'product_data' => $productData,
                'category_keywords' => $this->getCategoryKeywords($category)
            ];

            // Build prompt for AI analysis
            $prompt = $this->buildKeywordsAnalysisPrompt($context);

            // Call AI with retry mechanism
            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em SEO e pesquisa de palavras-chave. Analise o conteúdo fornecido e forneça uma análise detalhada de keywords.", 'advanced'),
                'analyze_keywords',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Parse AI response
                $parsedResult = $this->parseKeywordsAnalysisResponse($aiResponse, $context);

                // Cache the result
                $this->cache->set($cacheKey, $parsedResult, 'keywords', 86400); // 24 hours

                return $parsedResult;
            } else {
                // Fallback to basic analysis if AI fails
                return $this->analyzeKeywordsFallback($productData);
            }
        } catch (\Exception $e) {
            $this->logger->error('Keywords analysis failed', [
                'error' => $e->getMessage(),
                'product_id' => $productData['id'] ?? null
            ]);

            // Return fallback analysis
            return $this->analyzeKeywordsFallback($productData);
        }
    }

    /**
     * Build prompt for keywords analysis
     */
    private function buildKeywordsAnalysisPrompt(array $context): string
    {
        return SEOPrompts::analyzeKeywords($context);
    }

    /**
     * Parse AI response for keywords analysis
     */
    private function parseKeywordsAnalysisResponse(string $aiResponse, array $context): array
    {
        // Try to extract JSON from AI response
        $jsonStart = strpos($aiResponse, '{');
        $jsonEnd = strrpos($aiResponse, '}');

        if ($jsonStart !== false && $jsonEnd !== false) {
            $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
            $parsed = json_decode($jsonStr, true);

            if ($parsed && is_array($parsed)) {
                // Ensure we have all required fields
                $parsed['category_keywords'] = $parsed['category_keywords'] ?? $context['category_keywords'];
                $parsed['competitor_keywords'] = $this->getCompetitorKeywords($context['product_data']);
                $parsed['trending_keywords'] = $this->getTrendingKeywords($context['category']);

                // Defesa extra: o prompt (SEOPrompts::analyzeKeywords) já instrui a IA a
                // NUNCA incluir "search_volume" (LLM não tem dado real de busca), mas LLMs
                // nem sempre seguem a instrução à risca. Remove qualquer "search_volume"
                // que a IA tenha inventado mesmo assim, para não vazar um número fabricado.
                if (isset($parsed['keyword_opportunities']) && is_array($parsed['keyword_opportunities'])) {
                    foreach ($parsed['keyword_opportunities'] as &$opportunity) {
                        if (is_array($opportunity) && array_key_exists('search_volume', $opportunity)) {
                            unset($opportunity['search_volume']);
                        }
                    }
                    unset($opportunity);
                }

                return $parsed;
            }
        }

        // Fallback: return basic analysis
        return [
            'primary_keywords' => [],
            'secondary_keywords' => [],
            'long_tail_keywords' => [],
            'category_keywords' => $context['category_keywords'],
            'competitor_keywords' => $this->getCompetitorKeywords($context['product_data']),
            'trending_keywords' => $this->getTrendingKeywords($context['category']),
            'keyword_opportunities' => [],
            'score' => 50
        ];
    }

    /**
     * Fallback analysis when AI is not available
     */
    private function analyzeKeywordsFallback(array $productData): array
    {
        $title = $productData['title'] ?? '';
        $description = $productData['description'] ?? '';
        $category = $productData['category_id'] ?? null;

        $analysis = [
            'primary_keywords' => [],
            'secondary_keywords' => [],
            'long_tail_keywords' => [],
            'category_keywords' => $this->getCategoryKeywords($category),
            'competitor_keywords' => $this->getCompetitorKeywords($productData),
            'trending_keywords' => $this->getTrendingKeywords($category),
            'keyword_opportunities' => [],
            'score' => 0
        ];

        // Extrair keywords do conteúdo atual
        $currentKeywords = array_unique(array_merge(
            $this->extractKeywordsFromText($title),
            $this->extractKeywordsFromText($description)
        ));

        // Classificar keywords por importância
        foreach ($currentKeywords as $keyword) {
            $importance = $this->calculateKeywordImportance($keyword, $category);

            if ($importance > 0.8) {
                $analysis['primary_keywords'][] = $keyword;
            } elseif ($importance > 0.5) {
                $analysis['secondary_keywords'][] = $keyword;
            } else {
                $analysis['long_tail_keywords'][] = $keyword;
            }
        }

        // Identificar oportunidades perdidas
        $categoryKeywords = $analysis['category_keywords'];
        foreach ($categoryKeywords as $catKeyword) {
            if (!in_array($catKeyword, $currentKeywords)) {
                $volumeData = $this->getKeywordSearchVolumeData($catKeyword);
                $analysis['keyword_opportunities'][] = [
                    'keyword' => $catKeyword,
                    'priority' => $this->calculateKeywordImportance($catKeyword, $category),
                    // Correção de metodologia: só é um número real quando 'data_source' ===
                    // 'google_keyword_planner'. Antes, quando o Keyword Planner não estava
                    // configurado, o sistema pedia pra uma IA "chutar" um número (alucinação)
                    // ou usava heurística por tamanho da palavra — ambos fabricavam falsa
                    // precisão. Agora: sem Keyword Planner configurado, 'search_volume' vem
                    // null em vez de inventado.
                    'search_volume' => $volumeData['volume'],
                    'search_volume_source' => $volumeData['source'],
                    'competition' => $this->getKeywordCompetition($catKeyword)
                ];
            }
        }

        $analysis['score'] = $this->calculateKeywordScore($analysis);

        return $analysis;
    }

    // ========== OTIMIZAÇÕES ESPECÍFICAS ==========

    /**
     * Otimiza título para melhor SEO
     */
    private function optimizeTitle(array $productData, array $analysis): array
    {
        $currentTitle = $productData['title'] ?? '';
        $category = $productData['category_id'] ?? null;
        $brand = $productData['brand'] ?? '';

        // Extrair elementos importantes
        $importantKeywords = array_slice($analysis['detailed_analysis']['keywords_analysis']['primary_keywords'], 0, 3);
        $trendingKeywords = array_slice($analysis['detailed_analysis']['keywords_analysis']['trending_keywords'], 0, 2);

        // Construir novo título
        $titleParts = [];

        // Adicionar marca se não estiver
        if ($brand && stripos($currentTitle, $brand) === false) {
            $titleParts[] = $brand;
        }

        // Título base
        $baseTitle = trim(str_replace($brand, '', $currentTitle));
        $titleParts[] = $baseTitle;

        // Adicionar keywords trending se houver espaço
        foreach ($trendingKeywords as $keyword) {
            $testTitle = implode(' ', array_merge($titleParts, [$keyword]));
            if (mb_strlen($testTitle) <= 55) {
                $titleParts[] = $keyword;
            }
        }

        $optimizedTitle = implode(' ', $titleParts);

        // Limitar a 60 caracteres
        if (mb_strlen($optimizedTitle) > 60) {
            $optimizedTitle = mb_substr($optimizedTitle, 0, 57) . '...';
        }

        $expectedImprovement = $this->estimateTitleImprovement(
            $currentTitle,
            $optimizedTitle,
            $brand,
            $trendingKeywords
        );

        return [
            'original' => $currentTitle,
            'optimized' => $optimizedTitle,
            'changes_made' => [
                'added_brand' => $brand && stripos($currentTitle, $brand) === false,
                'added_keywords' => array_diff($titleParts, explode(' ', $currentTitle)),
                'length_adjusted' => mb_strlen($optimizedTitle) !== mb_strlen($currentTitle)
            ],
            'expected_improvement' => $expectedImprovement
        ];
    }

    /**
     * Otimiza descrição para SEO
     */
    private function optimizeDescription(array $productData, array $analysis): array
    {
        $currentDesc = $productData['description'] ?? '';
        $keywords = $analysis['detailed_analysis']['keywords_analysis']['primary_keywords'];

        // Se não há descrição, gerar uma
        if (empty($currentDesc)) {
            $aiContent = new AIContentGeneratorService();
            $generated = $aiContent->generateProductDescription($productData);

            return [
                'original' => $currentDesc,
                'optimized' => $generated['description'],
                'changes_made' => ['generated_new_description'],
                'expected_improvement' => 40
            ];
        }

        // Melhorar descrição existente
        $optimizedDesc = $currentDesc;
        $structureAdded = false;
        $ctaAdded = false;

        // Adicionar estrutura se não houver
        if (strpos($optimizedDesc, '•') === false && strpos($optimizedDesc, '-') === false) {
            $optimizedDesc .= "\n\n✨ CARACTERÍSTICAS PRINCIPAIS:\n";
            foreach ($productData['attributes'] ?? [] as $attr) {
                if (isset($attr['name']) && isset($attr['value'])) {
                    $optimizedDesc .= "• {$attr['name']}: {$attr['value']}\n";
                }
            }
            $structureAdded = true;
        }

        // Adicionar call-to-action se não houver
        if (!$analysis['detailed_analysis']['description_analysis']['call_to_action']) {
            $optimizedDesc .= "\n🛒 COMPRE AGORA e garanta o seu!";
            $ctaAdded = true;
        }

        $expectedImprovement = 0;
        if ($structureAdded) {
            $expectedImprovement += 10;
        }
        if ($ctaAdded) {
            $expectedImprovement += 5;
        }
        if (mb_strlen($optimizedDesc) > mb_strlen($currentDesc)) {
            $expectedImprovement += 5;
        }

        return [
            'original' => $currentDesc,
            'optimized' => $optimizedDesc,
            'changes_made' => ['added_structure', 'added_cta'],
            'expected_improvement' => max(10, min(30, $expectedImprovement))
        ];
    }

    // ========== CÁLCULOS E MÉTRICAS ==========

    /**
     * Calcula score geral de SEO
     */
    private function calculateOverallSEOScore(array $analyses): float
    {
        $weights = [
            'title_analysis' => 0.25,
            'description_analysis' => 0.20,
            'keywords_analysis' => 0.20,
            'attributes_analysis' => 0.15,
            'category_analysis' => 0.10,
            'images_analysis' => 0.05,
            'competition_analysis' => 0.05
        ];

        $score = 0;
        foreach ($weights as $analysis => $weight) {
            $score += ($analyses[$analysis]['score'] ?? 0) * $weight;
        }

        return round($score, 2);
    }

    /**
     * Identifica oportunidades de otimização
     */
    private function identifyOptimizationOpportunities(array $analyses): array
    {
        $opportunities = [];

        // Oportunidades baseadas nos scores
        foreach ($analyses as $type => $analysis) {
            if (($analysis['score'] ?? 0) < 70) {
                $opportunities[] = [
                    'type' => $type,
                    'current_score' => $analysis['score'] ?? 0,
                    'potential_improvement' => max(5, min(40, 100 - ($analysis['score'] ?? 0))),
                    'priority' => $analysis['score'] < 50 ? 'high' : 'medium',
                    'description' => $this->getOptimizationDescription($type)
                ];
            }
        }

        return $opportunities;
    }

    private function estimateTitleImprovement(string $currentTitle, string $optimizedTitle, ?string $brand, array $trendingKeywords): int
    {
        $scoreBefore = $this->scoreTitleLength($currentTitle);
        $scoreAfter = $this->scoreTitleLength($optimizedTitle);

        $improvement = max(0, $scoreAfter - $scoreBefore);

        if ($brand && stripos($currentTitle, $brand) === false && stripos($optimizedTitle, $brand) !== false) {
            $improvement += 8;
        }

        $keywordBoost = min(10, count($trendingKeywords) * 2);
        $improvement += $keywordBoost;

        return min(30, max(10, $improvement));
    }

    private function scoreTitleLength(string $title): int
    {
        $len = mb_strlen($title);
        $ideal = 60;
        $diff = abs($ideal - $len);

        if ($diff <= 5) return 20;
        if ($diff <= 10) return 15;
        if ($diff <= 20) return 10;
        return 5;
    }

    // ========== INICIALIZAÇÃO E CONFIGURAÇÃO ==========

    /**
     * Inicializa regras de SEO
     */
    private function initializeSEORules(): void
    {
        $this->seoRules = [
            'title' => [
                'min_length' => 30,
                'max_length' => 60,
                'required_elements' => ['brand', 'product_type'],
                'forbidden_words' => ['melhor', 'único', 'exclusivo', 'top']
            ],
            'description' => [
                'min_length' => 200,
                'max_length' => 50000,
                'required_elements' => ['bullets', 'cta', 'benefits'],
                'keyword_density' => ['min' => 0.02, 'max' => 0.08]
            ],
            'keywords' => [
                'primary_count' => 3,
                'secondary_count' => 5,
                'long_tail_count' => 10
            ]
        ];
    }

    /**
     * Carrega base de keywords dinâmica da API ML + cache
     * As keywords por categoria são carregadas on-demand via getCategoryKeywords()
     */
    private function loadKeywordDatabase(): void
    {
        // Tentar carregar do cache primeiro
        $cacheKey = 'keyword_database_categories';
        $cached = $this->cache->get($cacheKey, 'keywords');
        if ($cached && is_array($cached)) {
            $this->keywords = $cached;
            return;
        }

        try {
            // Buscar categorias populares do banco
            $stmt = $this->db->query("
                SELECT DISTINCT category_id
                FROM items
                WHERE category_id IS NOT NULL AND category_id != ''
                ORDER BY RAND()
                LIMIT 20
            ");
            $categoryIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $keywords = [];
            foreach ($categoryIds as $catId) {
                $catKeywords = $this->getCategoryKeywords($catId);
                if (!empty($catKeywords)) {
                    $keywords[$catId] = $catKeywords;
                }
            }

            if (!empty($keywords)) {
                $this->keywords = $keywords;
                $this->cache->set($cacheKey, $keywords, 'keywords', 43200); // 12h
                return;
            }
        } catch (\Exception $e) {
            // Tabela items pode não existir — ignorar
        }

        // Fallback mínimo caso nenhuma fonte funcione
        $this->keywords = [];
    }

    // ========== MÉTODOS AUXILIARES ==========

    private function createErrorResponse(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'analyzed_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Analisa SEO dos atributos do produto
     */
    private function analyzeAttributes(array $productData): array
    {
        $attributes = $productData['attributes'] ?? [];
        $analysis = [
            'total_attributes' => count($attributes),
            'filled_attributes' => 0,
            'required_filled' => 0,
            'optional_filled' => 0,
            'attribute_names' => [],
            'values_quality' => 0,
            'score' => 0,
            'issues' => [],
            'suggestions' => []
        ];

        foreach ($attributes as $attr) {
            if (!empty($attr['value_name']) || !empty($attr['value'])) {
                $analysis['filled_attributes']++;

                if ($attr['required'] ?? false) {
                    $analysis['required_filled']++;
                } else {
                    $analysis['optional_filled']++;
                }

                $analysis['attribute_names'][] = $attr['name'] ?? $attr['id'] ?? '';
            }
        }

        // Calculate values quality
        $qualityScore = 0;
        foreach ($attributes as $attr) {
            $value = $attr['value_name'] ?? $attr['value'] ?? '';
            if (!empty($value) && mb_strlen($value) > 2) {
                $qualityScore += 10;
            }
        }
        $analysis['values_quality'] = min(100, $qualityScore);

        // Calculate score
        $completionRatio = count($attributes) > 0 ? $analysis['filled_attributes'] / count($attributes) : 0;
        $analysis['score'] = (int)round(($completionRatio * 60) + ($analysis['values_quality'] * 0.4));

        if ($analysis['filled_attributes'] < count($attributes) * 0.8) {
            $analysis['issues'][] = 'Poucos atributos preenchidos';
            $analysis['suggestions'][] = 'Preencha mais atributos para melhor SEO';
        }

        return $analysis;
    }

    /**
     * Analisa otimização da categoria
     */
    private function analyzeCategoryOptimization(array $productData): array
    {
        $categoryId = $productData['category_id'] ?? null;

        // Check cache first
        $cacheKey = 'category_optimization_' . md5($categoryId . '_' . ($productData['id'] ?? ''));
        $cached = $this->cache->get($cacheKey, 'seo_category');
        if ($cached) {
            return $cached;
        }

        $analysis = [
            'category_id' => $categoryId,
            'category_name' => $productData['category_name'] ?? 'Desconhecida',
            'category_specific_requirements' => [],
            'compliance_score' => 0,
            'optimization_opportunities' => [],
            'score' => 0,
            'issues' => [],
            'suggestions' => []
        ];

        // For now, return a basic analysis - in production this would connect to category service
        $analysis['score'] = 75; // Default score
        $analysis['compliance_score'] = 80;

        // Cache the result
        $this->cache->set($cacheKey, $analysis, 'seo_category', 3600); // 1 hour

        return $analysis;
    }

    /**
     * Analisa SEO das imagens
     */
    private function analyzeImagesSEO(array $productData): array
    {
        $images = $productData['pictures'] ?? $productData['images'] ?? [];

        $analysis = [
            'total_images' => count($images),
            'high_quality_images' => 0,
            'alt_text_provided' => 0,
            'image_sizes_varied' => false,
            'image_optimization_score' => 0,
            'score' => 0,
            'issues' => [],
            'suggestions' => []
        ];

        $sizes = [];
        foreach ($images as $img) {
            if (isset($img['url']) || isset($img['secure_url'])) {
                $analysis['total_images']++;

                // Check if image is high quality (assuming URL patterns)
                $url = $img['url'] ?? $img['secure_url'] ?? '';
                if (strpos($url, 'quality') !== false || strpos($url, 'high') !== false) {
                    $analysis['high_quality_images']++;
                }

                // Check for alt text
                if (!empty($img['alt'])) {
                    $analysis['alt_text_provided']++;
                }

                // Store sizes for variety check
                if (isset($img['size'])) {
                    $sizes[] = $img['size'];
                }
            }
        }

        // Check if sizes are varied
        $analysis['image_sizes_varied'] = count(array_unique($sizes)) > 1;

        // Calculate scores
        $imageCountScore = min(50, $analysis['total_images'] * 10);
        $qualityScore = $analysis['total_images'] > 0 ? ($analysis['high_quality_images'] / $analysis['total_images']) * 30 : 0;
        $altScore = $analysis['total_images'] > 0 ? ($analysis['alt_text_provided'] / $analysis['total_images']) * 20 : 0;

        $analysis['image_optimization_score'] = (int)round($qualityScore + $altScore);
        $analysis['score'] = (int)round($imageCountScore + $analysis['image_optimization_score']);

        if ($analysis['total_images'] < 3) {
            $analysis['issues'][] = 'Poucas imagens (mínimo recomendado: 3)';
            $analysis['suggestions'][] = 'Adicione mais imagens do produto';
        }

        return $analysis;
    }

    /**
     * Analisa concorrência SEO
     */
    private function analyzeCompetition(array $productData): array
    {
        $title = $productData['title'] ?? '';
        $category = $productData['category_id'] ?? null;

        // Check cache first
        $cacheKey = 'competition_analysis_' . md5($title . '_' . $category);
        $cached = $this->cache->get($cacheKey, 'seo_competition');
        if ($cached) {
            return $cached;
        }

        try {
            // Prepare context for AI
            $context = [
                'title' => $title,
                'category' => $category,
                'product_data' => $productData
            ];

            // Build prompt for AI analysis
            $prompt = $this->buildCompetitionAnalysisPrompt($context);

            // Call AI with retry mechanism
            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em análise de concorrência e SEO. Analise o cenário competitivo para o produto fornecido.", 'advanced'),
                'analyze_competition',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Parse AI response
                $parsedResult = $this->parseCompetitionAnalysisResponse($aiResponse);

                // Cache the result
                $this->cache->set($cacheKey, $parsedResult, 'seo_competition', 10800); // 3 hours

                return $parsedResult;
            } else {
                // Fallback to basic analysis if AI fails
                return $this->analyzeCompetitionFallback($productData);
            }
        } catch (\Exception $e) {
            $this->logger->error('Competition analysis failed', [
                'error' => $e->getMessage(),
                'product_id' => $productData['id'] ?? null
            ]);

            return $this->analyzeCompetitionFallback($productData);
        }
    }

    /**
     * Build prompt for competition analysis
     */
    private function buildCompetitionAnalysisPrompt(array $context): string
    {
        return "Analise o cenário competitivo para este produto:

Título: {$context['title']}
Categoria: {$context['category']}

Critérios de análise:
1. Nível de concorrência (baixa, média, alta, muito alta)
2. Principais concorrentes
3. Estratégias de SEO utilizadas pelos concorrentes
4. Oportunidades de diferenciação
5. Preços médios de mercado
6. Volume de vendas estimado dos concorrentes
7. Pontos fortes e fracos da concorrência

Retorne um JSON com a seguinte estrutura:
{
  \"competition_level\": \"nível de concorrência\",
  \"top_competitors\": [\"concorrente1\", \"concorrente2\"],
  \"competitor_strategies\": [\"estratégia1\", \"estratégia2\"],
  \"differentiation_opportunities\": [\"oportunidade1\", \"oportunidade2\"],
  \"market_price_range\": {\"min\": valor, \"max\": valor, \"avg\": valor},
  \"estimated_sales_volume\": número estimado,
  \"competitor_strengths\": [\"força1\", \"força2\"],
  \"competitor_weaknesses\": [\"fraqueza1\", \"fraqueza2\"],
  \"score\": pontuação de 0-100
}";
    }

    /**
     * Parse AI response for competition analysis
     */
    private function parseCompetitionAnalysisResponse(string $aiResponse): array
    {
        // Try to extract JSON from AI response
        $jsonStart = strpos($aiResponse, '{');
        $jsonEnd = strrpos($aiResponse, '}');

        if ($jsonStart !== false && $jsonEnd !== false) {
            $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
            $parsed = json_decode($jsonStr, true);

            if ($parsed && is_array($parsed)) {
                return $parsed;
            }
        }

        // Fallback: return basic analysis
        return [
            'competition_level' => 'medium',
            'top_competitors' => ['Concorrente 1', 'Concorrente 2'],
            'competitor_strategies' => ['Preço competitivo', 'Bom SEO'],
            'differentiation_opportunities' => ['Melhor qualidade', 'Garantia estendida'],
            'market_price_range' => ['min' => 100, 'max' => 500, 'avg' => 250],
            'estimated_sales_volume' => 1000,
            'competitor_strengths' => ['Bom posicionamento', 'Preço baixo'],
            'competitor_weaknesses' => ['Poucas avaliações', 'Imagens de baixa qualidade'],
            'score' => 70
        ];
    }

    /**
     * Fallback competition analysis
     */
    private function analyzeCompetitionFallback(array $productData): array
    {
        return [
            'competition_level' => 'medium',
            'top_competitors' => [],
            'competitor_strategies' => [],
            'differentiation_opportunities' => [],
            'market_price_range' => ['min' => 0, 'max' => 0, 'avg' => 0],
            'estimated_sales_volume' => 0,
            'competitor_strengths' => [],
            'competitor_weaknesses' => [],
            'score' => 60
        ];
    }

    /**
     * Analisa compatibilidade com algoritmo do marketplace
     */
    private function analyzeMLAlgorithmCompatibility(array $productData): array
    {
        $title = $productData['title'] ?? '';
        $description = $productData['description'] ?? '';
        $attributes = $productData['attributes'] ?? [];

        $analysis = [
            'algorithm_compatibility_score' => 0,
            'relevance_factors' => [],
            'engagement_potential' => 0,
            'conversion_factors' => [],
            'score' => 0,
            'issues' => [],
            'suggestions' => []
        ];

        // Calculate relevance factors
        $relevanceScore = 0;

        // Title length (good for algorithm)
        if (mb_strlen($title) >= 30 && mb_strlen($title) <= 60) {
            $relevanceScore += 20;
            $analysis['relevance_factors'][] = 'Título com comprimento ideal';
        } else {
            $analysis['issues'][] = 'Título fora do comprimento ideal (30-60 chars)';
            $analysis['suggestions'][] = 'Ajuste o comprimento do título';
        }

        // Description length (good for algorithm)
        if (mb_strlen($description) >= 200) {
            $relevanceScore += 15;
            $analysis['relevance_factors'][] = 'Descrição com bom comprimento';
        } else {
            $analysis['issues'][] = 'Descrição muito curta';
            $analysis['suggestions'][] = 'Expanda a descrição do produto';
        }

        // Attribute completeness
        $attrCompleteness = count($attributes) > 0 ? (count(array_filter($attributes, function ($attr) {
            return !empty($attr['value_name']) || !empty($attr['value']);
        })) / count($attributes)) * 100 : 0;

        if ($attrCompleteness >= 80) {
            $relevanceScore += 25;
            $analysis['relevance_factors'][] = 'Atributos bem preenchidos';
        } else {
            $analysis['issues'][] = 'Atributos incompletos';
            $analysis['suggestions'][] = 'Preencha mais atributos do produto';
        }

        // Engagement potential based on keywords and structure
        $engagementPotential = 0;
        $keywords = $this->extractKeywordsFromText($title . ' ' . $description);
        $engagementPotential = min(40, count($keywords) * 5);

        $analysis['algorithm_compatibility_score'] = (int)round($relevanceScore * 0.7 + $engagementPotential * 0.3);
        $analysis['engagement_potential'] = (int)round($engagementPotential);
        $analysis['score'] = $analysis['algorithm_compatibility_score'];

        return $analysis;
    }

    /**
     * Gets category-specific keywords
     */
    private function getCategoryKeywords(?string $category): array
    {
        if (!$category) {
            return [];
        }

        // Check cache first
        $cacheKey = 'category_keywords_' . md5($category);
        $cached = $this->cache->get($cacheKey, 'keywords');
        if ($cached) {
            return $cached;
        }

        try {
            // Use AI to get category-specific keywords
            $prompt = "Para a categoria {$category}, liste as palavras-chave mais importantes e relevantes para SEO em português brasileiro. Retorne apenas um array JSON com as palavras-chave.";

            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em SEO e pesquisa de palavras-chave para marketplaces.", 'advanced'),
                'get_category_keywords',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Try to extract keywords from response
                $jsonStart = strpos($aiResponse, '[');
                $jsonEnd = strrpos($aiResponse, ']');

                if ($jsonStart !== false && $jsonEnd !== false) {
                    $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
                    $keywords = json_decode($jsonStr, true);

                    if (is_array($keywords)) {
                        $keywords = array_slice($keywords, 0, 10); // Limit to 10 keywords

                        // Cache the result
                        $this->cache->set($cacheKey, $keywords, 'keywords', 86400); // 24 hours

                        return $keywords;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Category keywords fetch failed', [
                'error' => $e->getMessage(),
                'category' => $category
            ]);
        }

        return [];
    }

    /**
     * Gets forbidden words for marketplace
     * Now fetches REAL data from ML API or cache
     */
    private function getForbiddenWords(): array
    {
        // Check cache first (24h TTL)
        $cacheKey = 'ml_forbidden_words_official';
        $cached = $this->cache->get($cacheKey, 'seo_config');
        if ($cached && is_array($cached)) {
            return $cached;
        }

        try {
            // Try to fetch from ML API (if available) or use enhanced fallback
            $forbiddenWords = $this->fetchForbiddenWordsFromML();

            if (!empty($forbiddenWords)) {
                // Cache for 24 hours
                $this->cache->set($cacheKey, $forbiddenWords, 'seo_config', 86400);
                return $forbiddenWords;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch forbidden words from ML', [
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Fetches official forbidden words from ML or regulations
     * This method attempts to get real data from ML API
     */
    private function fetchForbiddenWordsFromML(): array
    {
        // ML doesn't have a public API for forbidden words list
        // But we can use AI to compile from official regulations + recent rejections

        try {
            $prompt = "Liste as palavras e frases PROIBIDAS no Mercado Livre Brasil segundo as políticas oficiais de 2024-2026.
            Inclua:
            1. Superlativos não comprovados
            2. Termos de urgência falsa
            3. Garantias não verificáveis
            4. Comparações diretas com concorrentes
            5. Termos médicos sem comprovação

            Retorne APENAS um array JSON com as expressões, sem explicações:
            [\"termo1\", \"termo2\", ...]";

            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em políticas do Mercado Livre e compliance.", 'advanced'),
                'fetch_forbidden_words',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Extract JSON array from response
                $jsonStart = strpos($aiResponse, '[');
                $jsonEnd = strrpos($aiResponse, ']');

                if ($jsonStart !== false && $jsonEnd !== false) {
                    $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
                    $parsed = json_decode($jsonStr, true);

                    if (is_array($parsed) && count($parsed) > 10) {
                        $this->logger->info('Successfully fetched forbidden words from AI', [
                            'count' => count($parsed)
                        ]);
                        return $parsed;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('AI fetch for forbidden words failed', [
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    private function calculateTextReadability(string $text): float
    {
        $sentences = preg_split('/[.!?]+/', $text);
        $sentences = array_filter(array_map('trim', $sentences));
        $words = preg_split('/\s+/', trim($text));
        $wordCount = count(array_filter($words));
        $sentenceCount = max(1, count($sentences));
        $avgWords = $wordCount / $sentenceCount;
        $score = 100 - min(100, $avgWords * 1.5);
        return max(0, $score);
    }

    /**
     * Calculates title SEO score
     */
    private function calculateTitleScore(array $analysis): int
    {
        $score = 50; // Base score

        // Length bonus
        if ($analysis['length'] >= 30 && $analysis['length'] <= 60) {
            $score += 20;
        } elseif ($analysis['length'] >= 20 && $analysis['length'] <= 70) {
            $score += 10;
        }

        // Keywords bonus
        if (!empty($analysis['keywords_found'])) {
            $score += min(15, count($analysis['keywords_found']) * 5);
        }

        // Issues penalty
        $score -= count($analysis['issues']) * 10;

        // Readability bonus
        if ($analysis['readability'] >= 70) {
            $score += 15;
        }

        return max(0, min(100, $score));
    }

    /**
     * Calculates description SEO score
     */
    private function calculateDescriptionScore(array $analysis): int
    {
        $score = 50; // Base score

        // Length bonus
        if ($analysis['description_length'] >= 200 && $analysis['description_length'] <= 5000) {
            $score += 20;
        } elseif ($analysis['description_length'] >= 100) {
            $score += 10;
        }

        // Structure bonus
        if ($analysis['structure_score'] >= 50) {
            $score += $analysis['structure_score'] * 0.3;
        }

        // Readability bonus
        if ($analysis['readability_score'] >= 60) {
            $score += $analysis['readability_score'] * 0.2;
        }

        // Call-to-action bonus
        if ($analysis['call_to_action']) {
            $score += 10;
        }

        // Bullets bonus
        if ($analysis['bullets_found'] >= 3) {
            $score += min(15, $analysis['bullets_found'] * 3);
        }

        return max(0, min(100, (int)round($score)));
    }

    /**
     * Extracts keywords from text
     */
    private function extractKeywordsFromText(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        // Remove punctuation and convert to lowercase
        $cleanText = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($text));

        // Split into words
        $words = preg_split('/\s+/', $cleanText, -1, PREG_SPLIT_NO_EMPTY);

        // Filter out stop words and short words
        $stopWords = ['a', 'o', 'e', 'é', 'de', 'da', 'do', 'em', 'um', 'uma', 'para', 'com', 'não', 'na', 'no', 'se', 'que', 'por', 'mais', 'as', 'os', 'como', 'mas', 'foi', 'ao', 'ele', 'das', 'tem', 'à', 'seu', 'sua', 'ou', 'ser', 'quando', 'muito', 'há', 'nos', 'já', 'está', 'eu', 'também', 'só', 'pelo', 'pela', 'até', 'isso', 'ela', 'entre', 'era', 'depois', 'sem', 'mesmo', 'aos', 'ter', 'seus', 'quem', 'nas', 'me', 'esse', 'eles', 'estão', 'você', 'tinha', 'foram', 'essa', 'num', 'nem', 'suas', 'meu', 'minha', 'te', 'tu', 'vocês', 'nosso', 'nossa', 'dela', 'delas', 'esse', 'esses', 'essa', 'essas', 'isto', 'aquilo'];

        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) > 2 && !in_array($word, $stopWords)) {
                $keywords[] = $word;
            }
        }

        // Return unique keywords
        return array_slice(array_unique($keywords), 0, 10);
    }

    /**
     * Calculates keyword density
     */
    private function calculateKeywordDensity(string $text, array $keywords): array
    {
        if (empty($text) || empty($keywords)) {
            return ['density' => 0.0];
        }

        $wordCount = str_word_count($text);
        if ($wordCount === 0) {
            return ['density' => 0.0];
        }

        $keywordCount = 0;
        $textLower = mb_strtolower($text);

        foreach ($keywords as $keyword) {
            $keywordLower = mb_strtolower($keyword);
            $keywordCount += substr_count($textLower, $keywordLower);
        }

        $density = $wordCount > 0 ? ($keywordCount / $wordCount) * 100 : 0;

        return [
            'density' => round($density, 2),
            'keyword_count' => $keywordCount,
            'total_words' => $wordCount
        ];
    }

    /**
     * Gets competitor keywords
     */
    private function getCompetitorKeywords(array $product): array
    {
        $title = $product['title'] ?? '';
        $category = $product['category_id'] ?? null;

        // Check cache first
        $cacheKey = 'competitor_keywords_' . md5($title . '_' . $category);
        $cached = $this->cache->get($cacheKey, 'keywords');
        if ($cached) {
            return $cached;
        }

        try {
            // Use AI to analyze competitor keywords
            $prompt = "Baseado no título '{$title}' e categoria '{$category}', quais seriam as palavras-chave mais usadas pelos concorrentes para SEO? Liste as 5-10 principais palavras-chave usadas na concorrência.";

            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em SEO e análise de concorrência de marketplaces.", 'advanced'),
                'get_competitor_keywords',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Extract keywords from response
                $keywords = $this->extractKeywordsFromText($aiResponse);
                $keywords = array_slice($keywords, 0, 10);

                // Cache the result
                $this->cache->set($cacheKey, $keywords, 'keywords', 7200); // 2 hours

                return $keywords;
            }
        } catch (\Exception $e) {
            $this->logger->error('Competitor keywords fetch failed', [
                'error' => $e->getMessage(),
                'title' => $title,
                'category' => $category
            ]);
        }

        // Fallback
        return ['promoção', 'oferta', 'desconto', 'original', 'garantia', 'entrega rápida'];
    }

    /**
     * Gets trending keywords for category
     */
    private function getTrendingKeywords(?string $category): array
    {
        if (!$category) {
            return ['2024', 'novo', 'lançamento'];
        }

        // Check cache first
        $cacheKey = 'trending_keywords_' . md5($category);
        $cached = $this->cache->get($cacheKey, 'keywords');
        if ($cached) {
            return $cached;
        }

        try {
            // Use AI to get trending keywords
            $prompt = "Quais são as palavras-chave em tendência para a categoria {$category} em 2024? Liste as 5-8 palavras-chave mais relevantes e em alta.";

            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em tendências de mercado e SEO para marketplaces.", 'advanced'),
                'get_trending_keywords',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Extract keywords from response
                $keywords = $this->extractKeywordsFromText($aiResponse);
                $keywords = array_slice($keywords, 0, 8);

                // Cache the result
                $this->cache->set($cacheKey, $keywords, 'keywords', 43200); // 12 hours

                return $keywords;
            }
        } catch (\Exception $e) {
            $this->logger->error('Trending keywords fetch failed', [
                'error' => $e->getMessage(),
                'category' => $category
            ]);
        }

        return [];
    }

    /**
     * Calculates keyword importance
     */
    private function calculateKeywordImportance(string $keyword, ?string $category): float
    {
        if (!$category) {
            return 0.5;
        }

        // Check cache first
        $cacheKey = 'keyword_importance_' . md5($keyword . '_' . $category);
        $cached = $this->cache->get($cacheKey, 'keywords');
        if ($cached !== null) {
            return (float)($cached['value'] ?? $cached);
        }

        try {
            // Use AI to calculate keyword importance
            $prompt = "Na categoria {$category}, qual é a importância da palavra-chave '{$keyword}' em uma escala de 0 a 1 (onde 1 é muito importante)? Responda apenas com um número decimal.";

            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em SEO e análise de palavras-chave para marketplaces.", 'basic'),
                'calculate_keyword_importance',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = trim($result['content']);

                // Extract number from response
                if (is_numeric($aiResponse)) {
                    $importance = (float)$aiResponse;
                    $importance = max(0, min(1, $importance)); // Clamp between 0 and 1

                    // Cache the result
                    $this->cache->set($cacheKey, $importance, 'keywords', 86400); // 24 hours

                    return $importance;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Keyword importance calculation failed', [
                'error' => $e->getMessage(),
                'keyword' => $keyword,
                'category' => $category
            ]);
        }

        // Fallback: calculate based on keyword characteristics
        $importance = 0.5; // Base importance

        // Boost for certain patterns
        if (mb_strlen($keyword) > 3 && mb_strlen($keyword) < 20) {
            $importance += 0.1;
        }

        if (preg_match('/\d/', $keyword)) { // Contains numbers
            $importance += 0.1;
        }

        if (in_array($keyword, ['novo', 'original', 'garantia', 'entrega'])) {
            $importance += 0.2;
        }

        return max(0, min(1, $importance));
    }

    /**
     * Calculates keyword score
     */
    private function calculateKeywordScore(array $analysis): int
    {
        $score = 20; // Base score

        // Primary keywords bonus
        $score += min(30, count($analysis['primary_keywords']) * 10);

        // Secondary keywords bonus
        $score += min(20, count($analysis['secondary_keywords']) * 5);

        // Long-tail keywords bonus
        $score += min(15, count($analysis['long_tail_keywords']) * 3);

        // Opportunity bonus
        $score += min(15, count($analysis['keyword_opportunities']) * 2);

        return max(0, min(100, $score));
    }

    /**
     * Gets keyword search volume
     */
    /**
     * Correção de metodologia: a versão anterior, quando o Google Keyword Planner
     * não estava configurado, pedia para uma IA generativa "estimar" um número
     * inteiro de volume de busca (a IA não tem acesso a dados reais de busca —
     * isso é alucinação apresentada como estimativa), e se isso falhasse, caía
     * numa heurística baseada no tamanho da palavra-chave (fabricação sem base
     * estatística). Ambos os fallbacks foram removidos. Agora só existe dado real
     * (Google Keyword Planner) ou 'null' explícito — nunca mais um número
     * fabricado apresentado como se fosse volume de busca medido.
     *
     * @return array{volume: int|null, source: string}
     */
    private function getKeywordSearchVolumeData(string $keyword): array
    {
        $cacheKey = 'keyword_volume_' . md5($keyword);
        $cached = $this->cache->get($cacheKey, 'keywords');
        if (is_array($cached) && array_key_exists('value', $cached)) {
            return ['volume' => $cached['value'], 'source' => $cached['source'] ?? 'unknown'];
        }

        try {
            if ($this->keywordPlanner->isConfigured()) {
                $metrics = $this->keywordPlanner->getKeywordMetrics($keyword, 'BR', 'pt');

                if ($metrics && isset($metrics['volume'])) {
                    $volume = (int)$metrics['volume'];

                    $this->cache->set($cacheKey, ['value' => $volume, 'source' => 'google_keyword_planner'], 'keywords', 172800); // 48 hours

                    $this->logger->info('seo', "Google Keyword Planner: Real volume data for '{$keyword}': {$volume}");
                    return ['volume' => $volume, 'source' => 'google_keyword_planner'];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Keyword volume lookup failed', [
                'error' => $e->getMessage(),
                'keyword' => $keyword
            ]);
        }

        // Sem Keyword Planner configurado ou sem dado disponível: não fabricar
        // substituto. Cache curto (1h) para não bater na API repetidamente.
        $this->cache->set($cacheKey, ['value' => null, 'source' => 'unavailable'], 'keywords', 3600);
        return ['volume' => null, 'source' => 'unavailable'];
    }

    /**
     * Gets keyword competition level
     */
    private function getKeywordCompetition(string $keyword): string
    {
        // Check cache first
        $cacheKey = 'keyword_competition_' . md5($keyword);
        $cached = $this->cache->get($cacheKey, 'keywords');
        if ($cached) {
            return (string)($cached['value'] ?? $cached);
        }

        try {
            // Try Google Keyword Planner API first (REAL DATA)
            if ($this->keywordPlanner->isConfigured()) {
                $metrics = $this->keywordPlanner->getKeywordMetrics($keyword, 'BR', 'pt');

                if ($metrics && isset($metrics['competition'])) {
                    $competition = $metrics['competition'];

                    // Cache the result
                    $this->cache->set($cacheKey, ['value' => $competition, 'source' => 'google_keyword_planner'], 'keywords', 172800); // 48 hours

                    $this->logger->info('seo', "Google Keyword Planner: Real competition data for '{$keyword}': {$competition}");
                    return $competition;
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('Keyword competition lookup failed', [
                'error' => $e->getMessage(),
                'keyword' => $keyword
            ]);
        }

        // Correção de metodologia: os fallbacks anteriores (IA "chutando" um nível de
        // concorrência sem dados reais, e heurística por tamanho da palavra) foram
        // removidos. Sem Keyword Planner configurado, retorna explicitamente
        // 'desconhecida' em vez de fabricar uma classificação.
        $this->cache->set($cacheKey, ['value' => 'desconhecida', 'source' => 'unavailable'], 'keywords', 3600);
        return 'desconhecida';
    }

    /**
     * Optimizes keywords for product
     */
    private function optimizeKeywords(array $product, array $analysis): array
    {
        $title = $product['title'] ?? '';
        $description = $product['description'] ?? '';
        $category = $product['category_id'] ?? null;

        // Check cache first
        $cacheKey = 'keyword_optimization_' . md5($title . '_' . $description . '_' . $category);
        $cached = $this->cache->get($cacheKey, 'keywords');
        if ($cached) {
            return $cached;
        }

        try {
            // Use AI to optimize keywords
            $prompt = "Com base no título: '{$title}', descrição: '{$description}' e categoria: '{$category}', sugira uma lista de palavras-chave otimizadas para SEO. Considere as oportunidades identificadas: " . json_encode($analysis['keyword_opportunities'] ?? []) . ". Retorne um JSON com as palavras-chave sugeridas e sua prioridade.";

            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em SEO e otimização de palavras-chave para marketplaces.", 'advanced'),
                'optimize_keywords',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Try to extract JSON from response
                $jsonStart = strpos($aiResponse, '{');
                $jsonEnd = strrpos($aiResponse, '}');

                if ($jsonStart !== false && $jsonEnd !== false) {
                    $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
                    $parsed = json_decode($jsonStr, true);

                    if ($parsed && is_array($parsed)) {
                        // Cache the result
                        $this->cache->set($cacheKey, $parsed, 'keywords', 7200); // 2 hours

                        return $parsed;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Keyword optimization failed', [
                'error' => $e->getMessage(),
                'product_id' => $product['id'] ?? null
            ]);
        }

        $existing = $this->extractKeywordsFromText($title . ' ' . $description);
        $candidates = array_values(array_unique(array_filter(array_merge(
            $analysis['primary_keywords'] ?? [],
            $analysis['secondary_keywords'] ?? [],
            $this->getCategoryKeywords($category),
            $this->getTrendingKeywords($category)
        ))));

        $missing = array_values(array_diff($candidates, $existing));
        $added = array_slice($missing, 0, 5);
        $priority = array_slice($candidates, 0, 3);

        $opportunities = [];
        foreach ($missing as $keyword) {
            $opportunities[] = [
                'keyword' => $keyword,
                'context' => 'category_trend'
            ];
        }

        return [
            'added' => $added,
            'optimized' => $opportunities,
            'priority_keywords' => $priority
        ];
    }

    /**
     * Optimizes attributes for SEO
     */
    private function optimizeAttributes(array $product, array $analysis): array
    {
        $attributes = $product['attributes'] ?? [];
        $improvements = 0;
        $suggestions = [];

        foreach ($attributes as $attr) {
            if (empty($attr['value_name']) && empty($attr['value'])) {
                $suggestions[] = "Preencher atributo: " . ($attr['name'] ?? $attr['id'] ?? 'desconhecido');
                $improvements++;
            }
        }

        return [
            'improved' => $improvements,
            'suggestions' => $suggestions,
            'total_attributes' => count($attributes),
            'filled_attributes' => count(array_filter($attributes, function ($attr) {
                return !empty($attr['value_name']) || !empty($attr['value']);
            }))
        ];
    }

    /**
     * Applies optimizations to product
     */
    private function applyOptimizations(array $product, array $optimizations): array
    {
        $optimizedProduct = $product;

        // Apply title optimization if available
        if (isset($optimizations['title']['optimized'])) {
            $optimizedProduct['title'] = $optimizations['title']['optimized'];
        }

        // Apply description optimization if available
        if (isset($optimizations['description']['optimized'])) {
            $optimizedProduct['description'] = $optimizations['description']['optimized'];
        }

        // Apply attribute optimizations if available
        if (isset($optimizations['attributes']['suggestions'])) {
            // This would typically involve updating the product attributes
            $optimizedProduct['optimization_notes'] = $optimizations['attributes']['suggestions'];
        }

        // Apply keyword optimizations if available
        if (isset($optimizations['keywords']['priority_keywords'])) {
            $optimizedProduct['priority_keywords'] = $optimizations['keywords']['priority_keywords'];
        }

        return $optimizedProduct;
    }

    /**
     * Calculates visibility increase
     */
    private function calculateVisibilityIncrease(float $before, float $after): int
    {
        $difference = $after - $before;
        // Convert score difference to estimated visibility percentage
        // Assuming 10 points = ~15% visibility increase
        return (int)round(abs($difference) * 1.5);
    }

    /**
     * Generates optimization summary
     */
    private function generateOptimizationSummary(array $optimizations): string
    {
        $summaryParts = [];

        if (isset($optimizations['title'])) {
            $summaryParts[] = "Título otimizado";
        }

        if (isset($optimizations['description'])) {
            $summaryParts[] = "Descrição aprimorada";
        }

        if (isset($optimizations['keywords']['priority_keywords'])) {
            $count = count($optimizations['keywords']['priority_keywords']);
            $summaryParts[] = "{$count} palavras-chave prioritárias adicionadas";
        }

        if (isset($optimizations['attributes']['improved'])) {
            $improved = $optimizations['attributes']['improved'];
            $summaryParts[] = "{$improved} atributos melhorados";
        }

        return !empty($summaryParts) ? implode(', ', $summaryParts) : 'Nenhuma otimização significativa aplicada';
    }

    /**
     * Generates action plan
     */
    private function generateActionPlan(array $analyses, array $opportunities): array
    {
        $actionPlan = [];

        // Prioritize opportunities by severity
        usort($opportunities, function ($a, $b) {
            $priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
            return ($priorityOrder[$b['priority']] ?? 0) - ($priorityOrder[$a['priority']] ?? 0);
        });

        foreach ($opportunities as $opportunity) {
            $actionPlan[] = [
                'action' => $opportunity['description'] ?? 'Melhoria necessária',
                'priority' => $opportunity['priority'] ?? 'medium',
                'type' => $opportunity['type'] ?? 'general',
                'estimated_impact' => $opportunity['potential_improvement'] ?? 10
            ];
        }

        // Add specific actions based on analysis
        if (isset($analyses['title_analysis']['issues']) && count($analyses['title_analysis']['issues']) > 0) {
            $actionPlan[] = [
                'action' => 'Corrigir problemas no título',
                'priority' => 'high',
                'type' => 'title',
                'details' => $analyses['title_analysis']['issues']
            ];
        }

        if (isset($analyses['description_analysis']['issues']) && count($analyses['description_analysis']['issues']) > 0) {
            $actionPlan[] = [
                'action' => 'Melhorar descrição do produto',
                'priority' => 'medium',
                'type' => 'description',
                'details' => $analyses['description_analysis']['issues']
            ];
        }

        return $actionPlan;
    }

    /**
     * Predicts optimization impact
     */
    private function predictOptimizationImpact(array $analyses, array $actionPlan, array $productData = []): array
    {
        $impactFactor = 1.0;

        // 1. Try to use Advanced Predictive Analytics via Unified AI
        if (!empty($productData) && isset($this->unifiedAi)) {
            try {
                // Call predictive analytics
                $prediction = $this->unifiedAi->processAIRequest('predict_performance', $productData);

                if ($prediction['success'] && isset($prediction['result']['predictions'])) {
                    // Adjust impact factor based on predicted demand growth
                    $demandForecast = $prediction['result']['predictions']['demand_forecast'] ?? [];
                    if (!empty($demandForecast)) {
                        // Check trend
                        $first = reset($demandForecast);
                        $last = end($demandForecast);
                        if ($last['value'] > $first['value']) {
                            $growth = ($last['value'] - $first['value']) / $first['value'];
                            $impactFactor += $growth; // Boost impact if market is growing
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silent fail, fallback to heuristics
            }
        }

        $visibilityIncrease = 0;
        $rankingImprovement = 0;

        // Calculate based on action plan priorities
        foreach ($actionPlan as $action) {
            $impact = $action['estimated_impact'] ?? 10;
            $priorityMultiplier = $action['priority'] === 'high' ? 1.5 : ($action['priority'] === 'medium' ? 1.0 : 0.5);

            $visibilityIncrease += $impact * $priorityMultiplier * 0.3;
            $rankingImprovement += ($impact * $priorityMultiplier * 0.1);
        }

        // Apply ML Prediction Factor
        $visibilityIncrease *= $impactFactor;

        $rankingPositions = max(1, (int)round($rankingImprovement / 2));
        $rankingRange = $rankingPositions . '-' . max($rankingPositions + 1, (int)ceil($rankingImprovement));

        return [
            'visibility_increase' => round($visibilityIncrease) . '%',
            'ranking_improvement' => $rankingRange . ' posições',
            'conversion_potential' => round($visibilityIncrease * 0.7) . '% aumento estimado',
            'confidence_level' => $impactFactor > 1.0 ? 'high' : 'medium',
            'ml_factor' => round($impactFactor, 2)
        ];
    }

    /**
     * Gets competitor benchmarks
     * Now fetches REAL competitors from ML API and calculates actual metrics
     */
    private function getCompetitorBenchmarks(array $product): array
    {
        $category = $product['category_id'] ?? null;
        $title = $product['title'] ?? '';

        if (!$category) {
            return $this->getCompetitorBenchmarksFallback();
        }

        // Check cache first (6h TTL)
        $cacheKey = 'competitor_benchmarks_' . md5($category . '_' . mb_substr($title, 0, 30));
        $cached = $this->cache->get($cacheKey, 'seo_competition');
        if ($cached && is_array($cached)) {
            return $cached;
        }

        try {
            // Fetch REAL competitors from ML API
            $competitors = $this->fetchRealCompetitors($category, $title);

            if (!empty($competitors) && count($competitors) >= 3) {
                // Calculate REAL benchmarks from actual competitor data
                $benchmarks = $this->calculateRealBenchmarks($competitors);

                // Cache the result for 6 hours
                $this->cache->set($cacheKey, $benchmarks, 'seo_competition', 21600);

                $this->logger->info('Calculated real competitor benchmarks', [
                    'category' => $category,
                    'competitors_analyzed' => count($competitors),
                    'avg_title_length' => $benchmarks['benchmark_metrics']['title_length_avg']
                ]);

                return $benchmarks;
            }
        } catch (\Exception $e) {
            $this->logger->error('Real competitor benchmarks fetch failed', [
                'error' => $e->getMessage(),
                'category' => $category
            ]);
        }

        // Fallback to AI-based estimation if ML API fails
        return $this->getCompetitorBenchmarksFallback($category, $title);
    }

    /**
     * Fetches REAL top competitors from ML search API
     */
    private function fetchRealCompetitors(string $category, string $title): array
    {
        try {
            $accountId = $_SESSION['active_ml_account_id'] ?? null;
            if (!$accountId) {
                $this->logger->warning('No active ML account for competitor search', []);
                return [];
            }

            $mlClient = new MercadoLivreClient($accountId);

            // Extract main keywords from title for search
            $keywords = $this->extractMainKeywords($title);
            $searchQuery = implode(' ', array_slice($keywords, 0, 3)); // Top 3 keywords

            // Search for competitors in same category
            $searchParams = [
                'q' => $searchQuery,
                'category' => $category,
                'limit' => 20,
                'sort' => 'relevance',
                'buying_mode' => 'buy_it_now',
                'official_store_id' => 'all'
            ];

            $response = $mlClient->get('/sites/MLB/search', $searchParams);

            if (!$response['success'] || empty($response['body']['results'])) {
                $this->logger->warning('ML search returned no competitors', [
                    'query' => $searchQuery,
                    'category' => $category
                ]);
                return [];
            }

            $competitors = [];
            foreach ($response['body']['results'] as $item) {
                // Skip if missing essential data
                if (empty($item['title']) || empty($item['id'])) {
                    continue;
                }

                $competitors[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'price' => $item['price'] ?? 0,
                    'sold_quantity' => $item['sold_quantity'] ?? 0,
                    'thumbnail' => $item['thumbnail'] ?? '',
                    'condition' => $item['condition'] ?? 'new',
                    'seller_reputation' => $item['seller']['seller_reputation']['level_id'] ?? null,
                    'shipping_free' => $item['shipping']['free_shipping'] ?? false,
                    'official_store_id' => $item['official_store_id'] ?? null,
                    'permalink' => $item['permalink'] ?? ''
                ];

                // Limit to top 10 competitors
                if (count($competitors) >= 10) {
                    break;
                }
            }

            $this->logger->info('Fetched real competitors from ML', [
                'category' => $category,
                'query' => $searchQuery,
                'found' => count($competitors)
            ]);

            return $competitors;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch real competitors', [
                'error' => $e->getMessage(),
                'category' => $category
            ]);
            return [];
        }
    }

    /**
     * Calculates REAL benchmarks from actual competitor data
     */
    private function calculateRealBenchmarks(array $competitors): array
    {
        if (empty($competitors)) {
            return $this->getCompetitorBenchmarksFallback();
        }

        $titleLengths = [];
        $prices = [];
        $soldQuantities = [];
        $freeShippingCount = 0;
        $officialStoreCount = 0;
        $topReputationCount = 0;

        foreach ($competitors as $comp) {
            $titleLengths[] = mb_strlen($comp['title']);
            $prices[] = $comp['price'];
            $soldQuantities[] = $comp['sold_quantity'];

            if ($comp['shipping_free']) {
                $freeShippingCount++;
            }

            if ($comp['official_store_id']) {
                $officialStoreCount++;
            }

            if (in_array($comp['seller_reputation'], ['5_green', '4_light_green'])) {
                $topReputationCount++;
            }
        }

        $count = count($competitors);
        $avgTitleLength = $count > 0 ? round(array_sum($titleLengths) / $count) : 45;
        $avgPrice = $count > 0 ? round(array_sum($prices) / $count, 2) : 0;
        $avgSoldQuantity = $count > 0 ? round(array_sum($soldQuantities) / $count) : 0;

        // Calculate percentages
        $freeShippingPercent = $count > 0 ? round(($freeShippingCount / $count) * 100) : 0;
        $officialStorePercent = $count > 0 ? round(($officialStoreCount / $count) * 100) : 0;
        $topReputationPercent = $count > 0 ? round(($topReputationCount / $count) * 100) : 0;

        // Estimate average score based on competitor quality
        $estimatedAvgScore = 60; // Base
        if ($topReputationPercent > 50) $estimatedAvgScore += 15;
        if ($freeShippingPercent > 70) $estimatedAvgScore += 10;
        if ($officialStorePercent > 30) $estimatedAvgScore += 5;

        return [
            'average_score' => min(100, $estimatedAvgScore),
            'top_competitor' => min(100, $estimatedAvgScore + 15),
            'benchmark_metrics' => [
                'title_length_avg' => (int)$avgTitleLength,
                'title_length_min' => !empty($titleLengths) ? min($titleLengths) : 30,
                'title_length_max' => !empty($titleLengths) ? max($titleLengths) : 60,
                'price_avg' => $avgPrice,
                'price_min' => !empty($prices) ? min($prices) : 0,
                'price_max' => !empty($prices) ? max($prices) : 0,
                'sold_quantity_avg' => $avgSoldQuantity,
                'free_shipping_percent' => $freeShippingPercent,
                'official_store_percent' => $officialStorePercent,
                'top_reputation_percent' => $topReputationPercent
            ],
            'competitor_count' => $count,
            'data_source' => 'ml_api_real',
            'fetched_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Extracts main keywords from title for competitor search
     */
    private function extractMainKeywords(string $title): array
    {
        // Remove common stop words and get meaningful keywords
        $stopWords = ['de', 'da', 'do', 'dos', 'das', 'a', 'o', 'e', 'para', 'com', 'em', 'por', 'no', 'na'];

        $words = explode(' ', mb_strtolower($title));
        $keywords = [];

        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) > 3 && !in_array($word, $stopWords)) {
                $keywords[] = $word;
            }
        }

        return array_slice($keywords, 0, 5); // Return top 5
    }

    /**
     * Fallback for competitor benchmarks (AI or default)
     */
    private function getCompetitorBenchmarksFallback(?string $category = null, ?string $title = null): array
    {
        // Try AI if we have context
        if ($category && $title) {
            try {
                $prompt = "Para produtos na categoria '{$category}' com título similar a '{$title}', quais são os benchmarks típicos de SEO? Inclua score médio, melhores práticas e métricas de referência. Retorne um JSON com as informações.";

                $result = $this->retryService->execute(
                    fn() => $this->ai->generate($prompt, "Você é um especialista em benchmarking de SEO para marketplaces.", 'advanced'),
                    'get_competitor_benchmarks_ai',
                    ['timeout', 'rate limit', 'service unavailable']
                );

                if ($result['success']) {
                    $aiResponse = $result['content'];

                    // Try to extract JSON from response
                    $jsonStart = strpos($aiResponse, '{');
                    $jsonEnd = strrpos($aiResponse, '}');

                    if ($jsonStart !== false && $jsonEnd !== false) {
                        $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
                        $parsed = json_decode($jsonStr, true);

                        if ($parsed && is_array($parsed)) {
                            return $parsed;
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('AI competitor benchmarks failed', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'average_score' => null,
            'top_competitor' => null,
            'benchmark_metrics' => [
                'title_length_avg' => null,
                'title_length_min' => null,
                'title_length_max' => null,
                'price_avg' => null,
                'free_shipping_percent' => null,
                'official_store_percent' => null,
                'top_reputation_percent' => null
            ],
            'competitor_count' => 0,
            'data_source' => 'unavailable',
            'note' => 'Dados indisponíveis'
        ];
    }

    /**
     * Gets SEO trends for category
     */
    private function getSEOTrends(?string $category): array
    {
        if (!$category) {
            return [
                'trending_up' => [],
                'trending_down' => []
            ];
        }

        // Check cache first
        $cacheKey = 'seo_trends_' . md5($category);
        $cached = $this->cache->get($cacheKey, 'seo_trends');
        if ($cached) {
            return $cached;
        }

        try {
            // Use AI to get SEO trends
            $prompt = "Quais são as tendências de SEO em alta e em baixa para a categoria {$category} em 2024? Liste as principais tendências crescentes e decrescentes.";

            $result = $this->retryService->execute(
                fn() => $this->ai->generate($prompt, "Você é um especialista em tendências de SEO para marketplaces.", 'advanced'),
                'get_seo_trends',
                ['timeout', 'rate limit', 'service unavailable']
            );

            if ($result['success']) {
                $aiResponse = $result['content'];

                // Extract trends from response (simplified approach)
                $trendingUp = $this->extractKeywordsFromText($aiResponse);
                $trendingDown = array_slice($trendingUp, -3); // Last 3 as trending down
                $trendingUp = array_slice($trendingUp, 0, 3); // First 3 as trending up

                $trends = [
                    'trending_up' => $trendingUp,
                    'trending_down' => $trendingDown
                ];

                // Cache the result
                $this->cache->set($cacheKey, $trends, 'seo_trends', 43200); // 12 hours

                return $trends;
            }
        } catch (\Exception $e) {
            $this->logger->error('SEO trends fetch failed', [
                'error' => $e->getMessage(),
                'category' => $category
            ]);
        }

        return [
            'trending_up' => [],
            'trending_down' => []
        ];
    }

    /**
     * Gets optimization description
     */
    private function getOptimizationDescription(string $type): string
    {
        $descriptions = [
            'title_analysis' => 'Otimização do título para melhor visibilidade',
            'description_analysis' => 'Melhoria da descrição para conversão',
            'keywords_analysis' => 'Ajuste de palavras-chave para SEO',
            'attributes_analysis' => 'Preenchimento de atributos para relevância',
            'category_analysis' => 'Ajuste à categoria para posicionamento',
            'images_analysis' => 'Otimização de imagens para engajamento',
            'competition_analysis' => 'Melhorias baseadas na concorrência',
            'ml_algorithm_analysis' => 'Ajustes para algoritmo do marketplace'
        ];

        return $descriptions[$type] ?? "Melhoria necessária em {$type}";
    }

    /**
     * Legacy simple health calculation (Fast check for lists)
     * Replaces SeoService::calculateHealth
     */
    public function calculateLegacyHealth(array $item): float
    {
        // If ML provides a valid health (0.0 to 1.0), use it.
        if (isset($item['health']) && is_numeric($item['health'])) {
            if ($item['health'] > 0.01) {
                return (float)$item['health'];
            }
        }

        $score = 0.0;

        // 1. Title (20%) - Ideal: 40-60 chars
        $title = $item['title'] ?? '';
        $len = mb_strlen($title);
        if ($len >= 40) {
            $score += 0.2;
        } elseif ($len >= 20) {
            $score += 0.1;
        }

        // 2. Pictures (20%) - Ideal: > 3 pictures
        $pictures = $item['pictures'] ?? [];
        $picCount = count($pictures);
        if ($picCount >= 5) {
            $score += 0.2;
        } elseif ($picCount >= 3) {
            $score += 0.15;
        } elseif ($picCount >= 1) {
            $score += 0.05;
        }

        // 3. Attributes (20%) - Ideal: Many attributes filled
        $attributes = $item['attributes'] ?? [];
        $attrCount = count($attributes);
        if ($attrCount >= 8) {
            $score += 0.2;
        } elseif ($attrCount >= 4) {
            $score += 0.1;
        }

        // 4. Description (20%)
        if (!empty($item['descriptions']) || !empty($item['description'])) {
            $score += 0.2;
        }

        // 5. Variations/Detailed Info (20%)
        $listingType = $item['listing_type_id'] ?? '';
        if (in_array($listingType, ['gold_pro', 'gold_premium', 'gold_special'])) {
            $score += 0.2;
        } elseif ($listingType === 'gold') {
            $score += 0.1;
        }

        // Cap at 1.0
        return min(1.0, $score);
    }

    /**
     * Fallback method for estimating keyword volume
     * Used when Google Keyword Planner and AI fail
     */
}
