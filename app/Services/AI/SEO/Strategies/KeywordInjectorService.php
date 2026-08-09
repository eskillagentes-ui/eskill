<?php

declare(strict_types=1);

namespace App\Services\AI\SEO\Strategies;



/**
 * 💉 E3: Keyword Injector Service
 *
 * Injeta keywords naturalmente em títulos e descrições
 * sem parecer spam, mantendo legibilidade humana.
 *
 * Técnicas:
 * - Injeção natural no fluxo do texto
 * - Controle de densidade (0.5% - 3%)
 * - Distribuição balanceada por seção
 * - Variação de sinônimos para evitar repetição
 *
 * @package App\Services\AI\SEO\Strategies
 */
class KeywordInjectorService
{
    private ?int $accountId;
    private SemanticScoreService $scoreService;
    private SynonymExpansionService $synonymService;

    /**
     * Limites de densidade de keywords
     */
    private const DENSITY_MIN = 0.5;  // Mínimo 0.5%
    private const DENSITY_MAX = 3.0;  // Máximo 3%
    private const DENSITY_OPTIMAL = 1.5; // Ideal 1.5%

    /**
     * Padrões de injeção por tipo de campo
     */
    private const INJECTION_PATTERNS = [
        'title' => [
            'max_keywords' => 3,
            'position' => 'start',  // Keywords no início
            'separator' => ' ',
            'max_length' => 60
        ],
        'description' => [
            'max_keywords' => 15,
            'position' => 'distributed',  // Distribuído no texto
            'density_target' => 1.5,
            'min_length' => 500
        ],
        'model' => [
            'max_keywords' => 5,
            'position' => 'end',  // Keywords no final
            'separator' => ' ',
            'max_length' => 255
        ]
    ];

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->scoreService = new SemanticScoreService($accountId);
        $this->synonymService = new SynonymExpansionService($accountId);
    }

    /**
     * Injeta keywords em um título de forma natural
     *
     * @param string $title Título original
     * @param array $keywords Keywords a injetar
     * @param string|null $categoryId ID da categoria
     * @return array Título otimizado com análise
     */
    public function injectInTitle(
        string $title,
        array $keywords,
        ?string $categoryId = null
    ): array {
        $config = self::INJECTION_PATTERNS['title'];
        $maxLength = $config['max_length'];

        // Analisar título atual
        $currentWords = $this->tokenize($title);
        $currentKeywords = $this->identifyKeywords($title, $categoryId);

        // Filtrar keywords já presentes
        $newKeywords = array_filter($keywords, function (string $kw) use ($currentWords): bool {
            return !$this->wordExistsIn($kw, $currentWords);
        });

        // Rankear por score semântico
        if ($categoryId && !empty($newKeywords)) {
            $ranked = $this->scoreService->rankByScore($newKeywords, $categoryId, $config['max_keywords']);
            $newKeywords = array_column($ranked['ranked'] ?? [], 'keyword');
        } else {
            $newKeywords = array_slice($newKeywords, 0, $config['max_keywords']);
        }

        // Construir título otimizado
        $optimizedTitle = $this->buildOptimizedTitle($title, $newKeywords, $maxLength);

        // Analisar resultado
        $analysis = $this->analyzeInjection($title, $optimizedTitle, $keywords);

        return [
            'original' => $title,
            'optimized' => $optimizedTitle,
            'injected_keywords' => $newKeywords,
            'keywords_already_present' => $currentKeywords,
            'length' => [
                'original' => mb_strlen($title),
                'optimized' => mb_strlen($optimizedTitle),
                'max' => $maxLength
            ],
            'analysis' => $analysis
        ];
    }

    /**
     * Injeta keywords em uma descrição mantendo naturalidade
     *
     * @param string $description Descrição original
     * @param array $keywords Keywords a injetar
     * @param string|null $categoryId ID da categoria
     * @param array $options Opções de injeção
     * @return array Descrição otimizada
     */
    public function injectInDescription(
        string $description,
        array $keywords,
        ?string $categoryId = null,
        array $options = []
    ): array {
        $targetDensity = $options['target_density'] ?? self::DENSITY_OPTIMAL;
        $minLength = $options['min_length'] ?? self::INJECTION_PATTERNS['description']['min_length'];

        // Analisar descrição atual
        $currentDensity = $this->calculateDensity($description, $keywords);
        $wordCount = str_word_count($description);

        // Se descrição muito curta, expandir primeiro
        if (mb_strlen($description) < $minLength) {
            $description = $this->expandDescription($description, $keywords, $minLength);
        }

        // Calcular quantas keywords precisam ser adicionadas
        $targetKeywordCount = $this->calculateTargetKeywords($description, $targetDensity);
        $currentKeywordCount = $this->countKeywordsIn($description, $keywords);
        $keywordsToAdd = max(0, $targetKeywordCount - $currentKeywordCount);

        // Preparar keywords para injeção (com sinônimos para variar)
        $keywordsToInject = $this->prepareKeywordsForInjection(
            $keywords,
            $keywordsToAdd,
            $categoryId
        );

        // Injetar de forma distribuída
        $optimizedDescription = $this->distributeKeywords(
            $description,
            $keywordsToInject,
            $targetDensity
        );

        // Calcular nova densidade
        $newDensity = $this->calculateDensity($optimizedDescription, $keywords);

        return [
            'original' => $description,
            'optimized' => $optimizedDescription,
            'density' => [
                'original' => round($currentDensity, 2),
                'optimized' => round($newDensity, 2),
                'target' => $targetDensity,
                'min' => self::DENSITY_MIN,
                'max' => self::DENSITY_MAX
            ],
            'keywords_injected' => count($keywordsToInject),
            'word_count' => [
                'original' => $wordCount,
                'optimized' => str_word_count($optimizedDescription)
            ],
            'length' => [
                'original' => mb_strlen($description),
                'optimized' => mb_strlen($optimizedDescription)
            ]
        ];
    }

    /**
     * @deprecated Injeção de keywords no campo MODEL foi desativada.
     *
     * MODEL é um identificador semântico do produto (ex.: "CG 160 Titan"),
     * não um espaço de palavras-chave. Anexar sinônimos/keywords ao final do
     * valor (comportamento antigo deste método) contraria a política adotada
     * pela Ficha Técnica (TechSheetModelSuggestionPolicy: normalização real,
     * nunca "depósito de palavras-chave") e pode inclusive gerar valor
     * inconsistente com o schema oficial da categoria no Mercado Livre.
     *
     * Mantido apenas por compatibilidade de assinatura — não injeta mais
     * keywords, retorna o valor original inalterado. Para sugestões de MODEL,
     * use App\Services\TechSheetModelSuggestionPolicy (Ficha Técnica).
     */
    public function injectInModel(
        string $model,
        array $keywords,
        ?string $categoryId = null
    ): array {
        $original = trim($model);

        return [
            'original' => $original,
            'optimized' => $original,
            'injected_keywords' => [],
            'skipped_keywords' => array_values($keywords),
            'skip_reason' => 'model_keyword_injection_disabled',
            'message' => 'Injeção de keywords em MODEL foi desativada — use a Ficha Técnica '
                . '(TechSheetModelSuggestionPolicy) para normalização real do modelo.',
            'length' => [
                'original' => mb_strlen($original),
                'optimized' => mb_strlen($original),
                'max' => self::INJECTION_PATTERNS['model']['max_length'],
            ],
        ];
    }

    /**
     * Analisa a densidade de keywords em um texto
     */
    public function analyzeDensity(string $text, array $keywords): array
    {
        $density = $this->calculateDensity($text, $keywords);
        $wordCount = str_word_count($text);
        $keywordCount = $this->countKeywordsIn($text, $keywords);

        $status = 'optimal';
        $recommendation = null;

        if ($density < self::DENSITY_MIN) {
            $status = 'low';
            $recommendation = 'Adicione mais keywords ao texto para melhorar a indexação';
        } elseif ($density > self::DENSITY_MAX) {
            $status = 'high';
            $recommendation = 'Reduza a repetição de keywords para evitar penalização';
        }

        return [
            'density' => round($density, 2),
            'status' => $status,
            'word_count' => $wordCount,
            'keyword_occurrences' => $keywordCount,
            'limits' => [
                'min' => self::DENSITY_MIN,
                'optimal' => self::DENSITY_OPTIMAL,
                'max' => self::DENSITY_MAX
            ],
            'recommendation' => $recommendation,
            'keyword_breakdown' => $this->getKeywordBreakdown($text, $keywords)
        ];
    }

    /**
     * Sugere posições ideais para injeção de keywords
     */
    public function suggestInjectionPoints(string $text, array $keywords): array
    {
        $sentences = $this->splitIntoSentences($text);
        $suggestions = [];
        $usedKeywords = [];

        foreach ($sentences as $index => $sentence) {
            $sentenceKeywords = $this->countKeywordsIn($sentence, $keywords);

            if ($sentenceKeywords === 0) {
                // Esta sentença é candidata para injeção
                $availableKeywords = array_diff($keywords, $usedKeywords);

                if (!empty($availableKeywords)) {
                    $keywordToInject = reset($availableKeywords);
                    $suggestions[] = [
                        'sentence_index' => $index,
                        'original_sentence' => $sentence,
                        'suggested_keyword' => $keywordToInject,
                        'injection_type' => $this->determineInjectionType($sentence),
                        'suggested_sentence' => $this->injectInSentence($sentence, $keywordToInject)
                    ];
                    $usedKeywords[] = $keywordToInject;
                }
            }
        }

        return [
            'total_sentences' => count($sentences),
            'injection_points' => count($suggestions),
            'suggestions' => $suggestions
        ];
    }

    /**
     * Otimiza um texto completo para SEO
     */
    public function optimizeText(
        string $text,
        array $keywords,
        string $fieldType = 'description',
        ?string $categoryId = null
    ): array {
        switch ($fieldType) {
            case 'title':
                return $this->injectInTitle($text, $keywords, $categoryId);
            case 'model':
                // Desativado: ver injectInModel(). MODEL não deve receber keyword
                // injection — retorna sem alterar e sinaliza o motivo ao chamador.
                return $this->injectInModel($text, $keywords, $categoryId);
            case 'description':
            default:
                return $this->injectInDescription($text, $keywords, $categoryId);
        }
    }

    // ========================================================================
    // MÉTODOS AUXILIARES PRIVADOS
    // ========================================================================

    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        return array_filter(preg_split('/\s+/', $text));
    }

    private function wordExistsIn(string $word, array $words): bool
    {
        $word = mb_strtolower(trim($word));
        foreach ($words as $w) {
            if (mb_strtolower($w) === $word) {
                return true;
            }
            // Verificar se é parte de palavra composta
            if (stripos($w, $word) !== false || stripos($word, $w) !== false) {
                return true;
            }
        }
        return false;
    }

    private function identifyKeywords(string $text, ?string $categoryId): array
    {
        $words = $this->tokenize($text);
        $keywords = [];

        // Filtrar stopwords
        $stopWords = [
            'para',
            'com',
            'sem',
            'de',
            'da',
            'do',
            'em',
            'no',
            'na',
            'e',
            'ou',
            'um',
            'uma',
            'os',
            'as',
            'que',
            'por'
        ];

        foreach ($words as $word) {
            if (mb_strlen($word) > 2 && !in_array($word, $stopWords)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    private function buildOptimizedTitle(string $title, array $keywords, int $maxLength): string
    {
        $title = trim($title);

        // Se não há keywords para adicionar, retornar original
        if (empty($keywords)) {
            return $title;
        }

        // Estratégia: Adicionar keywords importantes no início
        $keywordPrefix = implode(' ', $keywords);

        // Verificar se cabe
        if (mb_strlen($keywordPrefix . ' ' . $title) <= $maxLength) {
            return $keywordPrefix . ' ' . $title;
        }

        // Tentar adicionar uma keyword por vez
        $result = $title;
        foreach ($keywords as $kw) {
            $newResult = $kw . ' ' . $result;
            if (mb_strlen($newResult) <= $maxLength) {
                $result = $newResult;
            } else {
                break;
            }
        }

        return $result;
    }

    private function calculateDensity(string $text, array $keywords): float
    {
        $wordCount = str_word_count($text);
        if ($wordCount === 0) return 0;

        $keywordCount = $this->countKeywordsIn($text, $keywords);

        return ($keywordCount / $wordCount) * 100;
    }

    private function countKeywordsIn(string $text, array $keywords): int
    {
        $text = mb_strtolower($text);
        $count = 0;

        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim($keyword));
            if (empty($keyword)) continue;

            $count += substr_count($text, $keyword);
        }

        return $count;
    }

    private function calculateTargetKeywords(string $text, float $targetDensity): int
    {
        $wordCount = str_word_count($text);
        return (int) ceil(($targetDensity / 100) * $wordCount);
    }

    private function prepareKeywordsForInjection(
        array $keywords,
        int $count,
        ?string $categoryId
    ): array {
        $prepared = [];
        $remaining = $count;

        foreach ($keywords as $keyword) {
            if ($remaining <= 0) break;

            $prepared[] = $keyword;
            $remaining--;

            // Adicionar sinônimo para variar (se houver espaço)
            if ($remaining > 0 && $categoryId) {
                $synonyms = $this->synonymService->expand($keyword, $categoryId, [
                    'levels' => [2],
                    'limit_per_level' => 1
                ]);

                if (!empty($synonyms['synonyms'])) {
                    $syn = $synonyms['synonyms'][0]['word'] ?? null;
                    if ($syn && !in_array($syn, $prepared)) {
                        $prepared[] = $syn;
                        $remaining--;
                    }
                }
            }
        }

        return $prepared;
    }

    private function distributeKeywords(
        string $text,
        array $keywords,
        float $targetDensity
    ): string {
        if (empty($keywords)) {
            return $text;
        }

        $sentences = $this->splitIntoSentences($text);
        $keywordIndex = 0;
        $totalSentences = count($sentences);

        // Distribuir keywords uniformemente
        $interval = max(1, (int) floor($totalSentences / count($keywords)));

        foreach ($sentences as $index => &$sentence) {
            if ($keywordIndex >= count($keywords)) {
                break;
            }

            // Injetar a cada N sentenças
            if ($index % $interval === 0) {
                $keyword = $keywords[$keywordIndex];

                // Verificar se keyword já existe na sentença
                if (stripos($sentence, $keyword) === false) {
                    $sentence = $this->injectInSentence($sentence, $keyword);
                    $keywordIndex++;
                }
            }
        }

        return implode(' ', $sentences);
    }

    private function splitIntoSentences(string $text): array
    {
        // Dividir por pontuação final
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_map('trim', $sentences);
    }

    private function injectInSentence(string $sentence, string $keyword): string
    {
        // Estratégias de injeção natural
        $strategies = [
            // No início com "Este/Esta"
            fn($s, $k) => preg_replace('/^(Este|Esta|O|A)\s+/i', "$1 $k ", $s),
            // Após vírgula
            fn($s, $k) => preg_replace('/,\s+/', ", $k, ", $s, 1),
            // Antes do ponto final
            fn($s, $k) => preg_replace('/\.$/', " $k.", $s),
            // No meio após "com" ou "para"
            fn($s, $k) => preg_replace('/(com|para)\s+/i', "$1 $k ", $s, 1),
        ];

        foreach ($strategies as $strategy) {
            $result = $strategy($sentence, $keyword);
            if ($result !== $sentence) {
                return $result;
            }
        }

        // Fallback: adicionar no final
        return rtrim($sentence, '.') . " {$keyword}.";
    }

    private function determineInjectionType(string $sentence): string
    {
        if (preg_match('/^(Este|Esta|O|A)\s+/i', $sentence)) {
            return 'after_article';
        }
        if (strpos($sentence, ',') !== false) {
            return 'after_comma';
        }
        if (preg_match('/(com|para)\s+/i', $sentence)) {
            return 'after_preposition';
        }
        return 'end_of_sentence';
    }

    private function expandDescription(string $description, array $keywords, int $minLength): string
    {
        // Templates para expandir descrição curta
        $templates = [
            "✅ Características principais:\n",
            "🔹 {keyword} de alta qualidade\n",
            "🔹 Ideal para uso {context}\n",
            "🔹 Fácil instalação e manutenção\n",
            "\n📦 O que está incluso:\n",
            "- Produto conforme descrição\n",
            "- Manual de instruções\n",
            "\n⭐ Garantia de satisfação"
        ];

        $expanded = $description . "\n\n";
        $keywordIndex = 0;

        foreach ($templates as $template) {
            if (mb_strlen($expanded) >= $minLength) {
                break;
            }

            $line = $template;

            // Substituir placeholders
            if (strpos($line, '{keyword}') !== false && isset($keywords[$keywordIndex])) {
                $line = str_replace('{keyword}', $keywords[$keywordIndex], $line);
                $keywordIndex++;
            }
            if (strpos($line, '{context}') !== false) {
                $contexts = ['profissional', 'diário', 'residencial'];
                $ctxIndex = abs(crc32($line . (string)$keywordIndex)) % count($contexts);
                $line = str_replace('{context}', $contexts[$ctxIndex], $line);
            }

            $expanded .= $line;
        }

        return $expanded;
    }

    private function getKeywordBreakdown(string $text, array $keywords): array
    {
        $text = mb_strtolower($text);
        $breakdown = [];

        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim($keyword));
            if (empty($keyword)) continue;

            $count = substr_count($text, $keyword);
            $breakdown[$keyword] = [
                'count' => $count,
                'density' => str_word_count($text) > 0
                    ? round(($count / str_word_count($text)) * 100, 2)
                    : 0
            ];
        }

        return $breakdown;
    }

    private function analyzeInjection(string $original, string $optimized, array $keywords): array
    {
        $originalDensity = $this->calculateDensity($original, $keywords);
        $optimizedDensity = $this->calculateDensity($optimized, $keywords);

        return [
            'density_change' => round($optimizedDensity - $originalDensity, 2),
            'length_change' => mb_strlen($optimized) - mb_strlen($original),
            'word_count_change' => str_word_count($optimized) - str_word_count($original),
            'improvement_score' => $this->calculateImprovementScore($originalDensity, $optimizedDensity)
        ];
    }

    private function calculateImprovementScore(float $original, float $optimized): int
    {
        // Score de 0-100 baseado em quão perto do ideal estamos
        $optimalDistance = abs(self::DENSITY_OPTIMAL - $optimized);
        $originalDistance = abs(self::DENSITY_OPTIMAL - $original);

        if ($originalDistance === 0) return 50; // Já estava no ideal

        $improvement = (($originalDistance - $optimalDistance) / $originalDistance) * 50;
        return (int) min(100, max(0, 50 + $improvement));
    }
}
