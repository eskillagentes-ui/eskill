<?php

declare(strict_types=1);

namespace App\Services\AI\Answers;

use App\Services\LLMService;
use App\Services\NegotiationService;
use App\Services\ItemService;

class AnswerGeneratorService
{
    private LLMService $llm;
    private NegotiationService $negotiationService;
    private ItemService $itemService;
    private ?int $accountId;

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->llm = new LLMService();
        $this->negotiationService = new NegotiationService();
        $this->itemService = new ItemService($accountId);
    }

    /**
     * Generates a draft answer using AI and Negotiation Logic
     */
    public function generateDraft(array $question): array
    {
        try {
            // 1. Validation
            if (empty($question['item_id'])) {
                return ['success' => false, 'error' => 'Item ID required'];
            }

            // 2. Fetch Item Details for Context
            $item = $this->itemService->getItem($question['item_id']);
            if (isset($item['error'])) {
                // Fallback if item not found locally? Maybe fetch from API?
                // For now, fail.
                return ['success' => false, 'error' => 'Item context not found: ' . $item['error']];
            }

            // 3. Check Auto-Negotiation (DealMaker)
            $negotiationResult = $this->negotiationService->processNegotiation($question['text'], $question['item_id']);

            if ($negotiationResult) {
                return [
                    'success' => true,
                    'draft' => $negotiationResult['text'],
                    'model' => 'dealmaker-v1',
                    'action' => $negotiationResult['action'],
                    'source' => 'rule_engine'
                ];
            }

            // 4. Build Context for AI
            $context = $this->buildContext($item, $question);

            // 5. Generate with LLM
            $systemPrompt = $this->getSystemPrompt();
            $userPrompt = "Generate a response for this customer question based on the product context:\n\n" . $context;

            $result = $this->llm->generate($userPrompt, $systemPrompt, 'basic');

            return [
                'success' => true,
                'draft' => $result['content'],
                'model' => $result['model'],
                'source' => 'ai_generation'
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function buildContext(array $item, array $question): string
    {
        $context = "Product: " . ($item['title'] ?? 'N/A') . "\n";
        $context .= "Price: " . ($item['price'] ?? 'N/A') . "\n";
        $context .= "Description: " . mb_substr(($item['description'] ?? ''), 0, 800) . "...\n"; // Increased limit

        // Add Attributes if available
        if (!empty($item['attributes'])) {
            $attrs = array_slice($item['attributes'], 0, 15);
            $context .= "Attributes: " . json_encode($attrs) . "\n";
        }

        $context .= "\nCustomer Question: " . $question['text'] . "\n";
        return $context;
    }

    private function getSystemPrompt(): string
    {
        return "You are an expert E-commerce Support Agent for Mercado Livre Brazil. " .
            "Answer politely, concisely, and directly in Brazilian Portuguese. " .
            "Use a professional but friendly tone. " .
            "If the answer is not in the context, suggest checking the description or asking for specifics. " .
            "DO NOT hallucinate technical data. " .
            "Keep answers short (max 3 sentences) unless detailed explanation is needed.";
    }
}
