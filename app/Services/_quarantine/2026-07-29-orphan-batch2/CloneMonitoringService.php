<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * CloneMonitoringService - Monitoramento e Hardening do Módulo de Clonagem
 *
 * Fase 6: Hardening, monitoramento e compliance
 *
 * Funcionalidades:
 * - Logs estruturados para todas as operações
 * - Alertas para taxas anormais de erro
 * - Feature flags para controle do módulo
 * - Métricas de saúde do sistema
 * - Rate limiting inteligente com backoff
 */
class CloneMonitoringService
{
    private PDO $db;
    private LoggingService $logger;
    private ?SettingsService $settings = null;

    // Thresholds de alerta
    private const ERROR_RATE_THRESHOLD = 0.15; // 15% de erro dispara alerta
    private const API_BLOCK_THRESHOLD = 5;     // 5 bloqueios em 1h dispara alerta
    private const SLOW_CLONE_THRESHOLD = 30;   // 30s é considerado lento

    // Feature flags
    public const FLAG_CLONE_ENABLED = 'clone_module_enabled';
    public const FLAG_BATCH_ENABLED = 'clone_batch_enabled';
    public const FLAG_POST_ACTIONS_ENABLED = 'clone_post_actions_enabled';
    public const FLAG_RATE_LIMIT_STRICT = 'clone_rate_limit_strict';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logger = new LoggingService();
        $this->ensureMonitoringTables();
    }

    /**
     * Garante que as tabelas de monitoramento existem
     */
    private function ensureMonitoringTables(): void
    {
        try {
            // Tabela de alertas
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS clone_alerts (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    alert_type VARCHAR(50) NOT NULL,
                    severity ENUM('info', 'warning', 'critical') DEFAULT 'warning',
                    message TEXT NOT NULL,
                    context JSON,
                    acknowledged BOOLEAN DEFAULT FALSE,
                    acknowledged_by INT,
                    acknowledged_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_type (alert_type),
                    INDEX idx_severity (severity),
                    INDEX idx_acknowledged (acknowledged),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Tabela de feature flags
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS feature_flags (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    flag_name VARCHAR(100) NOT NULL UNIQUE,
                    is_enabled BOOLEAN DEFAULT TRUE,
                    description TEXT,
                    metadata JSON,
                    updated_by INT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_flag_name (flag_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Tabela de métricas de saúde
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS clone_health_metrics (
                    id BIGINT PRIMARY KEY AUTO_INCREMENT,
                    metric_name VARCHAR(100) NOT NULL,
                    metric_value DECIMAL(20,4) NOT NULL,
                    metric_unit VARCHAR(20),
                    context JSON,
                    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_metric_name (metric_name),
                    INDEX idx_recorded (recorded_at),
                    INDEX idx_name_recorded (metric_name, recorded_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Inicializar feature flags padrão
            $this->initDefaultFlags();
        } catch (\Exception $e) {
            log_error('Erro ao criar tabelas do CloneMonitoringService', [
                'service' => 'CloneMonitoringService',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Inicializa feature flags padrão se não existirem
     */
    private function initDefaultFlags(): void
    {
        $defaults = [
            self::FLAG_CLONE_ENABLED => ['enabled' => true, 'desc' => 'Habilita/desabilita módulo de clonagem'],
            self::FLAG_BATCH_ENABLED => ['enabled' => true, 'desc' => 'Habilita/desabilita clonagem em lote'],
            self::FLAG_POST_ACTIONS_ENABLED => ['enabled' => true, 'desc' => 'Habilita/desabilita pós-ações (SEO, etc)'],
            self::FLAG_RATE_LIMIT_STRICT => ['enabled' => false, 'desc' => 'Modo estrito de rate limiting'],
        ];

        $stmt = $this->db->prepare("
            INSERT IGNORE INTO feature_flags (flag_name, is_enabled, description)
            VALUES (:name, :enabled, :desc)
        ");

        foreach ($defaults as $name => $config) {
            $stmt->execute([
                'name' => $name,
                'enabled' => $config['enabled'] ? 1 : 0,
                'desc' => $config['desc']
            ]);
        }
    }

    // =========================================================================
    // FEATURE FLAGS
    // =========================================================================

    /**
     * Verifica se uma feature flag está habilitada
     */
    public function isFeatureEnabled(string $flagName): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT is_enabled FROM feature_flags WHERE flag_name = :name
            ");
            $stmt->execute(['name' => $flagName]);
            $result = $stmt->fetchColumn();

            return $result !== false ? (bool)$result : true; // Default: enabled
        } catch (\Exception $e) {
            return true; // Em caso de erro, permitir operação
        }
    }

    /**
     * Atualiza estado de uma feature flag
     */
    public function setFeatureFlag(string $flagName, bool $enabled, ?int $userId = null): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE feature_flags
                SET is_enabled = :enabled, updated_at = NOW()
                WHERE flag_name = :name
            ");
            $stmt->execute([
                'enabled' => $enabled ? 1 : 0,
                'name' => $flagName
            ]);

            // Log da mudança
            $this->logCloneEvent('feature_flag_changed', [
                'flag' => $flagName,
                'enabled' => $enabled,
                'user_id' => $userId
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Lista todas as feature flags
     */
    public function listFeatureFlags(): array
    {
        $stmt = $this->db->query("
            SELECT flag_name, is_enabled, description, updated_at
            FROM feature_flags
            ORDER BY flag_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verifica se o módulo de clonagem pode operar
     *
     * Nota: Estado crítico apenas bloqueia em modo estrito (FLAG_RATE_LIMIT_STRICT)
     * Em modo normal, gera alerta mas permite operação
     */
    public function canClone(): array
    {
        $enabled = $this->isFeatureEnabled(self::FLAG_CLONE_ENABLED);

        if (!$enabled) {
            return [
                'allowed' => false,
                'reason' => 'Módulo de clonagem temporariamente desabilitado',
                'flag' => self::FLAG_CLONE_ENABLED
            ];
        }

        // Verificar saúde do sistema
        $health = $this->getSystemHealth();

        // Em modo estrito, bloquear se crítico
        $strictMode = $this->isFeatureEnabled(self::FLAG_RATE_LIMIT_STRICT);
        if ($health['status'] === 'critical' && $strictMode) {
            return [
                'allowed' => false,
                'reason' => 'Sistema em estado crítico: ' . ($health['issues'][0] ?? 'erro desconhecido'),
                'health' => $health
            ];
        }

        // Em modo normal, apenas logar alerta se crítico
        if ($health['status'] === 'critical') {
            $this->createAlert(
                'health_warning',
                'warning',
                'Sistema em estado crítico mas operando: ' . ($health['issues'][0] ?? 'erro desconhecido'),
                ['health' => $health]
            );
        }

        return ['allowed' => true, 'health' => $health];
    }

    // =========================================================================
    // LOGGING ESTRUTURADO
    // =========================================================================

    /**
     * Log de evento de clonagem
     */
    public function logCloneEvent(string $event, array $data = [], string $level = 'INFO'): void
    {
        $this->logger->log(
            $level,
            LoggingService::CATEGORY_CATALOG_CLONE,
            $event,
            array_merge($data, [
                'timestamp' => date('c'),
                'event_type' => $event
            ])
        );
    }

    /**
     * Log de início de clonagem
     */
    public function logCloneStart(string $sourceItemId, int $sourceAccountId, int $targetAccountId, array $options = []): string
    {
        $operationId = uniqid('clone_', true);

        $this->logCloneEvent('clone_started', [
            'operation_id' => $operationId,
            'source_item_id' => $sourceItemId,
            'source_account_id' => $sourceAccountId,
            'target_account_id' => $targetAccountId,
            'pricing_strategy' => $options['pricing_strategy'] ?? null,
            'is_batch' => $options['is_batch'] ?? false,
            'job_id' => $options['job_id'] ?? null
        ]);

        // Registrar métrica
        $this->recordMetric('clone_operations_started', 1, 'count');

        return $operationId;
    }

    /**
     * Log de fim de clonagem
     */
    public function logCloneEnd(
        string $operationId,
        string $status,
        ?string $targetItemId = null,
        ?string $error = null,
        ?float $duration = null
    ): void {
        $this->logCloneEvent('clone_completed', [
            'operation_id' => $operationId,
            'status' => $status,
            'target_item_id' => $targetItemId,
            'error' => $error,
            'duration_seconds' => $duration
        ], $status === 'error' ? 'ERROR' : 'INFO');

        // Registrar métricas
        $this->recordMetric("clone_status_{$status}", 1, 'count');

        if ($duration !== null) {
            $this->recordMetric('clone_duration', $duration, 'seconds');

            // Alertar se muito lento
            if ($duration > self::SLOW_CLONE_THRESHOLD) {
                $this->createAlert(
                    'slow_clone',
                    'warning',
                    "Clonagem lenta detectada: {$duration}s (limite: " . self::SLOW_CLONE_THRESHOLD . "s)",
                    ['operation_id' => $operationId, 'duration' => $duration]
                );
            }
        }

        // Verificar taxa de erro após cada operação
        $this->checkErrorRate();
    }

    /**
     * Log de erro de API
     */
    public function logApiError(string $endpoint, int $statusCode, string $error, array $context = []): void
    {
        $this->logCloneEvent('api_error', array_merge([
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'error' => $error
        ], $context), 'ERROR');

        $this->recordMetric('api_errors', 1, 'count');

        // Detectar bloqueio de API (429 ou 403)
        if (in_array($statusCode, [429, 403])) {
            $this->recordMetric('api_blocks', 1, 'count');
            $this->checkApiBlockRate();
        }
    }

    // =========================================================================
    // MÉTRICAS DE SAÚDE
    // =========================================================================

    /**
     * Registra uma métrica
     */
    public function recordMetric(string $name, float $value, string $unit = 'count', array $context = []): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO clone_health_metrics (metric_name, metric_value, metric_unit, context)
                VALUES (:name, :value, :unit, :context)
            ");
            $stmt->execute([
                'name' => $name,
                'value' => $value,
                'unit' => $unit,
                'context' => json_encode($context)
            ]);
        } catch (\Exception $e) {
            // Silently fail - não bloquear operação por falha de métrica
        }
    }

    /**
     * Obtém métricas agregadas
     */
    public function getMetrics(string $period = '1h'): array
    {
        $intervals = [
            '1h' => '1 HOUR',
            '24h' => '24 HOUR',
            '7d' => '7 DAY',
            '30d' => '30 DAY'
        ];
        $interval = $intervals[$period] ?? '1 HOUR';

        $stmt = $this->db->prepare("
            SELECT
                metric_name,
                COUNT(*) as count,
                SUM(metric_value) as total,
                AVG(metric_value) as average,
                MIN(metric_value) as min_value,
                MAX(metric_value) as max_value,
                metric_unit
            FROM clone_health_metrics
            WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL {$interval})
            GROUP BY metric_name, metric_unit
            ORDER BY metric_name
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtém saúde geral do sistema
     */
    public function getSystemHealth(): array
    {
        $issues = [];
        $status = 'healthy';

        // Verificar taxa de erro na última hora
        $errorRate = $this->calculateErrorRate('1h');
        if ($errorRate > self::ERROR_RATE_THRESHOLD) {
            $issues[] = "Taxa de erro alta: " . round($errorRate * 100, 1) . "%";
            $status = 'degraded';
        }
        if ($errorRate > 0.30) {
            $status = 'critical';
        }

        // Verificar bloqueios de API
        $apiBlocks = $this->countMetric('api_blocks', '1h');
        if ($apiBlocks >= self::API_BLOCK_THRESHOLD) {
            $issues[] = "Múltiplos bloqueios de API: {$apiBlocks} na última hora";
            $status = $status === 'critical' ? 'critical' : 'degraded';
        }

        $queueBreakdown = $this->getQueueBreakdown();
        $pendingJobs = $queueBreakdown['legacy_pending'] + $queueBreakdown['batch_pending'] + $queueBreakdown['batch_processing'];
        if ($pendingJobs > 100) {
            $issues[] = "Fila com muitos jobs pendentes: {$pendingJobs}";
            $status = $status === 'critical' ? 'critical' : 'degraded';
        }

        // Verificar alertas não reconhecidos
        $unresolvedAlerts = $this->countUnacknowledgedAlerts();
        if ($unresolvedAlerts > 5) {
            $issues[] = "{$unresolvedAlerts} alertas não reconhecidos";
        }

        // Métricas da última hora
        $metrics = $this->getMetrics('1h');
        $metricsMap = [];
        foreach ($metrics as $m) {
            $metricsMap[$m['metric_name']] = $m;
        }

        return [
            'status' => $status,
            'legacy_status' => $status === 'degraded' ? 'warning' : $status,
            'issues' => $issues,
            'error_rate' => round($errorRate * 100, 2),
            'api_blocks_1h' => $apiBlocks,
            'pending_jobs' => $pendingJobs,
            'queue_breakdown' => $queueBreakdown,
            'unresolved_alerts' => $unresolvedAlerts,
            'operations_1h' => (int)($metricsMap['clone_operations_started']['total'] ?? 0),
            'success_1h' => (int)($metricsMap['clone_status_success']['total'] ?? 0),
            'errors_1h' => (int)($metricsMap['clone_status_error']['total'] ?? 0),
            'avg_duration' => round((float)($metricsMap['clone_duration']['average'] ?? 0), 2),
            'feature_flags' => $this->listFeatureFlags(),
            'checked_at' => date('c')
        ];
    }

    /**
     * Calcula taxa de erro
     */
    private function calculateErrorRate(string $period = '1h'): float
    {
        $intervals = ['1h' => '1 HOUR', '24h' => '24 HOUR'];
        $interval = $intervals[$period] ?? '1 HOUR';

        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN metric_name = 'clone_status_error' THEN metric_value ELSE 0 END) as errors,
                SUM(CASE WHEN metric_name LIKE 'clone_status_%' THEN metric_value ELSE 0 END) as total
            FROM clone_health_metrics
            WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL {$interval})
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = (float)($result['total'] ?? 0);
        $errors = (float)($result['errors'] ?? 0);

        return $total > 0 ? $errors / $total : 0;
    }

    /**
     * Conta métrica específica
     */
    private function countMetric(string $name, string $period = '1h'): int
    {
        $intervals = ['1h' => '1 HOUR', '24h' => '24 HOUR'];
        $interval = $intervals[$period] ?? '1 HOUR';

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(metric_value), 0) as total
            FROM clone_health_metrics
            WHERE metric_name = :name
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL {$interval})
        ");
        $stmt->execute(['name' => $name]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Conta jobs pendentes
     */
    private function getQueueBreakdown(): array
    {
        $legacyPending = 0;
        $batchPending = 0;
        $batchProcessing = 0;

        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) FROM jobs
                WHERE type = 'catalog_clone_item' AND status = 'pending'
            ");
            $legacyPending = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
        }

        try {
            $stmt = $this->db->query("
                SELECT
                    SUM(CASE WHEN status IN ('pending', 'queued') THEN 1 ELSE 0 END) as batch_pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as batch_processing
                FROM catalog_clone_jobs
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $batchPending = (int)($result['batch_pending'] ?? 0);
            $batchProcessing = (int)($result['batch_processing'] ?? 0);
        } catch (\Exception $e) {
        }

        return [
            'legacy_pending' => $legacyPending,
            'batch_pending' => $batchPending,
            'batch_processing' => $batchProcessing
        ];
    }

    // =========================================================================
    // SISTEMA DE ALERTAS
    // =========================================================================

    /**
     * Cria um alerta
     */
    public function createAlert(string $type, string $severity, string $message, array $context = []): int
    {
        // Evitar alertas duplicados recentes (últimos 5 min)
        $stmt = $this->db->prepare("
            SELECT id FROM clone_alerts
            WHERE alert_type = :type
            AND message = :message
            AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            LIMIT 1
        ");
        $stmt->execute(['type' => $type, 'message' => $message]);
        if ($stmt->fetch()) {
            return 0; // Alerta duplicado
        }

        $stmt = $this->db->prepare("
            INSERT INTO clone_alerts (alert_type, severity, message, context)
            VALUES (:type, :severity, :message, :context)
        ");
        $stmt->execute([
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'context' => json_encode($context)
        ]);

        $alertId = (int)$this->db->lastInsertId();

        // Log do alerta
        $this->logger->log(
            $severity === 'critical' ? LoggingService::LEVEL_CRITICAL : LoggingService::LEVEL_WARNING,
            LoggingService::CATEGORY_MONITORING,
            "ALERT [{$type}]: {$message}",
            $context
        );

        return $alertId;
    }

    /**
     * Lista alertas
     */
    public function listAlerts(bool $onlyUnacknowledged = true, int $limit = 50): array
    {
        $sql = "SELECT * FROM clone_alerts";
        if ($onlyUnacknowledged) {
            $sql .= " WHERE acknowledged = FALSE";
        }

        $limitSql = max(1, min((int)$limit, 200));
        $sql .= " ORDER BY created_at DESC LIMIT {$limitSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reconhece um alerta
     */
    public function acknowledgeAlert(int $alertId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE clone_alerts
            SET acknowledged = TRUE, acknowledged_by = :user_id, acknowledged_at = NOW()
            WHERE id = :id AND acknowledged = FALSE
        ");
        if (!$stmt->execute(['id' => $alertId, 'user_id' => $userId])) {
            return false;
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Conta alertas não reconhecidos
     */
    private function countUnacknowledgedAlerts(): int
    {
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM clone_alerts WHERE acknowledged = FALSE
        ");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Verifica e alerta sobre taxa de erro
     */
    private function checkErrorRate(): void
    {
        $errorRate = $this->calculateErrorRate('1h');

        if ($errorRate > 0.30) {
            $this->createAlert(
                'high_error_rate',
                'critical',
                "Taxa de erro crítica: " . round($errorRate * 100, 1) . "%",
                ['error_rate' => $errorRate, 'threshold' => 0.30]
            );
        } elseif ($errorRate > self::ERROR_RATE_THRESHOLD) {
            $this->createAlert(
                'high_error_rate',
                'warning',
                "Taxa de erro elevada: " . round($errorRate * 100, 1) . "%",
                ['error_rate' => $errorRate, 'threshold' => self::ERROR_RATE_THRESHOLD]
            );
        }
    }

    /**
     * Verifica e alerta sobre bloqueios de API
     */
    private function checkApiBlockRate(): void
    {
        $blocks = $this->countMetric('api_blocks', '1h');

        if ($blocks >= self::API_BLOCK_THRESHOLD) {
            $this->createAlert(
                'api_blocks',
                'warning',
                "Múltiplos bloqueios de API detectados: {$blocks} na última hora",
                ['blocks' => $blocks, 'threshold' => self::API_BLOCK_THRESHOLD]
            );

            // Ativar rate limit estrito automaticamente
            if ($blocks >= self::API_BLOCK_THRESHOLD * 2) {
                $this->setFeatureFlag(self::FLAG_RATE_LIMIT_STRICT, true);
                $this->createAlert(
                    'auto_rate_limit',
                    'warning',
                    "Rate limit estrito ativado automaticamente devido a bloqueios",
                    ['blocks' => $blocks]
                );
            }
        }
    }

    // =========================================================================
    // RATE LIMITING INTELIGENTE
    // =========================================================================

    /**
     * Obtém delay recomendado para próxima operação (backoff exponencial)
     */
    public function getRecommendedDelay(): int
    {
        $isStrict = $this->isFeatureEnabled(self::FLAG_RATE_LIMIT_STRICT);
        $recentErrors = $this->countMetric('api_errors', '1h');
        $recentBlocks = $this->countMetric('api_blocks', '1h');

        // Base delay
        $delay = $isStrict ? 3000 : 1000; // 3s ou 1s

        // Backoff baseado em erros
        if ($recentErrors > 10) {
            $delay = (int)min($delay * pow(1.5, min($recentErrors / 10, 5)), 30000);
        }

        // Backoff extra para bloqueios
        if ($recentBlocks > 0) {
            $delay = (int)min($delay * pow(2, min($recentBlocks, 5)), 60000);
        }

        return $delay;
    }

    /**
     * Verifica se pode executar operação (rate limit check)
     */
    public function canExecuteNow(): array
    {
        $delay = $this->getRecommendedDelay();
        $operationsLastMinute = $this->countRecentOperations(60);
        $maxPerMinute = $this->isFeatureEnabled(self::FLAG_RATE_LIMIT_STRICT) ? 5 : 20;

        if ($operationsLastMinute >= $maxPerMinute) {
            return [
                'allowed' => false,
                'reason' => "Rate limit: {$operationsLastMinute}/{$maxPerMinute} operações/min",
                'retry_after' => 60 - (time() % 60),
                'delay_ms' => $delay
            ];
        }

        return [
            'allowed' => true,
            'delay_ms' => $delay,
            'operations_this_minute' => $operationsLastMinute,
            'limit_per_minute' => $maxPerMinute
        ];
    }

    /**
     * Conta operações recentes
     */
    private function countRecentOperations(int $seconds): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM clone_health_metrics
            WHERE metric_name = 'clone_operations_started'
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL :seconds SECOND)
        ");
        $stmt->execute(['seconds' => $seconds]);
        return (int)$stmt->fetchColumn();
    }

    // =========================================================================
    // RELATÓRIOS
    // =========================================================================

    /**
     * Gera relatório diário de operações
     */
    public function generateDailyReport(string $date = null): array
    {
        $date = $date ?? date('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT
                DATE(recorded_at) as date,
                metric_name,
                SUM(metric_value) as total,
                AVG(metric_value) as average,
                COUNT(*) as count
            FROM clone_health_metrics
            WHERE DATE(recorded_at) = :date
            GROUP BY DATE(recorded_at), metric_name
        ");
        $stmt->execute(['date' => $date]);
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Alertas do dia
        $stmt = $this->db->prepare("
            SELECT alert_type, severity, COUNT(*) as count
            FROM clone_alerts
            WHERE DATE(created_at) = :date
            GROUP BY alert_type, severity
        ");
        $stmt->execute(['date' => $date]);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Clonagens do dia
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as count
            FROM cloned_items
            WHERE DATE(created_at) = :date
            GROUP BY status
        ");
        $stmt->execute(['date' => $date]);
        $clones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'date' => $date,
            'metrics' => $metrics,
            'alerts' => $alerts,
            'clones' => $clones,
            'generated_at' => date('c')
        ];
    }
}
