<?php

declare(strict_types=1);

namespace App\Services\AI\Analyzers;

use App\Services\MercadoLivreClient;

/**
 * Competitive Analysis Service
 * Analyzes competitor listings to extract insights
 */
class CompetitiveAnalysisService
{
    private MercadoLivreClient $mlClient;
    private ?int $accountId;

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);
    }

    /**
     * Analyze competitor listings for a product
     *
     * @param string $query Search query
     * @param array $options Analysis options
     * @return array Competitive insights
     */
    public function analyzeCompetitors(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 10;
        $categoryId = $options['category_id'] ?? null;

        // Search for top competitors
        $competitors = $this->findTopCompetitors($query, $categoryId, $limit);

        if (empty($competitors)) {
            return [
                'error' => 'No competitors found',
                'query' => $query,
            ];
        }

        // Analyze their listings
        $analysis = [
            'total_analyzed' => count($competitors),
            'query' => $query,
            'top_performers' => [],
            'insights' => [],
            'recommendations' => [],
        ];

        // Extract top performers
        $analysis['top_performers'] = array_slice($competitors, 0, 3);

        // Analyze titles
        $titleAnalysis = $this->analyzeTitles($competitors);
        $analysis['insights']['titles'] = $titleAnalysis;

        // Analyze pricing
        $priceAnalysis = $this->analyzePricing($competitors);
        $analysis['insights']['pricing'] = $priceAnalysis;

        // Analyze attributes
        $attributeAnalysis = $this->analyzeAttributes($competitors);
        $analysis['insights']['attributes'] = $attributeAnalysis;

        // Generate recommendations
        $analysis['recommendations'] = $this->generateRecommendations($analysis['insights']);

        return $analysis;
    }

    /**
     * Find top performing competitor listings
     *
     * @param string $query
     * @param string|null $categoryId
     * @param int $limit
     * @return array
     */
    private function findTopCompetitors(string $query, ?string $categoryId, int $limit): array
    {
        try {
            $params = [
                'q' => $query,
                'limit' => $limit,
                'sort' => 'relevance', // or 'sold_quantity'
            ];

            if ($categoryId) {
                $params['category'] = $categoryId;
            }

            $response = $this->mlClient->get('/sites/MLB/search', $params);

            if (isset($response['error']) || empty($response['results'])) {
                return [];
            }

            $competitors = [];

            foreach ($response['results'] as $item) {
                // Get full item details
                $itemDetails = $this->mlClient->get("/items/{$item['id']}");

                if (!isset($itemDetails['error'])) {
                    $competitors[] = [
                        'id' => $item['id'],
                        'title' => $item['title'],
                        'price' => $item['price'],
                        'sold_quantity' => $item['sold_quantity'] ?? 0,
                        'condition' => $item['condition'],
                        'thumbnail' => $item['thumbnail'] ?? '',
                        'attributes' => $itemDetails['attributes'] ?? [],
                        'description' => '', // Would need separate API call
                        'seller_reputation' => $item['seller']['seller_reputation'] ?? [],
                    ];
                }

                // Rate limiting
                usleep(100000); // 100ms delay
            }

            // Sort by sold quantity
            usort($competitors, function ($a, $b) {
                return $b['sold_quantity'] <=> $a['sold_quantity'];
            });

            return $competitors;
        } catch (\Exception $e) {
            log_warning('Error finding competitors', ['service' => 'CompetitiveAnalysisService', 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Analyze competitor titles
     *
     * @param array $competitors
     * @return array
     */
    private function analyzeTitles(array $competitors): array
    {
        $lengths = [];
        $commonWords = [];
        $patterns = [];

        foreach ($competitors as $comp) {
            $title = $comp['title'];
            $lengths[] = mb_strlen($title);

            // Extract words
            $words = explode(' ', mb_strtolower($title));
            foreach ($words as $word) {
                if (mb_strlen($word) > 3) {
                    $commonWords[] = $word;
                }
            }

            // Detect patterns (e.g., Brand + Model + Features)
            if (preg_match('/^([A-Z][a-z]+)\s+([A-Z0-9]+)/', $title)) {
                $patterns['brand_model_first']++;
            }
        }

        $wordFrequency = array_count_values($commonWords);
        arsort($wordFrequency);

        return [
            'avg_length' => round(array_sum($lengths) / max(count($lengths), 1)),
            'min_length' => min($lengths),
            'max_length' => max($lengths),
            'most_common_words' => array_slice($wordFrequency, 0, 10, true),
            'patterns' => $patterns,
        ];
    }

    /**
     * Analyze competitor pricing
     *
     * @param array $competitors
     * @return array
     */
    private function analyzePricing(array $competitors): array
    {
        $prices = array_column($competitors, 'price');

        if (empty($prices)) {
            return ['error' => 'No pricing data'];
        }

        sort($prices);

        $count = count($prices);
        $median = $count % 2 === 0
            ? ($prices[(int) ($count / 2) - 1] + $prices[(int) ($count / 2)]) / 2
            : $prices[(int) floor($count / 2)];

        return [
            'min' => min($prices),
            'max' => max($prices),
            'avg' => round(array_sum($prices) / $count, 2),
            'median' => round($median, 2),
            'distribution' => [
                'low' => count(array_filter($prices, fn(float|int $p): bool => $p < $median * 0.8)),
                'medium' => count(array_filter($prices, fn(float|int $p): bool => $p >= $median * 0.8 && $p <= $median * 1.2)),
                'high' => count(array_filter($prices, fn(float|int $p): bool => $p > $median * 1.2)),
            ],
            'recommended_range' => [
                'min' => round($median * 0.9, 2),
                'max' => round($median * 1.1, 2),
            ],
        ];
    }

    /**
     * Analyze competitor attributes
     *
     * @param array $competitors
     * @return array
     */
    private function analyzeAttributes(array $competitors): array
    {
        $attributeFrequency = [];
        $attributeValues = [];

        foreach ($competitors as $comp) {
            foreach ($comp['attributes'] as $attr) {
                $attrId = $attr['id'];
                $attrValue = $attr['value_name'] ?? $attr['value'] ?? '';

                // Count frequency
                if (!isset($attributeFrequency[$attrId])) {
                    $attributeFrequency[$attrId] = [
                        'name' => $attr['name'] ?? $attrId,
                        'count' => 0,
                        'values' => [],
                    ];
                }

                $attributeFrequency[$attrId]['count']++;

                // Track values
                if (!isset($attributeValues[$attrId][$attrValue])) {
                    $attributeValues[$attrId][$attrValue] = 0;
                }
                $attributeValues[$attrId][$attrValue]++;
            }
        }

        // Calculate usage percentage
        $totalCompetitors = count($competitors);
        foreach ($attributeFrequency as $attrId => &$data) {
            $data['usage_percentage'] = round(($data['count'] / $totalCompetitors) * 100);
            $data['most_common_values'] = $attributeValues[$attrId] ?? [];
            arsort($data['most_common_values']);
            $data['most_common_values'] = array_slice($data['most_common_values'], 0, 5, true);
        }

        // Sort by usage
        uasort($attributeFrequency, fn($a, $b) => $b['usage_percentage'] <=> $a['usage_percentage']);

        return [
            'total_unique_attributes' => count($attributeFrequency),
            'avg_attributes_per_listing' => round(
                array_sum(array_map(fn(array $c): int => count($c['attributes']), $competitors)) / $totalCompetitors
            ),
            'attribute_usage' => array_slice($attributeFrequency, 0, 15, true),
        ];
    }

    /**
     * Generate recommendations based on insights
     *
     * @param array $insights
     * @return array
     */
    private function generateRecommendations(array $insights): array
    {
        $recommendations = [];

        // Title recommendations
        if (isset($insights['titles'])) {
            $avgLen = $insights['titles']['avg_length'];
            $recommendations[] = [
                'type' => 'title',
                'priority' => 'high',
                'recommendation' => "Use títulos com aproximadamente {$avgLen} caracteres (média dos concorrentes)",
                'impact' => 'Melhora relevância nos resultados de busca',
            ];

            if (!empty($insights['titles']['most_common_words'])) {
                $topWords = array_keys(array_slice($insights['titles']['most_common_words'], 0, 3));
                $recommendations[] = [
                    'type' => 'keywords',
                    'priority' => 'high',
                    'recommendation' => "Incluir palavras-chave populares: " . implode(', ', $topWords),
                    'impact' => 'Aumenta visibilidade em buscas',
                ];
            }
        }

        // Price recommendations
        if (isset($insights['pricing'])) {
            $pricing = $insights['pricing'];
            $recommendations[] = [
                'type' => 'pricing',
                'priority' => 'medium',
                'recommendation' => sprintf(
                    "Preço recomendado entre R$ %.2f e R$ %.2f (mediana: R$ %.2f)",
                    $pricing['recommended_range']['min'],
                    $pricing['recommended_range']['max'],
                    $pricing['median']
                ),
                'impact' => 'Posicionamento competitivo no mercado',
            ];
        }

        // Attribute recommendations
        if (isset($insights['attributes'])) {
            $topAttributes = array_slice($insights['attributes']['attribute_usage'], 0, 5);

            foreach ($topAttributes as $attr) {
                if ($attr['usage_percentage'] >= 70) {
                    $recommendations[] = [
                        'type' => 'attribute',
                        'priority' => 'high',
                        'recommendation' => "Preencher atributo '{$attr['name']}' ({$attr['usage_percentage']}% dos concorrentes usam)",
                        'impact' => 'Paridade competitiva e melhor ranqueamento',
                    ];
                }
            }
        }

        return $recommendations;
    }
}
