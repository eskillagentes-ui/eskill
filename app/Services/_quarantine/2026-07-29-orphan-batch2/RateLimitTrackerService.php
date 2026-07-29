<?php

declare(strict_types=1);

namespace App\Services;

use Exception;

/**
 * API Rate Limit Tracker Service
 * Tracks API calls to prevent hitting rate limits
 * Implements predictive throttling and alerting
 */
class RateLimitTrackerService
{
    private AdvancedRedisCacheService $redis;
    
    // ML API Limits (per minute)
    private const ML_LIMIT_PER_MINUTE = 60;
    private const ML_LIMIT_PER_HOUR = 3000;
    
    // Anthropic API Limits
    private const ANTHROPIC_LIMIT_PER_MINUTE = 50;
    private const ANTHROPIC_LIMIT_PER_DAY = 10000;
    
    // Alert thresholds (percentage of limit)
    private const ALERT_THRESHOLD = 80;
    private const CRITICAL_THRESHOLD = 95;

    public function __construct(?AdvancedRedisCacheService $redis = null)
    {
        if ($redis !== null) {
            $this->redis = $redis;
            return;
        }

        $this->redis = new AdvancedRedisCacheService();

        $health = $this->redis->healthCheck();
        if (($health['status'] ?? 'unhealthy') !== 'healthy') {
            throw new Exception('Redis indisponível no RateLimitTrackerService');
        }
    }

    /**
     * Track an API call
     */
    public function trackCall(string $provider, string $endpoint = 'general'): bool
    {
        $timestamp = time();
        $minute = date('Y-m-d H:i', $timestamp);
        $hour = date('Y-m-d H', $timestamp);
        $day = date('Y-m-d', $timestamp);

        $minuteKey = "rate_limit:{$provider}:minute:{$minute}";
        $hourKey = "rate_limit:{$provider}:hour:{$hour}";
        $dayKey = "rate_limit:{$provider}:day:{$day}";
        
        // Increment counters
        $this->redis->increment($minuteKey);
        $this->redis->increment($hourKey);
        $this->redis->increment($dayKey);
        
        // Set expiration (cleanup old data)
        // Minute counter expires after 2 minutes
        // Hour counter expires after 2 hours
        // Day counter expires after 2 days

        // Best-effort expiration (avoid unbounded key growth)
        $this->redis->expire($minuteKey, 120);
        $this->redis->expire($hourKey, 7200);
        $this->redis->expire($dayKey, 172800);
        
        return true;
    }

    /**
     * Check if we can make a call without hitting limits
     */
    public function canMakeCall(string $provider): array
    {
        $limits = $this->getLimits($provider);
        $usage = $this->getCurrentUsage($provider);
        
        $canCall = true;
        $reason = '';
        $waitSeconds = 0;
        
        // Check minute limit
        if (isset($limits['minute']) && ($usage['minute'] ?? 0) >= $limits['minute']) {
            $canCall = false;
            $reason = 'Minute limit reached';
            $waitSeconds = 60 - (time() % 60); // Wait until next minute
        }
        
        // Check hour limit
        if (isset($limits['hour']) && ($usage['hour'] ?? 0) >= $limits['hour']) {
            $canCall = false;
            $reason = 'Hour limit reached';
            $waitSeconds = max($waitSeconds, 3600 - (time() % 3600));
        }
        
        // Check day limit
        if (isset($limits['day']) && ($usage['day'] ?? 0) >= $limits['day']) {
            $canCall = false;
            $reason = 'Daily limit reached';
            $waitSeconds = max($waitSeconds, 86400 - (time() % 86400));
        }
        
        return [
            'can_call' => $canCall,
            'reason' => $reason,
            'wait_seconds' => $waitSeconds,
            'usage' => $usage,
            'limits' => $limits,
            'usage_percentage' => $this->calculateUsagePercentage($usage, $limits)
        ];
    }

    /**
     * Get current usage for a provider
     */
    public function getCurrentUsage(string $provider): array
    {
        $minute = date('Y-m-d H:i');
        $hour = date('Y-m-d H');
        $day = date('Y-m-d');
        
        return [
            'minute' => (int)$this->redis->get("rate_limit:{$provider}:minute:{$minute}") ?: 0,
            'hour' => (int)$this->redis->get("rate_limit:{$provider}:hour:{$hour}") ?: 0,
            'day' => (int)$this->redis->get("rate_limit:{$provider}:day:{$day}") ?: 0
        ];
    }

    /**
     * Get limits for a provider
     */
    private function getLimits(string $provider): array
    {
        switch ($provider) {
            case 'mercadolivre':
            case 'ml':
                return [
                    'minute' => self::ML_LIMIT_PER_MINUTE,
                    'hour' => self::ML_LIMIT_PER_HOUR
                ];
            
            case 'anthropic':
            case 'claude':
                return [
                    'minute' => self::ANTHROPIC_LIMIT_PER_MINUTE,
                    'day' => self::ANTHROPIC_LIMIT_PER_DAY
                ];
            
            default:
                return [
                    'minute' => 60,
                    'hour' => 1000
                ];
        }
    }

    /**
     * Calculate usage percentage
     */
    private function calculateUsagePercentage(array $usage, array $limits): array
    {
        $percentages = [];
        
        foreach (['minute', 'hour', 'day'] as $period) {
            if (isset($limits[$period]) && $limits[$period] > 0) {
                $percentages[$period] = round(($usage[$period] / $limits[$period]) * 100, 2);
            }
        }
        
        return $percentages;
    }

    /**
     * Check if we should alert about usage
     */
    public function shouldAlert(string $provider): ?array
    {
        $status = $this->canMakeCall($provider);
        $percentages = $status['usage_percentage'];
        
        $maxPercentage = max($percentages);
        
        if ($maxPercentage >= self::CRITICAL_THRESHOLD) {
            return [
                'level' => 'critical',
                'message' => "API rate limit at {$maxPercentage}% for {$provider}",
                'usage' => $status['usage'],
                'limits' => $status['limits']
            ];
        }
        
        if ($maxPercentage >= self::ALERT_THRESHOLD) {
            return [
                'level' => 'warning',
                'message' => "API rate limit at {$maxPercentage}% for {$provider}",
                'usage' => $status['usage'],
                'limits' => $status['limits']
            ];
        }
        
        return null;
    }

    /**
     * Predictive throttling: estimate if we'll hit limit in next N minutes
     */
    public function predictLimitHit(string $provider, int $lookAheadMinutes = 5): array
    {
        $usage = $this->getCurrentUsage($provider);
        $limits = $this->getLimits($provider);
        
        // Calculate current rate (calls per minute)
        $currentRate = $usage['minute'];
        
        // Predict usage in N minutes
        $predictedUsage = $usage['hour'] + ($currentRate * $lookAheadMinutes);
        
        $willHitLimit = false;
        $timeToLimit = null;
        
        if (isset($limits['hour']) && $predictedUsage >= $limits['hour']) {
            $willHitLimit = true;
            $remaining = $limits['hour'] - $usage['hour'];
            $timeToLimit = $currentRate > 0 ? ceil($remaining / $currentRate) : null;
        }
        
        return [
            'will_hit_limit' => $willHitLimit,
            'time_to_limit_minutes' => $timeToLimit,
            'current_rate_per_minute' => $currentRate,
            'predicted_usage' => $predictedUsage,
            'recommendation' => $willHitLimit ? 'throttle' : 'normal'
        ];
    }

    /**
     * Get comprehensive status for all providers
     */
    public function getStatus(): array
    {
        $providers = ['mercadolivre', 'anthropic'];
        $status = [];
        
        foreach ($providers as $provider) {
            $canCall = $this->canMakeCall($provider);
            $prediction = $this->predictLimitHit($provider);
            $alert = $this->shouldAlert($provider);
            
            $status[$provider] = [
                'can_call' => $canCall['can_call'],
                'usage' => $canCall['usage'],
                'limits' => $canCall['limits'],
                'usage_percentage' => $canCall['usage_percentage'],
                'prediction' => $prediction,
                'alert' => $alert
            ];
        }
        
        return $status;
    }

    /**
     * Reset counters (for testing or manual intervention)
     */
    public function resetCounters(string $provider): bool
    {
        $minute = date('Y-m-d H:i');
        $hour = date('Y-m-d H');
        $day = date('Y-m-d');
        
        $this->redis->delete("rate_limit:{$provider}:minute:{$minute}");
        $this->redis->delete("rate_limit:{$provider}:hour:{$hour}");
        $this->redis->delete("rate_limit:{$provider}:day:{$day}");
        
        return true;
    }
}
