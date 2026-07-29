<?php

declare(strict_types=1);

namespace App\Services\AI\SEO\Strategies;

use App\Services\LLMService;

/**
 * ❓ E11: FAQ Optimizer Service
 *
 * Gera e otimiza FAQs para SEO:
 * - Geração automática de perguntas frequentes
 * - Otimização com keywords estratégicas
 * - Schema.org FAQ markup
 * - Integração com descrição do produto
 *
 * FAQs bem estruturadas aumentam:
 * - Tempo na página
 * - Conversão
 * - Featured snippets
 *
 * @package App\Services\AI\SEO\Strategies
 */
class FAQOptimizerService
{
    private ?int $accountId;
    private KeywordSourceService $keywordService;
    private UseContextService $contextService;

    /**
     * Templates de perguntas por categoria
     */
    private const FAQ_TEMPLATES = [
        'default' => [
            'specs' => [
                'Quais são as dimensões de {product}?',
                'Qual o peso de {product}?',
                'Qual a capacidade de {product}?',
                'Qual o material de {product}?'
            ],
            'usage' => [
                '{product} é resistente à chuva?',
                'Posso usar {product} diariamente?',
                '{product} é fácil de instalar?',
                'Qual a durabilidade de {product}?'
            ],
            'compatibility' => [
                '{product} é compatível com minha moto?',
                'Serve em qualquer modelo?',
                'Precisa de adaptador para instalar?',
                'Funciona com {brand}?'
            ],
            'shipping' => [
                'Em quantos dias {product} chega?',
                'Tem frete grátis?',
                'O envio é seguro?',
                'Posso rastrear o pedido?'
            ],
            'warranty' => [
                '{product} tem garantia?',
                'Como funciona a troca?',
                'E se vier com defeito?',
                'A garantia cobre o quê?'
            ]
        ],
        'MLB3530' => [ // Baús e Bagageiros
            'specs' => [
                'Quantos litros tem o baú?',
                'Cabe capacete no baú?',
                'Qual o tamanho interno do baú?',
                'O baú é resistente ao sol?'
            ],
            'usage' => [
                'O baú é bom para motoboy?',
                'Posso usar para delivery?',
                'Aguenta uso intenso?',
                'É bom para viagens longas?'
            ],
            'compatibility' => [
                'O baú serve na minha CG 160?',
                'Precisa de base específica?',
                'Compatível com Honda e Yamaha?',
                'Serve em scooter?'
            ]
        ]
    ];

    /**
     * Respostas modelo por tipo
     */
    private const ANSWER_TEMPLATES = [
        'specs' => 'O {product} possui {spec_value}. {additional_info}',
        'usage' => 'Sim! O {product} é ideal para {use_case}. {benefit}',
        'compatibility' => 'O {product} é compatível com {models}. {installation_info}',
        'shipping' => '{product} é enviado em {shipping_time}. {shipping_method}',
        'warranty' => 'O {product} possui garantia de {warranty_period}. {warranty_details}'
    ];

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->keywordService = new KeywordSourceService($accountId);
        $this->contextService = new UseContextService($accountId);
    }

    /**
     * Gera FAQs otimizadas para um produto
     */
    public function generateFAQs(array $productData, int $count = 5): array
    {
        $title = $productData['title'] ?? '';
        $description = $productData['description'] ?? '';
        $categoryId = $productData['category_id'] ?? null;
        $brand = $productData['brand'] ?? '';
        $specs = $productData['specs'] ?? [];

        // Selecionar templates
        $templates = $this->selectTemplates($categoryId, $count);

        // Gerar perguntas
        $faqs = [];
        foreach ($templates as $type => $questions) {
            foreach ($questions as $question) {
                $processedQ = $this->processQuestion($question, $productData);
                $answer = $this->generateAnswer($type, $productData);

                $faqs[] = [
                    'question' => $processedQ,
                    'answer' => $answer,
                    'type' => $type,
                    'keywords' => $this->extractKeywords($processedQ . ' ' . $answer)
                ];

                if (count($faqs) >= $count) break 2;
            }
        }

        return [
            'faqs' => $faqs,
            'total' => count($faqs),
            'schema' => $this->generateSchema($faqs),
            'html' => $this->generateHTML($faqs)
        ];
    }

    /**
     * Gera FAQs usando IA
     */
    public function generateWithAI(array $productData, int $count = 5): array
    {
        try {
            $llm = new LLMService();

            $prompt = $this->buildFAQPrompt($productData, $count);
            $response = $llm->generate($prompt);

            $faqs = $this->parseFAQResponse($response);

            return [
                'faqs' => $faqs,
                'source' => 'ai',
                'schema' => $this->generateSchema($faqs),
                'html' => $this->generateHTML($faqs)
            ];
        } catch (\Exception $e) {
            // Fallback para templates
            return $this->generateFAQs($productData, $count);
        }
    }

    /**
     * Otimiza FAQs existentes com keywords
     */
    public function optimizeFAQs(array $faqs, array $keywords): array
    {
        $optimized = [];

        foreach ($faqs as $faq) {
            $question = $faq['question'] ?? '';
            $answer = $faq['answer'] ?? '';

            // Injetar keywords naturalmente
            $optimizedQ = $this->injectKeywords($question, $keywords, 1);
            $optimizedA = $this->injectKeywords($answer, $keywords, 2);

            $optimized[] = [
                'original_question' => $question,
                'optimized_question' => $optimizedQ,
                'original_answer' => $answer,
                'optimized_answer' => $optimizedA,
                'keywords_added' => $this->countAddedKeywords($question . $answer, $optimizedQ . $optimizedA, $keywords)
            ];
        }

        return [
            'optimized_faqs' => $optimized,
            'total_keywords_added' => array_sum(array_column($optimized, 'keywords_added'))
        ];
    }

    /**
     * Gera Schema.org FAQPage
     */
    public function generateSchema(array $faqs): array
    {
        $mainEntity = [];

        foreach ($faqs as $faq) {
            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer']
                ]
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $mainEntity
        ];
    }

    /**
     * Gera HTML formatado para FAQs
     */
    public function generateHTML(array $faqs, string $style = 'accordion'): string
    {
        if ($style === 'accordion') {
            return $this->generateAccordionHTML($faqs);
        }

        return $this->generateSimpleHTML($faqs);
    }

    /**
     * Gera texto de FAQs para descrição
     */
    public function generateDescriptionText(array $faqs): string
    {
        $text = "\n\n❓ **PERGUNTAS FREQUENTES**\n\n";

        foreach ($faqs as $i => $faq) {
            $num = $i + 1;
            $text .= "**{$num}. {$faq['question']}**\n";
            $text .= "{$faq['answer']}\n\n";
        }

        return $text;
    }

    /**
     * Analisa FAQs de concorrentes
     */
    public function analyzeCompetitorFAQs(array $competitorData): array
    {
        $commonQuestions = [];
        $keywordFrequency = [];

        foreach ($competitorData as $competitor) {
            $faqs = $competitor['faqs'] ?? [];

            foreach ($faqs as $faq) {
                $question = mb_strtolower($faq['question'] ?? '');

                // Normalizar pergunta
                $normalized = $this->normalizeQuestion($question);

                if (!isset($commonQuestions[$normalized])) {
                    $commonQuestions[$normalized] = 0;
                }
                $commonQuestions[$normalized]++;

                // Extrair keywords
                $words = preg_split('/\s+/', $question);
                foreach ($words as $word) {
                    if (strlen($word) > 3) {
                        $word = mb_strtolower($word);
                        $keywordFrequency[$word] = ($keywordFrequency[$word] ?? 0) + 1;
                    }
                }
            }
        }

        arsort($commonQuestions);
        arsort($keywordFrequency);

        return [
            'common_questions' => array_slice($commonQuestions, 0, 10, true),
            'top_keywords' => array_slice($keywordFrequency, 0, 20, true),
            'suggestions' => $this->generateSuggestionsFromAnalysis($commonQuestions)
        ];
    }

    /**
     * Valida qualidade das FAQs
     */
    public function validateFAQs(array $faqs): array
    {
        $issues = [];
        $score = 100;

        foreach ($faqs as $i => $faq) {
            $question = $faq['question'] ?? '';
            $answer = $faq['answer'] ?? '';

            // Verificar pergunta
            if (strlen($question) < 10) {
                $issues[] = "FAQ #{$i}: Pergunta muito curta";
                $score -= 10;
            }
            if (!str_contains($question, '?')) {
                $issues[] = "FAQ #{$i}: Pergunta sem interrogação";
                $score -= 5;
            }

            // Verificar resposta
            if (strlen($answer) < 30) {
                $issues[] = "FAQ #{$i}: Resposta muito curta (mín: 30 caracteres)";
                $score -= 15;
            }
            if (strlen($answer) > 500) {
                $issues[] = "FAQ #{$i}: Resposta muito longa (máx: 500 caracteres)";
                $score -= 5;
            }
        }

        // Verificar quantidade
        if (count($faqs) < 3) {
            $issues[] = "Poucas FAQs (recomendado: mínimo 3)";
            $score -= 20;
        }

        return [
            'is_valid' => $score >= 60,
            'score' => max(0, $score),
            'issues' => $issues,
            'recommendation' => $this->getValidationRecommendation($score)
        ];
    }

    /**
     * Sugere FAQs baseado em categoria
     */
    public function suggestForCategory(string $categoryId): array
    {
        $templates = self::FAQ_TEMPLATES[$categoryId] ?? self::FAQ_TEMPLATES['default'];

        $suggestions = [];
        foreach ($templates as $type => $questions) {
            foreach ($questions as $q) {
                $suggestions[] = [
                    'question_template' => $q,
                    'type' => $type,
                    'priority' => $this->getQuestionPriority($type)
                ];
            }
        }

        // Ordenar por prioridade
        usort($suggestions, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return [
            'category_id' => $categoryId,
            'suggestions' => $suggestions,
            'total' => count($suggestions)
        ];
    }

    // ========================================================================
    // MÉTODOS PRIVADOS
    // ========================================================================

    private function selectTemplates(?string $categoryId, int $count): array
    {
        $categoryTemplates = $categoryId ? (self::FAQ_TEMPLATES[$categoryId] ?? []) : [];
        $defaultTemplates = self::FAQ_TEMPLATES['default'];

        // Mesclar templates
        $merged = [];
        foreach ($defaultTemplates as $type => $questions) {
            $merged[$type] = array_merge(
                $categoryTemplates[$type] ?? [],
                $questions
            );
            $merged[$type] = array_unique($merged[$type]);
        }

        return $merged;
    }

    private function processQuestion(string $template, array $productData): string
    {
        $replacements = [
            '{product}' => $productData['title'] ?? 'o produto',
            '{brand}' => $productData['brand'] ?? 'a marca',
            '{model}' => $productData['model'] ?? 'o modelo',
            '{category}' => $productData['category_name'] ?? 'a categoria'
        ];

        $question = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );

        // Truncar se muito longo
        if (mb_strlen($question) > 100) {
            $question = mb_substr($question, 0, 97) . '...?';
        }

        return $question;
    }

    private function generateAnswer(string $type, array $productData): string
    {
        $title = $productData['title'] ?? 'Este produto';
        $brand = $productData['brand'] ?? '';
        $specs = $productData['specs'] ?? [];

        $answers = [
            'specs' => "O {$title} possui especificações de alta qualidade que atendem às suas necessidades. Consulte as características técnicas para mais detalhes.",
            'usage' => "Sim! O {$title} é projetado para uso versátil, seja profissional ou lazer. Sua durabilidade garante longa vida útil.",
            'compatibility' => "O {$title} é compatível com a maioria dos modelos populares do mercado. Verifique a lista de compatibilidade para confirmar.",
            'shipping' => "Enviamos o {$title} com todo cuidado. O prazo varia conforme sua localização, mas trabalhamos para entrega rápida.",
            'warranty' => "O {$title} possui garantia do fabricante. Em caso de defeito, nossa equipe oferece suporte completo para troca."
        ];

        return $answers[$type] ?? $answers['specs'];
    }

    private function extractKeywords(string $text): array
    {
        $stopWords = ['o', 'a', 'os', 'as', 'um', 'uma', 'de', 'da', 'do', 'para', 'com', 'em', 'que', 'é', 'são'];
        $words = preg_split('/\s+/', mb_strtolower($text));

        $keywords = [];
        foreach ($words as $word) {
            $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if (strlen($word) > 3 && !in_array($word, $stopWords)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    private function buildFAQPrompt(array $productData, int $count): string
    {
        $title = $productData['title'] ?? '';
        $description = $productData['description'] ?? '';
        $category = $productData['category_name'] ?? '';

        return "Gere {$count} perguntas frequentes (FAQ) para o seguinte produto do Mercado Livre:

Título: {$title}
Categoria: {$category}
Descrição: {$description}

Regras:
1. Perguntas devem ser naturais, como um comprador perguntaria
2. Respostas devem ter entre 50-150 caracteres
3. Inclua perguntas sobre: especificações, uso, compatibilidade, garantia
4. Use linguagem informal mas profissional
5. NÃO invente dados técnicos específicos

Formato de resposta (JSON):
[{\"question\": \"...\", \"answer\": \"...\"}]";
    }

    private function parseFAQResponse(string $response): array
    {
        // Tentar extrair JSON
        preg_match('/\[[\s\S]*\]/', $response, $matches);

        if (!empty($matches[0])) {
            $faqs = json_decode($matches[0], true);
            if (is_array($faqs)) {
                return $faqs;
            }
        }

        return [];
    }

    private function injectKeywords(string $text, array $keywords, int $maxInject): string
    {
        $injected = 0;
        $result = $text;

        foreach ($keywords as $keyword) {
            if ($injected >= $maxInject) break;

            // Garantir que keyword é string
            if (is_array($keyword)) {
                $keyword = $keyword['keyword'] ?? $keyword['word'] ?? $keyword[0] ?? '';
            }
            if (empty($keyword) || !is_string($keyword)) continue;

            // Não injetar se já existe
            if (stripos($result, $keyword) !== false) continue;

            // Encontrar ponto de inserção natural
            if (preg_match('/\.\s/', $result, $matches, PREG_OFFSET_CAPTURE)) {
                $pos = $matches[0][1] + 2;
                $result = substr($result, 0, $pos) . mb_strtoupper(mb_substr($keyword, 0, 1)) . mb_substr($keyword, 1) . '. ' . substr($result, $pos);
                $injected++;
            }
        }

        return $result;
    }

    private function countAddedKeywords(string $original, string $optimized, array $keywords): int
    {
        $count = 0;
        foreach ($keywords as $kw) {
            $originalCount = substr_count(mb_strtolower($original), mb_strtolower($kw));
            $optimizedCount = substr_count(mb_strtolower($optimized), mb_strtolower($kw));
            $count += max(0, $optimizedCount - $originalCount);
        }
        return $count;
    }

    private function generateAccordionHTML(array $faqs): string
    {
        $html = '<div class="faq-accordion">';

        foreach ($faqs as $i => $faq) {
            $id = 'faq-' . $i;
            $html .= <<<HTML
<div class="faq-item">
    <button class="faq-question" onclick="toggleFAQ('{$id}')">
        <span>{$faq['question']}</span>
        <span class="faq-icon">+</span>
    </button>
    <div id="{$id}" class="faq-answer" style="display:none;">
        <p>{$faq['answer']}</p>
    </div>
</div>
HTML;
        }

        $html .= '</div>';
        return $html;
    }

    private function generateSimpleHTML(array $faqs): string
    {
        $html = '<div class="faq-list">';

        foreach ($faqs as $faq) {
            $html .= <<<HTML
<div class="faq-item">
    <h3 class="faq-question">{$faq['question']}</h3>
    <p class="faq-answer">{$faq['answer']}</p>
</div>
HTML;
        }

        $html .= '</div>';
        return $html;
    }

    private function normalizeQuestion(string $question): string
    {
        // Remover pontuação e normalizar
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $question);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return trim($normalized);
    }

    private function generateSuggestionsFromAnalysis(array $commonQuestions): array
    {
        $suggestions = [];

        foreach (array_slice($commonQuestions, 0, 5, true) as $question => $count) {
            $suggestions[] = [
                'question' => mb_strtoupper(mb_substr($question, 0, 1)) . mb_substr($question, 1) . '?',
                'frequency' => $count,
                'recommendation' => 'Incluir esta FAQ - alta frequência entre concorrentes'
            ];
        }

        return $suggestions;
    }

    private function getValidationRecommendation(int $score): string
    {
        if ($score >= 90) return 'Excelente! FAQs bem estruturadas.';
        if ($score >= 70) return 'Bom, mas pode melhorar algumas respostas.';
        if ($score >= 50) return 'Regular. Revise as FAQs indicadas.';
        return 'FAQs precisam de revisão significativa.';
    }

    private function getQuestionPriority(string $type): int
    {
        $priorities = [
            'compatibility' => 100,
            'specs' => 90,
            'usage' => 80,
            'warranty' => 70,
            'shipping' => 60
        ];

        return $priorities[$type] ?? 50;
    }
}
