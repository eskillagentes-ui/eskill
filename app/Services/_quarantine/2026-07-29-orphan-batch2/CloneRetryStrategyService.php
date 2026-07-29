<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Serviço de estratégia de retry para clonagem de catálogo.
 *
 * Define regras de retry baseadas no tipo de erro, implementa
 * backoff exponencial e gera relatórios de erros.
 */
class CloneRetryStrategyService
{
    private PDO $db;

    /**
     * Erros que NÃO devem ter retry (irrecuperáveis).
     */
    private const NON_RETRYABLE_CODES = ['400', '403', '404', '410', '422'];

    /**
     * Erros que devem ter retry com backoff.
     */
    private const RETRYABLE_CODES = ['429', '500', '502', '503', '504'];

    /**
     * Máximo de tentativas por tipo de erro.
     */
    private const MAX_ATTEMPTS = [
        '429' => 10,  // Rate limit - muitas tentativas com backoff
        '500' => 3,   // Server error - poucas tentativas
        '502' => 3,
        '503' => 5,   // Manutenção - tentativas moderadas
        '504' => 3,
        'timeout' => 5,
        'network' => 5,
        'default' => 3,
    ];

    /**
     * Delay base em segundos para backoff exponencial.
     */
    private const BASE_DELAY = 2;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Determina se uma operação deve ser retentada baseado no tipo de erro.
     *
     * @param string $errorCode Código de erro HTTP ou mensagem de erro
     * @param int    $attempt   Número da tentativa atual (0-indexed)
     * @return array{should_retry: bool, delay?: int, reason: string, final_status?: string, next_status?: string}
     */
    public function shouldRetry(string $errorCode, int $attempt): array
    {
        $httpCode = $this->extractHttpCode($errorCode);
        $errorType = $this->classifyError($errorCode, $httpCode);

        // Erros irrecuperáveis - não fazer retry
        if (in_array($httpCode, self::NON_RETRYABLE_CODES, true)) {
            return $this->buildNoRetryResponse($httpCode, $errorCode);
        }

        // Erros de rede/timeout
        if ($errorType === 'timeout' || $errorType === 'network') {
            return $this->buildRetryResponse($errorType, $attempt);
        }

        // Erros retryáveis (429, 5xx)
        if (in_array($httpCode, self::RETRYABLE_CODES, true)) {
            return $this->buildRetryResponse($httpCode, $attempt);
        }

        // Erro desconhecido - retry limitado
        return $this->buildRetryResponse('default', $attempt);
    }

    /**
     * Gera relatório de erros agrupados.
     *
     * @param string|null $filter Filtrar por código específico
     * @param int         $hours  Período em horas
     * @return array Lista de erros agrupados
     */
    public function getErrorReport(?string $filter = null, int $hours = 24): array
    {
        $sql = "
            SELECT
                error_code,
                COUNT(*) as error_count,
                MAX(created_at) as last_occurrence
            FROM catalog_clone_jobs
            WHERE status = 'failed'
              AND created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
        ";

        $params = ['hours' => $hours];

        if ($filter !== null) {
            $sql .= " AND error_code = :filter";
            $params['filter'] = $filter;
        }

        $sql .= " GROUP BY error_code ORDER BY error_count DESC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $rows = [];
        }

        return array_map(function (array $row) {
            $code = $row['error_code'] ?? 'unknown';
            $maxAttempts = self::MAX_ATTEMPTS[$code] ?? self::MAX_ATTEMPTS['default'];
            $retryEnabled = !in_array($code, self::NON_RETRYABLE_CODES, true);

            return [
                'error_code' => $code,
                'error_count' => (int) $row['error_count'],
                'last_occurrence' => $row['last_occurrence'],
                'retry_enabled' => $retryEnabled,
                'max_attempts' => $maxAttempts,
            ];
        }, $rows);
    }

    /**
     * Extrai código HTTP de uma mensagem de erro.
     */
    private function extractHttpCode(string $errorCode): string
    {
        // Se já é um código numérico puro
        if (preg_match('/^\d{3}$/', $errorCode)) {
            return $errorCode;
        }

        // Extrair código HTTP de mensagens como "HTTP 429 Too Many Requests"
        if (preg_match('/\b(4\d{2}|5\d{2})\b/', $errorCode, $matches)) {
            return $matches[1];
        }

        return $errorCode;
    }

    /**
     * Classifica o tipo de erro.
     */
    private function classifyError(string $errorCode, string $httpCode): string
    {
        $lower = strtolower($errorCode);

        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return 'timeout';
        }

        if (str_contains($lower, 'network') || str_contains($lower, 'connection')) {
            return 'network';
        }

        if (in_array($httpCode, self::RETRYABLE_CODES, true)) {
            return 'http_retryable';
        }

        if (in_array($httpCode, self::NON_RETRYABLE_CODES, true)) {
            return 'http_non_retryable';
        }

        return 'unknown';
    }

    /**
     * Constrói resposta de não-retry.
     */
    private function buildNoRetryResponse(string $httpCode, string $errorCode): array
    {
        $reasons = [
            '400' => 'Bad Request - dados inválidos, sem retry',
            '403' => 'Item sem permissão de acesso, sem retry',
            '404' => 'Item não encontrado, sem retry',
            '410' => 'Item removido permanentemente, sem retry',
            '422' => 'Dados não processáveis, sem retry',
        ];

        return [
            'should_retry' => false,
            'final_status' => $httpCode === '403' ? 'skipped' : 'failed',
            'reason' => $reasons[$httpCode] ?? "Erro {$httpCode} irrecuperável",
        ];
    }

    /**
     * Constrói resposta de retry com backoff exponencial.
     */
    private function buildRetryResponse(string $errorType, int $attempt): array
    {
        $maxAttempts = self::MAX_ATTEMPTS[$errorType] ?? self::MAX_ATTEMPTS['default'];

        if ($attempt >= $maxAttempts) {
            return [
                'should_retry' => false,
                'final_status' => 'failed',
                'reason' => "Max attempts ({$maxAttempts}) atingido para erro {$errorType}",
            ];
        }

        $delay = $this->calculateBackoff($attempt);

        return [
            'should_retry' => true,
            'delay' => $delay,
            'next_status' => 'pending',
            'reason' => "Retry {$attempt}/{$maxAttempts} com delay de {$delay}s",
            'attempt' => $attempt + 1,
            'max_attempts' => $maxAttempts,
        ];
    }

    /**
     * Calcula delay com backoff exponencial (sem jitter para previsibilidade).
     *
     * Fórmula: base * 2^attempt
     * Ex: 2, 4, 8, 16, 32, 64...
     */
    private function calculateBackoff(int $attempt): int
    {
        $delay = (int) (self::BASE_DELAY * pow(2, $attempt));

        // Cap máximo de 5 minutos
        return min($delay, 300);
    }
}
