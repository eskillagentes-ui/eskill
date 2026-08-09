<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use App\Services\AI\Core\AIProviderManager;
use PDO;

/**
 * 🔎 KEYWORD KILLER - Pesquisa de Keywords Matadora
 *
 * Descobre as melhores keywords para seus produtos:
 * - Keywords de alta conversão
 * - Long-tail keywords
 * - Keywords dos concorrentes
 * - Trends e sazonalidade
 * - Volume de busca estimado
 *
 * @author AI Development Team
 * @version 1.0.0
 */
class KeywordKiller
{
    private PDO $db;
    private ?int $accountId;
    private ?MercadoLivreClient $mlClient = null;
    private ?AIProviderManager $aiProvider = null;

    // Keyword categories
    private const KEYWORD_TYPES = [
        'primary' => 'Keyword principal do produto',
        'secondary' => 'Keywords secundárias de suporte',
        'long_tail' => 'Keywords de cauda longa (específicas)',
        'modifier' => 'Modificadores (cor, tamanho, marca)',
        'intent' => 'Keywords de intenção de compra',
        'competitor' => 'Keywords usadas pelos concorrentes',
    ];

    // Intent modifiers that indicate buying intent
    private const BUYING_INTENT_WORDS = [
        'comprar',
        'preço',
        'barato',
        'promoção',
        'onde encontrar',
        'melhor',
        'original',
        'frete grátis',
        'entrega rápida',
        'novo',
        'lacrado',
        'garantia',
        'qualidade',
    ];

    public function __construct(?int $accountId = null)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;

        if ($accountId) {
            $this->mlClient = new MercadoLivreClient($accountId);
        }

        $this->aiProvider = new AIProviderManager();
    }

    /**
     * 🚀 Otimizar keywords de um item específico
     */
    public function optimize(string $itemId): array
    {
        try {
            if (!$this->mlClient) {
                return ['success' => false, 'error' => 'Conta não vinculada'];
            }

            $item = $this->mlClient->get("/items/{$itemId}");
            if (!$item || isset($item['error'])) {
                return [
                    'success' => false,
                    'error' => $item['error'] ?? 'Item não encontrado'
                ];
            }

            return $this->researchKeywords($item);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 📊 Analisar uso de keywords no título
     */
    public function analyzeKeywordUsage(array $item): array
    {
        $result = [
            'score' => 50,
            'issues' => [],
            'found_keywords' => [],
        ];

        try {
            $title = mb_strtolower($item['title'] ?? '');

            // 1. Extract potential keywords from title
            $titleWords = $this->extractBaseKeywords($item);
            $result['found_keywords'] = $titleWords;

            $score = 100;
            $issues = [];

            // 2. Check keyword volume (simulated logic based on extractBaseKeywords quality)
            $highValueKeywords = 0;
            foreach ($titleWords as $kw) {
                $len = mb_strlen($kw);
                if ($len >= 4) $highValueKeywords++;
            }

            if ($highValueKeywords < 3) {
                $score -= 20;
                $issues[] = 'Poucas palavras-chave relevantes identificadas';
            }

            // 3. Check Buying Intent keywords
            $hasIntent = false;
            foreach (self::BUYING_INTENT_WORDS as $intent) {
                if (mb_stripos($title, $intent) !== false) {
                    $hasIntent = true;
                    break;
                }
            }

            // Notes: Intent keywords in title are controversial, sometimes disallowed by ML.
            // So we won't penalize heavily, but maybe reward if present safely (like "original")

            // 4. Check important attributes as keywords
            $missingImportant = [];
            $brand = mb_strtolower($item['brand'] ?? $this->extractAttribute($item, 'BRAND'));
            $model = mb_strtolower($item['model'] ?? $this->extractAttribute($item, 'MODEL'));

            if ($brand && mb_stripos($title, $brand) === false) {
                $score -= 15;
                $issues[] = "Marca '{$brand}' ausente do título";
                $missingImportant[] = $brand;
            }

            if ($model && mb_stripos($title, $model) === false) {
                $score -= 10;
                $issues[] = "Modelo '{$model}' ausente do título";
                $missingImportant[] = $model;
            }

            // 5. Keyword stuffing check
            $wordCounts = array_count_values(preg_split('/[\s\-\/]+/', $title));
            foreach ($wordCounts as $word => $count) {
                // Ignore small words
                if (mb_strlen($word) < 4) continue;

                if ($count >= 3) {
                    $score -= 15;
                    $issues[] = "Palavra '{$word}' repetida excessivamente ({$count}x)";
                }
            }

            $result['score'] = max(0, $score);
            $result['issues'] = $issues;
        } catch (\Exception $e) {
            // Keep defaults
        }

        return $result;
    }

    private function extractAttribute(array $item, string $attrId): string
    {
        foreach ($item['attributes'] ?? [] as $attr) {
            if (($attr['id'] ?? '') === $attrId) {
                return $attr['value_name'] ?? '';
            }
        }
        return '';
    }

    /**
     * 🔍 Pesquisa completa de keywords para um produto
     */
    public function researchKeywords(array $productData): array
    {
        $result = [
            'product' => $productData['title'] ?? '',
            'keywords' => [
                'primary' => [],
                'secondary' => [],
                'long_tail' => [],
                'modifiers' => [],
                'buying_intent' => [],
                'competitor' => [],
            ],
            'suggestions' => [],
            'keyword_supply_analysis' => [],
            'missing_opportunities' => [],
        ];

        // 1. Extract base keywords
        $baseKeywords = $this->extractBaseKeywords($productData);
        $result['keywords']['primary'] = array_slice($baseKeywords, 0, 5);
        $result['keywords']['secondary'] = array_slice($baseKeywords, 5, 10);

        // 2. Generate long-tail variations
        $result['keywords']['long_tail'] = $this->generateLongTail($productData, $baseKeywords);

        // 3. Extract modifiers
        $result['keywords']['modifiers'] = $this->extractModifiers($productData);

        // 4. Analyze buying intent keywords
        $result['keywords']['buying_intent'] = $this->generateBuyingIntentKeywords($productData);

        // 5. Get competitor keywords (if ML client available)
        if ($this->mlClient) {
            $result['keywords']['competitor'] = $this->analyzeCompetitorKeywords($productData);
        }

        // 6. Analisar oferta/competição real por keyword (NÃO é volume de busca —
        // ver analyzeKeywordSupply() para a correção de metodologia).
        $result['keyword_supply_analysis'] = $this->analyzeKeywordSupply($baseKeywords);

        // 7. Identify missing opportunities
        $result['missing_opportunities'] = $this->findMissingOpportunities($productData, $result['keywords']);

        // 8. Generate AI suggestions
        $result['suggestions'] = $this->getAISuggestions($productData, $result['keywords']);

        // Total keywords found
        $result['total_keywords'] = array_sum(array_map('count', $result['keywords']));

        return $result;
    }

    /**
     * 📊 Extrair keywords base do produto
     */
    private function extractBaseKeywords(array $productData): array
    {
        $keywords = [];

        // From title
        $title = $productData['title'] ?? '';
        $words = preg_split('/[\s\-\/\+]+/', $title);

        foreach ($words as $word) {
            $word = trim(mb_strtolower($word));
            // Filter: min 3 chars, not a stopword
            if (mb_strlen($word) >= 3 && !$this->isStopword($word)) {
                $keywords[] = $word;
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

        // From attributes
        $keyAttributes = ['BRAND', 'MODEL', 'COLOR', 'SIZE', 'MATERIAL', 'LINE'];
        foreach ($productData['attributes'] ?? [] as $attr) {
            if (in_array($attr['id'] ?? '', $keyAttributes)) {
                $value = mb_strtolower($attr['value_name'] ?? '');
                if ($value && mb_strlen($value) >= 2) {
                    $keywords[] = $value;
                }
            }
        }

        return array_unique($keywords);
    }

    /**
     * 🔗 Gerar keywords de cauda longa
     */
    private function generateLongTail(array $productData, array $baseKeywords): array
    {
        $longTail = [];
        $title = $productData['title'] ?? '';
        $brand = $productData['brand'] ?? '';

        // Pattern 1: Brand + Product
        if ($brand) {
            $longTail[] = mb_strtolower("{$brand} {$title}");
            $longTail[] = mb_strtolower("{$title} {$brand}");
            $longTail[] = mb_strtolower("{$brand} {$title} original");
        }

        // Pattern 2: Product + Attribute combinations
        $specs = [];
        foreach ($productData['attributes'] ?? [] as $attr) {
            $value = $attr['value_name'] ?? '';
            if ($value && mb_strlen($value) <= 20) {
                $specs[] = mb_strtolower($value);
            }
        }

        foreach (array_slice($specs, 0, 5) as $spec) {
            $longTail[] = mb_strtolower("{$title} {$spec}");
        }

        // Pattern 3: Product + Use Case
        $useCases = ['para presente', 'para casa', 'para trabalho', 'profissional', 'importado'];
        foreach ($useCases as $use) {
            if (mb_strlen($title) + mb_strlen($use) <= 50) {
                $longTail[] = mb_strtolower("{$title} {$use}");
            }
        }

        // Pattern 4: 2-3 word combinations from base keywords
        for ($i = 0; $i < count($baseKeywords) - 1; $i++) {
            for ($j = $i + 1; $j < min($i + 3, count($baseKeywords)); $j++) {
                $combo = $baseKeywords[$i] . ' ' . $baseKeywords[$j];
                if (mb_strlen($combo) >= 6 && mb_strlen($combo) <= 40) {
                    $longTail[] = $combo;
                }
            }
        }

        // Clean and unique
        $longTail = array_map(fn(string $k): string => trim(preg_replace('/\s+/', ' ', $k)), $longTail);
        $longTail = array_unique($longTail);

        // Limit and sort by length
        usort($longTail, fn($a, $b) => mb_strlen($a) <=> mb_strlen($b));

        return array_slice($longTail, 0, 20);
    }

    /**
     * 🎨 Extrair modificadores (cor, tamanho, etc)
     */
    private function extractModifiers(array $productData): array
    {
        $modifiers = [];

        $modifierAttrs = [
            'COLOR' => 'cor',
            'SIZE' => 'tamanho',
            'MATERIAL' => 'material',
            'VOLTAGE' => 'voltagem',
            'CAPACITY' => 'capacidade',
            'WEIGHT' => 'peso',
        ];

        foreach ($productData['attributes'] ?? [] as $attr) {
            $attrId = $attr['id'] ?? '';
            if (isset($modifierAttrs[$attrId])) {
                $value = $attr['value_name'] ?? '';
                if ($value) {
                    $modifiers[] = [
                        'type' => $modifierAttrs[$attrId],
                        'value' => mb_strtolower($value),
                        'keyword' => mb_strtolower("{$modifierAttrs[$attrId]} {$value}"),
                    ];
                }
            }
        }

        return $modifiers;
    }

    /**
     * 💰 Gerar keywords de intenção de compra
     */
    private function generateBuyingIntentKeywords(array $productData): array
    {
        $keywords = [];
        $title = mb_strtolower($productData['title'] ?? '');

        foreach (self::BUYING_INTENT_WORDS as $intent) {
            // Combine with product name
            $keyword = trim("{$title} {$intent}");
            if (mb_strlen($keyword) <= 60) {
                $keywords[] = [
                    'keyword' => $keyword,
                    'intent' => $intent,
                    'type' => $this->classifyIntent($intent),
                ];
            }
        }

        // Add "comprar [produto]" pattern
        $shortTitle = mb_substr($title, 0, 40);
        $keywords[] = [
            'keyword' => "comprar {$shortTitle}",
            'intent' => 'comprar',
            'type' => 'transactional',
        ];

        $keywords[] = [
            'keyword' => "onde comprar {$shortTitle}",
            'intent' => 'onde comprar',
            'type' => 'transactional',
        ];

        return array_slice($keywords, 0, 15);
    }

    /**
     * 🕵️ Analisar keywords dos concorrentes
     */
    private function analyzeCompetitorKeywords(array $productData): array
    {
        $competitorKeywords = [];

        try {
            // Search for similar products
            $searchTerm = mb_substr($productData['title'] ?? '', 0, 30);
            $results = $this->mlClient->get('/sites/MLB/search', [
                'q' => $searchTerm,
                'limit' => 10,
                'sort' => 'sold_quantity_desc'
            ]);

            $allKeywords = [];

            foreach ($results['results'] ?? [] as $item) {
                $itemTitle = $item['title'] ?? '';
                $words = preg_split('/[\s\-\/]+/', mb_strtolower($itemTitle));

                foreach ($words as $word) {
                    $word = trim($word);
                    if (mb_strlen($word) >= 3 && !$this->isStopword($word)) {
                        $allKeywords[$word] = ($allKeywords[$word] ?? 0) + 1;
                    }
                }
            }

            // Sort by frequency
            arsort($allKeywords);

            // Get keywords that appear in multiple competitor titles
            foreach (array_slice($allKeywords, 0, 20, true) as $kw => $freq) {
                if ($freq >= 2) {
                    $competitorKeywords[] = [
                        'keyword' => $kw,
                        'frequency' => $freq,
                        'in_competitors' => $freq,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        return $competitorKeywords;
    }

    /**
     * 📊 Analisa oferta/competição por keyword usando dados reais da ML API.
     *
     * Correção de metodologia: a versão anterior ("estimateVolumes") multiplicava
     * o total de anúncios concorrentes (oferta) por um fator arbitrário de 7% e
     * chamava o resultado de "estimated_volume" — isso não é volume de busca,
     * é uma fabricação sem base estatística. total_results mede OFERTA (quantos
     * vendedores competem por aquele termo), não DEMANDA (quantas pessoas
     * pesquisam). O Mercado Livre não expõe volume de busca via API pública.
     * Este método agora reporta apenas o dado real (total de anúncios) e deriva
     * um "opportunity_score" a partir dele, sem fingir medir demanda.
     */
    private function analyzeKeywordSupply(array $keywords): array
    {
        $supply = [];

        foreach (array_slice($keywords, 0, 10) as $keyword) {
            $totalListings = null;

            if ($this->mlClient) {
                try {
                    $searchData = $this->mlClient->searchItems([
                        'q' => $keyword,
                        'limit' => 1, // Só precisamos do paging.total
                    ]);
                    $totalListings = intval($searchData['paging']['total'] ?? 0);
                } catch (\Exception $e) {
                    $totalListings = null; // Dado real indisponível — não fabricar substituto
                }
            }

            $competition = $totalListings !== null
                ? $this->classifyCompetitionFromListings($totalListings)
                : 'desconhecida';

            $supply[] = [
                'keyword' => $keyword,
                // Dado real da API ML: quantos anúncios competem por este termo (oferta, não demanda).
                'total_listings' => $totalListings,
                'competition' => $competition,
                'data_source' => $totalListings !== null ? 'ml_search_api' : 'unavailable',
                'opportunity_score' => $this->calculateOpportunityScore($totalListings),
            ];
        }

        // Sort by opportunity score (favorece termos com menos concorrência real)
        usort($supply, fn($a, $b) => $b['opportunity_score'] <=> $a['opportunity_score']);

        return $supply;
    }

    /**
     * Classifica competição a partir do total real de anúncios concorrentes
     * (dado real da API ML), em vez do comprimento da palavra-chave.
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
     * 🎯 Encontrar oportunidades perdidas
     */
    private function findMissingOpportunities(array $productData, array $currentKeywords): array
    {
        $opportunities = [];

        // Check if brand is missing
        if (empty($productData['brand'])) {
            $opportunities[] = [
                'type' => 'missing_brand',
                'message' => 'Produto sem marca definida - perde buscas por marca',
                'impact' => 'high',
                'suggestion' => 'Adicione a marca nos atributos',
            ];
        }

        // Check if model is missing
        if (empty($productData['model'])) {
            $opportunities[] = [
                'type' => 'missing_model',
                'message' => 'Produto sem modelo - perde buscas específicas',
                'impact' => 'medium',
                'suggestion' => 'Adicione o modelo nos atributos',
            ];
        }

        // Check title length
        $titleLen = mb_strlen($productData['title'] ?? '');
        if ($titleLen < 40) {
            $opportunities[] = [
                'type' => 'short_title',
                'message' => "Título curto ({$titleLen} chars) - espaço para mais keywords",
                'impact' => 'high',
                'suggestion' => 'Expanda o título para 50-60 caracteres com keywords',
            ];
        }

        // Check for number specs
        if (!preg_match('/\d+/', $productData['title'] ?? '')) {
            $opportunities[] = [
                'type' => 'no_specs',
                'message' => 'Título sem especificações numéricas',
                'impact' => 'medium',
                'suggestion' => 'Adicione tamanho, capacidade ou quantidade',
            ];
        }

        return $opportunities;
    }

    /**
     * 🤖 Obter sugestões da IA
     */
    private function getAISuggestions(array $productData, array $currentKeywords): array
    {
        try {
            $provider = $this->aiProvider->getPrimaryProvider();
            if (!$provider) return [];

            $prompt = "Analise este produto do Mercado Livre e sugira 10 keywords de alta conversão:

Produto: {$productData['title']}
Marca: " . ($productData['brand'] ?? 'N/A') . "

Keywords já encontradas:
" . implode(', ', array_merge(
                $currentKeywords['primary'] ?? [],
                array_slice($currentKeywords['long_tail'] ?? [], 0, 5)
            )) . "

Sugira 10 keywords NOVAS que não estão na lista acima.
Foque em:
1. Keywords de cauda longa específicas
2. Keywords de intenção de compra
3. Keywords que os clientes usam para buscar este produto

Responda em JSON:
{\"keywords\": [\"keyword1\", \"keyword2\", ...]}";

            $response = $provider->chat([
                ['role' => 'system', 'content' => 'Você é um especialista em SEO para Mercado Livre. Responda apenas em JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ], ['temperature' => 0.7, 'max_tokens' => 500]);

            $content = $response['content'] ?? '';
            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $data = json_decode($matches[0], true);
                return $data['keywords'] ?? [];
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        return [];
    }

    // Helper methods

    private function isStopword(string $word): bool
    {
        $stopwords = [
            'de',
            'da',
            'do',
            'das',
            'dos',
            'para',
            'com',
            'em',
            'por',
            'que',
            'uma',
            'um',
            'seu',
            'sua',
            'este',
            'esta',
            'isso',
            'the',
            'and',
            'for',
            'with',
            'new',
            'set',
        ];
        return in_array($word, $stopwords);
    }

    private function classifyIntent(string $intent): string
    {
        $transactional = ['comprar', 'preço', 'onde encontrar', 'promoção'];
        $commercial = ['melhor', 'original', 'qualidade', 'garantia'];

        if (in_array($intent, $transactional)) return 'transactional';
        if (in_array($intent, $commercial)) return 'commercial';
        return 'informational';
    }

    /**
     * Opportunity score (0-100) baseado somente em oferta real (total_listings),
     * sem componente de "volume" fabricado. Menos concorrentes reais = mais
     * oportunidade. Quando o dado real não está disponível, retorna um score
     * neutro (50) em vez de inventar um número.
     */
    private function calculateOpportunityScore(?int $totalListings): int
    {
        if ($totalListings === null) {
            return 50;
        }

        $comp = $this->classifyCompetitionFromListings($totalListings);
        return match ($comp) {
            'low' => 85,
            'medium' => 60,
            default => 35,
        };
    }
}
