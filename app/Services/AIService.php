<?php

declare(strict_types=1);

namespace App\Services;

/**
 * @deprecated Use LLMService / UnifiedAIService. Mantido como fachada para consumidores legados.
 *
 * Delega geração ao AI Gateway interno (LLMService + AIProviderManager).
 */
class AIService
{
    private LLMService $llm;

    public function __construct(string $provider = 'openai', ?string $apiKey = null)
    {
        // $provider/$apiKey ignorados — LLMService resolve provedores via AIConfigService.
        unset($provider, $apiKey);
        $this->llm = new LLMService();
    }

    /**
     * Generate content using AI gateway
     */
    public function generate(string $prompt): string
    {
        $result = $this->llm->generate($prompt, '', 'basic');
        if (($result['success'] ?? false) === true) {
            return (string) ($result['content'] ?? '');
        }

        $error = (string) ($result['error'] ?? 'LLM unavailable');
        log_error('AIService gateway failure', [
            'service' => 'AIService',
            'error' => $error,
            'via' => 'LLMService',
        ]);
        throw new \RuntimeException('AI gateway: ' . $error);
    }

    /**
     * Expand synonyms using AI
     */
    public function expandSynonyms(string $keyword, string $categoryId): array
    {
        $prompt = "Generate a comprehensive list of synonyms and related terms for the keyword '{$keyword}' in the category {$categoryId}. Focus on Brazilian Portuguese terms. Return only as a JSON array.";
        $response = $this->generate($prompt);

        $synonyms = json_decode($response, true);
        if (is_array($synonyms)) {
            return $synonyms;
        }

        $lines = explode("\n", $response);
        $extracted = [];

        foreach ($lines as $line) {
            $cleanLine = trim($line, " \t\n\r\0\x0B-.\"");
            if ($cleanLine !== '' && !preg_match('/^[0-9]+\.?\s*$/', $cleanLine)) {
                $extracted[] = $cleanLine;
            }
        }

        return $extracted;
    }

    /**
     * Generate context-aware content
     */
    public function generateContextContent(string $baseContent, array $contexts): string
    {
        $contextStr = implode(', ', $contexts);
        $prompt = "Enhance and expand this content considering these usage contexts: {$contextStr}. Original content: {$baseContent}. Provide in Brazilian Portuguese.";

        return $this->generate($prompt);
    }

    /**
     * Classify keywords using AI
     *
     * @param list<string> $keywords
     * @return array<string, list<string>>
     */
    public function classifyKeywords(array $keywords, string $categoryId): array
    {
        $keywordsStr = implode(', ', $keywords);
        $prompt = "Classify these keywords for category {$categoryId} in Brazilian Portuguese market into four types: core (main product terms), support (auxiliary terms), technical (specifications), and context (usage situations). Return as a JSON object with arrays for each type. Keywords: {$keywordsStr}";

        try {
            $response = $this->generate($prompt);
            $classification = json_decode($response, true);

            if (is_array($classification) &&
                isset($classification['core']) &&
                isset($classification['suporte']) &&
                isset($classification['tecnica']) &&
                isset($classification['contexto'])) {
                return $classification;
            }

            return [
                'core' => [],
                'suporte' => [],
                'tecnica' => [],
                'contexto' => [],
            ];
        } catch (\Throwable $e) {
            log_warning('Erro na classificação de keywords por IA', [
                'service' => 'AIService',
                'error' => $e->getMessage(),
            ]);
            return [
                'core' => [],
                'suporte' => [],
                'tecnica' => [],
                'contexto' => [],
            ];
        }
    }

    /**
     * Generate SEO-optimized description using AI
     */
    public function generateSeoDescription(string $title, string $features, string $targetKeywords): string
    {
        $prompt = "Create an SEO-optimized product description in Brazilian Portuguese for: '{$title}'. Features: {$features}. Target keywords: {$targetKeywords}. The description should be 400-600 words, include natural keyword placement, highlight benefits, and be compelling for customers.";

        return $this->generate($prompt);
    }

    /**
     * Generate FAQ content using AI
     *
     * @param list<string> $keywords
     * @return list<array<string, mixed>>
     */
    public function generateFAQ(array $keywords, string $productType): array
    {
        $keywordsStr = implode(', ', $keywords);
        $prompt = "Generate 5 common questions and answers in Brazilian Portuguese for a {$productType} focusing on these keywords: {$keywordsStr}. Return as a JSON array of objects with 'question' and 'answer' properties.";

        $response = $this->generate($prompt);
        $faq = json_decode($response, true);

        return is_array($faq) ? $faq : [];
    }
}
