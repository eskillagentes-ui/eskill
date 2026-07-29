<?php

declare(strict_types=1);

namespace App\Services\AI\Optimizers;

use App\Services\AI\Providers\OpenAIProvider;
use App\Services\AI\Core\PromptBuilder;

/**
 * Description Optimization Service
 * Generates optimized product descriptions using AI
 */
class DescriptionOptimizer
{
    private OpenAIProvider $aiProvider;
    private PromptBuilder $promptBuilder;
    private array $config;

    // Category-specific templates
    private const CATEGORY_TEMPLATES = [
        'electronics' => [
            'focus' => 'specs_and_features',
            'tone' => 'technical_friendly',
            'keywords' => ['especificações', 'tecnologia', 'compatibilidade', 'performance'],
        ],
        'fashion' => [
            'focus' => 'style_and_fit',
            'tone' => 'lifestyle',
            'keywords' => ['estilo', 'conforto', 'tendência', 'qualidade'],
        ],
        'home' => [
            'focus' => 'utility_and_design',
            'tone' => 'practical',
            'keywords' => ['praticidade', 'design', 'durabilidade', 'funcional'],
        ],
        'sports' => [
            'focus' => 'performance_and_durability',
            'tone' => 'energetic',
            'keywords' => ['performance', 'resistência', 'treino', 'atleta'],
        ],
    ];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? [
            'model' => $_ENV['AI_DEFAULT_MODEL'] ?? 'gpt-4o',
            'temperature' => 0.7,
            'max_tokens' => 2000,
        ];

        $this->aiProvider = new OpenAIProvider($this->config);
        $this->promptBuilder = new PromptBuilder();
    }

    /**
     * Generate optimized description from product data
     *
     * @param array $productData Product information
     * @param array $keywords SEO keywords to include
     * @return array Generation result
     */
    public function generate(array $productData, array $keywords = []): array
    {
        $startTime = microtime(true);

        // Build prompt
        $prompt = $this->promptBuilder->buildDescriptionOptimizationPrompt($productData, $keywords);

        // Get AI response
        $response = $this->aiProvider->chat([
            [
                'role' => 'system',
                'content' => $this->promptBuilder->buildSystemMessage('copywriter')
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ], [
            'temperature' => $this->config['temperature'],
            'max_tokens' => $this->config['max_tokens'],
        ]);

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['message'] ?? 'AI request failed',
            ];
        }

        // Parse AI response
        $aiData = $this->parseAIResponse($response['content']);

        if (!$aiData) {
            return [
                'success' => false,
                'error' => 'Failed to parse AI response',
                'raw_response' => $response['content'],
            ];
        }

        $duration = microtime(true) - $startTime;

        return [
            'success' => true,
            'description' => $aiData['description'],
            'score' => $aiData['score'] ?? 0,
            'char_count' => $aiData['char_count'] ?? mb_strlen($aiData['description']),
            'keywords_used' => $aiData['keywords_used'] ?? [],
            'highlights' => $aiData['highlights'] ?? [],
            'structure_compliance' => $aiData['structure_compliance'] ?? [],
            'ai_model' => $response['model'],
            'ai_provider' => $response['provider'],
            'usage' => $response['usage'],
            'cost' => $response['cost'] ?? 0,
            'duration' => round($duration, 3),
        ];
    }

    /**
     * Optimize existing description
     *
     * @param string $currentDescription Current description text
     * @param array $productData Additional product info for context
     * @param array $keywords Keywords to ensure are included
     * @return array Optimization result
     */
    public function optimize(string $currentDescription, array $productData = [], array $keywords = []): array
    {
        // Add current description to product data for context
        $productData['current_description'] = $currentDescription;

        // Use generate method
        return $this->generate($productData, $keywords);
    }

    /**
     * Generate multiple versions with different styles
     *
     * @param array $productData Product information
     * @param array $keywords Keywords
     * @return array Multiple versions
     */
    public function generateVersions(array $productData, array $keywords = []): array
    {
        $versions = [];
        $styles = ['persuasive', 'technical', 'lifestyle'];

        foreach ($styles as $style) {
            // Modify prompt for each style
            $styleConfig = $this->config;
            $styleConfig['style'] = $style;

            $productData['style_preference'] = $style;
            $result = $this->generate($productData, $keywords);

            if ($result['success']) {
                $versions[$style] = $result;
            }
        }

        return [
            'versions' => $versions,
            'count' => count($versions),
        ];
    }

    /**
     * Analyze description quality without optimizing
     *
     * @param string $description Description to analyze
     * @param array $context Context for analysis (keywords)
     * @return array Analysis result
     */
    public function analyze(string $description, array $context = []): array
    {
        $issues = [];
        $strengths = [];
        $score = 100;

        $length = mb_strlen($description);
        $keywords = $context;

        // Length analysis - strict checks
        if ($length < 100) {
            $issues[] = 'Descrição muito curta';
            $score -= 40;
        } elseif ($length < 400) {
            $issues[] = 'Descrição curta (recomendado mínimo 400 caracteres)';
            $score -= 20;
        } elseif ($length < 800) {
            $issues[] = 'Descrição moderada (recomendado mínimo 800 caracteres)';
            $score -= 10;
        } elseif ($length >= 1500 && $length <= 2500) {
            $strengths[] = 'Comprimento ideal (1500-2500 caracteres)';
        } elseif ($length > 4500) {
            $issues[] = 'Descrição muito longa (pode cansar o leitor)';
            $score -= 5;
        }

        // Keyword analysis (keywords are passed directly as array)
        $keywordDensity = 0;
        if (!empty($keywords)) {
            $descLower = mb_strtolower($description);
            $foundKeywords = 0;
            $totalOccurrences = 0;

            foreach (array_slice($keywords, 0, 10) as $keyword) {
                $keywordLower = mb_strtolower($keyword);
                $count = mb_substr_count($descLower, $keywordLower);
                if ($count > 0) {
                    $foundKeywords++;
                    $totalOccurrences += $count;
                }
            }

            // Calculate keyword density (occurrences per 100 words)
            $wordCount = str_word_count($description);
            if ($wordCount > 0) {
                $keywordDensity = round(($totalOccurrences / $wordCount) * 100, 2);
            }

            if ($foundKeywords === 0) {
                $issues[] = 'Nenhuma keyword SEO encontrada';
                $score -= 15;
            } elseif ($foundKeywords <= 3) {
                $issues[] = 'Poucas keywords SEO (encontradas: ' . $foundKeywords . ')';
                $score -= 10;
            } else {
                $strengths[] = "{$foundKeywords} keywords SEO incluídas";
            }
        }

        // Structure analysis
        $hasEmojis = preg_match('/[\x{1F300}-\x{1F9FF}]/u', $description);
        $hasBullets = strpos($description, '•') !== false || strpos($description, '-') !== false;
        $hasSections = preg_match('/^#|^\d+\.|^[A-Z]{2,}/m', $description) || substr_count($description, "\n\n") >= 2;

        if ($hasBullets) {
            $strengths[] = 'Usa bullet points (boa formatação)';
        } else {
            $issues[] = 'Sem bullet points (dificulta leitura)';
            $score -= 10;
        }

        if ($hasEmojis) {
            $strengths[] = 'Usa emojis (visual atrativo)';
        }

        if ($hasSections) {
            $strengths[] = 'Bem estruturada em seções';
        } else {
            $issues[] = 'Falta estrutura em seções';
            $score -= 10;
        }

        // Content density
        $wordCount = str_word_count($description);

        if ($wordCount < 100) {
            $issues[] = 'Poucas palavras (menos de 100)';
            $score -= 15;
        }

        // Check for common weak phrases
        $weakPhrases = ['produto novo', 'boa qualidade', 'melhor preço', 'não perca'];
        $hasWeakPhrases = false;
        foreach ($weakPhrases as $phrase) {
            if (mb_stripos($description, $phrase) !== false) {
                $hasWeakPhrases = true;
                break;
            }
        }

        if ($hasWeakPhrases) {
            $issues[] = 'Usa frases genéricas (pouco persuasivo)';
            $score -= 10;
        }

        return [
            'description' => $description,
            'score' => max(0, min(100, $score)),
            'char_count' => $length,
            'word_count' => $wordCount,
            'issues' => $issues,
            'strengths' => $strengths,
            'grade' => $this->getGrade($score),
            'keyword_density' => $keywordDensity,
            'structure' => [
                'has_bullets' => (bool) $hasBullets,
                'has_emojis' => (bool) $hasEmojis,
                'has_sections' => (bool) $hasSections,
            ],
        ];
    }

    /**
     * Enhance description with specific improvements
     *
     * @param string $description Current description
     * @param array $enhancements What to enhance (add_emojis, add_bullets, add_sections)
     * @return array Enhanced description
     */
    public function enhance(string $description, array $enhancements = []): array
    {
        $enhanced = $description;
        $changes = [];

        // Support both array of strings and associative array formats
        $addEmojis = isset($enhancements['add_emojis']) ? $enhancements['add_emojis'] : in_array('emojis', $enhancements);
        $addBullets = isset($enhancements['add_bullets']) ? $enhancements['add_bullets'] : in_array('bullets', $enhancements);
        $addSections = isset($enhancements['add_sections']) ? $enhancements['add_sections'] : in_array('sections', $enhancements);
        $addStructure = isset($enhancements['add_structure']) ? $enhancements['add_structure'] : in_array('structure', $enhancements);

        // Add emojis to sections
        if ($addEmojis) {
            $emojiMap = [
                'CARACTERÍSTICAS' => '📋',
                'ESPECIFICAÇÕES' => '📐',
                'GARANTIA' => '🛡️',
                'ENVIO' => '🚚',
                'INCLUSO' => '📦',
                'QUALIDADE' => '⭐',
                'BATERIA' => '🔋',
                'RESISTENTE' => '💪',
                'COMPATÍVEL' => '📱',
            ];

            foreach ($emojiMap as $word => $emoji) {
                if (mb_stripos($enhanced, $word) !== false && mb_strpos($enhanced, $emoji) === false) {
                    $enhanced = preg_replace('/\b' . $word . '\b/iu', $emoji . ' ' . $word, $enhanced, 1);
                    $changes[] = "Adicionado emoji {$emoji} para {$word}";
                }
            }
        }

        // Add bullet points
        if ($addBullets && strpos($enhanced, '•') === false) {
            // Convert lines that look like features to bullet points
            $lines = explode("\n", $enhanced);
            $modifiedLines = [];

            foreach ($lines as $line) {
                $trimmed = trim($line);
                // If line starts with - or looks like a feature (short, starts with capital)
                if (preg_match('/^-\s*(.+)$/', $trimmed, $match)) {
                    $modifiedLines[] = '• ' . trim($match[1]);
                    $changes[] = 'Convertido - para •';
                } elseif (
                    mb_strlen($trimmed) > 5 && mb_strlen($trimmed) < 80 &&
                    preg_match('/^[A-ZÀÁÂÃÉÊÍÓÔÕÚÇ]/', $trimmed) &&
                    !preg_match('/[.!?:]$/', $trimmed)
                ) {
                    $modifiedLines[] = '• ' . $trimmed;
                } else {
                    $modifiedLines[] = $line;
                }
            }

            $enhanced = implode("\n", $modifiedLines);
        }

        // Add structure (line breaks and sections)
        if ($addStructure || $addSections) {
            // Add spacing between sections if missing
            $enhanced = preg_replace('/([.!])\s*([A-ZÀÁÂÃÉÊÍÓÔÕÚÇ]{3,})/', "$1\n\n$2", $enhanced);

            // Ensure proper paragraph breaks
            $enhanced = preg_replace('/\n{3,}/', "\n\n", $enhanced);

            $changes[] = 'Melhorada estrutura com espaçamentos';
        }

        return [
            'original' => $description,
            'enhanced' => $enhanced,
            'changes' => $changes,
            'improvement' => $this->calculateImprovement($description, $enhanced),
        ];
    }

    /**
     * Get template by category
     *
     * @param string $category Category name (electronics, fashion, home, sports)
     * @return string Template string
     */
    public function getTemplateByCategory(string $category): string
    {
        $templates = [
            'electronics' => "📱 {TITLE}\n\n📋 CARACTERÍSTICAS PRINCIPAIS\n• {FEATURE_1}\n• {FEATURE_2}\n• {FEATURE_3}\n\n📐 ESPECIFICAÇÕES TÉCNICAS\n• {SPEC_1}\n• {SPEC_2}\n• {SPEC_3}\n\n✅ COMPATIBILIDADE\n{COMPATIBILITY}\n\n📦 O QUE ESTÁ INCLUSO\n{INCLUDED}\n\n🛡️ GARANTIA\n{WARRANTY}",

            'fashion' => "👗 {TITLE}\n\n✨ ESTILO E CONFORTO\n{STYLE_DESCRIPTION}\n\n📏 MEDIDAS\n• {SIZE_1}\n• {SIZE_2}\n• {SIZE_3}\n\n🧵 MATERIAL\n{MATERIAL}\n\n💡 DICAS DE USO\n{TIPS}\n\n🎁 OCASIÕES\n{OCCASIONS}",

            'home' => "🏠 {TITLE}\n\n⭐ BENEFÍCIOS\n• {BENEFIT_1}\n• {BENEFIT_2}\n• {BENEFIT_3}\n\n📐 DIMENSÕES\n{DIMENSIONS}\n\n🛠️ MATERIAL\n{MATERIAL}\n\n📦 CONTEÚDO DA EMBALAGEM\n{INCLUDED}\n\n🧹 CUIDADOS\n{CARE}",

            'sports' => "🏃 {TITLE}\n\n💪 PERFORMANCE\n{PERFORMANCE_DESCRIPTION}\n\n📋 CARACTERÍSTICAS\n• {FEATURE_1}\n• {FEATURE_2}\n• {FEATURE_3}\n\n📏 ESPECIFICAÇÕES\n{SPECS}\n\n🎯 INDICADO PARA\n{RECOMMENDED_FOR}\n\n🏆 DIFERENCIAIS\n{BENEFITS}",

            'default' => "📦 {TITLE}\n\n📋 DESCRIÇÃO\n{DESCRIPTION}\n\n✅ CARACTERÍSTICAS\n• {FEATURE_1}\n• {FEATURE_2}\n• {FEATURE_3}\n\n📐 ESPECIFICAÇÕES\n{SPECS}\n\n📦 INCLUSO\n{INCLUDED}",
        ];

        return $templates[$category] ?? $templates['default'];
    }

    /**
     * Validate description length
     *
     * @param string $description Description to validate
     * @param int $minLength Minimum length (default 100)
     * @param int $maxLength Maximum length (default 4500)
     * @return bool True if valid length
     */
    public function validateLength(string $description, int $minLength = 100, int $maxLength = 4500): bool
    {
        $length = mb_strlen($description);
        return $length >= $minLength && $length <= $maxLength;
    }

    /**
     * Parse AI JSON response
     *
     * @param string $content
     * @return array|null
     */
    private function parseAIResponse(string $content): ?array
    {
        // Try to extract JSON from markdown code blocks
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        } elseif (preg_match('/```\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        // Try direct JSON parse
        $data = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($data['description'])) {
            return $data;
        }

        // Fallback: try to find JSON object in text
        if (preg_match('/\{[\s\S]*"description"[\s\S]*\}/m', $content, $matches)) {
            $data = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        log_warning('Falha ao parsear resposta AI para descrição', [
            'service' => 'DescriptionOptimizer',
            'response_preview' => substr($content, 0, 200),
        ]);
        return null;
    }

    /**
     * Get letter grade from score
     *
     * @param int $score
     * @return string
     */
    private function getGrade(int $score): string
    {
        if ($score >= 90) return 'A+';
        if ($score >= 85) return 'A';
        if ($score >= 80) return 'B+';
        if ($score >= 75) return 'B';
        if ($score >= 70) return 'C+';
        if ($score >= 65) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }

    /**
     * Calculate improvement percentage
     *
     * @param string $original
     * @param string $enhanced
     * @return float
     */
    private function calculateImprovement(string $original, string $enhanced): float
    {
        $originalScore = $this->analyze($original)['score'];
        $enhancedScore = $this->analyze($enhanced)['score'];

        return round((($enhancedScore - $originalScore) / max($originalScore, 1)) * 100, 1);
    }
}
