<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Serviço Unificado de Renovação de Tokens do Mercado Livre
 * 
 * Consolida toda a lógica de renovação de tokens em um único lugar,
 * fornecendo interface simples para diferentes modos de operação:
 * - Renovação de tokens expirados/prestes a expirar
 * - Renovação forçada de todas as contas
 * - Renovação de conta específica
 * - Métricas de saúde do sistema
 * 
 * Features:
 * - File locking para evitar execuções concorrentes
 * - Auditoria completa de todas as operações
 * - Rate limiting configurável
 * - Retry com backoff exponencial
 * - Métricas detalhadas de sucesso/falha
 */
class UnifiedTokenRefreshService
{
    private PDO $db;
    private MercadoLivreAuthService $authService;
    private ?StructuredLogService $logger = null;
    private ?string $lockFile = null;
    /** @var resource|null */
    private $lockHandle = null;
    
    // Configurações padrão
    private const DEFAULT_BUFFER_MINUTES = 120;      // 2 horas
    private const DEFAULT_MAX_RETRIES = 3;
    private const DEFAULT_RATE_DELAY_MS = 500;       // 0.5 segundos entre renovações
    private const SKIP_EXPIRED_DAYS = 30;            // Ignorar tokens expirados há mais de 30 dias
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->authService = new MercadoLivreAuthService();
        $this->lockFile = $this->getLockFilePath();
        
        try {
            $this->logger = new StructuredLogService();
        } catch (\Throwable $e) {
            if (function_exists('log_warning')) {
                log_warning('Falha ao inicializar StructuredLogService em UnifiedTokenRefreshService', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
    
    /**
     * Renova tokens que estão prestes a expirar
     * 
     * @param int $bufferMinutes Renovar se expira em menos de X minutos (padrão: 120 = 2 horas)
     * @param bool $useLock Se false, não adquire lock (útil para testes)
     * @return array Estatísticas da execução
     */
    public function refreshExpiring(int $bufferMinutes = self::DEFAULT_BUFFER_MINUTES, bool $useLock = true): array
    {
        if ($useLock && !$this->acquireLock()) {
            return $this->createSkippedResult('Lock acquisition failed - another process is running');
        }
        
        try {
            return $this->executeRefresh(false, $bufferMinutes);
        } catch (\Throwable $e) {
            $this->log('error', 'Erro na renovação de tokens expirando', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($useLock) {
                $this->releaseLock();
            }
        }
    }

    /**
     * Alias de compatibilidade para versões antigas/tests.
     */
    public function processRefreshQueue(int $bufferMinutes = self::DEFAULT_BUFFER_MINUTES, bool $useLock = true): array
    {
        return $this->refreshExpiring($bufferMinutes, $useLock);
    }

    /**
     * Alias de compatibilidade para versões antigas/tests.
     */
    public function refreshTokensNearExpiry(int $bufferMinutes = self::DEFAULT_BUFFER_MINUTES, bool $useLock = true): array
    {
        return $this->refreshExpiring($bufferMinutes, $useLock);
    }
    
    /**
     * Força renovação de TODAS as contas ativas
     * 
     * @param bool $useLock Se false, não adquire lock
     * @return array Estatísticas da execução
     */
    public function forceRefreshAll(bool $useLock = true): array
    {
        if ($useLock && !$this->acquireLock()) {
            return $this->createSkippedResult('Lock acquisition failed - another process is running');
        }
        
        try {
            return $this->executeRefresh(true);
        } catch (\Throwable $e) {
            $this->log('error', 'Erro na renovação forçada de tokens', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($useLock) {
                $this->releaseLock();
            }
        }
    }
    
    /**
     * Renova token de uma conta específica
     * 
     * @param int $accountId ID da conta
     * @param int $bufferMinutes Buffer de renovação
     * @return array Resultado da renovação ['success' => bool, 'message' => string]
     */
    public function refreshAccount(int $accountId, int $bufferMinutes = self::DEFAULT_BUFFER_MINUTES): array
    {
        $this->log('info', "Renovando token da conta #{$accountId}", ['account_id' => $accountId]);
        
        $success = $this->authService->refreshToken($accountId, self::DEFAULT_MAX_RETRIES);

        $apiValidation = [
            'status' => 'skipped',
            'message' => 'Validação na API desabilitada',
        ];

        if ($success) {
            $apiValidation = $this->validateAccountConnection($accountId);
            $this->applyApiValidationOutcome($accountId, $apiValidation);
        }

        $message = $success ? 'Token renovado com sucesso' : 'Falha ao renovar token';
        if ($success && $apiValidation['status'] === 'ok') {
            $message = 'Token renovado e validado na API do Mercado Livre';
        } elseif ($success && $apiValidation['status'] === 'failed') {
            $message = 'Token renovado, mas validação na API falhou';
        }
        
        return [
            'success' => $success,
            'account_id' => $accountId,
            'message' => $message,
            'api_validation' => $apiValidation,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Obtém métricas de saúde do sistema de tokens
     * 
     * @return array Métricas detalhadas
     */
    public function getHealthMetrics(): array
    {
        $metrics = [
            'timestamp' => date('Y-m-d H:i:s'),
            'total_accounts' => $this->getTotalAccounts(),
            'active_accounts' => $this->getActiveAccounts(),
            'expired_accounts' => $this->getExpiredAccounts(),
            'expiring_24h' => $this->getExpiringSoon(24),
            'expiring_48h' => $this->getExpiringSoon(48),
            'refresh_attempts_24h' => $this->getRefreshAttempts24h(),
            'refresh_successes_24h' => $this->getRefreshSuccesses24h(),
            'refresh_failures_24h' => $this->getRefreshFailures24h(),
            'failure_rate_24h' => 0,
            'accounts_with_failures' => $this->getAccountsWithConsecutiveFailures(3),
            'last_refresh_avg_hours' => $this->getAverageHoursSinceLastRefresh(),
            'accounts_with_api_validation_failures' => $this->getAccountsWithApiValidationFailures(),
            'accounts_with_identity_mismatch' => $this->getAccountsWithIdentityMismatch(),
            'accounts_with_auth_errors' => $this->getAccountsWithAuthValidationErrors(),
            'recent_validation_errors' => $this->getRecentValidationErrors(),
        ];
        
        // Calcular taxa de falha
        if ($metrics['refresh_attempts_24h'] > 0) {
            $metrics['failure_rate_24h'] = round(
                ($metrics['refresh_failures_24h'] / $metrics['refresh_attempts_24h']) * 100,
                2
            );
        }
        
        // Status geral
        $metrics['health_status'] = $this->determineHealthStatus($metrics);
        
        return $metrics;
    }
    
    /**
     * Executa a lógica de renovação de tokens
     * 
     * @param bool $forceAll Se true, renova todas as contas
     * @param int $bufferMinutes Buffer de renovação
     * @return array Estatísticas
     */
    private function executeRefresh(bool $forceAll, int $bufferMinutes = self::DEFAULT_BUFFER_MINUTES): array
    {
        $startTime = microtime(true);
        
        $results = [
            'started_at' => date('Y-m-d H:i:s'),
            'mode' => $forceAll ? 'force_all' : 'expiring_only',
            'buffer_minutes' => $bufferMinutes,
            'accounts_checked' => 0,
            'tokens_refreshed' => 0,
            'tokens_failed' => 0,
            'tokens_skipped' => 0,
            'api_validations_ok' => 0,
            'api_validations_failed' => 0,
            'api_validations_skipped' => 0,
            'details' => [],
        ];
        
        $accounts = $this->getAccountsToRefresh($forceAll, $bufferMinutes);
        $results['accounts_checked'] = count($accounts);
        
        $this->log('info', 'Iniciando renovação de tokens', [
            'mode' => $results['mode'],
            'accounts' => $results['accounts_checked'],
        ]);
        
        // Rate limiting configuration
        $rateDelayMs = (int)($_ENV['ML_API_RATE_DELAY_MS'] ?? self::DEFAULT_RATE_DELAY_MS);
        $maxRetries = (int)($_ENV['TOKEN_REFRESH_MAX_RETRIES'] ?? self::DEFAULT_MAX_RETRIES);
        
        foreach ($accounts as $account) {
            $accountId = (int)$account['id'];
            $nickname = $account['nickname'];
            $expiresAt = $account['token_expires_at'];
            $currentStatus = $account['status'];
            
            $detail = [
                'account_id' => $accountId,
                'nickname' => $nickname,
                'expires_at' => $expiresAt,
                'status_before' => $currentStatus,
            ];
            
            // Verificar se já expirou há muito tempo
            if ($this->isTokenTooOld($expiresAt)) {
                $detail['result'] = 'skipped';
                $detail['reason'] = 'Token expirado há mais de ' . self::SKIP_EXPIRED_DAYS . ' dias - requer reconexão manual';
                $results['tokens_skipped']++;
                $results['details'][] = $detail;
                continue;
            }
            
            // Tentar renovar
            try {
                $success = $this->authService->refreshToken($accountId, $maxRetries);
                
                if ($success) {
                    $detail['result'] = 'success';
                    $detail['status_after'] = 'active';
                    $results['tokens_refreshed']++;

                    $apiValidation = $this->validateAccountConnection($accountId);
                    $detail['api_validation'] = $apiValidation;
                    $detail['status_after'] = $this->applyApiValidationOutcome($accountId, $apiValidation);

                    if ($apiValidation['status'] === 'ok') {
                        $results['api_validations_ok']++;
                    } elseif ($apiValidation['status'] === 'failed') {
                        $results['api_validations_failed']++;
                    } else {
                        $results['api_validations_skipped']++;
                    }
                    
                    $this->log('info', "Token renovado: {$nickname}", ['account_id' => $accountId]);
                } else {
                    $statusRow = $this->getAccountStatusRow($accountId);
                    $statusAfter = $statusRow['status'];
                    $lastError = $statusRow['last_refresh_error'];

                    // Se o refresh falhou por invalid_grant, o MercadoLivreAuthService marca como disconnected.
                    // NUNCA sobrescrever disconnected para expired.
                    if ($statusAfter === 'disconnected') {
                        $detail['result'] = 'failed';
                        $detail['status_after'] = 'disconnected';
                        $detail['reason'] = $lastError ?: 'Conta desconectada — requer reconexão OAuth';
                        $results['tokens_failed']++;

                        $this->log('warning', "Conta desconectada — refresh irrecuperável: {$nickname}", [
                            'account_id' => $accountId,
                            'last_refresh_error' => $lastError,
                        ]);
                    } else {
                        // Marcar como expirado se ainda não estava
                        if ($currentStatus !== 'expired') {
                            $this->markAccountAsExpired($accountId);
                        }

                        $detail['result'] = 'failed';
                        $detail['status_after'] = 'expired';
                        $detail['reason'] = 'Refresh token inválido ou expirado';
                        $results['tokens_failed']++;

                        $this->log('warning', "Falha ao renovar: {$nickname}", ['account_id' => $accountId]);
                    }
                }
            } catch (\Throwable $e) {
                $detail['result'] = 'error';
                $detail['reason'] = $e->getMessage();
                $results['tokens_failed']++;
                
                $this->log('error', "Erro ao renovar: {$nickname}", [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
            
            $results['details'][] = $detail;
            
            // Rate limiting
            usleep($rateDelayMs * 1000);
        }
        
        $executionTime = round((microtime(true) - $startTime) * 1000);
        $results['finished_at'] = date('Y-m-d H:i:s');
        $results['execution_time_ms'] = $executionTime;

        // Compatibilidade retroativa para consumidores antigos do worker
        $results['checked'] = $results['accounts_checked'];
        $results['refreshed'] = $results['tokens_refreshed'];
        $results['failed'] = $results['tokens_failed'];
        $results['skipped'] = $results['tokens_skipped'];
        
        $this->log('info', 'Renovação de tokens concluída', [
            'checked' => $results['accounts_checked'],
            'refreshed' => $results['tokens_refreshed'],
            'failed' => $results['tokens_failed'],
            'skipped' => $results['tokens_skipped'],
            'execution_ms' => $executionTime,
        ]);
        
        return $results;
    }
    
    /**
     * Obtém contas que precisam de renovação
     */
    private function getAccountsToRefresh(bool $forceAll, int $bufferMinutes): array
    {
        if ($forceAll) {
            $stmt = $this->db->prepare("
                SELECT id, nickname, ml_user_id, token_expires_at, status
                FROM ml_accounts
                WHERE status != 'disconnected'
                AND refresh_token IS NOT NULL
                AND refresh_token != ''
                ORDER BY token_expires_at ASC
            ");
            $stmt->execute();
        } else {
            $bufferTime = date('Y-m-d H:i:s', time() + ($bufferMinutes * 60));
            
            $stmt = $this->db->prepare("
                SELECT id, nickname, ml_user_id, token_expires_at, status
                FROM ml_accounts
                WHERE (
                    token_expires_at <= :buffer_time
                    OR status = 'expired'
                )
                AND status != 'disconnected'
                AND refresh_token IS NOT NULL
                AND refresh_token != ''
                ORDER BY token_expires_at ASC
            ");
            $stmt->execute(['buffer_time' => $bufferTime]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verifica se o token está expirado há muito tempo
     */
    private function isTokenTooOld(string $expiresAt): bool
    {
        $secondsExpired = time() - strtotime($expiresAt);
        return $secondsExpired > (self::SKIP_EXPIRED_DAYS * 24 * 3600);
    }
    
    /**
     * Marca conta como expirada
     */
    private function markAccountAsExpired(int $accountId): void
    {
        $this->db->prepare(
            "UPDATE ml_accounts SET status = 'expired', updated_at = NOW() WHERE id = :id"
        )->execute(['id' => $accountId]);
    }

    /**
     * @return array{status: string, last_refresh_error: string|null}
     */
    private function getAccountStatusRow(int $accountId): array
    {
        try {
            $stmt = $this->db->prepare('SELECT status, last_refresh_error FROM ml_accounts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return ['status' => 'unknown', 'last_refresh_error' => null];
            }

            $status = (string)($row['status'] ?? 'unknown');
            $errRaw = $row['last_refresh_error'] ?? null;
            $err = is_string($errRaw) && trim($errRaw) !== '' ? trim($errRaw) : null;

            return ['status' => $status, 'last_refresh_error' => $err];
        } catch (\Throwable $e) {
            $this->log('warning', 'Falha ao consultar status da conta após refresh', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            return ['status' => 'unknown', 'last_refresh_error' => null];
        }
    }
    
    // ===== FILE LOCKING =====
    
    private function getLockFilePath(): string
    {
        $storageDir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        return $storageDir . '/unified_token_refresh.lock';
    }
    
    private function acquireLock(): bool
    {
        if ($this->lockHandle !== null) {
            return true;
        }

        $handle = @fopen($this->lockFile, 'c+');
        if ($handle === false) {
            $this->log('error', 'Falha ao abrir arquivo de lock', ['lock_file' => $this->lockFile]);
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $this->log('warning', 'Renovação já em execução em outro processo', [
                'lock_file' => $this->lockFile,
            ]);
            return false;
        }

        ftruncate($handle, 0);
        $lockData = [
            'pid' => getmypid(),
            'hostname' => gethostname(),
            'started_at' => date('Y-m-d H:i:s'),
        ];
        fwrite($handle, (string) json_encode($lockData, JSON_PRETTY_PRINT));
        fflush($handle);

        $this->lockHandle = $handle;
        $this->log('info', 'Lock adquirido', ['pid' => getmypid()]);
        return true;
    }
    
    private function releaseLock(): void
    {
        if ($this->lockHandle === null) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;

        // Melhor esforço: limpar conteúdo; o flock já serializa concorrência.
        @unlink($this->lockFile);
        $this->log('info', 'Lock liberado');
    }
    
    // ===== MÉTRICAS =====
    
    private function getTotalAccounts(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM ml_accounts')->fetchColumn();
    }
    
    private function getActiveAccounts(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM ml_accounts WHERE status = 'active'")->fetchColumn();
    }
    
    private function getExpiredAccounts(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM ml_accounts WHERE status = 'expired'")->fetchColumn();
    }
    
    private function getExpiringSoon(int $hours): int
    {
        $threshold = date('Y-m-d H:i:s', time() + ($hours * 3600));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ml_accounts 
             WHERE status = 'active' 
             AND token_expires_at <= :threshold"
        );
        $stmt->execute(['threshold' => $threshold]);
        return (int)$stmt->fetchColumn();
    }
    
    private function getRefreshAttempts24h(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM token_refresh_audit 
             WHERE action = 'refresh_attempt' 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->fetchColumn();
    }
    
    private function getRefreshSuccesses24h(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM token_refresh_audit 
             WHERE action = 'refresh_success' 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->fetchColumn();
    }
    
    private function getRefreshFailures24h(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM token_refresh_audit 
             WHERE action = 'refresh_failed' 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->fetchColumn();
    }
    
    private function getAccountsWithConsecutiveFailures(int $threshold): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ml_accounts WHERE refresh_failure_count >= :threshold"
        );
        $stmt->execute(['threshold' => $threshold]);
        return (int)$stmt->fetchColumn();
    }
    
    private function getAverageHoursSinceLastRefresh(): ?float
    {
        $result = $this->db->query(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, last_refresh_at, NOW())) as avg_hours 
             FROM ml_accounts 
             WHERE status = 'active' 
             AND last_refresh_at IS NOT NULL"
        )->fetch(PDO::FETCH_ASSOC);
        
        return $result['avg_hours'] ? round((float)$result['avg_hours'], 2) : null;
    }

    private function getAccountsWithApiValidationFailures(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM ml_accounts
             WHERE refresh_failure_count > 0
             AND last_refresh_error IS NOT NULL
             AND TRIM(last_refresh_error) != ''"
        )->fetchColumn();
    }

    private function getAccountsWithIdentityMismatch(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM ml_accounts
             WHERE last_refresh_error IS NOT NULL
             AND (
                LOWER(last_refresh_error) LIKE '%ml_user_id_mismatch%'
                OR LOWER(last_refresh_error) LIKE '%mismatch%'
             )"
        )->fetchColumn();
    }

    private function getAccountsWithAuthValidationErrors(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM ml_accounts
             WHERE last_refresh_error IS NOT NULL
             AND (
                LOWER(last_refresh_error) LIKE '%401%'
                OR LOWER(last_refresh_error) LIKE '%unauthorized%'
                OR LOWER(last_refresh_error) LIKE '%invalid token%'
                OR LOWER(last_refresh_error) LIKE '%invalid_token%'
                OR LOWER(last_refresh_error) LIKE '%invalid access token%'
                OR LOWER(last_refresh_error) LIKE '%missing_access_token%'
             )"
        )->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentValidationErrors(int $limit = 5): array
    {
        $safeLimit = max(1, min(20, $limit));
        $stmt = $this->db->query(
            "SELECT id, nickname, last_refresh_error, refresh_failure_count, updated_at
             FROM ml_accounts
             WHERE last_refresh_error IS NOT NULL
             AND TRIM(last_refresh_error) != ''
             ORDER BY updated_at DESC
             LIMIT {$safeLimit}"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function determineHealthStatus(array $metrics): string
    {
        if ($metrics['accounts_with_identity_mismatch'] > 0) {
            return 'critical';
        }

        if ($metrics['accounts_with_auth_errors'] >= 3) {
            return 'critical';
        }

        if ($metrics['failure_rate_24h'] >= 40) {
            return 'critical';
        }
        
        if (
            $metrics['failure_rate_24h'] >= 20
            || $metrics['expired_accounts'] >= 5
            || $metrics['accounts_with_api_validation_failures'] >= 3
        ) {
            return 'warning';
        }
        
        if ($metrics['expiring_24h'] > 0 || $metrics['accounts_with_api_validation_failures'] > 0) {
            return 'attention';
        }
        
        return 'healthy';
    }
    
    // ===== HELPERS =====
    
    private function createSkippedResult(string $reason): array
    {
        return [
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
            'status' => 'skipped',
            'reason' => $reason,
            'accounts_checked' => 0,
            'tokens_refreshed' => 0,
            'tokens_failed' => 0,
            'tokens_skipped' => 0,
            'api_validations_ok' => 0,
            'api_validations_failed' => 0,
            'api_validations_skipped' => 0,
            // Compatibilidade retroativa
            'checked' => 0,
            'refreshed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
    }

    private function shouldValidateWithApi(): bool
    {
        $raw = $_ENV['ML_VALIDATE_TOKEN_AFTER_REFRESH']
            ?? getenv('ML_VALIDATE_TOKEN_AFTER_REFRESH')
            ?? null;

        if ($raw === null || $raw === '') {
            return true;
        }

        return (bool)filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Valida acesso na API do ML após refresh via /users/me
     * e sincroniza identidade básica da conta no banco local.
     *
     * @return array{status: string, message: string, ml_user_id?: string, nickname?: string, error?: string}
     */
    private function validateAccountConnection(int $accountId): array
    {
        if (!$this->shouldValidateWithApi()) {
            return [
                'status' => 'skipped',
                'message' => 'Validação na API desabilitada por configuração',
            ];
        }

        try {
            $client = new MercadoLivreClient($accountId, $this->authService);
            $me = $client->get('/users/me');

            if (isset($me['body']) && is_array($me['body'])) {
                $me = $me['body'];
            }

            if (isset($me['error'])) {
                return [
                    'status' => 'failed',
                    'message' => 'Falha ao validar token na API /users/me',
                    'error' => (string)($me['message'] ?? $me['error']),
                ];
            }

            $mlUserId = (string)($me['id'] ?? '');
            if ($mlUserId === '') {
                return [
                    'status' => 'failed',
                    'message' => 'Resposta inválida da API /users/me',
                    'error' => 'Campo id ausente',
                ];
            }

            $expectedMlUserId = $this->getExpectedMlUserId($accountId);
            if ($expectedMlUserId !== null && $expectedMlUserId !== '' && $expectedMlUserId !== $mlUserId) {
                return [
                    'status' => 'failed',
                    'message' => 'Token validou com usuário ML diferente da conta esperada',
                    'error' => 'ml_user_id_mismatch',
                    'ml_user_id' => $mlUserId,
                    'expected_ml_user_id' => $expectedMlUserId,
                ];
            }

            $nickname = (string)($me['nickname'] ?? '');
            $this->syncAccountIdentity($accountId, $mlUserId, $nickname !== '' ? $nickname : null);

            return [
                'status' => 'ok',
                'message' => 'Token validado com sucesso na API do Mercado Livre',
                'ml_user_id' => $mlUserId,
                'nickname' => $nickname,
            ];
        } catch (\Throwable $e) {
            $this->log('warning', 'Falha ao validar token na API do Mercado Livre', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'message' => 'Exceção ao validar token na API do Mercado Livre',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function syncAccountIdentity(int $accountId, string $mlUserId, ?string $nickname): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE ml_accounts
                SET ml_user_id = :ml_user_id,
                    nickname = COALESCE(:nickname, nickname),
                    status = 'active',
                    updated_at = NOW()
                WHERE id = :id"
            );

            $stmt->execute([
                'ml_user_id' => $mlUserId,
                'nickname' => $nickname,
                'id' => $accountId,
            ]);
        } catch (\Throwable $e) {
            $this->log('warning', 'Falha ao sincronizar identidade da conta ML após validação', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Aplica no banco local o resultado da validação da API do Mercado Livre.
     *
     * @param array{status?: string, message?: string, error?: string} $apiValidation
     */
    private function applyApiValidationOutcome(int $accountId, array $apiValidation): string
    {
        $status = (string)($apiValidation['status'] ?? 'skipped');

        if ($status === 'ok') {
            try {
                $stmt = $this->db->prepare(
                    "UPDATE ml_accounts
                    SET status = 'active',
                        refresh_failure_count = 0,
                        last_refresh_error = NULL,
                        updated_at = NOW()
                    WHERE id = :id"
                );

                $stmt->execute(['id' => $accountId]);
            } catch (\Throwable $e) {
                $this->log('warning', 'Falha ao atualizar estado da conta após validação API bem-sucedida', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            return 'active';
        }

        if ($status === 'failed') {
            $errorMessage = (string)($apiValidation['error'] ?? $apiValidation['message'] ?? 'Falha na validação da API após refresh');
            $errorMessage = trim($errorMessage) !== '' ? $errorMessage : 'Falha na validação da API após refresh';
            $errorMessage = mb_substr($errorMessage, 0, 500);
            $expireAccount = $this->shouldExpireAccountFromValidationError($errorMessage);

            try {
                $stmt = $this->db->prepare(
                    "UPDATE ml_accounts
                    SET refresh_failure_count = refresh_failure_count + 1,
                        last_refresh_error = :error,
                        status = CASE WHEN :expire = 1 THEN 'expired' ELSE status END,
                        updated_at = NOW()
                    WHERE id = :id"
                );

                $stmt->execute([
                    'error' => $errorMessage,
                    'expire' => $expireAccount ? 1 : 0,
                    'id' => $accountId,
                ]);
            } catch (\Throwable $e) {
                $this->log('warning', 'Falha ao persistir erro de validação da API após refresh', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            return $expireAccount ? 'expired' : 'active';
        }

        return 'active';
    }

    private function shouldExpireAccountFromValidationError(string $errorMessage): bool
    {
        $normalized = mb_strtolower($errorMessage);

        return str_contains($normalized, '401')
            || str_contains($normalized, 'unauthorized')
            || str_contains($normalized, 'invalid token')
            || str_contains($normalized, 'invalid_token')
            || str_contains($normalized, 'invalid access token')
            || str_contains($normalized, 'missing_access_token')
            || str_contains($normalized, 'ml_user_id_mismatch')
            || str_contains($normalized, 'mismatch');
    }

    private function getExpectedMlUserId(int $accountId): ?string
    {
        try {
            $stmt = $this->db->prepare('SELECT ml_user_id FROM ml_accounts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return null;
            }

            $value = (string)($row['ml_user_id'] ?? '');
            return $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            $this->log('warning', 'Falha ao obter ml_user_id esperado para validação de identidade', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->{$level}($message, $context);
            return;
        }
        
        $context['service'] = 'UnifiedTokenRefreshService';
        $logFn = "log_{$level}";
        if (function_exists($logFn)) {
            $logFn($message, $context);
        }
    }
}
