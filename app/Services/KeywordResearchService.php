<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Serviço de Pesquisa de Palavras-Chave (Palavras-Chave)
 */
class KeywordResearchService
{
    public const STOP_WORDS = [
        'a',
        'o',
        'e',
        'de',
        'da',
        'do',
        'em',
        'um',
        'uma',
        'para',
        'com',
        'sem',
        'no',
        'na',
        'os',
        'as',
        'ou',
        'por',
        'que',
        'dos',
        'das'
    ];

    private MercadoLivreClient $client;
    private CacheService $cache;
    private string $siteId;

    public function __construct(
        ?int $accountId = null,
        ?MercadoLivreClient $client = null,
        ?CacheService $cache = null,
        ?string $siteId = null
    ) {
        $this->client = $client ?? new MercadoLivreClient($accountId);
        $this->cache = $cache ?? new CacheService();
        $this->siteId = $siteId ?? 'MLB';
    }

    /**
     * Pesquisa palavras-chave para categoria e keyword base
     */
    public function researchKeywords(string $categoryId, ?string $baseKeyword = null): array
    {
        $baseKeyword = trim((string) $baseKeyword);
        $cacheKey = 'keyword_research:' . $this->siteId . ':' . $categoryId . ':' . md5($baseKeyword);

        return $this->cache->remember($cacheKey, function () use ($categoryId, $baseKeyword) {
            $primary = $baseKeyword !== '' ? [$baseKeyword] : [];
            $variations = $baseKeyword !== '' ? $this->generateKeywordVariations($baseKeyword) : [];
            $categoryTerms = $this->getCategorySpecificTerms($categoryId);
            $trends = $this->getCategoryTrends($categoryId);
            $autocomplete = $baseKeyword !== '' ? $this->getAutocompleteKeywords($baseKeyword) : [];
            $competitors = $baseKeyword !== '' ? $this->extractCompetitorKeywords($baseKeyword, $categoryId) : [];

            $all = array_values(array_unique(array_filter(array_merge(
                $primary,
                $variations,
                $categoryTerms,
                $trends,
                $autocomplete,
                $competitors
            ))));

            $primaryKeywords = !empty($primary) ? $primary : array_slice($all, 0, 3);
            $secondary = array_values(array_diff($all, $primaryKeywords));

            return [
                'primary_keywords' => $primaryKeywords,
                'secondary_keywords' => array_slice($secondary, 0, 20),
                'category_terms' => $categoryTerms,
                'trends' => $trends,
                'autocomplete' => $autocomplete,
                'competitors' => $competitors,
                'all' => $all,
            ];
        }, 3600);
    }

    /**
     * Trends de keywords por categoria
     */
    public function getCategoryTrends(string $categoryId): array
    {
        $trends = $this->client->getTrends($categoryId);
        return array_values(array_unique(array_filter(is_array($trends) ? $trends : [])));
    }

    /**
     * Sugestões de autocomplete
     */
    public function getAutocompleteKeywords(string $keyword): array
    {
        $suggestions = $this->client->getAutocompleteSuggestions($keyword);
        return array_values(array_unique(array_filter(is_array($suggestions) ? $suggestions : [])));
    }

    /**
     * Extrai keywords de concorrentes
     */
    public function extractCompetitorKeywords(string $baseKeyword, string $categoryId, int $limit = 20): array
    {
        $analysis = $this->client->getCompetitorAnalysis($baseKeyword, $categoryId);
        $titles = array_column($analysis['top_performers'] ?? [], 'title');
        $keywords = [];

        foreach ($titles as $title) {
            if (!is_string($title)) {
                continue;
            }
            $words = preg_split('/\s+/', mb_strtolower($title));
            foreach ($words as $word) {
                $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
                if ($word === '' || in_array($word, self::STOP_WORDS, true)) {
                    continue;
                }
                $keywords[] = $word;
            }
        }

        $keywords = array_values(array_unique($keywords));
        return array_slice($keywords, 0, $limit);
    }

    /**
     * Get keywords for a category and base keyword
     */
    public function getKeywords(string $categoryId, string $baseKeyword): array
    {
        // This would typically call external APIs or database
        // For now, returning a combination of base keyword and related terms

        $keywords = [$baseKeyword];

        // Add variations and related terms
        $variations = $this->generateKeywordVariations($baseKeyword);
        $keywords = array_merge($keywords, $variations);

        // Add category-specific terms
        $categoryTerms = $this->getCategorySpecificTerms($categoryId);
        $keywords = array_merge($keywords, $categoryTerms);

        return array_unique($keywords);
    }

    /**
     * Classifica keywords por tipo
     * @return array ['core' => [], 'suporte' => [], 'tecnica' => [], 'contexto' => []]
     */
    public function classifyByType(array $keywords, string $categoryId): array
    {
        $classification = [
            'core' => [],
            'suporte' => [],
            'tecnica' => [],
            'contexto' => []
        ];

        foreach ($keywords as $keyword) {
            $type = $this->classifySingleKeyword($keyword, $categoryId);
            $classification[$type][] = $keyword;
        }

        return $classification;
    }

    /**
     * Analisa oferta/competição real de uma keyword via API do Mercado Livre.
     *
     * Correção de metodologia: a versão anterior calculava "monthly_volume" a
     * partir do comprimento da palavra-chave (2000 - length*50) — um número
     * inventado, sem nenhuma base em dados reais de busca. O Mercado Livre não
     * expõe volume de busca via API pública. Este método agora consulta o total
     * real de anúncios concorrentes (oferta) e reporta apenas isso, mantendo
     * 'monthly_volume' apenas por compatibilidade de schema (sempre null quando
     * não há dado real, nunca mais fabricado).
     */
    public function estimateSearchVolume(string $keyword, ?string $categoryId = null): array
    {
        $totalListings = $this->getTotalListings($keyword, $categoryId);

        return [
            'keyword' => $keyword,
            'category_id' => $categoryId,
            // Dado real da API ML: quantos anúncios competem por este termo (oferta, não demanda).
            'total_listings' => $totalListings,
            // Mantido por compatibilidade retroativa de schema — não é mais fabricado.
            // Sempre null: não existe dado real de volume de busca disponível.
            'monthly_volume' => null,
            'competition' => $totalListings !== null
                ? $this->classifyCompetitionFromListings($totalListings)
                : 'desconhecida',
            'data_source' => $totalListings !== null ? 'ml_search_api' : 'unavailable',
            'trend' => 0
        ];
    }

    /**
     * Retorna keywords com competição real (baseada em total de anúncios concorrentes).
     */
    public function getWithCompetitionScore(array $keywords): array
    {
        $result = [];

        foreach ($keywords as $keyword) {
            $totalListings = $this->getTotalListings($keyword, null);
            $result[] = [
                'keyword' => $keyword,
                'total_listings' => $totalListings,
                'competition_score' => $totalListings !== null
                    ? $this->classifyCompetitionFromListings($totalListings)
                    : 'desconhecida',
            ];
        }

        return $result;
    }

    /**
     * Consulta o total real de anúncios concorrentes para uma keyword via API ML.
     */
    private function getTotalListings(string $keyword, ?string $categoryId): ?int
    {
        try {
            $params = ['q' => $keyword, 'limit' => 1];
            if (!empty($categoryId)) {
                $params['category'] = $categoryId;
            }
            $response = $this->client->get("/sites/{$this->siteId}/search", $params);
            return isset($response['paging']['total']) ? (int)$response['paging']['total'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Classifica competição a partir do total real de anúncios concorrentes.
     */
    private function classifyCompetitionFromListings(int $totalListings): string
    {
        if ($totalListings >= 500) {
            return 'high';
        }
        if ($totalListings >= 50) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Generate keyword variations
     */
    public function generateKeywordVariations(string $baseKeyword): array
    {
        $variations = [];
        $words = explode(' ', $baseKeyword);

        // Add singular/plural variations if applicable
        foreach ($words as $word) {
            if (mb_substr($word, -1) === 's') {
                // Word ends in 's', might be plural
                $singular = rtrim($word, 's');
                $variations[] = str_replace($word, $singular, $baseKeyword);
            } else {
                // Add plural form
                $plural = $word . 's';
                $variations[] = str_replace($word, $plural, $baseKeyword);
            }
        }

        // Add common modifiers
        $modifiers = ['barato', 'original', 'novo', 'usado', 'premium', 'economico'];
        foreach ($modifiers as $modifier) {
            $variations[] = $modifier . ' ' . $baseKeyword;
            $variations[] = $baseKeyword . ' ' . $modifier;
        }

        return $variations;
    }

    /**
     * Get category-specific terms
     */
    private function getCategorySpecificTerms(string $categoryId): array
    {
        // Define some common category terms
        $categoryTerms = [
            'MLB3530' => [ // Baús/Bagageiros
                'baú',
                'bauleto',
                'bagageiro',
                'maleiro',
                'porta objetos',
                'compartimento'
            ],
            'MLB1071' => [ // Capacetes
                'capacete',
                'viseira',
                'concha',
                'abajur',
                'protetor'
            ],
            'MLB1234' => [ // Generic category
                'produto',
                'item',
                'artigo',
                'equipamento',
                'acessório'
            ]
        ];

        return $categoryTerms[$categoryId] ?? ['produto', 'item', 'artigo'];
    }

    /**
     * Classify a single keyword
     */
    private function classifySingleKeyword(string $keyword, string $categoryId): string
    {
        $keywordLower = mb_strtolower($keyword);

        // Core keywords are typically the main product terms
        $coreTerms = ['produto', 'item', 'modelo', 'marca'];
        foreach ($coreTerms as $term) {
            if (strpos($keywordLower, $term) !== false) {
                return 'core';
            }
        }

        // Technical keywords describe specifications
        $techTerms = ['medida', 'tamanho', 'capacidade', 'material', 'cor', 'peso', 'dimensão'];
        foreach ($techTerms as $term) {
            if (strpos($keywordLower, $term) !== false) {
                return 'tecnica';
            }
        }

        // Context keywords relate to usage
        $contextTerms = ['uso', 'aplicação', 'função', 'finalidade'];
        foreach ($contextTerms as $term) {
            if (strpos($keywordLower, $term) !== false) {
                return 'contexto';
            }
        }

        // Default to support
        return 'suporte';
    }

}
