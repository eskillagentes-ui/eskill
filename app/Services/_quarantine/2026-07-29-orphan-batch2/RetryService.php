<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

/**
 * Retry Service with Circuit Breaker Pattern
 * 
 * Production-grade retry logic with exponential backoff,
 * circuit breaker pattern, and intelligent error handling.
 * 
 * @author AI Development Team
 * @version 1.0.0
 */
class RetryService
{
    private LoggingService $logger;
    
    // Circuit breaker states
    private const STATE_CLOSED = 'closed';     // Normal operation
    private const STATE_OPEN = 'open';         // Failing, reject calls
    private const STATE_HALF_OPEN = 'half-open'; // Testing recovery
    
    // Default configuration
    private array $config = [
        'max_retries' => 3,
        'base_delay_ms' => 1000,
        'max_delay_ms' => 30000,
        'backoff_multiplier' => 2,
        'jitter' => true,
        
        // Circuit breaker
        'failure_threshold' => 5,
        'success_threshold' => 2,
        'timeout_seconds' => 60,
    ];
    
    // Circuit state per provider
    private static array $circuits = [];
    
    public function __construct(?LoggingService $logger = null, ?array $config = null)
    {
        $this->logger = $logger ?? new LoggingService();
        
        if ($config) {
            $this->config = array_merge($this->config, $config);
        }
    }
    
    /**
     * Execute a callable with retry logic
     * 
     * @param callable $operation
     * @param string $operationName
     * @param array $retryableErrors Error codes/classes to retry on
     * @return mixed
     * @throws \Exception
     */
    public function execute(callable $operation, string $operationName, array $retryableErrors = []): mixed
    {
        $attempt = 0;
        $lastException = null;
        
        // Check circuit breaker
        if ($this->isCircuitOpen($operationName)) {
            throw new \RuntimeException("Circuit breaker is OPEN for {$operationName}. Try again later.");
        }
        
        while ($attempt <= $this->config['max_retries']) {
            try {
                $result = $operation();
                
                // Success - record and return
                $this->recordSuccess($operationName);
                
                if ($attempt > 0) {
                    $this->logger->info('RETRY', $operationName, 
                        "Succeeded after {$attempt} retries", 
                        ['attempts' => $attempt + 1]
                    );
                }
                
                return $result;
                
            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;
                
                // Check if this error is retryable
                if (!$this->isRetryable($e, $retryableErrors)) {
                    $this->recordFailure($operationName);
                    throw $e;
                }
                
                // Check if we've exhausted retries
                if ($attempt > $this->config['max_retries']) {
                    $this->recordFailure($operationName);
                    $this->logger->error('RETRY', $operationName, 
                        "All {$this->config['max_retries']} retries exhausted",
                        ['exception' => $e]
                    );
                    throw $e;
                }
                
                // Calculate delay with exponential backoff
                $delay = $this->calculateDelay($attempt);
                
                $this->logger->warning('RETRY', $operationName,
                    "Attempt {$attempt} failed, retrying in {$delay}ms",
                    [
                        'attempt' => $attempt,
                        'delay_ms' => $delay,
                        'error' => $e->getMessage(),
                        'error_code' => $e->getCode()
                    ]
                );
                
                usleep($delay * 1000);
            }
        }
        
        throw $lastException ?? new \RuntimeException("Retry failed without exception");
    }
    
    /**
     * Execute with fallback
     * 
     * @param callable $primary
     * @param callable $fallback
     * @param string $operationName
     * @return mixed
     */
    public function executeWithFallback(callable $primary, callable $fallback, string $operationName): mixed
    {
        try {
            return $this->execute($primary, $operationName);
        } catch (\Exception $e) {
            $this->logger->warning('FALLBACK', $operationName,
                "Primary failed, using fallback",
                ['exception' => $e]
            );
            
            return $fallback();
        }
    }
    
    /**
     * Calculate delay with exponential backoff and jitter
     */
    private function calculateDelay(int $attempt): int
    {
        $delay = $this->config['base_delay_ms'] * pow($this->config['backoff_multiplier'], $attempt - 1);
        $delay = min($delay, $this->config['max_delay_ms']);
        
        // Add jitter (±25%)
        if ($this->config['jitter']) {
            $jitter = $delay * 0.25;
            $delay += random_int((int)(-$jitter), (int)$jitter);
        }
        
        return (int) max($delay, 0);
    }
    
    /**
     * Check if exception is retryable
     */
    private function isRetryable(\Exception $e, array $retryableErrors): bool
    {
        // Default retryable HTTP codes
        $retryableCodes = [429, 500, 502, 503, 504];
        
        // Check error code
        if (in_array($e->getCode(), $retryableCodes)) {
            return true;
        }
        
        // Check custom retryable errors
        foreach ($retryableErrors as $error) {
            if (is_string($error) && stripos($e->getMessage(), $error) !== false) {
                return true;
            }
            if (is_int($error) && $e->getCode() === $error) {
                return true;
            }
        }
        
        // Check for common transient error messages
        $transientMessages = [
            'timeout', 'timed out',
            'connection refused', 'connection reset',
            'rate limit', 'too many requests',
            'service unavailable', 'temporary failure',
            'server error', 'internal error',
        ];
        
        $message = strtolower($e->getMessage());
        foreach ($transientMessages as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if circuit breaker is open
     */
    private function isCircuitOpen(string $operationName): bool
    {
        if (!isset(self::$circuits[$operationName])) {
            self::$circuits[$operationName] = [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'successes' => 0,
                'last_failure' => null,
                'opened_at' => null,
            ];
        }
        
        $circuit = &self::$circuits[$operationName];
        
        if ($circuit['state'] === self::STATE_OPEN) {
            // Check if timeout has passed
            if ($circuit['opened_at'] && 
                time() - $circuit['opened_at'] >= $this->config['timeout_seconds']) {
                $circuit['state'] = self::STATE_HALF_OPEN;
                $circuit['successes'] = 0;
                $this->logger->info('CIRCUIT', $operationName, 
                    "Circuit moved to HALF-OPEN for testing");
                return false;
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Record success for circuit breaker
     */
    private function recordSuccess(string $operationName): void
    {
        if (!isset(self::$circuits[$operationName])) {
            return;
        }
        
        $circuit = &self::$circuits[$operationName];
        
        if ($circuit['state'] === self::STATE_HALF_OPEN) {
            $circuit['successes']++;
            
            if ($circuit['successes'] >= $this->config['success_threshold']) {
                $circuit['state'] = self::STATE_CLOSED;
                $circuit['failures'] = 0;
                $this->logger->info('CIRCUIT', $operationName, 
                    "Circuit CLOSED - service recovered");
            }
        } elseif ($circuit['state'] === self::STATE_CLOSED) {
            // Reset failure count on success
            $circuit['failures'] = max(0, $circuit['failures'] - 1);
        }
    }
    
    /**
     * Record failure for circuit breaker
     */
    private function recordFailure(string $operationName): void
    {
        if (!isset(self::$circuits[$operationName])) {
            self::$circuits[$operationName] = [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'successes' => 0,
                'last_failure' => null,
                'opened_at' => null,
            ];
        }
        
        $circuit = &self::$circuits[$operationName];
        $circuit['failures']++;
        $circuit['last_failure'] = time();
        
        if ($circuit['state'] === self::STATE_HALF_OPEN) {
            // Failed during half-open testing - open again
            $circuit['state'] = self::STATE_OPEN;
            $circuit['opened_at'] = time();
            $this->logger->warning('CIRCUIT', $operationName, 
                "Circuit OPENED again - recovery failed");
        } elseif ($circuit['failures'] >= $this->config['failure_threshold']) {
            $circuit['state'] = self::STATE_OPEN;
            $circuit['opened_at'] = time();
            $this->logger->error('CIRCUIT', $operationName, 
                "Circuit OPENED - {$circuit['failures']} consecutive failures");
        }
    }
    
    /**
     * Get circuit status
     */
    public function getCircuitStatus(?string $operationName = null): array
    {
        if ($operationName) {
            return self::$circuits[$operationName] ?? ['state' => self::STATE_CLOSED];
        }
        return self::$circuits;
    }
    
    /**
     * Reset circuit for testing
     */
    public function resetCircuit(string $operationName): void
    {
        unset(self::$circuits[$operationName]);
        $this->logger->info('CIRCUIT', $operationName, "Circuit manually reset");
    }
}
