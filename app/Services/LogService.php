<?php

declare(strict_types=1);

namespace App\Services;

class LogService
{
    private string $logDir;

    public function __construct()
    {
        $this->logDir = rtrim((string)(getenv('LOG_DIR') ?: ''), '/');
        if ($this->logDir === '') {
            $this->logDir = __DIR__ . '/../../storage/logs';
        }
    }

    /**
     * Rotates logs older than X days or larger than Y size
     */
    public function rotateLogs(int $keepDays = 7): array
    {
        $results = ['deleted' => 0, 'errors' => 0];
        
        if (!is_dir($this->logDir)) return $results;

        $files = glob($this->logDir . '/*.log');
        $files = array_merge($files, glob($this->logDir . '/*.old'));
        
        foreach ($files as $file) {
            // Check Last Modified
            $mtime = filemtime($file);
            if ($mtime && (time() - $mtime) > ($keepDays * 86400)) {
                // Delete old logs
                if (unlink($file)) {
                    $results['deleted']++;
                } else {
                    $results['errors']++;
                }
            } else {
                // Check Size (> 10MB)
                if (filesize($file) > 10 * 1024 * 1024) {
                    // Truncate or Rename
                    $newName = $file . '.' . date('YmdHis') . '.old';
                    rename($file, $newName);
                }
            }
        }
        
        return $results;
    }
    /**
     * Log info message
     */
    public function info(string $channel, $message, array $context = []): void
    {
        $this->writeLog('INFO', $channel, $message, $context);
    }

    /**
     * Log error message
     */
    public function error(string $channel, $message, array $context = []): void
    {
        $this->writeLog('ERROR', $channel, $message, $context);
    }
    
    /**
     * Warning alias
     */
    public function warning(string $channel, $message, array $context = []): void
    {
        $this->writeLog('WARNING', $channel, $message, $context);
    }

    private function writeLog(string $level, string $channel, $message, array $context = []): void
    {
        $file = $this->logDir . '/' . strtolower($channel) . '.log';
        if (is_array($message)) {
            $message = json_encode($message);
        }
        $contextStr = !empty($context) ? json_encode($context) : '';
        
        $line = "[" . date('Y-m-d H:i:s') . "] {$level}: {$message} {$contextStr}" . PHP_EOL;
        
        file_put_contents($file, $line, FILE_APPEND);
    }
}
