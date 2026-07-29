<?php

declare(strict_types=1);

namespace App\Services\SEO;

use App\Services\SEO\AIClient;

/**
 * Serviço de análise semântica avançada com OpenAI
 * Identifica relações LAT e oportunidades contextuais
 */
class SemanticAnalyzerService
{
    private AIClient $ai;
    private ?string $accountId;

    public function __construct(?string $accountId = null)
    {
        $this->ai = new AIClient();
        $this->accountId = $accountId;
    }

    /**
     * Análise semântica completa de um produto
     */
    public function analyzeSemanticStructure(array $product): array
    {
        $title = $product['title'] ?? '';
        $description = $product['description'] ?? '';
        $category = $product['category'] ?? '';
        $attributes = $product['attributes'] ?? [];

        $prompt = "Realize análise semântica profunda deste produto para marketplace:

TÍTULO: {$title}
DESCRIÇÃO: " . mb_substr($description, 0, 1000) . "
CATEGORIA: {$category}
ATRIBUTOS: " . json_encode($attributes, JSON_UNESCAPED_UNICODE) . "

Execute análise semântica avançada e retorne JSON:
{
    \"semantic_core\": {
        \"main_concepts\": [\"conceitos principais\"],
        \"semantic_clusters\": [
            {
                \"cluster_name\": \"nome do agrupamento\",
                \"keywords\": [\"palavras do cluster\"],
                \"semantic_weight\": (peso 0-1),
                \"commercial_intent\": \"intenção comercial\"
            }
        ],
        \"conceptual_hierarchy\": {
            \"primary\": \"conceito primário\",
            \"secondary\": [\"conceitos secundários\"],
            \"tertiary\": [\"conceitos terciários\"]
        }
    },
    \"latent_semantic_analysis\": {
        \"latent_keywords\": [\"keywords latentes identificadas\"],
        \"semantic_relationships\": [
            {
                \"word1\": \"palavra1\",
                \"word2\": \"palavra2\",
                \"relationship_strength\": (0-1),
                \"relationship_type\": \"sinônimo/relacionado/conceitual\"
            }
        ],
        \"semantic_gaps\": [\"lacunas semânticas identificadas\"],
        \"contextual_opportunities\": [\"oportunidades contextuais\"]
    },
    \"user_intent_mapping\": {
        \"primary_intent\": \"intenção principal do usuário\",
        \"secondary_intents\": [\"intenções secundárias\"],
        \"intent_signals\": [\"sinais de intenção no texto\"],
        \"intent_fulfillment_score\": (0-100)
    },
    \"semantic_optimization\": {
        \"semantic_density_score\": (0-100),
        \"topic_coherence\": (0-100),
        \"keyword_diversity\": (0-100),
        \"semantic_richness\": (0-100),
        \"recommendations\": [
            {
                \"type\": \"expansion/enhancement/restructuring\",
                \"action\": \"ação específica\",
                \"semantic_impact\": \"impacto semântico\",
                \"priority\": \"alta/média/baixa\"
            }
        ]
    },
    \"competitive_semantic_advantage\": {
        \"unique_semantic_angles\": [\"ângulos semânticos únicos\"],
        \"semantic_differentiation\": [\"diferenciais semânticos\"],
        \"conceptual_innovation\": [\"inovações conceituais\"],
        \"semantic_opportunity_score\": (0-100)
    }
}";

        $response = $this->ai->chatJSON($prompt, [
            'temperature' => 0.7,
            'max_tokens' => 3000,
            'cache_ttl' => 3600
        ]);

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error']];
        }

        $data = $response['data'];
        $data['success'] = true;
        $data['analyzed_at'] = date('Y-m-d H:i:s');

        return $data;
    }

    /**
     * Expansão semântica de keywords
     */
    public function expandSemanticKeywords(string $baseKeyword, array $context = []): array
    {
        $category = $context['category'] ?? '';
        $targetAudience = $context['target_audience'] ?? '';
        $useCase = $context['use_case'] ?? '';

        $prompt = "Expanda semanticamente a keyword principal usando análise LAT e contextos avançados:

KEYWORD BASE: {$baseKeyword}
CATEGORIA: {$category}
PÚBLICO-ALVO: {$targetAudience}
CASO DE USO: {$useCase}

Realize expansão semântica completa e retorne JSON:
{
    \"semantic_expansion\": {
        \"synonyms\": [\"sinônimos diretos\"],
        \"related_concepts\": [\"conceitos relacionados\"],
        \"contextual_variations\": [\"variações contextuais\"],
        \"functional_equivalents\": [\"equivalentes funcionais\"],
        \"emotional_associations\": [\"associações emocionais\"]
    },
    \"intent_variations\": {
        \"problem_aware\": [\"keywords quando usuário tem problema\"],
        \"solution_aware\": [\"keywords quando usuário busca solução\"],
        \"comparison_keywords\": [\"keywords de comparação\"],
        \"benefit_focused\": [\"keywords focadas em benefícios\"],
        \"feature_focused\": [\"keywords focadas em características\"]
    },
    \"long_tail_semantic\": {
        \"question_based\": [\"keywords baseadas em perguntas\"],
        \"how_to_variations\": [\"variações 'como fazer'\"],
        \"best_for_variations\": [\"variações 'melhor para'\"],
        \"location_context\": [\"contexto de localização\"],
        \"time_context\": [\"contexto de tempo\"],
        \"quality_indicators\": [\"indicadores de qualidade\"]
    },
    \"semantic_clusters\": [
        {
            \"cluster_theme\": \"tema do agrupamento\",
            \"keywords\": [\"keywords do cluster\"],
            \"search_volume_indicator\": \"alto/médio/baixo\",
            \"competition_level\": \"alta/média/baixa\",
            \"conversion_potential\": \"alto/médio/baixo\"
        }
    ],
    \"strategic_recommendations\": {
        \"primary_focus\": [\"keywords prioritárias\"],
        \"secondary_opportunities\": [oportunidades secundárias],
        \"niche_opportunities\": [\"oportunidades de nicho\"],
        \"seasonal_variations\": [\"variações sazonais\"]
    }
}";

        $response = $this->ai->chatJSON($prompt, [
            'temperature' => 0.8,
            'cache_ttl' => 7200
        ]);

        return $response['success'] ? $response['data'] : ['success' => false, 'error' => $response['error']];
    }

    /**
     * Análise de coesão semântica entre múltiplos produtos
     */
    public function analyzeSemanticCohesion(array $products): array
    {
        $productSummaries = [];

        foreach ($products as $product) {
            $productSummaries[] = [
                'id' => $product['id'] ?? '',
                'title' => $product['title'] ?? '',
                'category' => $product['category'] ?? '',
                'keywords_preview' => implode(', ', array_slice($this->extractBasicKeywords($product['title'] ?? ''), 0, 5))
            ];
        }

        $prompt = "Analise coesão semântica entre múltiplos produtos da mesma categoria:

PRODUTOS: " . json_encode($productSummaries, JSON_UNESCAPED_UNICODE) . "

Execute análise de coesão semântica e retorne JSON:
{
    \"semantic_cohesion_analysis\": {
        \"overall_cohesion_score\": (0-100),
        \"semantic_consistency\": (0-100),
        \"theme_alignment\": (0-100),
        \"brand_voice_consistency\": (0-100)
    },
    \"semantic_gaps\": {
        \"missing_themes\": [\"temas não cobertos\"],
        \"underrepresented_concepts\": [\"conceitos sub-representados\"],
        \"semantic_holes\": [\"buracos semânticos na cobertura\"],
        \"opportunity_areas\": [\"áreas de oportunidade semântica\"]
    },
    \"semantic_overlaps\": {
        \"duplicate_concepts\": [\"conceitos duplicados\"],
        \"cannibalization_risks\": [\"riscos de canibalização\"],
        \"redundant_keywords\": [\"keywords redundantes\"],
        \"consolidation_opportunities\": [\"oportunidades de consolidação\"]
    },
    \"semantic_strategy\": {
        \"primary_themes\": [\"temas principais da estratégia\"],
        \"supporting_themes\": [\"temas de apoio\"],
        \"niche_angles\": [\"ângulos de nicho\"],
        \"differentiation_opportunities\": [\"oportunidades de diferenciação\"]
    },
    \"recommendations\": [
        {
            \"product_id\": \"ID do produto\",
            \"action\": \"ação recomendada\",
            \"semantic_impact\": \"impacto semântico esperado\",
            \"priority\": \"alta/média/baixa\"
        }
    ],
    \"optimization_roadmap\": {
        \"immediate_actions\": [\"ações imediatas\"],
        \"short_term_goals\": [\"metas de curto prazo\"],
        \"long_term_strategy\": \"estratégia de longo prazo\"
    }
}";

        $response = $this->ai->chatJSON($prompt, [
            'temperature' => 0.6,
            'cache_ttl' => 3600
        ]);

        return $response['success'] ? $response['data'] : ['success' => false, 'error' => $response['error']];
    }

    /**
     * Análise de tendências semânticas
     */
    public function analyzeSemanticTrends(string $category, array $historicalData = []): array
    {
        $prompt = "Analise tendências semânticas emergentes na categoria:

CATEGORIA: {$category}
DADOS HISTÓRICOS: " . json_encode($historicalData, JSON_UNESCAPED_UNICODE) . "

Identifique tendências semânticas e retorne JSON:
{
    \"emerging_semantic_trends\": {
        \"rising_concepts\": [
            {
                \"concept\": \"conceito em ascensão\",
                \"growth_indicator\": \"alto/médio/baixo\",
                \"time_to_mainstream\": \"estimativa de tempo\",
                \"commercial_potential\": \"potencial comercial\"
            }
        ],
        \"declining_concepts\": [\"conceitos em declínio\"],
        \"semantic_shifts\": [
            {
                \"from_concept\": \"conceito antigo\",
                \"to_concept\": \"conceito novo\",
                \"shift_magnitude\": \"magnitude da mudança\",
                \"driver_factors\": [\"fatores impulsionadores\"]
            }
        ]
    },
    \"semantic_opportunities\": {
        \"early_adopter_opportunities\": [\"oportunidades para early adopters\"],
        \"niche_concepts\": [\"conceitos de nicho emergentes\"],
        \"cross_category_synergies\": [\"sinergias entre categorias\"],
        \"seasonal_semantic_patterns\": [\"padrões semânticos sazonais\"]
    },
    \"competitive_landscape\": {
        \"semantic_leaders\": [\"líderes em semântica\"],
        \"semantic_followers\": [\"seguidores semânticos\"],
        \"semantic_innovators\": [\"inovadores semânticos\"],
        \"market_gaps\": [\"lacunas no mercado semântico\"]
    },
    \"strategic_recommendations\": {
        \"immediate_opportunities\": [\"oportunidades imediatas\"],
        \"medium_term_investments\": [\"investimentos de médio prazo\"],
        \"long_term_positioning\": \"posicionamento de longo prazo\",
        \"risk_mitigation\": [\"mitigação de riscos semânticos\"]
    },
    \"prediction_confidence\": {
        \"high_confidence_trends\": [\"tendências de alta confiança\"],
        \"medium_confidence_trends\": [\"tendências de média confiança\"],
        \"experimental_concepts\": [\"conceitos experimentais\"]
    }
}";

        $response = $this->ai->chatJSON($prompt, [
            'temperature' => 0.7,
            'cache_ttl' => 86400 // 24 horas
        ]);

        return $response['success'] ? $response['data'] : ['success' => false, 'error' => $response['error']];
    }

    /**
     * Gera conteúdo semanticamente otimizado
     */
    public function generateSemanticContent(array $product, string $contentType = 'description'): array
    {
        $title = $product['title'] ?? '';
        $category = $product['category'] ?? '';
        $semanticAnalysis = $product['semantic_analysis'] ?? [];

        $prompt = "Gere conteúdo semanticamente otimizado usando análise semântica avançada:

PRODUTO: {$title}
CATEGORIA: {$category}
TIPO DE CONTEÚDO: {$contentType}
ANÁLISE SEMÂNTICA: " . json_encode($semanticAnalysis, JSON_UNESCAPED_UNICODE) . "

Crie conteúdo semanticamente rico e retorne JSON:
{
    \"optimized_content\": {
        \"title_suggestions\": [
            {
                \"title\": \"título otimizado\",
                \"semantic_score\": (0-100),
                \"keywords_covered\": [\"keywords cobertas\"],
                \"semantic_clusters\": [\"clusters semânticos utilizados\"]
            }
        ],
        \"description\": \"descrição semanticamente otimizada\",
        \"bullet_points\": [
            {
                \"point\": \"bullet point otimizado\",
                \"semantic_intent\": \"intenção semântica\",
                \"commercial_angle\": \"ângulo comercial\"
            }
        ],
        \"semantic_summary\": \"resumo que captura essência semântica\"
    },
    \"semantic_optimization\": {
        \"semantic_density\": (0-100),
        \"topic_coherence\": (0-100),
        \"keyword_diversity\": (0-100),
        \"user_intent_alignment\": (0-100),
        \"commercial_strength\": (0-100)
    },
    \"semantic_elements\": {
        \"latent_keywords_used\": [\"keywords latentes utilizadas\"],
        \"semantic_clusters_active\": [\"clusters semânticos ativos\"],
        \"conceptual_relationships\": [\"relações conceituais estabelecidas\"],
        \"contextual_references\": [\"referências contextuais\"]
    },
    \"quality_metrics\": {
        \"readability_score\": (0-100),
        \"engagement_potential\": (0-100),
        \"conversion_readiness\": (0-100),
        \"seo_completeness\": (0-100)
    },
    \"improvement_suggestions\": [
        {
            \"aspect\": \"aspecto a melhorar\",
            \"suggestion\": \"sugestão específica\",
            \"impact_estimate\": \"estimativa de impacto\"
        }
    ]
}";

        $response = $this->ai->chatJSON($prompt, [
            'temperature' => 0.8,
            'max_tokens' => 2500,
            'cache_ttl' => 3600
        ]);

        return $response['success'] ? $response['data'] : ['success' => false, 'error' => $response['error']];
    }

    /**
     * Validação de otimização semântica
     */
    public function validateSemanticOptimization(array $product, array $optimizedContent): array
    {
        $originalTitle = $product['title'] ?? '';
        $optimizedTitle = $optimizedContent['title'] ?? '';
        $originalDescription = $product['description'] ?? '';
        $optimizedDescription = $optimizedContent['description'] ?? '';

        $prompt = "Valide a otimização semântica comparando original vs otimizado:

ORIGINAL:
Título: {$originalTitle}
Descrição: " . mb_substr($originalDescription, 0, 500) . "

OTIMIZADO:
Título: {$optimizedTitle}
Descrição: " . mb_substr($optimizedDescription, 0, 500) . "

Valide semanticamente e retorne JSON:
{
    \"semantic_validation\": {
        \"improvement_score\": (0-100),
        \"semantic_richness_gain\": (0-100),
        \"keyword_expansion_quality\": (0-100),
        \"user_intent_alignment\": (0-100)
    },
    \"improvements_identified\": [
        {
            \"type\": \"expansion/enhancement/clarification\",
            \"description\": \"descrição da melhoria\",
            \"semantic_impact\": \"impacto semântico\"
        }
    ],
    \"potential_issues\": [
        {
            \"issue\": \"problema identificado\",
            \"severity\": \"alta/média/baixa\",
            \"recommendation\": \"como corrigir\"
        }
    ],
    \"semantic_coverage_analysis\": {
        \"new_concepts_added\": [\"novos conceitos adicionados\"],
        \"semantic_gaps_filled\": [\"lacunas semânticas preenchidas\"],
        \"redundancy_eliminated\": [\"redundâncias eliminadas\"]
    },
    \"recommendations\": {
        \"final_adjustments\": [\"ajustes finais recomendados\"],
        \"monitoring_points\": [\"pontos a monitorar\"],
        \"success_indicators\": [\"indicadores de sucesso\"
        ]
    },
    \"validation_summary\": {
        \"overall_assessment\": \"avaliação geral da otimização\",
        \"readiness_score\": (0-100),
        \"deployment_confidence\": \"confiança na implementação\"
    }
}";

        $response = $this->ai->chatJSON($prompt, [
            'temperature' => 0.5,
            'cache_ttl' => 1800
        ]);

        return $response['success'] ? $response['data'] : ['success' => false, 'error' => $response['error']];
    }

    /**
     * Métodos auxiliares
     */
    private function extractBasicKeywords(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', trim($text));

        $stopWords = ['de', 'da', 'do', 'em', 'para', 'com', 'sem', 'a', 'o', 'as', 'os', 'e', 'ou', 'um', 'uma', 'uns', 'umas'];

        return array_filter($words, function ($word) use ($stopWords) {
            return mb_strlen($word) > 2 && !in_array($word, $stopWords);
        });
    }

    /**
     * Calcula similaridade semântica entre textos
     */
    public function calculateSemanticSimilarity(string $text1, string $text2): array
    {
        $prompt = "Calcule similaridade semântica avançada entre textos:

TEXTO 1: {$text1}
TEXTO 2: {$text2}

Analise e retorne JSON:
{
    \"semantic_similarity\": {
        \"overall_score\": (0-100),
        \"conceptual_overlap\": (0-100),
        \"intent_similarity\": (0-100),
        \"topic_alignment\": (0-100)
    },
    \"shared_concepts\": [\"conceitos compartilhados\"],
    \"unique_concepts_text1\": [\"conceitos únicos texto 1\"],
    \"unique_concepts_text2\": [\"conceitos únicos texto 2\"],
    \"semantic_relationship\": \"relação semântica principal\",
    \"recommendation\": \"recomendação baseada na similaridade\"
}";

        $response = $this->ai->chatJSON($prompt, ['cache_ttl' => 1800]);

        return $response['success'] ? $response['data'] : ['semantic_similarity' => ['overall_score' => 0]];
    }
}
