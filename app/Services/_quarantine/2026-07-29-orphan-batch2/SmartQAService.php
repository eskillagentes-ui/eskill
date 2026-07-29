<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\MercadoLivreClient;
use App\Services\CacheService;
use App\Services\LLMService;
use App\Services\StructuredLogService;

/**
 * Smart Q&A Automation Service
 *
 * Features:
 * - Intelligent auto-response system
 * - Sentiment-based prioritization
 * - Proactive Q&A generation
 * - Bulk answer processing
 * - Knowledge base integration
 * - Escalation rules
 */
class SmartQAService
{
    private \PDO $db;
    private MercadoLivreClient $mlClient;
    private CacheService $cache;
    private StructuredLogService $logger;
    private int $accountId;
    private array $config;
    private array $knowledgeBase;

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = \App\Database::getInstance();
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->cache = new CacheService();
        $this->logger = new StructuredLogService();
        $this->config = $this->loadQAConfig();
        $this->knowledgeBase = $this->loadKnowledgeBase();
    }

    /**
     * Process and answer questions automatically
     */
    public function processAutoResponses(array $questions = []): array
    {
        try {
            // Get pending questions if none provided
            if (empty($questions)) {
                $questions = $this->getPendingQuestions();
            }

            $results = [];

            foreach ($questions as $question) {
                $result = $this->processSingleQuestion($question);
                $results[] = $result;

                // Apply auto-response if recommended
                if ($result['auto_respond'] && $result['answer']) {
                    $this->sendAutoAnswer($question['id'], $result['answer']);
                }
            }

            return [
                'success' => true,
                'processed_questions' => count($results),
                'auto_answered' => count(array_filter($results, fn(array $r): bool => $r['auto_respond'])),
                'escalated' => count(array_filter($results, fn(array $r): bool => $r['escalate'])),
                'results' => $results,
                'summary' => $this->generateProcessingSummary($results)
            ];
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::processAutoResponses error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate proactive Q&A for products
     */
    public function generateProactiveQA(array $productIds = []): array
    {
        try {
            $proactiveQA = [];

            // Get products if none specified
            if (empty($productIds)) {
                $productIds = $this->getProductsNeedingQA();
            }

            foreach ($productIds as $productId) {
                $qaSet = $this->generateQAForProduct($productId);
                if (!empty($qaSet)) {
                    $proactiveQA[] = [
                        'product_id' => $productId,
                        'questions' => $qaSet,
                        'created_at' => time(),
                        'status' => 'draft'
                    ];
                }
            }

            // Save proactive Q&A to database
            $this->saveProactiveQA($proactiveQA);

            return [
                'success' => true,
                'products_processed' => count($proactiveQA),
                'total_questions_generated' => array_sum(array_column($proactiveQA, 'questions_count')),
                'proactive_qa' => $proactiveQA,
                'estimated_impact' => $this->estimateQAImpact($proactiveQA)
            ];
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::generateProactiveQA error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Batch answer processing
     */
    public function processBatchAnswers(array $questionBatch): array
    {
        try {
            $results = [];
            $batchId = $this->generateBatchId();

            foreach ($questionBatch as $question) {
                $result = $this->processSingleQuestion($question);
                $result['batch_id'] = $batchId;
                $results[] = $result;
            }

            // Process batch optimizations
            $batchOptimizations = $this->optimizeBatchResponses($results);

            return [
                'success' => true,
                'batch_id' => $batchId,
                'total_questions' => count($results),
                'auto_answered' => count(array_filter($results, fn(array $r): bool => $r['auto_respond'])),
                'optimizations_applied' => count($batchOptimizations),
                'results' => $results,
                'batch_optimizations' => $batchOptimizations
            ];
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::processBatchAnswers error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update knowledge base
     */
    public function updateKnowledgeBase(array $newEntries): array
    {
        try {
            $updatedEntries = [];

            foreach ($newEntries as $entry) {
                $processedEntry = $this->processKnowledgeEntry($entry);
                if ($processedEntry['valid']) {
                    $this->addToKnowledgeBase($processedEntry);
                    $updatedEntries[] = $processedEntry;
                }
            }

            // Rebuild knowledge base cache
            $this->rebuildKnowledgeBaseCache();

            return [
                'success' => true,
                'entries_added' => count($updatedEntries),
                'knowledge_base_size' => count($this->knowledgeBase),
                'updated_entries' => $updatedEntries
            ];
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::updateKnowledgeBase error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get Q&A analytics and insights
     */
    public function getQAAnalytics(array $filters = []): array
    {
        try {
            $analytics = [
                'overview' => $this->getQAOverview($filters),
                'response_performance' => $this->getResponsePerformanceAnalytics($filters),
                'question_trends' => $this->getQuestionTrends($filters),
                'auto_response_effectiveness' => $this->getAutoResponseEffectiveness($filters),
                'escalation_patterns' => $this->getEscalationPatterns($filters),
                'knowledge_base_performance' => $this->getKnowledgeBasePerformance($filters),
                'product_question_heatmap' => $this->getProductQuestionHeatmap($filters),
                'time_to_answer_analysis' => $this->getTimeToAnswerAnalysis($filters)
            ];

            return [
                'success' => true,
                'analytics' => $analytics,
                'generated_at' => time(),
                'data_period' => $filters['period'] ?? 'last_30_days'
            ];
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getQAAnalytics error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process single question with AI
     */
    private function processSingleQuestion(array $question): array
    {
        // Analyze question
        $analysis = $this->analyzeQuestion($question);

        // Check if auto-response is appropriate
        $shouldAutoRespond = $this->shouldAutoRespond($question, $analysis);

        $answer = null;
        if ($shouldAutoRespond) {
            $answer = $this->generateAutoAnswer($question, $analysis);
        }

        // Check if escalation is needed
        $shouldEscalate = $this->shouldEscalate($question, $analysis);

        return [
            'question_id' => $question['id'],
            'question_text' => $question['text'],
            'analysis' => $analysis,
            'auto_respond' => $shouldAutoRespond,
            'answer' => $answer,
            'escalate' => $shouldEscalate,
            'confidence' => $answer ? $this->calculateAnswerConfidence($question, $answer) : 0,
            'priority' => $this->calculateQuestionPriority($question, $analysis),
            'category' => $analysis['category'],
            'sentiment' => $analysis['sentiment'],
            'urgency' => $analysis['urgency']
        ];
    }

    /**
     * Analyze question for categorization and sentiment
     */
    private function analyzeQuestion(array $question): array
    {
        // Get product info for context
        $productInfo = $this->getProductInfo($question['item_id']);

        // Use AI for analysis
        $aiAnalysis = $this->analyzeQuestionWithAI($question, $productInfo);

        // Fallback to rule-based analysis
        if (!$aiAnalysis) {
            $aiAnalysis = $this->analyzeQuestionWithRules($question);
        }

        return [
            'category' => $aiAnalysis['category'] ?? 'general',
            'sentiment' => $aiAnalysis['sentiment'] ?? 'neutral',
            'urgency' => $aiAnalysis['urgency'] ?? 'normal',
            'complexity' => $aiAnalysis['complexity'] ?? 'medium',
            'product_context' => $productInfo,
            'keywords' => $aiAnalysis['keywords'] ?? [],
            'intent' => $aiAnalysis['intent'] ?? 'inquiry',
            'requires_inventory_check' => $this->requiresInventoryCheck($question),
            'requires_shipping_info' => $this->requiresShippingInfo($question)
        ];
    }

    /**
     * Determine if question should be auto-responded
     */
    private function shouldAutoRespond(array $question, array $analysis): bool
    {
        // Check against auto-response rules
        $rules = $this->config['auto_response_rules'];

        // Never auto-respond to negative sentiment questions
        if ($analysis['sentiment'] === 'negative' && $rules['skip_negative_sentiment']) {
            return false;
        }

        // Auto-respond to low complexity questions
        if ($analysis['complexity'] === 'low' && $rules['auto_simple_questions']) {
            return true;
        }

        // Auto-respond if answer exists in knowledge base
        if ($this->hasKnowledgeBaseAnswer($question, $analysis)) {
            return true;
        }

        // Auto-respond if product information is sufficient
        if (
            $analysis['requires_inventory_check'] === false &&
            $analysis['requires_shipping_info'] === false &&
            $rules['auto_product_questions']
        ) {
            return true;
        }

        return false;
    }

    /**
     * Generate auto-answer using AI and knowledge base
     */
    private function generateAutoAnswer(array $question, array $analysis): ?string
    {
        // Try knowledge base first
        $kbAnswer = $this->findKnowledgeBaseAnswer($question, $analysis);
        if ($kbAnswer) {
            return $this->personalizeAnswer($kbAnswer, $question);
        }

        // Generate answer with AI
        $aiAnswer = $this->generateAIAnswer($question, $analysis);
        if ($aiAnswer) {
            return $aiAnswer;
        }

        // Use template answers
        $templateAnswer = $this->getTemplateAnswer($analysis);
        if ($templateAnswer) {
            return $this->personalizeAnswer($templateAnswer, $question);
        }

        return null;
    }

    /**
     * Generate Q&A for specific product
     */
    private function generateQAForProduct(string $productId): array
    {
        $productInfo = $this->getProductInfo($productId);
        if (!$productInfo) {
            return [];
        }

        $questions = [];

        // Generate questions based on product type and category
        $questionTemplates = $this->getQuestionTemplates($productInfo['category_id']);

        foreach ($questionTemplates as $template) {
            $question = $this->generateQuestionFromTemplate($template, $productInfo);
            $questions[] = $question;
        }

        // Add competitor questions
        $competitorQuestions = $this->generateCompetitorQuestions($productInfo);
        $questions = array_merge($questions, $competitorQuestions);

        // Add shipping and warranty questions
        if ($productInfo['requires_shipping']) {
            $questions[] = $this->generateShippingQuestion($productInfo);
        }

        if ($productInfo['has_warranty']) {
            $questions[] = $this->generateWarrantyQuestion($productInfo);
        }

        return $questions;
    }

    /**
     * Send auto-answer to Mercado Livre
     */
    private function sendAutoAnswer(string $questionId, string $answer): bool
    {
        try {
            $result = $this->mlClient->post("/questions/{$questionId}/answers", [
                'text' => $answer,
                'status' => 'ACTIVE'
            ]);

            if (!isset($result['error'])) {
                // Log auto-response
                $this->logAutoResponse($questionId, $answer);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            log_warning('Falha na auto-resposta de pergunta', [
                'service' => 'SmartQAService',
                'question_id' => $questionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Analyze question with AI
     */
    private function analyzeQuestionWithAI(array $question, array $productInfo): ?array
    {
        try {
            $prompt = $this->buildAnalysisPrompt($question, $productInfo);
            $llm = new LLMService();
            $response = $llm->generate($prompt);

            if ($response['success']) {
                $content = trim($response['content']);
                $content = preg_replace('/^```(?:json)?\s*/s', '', $content);
                $content = preg_replace('/\s*```$/s', '', $content);
                return json_decode($content, true);
            }

            return null;
        } catch (\Exception $e) {
            log_warning('Falha na análise de pergunta por IA', [
                'service' => 'SmartQAService',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate answer with AI
     */
    private function generateAIAnswer(array $question, array $analysis): ?string
    {
        try {
            $prompt = $this->buildAnswerPrompt($question, $analysis);
            $llm = new LLMService();
            $response = $llm->generate($prompt);

            if ($response['success'] && !empty($response['content'])) {
                return trim($response['content']);
            }

            return null;
        } catch (\Exception $e) {
            log_warning('Falha na geração de resposta por IA', [
                'service' => 'SmartQAService',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get pending questions
     */
    private function getPendingQuestions(): array
    {
        $stmt = $this->db->prepare("
            SELECT q.*, i.title as item_title, i.category_id
            FROM ml_questions q
            JOIN ml_items i ON q.item_id = i.id
            WHERE q.account_id = :account_id
            AND q.status = 'UNANSWERED'
            AND q.answer IS NULL
            ORDER BY q.date_created ASC
            LIMIT 50
        ");

        $stmt->execute(['account_id' => $this->accountId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Load Q&A configuration
     */
    private function loadQAConfig(): array
    {
        return [
            'auto_response_rules' => [
                'skip_negative_sentiment' => true,
                'auto_simple_questions' => true,
                'auto_product_questions' => true,
                'max_question_age_hours' => 24,
                'min_confidence_threshold' => 0.8
            ],
            'escalation_rules' => [
                'escalate_negative_sentiment' => true,
                'escalate_high_value_customers' => true,
                'escalate_complex_questions' => true,
                'escalation_keywords' => ['reclamação', 'problema', 'defeito', 'quebrado']
            ],
            'knowledge_base' => [
                'auto_update' => true,
                'confidence_threshold' => 0.9,
                'max_entries_per_product' => 100
            ]
        ];
    }

    // ─── Utility ───────────────────────────────────────────────────────────

    private function generateBatchId(): string
    {
        return 'batch_' . uniqid();
    }

    // ─── Data query helpers ─────────────────────────────────────────────

    private function getProductInfo(string $itemId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, title, price, category_id, available_quantity,
                       sold_quantity, status, sku, permalink
                FROM ml_items
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['id' => $itemId, 'account_id' => $this->accountId]);
            $item = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$item) {
                return [];
            }
            $item['requires_shipping'] = true;
            $item['has_warranty'] = ((float)($item['price'] ?? 0)) > 100;
            return $item;
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getProductInfo error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    private function getProductsNeedingQA(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT i.id
                FROM ml_items i
                LEFT JOIN ml_qa_proactive p ON p.item_id = i.id
                WHERE i.account_id = :account_id
                  AND i.status = 'active'
                  AND i.sold_quantity > 5
                  AND p.id IS NULL
                ORDER BY i.sold_quantity DESC
                LIMIT 20
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id');
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getProductsNeedingQA error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    private function loadKnowledgeBase(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, category, keywords, question_pattern, answer_template,
                       confidence_score, usage_count
                FROM ml_qa_knowledge_base
                ORDER BY usage_count DESC
                LIMIT 500
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['keywords'] = json_decode($row['keywords'] ?? '[]', true) ?: [];
            }
            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::loadKnowledgeBase error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    // ─── Knowledge base management ──────────────────────────────────────

    private function processKnowledgeEntry(array $entry): array
    {
        $category = trim($entry['category'] ?? '');
        $answer   = trim($entry['answer_template'] ?? $entry['answer'] ?? '');
        $pattern  = trim($entry['question_pattern'] ?? '');
        $keywords = $entry['keywords'] ?? [];

        if (empty($category) || empty($answer)) {
            return ['valid' => false, 'reason' => 'missing_required_fields'];
        }
        if (is_string($keywords)) {
            $keywords = array_map('trim', explode(',', $keywords));
        }

        return [
            'valid' => true,
            'category' => $category,
            'keywords' => $keywords,
            'question_pattern' => $pattern,
            'answer_template' => $answer,
            'confidence_score' => (float)($entry['confidence_score'] ?? 0.80)
        ];
    }

    private function addToKnowledgeBase(array $entry): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ml_qa_knowledge_base
                    (category, keywords, question_pattern, answer_template, confidence_score)
                VALUES (:category, :keywords, :pattern, :answer, :confidence)
            ");
            $stmt->execute([
                'category'   => $entry['category'],
                'keywords'   => json_encode($entry['keywords'] ?? []),
                'pattern'    => $entry['question_pattern'] ?? '',
                'answer'     => $entry['answer_template'],
                'confidence' => $entry['confidence_score'] ?? 0.80
            ]);
            $this->knowledgeBase[] = $entry;
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::addToKnowledgeBase error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            // ignore duplicates
        }
    }

    private function rebuildKnowledgeBaseCache(): void
    {
        $this->knowledgeBase = $this->loadKnowledgeBase();
        $this->cache->set('qa_knowledge_base_' . $this->accountId, $this->knowledgeBase, 3600);
    }

    private function hasKnowledgeBaseAnswer(array $question, array $analysis): bool
    {
        return $this->findKnowledgeBaseAnswer($question, $analysis) !== null;
    }

    private function findKnowledgeBaseAnswer(array $question, array $analysis): ?string
    {
        $text = mb_strtolower($question['question_text'] ?? $question['text'] ?? '');
        $category = $analysis['category'] ?? '';

        foreach ($this->knowledgeBase as $entry) {
            // Match by category first
            if (!empty($entry['category']) && $entry['category'] !== $category) {
                continue;
            }
            // Match by pattern
            if (!empty($entry['question_pattern'])) {
                $pattern = mb_strtolower($entry['question_pattern']);
                if (mb_strpos($text, $pattern) !== false) {
                    $this->incrementKBUsage((int)($entry['id'] ?? 0));
                    return $entry['answer_template'];
                }
            }
            // Match by keywords
            $keywords = $entry['keywords'] ?? [];
            if (!empty($keywords)) {
                $matchCount = 0;
                foreach ($keywords as $kw) {
                    if (mb_strpos($text, mb_strtolower($kw)) !== false) {
                        $matchCount++;
                    }
                }
                if ($matchCount >= max(1, count($keywords) * 0.5)) {
                    $this->incrementKBUsage((int)($entry['id'] ?? 0));
                    return $entry['answer_template'];
                }
            }
        }
        return null;
    }

    private function incrementKBUsage(int $entryId): void
    {
        if ($entryId <= 0) return;
        try {
            $this->db->prepare("
                UPDATE ml_qa_knowledge_base
                SET usage_count = usage_count + 1, last_used = NOW()
                WHERE id = :id
            ")->execute(['id' => $entryId]);
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::incrementKBUsage error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            // non-critical
        }
    }

    // ─── Question analysis helpers ──────────────────────────────────────

    private function analyzeQuestionWithRules(array $question): array
    {
        $text = mb_strtolower($question['question_text'] ?? $question['text'] ?? '');

        // Category detection
        $categoryMap = [
            'envio|frete|entrega|correio|transportadora' => 'shipping',
            'garantia|troca|devol'                        => 'warranty',
            'estoque|disponível|quantidade|tem disponível' => 'stock',
            'preço|desconto|parcel|valor|custo'           => 'pricing',
            'medida|tamanho|dimensão|peso'                => 'specifications',
            'funciona|compatível|serve|encaixa'           => 'compatibility',
            'nota fiscal|nfe|cupom fiscal'                => 'invoice',
        ];
        $category = 'general';
        foreach ($categoryMap as $pattern => $cat) {
            if (preg_match('/(' . $pattern . ')/iu', $text)) {
                $category = $cat;
                break;
            }
        }

        // Sentiment detection
        $negWords = ['problema', 'defeito', 'quebr', 'péssim', 'horrível', 'ruim', 'reclamação', 'insatisf'];
        $posWords = ['ótimo', 'excelent', 'parabeniz', 'obrigad', 'recomend'];
        $sentiment = 'neutral';
        foreach ($negWords as $w) {
            if (mb_strpos($text, $w) !== false) {
                $sentiment = 'negative';
                break;
            }
        }
        if ($sentiment === 'neutral') {
            foreach ($posWords as $w) {
                if (mb_strpos($text, $w) !== false) {
                    $sentiment = 'positive';
                    break;
                }
            }
        }

        // Urgency detection
        $urgentWords = ['urgent', 'rápid', 'hoje', 'agora', 'imediato'];
        $urgency = 'normal';
        foreach ($urgentWords as $w) {
            if (mb_strpos($text, $w) !== false) {
                $urgency = 'high';
                break;
            }
        }

        // Keywords extraction
        $words = array_filter(preg_split('/\s+/', $text), fn(string $w): bool => mb_strlen($w) > 3);

        return [
            'category'   => $category,
            'sentiment'  => $sentiment,
            'urgency'    => $urgency,
            'complexity' => mb_strlen($text) > 200 ? 'high' : (mb_strlen($text) > 50 ? 'medium' : 'low'),
            'keywords'   => array_values(array_unique(array_slice($words, 0, 10))),
            'intent'     => str_contains($text, '?') ? 'question' : 'statement'
        ];
    }

    private function requiresInventoryCheck(array $question): bool
    {
        $text = mb_strtolower($question['question_text'] ?? $question['text'] ?? '');
        return (bool)preg_match('/(estoque|disponível|quantidade|tem.*para|unidade)/iu', $text);
    }

    private function requiresShippingInfo(array $question): bool
    {
        $text = mb_strtolower($question['question_text'] ?? $question['text'] ?? '');
        return (bool)preg_match('/(frete|envio|entrega|prazo|transporta|correio|cep)/iu', $text);
    }

    private function shouldEscalate(array $question, array $analysis): bool
    {
        $rules = $this->config['escalation_rules'];

        if ($rules['escalate_negative_sentiment'] && ($analysis['sentiment'] ?? '') === 'negative') {
            return true;
        }
        if ($rules['escalate_complex_questions'] && ($analysis['complexity'] ?? '') === 'high') {
            return true;
        }

        $text = mb_strtolower($question['question_text'] ?? $question['text'] ?? '');
        foreach ($rules['escalation_keywords'] as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    private function calculateAnswerConfidence(array $question, string $answer): float
    {
        $score = 0.5;
        if (mb_strlen($answer) > 50) $score += 0.1;
        if (mb_strlen($answer) > 150) $score += 0.1;

        $qText = mb_strtolower($question['question_text'] ?? $question['text'] ?? '');
        $aText = mb_strtolower($answer);

        // Keyword overlap boosts confidence
        $qWords = array_filter(preg_split('/\s+/', $qText), fn(string $w): bool => mb_strlen($w) > 3);
        $matches = 0;
        foreach ($qWords as $w) {
            if (mb_strpos($aText, $w) !== false) $matches++;
        }
        if (count($qWords) > 0) {
            $score += min(0.2, ($matches / count($qWords)) * 0.2);
        }

        return round(min(0.95, $score), 2);
    }

    private function calculateQuestionPriority(array $question, array $analysis): string
    {
        $s = 0;
        if (($analysis['sentiment'] ?? '') === 'negative') $s += 3;
        if (($analysis['urgency'] ?? '') === 'high') $s += 3;
        if (($analysis['complexity'] ?? '') === 'high') $s += 1;

        // Older unanswered = higher priority
        $created = strtotime($question['date_created'] ?? 'now');
        $hoursOld = (time() - $created) / 3600;
        if ($hoursOld > 12) $s += 2;
        elseif ($hoursOld > 4) $s += 1;

        if ($s >= 5) return 'critical';
        if ($s >= 3) return 'high';
        if ($s >= 1) return 'medium';
        return 'low';
    }

    // ─── Answer generation helpers ──────────────────────────────────────

    private function personalizeAnswer(string $answer, array $question): string
    {
        $productTitle = $question['item_title'] ?? $question['title'] ?? '';
        $answer = str_replace('{produto}', $productTitle, $answer);
        $answer = str_replace('{product}', $productTitle, $answer);

        // Greeting
        $hour = (int)date('G');
        $greeting = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
        $answer = str_replace('{saudacao}', $greeting, $answer);

        return $answer;
    }

    private function getTemplateAnswer(array $analysis): ?string
    {
        $templates = [
            'shipping'       => 'Olá! O envio é feito via {saudacao}. O prazo de entrega varia conforme sua região. Após a compra, você receberá o código de rastreio. Qualquer dúvida, estamos à disposição!',
            'stock'          => 'Olá! Sim, temos o produto {produto} disponível em estoque. Pode comprar com segurança!',
            'warranty'       => 'Olá! O produto {produto} possui garantia conforme descrito no anúncio. Em caso de problemas, entre em contato para resolvermos juntos.',
            'pricing'        => 'Olá! O preço informado no anúncio é o valor final. Aceitamos parcelamento conforme opções disponíveis na plataforma.',
            'specifications' => 'Olá! As especificações completas do produto {produto} estão na descrição do anúncio. Se precisar de alguma informação adicional, é só perguntar!',
            'compatibility'  => 'Olá! Para verificar a compatibilidade, por favor confira as especificações na descrição do anúncio. Se tiver dúvida específica, nos informe o modelo do seu equipamento.',
            'invoice'        => 'Olá! Sim, emitimos nota fiscal para todos os produtos. A NF-e será enviada por e-mail após a confirmação do pagamento.',
        ];

        $cat = $analysis['category'] ?? 'general';
        return $templates[$cat] ?? null;
    }

    // ─── Proactive Q&A generation ───────────────────────────────────────

    private function getQuestionTemplates(string $categoryId): array
    {
        return [
            ['type' => 'specifications', 'template' => 'Quais são as dimensões exatas do {produto}?'],
            ['type' => 'compatibility', 'template'  => 'O {produto} é compatível com quais modelos?'],
            ['type' => 'warranty', 'template'        => 'Qual o prazo de garantia do {produto}?'],
            ['type' => 'shipping', 'template'        => 'Qual o prazo de entrega para minha região?'],
            ['type' => 'stock', 'template'           => 'O {produto} está disponível para pronta entrega?'],
        ];
    }

    private function generateQuestionFromTemplate(array $template, array $productInfo): array
    {
        $question = str_replace('{produto}', $productInfo['title'] ?? 'produto', $template['template'] ?? '');
        $answer = $this->getTemplateAnswer(['category' => $template['type']]);
        if ($answer) {
            $answer = str_replace('{produto}', $productInfo['title'] ?? 'produto', $answer);
        }

        return [
            'question' => $question,
            'answer'   => $answer ?? '',
            'type'     => $template['type'] ?? 'general',
            'auto_generated' => true
        ];
    }

    private function generateCompetitorQuestions(array $productInfo): array
    {
        $title = $productInfo['title'] ?? '';
        return [
            [
                'question' => "Qual o diferencial do {$title} em relação a outros similares?",
                'answer'   => "Nosso produto se destaca pela qualidade e pelo ótimo custo-benefício. Confira a descrição completa do anúncio!",
                'type'     => 'differentiation',
                'auto_generated' => true
            ]
        ];
    }

    private function generateShippingQuestion(array $productInfo): array
    {
        $title = $productInfo['title'] ?? 'produto';
        return [
            'question' => "Como funciona o envio do {$title}?",
            'answer'   => "O envio é feito de forma segura e rastreável. Após a confirmação do pagamento, o produto é despachado em até 24h úteis.",
            'type'     => 'shipping',
            'auto_generated' => true
        ];
    }

    private function generateWarrantyQuestion(array $productInfo): array
    {
        $title = $productInfo['title'] ?? 'produto';
        return [
            'question' => "O {$title} possui garantia?",
            'answer'   => "Sim! O produto possui garantia conforme descrito no anúncio. Em caso de qualquer problema, entre em contato para resolvermos.",
            'type'     => 'warranty',
            'auto_generated' => true
        ];
    }

    // ─── Proactive QA persistence ───────────────────────────────────────

    private function saveProactiveQA(array $proactiveQA): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ml_qa_proactive (item_id, question_text, question_type, status)
                VALUES (:item_id, :question, :type, 'draft')
            ");
            foreach ($proactiveQA as $entry) {
                foreach ($entry['questions'] ?? [] as $q) {
                    $stmt->execute([
                        'item_id'  => $entry['product_id'],
                        'question' => $q['question'] ?? '',
                        'type'     => $q['type'] ?? 'general'
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::saveProactiveQA error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            // table may not exist yet
        }
    }

    private function estimateQAImpact(array $proactiveQA): array
    {
        $totalQuestions = 0;
        foreach ($proactiveQA as $entry) {
            $totalQuestions += count($entry['questions'] ?? []);
        }

        return [
            'total_proactive_questions' => $totalQuestions,
            'estimated_reduction_pct'   => min(40, $totalQuestions * 2),
            'estimated_conversion_lift' => round($totalQuestions * 0.005, 3),
            'coverage_improvement'      => round(min(95, 60 + $totalQuestions * 1.5), 1)
        ];
    }

    // ─── Batch processing ───────────────────────────────────────────────

    private function optimizeBatchResponses(array $results): array
    {
        $optimizations = [];

        // Group by category for consistent answers
        $byCategory = [];
        foreach ($results as $r) {
            $cat = $r['category'] ?? 'general';
            $byCategory[$cat][] = $r;
        }

        foreach ($byCategory as $cat => $group) {
            if (count($group) >= 3) {
                $template = $this->getTemplateAnswer(['category' => $cat]);
                if ($template) {
                    $optimizations[] = [
                        'type' => 'template_standardization',
                        'category' => $cat,
                        'affected_questions' => count($group),
                        'template_used' => $template
                    ];
                }
            }
        }

        // Flag duplicate questions
        $seen = [];
        foreach ($results as $r) {
            $normalized = mb_strtolower(trim($r['question_text'] ?? ''));
            if (isset($seen[$normalized])) {
                $optimizations[] = [
                    'type' => 'duplicate_detected',
                    'question_id' => $r['question_id'] ?? '',
                    'original_id' => $seen[$normalized]
                ];
            } else {
                $seen[$normalized] = $r['question_id'] ?? '';
            }
        }

        return $optimizations;
    }

    // ─── Logging ────────────────────────────────────────────────────────

    private function logAutoResponse(string $questionId, string $answer): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE ml_questions
                SET answer_text = :answer, status = 'ANSWERED',
                    ai_draft = :answer, confidence_score = 0.85
                WHERE question_id = :question_id AND account_id = :account_id
            ");
            $stmt->execute([
                'answer'      => $answer,
                'question_id' => $questionId,
                'account_id'  => $this->accountId
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::logAutoResponse error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            // non-critical
        }
    }

    // ─── AI prompts ─────────────────────────────────────────────────────

    private function buildAnalysisPrompt(array $question, array $productInfo): string
    {
        $qText   = $question['question_text'] ?? $question['text'] ?? '';
        $pTitle  = $productInfo['title'] ?? 'Produto';
        $pPrice  = $productInfo['price'] ?? '0';
        $pCat    = $productInfo['category_id'] ?? '';

        return "Analise a seguinte pergunta de um comprador sobre o produto \"{$pTitle}\" "
            . "(categoria: {$pCat}, preço: R\${$pPrice}).\n\n"
            . "Pergunta: \"{$qText}\"\n\n"
            . "Retorne um JSON com os campos: category (shipping|warranty|stock|pricing|specifications|compatibility|invoice|general), "
            . "sentiment (positive|negative|neutral), urgency (high|normal|low), "
            . "complexity (high|medium|low), keywords (array de strings), intent (question|complaint|request|statement).";
    }

    private function buildAnswerPrompt(array $question, array $analysis): string
    {
        $qText     = $question['question_text'] ?? $question['text'] ?? '';
        $category  = $analysis['category'] ?? 'general';
        $sentiment = $analysis['sentiment'] ?? 'neutral';
        $product   = $analysis['product_context'] ?? [];
        $pTitle    = $product['title'] ?? 'o produto';

        return "Gere uma resposta profissional e amigável para a seguinte pergunta sobre \"{$pTitle}\" "
            . "no Mercado Livre.\n\n"
            . "Categoria da pergunta: {$category}\n"
            . "Sentimento: {$sentiment}\n"
            . "Pergunta: \"{$qText}\"\n\n"
            . "Regras: seja cordial, use no máximo 300 caracteres, não prometa o que não pode cumprir, "
            . "use português brasileiro formal.";
    }

    // ─── Analytics helpers ──────────────────────────────────────────────

    private function getQAOverview(array $filters): array
    {
        try {
            $days = match ($filters['period'] ?? 'last_30_days') {
                'last_7_days' => 7,
                'last_90_days' => 90,
                default => 30
            };
            $since = date('Y-m-d', strtotime("-{$days} days"));

            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) AS total_questions,
                    SUM(CASE WHEN status = 'ANSWERED' THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN status = 'UNANSWERED' THEN 1 ELSE 0 END) AS unanswered,
                    SUM(CASE WHEN ai_draft IS NOT NULL THEN 1 ELSE 0 END) AS auto_answered,
                    AVG(confidence_score) AS avg_confidence
                FROM ml_questions
                WHERE account_id = :account_id AND date_created >= :since
            ");
            $stmt->execute(['account_id' => $this->accountId, 'since' => $since]);
            $overview = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $overview['period'] = $filters['period'] ?? 'last_30_days';
            $total = (int)($overview['total_questions'] ?? 0);
            $overview['answer_rate'] = $total > 0
                ? round((int)($overview['answered'] ?? 0) / $total * 100, 1) : 0;
            return $overview;
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getQAOverview error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return ['error' => $e->getMessage()];
        }
    }

    private function getResponsePerformanceAnalytics(array $filters): array
    {
        try {
            $days = match ($filters['period'] ?? 'last_30_days') {
                'last_7_days' => 7,
                'last_90_days' => 90,
                default => 30
            };
            $since = date('Y-m-d', strtotime("-{$days} days"));

            $stmt = $this->db->prepare("
                SELECT
                    DATE(date_created) AS day,
                    COUNT(*) AS questions,
                    SUM(CASE WHEN status = 'ANSWERED' THEN 1 ELSE 0 END) AS answered
                FROM ml_questions
                WHERE account_id = :account_id AND date_created >= :since
                GROUP BY day
                ORDER BY day ASC
            ");
            $stmt->execute(['account_id' => $this->accountId, 'since' => $since]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getResponsePerformanceAnalytics error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    private function getQuestionTrends(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT intent, sentiment, COUNT(*) AS count
                FROM ml_questions
                WHERE account_id = :account_id
                  AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY intent, sentiment
                ORDER BY count DESC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getQuestionTrends error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    private function getAutoResponseEffectiveness(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN ai_draft IS NOT NULL THEN 1 ELSE 0 END) AS auto_generated,
                    AVG(CASE WHEN ai_draft IS NOT NULL THEN confidence_score ELSE NULL END) AS avg_confidence
                FROM ml_questions
                WHERE account_id = :account_id
                  AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $total = (int)($data['total'] ?? 0);
            $auto  = (int)($data['auto_generated'] ?? 0);
            $data['auto_response_rate'] = $total > 0 ? round($auto / $total * 100, 1) : 0;
            return $data;
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getAutoResponseEffectiveness error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    private function getEscalationPatterns(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT sentiment, urgency, COUNT(*) AS count
                FROM ml_questions
                WHERE account_id = :account_id
                  AND (sentiment = 'negative' OR urgency = 'high')
                  AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY sentiment, urgency
                ORDER BY count DESC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getEscalationPatterns error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    private function getKnowledgeBasePerformance(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT category, SUM(usage_count) AS total_uses,
                       SUM(success_count) AS total_success,
                       COUNT(*) AS entries
                FROM ml_qa_knowledge_base
                GROUP BY category
                ORDER BY total_uses DESC
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['success_rate'] = (int)($r['total_uses'] ?? 0) > 0
                    ? round((int)$r['total_success'] / (int)$r['total_uses'] * 100, 1) : 0;
            }
            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getKnowledgeBasePerformance error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    private function getProductQuestionHeatmap(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT q.item_id, i.title, COUNT(*) AS question_count,
                       SUM(CASE WHEN q.status = 'UNANSWERED' THEN 1 ELSE 0 END) AS unanswered
                FROM ml_questions q
                LEFT JOIN ml_items i ON i.id = q.item_id
                WHERE q.account_id = :account_id
                  AND q.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY q.item_id
                ORDER BY question_count DESC
                LIMIT 20
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getProductQuestionHeatmap error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    private function getTimeToAnswerAnalysis(array $filters): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    AVG(TIMESTAMPDIFF(MINUTE, date_created,
                        COALESCE(
                            (SELECT MIN(q2.date_created) FROM ml_questions q2
                             WHERE q2.question_id = q.question_id AND q2.answer_text IS NOT NULL),
                            NOW()
                        )
                    )) AS avg_response_minutes
                FROM ml_questions q
                WHERE q.account_id = :account_id
                  AND q.status = 'ANSWERED'
                  AND q.date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $data = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $avgMin = (float)($data['avg_response_minutes'] ?? 0);
            return [
                'avg_response_minutes' => round($avgMin, 1),
                'avg_response_hours' => round($avgMin / 60, 1),
                'performance_grade' => $avgMin < 60 ? 'excellent' : ($avgMin < 240 ? 'good' : ($avgMin < 720 ? 'average' : 'needs_improvement'))
            ];
        } catch (\Exception $e) {
            $this->logger->warning('SmartQAService::getTimeToAnswerAnalysis error', ['error' => $e->getMessage(), 'account_id' => $this->accountId]);
            return [];
        }
    }

    // ─── Summary ────────────────────────────────────────────────────────

    private function generateProcessingSummary(array $results): array
    {
        $auto   = count(array_filter($results, fn(array $r): bool => $r['auto_respond'] ?? false));
        $escal  = count(array_filter($results, fn(array $r): bool => $r['escalate'] ?? false));
        $total  = count($results);
        $avgConf = $total > 0
            ? round(array_sum(array_column($results, 'confidence')) / $total, 2) : 0;

        $bySentiment = ['positive' => 0, 'negative' => 0, 'neutral' => 0];
        foreach ($results as $r) {
            $s = $r['sentiment'] ?? 'neutral';
            if (isset($bySentiment[$s])) $bySentiment[$s]++;
        }

        return [
            'total_processed' => $total,
            'auto_answered' => $auto,
            'escalated' => $escal,
            'manual_review' => $total - $auto - $escal,
            'avg_confidence' => $avgConf,
            'sentiment_distribution' => $bySentiment,
            'processed_at' => date('Y-m-d H:i:s')
        ];
    }
}
