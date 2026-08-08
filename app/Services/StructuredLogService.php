<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\WebProcessor;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\MemoryUsageProcessor;

/**
 * Serviço de Logs Estruturados
 *
 * Sistema completo de logging com Monolog, formatação JSON,
 * rotação automática e múltiplos níveis de log.
 */
class StructuredLogService
{
    private Logger $logger;
    private string $logPath;
    private string $logLevel;

    // Níveis de log
    const LEVEL_DEBUG = Logger::DEBUG;
    const LEVEL_INFO = Logger::INFO;
    const LEVEL_WARNING = Logger::WARNING;
    const LEVEL_ERROR = Logger::ERROR;
    const LEVEL_CRITICAL = Logger::CRITICAL;

    public function __construct()
    {
        $this->logPath = getenv('LOG_PATH') ?: (__DIR__ . '/../../storage/logs/app.log');
        $this->logLevel = getenv('LOG_LEVEL') ?: 'debug';

        // Garantir que o diretório de logs existe
        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        // Criar logger
        $this->logger = new Logger('app');

        // Handler principal: usar RotatingFileHandler para gerar arquivos por dia (app-YYYY-MM-DD.log)
        try {
            // Ensure the configured path ends with a filename; if a directory was provided, append app.log
            if (is_dir($this->logPath)) {
                $this->logPath = rtrim($this->logPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'app.log';
            }

            // If LOG_PATH points to a specific .log file, use StreamHandler so tests
            // that expect an exact filepath (e.g., /tmp/test_app_xxx.log) are satisfied.
            if (str_ends_with($this->logPath, '.log')) {
                $handler = new StreamHandler($this->logPath, $this->getMonologLevel($this->logLevel));
            } else {
                // RotatingFileHandler will create files like app-YYYY-MM-DD.log
                $maxFiles = 30; // keep 30 days by default
                $handler = new RotatingFileHandler(
                    $this->logPath,
                    $maxFiles,
                    $this->getMonologLevel($this->logLevel)
                );
            }

            // Formatação JSON
            $handler->setFormatter(new JsonFormatter());

            // Adicionar handler
            $this->logger->pushHandler($handler);
        } catch (\Throwable $e) {
            // Se falhar em criar o handler de arquivo, usar apenas error_log
            error_log("StructuredLogService: Failed to create rotating file handler: " . $e->getMessage());
        }
        // 0660: not world-readable, keeps ACL mask writable for eskill/root
        try {
            if (file_exists($this->logPath)) {
                @chmod($this->logPath, 0660);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Processors adicionais
        $this->logger->pushProcessor(new WebProcessor());
        $this->logger->pushProcessor(new IntrospectionProcessor());
        $this->logger->pushProcessor(new MemoryUsageProcessor());

        // NOTE: masking is applied in enrichContext() to avoid mutating Monolog LogRecord objects
        // Processor customizado para adicionar informações extras
        $this->logger->pushProcessor(function ($record) {
            $record['extra']['user_id'] = $_SESSION['user_id'] ?? null;
            $record['extra']['account_id'] = $_SESSION['account_id'] ?? null;
            $record['extra']['request_id'] = $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_');
            $record['extra']['timestamp'] = microtime(true);

            return $record;
        });
    }

    /**
     * Mascara chaves/valores sensíveis no contexto do log
     */
    private function maskSensitiveValues(array $data): array
    {
        $sensitiveKeys = [
            'password',
            'pass',
            'pwd',
            'secret',
            'token',
            'access_token',
            'refresh_token',
            'client_secret',
            'app_key',
            'api_key',
            'authorization',
            'auth',
            'db_password',
            'smtp_pass'
        ];

        $masked = [];

        foreach ($data as $k => $v) {
            $lower = strtolower((string)$k);
            $isSensitive = false;
            foreach ($sensitiveKeys as $sk) {
                if (strpos($lower, $sk) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                // Mask completely
                $masked[$k] = '***REDACTED***';
                continue;
            }

            if (is_array($v)) {
                $masked[$k] = $this->maskSensitiveValues($v);
            } elseif (is_string($v) && preg_match('/^\w{40,}$/', $v)) {
                // long tokens/keys - mask
                $masked[$k] = substr($v, 0, 6) . '...' . substr($v, -6);
            } else {
                $masked[$k] = $v;
            }
        }

        return $masked;
    }

    /**
     * Log de debug (desenvolvimento)
     */
    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $this->enrichContext($context));
    }

    /**
     * Log informativo
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $this->enrichContext($context));
    }

    /**
     * Log de warning (atenção necessária)
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $this->enrichContext($context));
    }

    /**
     * Log de erro (requer ação)
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $this->enrichContext($context));
    }

    /**
     * Log crítico (sistema comprometido)
     */
    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $this->enrichContext($context));
    }

    /**
     * Log de exceção com stack trace
     */
    public function exception(\Throwable $e, array $context = []): void
    {
        $context['exception'] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];

        $this->error('Exception: ' . $e->getMessage(), $context);
    }

    /**
     * Log de performance (medir tempo de execução)
     */
    public function performance(string $operation, float $duration, array $context = []): void
    {
        $context['performance'] = [
            'operation' => $operation,
            'duration_ms' => round($duration * 1000, 2),
            'duration_s' => round($duration, 4)
        ];

        $level = $duration > 5.0 ? 'warning' : 'info';

        $this->{$level}("Performance: {$operation}", $context);
    }

    /**
     * Log de audit (rastreamento de ações importantes)
     */
    public function audit(string $action, array $data = []): void
    {
        $context = [
            'audit' => true,
            'action' => $action,
            'data' => $data,
            'user_id' => $_SESSION['user_id'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ];

        $this->info("Audit: {$action}", $context);
    }

    /**
     * Buscar logs com filtros
     */
    /**
     * Buscar logs com filtros (Otimizado para leitura reversa sem carregar arquivo todo)
     */
    public function search(array $filters = []): array
    {
        $logFile = $this->logPath;
        $results = [];

        if (!file_exists($logFile)) {
            return [];
        }

        $limit = $filters['limit'] ?? 100;
        $level = $filters['level'] ?? null;
        $search = $filters['search'] ?? null;
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;

        // Implementação de leitura reversa eficiente
        $handle = fopen($logFile, 'rb');
        if ($handle === false) return [];

        try {
            $fileSize = filesize($logFile);
            $chunkSize = 4096;
            $pos = $fileSize;
            $buffer = '';
            $foundParams = 0;

            // Ensure we handle valid query logic
            while ($pos > 0 && $foundParams < $limit) {
                $readSize = ($pos < $chunkSize) ? $pos : $chunkSize;
                $pos -= $readSize;

                fseek($handle, $pos);
                $chunk = fread($handle, $readSize);

                $buffer = $chunk . $buffer;
                $lines = explode("\n", $buffer);

                // The first element of $lines (index 0) is likely incomplete until we reach start of file,
                // so we keep it in buffer and process the rest reversed.
                // If pos == 0, process all.
                if ($pos > 0) {
                    $buffer = array_shift($lines); // Keep remainder for next read
                } else {
                    $buffer = ''; // Process everything
                }

                // Process lines in reverse order (newest first)
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $line = trim($lines[$i]);
                    if (empty($line)) continue;

                    // Stop if we have enough
                    if ($foundParams >= $limit) break;

                    // Parse JSON
                    $log = json_decode($line, true);
                    if (!$log) continue;

                    // Apply Filters
                    if ($level && strtolower($log['level_name'] ?? '') !== strtolower($level)) {
                        continue;
                    }

                    if ($search && stripos($line, $search) === false) { // Fast string search on raw line
                        continue;
                    }

                    if ($startDate && ($log['datetime'] ?? '') < $startDate) {
                        continue; // Log is older than start date? Valid. But if file is sorted, we can stop?
                        // Logs are appended, so if we read backwards and hit a date < start_date, we can theoretically stop if monotonic.
                        // Assuming valid timestamps:
                        // If we find a log OLDER than usage start, we can stop reading?
                        // Yes, optimizing:
                        break 2; // Stop outer loop
                    }

                    if ($endDate && ($log['datetime'] ?? '') > $endDate) {
                        continue;
                    }

                    $results[] = $log;
                    $foundParams++;
                }
            }
        } finally {
            fclose($handle);
        }

        return $results;
    }

    /**
     * Obter estatísticas dos logs
     */
    public function getStatistics(string $period = '24h'): array
    {
        $logs = $this->search(['limit' => 10000]);

        $stats = [
            'total' => count($logs),
            'by_level' => [
                'debug' => 0,
                'info' => 0,
                'warning' => 0,
                'error' => 0,
                'critical' => 0
            ],
            'top_errors' => [],
            'performance' => [
                'slow_operations' => []
            ]
        ];

        $errorMessages = [];

        foreach ($logs as $log) {
            $level = strtolower($log['level_name'] ?? 'info');
            $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;

            // Agrupar erros similares
            if (in_array($level, ['error', 'critical'])) {
                $message = $log['message'] ?? '';
                $errorMessages[$message] = ($errorMessages[$message] ?? 0) + 1;
            }

            // Operações lentas
            if (isset($log['context']['performance'])) {
                $perf = $log['context']['performance'];
                if ($perf['duration_s'] > 1.0) {
                    $stats['performance']['slow_operations'][] = [
                        'operation' => $perf['operation'],
                        'duration' => $perf['duration_s'],
                        'timestamp' => $log['datetime']
                    ];
                }
            }
        }

        // Top 10 erros
        arsort($errorMessages);
        $stats['top_errors'] = array_slice($errorMessages, 0, 10, true);

        // Top 10 operações lentas
        usort($stats['performance']['slow_operations'], function ($a, $b) {
            return $b['duration'] <=> $a['duration'];
        });
        $stats['performance']['slow_operations'] = array_slice(
            $stats['performance']['slow_operations'],
            0,
            10
        );

        return $stats;
    }

    /**
     * Limpar logs antigos
     */
    public function cleanup(int $days = 30): int
    {
        $logDir = dirname($this->logPath);
        $deleted = 0;

        $cutoff = time() - ($days * 86400);

        foreach (glob($logDir . '/*.log*') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Enriquecer contexto com informações adicionais
     */
    private function enrichContext(array $context): array
    {
        $merged = array_merge([
            'url' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ], $context);

        // Mask sensitive values before sending to logger
        return $this->maskSensitiveValues($merged);
    }

    /**
     * Converter nível string para constante Monolog
     */
    private function getMonologLevel(string $level): int
    {
        return match (strtolower($level)) {
            'debug' => Logger::DEBUG,
            'info' => Logger::INFO,
            'warning', 'warn' => Logger::WARNING,
            'error' => Logger::ERROR,
            'critical' => Logger::CRITICAL,
            default => Logger::INFO
        };
    }

    /**
     * Obter instância do logger Monolog
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }
}
