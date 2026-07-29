<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Services\FeatureFlagService;

/**
 * Feature-flag guarded entry point for advanced ML modules.
 *
 * Both AdvancedPricingEngine and MLAnalyticsIntelligenceService perform
 * heavy I/O (ML API calls, DB, cache). This service:
 *   1. Checks a feature flag before activating each module.
 *   2. Catches any \Throwable from the advanced module so failures never
 *      propagate to the core commerce flow.
 *
 * Flags:
 *   ml_advanced_pricing_enabled   — AdvancedPricingEngine
 *   ml_advanced_analytics_enabled — MLAnalyticsIntelligenceService
 */
class MlAdvancedFeaturesService
{
    public const FLAG_PRICING   = 'ml_advanced_pricing_enabled';
    public const FLAG_ANALYTICS = 'ml_advanced_analytics_enabled';

    private FeatureFlagService $flags;

    public function __construct(FeatureFlagService $flags)
    {
        $this->flags = $flags;

        // Register flags with safe-enabled defaults.
        // createFlag() returns false (not throws) when the flag already exists.
        $this->flags->createFlag(
            self::FLAG_PRICING,
            true,
            'Enables AdvancedPricingEngine dynamic repricing for ML accounts'
        );
        $this->flags->createFlag(
            self::FLAG_ANALYTICS,
            true,
            'Enables MLAnalyticsIntelligenceService comprehensive analytics'
        );
    }

    /**
     * Run the advanced pricing engine for an account.
     *
     * Returns a skipped-marker array instead of throwing when:
     *   - flag is disabled, OR
     *   - the engine raises any \Throwable (isolates core from failures)
     *
     * @param array<string, mixed>         $rules
     * @param AdvancedPricingEngine|null   $engine  Inject for testing
     * @return array<string, mixed>
     */
    public function runAdvancedPricing(
        int $accountId,
        array $rules = [],
        ?AdvancedPricingEngine $engine = null
    ): array {
        if (!$this->flags->isEnabled(self::FLAG_PRICING)) {
            return ['skipped' => true, 'reason' => 'feature_disabled'];
        }

        try {
            if ($engine === null) {
                $engine = new AdvancedPricingEngine($accountId);
            }

            return $engine->startDynamicPricing($rules);
        } catch (\Throwable $e) {
            log_warning('MlAdvancedFeaturesService::runAdvancedPricing failed', [
                'account_id' => $accountId,
                'error'      => $e->getMessage(),
            ]);

            return [
                'skipped' => true,
                'reason'  => 'execution_error',
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Run the advanced analytics service for an account.
     *
     * @param array<string, mixed>                  $filters
     * @param MLAnalyticsIntelligenceService|null   $analytics  Inject for testing
     * @return array<string, mixed>
     */
    public function runAdvancedAnalytics(
        int $accountId,
        array $filters = [],
        ?MLAnalyticsIntelligenceService $analytics = null
    ): array {
        if (!$this->flags->isEnabled(self::FLAG_ANALYTICS)) {
            return ['skipped' => true, 'reason' => 'feature_disabled'];
        }

        try {
            if ($analytics === null) {
                $analytics = new MLAnalyticsIntelligenceService($accountId);
            }

            return $analytics->getComprehensiveAnalytics($filters);
        } catch (\Throwable $e) {
            log_warning('MlAdvancedFeaturesService::runAdvancedAnalytics failed', [
                'account_id' => $accountId,
                'error'      => $e->getMessage(),
            ]);

            return [
                'skipped' => true,
                'reason'  => 'execution_error',
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Whether the advanced pricing engine is currently enabled.
     */
    public function isPricingEnabled(): bool
    {
        return $this->flags->isEnabled(self::FLAG_PRICING);
    }

    /**
     * Whether the advanced analytics service is currently enabled.
     */
    public function isAnalyticsEnabled(): bool
    {
        return $this->flags->isEnabled(self::FLAG_ANALYTICS);
    }
}
