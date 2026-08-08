<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Database;
use App\Services\MercadoLivreAuthService;
use App\Services\UnifiedTokenRefreshService;
use App\Services\StructuredLogService;
use PDO;

/**
 * Job para renovar tokens do Mercado Livre automaticamente
 * 
 * ATUALIZADO: Agora utiliza UnifiedTokenRefreshService para lógica consolidada
 * 
 * Deve ser executado via cron a cada hora:
 * 0 * * * * cd /home/eskill/htdocs/eskill.com.br && php scripts/refresh_ml_tokens.php >> storage/logs/token_refresh.log 2>&1
 * 
 * Ou via scheduler interno do sistema.
 */
class TokenRefreshJob
{
    private PDO $db;
    private UnifiedTokenRefreshService $unifiedService;
    private ?StructuredLogService $logger = null;
    
    // Renovar tokens que expiram nas próximas 2 horas
    private const REFRESH_BUFFER_HOURS = 2;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->unifiedService = new UnifiedTokenRefreshService();
        
        try {
            $this->logger = new StructuredLogService();
        } catch (\Throwable $e) {
            // Logger opcional
        }
    }
    
    /**
     * Executa a renovação de todos os tokens prestes a expirar
     * 
     * @param bool $forceAll Se true, renova TODAS as contas ativas, não apenas as que expiram em breve
     */
    public function run(bool $forceAll = false, bool $useLock = true): array
    {
        // Carregar configurações de ambiente
        $bufferMinutes = (int)($_ENV['TOKEN_REFRESH_MARGIN_MINUTES'] ?? (self::REFRESH_BUFFER_HOURS * 60));

        // Delegar para UnifiedTokenRefreshService
        if ($forceAll) {
            return $this->unifiedService->forceRefreshAll($useLock);
        }

        return $this->unifiedService->refreshExpiring($bufferMinutes, $useLock);
    }
    
    /**
     * Verifica e renova token de uma conta específica
     */
    public function refreshAccount(int $accountId): bool
    {
        $result = $this->unifiedService->refreshAccount($accountId);
        return $result['success'];
    }
    
    /**
     * Backward compatibility: acessa métricas de saúde
     */
    public function getHealthMetrics(): array
    {
        return $this->unifiedService->getHealthMetrics();
    }
    
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->{$level}($message, $context);
        }
        
        // Usar sistema de logging estruturado
        $logFn = 'log_' . $level;
        if (function_exists($logFn)) {
            $logFn($message, array_merge(['service' => 'TokenRefreshJob'], $context));
        }
    }
}
