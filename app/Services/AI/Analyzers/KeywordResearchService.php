<?php

declare(strict_types=1);

namespace App\Services\AI\Analyzers;

use App\Services\MercadoLivreClient;

/**
 * Keyword Research Service
 * Finds and scores keywords for optimal SEO
 */
class KeywordResearchService
{
    private MercadoLivreClient $mlClient;
    private ?int $accountId;

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);
    }

    /**
     * Research keywords for a category and product
     *
     * @param string $categoryId ML category ID
     * @param string $baseQuery Base search query
     * @param array $options Research options
     * @return array Keywords with scores
     */
    public function researchKeywords(string $categoryId, string $baseQuery, array $options = []): array
    {
        $limit = $options['limit'] ?? 20;

        // Get trending searches from ML
        $trendingKeywords = $this->getTrendingSearches($categoryId, $baseQuery);

        // Get related keywords
        $relatedKeywords = $this->getRelatedKeywords($baseQuery);

        // Combine and deduplicate
        $allKeywords = array_unique(array_merge($trendingKeywords, $relatedKeywords));

        // Score each keyword
        $scoredKeywords = [];
        foreach ($allKeywords as $keyword) {
            $score = $this->scoreKeyword($keyword, $baseQuery, $categoryId);

            $scoredKeywords[] = [
                'keyword' => $keyword,
                'score' => $score['total'],
                'total_listings' => $score['total_listings'],
                'competition' => $score['competition'],
                'relevance' => $score['relevance'],
                'commercial_intent' => $score['commercial_intent'],
            ];
        }

        // Sort by score descending
        usort($scoredKeywords, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($scoredKeywords, 0, $limit);
    }

    /**
     * Get trending searches from Mercado Livre
     *
     * @param string $categoryId
     * @param string $query
     * @return array
     */
    private function getTrendingSearches(string $categoryId, string $query): array
    {
        try {
            // Use ML Trends API if available
            $response = $this->mlClient->get("/trends/{$categoryId}/searches");

            if (isset($response['error'])) {
                return $this->extractKeywordsFromSearch($query, $categoryId);
            }

            return array_slice($response['keywords'] ?? [], 0, 10);
        } catch (\Exception $e) {
            log_warning('Error fetching trending searches', ['service' => 'KeywordResearchService', 'error' => $e->getMessage()]);
            return $this->extractKeywordsFromSearch($query, $categoryId);
        }
    }

    /**
     * Extract keywords by searching and analyzing results
     *
     * @param string $query
     * @param string $categoryId
     * @return array
     */
    private function extractKeywordsFromSearch(string $query, string $categoryId): array
    {
        try {
            $response = $this->mlClient->get('/sites/MLB/search', [
                'q' => $query,
                'category' => $categoryId,
                'limit' => 50
            ]);

            if (isset($response['error']) || empty($response['results'])) {
                return $this->generateFallbackKeywords($query);
            }

            // Extract keywords from top results titles
            $keywords = [];
            foreach ($response['results'] as $item) {
                $title = $item['title'] ?? '';
                $titleKeywords = $this->extractKeywordsFromTitle($title);
                $keywords = array_merge($keywords, $titleKeywords);
            }

            // Count frequency
            $keywordFrequency = array_count_values($keywords);
            arsort($keywordFrequency);

            return array_keys(array_slice($keywordFrequency, 0, 15));
        } catch (\Exception $e) {
            log_warning('Error extracting keywords', ['service' => 'KeywordResearchService', 'error' => $e->getMessage()]);
            return $this->generateFallbackKeywords($query);
        }
    }

    /**
     * Get related keywords using word associations
     *
     * @param string $query
     * @return array
     */
    private function getRelatedKeywords(string $query): array
    {
        $words = explode(' ', mb_strtolower($query));
        $related = [];

        // Common associations for electronics
        $associations = [
            'fone' => ['bluetooth', 'sem fio', 'wireless', 'tws', 'earbuds', 'headphone'],
            'bluetooth' => ['5.0', '5.1', '5.2', '5.3', 'wireless'],
            'celular' => ['smartphone', '5g', '128gb', '256gb', 'dual sim'],
            'notebook' => ['intel', 'amd', 'ssd', '8gb', '16gb', 'i5', 'i7'],
            'monitor' => ['144hz', '4k', 'curved', 'gaming', 'led', 'ips'],
            'teclado' => ['mecânico', 'gamer', 'rgb', 'bluetooth', 'wireless'],
            'mouse' => ['gamer', 'rgb', 'wireless', 'dpi', 'bluetooth'],
        ];

        foreach ($words as $word) {
            if (isset($associations[$word])) {
                $related = array_merge($related, $associations[$word]);
            }
        }

        return array_unique($related);
    }

    /**
     * Score a keyword based on multiple factors
     *
     * @param string $keyword
     * @param string $baseQuery
     * @param string $categoryId
     * @return array Score breakdown
     */
    /**
     * Correção de metodologia: a versão anterior calculava 'search_volume' e
     * 'competition' a partir do MESMO dado real (total de anúncios concorrentes
     * retornado por getSearchResultsCount, que mede OFERTA, não demanda), apenas
     * com escalas diferentes (/100 vs /50). Isso contava o mesmo sinal duas vezes
     * disfarçado de duas métricas independentes, e ainda rotulava oferta como se
     * fosse "volume de busca" — o Mercado Livre não expõe volume de busca via API
     * pública. Agora o total de anúncios alimenta apenas 'competition' (peso 0.5,
     * substituindo os antigos pesos somados de search_volume+competition).
     */
    private function scoreKeyword(string $keyword, string $baseQuery, string $categoryId): array
    {
        $scores = [
            'total_listings' => 0,
            'competition' => 0,
            'relevance' => 0,
            'commercial_intent' => 0,
        ];

        // Dado real da API ML: total de anúncios concorrentes para esta keyword (oferta).
        $totalListings = $this->getSearchResultsCount($keyword, $categoryId);
        $scores['total_listings'] = $totalListings;

        // Competição real (menos concorrentes = mais oportunidade)
        $scores['competition'] = max(0, 100 - min(100, ($totalListings / 50)));

        // Relevance to base query
        $scores['relevance'] = $this->calculateRelevance($keyword, $baseQuery);

        // Commercial intent
        $scores['commercial_intent'] = $this->calculateCommercialIntent($keyword);

        // Weighted total
        $scores['total'] = round(
            ($scores['competition'] * 0.5) +
                ($scores['relevance'] * 0.3) +
                ($scores['commercial_intent'] * 0.2)
        );

        return $scores;
    }

    /**
     * Get search results count for keyword
     *
     * @param string $keyword
     * @param string $categoryId
     * @return int
     */
    private function getSearchResultsCount(string $keyword, string $categoryId): int
    {
        try {
            $response = $this->mlClient->get('/sites/MLB/search', [
                'q' => $keyword,
                'category' => $categoryId,
                'limit' => 1
            ]);

            return $response['paging']['total'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Calculate relevance between keyword and base query
     *
     * @param string $keyword
     * @param string $baseQuery
     * @return int Score 0-100
     */
    private function calculateRelevance(string $keyword, string $baseQuery): int
    {
        $keyword = mb_strtolower($keyword);
        $baseQuery = mb_strtolower($baseQuery);

        // Exact match
        if ($keyword === $baseQuery) {
            return 100;
        }

        // Contains base query
        if (mb_strpos($keyword, $baseQuery) !== false || mb_strpos($baseQuery, $keyword) !== false) {
            return 80;
        }

        // Word overlap
        $keywordWords = explode(' ', $keyword);
        $queryWords = explode(' ', $baseQuery);

        $overlap = count(array_intersect($keywordWords, $queryWords));
        $total = count(array_unique(array_merge($keywordWords, $queryWords)));

        return (int) round(($overlap / max($total, 1)) * 60);
    }

    /**
     * Calculate commercial intent of keyword
     *
     * @param string $keyword
     * @return int Score 0-100
     */
    private function calculateCommercialIntent(string $keyword): int
    {
        $keyword = mb_strtolower($keyword);

        // High intent words
        $highIntent = ['comprar', 'preço', 'barato', 'promoção', 'desconto', 'oferta', 'novo', 'original'];
        $mediumIntent = ['melhor', 'top', 'bom', 'qualidade', 'review'];

        foreach ($highIntent as $word) {
            if (mb_strpos($keyword, $word) !== false) {
                return 90;
            }
        }

        foreach ($mediumIntent as $word) {
            if (mb_strpos($keyword, $word) !== false) {
                return 60;
            }
        }

        // Technical specs indicate buying research
        if (preg_match('/\d+gb|\d+hz|\d+mp|\d+pol|ipx\d/i', $keyword)) {
            return 70;
        }

        return 50; // Default moderate intent
    }

    /**
     * Extract keywords from title
     *
     * @param string $title
     * @return array
     */
    private function extractKeywordsFromTitle(string $title): array
    {
        // Remove common stopwords
        $stopwords = ['de', 'da', 'do', 'para', 'com', 'sem', 'em', 'na', 'no', 'a', 'o', 'e'];

        $words = explode(' ', mb_strtolower($title));
        $keywords = [];

        foreach ($words as $word) {
            $word = trim($word, '.,;:!?()[]{}');

            if (mb_strlen($word) >= 3 && !in_array($word, $stopwords)) {
                $keywords[] = $word;
            }
        }

        return $keywords;
    }

    /**
     * Generate fallback keywords when API fails
     *
     * @param string $query
     * @return array
     */
    private function generateFallbackKeywords(string $query): array
    {
        $words = explode(' ', mb_strtolower($query));
        $fallback = $words;

        // Add common modifiers
        $modifiers = ['novo', 'original', 'barato', 'melhor', 'top'];

        return array_merge($fallback, array_slice($modifiers, 0, 3));
    }
}
