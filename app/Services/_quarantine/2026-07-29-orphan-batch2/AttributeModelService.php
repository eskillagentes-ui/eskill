<?php

declare(strict_types=1);

namespace App\Services\SEO;

use App\Services\MercadoLivreClient;
use App\Services\SEO\AIClient;

/**
 * Serviço especializado em preenchimento automático do campo atributo modelo
 * Combina análise de produto com dados do Mercado Livre e IA
 */
class AttributeModelService
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
     * Extração e sugestão automática do modelo
     */
    public function extractAndSuggestModel(array $product): array
    {
        $title = $product['title'] ?? '';
        $description = $product['description'] ?? '';
        $category = $product['category_id'] ?? '';
        $brand = $this->extractBrand($product);
        $currentModel = $this->extractCurrentModel($product);

        // 1. Análise via IA para extração de padrões
        $aiAnalysis = $this->performModelExtraction($title, $description, $brand, $category);

        // 2. Busca produtos similares para validação
        $similarProducts = $this->getSimilarProductsForModel($title, $category, $brand);

        // 3. Análise de padrões de mercado
        $marketPatterns = $this->analyzeMarketPatterns($similarProducts, $brand);

        // 4. Geração de sugestões
        $suggestions = $this->generateModelSuggestions($aiAnalysis, $marketPatterns, $currentModel);

        return [
            'success' => true,
            'current_model' => $currentModel,
            'ai_analysis' => $aiAnalysis,
            'market_patterns' => $marketPatterns,
            'suggestions' => $suggestions,
            'confidence_score' => $this->calculateConfidence($aiAnalysis, $marketPatterns),
            'recommended_action' => $this->getRecommendedAction($suggestions, $currentModel)
        ];
    }

    /**
     * Otimização do campo modelo
     */
    public function optimizeModelAttribute(string $currentModel, array $product, array $competitorModels = []): array
    {
        $title = $product['title'] ?? '';
        $category = $product['category_id'] ?? '';
        $brand = $this->extractBrand($product);

        $prompt = "Otimize o campo modelo para marketplace seguindo as melhores práticas:

MODELO ATUAL: {$currentModel}
TÍTULO DO PRODUTO: {$title}
MARCA: {$brand}
CATEGORIA: {$category}
MODELOS CONCORRENTES: " . json_encode($competitorModels, JSON_UNESCAPED_UNICODE) . "

Analise e otimize o modelo retornando JSON:
{
    \"model_analysis\": {
        \"current_structure\": \"análise da estrutura atual\",
        \"pattern_compliance\": \"conformidade com padrões\",
        \"market_alignment\": (0-100),
        \"search_optimization\": (0-100)
    },
    \"optimized_models\": [
        {
            \"model\": \"modelo otimizado\",
            \"strategy\": \"padrão/seo/detalhado/híbrido\",
            \"advantages\": [\"vantagens deste modelo\"],
            \"use_cases\": [\"casos de uso recomendados\"],
            \"confidence\": (0-100)
        }
    ],
    \"model_variations\": {
        \"short_version\": \"versão curta para espaços limitados\",
        \"detailed_version\": \"versão completa com detalhes\",
        \"seo_version\": \"versão otimizada para busca\",
        \"standard_version\": \"versão padrão da categoria\"
    },
    \"pattern_recommendations\": {
        \"industry_standard\": \"padrão da indústria recomendado\",
        \"category_best_practices\": [\"melhores práticas da categoria\"],
        \"seo_optimizations\": [\"otimizações SEO específicas\"],
        \"compatibility_notes\": [\"notas de compatibilidade\"]
    },
    \"implementation_guide\": {
        \"primary_choice\": \"escolha principal e justificativa\",
        \"fallback_options\": [\"opções alternativas\"],
        \"a_b_test_suggestions\": [\"sugestões para A/B test\"],
        \"monitoring_metrics\": [\"métricas para monitorar\"]
    },
    \"competitive_analysis\": {
        \"market_positioning\": \"posicionamento no mercado\",
        \"differentiation_opportunities\": [oportunidades de diferenciação],
        \"gap_analysis\": \"análise de lacunas vs concorrentes\"
    }
}";

        $response = $this->ai->chatJSON($prompt, [
            'temperature' => 0.7,
            'cache_ttl' => 3600
        ]);

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error']];
        }

        // Enriquece com dados da API ML
        $mlInsights = $this->getMLModelInsights($category, $brand);
        $response['data']['ml_insights'] = $mlInsights;

        return $response['data'];
    }

    /**
     * Validação de modelo
     */
    public function validateModel(string $model, array $product): array
    {
        $category = $product['category_id'] ?? '';
        $brand = $this->extractBrand($product);
        $title = $product['title'] ?? '';

        $validationPrompt = "Valide o campo modelo para conformidade e otimização:

MODELO: {$model}
PRODUTO: {$title}
MARCA: {$brand}
CATEGORIA: {$category}

Valide todos os aspectos e retorne JSON:
{
    \"validation_results\": {
        \"overall_score\": (0-100),
        \"format_compliance\": (0-100),
        \"marketplace_rules\": (0-100),
        \"seo_optimization\": (0-100),
        \"user_clarity\": (0-100)
    },
    \"compliance_check\": {
        \"length_valid\": boolean,
        \"characters_allowed\": boolean,
        \"format_correct\": boolean,
        \"no_forbidden_patterns\": boolean,
        \"category_specific_rules\": \"regras específicas da categoria\"
    },
    \"quality_metrics\": {
        \"searchability\": (0-100),
        \"clarity\": (0-100),
        \"uniqueness\": (0-100),
        \"informativeness\": (0-100)
    },
    \"issues_found\": [
        {
            \"type\": \"critical/warning/info\",
            \"description\": \"descrição do problema\",
            \"impact\": \"impacto no desempenho\",
            \"fix_suggestion\": \"sugestão de correção\"
        }
    ],
    \"optimization_opportunities\": [
        {
            \"opportunity\": \"oportunidade de melhoria\",
            \"expected_impact\": \"impacto esperado\",
            \"implementation_effort\": \"baixo/médio/alto\"
        }
    ],
    \"recommendation\": {
        \"approved\": boolean,
        \"final_model\": \"modelo final recomendado\",
        \"confidence\": (0-100),
        \"next_steps\": [\"próximos passos\"]
    }
}";

        $response = $this->ai->chatJSON($validationPrompt, [
            'cache_ttl' => 1800
        ]);

        return $response['success'] ? $response['data'] : ['success' => false, 'error' => $response['error']];
    }

    /**
     * Análise de tendências de modelos
     */
    public function analyzeModelTrends(string $category, string $brand = ''): array
    {
        $prompt = "Analise tendências de modelos de produtos na categoria:

CATEGORIA: {$category}
MARCA: {$brand}

Analise tendências atuais e retorne JSON:
{
    \"trend_analysis\": {
        \"current_patterns\": [\"padrões atuais dominantes\"],
        \"emerging_patterns\": [\"padrões emergentes\"],
        \"declining_patterns\": [\"padrões em declínio\"],
        \"seasonal_variations\": [\"variações sazonais\"]
    },
    \"market_leadership\": {
        \"top_performing_patterns\": [\"padrões de melhor performance\"],
        \"industry_standards\": [\"padrões de indústria\"],
        \"innovative_approaches\": [\"abordagens inovadoras\"]
    },
    \"category_specific_insights\": {
        \"required_elements\": [\"elementos obrigatórios\"],
        \"optional_benefits\": [\"elementos opcionais benéficos\"],
        \"common_mistakes\": [\"erros comuns a evitar\"],
        \"best_practices\": [\"melhores práticas específicas\"]
    },
    \"competitive_landscape\": {
        \"market_saturation\": \"nível de saturação\",
        \"blue_opportunity_areas\": [\"áreas de oportunidade azul\"],
        \"red_ocean_patterns\": [\"padrões de oceano vermelho\"]
    },
    \"strategic_recommendations\": {
        \"immediate_implementations\": [\"implementações imediatas\"],
        \"medium_term_experiments\": [\"experimentos de médio prazo\"],
        \"long_term_positioning\": \"posicionamento de longo prazo\",
        \"risk_considerations\": [\"considerações de risco\"]
    },
    \"predictive_insights\": {
        \"next_quarter_trends\": [\"tendências do próximo trimestre\"],
        \"year_ahead_predictions\": [\"previsões para o ano seguinte\"],
        \"technology_impact\": \"impacto tecnológico esperado\"
    }
}";

        $response = $this->ai->chatJSON($prompt, [
            'cache_ttl' => 86400 // 24 horas
        ]);

        return $response['success'] ? $response['data'] : ['success' => false, 'error' => $response['error']];
    }

    /**
     * Geração em massa de modelos
     */
    public function batchGenerateModels(array $products): array
    {
        $results = [];
        $categoryGroups = [];

        // Agrupa produtos por categoria para análise eficiente
        foreach ($products as $product) {
            $category = $product['category_id'] ?? 'unknown';
            $categoryGroups[$category][] = $product;
        }

        foreach ($categoryGroups as $category => $categoryProducts) {
            $categoryInsights = $this->analyzeModelTrends($category);

            foreach ($categoryProducts as $product) {
                $productId = $product['id'] ?? uniqid();

                $modelResult = $this->extractAndSuggestModel($product);
                $modelResult['category_insights'] = $categoryInsights;

                $results[$productId] = $modelResult;
            }
        }

        return [
            'success' => true,
            'processed_count' => count($products),
            'category_coverage' => array_keys($categoryGroups),
            'results' => $results,
            'summary' => $this->generateBatchSummary($results),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Métodos auxiliares privados
     */
    private function performModelExtraction(string $title, string $description, string $brand, string $category): array
    {
        $prompt = "Extraia padrões de modelo do produto usando NLP avançado:

TÍTULO: {$title}
DESCRIÇÃO: " . mb_substr($description, 0, 800) . "
MARCA: {$brand}
CATEGORIA: {$category}

Extraia informações de modelo e retorne JSON:
{
    \"extraction_results\": {
        \"current_model\": \"modelo extraído do texto\",
        \"model_patterns\": [\"padrões de modelo identificados\"],
        \"extraction_confidence\": (0-100),
        \"extraction_method\": \"método usado na extração\"
    },
    \"pattern_analysis\": {
        \"brand_model_pattern\": \"padrão marca-modelo identificado\",
        \"numeric_identifiers\": [\"identificadores numéricos\"],
        \"version_indicators\": [\"indicadores de versão\"],
        \"generation_markers\": [\"marcadores de geração\"]
    },
    \"semantic_indicators\": {
        \"model_type\": \"tipo do modelo (linha/versão/geração)\",
        \"product_line\": \"linha do produto\",
        \"series_identifier\": \"identificador de série\",
        \"variant_markers\": [\"marcadores de variante\"]
    },
    \"validation_clues\": {
        \"consistency_score\": (0-100),
        \"pattern_strength\": (0-100),
        \"market_alignment\": (0-100),
        \"uniqueness_factor\": (0-100)
    }
}";

        $response = $this->ai->chatJSON($prompt, [
            'cache_ttl' => 1800
        ]);

        return $response['success'] ? $response['data'] : [];
    }

    private function getSimilarProductsForModel(string $title, string $category, string $brand): array
    {
        try {
            $keywords = $this->extractKeywords($title);
            $searchQuery = $brand . ' ' . implode(' ', array_slice($keywords, 0, 2));

            $params = [
                'q' => $searchQuery,
                'category' => $category,
                'limit' => 10,
                'sort' => 'relevance'
            ];

            $response = $this->mlClient->get('/sites/MLB/search', $params);

            $products = [];
            foreach ($response['results'] ?? [] as $item) {
                $model = $this->extractCurrentModel([
                    'title' => $item['title'],
                    'attributes' => $item['attributes'] ?? []
                ]);

                if (!empty($model)) {
                    $products[] = [
                        'id' => $item['id'],
                        'title' => $item['title'],
                        'model' => $model,
                        'sold_quantity' => $item['sold_quantity'] ?? 0,
                        'price' => $item['price']
                    ];
                }
            }

            return $products;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function analyzeMarketPatterns(array $similarProducts, string $brand): array
    {
        if (empty($similarProducts)) {
            return ['patterns_found' => [], 'confidence' => 0];
        }

        $models = array_column($similarProducts, 'model');
        $patternAnalysis = [
            'common_prefixes' => [],
            'common_suffixes' => [],
            'numeric_patterns' => [],
            'brand_position' => [],
            'model_structures' => []
        ];

        foreach ($models as $model) {
            // Análise de padrões
            $parts = explode(' ', $model);
            if (count($parts) > 1) {
                $patternAnalysis['common_prefixes'][] = $parts[0];
                $patternAnalysis['common_suffixes'][] = end($parts);
            }

            // Padrões numéricos
            preg_match_all('/\d+/', $model, $numbers);
            if (!empty($numbers[0])) {
                $patternAnalysis['numeric_patterns'] = array_merge(
                    $patternAnalysis['numeric_patterns'],
                    $numbers[0]
                );
            }

            // Posição da marca
            $brandPosition = stripos($model, $brand);
            if ($brandPosition !== false) {
                $patternAnalysis['brand_position'][] = $brandPosition === 0 ? 'prefix' : 'suffix';
            }
        }

        return [
            'patterns_found' => $patternAnalysis,
            'frequency_analysis' => [
                'prefix_frequency' => array_count_values($patternAnalysis['common_prefixes']),
                'suffix_frequency' => array_count_values($patternAnalysis['common_suffixes']),
                'numeric_frequency' => array_count_values($patternAnalysis['numeric_patterns'])
            ],
            'market_confidence' => min(100, count($similarProducts) * 10)
        ];
    }

    private function generateModelSuggestions(array $aiAnalysis, array $marketPatterns, string $currentModel): array
    {
        $baseModel = $aiAnalysis['extraction_results']['current_model'] ?? $currentModel;
        $patterns = $marketPatterns['patterns_found'] ?? [];

        if (empty($patterns)) {
            return [
                'recommended_model' => $baseModel,
                'alternatives' => [],
                'confidence' => 50,
                'reasoning' => 'Sem dados suficientes para otimização'
            ];
        }

        $suggestions = [
            'recommended_model' => $this->buildOptimalModel($baseModel, $patterns),
            'alternatives' => $this->generateAlternatives($baseModel, $patterns),
            'pattern_based' => $this->applyPatternRules($baseModel, $patterns),
            'seo_optimized' => $this->seoOptimizeModel($baseModel, $patterns)
        ];

        return $suggestions;
    }

    private function buildOptimalModel(string $baseModel, array $patterns): string
    {
        // Lógica para construir modelo ótimo baseado em padrões
        $prefixes = $patterns['common_prefixes'] ?? [];
        $suffixes = $patterns['common_suffixes'] ?? [];

        if (!empty($prefixes) && !empty($suffixes)) {
            $mostCommonPrefix = array_keys(array_count_values($prefixes), max(array_count_values($prefixes)))[0] ?? '';
            $mostCommonSuffix = array_keys(array_count_values($suffixes), max(array_count_values($suffixes)))[0] ?? '';

            return $mostCommonPrefix . ' ' . $baseModel . ' ' . $mostCommonSuffix;
        }

        return $baseModel;
    }

    private function generateAlternatives(string $baseModel, array $patterns): array
    {
        return [
            $baseModel,
            $this->addNumericVersion($baseModel),
            $this->addGenerationMarker($baseModel),
            $this->addVariantIndicator($baseModel)
        ];
    }

    private function applyPatternRules(string $model, array $patterns): string
    {
        // Aplica regras baseadas em padrões de mercado
        return $model;
    }

    private function seoOptimizeModel(string $model, array $patterns): string
    {
        // Otimiza modelo para SEO
        return $model;
    }

    private function addNumericVersion(string $model): string
    {
        return preg_match('/\d/', $model) ? $model : $model . ' 1.0';
    }

    private function addGenerationMarker(string $model): string
    {
        return stripos($model, 'gen') === false ? $model . ' Gen' : $model;
    }

    private function addVariantIndicator(string $model): string
    {
        return preg_match('/(pro|plus|max|lite)/i', $model) ? $model : $model . ' Pro';
    }

    private function calculateConfidence(array $aiAnalysis, array $marketPatterns): int
    {
        $aiConfidence = $aiAnalysis['validation_clues']['consistency_score'] ?? 50;
        $marketConfidence = $marketPatterns['market_confidence'] ?? 0;

        return round(($aiConfidence + $marketConfidence) / 2);
    }

    private function getRecommendedAction(array $suggestions, string $currentModel): string
    {
        if (empty($currentModel)) {
            return 'create_new';
        }

        $recommended = $suggestions['recommended_model'] ?? '';
        if ($recommended === $currentModel) {
            return 'keep_current';
        }

        return 'update_optimized';
    }

    private function extractBrand(array $product): string
    {
        // Extrai marca dos atributos ou título
        $attributes = $product['attributes'] ?? [];

        foreach ($attributes as $attr) {
            if (isset($attr['id']) && $attr['id'] === 'BRAND') {
                return $attr['value_name'] ?? '';
            }
        }

        // Tentativa extração do título
        $title = $product['title'] ?? '';
        $titleParts = explode(' ', $title);
        return count($titleParts) > 0 ? $titleParts[0] : '';
    }

    private function extractCurrentModel(array $product): string
    {
        // Extrai modelo atual dos atributos ou título
        $attributes = $product['attributes'] ?? [];

        foreach ($attributes as $attr) {
            if (isset($attr['id']) && ($attr['id'] === 'MODEL' || $attr['id'] === 'MODEL_NAME')) {
                return $attr['value_name'] ?? '';
            }
        }

        // Extração do título
        $title = $product['title'] ?? '';

        // Padrões comuns de modelo
        $patterns = [
            '/\b[A-Z]{2,4}-\d{3,4}\b/',  // Ex: ABC-1234
            '/\b[A-Z]+\d+[A-Z]*\b/',     // Ex: iPhone12Pro
            '/\b\d+\.\d+[A-Z]*\b/',      // Ex: 3.0 Pro
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                return $matches[0];
            }
        }

        return '';
    }

    private function getMLModelInsights(string $category, string $brand): array
    {
        try {
            $params = [
                'category' => $category,
                'q' => $brand,
                'limit' => 5
            ];

            $response = $this->mlClient->get('/sites/MLB/search', $params);

            $insights = [
                'top_models' => [],
                'model_frequency' => [],
                'price_model_correlation' => []
            ];

            foreach ($response['results'] ?? [] as $item) {
                $model = $this->extractCurrentModel([
                    'title' => $item['title'],
                    'attributes' => $item['attributes'] ?? []
                ]);

                if (!empty($model)) {
                    $insights['top_models'][] = [
                        'model' => $model,
                        'price' => $item['price'],
                        'sold_quantity' => $item['sold_quantity'] ?? 0
                    ];
                }
            }

            return $insights;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function extractKeywords(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', trim($text));

        $stopWords = ['de', 'da', 'do', 'em', 'para', 'com', 'sem', 'a', 'o', 'as', 'os', 'e', 'ou'];

        return array_filter($words, function ($word) use ($stopWords) {
            return mb_strlen($word) > 2 && !in_array($word, $stopWords);
        });
    }

    private function generateBatchSummary(array $results): array
    {
        $summary = [
            'total_processed' => count($results),
            'high_confidence' => 0,
            'medium_confidence' => 0,
            'low_confidence' => 0,
            'actions_needed' => [
                'create_new' => 0,
                'update_optimized' => 0,
                'keep_current' => 0
            ]
        ];

        foreach ($results as $result) {
            $confidence = $result['confidence_score'] ?? 50;

            if ($confidence >= 75) $summary['high_confidence']++;
            elseif ($confidence >= 50) $summary['medium_confidence']++;
            else $summary['low_confidence']++;

            $action = $result['recommended_action'] ?? 'keep_current';
            $summary['actions_needed'][$action]++;
        }

        return $summary;
    }
}
