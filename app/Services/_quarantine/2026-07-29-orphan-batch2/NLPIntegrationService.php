<?php

declare(strict_types=1);

namespace App\Services\AI\ML;

use JsonException;
use Monolog\Logger;

/**
 * Serviço de integração com o microserviço Python (FastAPI) de NLP.
 * Quando o serviço remoto está indisponível, usa um classificador heurístico local
 * para manter a triagem operacional no webhook do Mercado Livre.
 */
class NLPIntegrationService
{
    private const REMOTE_HEALTH_PATH = '/health';
    private const REMOTE_PREDICT_PATH = '/api/v1/predict';

    /**
     * @var array<string, array<int, string>>
     */
    private const INTENT_PATTERNS = [
        'reclamacao_critica' => [
            'quebrado',
            'quebrada',
            'defeito',
            'defeituoso',
            'nao funciona',
            'não funciona',
            'procon',
            'reembolso',
            'dinheiro de volta',
            'devolver',
            'devolucao',
            'devolução',
            'reclamacao',
            'reclamação',
            'danificado',
            'avariado',
        ],
        'compatibilidade' => [
            'serve na',
            'serve no',
            'da certo',
            'dá certo',
            'compativel',
            'compatível',
            'compatibilidade',
            'cabe na',
            'cabe no',
            'aplica na',
            'funciona na',
            'cg 160',
            'fan 160',
            'bros 160',
            'factor 150',
        ],
        'logistica' => [
            'prazo',
            'entrega',
            'frete',
            'quando chega',
            'atrasado',
            'rastreio',
            'rastreamento',
            'envia hoje',
            'envio',
        ],
        'negociacao_preco' => [
            'desconto',
            'mais barato',
            'melhor preco',
            'melhor preço',
            'faz por',
            'ultimo preco',
            'último preço',
            'valor',
            'preco',
            'preço',
            'oferta',
        ],
    ];

    private string $baseUrl;
    private string $apiKey;
    private Logger $logger;
    private int $timeout;

    public function __construct(Logger $logger, string $baseUrl = 'http://127.0.0.1:8000', string $apiKey = 'dev-secret-key', int $timeout = 3)
    {
        $this->logger = $logger;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = max(1, $timeout);
    }

    /**
     * @return array{intent: string, urgency_score: float, confidence: float, is_critical: bool}
     */
    public function predictIntent(string $messageId, string $text, string $itemId, float $price): array
    {
        $payload = [
            'message_id' => $messageId,
            'text' => $text,
            'item_id' => $itemId,
            'price' => $price,
        ];

        $remotePrediction = $this->requestRemotePrediction($payload);
        if ($remotePrediction !== null) {
            return $remotePrediction;
        }

        $fallbackPrediction = $this->predictIntentLocally($text, $price);
        $this->logger->warning('NLP FastAPI indisponível; usando fallback heurístico local.', [
            'base_url' => $this->baseUrl,
            'message_id' => $messageId,
            'intent' => $fallbackPrediction['intent'],
            'urgency_score' => $fallbackPrediction['urgency_score'],
        ]);

        return $fallbackPrediction;
    }

    /**
     * Verifica se o motor NLP está operacional.
     * Considera saudável quando o FastAPI remoto responde ou o fallback local está pronto.
     */
    public function healthCheck(): bool
    {
        if ($this->checkRemoteHealth()) {
            return true;
        }

        $this->logger->warning('NLP FastAPI indisponível no health check; fallback local seguirá ativo.', [
            'base_url' => $this->baseUrl,
        ]);

        return $this->isLocalFallbackOperational();
    }

    /**
     * @param array<string, string|float> $payload
     * @return array{intent: string, urgency_score: float, confidence: float, is_critical: bool}|null
     */
    private function requestRemotePrediction(array $payload): ?array
    {
        try {
            $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->logger->error('Falha ao serializar payload para o NLP remoto.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        $ch = curl_init($this->baseUrl . self::REMOTE_PREDICT_PATH);
        if ($ch === false) {
            $this->logger->error('Falha ao inicializar cURL para o NLP remoto.', [
                'base_url' => $this->baseUrl,
            ]);

            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($response) || $httpCode !== 200) {
            $this->logger->warning('Falha na integração com o NLP remoto.', [
                'base_url' => $this->baseUrl,
                'http_code' => $httpCode,
                'curl_error' => $error !== '' ? $error : null,
            ]);

            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->logger->error('Resposta inválida do NLP remoto.', [
                'base_url' => $this->baseUrl,
                'response_excerpt' => substr($response, 0, 200),
            ]);

            return null;
        }

        return $this->normalizePrediction($data);
    }

    private function checkRemoteHealth(): bool
    {
        $ch = curl_init($this->baseUrl . self::REMOTE_HEALTH_PATH);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, min(2, $this->timeout)),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return is_string($response) && $httpCode === 200;
    }

    private function isLocalFallbackOperational(): bool
    {
        return self::INTENT_PATTERNS !== [];
    }

    /**
     * @return array{intent: string, urgency_score: float, confidence: float, is_critical: bool}
     */
    private function predictIntentLocally(string $text, float $price): array
    {
        $normalizedText = $this->normalizeText($text);
        $scores = [];

        foreach (self::INTENT_PATTERNS as $intent => $patterns) {
            $scores[$intent] = $this->countPatternMatches($normalizedText, $patterns);
        }

        if (($scores['negociacao_preco'] ?? 0) > 0 && $price >= 100.0) {
            $scores['negociacao_preco']++;
        }

        $bestIntent = 'duvida_geral';
        $bestScore = 0;

        foreach ($scores as $intent => $score) {
            if ($score > $bestScore) {
                $bestIntent = $intent;
                $bestScore = $score;
            }
        }

        $confidence = $this->resolveConfidence($bestIntent, $bestScore);
        $urgencyScore = $this->resolveUrgencyScore($bestIntent, $bestScore, $normalizedText);

        return [
            'intent' => $bestIntent,
            'urgency_score' => $urgencyScore,
            'confidence' => $confidence,
            'is_critical' => $urgencyScore >= 0.8,
        ];
    }

    /**
     * @param array<int, string> $patterns
     */
    private function countPatternMatches(string $normalizedText, array $patterns): int
    {
        $matches = 0;

        foreach ($patterns as $pattern) {
            if (str_contains($normalizedText, $this->normalizeText($pattern))) {
                $matches++;
            }
        }

        return $matches;
    }

    private function normalizeText(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = strtr($normalized, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized) ?? $normalized;

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    private function resolveConfidence(string $intent, int $score): float
    {
        if ($intent === 'duvida_geral') {
            return 0.55;
        }

        $baseConfidence = match ($intent) {
            'reclamacao_critica' => 0.86,
            'compatibilidade' => 0.82,
            'logistica' => 0.74,
            'negociacao_preco' => 0.70,
            default => 0.55,
        };

        return min(0.99, round($baseConfidence + (min($score, 3) * 0.04), 4));
    }

    private function resolveUrgencyScore(string $intent, int $score, string $normalizedText): float
    {
        $scoreBoost = min($score, 3) * 0.03;

        return match ($intent) {
            'reclamacao_critica' => min(
                0.99,
                round(0.84 + $scoreBoost + ($this->containsEscalationTerms($normalizedText) ? 0.06 : 0.0), 4)
            ),
            'logistica' => min(0.65, round(0.35 + $scoreBoost, 4)),
            'negociacao_preco' => min(0.45, round(0.22 + $scoreBoost, 4)),
            'compatibilidade' => min(0.30, round(0.12 + $scoreBoost, 4)),
            default => 0.10,
        };
    }

    private function containsEscalationTerms(string $normalizedText): bool
    {
        foreach (['procon', 'urgente', 'processo', 'advogado'] as $term) {
            if (str_contains($normalizedText, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{intent: string, urgency_score: float, confidence: float, is_critical: bool}
     */
    private function normalizePrediction(array $data): array
    {
        $urgencyScore = max(0.0, min(1.0, (float) ($data['urgency_score'] ?? 0.0)));

        return [
            'intent' => (string) ($data['intent'] ?? 'duvida_geral'),
            'urgency_score' => $urgencyScore,
            'confidence' => max(0.0, min(1.0, (float) ($data['confidence'] ?? 0.0))),
            'is_critical' => isset($data['is_critical'])
                ? (bool) $data['is_critical']
                : $urgencyScore >= 0.8,
        ];
    }
}
