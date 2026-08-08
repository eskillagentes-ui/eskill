<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use PDO;

/**
 * AI Chatbot Service - Conversational Assistant
 *
 * Assistente conversacional inteligente para responder perguntas sobre:
 * - Métricas e dados do dashboard
 * - Como usar funcionalidades
 * - Interpretação de resultados
 * - Sugestões personalizadas
 * - Troubleshooting
 *
 * @package App\Services\AI\SEO
 * @version 2.0.0
 * @since 2025-12-31
 */
class AIChatbotService
{
    private PDO $db;
    private int $accountId;
    private string $apiKey;
    private string $apiEndpoint = 'https://api.openai.com/v1/chat/completions';
    private array $conversationHistory = [];

    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? '';

        if (empty($this->apiKey)) {
            // Try to load .env one last time if not found
            if (file_exists(__DIR__ . '/../../../../.env')) {
                try {
                    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../../../../');
                    $dotenv->safeLoad();
                    $this->apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?? '';
                } catch (\Throwable $e) {
                    // Ignore dotenv errors
                }
            }
        }
    }

    /**
     * Envia mensagem ao chatbot e recebe resposta
     *
     * @param string $message Mensagem do usuário
     * @param array $context Contexto adicional
     * @return array Resposta do chatbot
     */
    public function chat(string $message, array $context = []): array
    {
        try {
            // Adicionar mensagem ao histórico
            $this->conversationHistory[] = [
                'role' => 'user',
                'content' => $message
            ];

            // Preparar contexto
            $systemContext = $this->buildSystemContext($context);

            // Montar mensagens
            $messages = array_merge(
                [['role' => 'system', 'content' => $systemContext]],
                $this->conversationHistory
            );

            // Chamar GPT-4 (ou fallback offline)
            $response = $this->callGPT4($messages);

            // Adicionar resposta ao histórico
            $this->conversationHistory[] = [
                'role' => 'assistant',
                'content' => $response
            ];

            // Salvar conversa (falhas aqui não devem quebrar a resposta)
            $this->saveConversation($message, $response);

            return [
                'message' => $response,
                'timestamp' => date('Y-m-d H:i:s'),
                'conversation_id' => $this->getConversationId(),
                'suggested_actions' => $this->extractSuggestedActions($response)
            ];
        } catch (\Throwable $e) {
            // Qualquer erro deve cair em modo offline, sem quebrar o endpoint
            log_warning('AIChatbotService: erro no chat, fallback offline', [
                'service' => 'AIChatbotService',
                'error' => $e->getMessage(),
            ]);

            $fallback = $this->getFallbackResponse([], 'unavailable');

            return [
                'message' => $fallback,
                'timestamp' => date('Y-m-d H:i:s'),
                'conversation_id' => $this->getConversationId(),
                'suggested_actions' => []
            ];
        }
    }

    /**
     * Responde pergunta sobre métrica específica
     *
     * @param string $metric Nome da métrica
     * @param mixed $value Valor atual
     * @return array Explicação detalhada
     */
    public function explainMetric(string $metric, $value): array
    {
        $question = "What does $metric mean and is $value good?";
        return $this->chat($question, ['type' => 'metric_explanation']);
    }

    /**
     * Ajuda com funcionalidade específica
     *
     * @param string $feature Nome da feature
     * @return array Guia de uso
     */
    public function helpWithFeature(string $feature): array
    {
        $question = "How do I use the $feature feature?";
        return $this->chat($question, ['type' => 'feature_help']);
    }

    /**
     * Sugestão de próximas ações
     *
     * @return array Ações recomendadas
     */
    public function suggestNextActions(): array
    {
        $state = $this->getAccountState();
        $actions = $this->rankSuggestedActions($state);

        return [
            'message' => $this->buildSuggestedActionsMessage($state),
            'timestamp' => date('Y-m-d H:i:s'),
            'conversation_id' => null,
            'suggested_actions' => array_column($actions, 'label'),
            'suggested_actions_meta' => $actions,
        ];
    }

    /**
     * Limpa histórico da conversa
     */
    public function clearHistory(): void
    {
        $this->conversationHistory = [];
    }

    private function getFallbackResponse(array $messages, string $reason = 'unavailable'): string
    {
        return match ($reason) {
            'quota' => 'O assistente de IA está temporariamente indisponível: a conta OpenAI ficou sem créditos (HTTP 429). '
                . 'Recarregue o saldo em platform.openai.com/settings/organization/billing e tente novamente. '
                . 'Enquanto isso, use o diagnóstico e as ferramentas do SEO Killer para ações básicas.',
            'missing_key' => 'O assistente de IA não está configurado: falta OPENAI_API_KEY no ambiente. '
                . 'Configure a chave e tente novamente.',
            'network' => 'Não foi possível conectar à API da OpenAI agora. Tente novamente em instantes.',
            default => 'O assistente de IA está temporariamente indisponível. '
                . 'Tente novamente em instantes ou use as ferramentas do SEO Killer enquanto isso.',
        };
    }

    // ==================== MÉTODOS PRIVADOS ====================

    private function buildSystemContext(array $context): string
    {
        $accountData = $this->getAccountData();

        return "You are an expert assistant for a Mercado Livre SEO optimization platform. " .
               "You help users understand their metrics, use features, and improve their listings. " .
               "Be concise, practical, and always provide actionable advice. " .
               "Respond always in Brazilian Portuguese. " .
               "Current account has {$accountData['total_items']} items with average score of {$accountData['avg_score']}.";
    }

    private function callGPT4(array $messages): string
    {
        // Verificar se API key está disponível
        if (empty($this->apiKey)) {
            return $this->getFallbackResponse($messages, 'missing_key');
        }

        $data = [
            'model' => 'gpt-4o',
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 500
        ];

        $ch = curl_init($this->apiEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($response === false) {
                    $error = curl_error($ch);
                    curl_close($ch);
                    log_error('Chatbot GPT-4: erro de rede', [
                        'service' => 'AIChatbotService',
                        'http_code' => $httpCode,
                        'error' => $error,
                    ]);
                    return $this->getFallbackResponse($messages, 'network');
                }

                curl_close($ch);

                if ($httpCode !== 200) {
                    log_error('Chatbot GPT-4: erro na API', [
                        'service' => 'AIChatbotService',
                        'http_code' => $httpCode,
                        'response_preview' => substr((string)$response, 0, 300),
                    ]);
                    $reason = 'unavailable';
                    if ($httpCode === 429 || str_contains((string) $response, 'insufficient_quota') || str_contains((string) $response, 'credit_balance_exhausted')) {
                        $reason = 'quota';
                    }
                    return $this->getFallbackResponse($messages, $reason);
                }

                $decoded = json_decode($response, true);
                if (!isset($decoded['choices'][0]['message']['content'])) {
                    log_warning('Chatbot GPT-4: resposta inválida', [
                        'service' => 'AIChatbotService',
                        'response_preview' => substr((string)$response, 0, 500),
                    ]);
                    return $this->getFallbackResponse($messages);
                }

                return $decoded['choices'][0]['message']['content'];
    }

    private function saveConversation(string $message, string $response): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO chatbot_conversations (account_id, conversation_id, user_message, bot_response, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$this->accountId, $this->getConversationId(), $message, $response]);
        } catch (\Exception $e) {
            // Log error but don't fail the response
            log_warning('Falha ao salvar conversa do chatbot', [
                'service' => 'AIChatbotService',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getConversationId(): string
    {
        return 'conv_' . $this->accountId . '_' . time();
    }

    private function extractSuggestedActions(string $response): array
    {
        // Extrair ações da resposta
        preg_match_all('/\d+\.\s+(.+?)(?:\n|$)/s', $response, $matches);
        return array_slice($matches[1] ?? [], 0, 3);
    }

    private function getAccountData(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(DISTINCT item_id) as total_items,
                    AVG(score_after) as avg_score
                FROM seo_optimizations
                WHERE account_id = ?
            ");
            $stmt->execute([$this->accountId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_items' => 0, 'avg_score' => 0];
        } catch (\Throwable $e) {
            // Se a tabela ainda não existir ou houver erro de schema, não devemos quebrar o chatbot
            log_warning('AIChatbotService: erro ao buscar dados da conta', [
                'service' => 'AIChatbotService',
                'error' => $e->getMessage(),
            ]);
            return ['total_items' => 0, 'avg_score' => 0];
        }
    }

    private function getAccountState(): array
    {
        $state = [
            'total_items' => 0,
            'optimized_items' => 0,
            'pending_items' => 0,
            'optimizations_count' => 0,
            'avg_score' => 0.0,
            'avg_improvement' => 0.0,
            'low_score_items' => 0,
            'recent_optimizations' => 0,
            'items_needing_attention' => 0,
            'last_optimization_at' => null,
            'critical_items' => [], // Add field for specific items
        ];

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM ml_items WHERE account_id = ?");
            $stmt->execute([$this->accountId]);
            $state['total_items'] = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            log_warning('AIChatbotService: erro ao buscar ml_items', [
                'service' => 'AIChatbotService',
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as optimizations_count,
                    COUNT(DISTINCT item_id) as optimized_items,
                    AVG(score_after) as avg_score,
                    AVG(score_improvement) as avg_improvement,
                    SUM(CASE WHEN score_after < 60 THEN 1 ELSE 0 END) as low_score_items,
                    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as recent_optimizations,
                    MAX(COALESCE(applied_at, created_at)) as last_optimization_at
                FROM seo_optimizations
                WHERE account_id = ?
            ");
            $stmt->execute([$this->accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $state['optimizations_count'] = (int)($row['optimizations_count'] ?? 0);
            $state['optimized_items'] = (int)($row['optimized_items'] ?? 0);
            $state['avg_score'] = (float)($row['avg_score'] ?? 0.0);
            $state['avg_improvement'] = (float)($row['avg_improvement'] ?? 0.0);
            $state['low_score_items'] = (int)($row['low_score_items'] ?? 0);
            $state['recent_optimizations'] = (int)($row['recent_optimizations'] ?? 0);
            $state['last_optimization_at'] = $row['last_optimization_at'] ?? null;
        } catch (\Throwable $e) {
            log_warning('AIChatbotService: erro ao buscar seo_optimizations', [
                'service' => 'AIChatbotService',
                'error' => $e->getMessage(),
            ]);
        }

        // Fetch critical items
        $state['critical_items'] = $this->getCriticalItems(3);

        $state['pending_items'] = max(0, $state['total_items'] - $state['optimized_items']);
        $state['items_needing_attention'] = max($state['pending_items'], $state['low_score_items']);

        return $state;
    }

    private function getCriticalItems(int $limit): array
    {
        try {
            $limitSql = max(1, min(50, (int)$limit));
            $stmt = $this->db->prepare("
                SELECT
                    i.id, i.title, i.price, i.sold_quantity, i.thumbnail,
                    COALESCE(o.score_after, 0) as score
                FROM ml_items i
                LEFT JOIN seo_optimizations o ON i.id = o.item_id
                WHERE i.account_id = ?
                AND i.status = 'active'
                AND (o.score_after IS NULL OR o.score_after < 70)
                ORDER BY i.sold_quantity DESC, i.price DESC
                LIMIT {$limitSql}
            ");
            $stmt->bindValue(1, $this->accountId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Silently fail if table doesn't exist
            return [];
        }
    }

    private function rankSuggestedActions(array $state): array
    {
        $actions = [];
        $add = function (string $label, string $reason = '', float $impact = 0.5, string $category = 'general', ?string $itemId = null) use (&$actions) {
            $actions[] = [
                'label' => $label,
                'reason' => $reason,
                'impact' => round(max(0.1, min($impact, 1.0)), 2),
                'category' => $category,
                'item_id' => $itemId,
            ];
        };

        $pending = (int)($state['pending_items'] ?? 0);
        $lowScore = (int)($state['low_score_items'] ?? 0);
        $avgScore = (float)($state['avg_score'] ?? 0.0);
        $avgImprovement = (float)($state['avg_improvement'] ?? 0.0);
        $recent = (int)($state['recent_optimizations'] ?? 0);
        $optimizationsCount = (int)($state['optimizations_count'] ?? 0);

        // 1. Critical Items (Highest Priority)
        foreach ($state['critical_items'] ?? [] as $item) {
            $score = (float)$item['score'];
            $title = mb_strimwidth($item['title'] ?? 'Item', 0, 25, '...');
            $sales = (int)($item['sold_quantity'] ?? 0);

            if ($score < 60) {
                $add(
                    "Corrigir item crítico: '{$title}'",
                    sprintf('Item com %d vendas mas score baixo (%d). Otimize agora para não perder ranking.', $sales, $score),
                    0.99,
                    'optimize_item',
                    isset($item['id']) ? (string) $item['id'] : null
                );
            } else {
                $add(
                    "Refinar item: '{$title}'",
                    sprintf('Aumente o score atual (%d) para maximizar a conversão.', $score),
                    0.88,
                    'optimize_item',
                    isset($item['id']) ? (string) $item['id'] : null
                );
            }
        }

        if ($pending > 0) {
            $add(
                'Rodar diagnóstico completo do SEO Killer',
                sprintf('%d itens ainda não têm otimização registrada; priorize saúde geral.', $pending),
                0.96,
                'health'
            );
        }

        if ($pending > 5) {
            $add(
                'Executar otimização em lote (bulk)',
                'Ganhe escala aplicando ajustes rápidos nos itens pendentes com score mais baixo.',
                0.9,
                'bulk'
            );
        }

        if ($lowScore > 0) {
            $add(
                'Reforçar atributos e mídia nos itens com score < 60',
                sprintf('%d itens ainda estão abaixo de 60 de score; foque em atributos, imagens e frete.', $lowScore),
                0.92,
                'quality'
            );
        }

        if ($avgScore > 0 && $avgScore < 75) {
            $add(
                'Gerar novos títulos otimizados',
                sprintf('Score médio em %.1f; títulos curtos e claros elevam CTR.', $avgScore),
                0.82,
                'title'
            );
        }

        if ($avgScore > 0 && $avgScore < 82) {
            $add(
                'Melhorar descrições com estrutura clara',
                'Use bullets, FAQs e diferenciais para elevar relevância e conversão.',
                0.78,
                'description'
            );
        }

        if ($avgImprovement > 0) {
            $add(
                'Replicar padrões das melhores otimizações',
                sprintf('Suas otimizações geram em média +%.1f pontos de score; replique o que funcionou.', $avgImprovement),
                0.7,
                'analysis'
            );
        }

        if ($recent > 0 || $optimizationsCount > 0) {
            $add(
                'Revisar impacto das últimas otimizações',
                'Valide se houve ganho de tráfego e conversão após as últimas alterações.',
                0.62,
                'monitoring'
            );
        }

        $add(
            'Revisar e ajustar o Autopilot do SEO Killer',
            'Garanta que as rotinas automáticas estão ligadas e priorizando itens críticos.',
            0.58,
            'autopilot'
        );

        $add(
            'Abrir AI Insights para novas oportunidades',
            'Busque ganhos rápidos com termos e categorias em alta.',
            0.45,
            'insights'
        );

        // Remover duplicados por label e ordenar por impacto
        $unique = [];
        foreach ($actions as $action) {
            $key = strtolower($action['label']);
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = $action;
        }

        $actions = array_values($unique);

        usort($actions, function ($a, $b) {
            return $b['impact'] <=> $a['impact'];
        });

        return array_slice($actions, 0, 6);
    }

    private function buildSuggestedActionsMessage(array $state): string
    {
        $lines = [];
        $lines[] = 'Aqui vão algumas próximas ações recomendadas para hoje:';

        if (($state['pending_items'] ?? 0) > 0) {
            $lines[] = sprintf('- %d itens ainda sem otimização registrada; rode diagnóstico e priorize os piores.', $state['pending_items']);
        }

        if (($state['low_score_items'] ?? 0) > 0) {
            $lines[] = sprintf('- %d itens abaixo de 60 de score; melhore atributos, imagens e frete.', $state['low_score_items']);
        }

        if (($state['avg_improvement'] ?? 0) > 0) {
            $lines[] = sprintf('- Suas otimizações geram em média +%.1f pontos de score; replique esse padrão nos itens pendentes.', $state['avg_improvement']);
        }

        if (!empty($state['last_optimization_at'])) {
            $lines[] = '- Revise as otimizações mais recentes para garantir que o ganho permaneça.';
        }

        if (count($lines) === 1) {
            $lines[] = 'Sua conta está estável; explore estratégias avançadas e mantenha a cadência de otimizações.';
        }

        return implode("\n", $lines);
    }
}
