<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Services\MercadoLivreClient;
use App\Services\ItemService;
use App\Services\CategoryService;
use App\Services\AI\Core\AIProviderManager;
use PDO;

/**
 * 🚀 TITLE KILLER - Otimizador de Títulos Matador
 *
 * Estratégias avançadas de SEO para títulos:
 * - Semântica perfeita
 * - Cauda longa (long-tail keywords)
 * - Máximo aproveitamento dos 60 caracteres
 * - Keywords de alta conversão
 *
 * @author AI Development Team
 * @version 1.0.0
 */
class TitleKiller
{
    private ?AIProviderManager $aiProvider;
    private ?int $accountId;
    private ?ItemService $itemService;

    // ML Title Rules
    private const MAX_LENGTH = 60;
    private const MIN_LENGTH = 40;
    private const OPTIMAL_WORDS = [5, 8]; // min, max

    // High-converting patterns by category
    private const TITLE_PATTERNS = [
        'electronics' => [
            'pattern' => '{brand} {model} {spec1} {spec2} {differentiator}',
            'examples' => ['Samsung Galaxy S23 128GB 5G Original Lacrado'],
            'must_include' => ['brand', 'model', 'storage/size', 'condition'],
        ],
        'fashion' => [
            'pattern' => '{product} {material} {style} {size_info} {gender}',
            'examples' => ['Camisa Polo Algodão Premium Slim Fit Masculina'],
            'must_include' => ['product', 'material', 'style', 'gender'],
        ],
        'home' => [
            'pattern' => '{product} {material} {size} {room} {style}',
            'examples' => ['Sofá Retrátil Veludo 3 Lugares Sala Premium'],
            'must_include' => ['product', 'size', 'room'],
        ],
        'sports' => [
            'pattern' => '{product} {brand} {sport} {benefit} {spec}',
            'examples' => ['Tênis Nike Running Corrida Ultra Leve 42'],
            'must_include' => ['product', 'brand', 'sport', 'size'],
        ],
        'default' => [
            'pattern' => '{product} {brand} {spec1} {spec2} {benefit}',
            'examples' => ['Produto Marca Especificação Benefício'],
            'must_include' => ['product', 'key_spec'],
        ],
    ];

    // Power words that increase CTR
    private const POWER_WORDS = [
        'pt_BR' => [
            'trust' => ['Original', 'Genuíno', 'Autêntico', 'Lacrado', 'Garantia'],
            'urgency' => ['Pronta Entrega', 'Envio Imediato', 'Última Unidade'],
            'quality' => ['Premium', 'Profissional', 'Alta Qualidade', 'Top'],
            'benefit' => ['Ultra', 'Super', 'Mega', 'Plus', 'Pro', 'Max'],
        ],
    ];

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->aiProvider = new AIProviderManager();
        $this->itemService = new ItemService($accountId);
    }

    /**
     * 🚀 Otimizar título de um item específico
     */
    public function optimize(string $itemId): array
    {
        try {
            $item = $this->itemService->getItem($itemId);
            if (!$item || isset($item['error'])) {
                return [
                    'success' => false,
                    'error' => $item['error'] ?? 'Item não encontrado'
                ];
            }

            return $this->generateKillerTitle($item);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 🔥 Gerar título matador usando IA
     *
     * @param array $productData
     * @return array
     */
    public function generateKillerTitle(array $productData): array
    {
        $category = $this->detectCategory($productData);
        $keywords = $this->extractKeywords($productData);
        $longTailKeywords = $this->generateLongTailKeywords($productData);

        $prompt = $this->buildKillerPrompt($productData, $category, $keywords, $longTailKeywords);

        try {
            $response = $this->aiProvider->chat([
                [
                    'role' => 'system',
                    'content' => $this->getSystemPrompt()
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ], ['temperature' => 0.7, 'max_tokens' => 1000]);

            if (isset($response['error'])) {
                return $this->generateFallbackTitle($productData, $keywords);
            }

            $result = $this->parseAIResponse($response['content']);

            // Validate and enhance
            $result = $this->validateAndEnhance($result, $productData);

            return [
                'success' => true,
                'titles' => $result['titles'],
                'primary' => $result['titles'][0] ?? '',
                'keywords_used' => $keywords,
                'long_tail' => $longTailKeywords,
                'strategy_applied' => $category,
                'seo_score' => $this->calculateTitleScore($result['titles'][0] ?? ''),
            ];
        } catch (\Exception $e) {
            return $this->generateFallbackTitle($productData, $keywords);
        }
    }

    /**
     * 📊 Gerar Long-Tail Keywords
     */
    public function generateLongTailKeywords(array $productData): array
    {
        $baseProduct = $productData['title'] ?? $productData['product_name'] ?? '';
        $brand = $productData['brand'] ?? '';
        $category = $productData['category'] ?? '';

        $longTail = [];

        // Pattern: Product + Attribute
        if (!empty($productData['attributes'])) {
            foreach (array_slice($productData['attributes'], 0, 5) as $attr) {
                $value = $attr['value_name'] ?? $attr['value'] ?? '';
                if ($value && mb_strlen($value) < 20) {
                    $longTail[] = trim("{$baseProduct} {$value}");
                }
            }
        }

        // Pattern: Product + Brand + Spec
        if ($brand) {
            $longTail[] = "{$brand} {$baseProduct}";
            $longTail[] = "{$baseProduct} {$brand} original";
        }

        // Pattern: Product + Use Case
        $useCases = $this->getUseCases($category);
        foreach ($useCases as $use) {
            $longTail[] = "{$baseProduct} para {$use}";
        }

        // Pattern: Product + Specific Specs
        $specs = $this->extractSpecs($productData);
        foreach ($specs as $spec) {
            $longTail[] = "{$baseProduct} {$spec}";
        }

        return array_unique(array_filter($longTail));
    }

    /**
     * 🎯 Extrair Keywords de Alta Conversão
     */
    private function extractKeywords(array $productData): array
    {
        $keywords = [];

        // From title
        $title = $productData['title'] ?? '';
        $words = preg_split('/\s+/', $title);
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                $keywords[] = mb_strtolower($word);
            }
        }

        // From brand
        if (!empty($productData['brand'])) {
            $keywords[] = mb_strtolower($productData['brand']);
        }

        // From model
        if (!empty($productData['model'])) {
            $keywords[] = mb_strtolower($productData['model']);
        }

        // From key attributes
        $keyAttrs = ['BRAND', 'MODEL', 'COLOR', 'SIZE', 'MATERIAL'];
        foreach ($productData['attributes'] ?? [] as $attr) {
            if (in_array($attr['id'] ?? '', $keyAttrs)) {
                $keywords[] = mb_strtolower($attr['value_name'] ?? '');
            }
        }

        return array_unique(array_filter($keywords));
    }

    /**
     * 🏗️ Build Killer Prompt for AI
     */
    private function buildKillerPrompt(array $productData, string $category, array $keywords, array $longTail): string
    {
        $pattern = self::TITLE_PATTERNS[$category] ?? self::TITLE_PATTERNS['default'];

        return "Você é um especialista em SEO para Mercado Livre com 10+ anos de experiência.

PRODUTO:
- Nome: {$productData['title']}
- Marca: " . ($productData['brand'] ?? 'N/A') . "
- Modelo: " . ($productData['model'] ?? 'N/A') . "
- Categoria: {$category}

KEYWORDS DE ALTA CONVERSÃO:
" . implode(', ', array_slice($keywords, 0, 10)) . "

LONG-TAIL KEYWORDS:
" . implode(', ', array_slice($longTail, 0, 5)) . "

PADRÃO IDEAL PARA CATEGORIA:
{$pattern['pattern']}

EXEMPLOS DE TÍTULOS QUE VENDEM:
" . implode("\n", $pattern['examples']) . "

REGRAS CRÍTICAS:
1. MÁXIMO 60 caracteres (CRÍTICO - ML corta)
2. MÍNIMO 40 caracteres (usar todo espaço)
3. Colocar keyword principal no INÍCIO
4. Incluir marca se conhecida
5. Incluir especificação diferenciadora
6. Usar palavras de poder: " . implode(', ', self::POWER_WORDS['pt_BR']['trust']) . "
7. NUNCA usar: promoção, desconto, grátis, oferta
8. Capitalizar primeira letra de cada palavra

TAREFA:
Gere 5 títulos MATADORES otimizados para SEO.
Para cada título, inclua o score de SEO (0-100).

Responda em JSON:
{
  \"titles\": [
    {\"title\": \"Título 1\", \"score\": 95, \"keywords\": [\"kw1\", \"kw2\"]},
    ...
  ]
}";
    }

    /**
     * 📋 Get System Prompt
     */
    private function getSystemPrompt(): string
    {
        return "Você é o maior especialista em SEO para Mercado Livre do Brasil.
Seu objetivo é criar títulos que VENDEM.
Você conhece todos os algoritmos do ML e sabe exatamente como rankear #1.
Responda APENAS em JSON válido.";
    }

    /**
     * ✅ Validate and Enhance Titles
     */
    private function validateAndEnhance(array $result, array $productData): array
    {
        $validatedTitles = [];

        foreach ($result['titles'] ?? [] as $item) {
            $title = $item['title'] ?? $item;

            // Enforce length
            if (mb_strlen($title) > self::MAX_LENGTH) {
                $title = $this->smartTruncate($title, self::MAX_LENGTH);
            }

            // Capitalize properly
            $title = $this->properCapitalize($title);

            // Remove forbidden words
            $title = $this->removeForbiddenWords($title);

            $validatedTitles[] = [
                'title' => $title,
                'length' => mb_strlen($title),
                'score' => $this->calculateTitleScore($title),
            ];
        }

        // Sort by score
        usort($validatedTitles, fn($a, $b) => $b['score'] <=> $a['score']);

        return ['titles' => array_column($validatedTitles, 'title')];
    }

    /**
     * 📊 Calculate Title SEO Score
     */
    public function calculateTitleScore(string $title): int
    {
        $score = 100;
        $len = mb_strlen($title);

        // ML recomenda 45-80 chars. Antes o threshold era 60, penalizando títulos
        // perfeitamente válidos como "Amortecedor Dianteiro Honda CG 160 Titan Esquerdo" (52 chars)
        // com modelos completos que naturalmente ultrapassam 60 chars.
        if ($len < 30) {
            $score -= 30;
        } elseif ($len < 45) {
            $score -= 15;
        } elseif ($len > 80) {
            $score -= 15;
        }

        // Títulos sem nenhum dígito raramente são específicos o suficiente para peças/acessórios.
        // Mantido com penalidade reduzida pois nem todo produto requer número.
        if (!preg_match('/\d/', $title)) {
            $score -= 5;
        }

        // All caps: prejudica legibilidade e pode sinalizar spam ao indexador ML
        if ($len > 5 && $title === mb_strtoupper($title)) {
            $score -= 15;
        }

        // Symbols: %, #, !, $, @ etc. não devem aparecer em títulos ML
        if (preg_match('/[%#!$@&*()\[\]{}|\\\\<>]/', $title)) {
            $score -= 20;
        }

        // Contagem de palavras: 5-15 palavras são efetivas para ML
        $wordCount = str_word_count($title);
        if ($wordCount < 4) {
            $score -= 10;
        }
        // Acima de 15 palavras: provável keyword stuffing.
        // Antes era > 10, penalizando títulos de peças com compatibilidade descrita.
        if ($wordCount > 15) {
            $score -= 10;
        }

        // Power words removidos do scoring: termos de marketing (ORIGINAL, GRÁTIS)
        // não contribuem para relevância semântica no ML e podem ser penalizados.

        return max(0, min(100, $score));
    }

    // Helper methods
    private function detectCategory(array $productData): string
    {
        $categoryId = $productData['category_id'] ?? '';
        $title = mb_strtolower($productData['title'] ?? '');

        $patterns = [
            'electronics' => ['celular', 'fone', 'notebook', 'tv', 'samsung', 'apple', 'iphone'],
            'fashion' => ['camisa', 'calça', 'vestido', 'tênis', 'roupa', 'moda'],
            'home' => ['sofá', 'mesa', 'cadeira', 'cama', 'decoração'],
            'sports' => ['esporte', 'academia', 'fitness', 'corrida', 'bola'],
        ];

        foreach ($patterns as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($title, $kw) !== false) {
                    return $cat;
                }
            }
        }

        return 'default';
    }

    private function getUseCases(string $category): array
    {
        return match ($category) {
            'electronics' => ['trabalho', 'jogos', 'fotos', 'vídeos'],
            'fashion' => ['dia a dia', 'trabalho', 'festa', 'casual'],
            'home' => ['sala', 'quarto', 'cozinha', 'escritório'],
            'sports' => ['academia', 'corrida', 'treino', 'competição'],
            default => ['uso diário', 'presente'],
        };
    }

    private function extractSpecs(array $productData): array
    {
        $specs = [];
        foreach ($productData['attributes'] ?? [] as $attr) {
            $value = $attr['value_name'] ?? '';
            if (preg_match('/\d+\s*(gb|mb|kg|g|cm|mm|v|w|mah)/i', $value)) {
                $specs[] = $value;
            }
        }
        return array_slice($specs, 0, 3);
    }

    private function smartTruncate(string $text, int $maxLen): string
    {
        if (mb_strlen($text) <= $maxLen) return $text;

        $text = mb_substr($text, 0, $maxLen);
        $lastSpace = mb_strrpos($text, ' ');

        if ($lastSpace !== false && $lastSpace > $maxLen - 15) {
            $text = mb_substr($text, 0, $lastSpace);
        }

        return $text;
    }

    private function properCapitalize(string $text): string
    {
        $words = explode(' ', mb_strtolower($text));
        $lowerWords = ['de', 'da', 'do', 'das', 'dos', 'para', 'com', 'em', 'e'];

        return implode(' ', array_map(function ($word, $i) use ($lowerWords) {
            if ($i > 0 && in_array($word, $lowerWords)) {
                return $word;
            }
            return mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }, $words, array_keys($words)));
    }

    private function removeForbiddenWords(string $text): string
    {
        $forbidden = ['grátis', 'desconto', 'promoção', 'oferta', 'barato', 'liquidação'];
        foreach ($forbidden as $word) {
            $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $text);
        }
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function parseAIResponse(string $content): array
    {
        // Try JSON extraction
        if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
            $data = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
        return ['titles' => []];
    }

    /**
     * 🏗️ Generate Fallback Title (Pattern-Based)
     */
    private function generateFallbackTitle(array $productData, array $keywords): array
    {
        $category = $this->detectCategory($productData);
        $patternConfig = self::TITLE_PATTERNS[$category] ?? self::TITLE_PATTERNS['default'];
        $pattern = $patternConfig['pattern'];

        // Data Extraction for Placeholders
        $placeholders = [
            '{brand}' => $productData['brand'] ?? '',
            '{model}' => $productData['model'] ?? '',
            '{product}' => $productData['title'] ?? ($productData['brand'] ?? '') . ' ' . ($productData['model'] ?? ''),
            '{spec1}' => $this->extractSpecs($productData)[0] ?? '',
            '{spec2}' => $this->extractSpecs($productData)[1] ?? '',
            '{differentiator}' => 'Original', // Safe default
            '{material}' => $this->getAttributeValue($productData, 'MATERIAL') ?? '',
            '{style}' => $this->getAttributeValue($productData, 'STYLE') ?? '',
            '{size_info}' => $this->getAttributeValue($productData, 'SIZE') ?? '',
            '{gender}' => $this->getAttributeValue($productData, 'GENDER') ?? '',
            '{size}' => $this->getAttributeValue($productData, 'SIZE') ?? '',
            '{room}' => '', // Hard to guess
            '{sport}' => '', // Hard to guess
            '{benefit}' => 'Pronta Entrega', // Safe default
        ];

        // Fill Pattern
        $title = $pattern;
        foreach ($placeholders as $key => $value) {
            $title = str_replace($key, $value, $title);
        }

        // Cleanup (remove empty placeholders and double spaces)
        $title = preg_replace('/\{.*?\}/', '', $title);
        $title = trim(preg_replace('/\s+/', ' ', $title));

        // If title is too short or empty, fallback to simple concatenation
        if (mb_strlen($title) < 10) {
            $parts = [];
            if (!empty($productData['brand'])) $parts[] = $productData['brand'];
            if (!empty($productData['model'])) $parts[] = $productData['model'];
            $title = !empty($productData['title']) ? $productData['title'] : implode(' ', $parts);
        }

        // Enhance with keywords if space allows
        foreach ($keywords as $kw) {
            if (mb_stripos($title, $kw) === false && mb_strlen($title) + mb_strlen($kw) + 1 <= 60) {
                $title .= ' ' . mb_strtoupper(mb_substr($kw, 0, 1)) . mb_substr($kw, 1);
                break;
            }
        }

        $finalTitle = $this->smartTruncate($title, 60);

        return [
            'success' => true,
            'titles' => [$finalTitle],
            'primary' => $finalTitle,
            'keywords_used' => $keywords,
            'strategy_applied' => "fallback_pattern_{$category}",
            'seo_score' => $this->calculateTitleScore($finalTitle),
        ];
    }

    private function getAttributeValue(array $productData, string $attrId): ?string
    {
        foreach ($productData['attributes'] ?? [] as $attr) {
            if (($attr['id'] ?? '') === $attrId) {
                return $attr['value_name'] ?? $attr['value'] ?? null;
            }
        }
        return null;
    }
}
