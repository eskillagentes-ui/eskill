<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Lightweight PSR-3 logger used by the legacy facade and unit tests.
 *
 * It writes one daily file per channel and mirrors error-level events to a
 * shared error file. The implementation stays intentionally small so it can
 * operate even when the richer structured logger is unavailable.
 */
class LoggerService extends AbstractLogger
{
    /**
     * Severity order used to filter entries below the configured minimum level.
     *
     * @var array<string,int>
     */
    private const LEVEL_PRIORITY = [
        LogLevel::DEBUG => 100,
        LogLevel::INFO => 200,
        LogLevel::NOTICE => 250,
        LogLevel::WARNING => 300,
        LogLevel::ERROR => 400,
        LogLevel::CRITICAL => 500,
        LogLevel::ALERT => 550,
        LogLevel::EMERGENCY => 600,
    ];

    private string $channel;
    private string $logDirectory;
    private string $minLevel;
    private bool $jsonFormat;

    /**
     * Builds a logger instance for a given channel.
     *
     * @param string      $channel    Logical channel name, such as `app` or `api`.
     * @param string|null $logPath    Directory path or `.log` file path used as base.
     * @param string      $minLevel   Minimum PSR-3 level accepted by this logger.
     * @param bool        $jsonFormat When true, each line is written as JSON.
     */
    public function __construct(
        string $channel = 'app',
        ?string $logPath = null,
        string $minLevel = LogLevel::DEBUG,
        bool $jsonFormat = false
    ) {
        $this->channel = $this->sanitizeChannel($channel);
        $this->logDirectory = $this->resolveLogDirectory($logPath);
        $this->minLevel = $this->normalizeLevel($minLevel);
        $this->jsonFormat = $jsonFormat;

        $this->ensureDirectoryExists($this->logDirectory);
    }

    /**
     * Creates a logger for the provided channel using the configured log path.
     */
    public static function channel(string $channel): self
    {
        $configuredPath = getenv('LOG_PATH');
        $logPath = is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : null;
        $minLevel = (string) (getenv('LOG_LEVEL') ?: LogLevel::DEBUG);

        return new self($channel, $logPath, $minLevel, false);
    }

    /**
     * Writes a PSR-3 log entry when it matches the minimum configured level.
     *
     * @param string|Stringable $level
     * @param string|Stringable $message
     * @param array<string,mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $normalizedLevel = $this->normalizeLevel((string) $level);
        if (!$this->shouldLog($normalizedLevel)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $renderedMessage = $this->interpolate((string) $message, $context);
        $normalizedContext = $this->normalizeContext($context);
        $line = $this->formatLine($timestamp, $normalizedLevel, $renderedMessage, $normalizedContext);

        $this->appendLine($this->channelFilePath(), $line);

        if ($this->shouldMirrorToErrorFile($normalizedLevel)) {
            $this->appendLine($this->errorFilePath(), $line);
        }
    }

    /**
     * Records a summarized API call with optional response metadata.
     *
     * @param array<string,mixed>      $params
     * @param array<string,mixed>|null $response
     */
    public function logApiRequest(
        string $method,
        string $endpoint,
        array $params = [],
        ?array $response = null,
        ?float $duration = null
    ): void {
        $context = [
            'method' => strtoupper($method),
            'endpoint' => $endpoint,
            'params' => $params,
            'response' => $response,
            'duration_ms' => $duration !== null ? round($duration * 1000, 2) : null,
        ];

        $this->info('API Request', $context);
    }

    /**
     * Records a relevant user action in the current channel.
     *
     * @param array<string,mixed> $details
     */
    public function logUserAction(string $action, array $details = []): void
    {
        $this->info('User Action', [
            'action' => $action,
            'details' => $details,
        ]);
    }

    /**
     * Records a measured operation with execution time metadata.
     *
     * @param array<string,mixed> $metadata
     */
    public function logPerformance(string $operation, float $duration, array $metadata = []): void
    {
        $this->info('Performance', [
            'operation' => $operation,
            'duration_ms' => round($duration * 1000, 2),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Returns the current channel name.
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * Returns the directory where the logger stores files.
     */
    public function getLogDirectory(): string
    {
        return $this->logDirectory;
    }

    /**
     * Resolves the file path for the current channel and day.
     */
    private function channelFilePath(): string
    {
        return $this->logDirectory . DIRECTORY_SEPARATOR . $this->channel . '-' . date('Y-m-d') . '.log';
    }

    /**
     * Resolves the shared error file path for the current day.
     */
    private function errorFilePath(): string
    {
        return $this->logDirectory . DIRECTORY_SEPARATOR . 'error-' . date('Y-m-d') . '.log';
    }

    /**
     * Converts a log path or file path into a directory path.
     */
    private function resolveLogDirectory(?string $logPath): string
    {
        $fallback = dirname(__DIR__, 2) . '/storage/logs';
        if ($logPath === null || trim($logPath) === '') {
            return $fallback;
        }

        $trimmed = rtrim(trim($logPath), DIRECTORY_SEPARATOR);
        if (str_ends_with(strtolower($trimmed), '.log')) {
            return dirname($trimmed);
        }

        return $trimmed;
    }

    /**
     * Creates the log directory when it does not exist yet.
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Nao foi possivel criar o diretorio de logs: ' . $directory);
        }
    }

    /**
     * Keeps channel names filesystem-safe and readable.
     */
    private function sanitizeChannel(string $channel): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim($channel));
        return $sanitized !== '' ? strtolower($sanitized) : 'app';
    }

    /**
     * Normalizes a level to a known PSR-3 constant.
     */
    private function normalizeLevel(string $level): string
    {
        $normalized = strtolower(trim($level));
        return isset(self::LEVEL_PRIORITY[$normalized]) ? $normalized : LogLevel::DEBUG;
    }

    /**
     * Decides whether the current entry meets the minimum configured level.
     */
    private function shouldLog(string $level): bool
    {
        return self::LEVEL_PRIORITY[$level] >= self::LEVEL_PRIORITY[$this->minLevel];
    }

    /**
     * Mirrors severe entries to the shared error file.
     */
    private function shouldMirrorToErrorFile(string $level): bool
    {
        return self::LEVEL_PRIORITY[$level] >= self::LEVEL_PRIORITY[LogLevel::ERROR];
    }

    /**
     * Replaces `{placeholder}` tokens using normalized context values.
     *
     * @param array<string,mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (!is_scalar($value) && !$value instanceof Stringable && !$value instanceof DateTimeInterface) {
                continue;
            }
            $replacements['{' . $key . '}'] = $this->stringifyValue($value);
        }

        return strtr($message, $replacements);
    }

    /**
     * Converts context values into log-safe scalars and arrays.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $normalized[$key] = [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'code' => $value->getCode(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
                continue;
            }

            if ($value instanceof DateTimeInterface) {
                $normalized[$key] = $value->format('Y-m-d H:i:s');
                continue;
            }

            if ($value instanceof Stringable) {
                $normalized[$key] = (string) $value;
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = $this->normalizeContext($value);
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Produces the final line stored in the log file.
     *
     * @param array<string,mixed> $context
     */
    private function formatLine(string $timestamp, string $level, string $message, array $context): string
    {
        if ($this->jsonFormat) {
            return json_encode([
                'timestamp' => $timestamp,
                'channel' => $this->channel,
                'level' => strtoupper($level),
                'message' => $message,
                'context' => $context,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }

        $line = sprintf(
            '[%s] %s.%s: %s',
            $timestamp,
            $this->channel,
            strtoupper($level),
            $message
        );

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $line . PHP_EOL;
    }

    /**
     * Appends a line to a file and keeps permissions constrained.
     *
     * Fail soft: logging must never crash a request (e.g. login) when a
     * root-owned or ACL-restricted log file blocks writes.
     */
    private function appendLine(string $filePath, string $line): void
    {
        if (@file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('LoggerService: nao foi possivel escrever no arquivo de log: ' . $filePath);
            return;
        }

        // 0660 preserves ACL mask write for shared users (eskill/root);
        // 0640 collapses the ACL mask to r-- and blocks the web user.
        @chmod($filePath, 0660);
    }

    /**
     * Converts a scalar-like value to a readable string representation.
     *
     * @param mixed $value
     */
    private function stringifyValue($value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[unserializable]';
    }
}
