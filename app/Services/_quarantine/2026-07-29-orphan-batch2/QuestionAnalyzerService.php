<?php

declare(strict_types=1);

namespace App\Services\AI\Answers;

use App\Services\LLMService;

class QuestionAnalyzerService
{
    private LLMService $llm;

    public function __construct()
    {
        $this->llm = new LLMService();
    }

    /**
     * Analyzes sentiment, intent, and urgency of a question
     */
    public function analyze(string $text, string $itemContext = ''): array
    {
        $systemPrompt = "You are an E-commerce Customer Service Analyst. " .
                       "Analyze the customer question and return ONLY a valid JSON object with: " .
                       "- sentiment: 'positive', 'neutral', 'negative', 'angry'\n" .
                       "- intent: 'shipping', 'technical', 'price', 'stock', 'warranty', 'compatibility', 'other'\n" .
                       "- urgency: integer 0-100 (100 is critical)\n" .
                       "- reasoning: short explanation (max 10 words)";

        $userPrompt = "Product Context: $itemContext\n\nQuestion: $text";

        try {
            $result = $this->llm->generate($userPrompt, $systemPrompt, 'basic');
            
            return $this->parseJson($result['content']);
            
        } catch (\Exception $e) {
            return $this->getFallback();
        }
    }

    private function parseJson(string $content): array
    {
        // Try to find JSON structure
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) return $json;
        }

        return $this->getFallback();
    }

    private function getFallback(): array
    {
        return [
            'sentiment' => 'neutral',
            'intent' => 'other',
            'urgency' => 50,
            'reasoning' => 'Analysis failed'
        ];
    }
}
