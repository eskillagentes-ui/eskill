<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Service para dashboard em tempo real do sistema de clonagem
 * 
 * Fornece dados atualizados via Server-Sent Events (SSE) para:
 * - Status de jobs em execução
 * - Progresso em tempo real
 * - Métricas e estatísticas
 * - Alertas ativos
 * 
 * @package App\Services
 */
class CloneRealtimeDashboardService
{
    private PDO $db;
    private const CACHE_TTL = 5; // 5 segundos
    private array $cache = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Stream SSE para dashboard em tempo real
     * 
     * Envia dados atualizados a cada 5 segundos via Server-Sent Events.
     * Chamado pelo endpoint /api/clone/dashboard/stream
     * 
     * @param int|null $accountId Filtrar por conta específica
     * @return void
     */
    public function streamDashboardData(?int $accountId = null): void
    {
        // Configurar headers SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Nginx compatibility

        // Loop infinito para enviar atualizações
        while (true) {
            $data = $this->getDashboardSnapshot($accountId);
            
            // Enviar dados via SSE
            echo "data: " . json_encode($data) . "\n\n";
            
            // Flush output buffer
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            // Verificar se cliente desconectou
            if (connection_aborted()) {
                break;
            }

            // Aguardar 5 segundos antes da próxima atualização
            sleep(5);
        }
    }

    /**
     * Obter snapshot completo do dashboard
     * 
     * @param int|null $accountId Filtrar por conta
     * @return array{
     *     timestamp: string,
     *     active_jobs: array,
     *     metrics: array,
     *     alerts: array,
     *     system_health: array
     * }
     */
    public function getDashboardSnapshot(?int $accountId = null): array
    {
        $cacheKey = 'dashboard_snapshot_' . ($accountId ?? 'all');
        
        // Verificar cache
        if ($this->isCacheValid($cacheKey)) {
            return $this->cache[$cacheKey]['data'];
        }

        $snapshot = [
            'timestamp' => date('Y-m-d H:i:s'),
            'active_jobs' => $this->getActiveJobs($accountId),
            'metrics' => $this->getRealtimeMetrics($accountId),
            'alerts' => $this->getActiveAlerts($accountId),
            'system_health' => $this->getSystemHealth($accountId),
        ];

        // Cachear resultado
        $this->cache[$cacheKey] = [
            'data' => $snapshot,
            'timestamp' => time(),
        ];

        return $snapshot;
    }

    /**
     * Obter jobs ativos em execução
     * 
     * @param int|null $accountId
     * @return array<array{
     *     job_id: int,
     *     account_id: int,
     *     account_name: string,
     *     status: string,
     *     progress: float,
     *     items_total: int,
     *     items_completed: int,
     *     items_failed: int,
     *     items_pending: int,
     *     started_at: string,
     *     elapsed_time: int,
     *     estimated_time_remaining: int|null,
     *     current_phase: string
     * }>
     */
    public function getActiveJobs(?int $accountId = null): array
    {
        $sql = "
            SELECT 
                j.id as job_id,
                j.account_id,
                a.nickname as account_name,
                j.status,
                j.items_total,
                j.items_completed,
                j.items_failed,
                j.items_pending,
                j.created_at as started_at,
                TIMESTAMPDIFF(SECOND, j.created_at, NOW()) as elapsed_time,
                j.metadata
            FROM catalog_clone_jobs j
            LEFT JOIN ml_accounts a ON j.account_id = a.id
            WHERE j.status IN ('pending', 'processing')
        ";

        $params = [];
        if ($accountId !== null) {
            $sql .= " AND j.account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $sql .= " ORDER BY j.created_at DESC LIMIT 10";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($job) {
            $progress = $job['items_total'] > 0 
                ? round(($job['items_completed'] / $job['items_total']) * 100, 2)
                : 0;

            $metadata = json_decode($job['metadata'] ?? '{}', true);
            $currentPhase = $metadata['current_phase'] ?? 'unknown';

            // Estimar tempo restante baseado no progresso atual
            $eta = null;
            if ($progress > 0 && $progress < 100) {
                $itemsPerSecond = $job['items_completed'] / max($job['elapsed_time'], 1);
                $remainingItems = $job['items_pending'];
                $eta = $itemsPerSecond > 0 ? (int)($remainingItems / $itemsPerSecond) : null;
            }

            return [
                'job_id' => (int)$job['job_id'],
                'account_id' => (int)$job['account_id'],
                'account_name' => $job['account_name'] ?? 'Unknown',
                'status' => $job['status'],
                'progress' => $progress,
                'items_total' => (int)$job['items_total'],
                'items_completed' => (int)$job['items_completed'],
                'items_failed' => (int)$job['items_failed'],
                'items_pending' => (int)$job['items_pending'],
                'started_at' => $job['started_at'],
                'elapsed_time' => (int)$job['elapsed_time'],
                'estimated_time_remaining' => $eta,
                'current_phase' => $currentPhase,
            ];
        }, $jobs);
    }

    /**
     * Obter métricas em tempo real
     * 
     * @param int|null $accountId
     * @return array{
     *     last_24h: array,
     *     last_hour: array,
     *     current_rate: array
     * }
     */
    public function getRealtimeMetrics(?int $accountId = null): array
    {
        $params = [];
        $accountFilter = '';
        if ($accountId !== null) {
            $accountFilter = " AND account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        // Métricas últimas 24h
        $sql24h = "
            SELECT 
                COUNT(*) as total_jobs,
                SUM(items_completed) as items_cloned,
                SUM(items_failed) as items_failed,
                AVG(CASE WHEN status = 'completed' THEN 
                    TIMESTAMPDIFF(SECOND, created_at, completed_at) 
                END) as avg_duration_seconds
            FROM catalog_clone_jobs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            {$accountFilter}
        ";

        $stmt24h = $this->db->prepare($sql24h);
        $stmt24h->execute($params);
        $metrics24h = $stmt24h->fetch(PDO::FETCH_ASSOC);

        // Métricas última hora
        $sql1h = "
            SELECT 
                COUNT(*) as total_jobs,
                SUM(items_completed) as items_cloned,
                SUM(items_failed) as items_failed
            FROM catalog_clone_jobs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            {$accountFilter}
        ";

        $stmt1h = $this->db->prepare($sql1h);
        $stmt1h->execute($params);
        $metrics1h = $stmt1h->fetch(PDO::FETCH_ASSOC);

        // Taxa atual (últimos 5 minutos)
        $sqlRate = "
            SELECT 
                COUNT(*) as jobs_count,
                SUM(items_completed) as items_count
            FROM catalog_clone_jobs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            {$accountFilter}
        ";

        $stmtRate = $this->db->prepare($sqlRate);
        $stmtRate->execute($params);
        $rate = $stmtRate->fetch(PDO::FETCH_ASSOC);

        return [
            'last_24h' => [
                'total_jobs' => (int)($metrics24h['total_jobs'] ?? 0),
                'items_cloned' => (int)($metrics24h['items_cloned'] ?? 0),
                'items_failed' => (int)($metrics24h['items_failed'] ?? 0),
                'avg_duration_seconds' => $metrics24h['avg_duration_seconds'] 
                    ? round($metrics24h['avg_duration_seconds'], 2) 
                    : null,
                'success_rate' => $this->calculateSuccessRate($accountId, 24),
            ],
            'last_hour' => [
                'total_jobs' => (int)($metrics1h['total_jobs'] ?? 0),
                'items_cloned' => (int)($metrics1h['items_cloned'] ?? 0),
                'items_failed' => (int)($metrics1h['items_failed'] ?? 0),
                'success_rate' => $this->calculateSuccessRate($accountId, 1),
            ],
            'current_rate' => [
                'jobs_per_minute' => round(($rate['jobs_count'] ?? 0) / 5, 2),
                'items_per_minute' => round(($rate['items_count'] ?? 0) / 5, 2),
            ],
        ];
    }

    /**
     * Obter alertas ativos
     * 
     * @param int|null $accountId
     * @return array<array{
     *     alert_id: int,
     *     type: string,
     *     severity: string,
     *     message: string,
     *     created_at: string,
     *     job_id: int|null
     * }>
     */
    public function getActiveAlerts(?int $accountId = null): array
    {
        $sql = "
            SELECT 
                id as alert_id,
                type,
                severity,
                message,
                created_at,
                job_id
            FROM clone_alerts
            WHERE resolved = 0
        ";

        $params = [];
        if ($accountId !== null) {
            $sql .= " AND account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $sql .= " ORDER BY 
            FIELD(severity, 'critical', 'warning', 'info'),
            created_at DESC
        LIMIT 10";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($alert) {
            return [
                'alert_id' => (int)$alert['alert_id'],
                'type' => $alert['type'],
                'severity' => $alert['severity'],
                'message' => $alert['message'],
                'created_at' => $alert['created_at'],
                'job_id' => $alert['job_id'] ? (int)$alert['job_id'] : null,
            ];
        }, $alerts);
    }

    /**
     * Obter saúde geral do sistema
     * 
     * @param int|null $accountId
     * @return array{
     *     status: string,
     *     issues: array,
     *     metrics: array
     * }
     */
    public function getSystemHealth(?int $accountId = null): array
    {
        $issues = [];
        $status = 'healthy';

        // Verificar jobs travados
        $stuckJobs = $this->countStuckJobs($accountId);
        if ($stuckJobs > 0) {
            $issues[] = [
                'type' => 'stuck_jobs',
                'count' => $stuckJobs,
                'severity' => 'warning',
                'message' => "{$stuckJobs} job(s) sem progresso há mais de 30 minutos",
            ];
            $status = 'degraded';
        }

        // Verificar taxa de falha alta
        $failureRate = $this->calculateSuccessRate($accountId, 1);
        if ($failureRate !== null && $failureRate < 80) {
            $issues[] = [
                'type' => 'high_failure_rate',
                'rate' => round(100 - $failureRate, 2),
                'severity' => $failureRate < 50 ? 'critical' : 'warning',
                'message' => "Taxa de falha de " . round(100 - $failureRate, 2) . "% na última hora",
            ];
            $status = $failureRate < 50 ? 'critical' : 'degraded';
        }

        // Verificar alertas críticos
        $criticalAlerts = $this->countAlertsBySeverity('critical', $accountId);
        if ($criticalAlerts > 0) {
            $issues[] = [
                'type' => 'critical_alerts',
                'count' => $criticalAlerts,
                'severity' => 'critical',
                'message' => "{$criticalAlerts} alerta(s) crítico(s) ativo(s)",
            ];
            $status = 'critical';
        }

        return [
            'status' => $status,
            'issues' => $issues,
            'metrics' => [
                'stuck_jobs' => $stuckJobs,
                'failure_rate_pct' => $failureRate !== null ? round(100 - $failureRate, 2) : 0,
                'critical_alerts' => $criticalAlerts,
            ],
        ];
    }

    /**
     * Calcular taxa de sucesso
     * 
     * @param int|null $accountId
     * @param int $hours Período em horas
     * @return float|null Taxa de sucesso em % ou null se não houver dados
     */
    private function calculateSuccessRate(?int $accountId, int $hours): ?float
    {
        $sql = "
            SELECT 
                SUM(items_completed) as completed,
                SUM(items_failed) as failed
            FROM catalog_clone_jobs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
        ";

        $params = ['hours' => $hours];
        if ($accountId !== null) {
            $sql .= " AND account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $completed = (int)($result['completed'] ?? 0);
        $failed = (int)($result['failed'] ?? 0);
        $total = $completed + $failed;

        if ($total === 0) {
            return null;
        }

        return ($completed / $total) * 100;
    }

    /**
     * Contar jobs travados
     * 
     * @param int|null $accountId
     * @return int
     */
    private function countStuckJobs(?int $accountId): int
    {
        $sql = "
            SELECT COUNT(*) as count
            FROM catalog_clone_jobs
            WHERE status IN ('pending', 'processing')
            AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ";

        $params = [];
        if ($accountId !== null) {
            $sql .= " AND account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($result['count'] ?? 0);
    }

    /**
     * Contar alertas por severidade
     * 
     * @param string $severity 'critical', 'warning', 'info'
     * @param int|null $accountId
     * @return int
     */
    private function countAlertsBySeverity(string $severity, ?int $accountId): int
    {
        $sql = "
            SELECT COUNT(*) as count
            FROM clone_alerts
            WHERE severity = :severity
            AND resolved = 0
        ";

        $params = ['severity' => $severity];
        if ($accountId !== null) {
            $sql .= " AND account_id = :account_id";
            $params['account_id'] = $accountId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($result['count'] ?? 0);
    }

    /**
     * Verificar se cache é válido
     * 
     * @param string $key
     * @return bool
     */
    private function isCacheValid(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        $age = time() - $this->cache[$key]['timestamp'];
        return $age < self::CACHE_TTL;
    }

    /**
     * Obter dados para widget de progresso
     * 
     * @param int $jobId
     * @return array{
     *     job_id: int,
     *     progress: float,
     *     current_phase: string,
     *     phases: array,
     *     eta_seconds: int|null
     * }
     */
    public function getJobProgress(int $jobId): array
    {
        $sql = "
            SELECT 
                id,
                status,
                items_total,
                items_completed,
                items_failed,
                items_pending,
                created_at,
                metadata
            FROM catalog_clone_jobs
            WHERE id = :job_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['job_id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            return [
                'job_id' => $jobId,
                'progress' => 0,
                'current_phase' => 'not_found',
                'phases' => [],
                'eta_seconds' => null,
            ];
        }

        $metadata = json_decode($job['metadata'] ?? '{}', true);
        $progress = $job['items_total'] > 0 
            ? round(($job['items_completed'] / $job['items_total']) * 100, 2)
            : 0;

        // Calcular ETA
        $elapsed = strtotime('now') - strtotime($job['created_at']);
        $eta = null;
        if ($progress > 0 && $progress < 100) {
            $itemsPerSecond = $job['items_completed'] / max($elapsed, 1);
            $eta = $itemsPerSecond > 0 
                ? (int)($job['items_pending'] / $itemsPerSecond) 
                : null;
        }

        return [
            'job_id' => (int)$job['id'],
            'progress' => $progress,
            'current_phase' => $metadata['current_phase'] ?? 'processing',
            'phases' => $metadata['phases'] ?? [],
            'eta_seconds' => $eta,
        ];
    }
}
