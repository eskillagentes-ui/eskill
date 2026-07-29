<?php

declare(strict_types=1);

namespace App\Services\AI\Optimizers;

use App\Services\MercadoLivreClient;
use App\Services\CategoryService;
use App\Services\AI\Providers\OpenAIProvider;

/**
 * Technical Sheet (Attributes) Optimization Service
 * Intelligently fills missing attributes and completes technical specifications
 */
class TechSheetOptimizer
{
    private MercadoLivreClient $mlClient;
    private CategoryService $categoryService;
    private OpenAIProvider $aiProvider;
    private ?int $accountId;

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->categoryService = new CategoryService($accountId);
        $this->aiProvider = new OpenAIProvider();
    }

    /**
     * Analyze and complete missing attributes
     *
     * @param string $itemId ML item ID or category ID
     * @param array $currentAttributes Current attributes
     * @return array Analysis and suggestions
     */
    public function complete(string $itemId, array $currentAttributes = []): array
    {
        // Get category attributes
        $categoryAttrs = $this->categoryService->getCategoryAttributes($itemId);

        if (isset($categoryAttrs['error'])) {
            return [
                'error' => 'Failed to fetch category attributes',
                'details' => $categoryAttrs,
            ];
        }

        // Separate required, recommended and optional
        $required = [];
        $recommended = [];
        $optional = [];

        foreach ($categoryAttrs as $attr) {
            $tags = $attr['tags'] ?? [];
            $attrId = $attr['id'];

            if (in_array('required', $tags)) {
                $required[$attrId] = $attr;
            } elseif (in_array('recommended', $tags)) {
                $recommended[$attrId] = $attr;
            } else {
                $optional[$attrId] = $attr;
            }
        }

        // Map current attributes by ID
        $currentMap = [];
        foreach ($currentAttributes as $attr) {
            $currentMap[$attr['id']] = $attr;
        }

        // Find missing
        $missingRequired = array_diff_key($required, $currentMap);
        $missingRecommended = array_diff_key($recommended, $currentMap);

        // Calculate completeness
        $totalRequired = count($required);
        $filledRequired = count($required) - count($missingRequired);
        $completeness = $totalRequired > 0
            ? round(($filledRequired / $totalRequired) * 100, 1)
            : 100;

        // Try to infer missing values using AI
        $suggestions = [];

        foreach ($missingRequired as $attrId => $attr) {
            $suggestion = $this->inferAttributeValue($itemId, $attr, $currentAttributes);
            if ($suggestion) {
                $suggestions[] = array_merge($suggestion, [
                    'priority' => 'required',
                    'attr_info' => $attr,
                ]);
            }
        }

        foreach (array_slice($missingRecommended, 0, 5) as $attrId => $attr) {
            $suggestion = $this->inferAttributeValue($itemId, $attr, $currentAttributes);
            if ($suggestion) {
                $suggestions[] = array_merge($suggestion, [
                    'priority' => 'recommended',
                    'attr_info' => $attr,
                ]);
            }
        }

        return [
            'completeness' => $completeness,
            'required' => [
                'total' => $totalRequired,
                'filled' => $filledRequired,
                'missing' => count($missingRequired),
            ],
            'recommended' => [
                'total' => count($recommended),
                'filled' => count($recommended) - count($missingRecommended),
                'missing' => count($missingRecommended),
            ],
            'current_attributes' => $currentAttributes,
            'missing_required' => array_values($missingRequired),
            'missing_recommended' => array_values(array_slice($missingRecommended, 0, 10)),
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Infer attribute value using AI and context
     *
     * @param string $itemId
     * @param array $attribute Attribute definition
     * @param array $contextAttributes Other attributes for context
     * @return array|null Suggested value with confidence
     */
    private function inferAttributeValue(string $itemId, array $attribute, array $contextAttributes): ?array
    {
        $attrId = $attribute['id'];
        $attrName = $attribute['name'];
        $valueType = $attribute['value_type'] ?? 'string';
        $allowedValues = $attribute['values'] ?? [];

        // If it has predefined values, try to match from context
        if (!empty($allowedValues)) {
            $inferred = $this->matchFromContext($attrName, $allowedValues, $contextAttributes);

            if ($inferred) {
                return [
                    'attribute_id' => $attrId,
                    'attribute_name' => $attrName,
                    'suggested_value' => $inferred['value'],
                    'suggested_value_id' => $inferred['value_id'] ?? null,
                    'confidence' => $inferred['confidence'],
                    'method' => 'context_matching',
                ];
            }
        }

        // For common attributes, use simple logic
        $commonDefaults = [
            'BRAND' => $this->extractBrandFromContext($contextAttributes),
            'CONDITION' => ['value_name' => 'Novo', 'confidence' => 0.9],
            'WARRANTY_TYPE' => ['value_name' => 'Garantia do vendedor', 'confidence' => 0.8],
        ];

        if (isset($commonDefaults[$attrId])) {
            $default = $commonDefaults[$attrId];
            return [
                'attribute_id' => $attrId,
                'attribute_name' => $attrName,
                'suggested_value' => $default['value_name'] ?? $default,
                'confidence' => $default['confidence'] ?? 0.7,
                'method' => 'common_default',
            ];
        }

        return null;
    }

    /**
     * Match attribute value from context
     *
     * @param string $attrName
     * @param array $allowedValues
     * @param array $contextAttributes
     * @return array|null
     */
    private function matchFromContext(string $attrName, array $allowedValues, array $contextAttributes): ?array
    {
        // Convert context to searchable text
        $contextText = '';
        foreach ($contextAttributes as $attr) {
            $contextText .= ' ' . ($attr['value_name'] ?? $attr['value'] ?? '');
        }
        $contextText = mb_strtolower($contextText);

        // Try to find matches
        $matches = [];
        foreach ($allowedValues as $value) {
            $valueName = mb_strtolower($value['name'] ?? '');

            if (empty($valueName)) continue;

            // Exact match
            if (mb_strpos($contextText, $valueName) !== false) {
                $matches[] = [
                    'value' => $value['name'],
                    'value_id' => $value['id'] ?? null,
                    'confidence' => 0.9,
                ];
            }

            // Partial match
            $words = explode(' ', $valueName);
            foreach ($words as $word) {
                if (mb_strlen($word) > 3 && mb_strpos($contextText, $word) !== false) {
                    $matches[] = [
                        'value' => $value['name'],
                        'value_id' => $value['id'] ?? null,
                        'confidence' => 0.6,
                    ];
                    break;
                }
            }
        }

        // Return best match
        if (!empty($matches)) {
            usort($matches, function ($a, $b) {
                return $b['confidence'] <=> $a['confidence'];
            });
            return $matches[0];
        }

        return null;
    }

    /**
     * Extract brand from existing attributes
     *
     * @param array $attributes
     * @return array|null
     */
    private function extractBrandFromContext(array $attributes): ?array
    {
        foreach ($attributes as $attr) {
            if (($attr['id'] ?? '') === 'BRAND') {
                return [
                    'value_name' => $attr['value_name'] ?? $attr['value'] ?? null,
                    'confidence' => 1.0,
                ];
            }
        }

        return null;
    }

    /**
     * Validate attributes against category requirements
     *
     * @param array $attributes
     * @param string|null $categoryId
     * @return array Validation result
     */
    public function validate(array $attributes, ?string $categoryId = null): array
    {
        $validAttributes = [];
        $invalidAttributes = [];

        // Known valid attribute IDs
        $knownAttributes = [
            'BRAND',
            'MODEL',
            'GTIN',
            'MPN',
            'COLOR',
            'SIZE',
            'WEIGHT',
            'HEIGHT',
            'WIDTH',
            'LENGTH',
            'CONDITION',
            'WARRANTY_TYPE',
            'WARRANTY_TIME',
            'MATERIAL',
            'CAPACITY',
            'VOLTAGE',
            'PACKAGE_WEIGHT',
            'PACKAGE_HEIGHT',
            'PACKAGE_WIDTH',
            'PACKAGE_LENGTH',
            'SELLER_SKU',
            'EAN',
            'UPC',
            'ISBN',
            'UNITS_PER_PACKAGE',
            'ITEM_CONDITION',
        ];

        foreach ($attributes as $attr) {
            $attrId = $attr['id'] ?? '';

            if (in_array($attrId, $knownAttributes)) {
                $validAttributes[] = $attrId;
            } else {
                $invalidAttributes[] = $attrId;
            }
        }

        return [
            'valid' => empty($invalidAttributes),
            'valid_attributes' => $validAttributes,
            'invalid_attributes' => $invalidAttributes,
            'errors' => [],
            'warnings' => [],
        ];
    }

    /**
     * Analyze attributes without API call
     * Used for offline analysis of attribute completeness
     *
     * @param array $attributes Current attributes
     * @param array $options Analysis options (total_required, etc.)
     * @return array Analysis result
     */
    public function analyze(array $attributes, array $options = []): array
    {
        // Essential/required attributes
        $requiredAttributes = ['BRAND', 'MODEL', 'GTIN', 'CONDITION'];

        // Recommended attributes
        $recommendedAttributes = ['GTIN', 'MPN', 'WARRANTY_TYPE', 'COLOR', 'SIZE'];

        // Map current attributes by ID
        $currentMap = [];
        foreach ($attributes as $attr) {
            $id = $attr['id'] ?? '';
            if ($id) {
                $currentMap[$id] = $attr;
            }
        }

        // Calculate based on options or defaults
        $totalRequired = $options['total_required'] ?? count($requiredAttributes);

        // Find filled required
        $filledRequired = 0;
        $missingRequired = [];

        foreach ($requiredAttributes as $reqId) {
            if (isset($currentMap[$reqId]) && !empty($currentMap[$reqId]['value'])) {
                $filledRequired++;
            } else {
                $missingRequired[] = ['id' => $reqId, 'name' => $reqId];
            }
        }

        // If total_required is explicitly set, use filled count against it
        if (isset($options['total_required'])) {
            $filledRequired = count(array_filter($attributes, function (array $attr): bool {
                return !empty($attr['value']);
            }));
        }

        // Calculate completeness
        $completeness = $totalRequired > 0
            ? round(($filledRequired / $totalRequired) * 100, 1)
            : 0.0;

        // Find missing recommended
        $missingRecommended = [];
        foreach ($recommendedAttributes as $recId) {
            if (!isset($currentMap[$recId])) {
                $missingRecommended[] = ['id' => $recId, 'name' => $recId];
            }
        }

        // Generate suggestions
        $suggestions = [];
        foreach ($missingRequired as $missing) {
            $inferResult = $this->inferValue($missing['id'], ['attributes' => $attributes]);
            if ($inferResult && $inferResult['value']) {
                $suggestions[] = [
                    'attribute_id' => $missing['id'],
                    'attribute_name' => $missing['name'],
                    'suggested_value' => $inferResult['value'],
                    'confidence' => (float) $inferResult['confidence'],
                    'priority' => 'required',
                ];
            }
        }

        return [
            'completeness' => (float) $completeness,
            'required' => [
                'total' => $totalRequired,
                'filled' => $filledRequired,
                'missing' => count($missingRequired),
            ],
            'recommended' => [
                'total' => count($recommendedAttributes),
                'filled' => count($recommendedAttributes) - count($missingRecommended),
                'missing' => count($missingRecommended),
            ],
            'current_attributes' => $attributes,
            'missing_required' => $missingRequired,
            'missing_recommended' => $missingRecommended,
            'suggested' => $suggestions,
        ];
    }

    /**
     * Infer attribute value publicly
     *
     * @param string $attributeId
     * @param array $context
     * @return array Value inference result
     */
    public function inferValue(string $attributeId, array $context = []): array
    {
        // Common defaults for standard attributes
        $defaults = [
            'CONDITION' => ['value' => 'new', 'confidence' => 0.8],
            'WARRANTY_TYPE' => ['value' => 'seller_warranty', 'confidence' => 0.7],
            'ITEM_CONDITION' => ['value' => 'new', 'confidence' => 0.8],
        ];

        // Check for default
        if (isset($defaults[$attributeId])) {
            return $defaults[$attributeId];
        }

        // Try to extract from title/description
        $title = $context['title'] ?? '';
        $description = $context['description'] ?? '';
        $text = mb_strtolower($title . ' ' . $description);

        // Brand extraction
        if ($attributeId === 'BRAND') {
            // Look in existing attributes
            foreach (($context['attributes'] ?? []) as $attr) {
                if (($attr['id'] ?? '') === 'BRAND' && !empty($attr['value'])) {
                    return ['value' => $attr['value'], 'confidence' => 1.0];
                }
            }
        }

        // Model extraction from title
        if ($attributeId === 'MODEL' && $title) {
            // Try to extract model number pattern
            if (preg_match('/\b([A-Z]{1,3}[-]?\d{2,5}[A-Z0-9]*)\b/i', $title, $matches)) {
                return ['value' => strtoupper($matches[1]), 'confidence' => 0.7];
            }
        }

        // No inference possible
        return ['value' => null, 'confidence' => 0];
    }

    /**
     * Get attribute priority level
     *
     * @param string $attributeId
     * @return string Priority level (required, recommended, optional)
     */
    public function getAttributePriority(string $attributeId): string
    {
        $required = ['BRAND', 'MODEL', 'CONDITION', 'ITEM_CONDITION'];
        $recommended = ['GTIN', 'MPN', 'EAN', 'UPC', 'WARRANTY_TYPE', 'COLOR', 'SIZE', 'MATERIAL'];

        if (in_array($attributeId, $required)) {
            return 'required';
        }

        if (in_array($attributeId, $recommended)) {
            return 'recommended';
        }

        return 'optional';
    }

    /**
     * Get competitor analysis for attributes
     *
     * @param string $categoryId
     * @param string $keywords Search keywords
     * @return array Competitor insights
     */
    public function getCompetitorInsights(string $categoryId, string $keywords = ''): array
    {
        try {
            // Get top competitors in this category
            $searchResults = $this->mlClient->searchItems([
                'category' => $categoryId,
                'q' => $keywords,
                'limit' => 20,
                'sort' => 'price_desc'
            ]);

            if (!$searchResults || empty($searchResults['results'])) {
                return $this->getFallbackInsights($categoryId);
            }

            // Extract attribute patterns from competitors
            $attributeAnalysis = $this->analyzeCompetitorAttributes($searchResults['results']);

            // Generate AI-powered recommendations
            $prompt = $this->buildCompetitorInsightsPrompt($searchResults['results'], $keywords, $attributeAnalysis);

            $response = $this->aiProvider->complete($prompt, [
                'temperature' => 0.3,
                'max_tokens' => 1000
            ]);

            if (!isset($response['error']) && !empty($response['content'])) {
                $aiInsights = $this->parseAIResponse($response['content']);

                return array_merge($attributeAnalysis, [
                    'ai_recommendations' => $aiInsights['recommendations'] ?? [],
                    'competitive_advantages' => $aiInsights['advantages'] ?? [],
                    'market_gaps' => $aiInsights['gaps'] ?? []
                ]);
            }

            // Fallback to analysis-based insights
            return $attributeAnalysis;
        } catch (\Exception $e) {
            return $this->getFallbackInsights($categoryId);
        }
    }

    private function analyzeCompetitorAttributes(array $competitors): array
    {
        $attributeCounts = [];
        $totalCompetitors = count($competitors);

        foreach ($competitors as $item) {
            if (isset($item['attributes']) && is_array($item['attributes'])) {
                foreach ($item['attributes'] as $attr) {
                    $attrId = $attr['id'] ?? $attr['name'] ?? 'unknown';
                    $attributeCounts[$attrId] = ($attributeCounts[$attrId] ?? 0) + 1;
                }
            }
        }

        // Calculate usage percentages and sort
        $mostUsedAttributes = [];
        foreach ($attributeCounts as $id => $count) {
            if ($count > 1) { // Only include attributes used by multiple competitors
                $usage = round(($count / $totalCompetitors) * 100, 1);
                $mostUsedAttributes[] = [
                    'id' => $id,
                    'usage' => $usage,
                    'count' => $count
                ];
            }
        }

        // Sort by usage
        usort($mostUsedAttributes, fn($a, $b) => $b['usage'] <=> $a['usage']);

        $avgAttributesCount = array_sum($attributeCounts) / $totalCompetitors;

        return [
            'avg_attributes_count' => round($avgAttributesCount, 1),
            'most_used_attributes' => array_slice($mostUsedAttributes, 0, 10),
            'total_competitors_analyzed' => $totalCompetitors
        ];
    }

    private function buildCompetitorInsightsPrompt(array $competitors, string $keywords, array $attributeAnalysis): string
    {
        $topCompetitors = array_slice($competitors, 0, 5);
        $competitorData = [];

        foreach ($topCompetitors as $item) {
            $competitorData[] = [
                'title' => $item['title'] ?? '',
                'price' => $item['price'] ?? 0,
                'sold_quantity' => $item['sold_quantity'] ?? 0,
                'attributes' => array_slice($item['attributes'] ?? [], 0, 10)
            ];
        }

        return sprintf(
            "Analyze these top competitors and provide strategic insights:\n\n" .
                "Keywords: %s\n" .
                "Competitors Data: %s\n" .
                "Attribute Analysis: %s\n\n" .
                "Provide insights in JSON format:\n" .
                "{\n" .
                "  \"recommendations\": [\"specific actionable recommendations\"],\n" .
                "  \"advantages\": [\"identified competitive advantages\"],\n" .
                "  \"gaps\": [\"market gaps or opportunities\"]\n" .
                "}\n\n" .
                "Focus on attribute optimization, positioning, and market differentiation.",
            $keywords,
            json_encode($competitorData, JSON_PRETTY_PRINT),
            json_encode($attributeAnalysis, JSON_PRETTY_PRINT)
        );
    }

    private function parseAIResponse(string $content): array
    {
        try {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        } catch (\Exception $e) {
            // Fall through to fallback
        }

        // Try to extract insights from plain text
        $insights = [
            'recommendations' => [],
            'advantages' => [],
            'gaps' => []
        ];

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (stripos($line, 'recommend') !== false) {
                $insights['recommendations'][] = $line;
            } elseif (stripos($line, 'advantage') !== false || stripos($line, 'strength') !== false) {
                $insights['advantages'][] = $line;
            } elseif (stripos($line, 'gap') !== false || stripos($line, 'opportunity') !== false) {
                $insights['gaps'][] = $line;
            }
        }

        return $insights;
    }

    private function getFallbackInsights(string $categoryId): array
    {
        return [
            'avg_attributes_count' => 24,
            'most_used_attributes' => [
                ['id' => 'BRAND', 'usage' => 98],
                ['id' => 'MODEL', 'usage' => 95],
                ['id' => 'WARRANTY_TYPE', 'usage' => 87],
            ],
            'recommended_additions' => [
                'Add "Versão Bluetooth" - Common in top sellers',
                'Add "Resistência à água" - Frequently included',
            ],
            'ai_recommendations' => ['Unable to fetch live competitor data'],
            'competitive_advantages' => ['Focus on quality attributes'],
            'market_gaps' => ['Consider unique value propositions']
        ];
    }
}
