<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\CatalogCloneMonitoringService;
use App\Services\FeatureFlagService;
use App\Services\LoggingService;
use App\Services\NotificationService;
use App\Services\AdvancedMonitoringService;
use App\Services\MonitoringAlertNotificationService;

/**
 * Controller para Dashboard de Monitoramento do Sistema V8.1
 * Inclui monitoramento avançado de sistema e clonagem
 */
class MonitoringController extends BaseController
{
    private CatalogCloneMonitoringService $monitoring;
    private FeatureFlagService $featureFlags;
    private LoggingService $logger;
    private NotificationService $notifications;
    private AdvancedMonitoringService $advancedMonitoring;
    private MonitoringAlertNotificationService $monitoringAlertNotifier;

    public function __construct()
    {
        parent::__construct();

        $this->monitoring = new CatalogCloneMonitoringService();
        $this->featureFlags = new FeatureFlagService();
        $this->logger = new LoggingService();
        $this->notifications = new NotificationService();
        $this->advancedMonitoring = new AdvancedMonitoringService();
        $this->monitoringAlertNotifier = new MonitoringAlertNotificationService();

        // Verificar se usuário tem permissão de admin
        if (!$this->isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Acesso negado']);
            exit;
        }
    }

    /**
     * Dashboard principal de monitoramento
     */
    public function dashboard(): void
    {
        require_once __DIR__ . '/../Views/monitoring/dashboard.php';
    }

    /**
     * API: Métricas em tempo real
     */
    public function realTimeMetrics(): void
    {
        header('Content-Type: application/json');

        try {
            $metrics = $this->monitoring->getRealTimeMetrics();
            echo json_encode([
                'success' => true,
                'data' => $metrics,
                'timestamp' => time()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao obter métricas: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * API: Verificar alertas
     */
    public function checkAlerts(): void
    {
        header('Content-Type: application/json');

        try {
            $alerts = $this->monitoring->checkAlerts();
            $logAlerts = $this->logger->checkLogAlerts();
            $notificationDispatch = $this->monitoringAlertNotifier->dispatchMlOperationalAlerts($alerts);

            $allAlerts = array_merge($alerts, $logAlerts);

            echo json_encode([
                'success' => true,
                'alerts' => $allAlerts,
                'count' => count($allAlerts),
                'notification_dispatch' => $notificationDispatch,
                'timestamp' => time()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao verificar alertas: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * API: Relatório de performance
     */
    public function performanceReport(): void
    {
        header('Content-Type: application/json');

        $days = $this->request->getInt('days', 7);

        try {
            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            $endDate = date('Y-m-d');
            $report = $this->monitoring->getPerformanceReport($startDate, $endDate);
            $dailyStats = $report['daily_stats'] ?? [];
            $hourlyStats = array_map(static function (array $day): array {
                return [
                    'hour_label' => $day['date'] ?? '',
                    'completed_jobs' => (int)($day['completed'] ?? 0),
                    'failed_jobs' => (int)($day['failed'] ?? 0),
                ];
            }, $dailyStats);

            echo json_encode([
                'success' => true,
                'data' => array_merge($report, [
                    // Alias de compatibilidade para dashboard legado
                    'hourly_stats' => $hourlyStats,
                ]),
                'period_days' => $days,
                'timestamp' => time()
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao gerar relatório: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * API: Gerenciar feature flags
     */
    public function featureFlags(): void
    {
        header('Content-Type: application/json');

        if ($this->request->method() === 'GET') {
            // Listar flags
            $flags = $this->featureFlags->getAllFlags();
            echo json_encode([
                'success' => true,
                'data' => $flags
            ]);
        } elseif ($this->request->method() === 'POST') {
            // Atualizar flag
            $input = $this->request->json();

            if (!isset($input['flag_name']) || !isset($input['enabled'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Parâmetros inválidos']);
                return;
            }

            $success = $this->featureFlags->setEnabled($input['flag_name'], (bool)$input['enabled']);

            if ($success) {
                $this->logger->info(
                    LoggingService::CATEGORY_SYSTEM,
                    'Feature flag alterada',
                    [
                        'flag' => $input['flag_name'],
                        'enabled' => (bool)$input['enabled'],
                        'user_id' => $this->getUserId()
                    ]
                );

                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao atualizar flag']);
            }
        }
    }

    /**
     * API: Logs do sistema
     */
    public function systemLogs(): void
    {
        header('Content-Type: application/json');

        $filters = [
            'level' => $this->request->get('level'),
            'category' => $this->request->get('category'),
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
            'message' => $this->request->get('message')
        ];

        // Remove filtros vazios
        $filters = array_filter($filters);

        $limit = $this->request->getIntClamped('limit', 50, 1, 500);
        $offset = $this->request->getInt('offset', 0);

        try {
            $logs = $this->logger->searchLogs($filters, $limit, $offset);

            echo json_encode([
                'success' => true,
                'data' => $logs,
                'filters' => $filters,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'returned' => count($logs)
                ]
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao buscar logs: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * API: Status de saúde do sistema
     */
    public function healthCheck(): void
    {
        header('Content-Type: application/json');

        $health = [
            'status' => 'ok',
            'timestamp' => time(),
            'checks' => []
        ];

        try {
            // Verificar DB
            $dbCheck = $this->checkDatabase();
            $health['checks']['database'] = $dbCheck;

            // Verificar feature flags
            $flagsCheck = $this->checkFeatureFlags();
            $health['checks']['feature_flags'] = $flagsCheck;

            // Verificar jobs em fila
            $queueCheck = $this->checkJobQueue();
            $health['checks']['job_queue'] = $queueCheck;

            // Status geral
            $allOk = $dbCheck['status'] === 'ok' &&
                $flagsCheck['status'] === 'ok' &&
                $queueCheck['status'] === 'ok';

            if (!$allOk) {
                $health['status'] = 'degraded';
                http_response_code(503);
            }

            echo json_encode($health);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => time()
            ]);
        }
    }

    /**
     * Verifica saúde do banco de dados
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            $this->monitoring->getRealTimeMetrics();
            $responseTime = (microtime(true) - $start) * 1000;

            return [
                'status' => 'ok',
                'response_time_ms' => round($responseTime, 2)
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica feature flags
     */
    private function checkFeatureFlags(): array
    {
        try {
            $flags = $this->featureFlags->getAllFlags();

            return [
                'status' => 'ok',
                'flags_count' => count($flags),
                'cloning_enabled' => $this->featureFlags->isCloningEnabled()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica fila de jobs
     */
    private function checkJobQueue(): array
    {
        try {
            $metrics = $this->monitoring->getRealTimeMetrics();
            $pending = (int)($metrics['basic_stats']['pending_jobs'] ?? $metrics['clone_jobs']['pending'] ?? 0);

            return [
                'status' => $pending > 100 ? 'warning' : 'ok',
                'pending_jobs' => $pending,
                'message' => $pending > 100 ? 'Muitos jobs pendentes' : 'Fila normal'
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * API: Estatísticas resumidas de jobs
     */
    public function jobStats(): void
    {
        header('Content-Type: application/json');

        try {
            $metrics = $this->monitoring->getRealTimeMetrics();
            $basic = $metrics['basic_stats'] ?? [];

            $pending = (int)($basic['pending_jobs'] ?? 0);
            $completed = (int)($basic['completed_jobs'] ?? 0);
            $failed = (int)($basic['failed_jobs'] ?? 0);
            $total = (int)($basic['total_jobs'] ?? ($pending + $completed + $failed));
            $successRate = (float)($basic['success_rate'] ?? ($completed + $failed > 0
                ? round(($completed / ($completed + $failed)) * 100, 1)
                : 100.0));

            echo json_encode([
                'success' => true,
                'data' => [
                    'total_jobs' => $total,
                    'pending_jobs' => $pending,
                    'completed_jobs' => $completed,
                    'failed_jobs' => $failed,
                    'success_rate' => $successRate,
                    'avg_processing_time' => 0,
                    'jobs_per_hour' => 0,
                ],
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao obter estatísticas de jobs: ' . $e->getMessage(),
            ]);
        }
    }

    // ===== ADVANCED MONITORING V8.1 METHODS =====

    /**
     * Advanced System Metrics API
     * GET /api/monitoring/system-metrics
     */
    public function systemMetrics(): void
    {
        header('Content-Type: application/json');

        try {
            $metrics = $this->advancedMonitoring->collectSystemMetrics();
            echo json_encode([
                'success' => true,
                'metrics' => $metrics,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * System Health Check API
     * GET /api/monitoring/health-advanced
     */
    public function healthAdvanced(): void
    {
        header('Content-Type: application/json');

        $health = $this->advancedMonitoring->getHealthStatus();

        if ($health['status'] === 'critical') {
            http_response_code(503); // Service Unavailable
        } elseif ($health['status'] === 'poor') {
            http_response_code(429); // Too Many Requests
        } else {
            http_response_code(200);
        }

        echo json_encode($health);
    }

    /**
     * System Alerts API
     * GET /api/monitoring/system-alerts
     */
    public function systemAlerts(): void
    {
        header('Content-Type: application/json');

        try {
            $alerts = $this->advancedMonitoring->getActiveAlerts();
            echo json_encode([
                'success' => true,
                'alerts' => $alerts,
                'count' => count($alerts)
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Performance Analytics API
     * GET /api/monitoring/performance-advanced
     */
    public function performanceAdvanced(): void
    {
        header('Content-Type: application/json');

        try {
            $timeframe = $this->request->get('timeframe', '1 hour');
            $analytics = $this->advancedMonitoring->getPerformanceAnalytics($timeframe);

            echo json_encode([
                'success' => true,
                'analytics' => $analytics,
                'timeframe' => $timeframe
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Log Request Performance (middleware)
     */
    public function logRequestPerformance(array $requestData): void
    {
        try {
            $this->advancedMonitoring->logRequestPerformance($requestData);
        } catch (\Exception $e) {
            // Silent fail for performance logging
            log_warning('Falha ao registrar performance de requisição', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Comprehensive Performance Metrics API (Phase 4)
     * GET /api/monitoring/metrics
     *
     * Formato consumido por app/Views/dashboard/monitoring.php (chaves no
     * nível raiz: cpu/memory/disk/db/cache/requests_by_hour/response_times/
     * recent_errors). Antes desta correção o payload real era
     * {success, metrics: {cache, queue, llm, database, system}} — o front
     * lia data.cpu/data.memory/data.disk/data.db/... diretamente (undefined)
     * e mostrava tudo zerado (CPU 0%, Memória 0%, Disco 0%, DB 0 conexões).
     */
    public function metrics(): void
    {
        header('Content-Type: application/json');

        try {
            $system = $this->advancedMonitoring->collectSystemMetrics();
            $metricsService = new \App\Services\PerformanceMetricsService();
            $raw = $metricsService->getMetrics();
            $cache = $raw['cache'] ?? [];
            $database = $raw['database'] ?? [];

            $queriesPerSec = (float)($database['queries_per_sec'] ?? 0);

            echo json_encode([
                'success' => true,
                'cpu' => (float)($system['cpu_usage'] ?? 0),
                'memory' => (float)($system['memory_usage'] ?? 0),
                'disk' => (float)($system['disk_usage'] ?? 0),
                'uptime' => isset($database['uptime_seconds'])
                    ? $this->formatUptime((int)$database['uptime_seconds'])
                    : '-',
                'last_restart' => null,
                'db' => [
                    'connections' => (int)($system['db_connections'] ?? 0),
                    'queries_per_min' => (int)round($queriesPerSec * 60),
                    'slow_queries' => (int)($system['db_slow_queries'] ?? 0),
                    'size' => round((float)($system['db_size_mb'] ?? 0), 1) . ' MB',
                ],
                'cache' => [
                    'hit_rate' => round((float)($cache['hit_rate'] ?? 0) * 100, 1),
                    'items' => (int)($cache['total_keys'] ?? 0),
                    'memory' => $cache['used_memory_human'] ?? '0 MB',
                ],
                'requests_by_hour' => $this->requestsByHourLast24h(),
                'response_times' => $this->responseTimesLast24h(),
                'recent_errors' => $this->recentSystemAlerts(),
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function formatUptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        if ($days > 0) {
            return "{$days}d {$hours}h";
        }
        $minutes = intdiv($seconds % 3600, 60);

        return "{$hours}h {$minutes}min";
    }

    /** @return list<array{hour: string, count: int}> */
    private function requestsByHourLast24h(): array
    {
        try {
            $db = \App\Database::getInstance();
            $stmt = $db->query("
                SELECT DATE_FORMAT(timestamp, '%H:00') as hour, COUNT(*) as count
                FROM performance_logs
                WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY hour
                ORDER BY hour ASC
            ");

            return array_map(
                static fn (array $row): array => ['hour' => (string)$row['hour'], 'count' => (int)$row['count']],
                $stmt->fetchAll(\PDO::FETCH_ASSOC)
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array{time: string, avg: float}> */
    private function responseTimesLast24h(): array
    {
        try {
            $db = \App\Database::getInstance();
            $stmt = $db->query("
                SELECT DATE_FORMAT(timestamp, '%H:00') as time, AVG(response_time) as avg
                FROM performance_logs
                WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY time
                ORDER BY time ASC
            ");

            return array_map(
                static fn (array $row): array => ['time' => (string)$row['time'], 'avg' => round((float)$row['avg'], 1)],
                $stmt->fetchAll(\PDO::FETCH_ASSOC)
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array{message: string, time: string}> */
    private function recentSystemAlerts(): array
    {
        try {
            $db = \App\Database::getInstance();
            $stmt = $db->query("
                SELECT title, message, created_at
                FROM system_alerts
                WHERE status = 'active'
                ORDER BY created_at DESC
                LIMIT 10
            ");

            return array_map(
                static fn (array $row): array => [
                    'message' => (string)$row['title'] . ': ' . (string)$row['message'],
                    'time' => (string)$row['created_at'],
                ],
                $stmt->fetchAll(\PDO::FETCH_ASSOC)
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Limpa métricas antigas
     */
    public function clean(): void
    {
        $days = $this->request->getInt('days', 30);

        $deleted = $this->advancedMonitoring->cleanOldMetrics($days);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'deleted' => $deleted,
            'days' => $days,
        ]);
    }
}
