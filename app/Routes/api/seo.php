<?php

declare(strict_types=1);
/** @var \App\Router $router */

use App\Controllers\CatalogCloneController;
use App\Controllers\CloneNotificationController;
use App\Controllers\CloneABTestingController;
use App\Controllers\CloneAutomationController;
use App\Controllers\CloneSellerRecommendationController;
use App\Controllers\CloneROIAnalysisController;
use App\Controllers\CloneSEOIntegrationController;
use App\Controllers\CloneManagementController;
use App\Controllers\CatalogController;
use App\Controllers\CategoryController;
use App\Controllers\SearchController;
use App\Controllers\ItemController;
use App\Controllers\ExportController;
use App\Controllers\BackupController;
use App\Controllers\StatisticsController;
use App\Controllers\AnalyticsController;
use App\Controllers\NotificationController;
use App\Controllers\SyncController;
use App\Controllers\DashboardController;
use App\Controllers\DashboardApiController;
use App\Controllers\OrderController;
use App\Controllers\AlertController;
use App\Controllers\CompetitorController;
use App\Controllers\OpportunityController;
use App\Controllers\AuditController;
use App\Controllers\JobController;
use App\Controllers\ReportController;
use App\Controllers\PollingController;
use App\Controllers\CompatibilityController;
use App\Controllers\QuestionController;
use App\Controllers\SEOToolsController;
use App\Controllers\DeepResearchController;
use App\Controllers\AgentController;
use App\Controllers\SecurityController;
use App\Controllers\PerformanceController;
use App\Controllers\AdvancedReportController;
use App\Controllers\PdfController;
use App\Controllers\BrandAnalyzerController;
use App\Controllers\PushController;
use App\Controllers\RealTimeNotificationController;
use App\Controllers\HealthController;
use App\Controllers\ApiTokenController;
use App\Controllers\EanController;
use App\Controllers\MessageController;
use App\Controllers\SEOKillerController;
use App\Controllers\TechnicalSheetController;
use App\Controllers\TokenDashboardController;
use App\Controllers\MultiAccountController;
use App\Controllers\BrandCentralController;
use App\Controllers\TrendsController;
use App\Controllers\InventoryAdvancedController;
use App\Controllers\MessagingController;
use App\Controllers\DynamicPricingController;
use App\Controllers\AIPredictionsController;
use App\Controllers\ChatbotAIController;
use App\Controllers\AIOptimizationController;
use App\Controllers\ErrorMonitoringController;
use App\Controllers\CompetitorMonitorController;
use App\Controllers\AIController;
use App\Controllers\AuthMonitorApiController;
use App\Controllers\AuthApiController;
use App\Controllers\AuthController;
use App\Controllers\RenderController;
use App\Controllers\BrevoIntegrationController;
use App\Controllers\ClawdbotWebhookController;
use App\Controllers\AssistantConnectorController;
use App\Controllers\OpenClawConnectorController;
use App\Controllers\UserController;
use App\Controllers\OnboardingController;
use App\Controllers\MarketDataController;

// ========================================
// 🧠 SEO Phase 1: Synonyms & Semantics
// ========================================
$router->get('api/seo/synonyms/{categoryId}', \App\Controllers\SeoSynonymsController::class, 'getHierarchy');
$router->post('api/seo/synonyms/expand', \App\Controllers\SeoSynonymsController::class, 'expand');
$router->post('api/seo/synonyms/model', \App\Controllers\SeoSynonymsController::class, 'generateModel');
$router->post('api/seo/score/calculate', \App\Controllers\SeoSynonymsController::class, 'calculateScore');
$router->get('api/seo/contexts/{categoryId}', \App\Controllers\SeoSynonymsController::class, 'getContexts');

// ========================================
// 📊 SEO Phase 2: Keyword Distribution
// ========================================
$router->post('api/seo/keywords/distribute', \App\Controllers\SeoKeywordsController::class, 'distribute');
$router->post('api/seo/keywords/classify', \App\Controllers\SeoKeywordsController::class, 'classify');
$router->get('api/seo/keywords/fetch/{categoryId}', \App\Controllers\SeoKeywordsController::class, 'fetch');
$router->post('api/seo/keywords/generate/{categoryId}', \App\Controllers\SeoKeywordsController::class, 'generate');
$router->post('api/seo/density/validate', \App\Controllers\SeoKeywordsController::class, 'validateDensity');
$router->post('api/seo/density/calculate', \App\Controllers\SeoKeywordsController::class, 'calculateDensity');
$router->get('api/seo/weights', \App\Controllers\SeoKeywordsController::class, 'getWeights');
$router->delete('api/seo/keywords/cache/{categoryId}', \App\Controllers\SeoKeywordsController::class, 'invalidateCache');

// ========================================
// � SEO Keyword Mining (ML API)
// ========================================
$router->get('api/seo/keywords/mine/{categoryId}', \App\Controllers\SeoKeywordsController::class, 'mine');
$router->get('api/seo/keywords/mine-moto', \App\Controllers\SeoKeywordsController::class, 'mineMoto');
$router->get('api/seo/keywords/attributes/{categoryId}', \App\Controllers\SeoKeywordsController::class, 'getAttributeKeywords');
$router->get('api/seo/keywords/discover', \App\Controllers\SeoKeywordsController::class, 'discover');
$router->post('api/seo/keywords/suggest-title', \App\Controllers\SeoKeywordsController::class, 'suggestTitle');

// ========================================
// 📊 Market Data API - Dados Reais do ML
// ========================================
$router->get('api/market/analyze/{categoryId}', MarketDataController::class, 'analyzeMarket');
$router->get('api/market/category/{categoryId}', MarketDataController::class, 'getCategory');
$router->get('api/market/pricing/{categoryId}', MarketDataController::class, 'analyzePricing');
$router->get('api/market/competitors/{categoryId}', MarketDataController::class, 'analyzeCompetitors');
$router->get('api/market/trends/{categoryId}', MarketDataController::class, 'getTrends');
$router->get('api/market/filters/{categoryId}', MarketDataController::class, 'getFilters');
$router->post('api/market/similar', MarketDataController::class, 'findSimilar');
$router->get('api/market/quality/{itemId}', MarketDataController::class, 'analyzeQuality');
$router->post('api/market/suggest-price', MarketDataController::class, 'suggestPrice');
$router->get('api/market/search', MarketDataController::class, 'search');
$router->get('api/market/item/{itemId}', MarketDataController::class, 'getItem');
$router->get('api/market/autocomplete', MarketDataController::class, 'autocompleteSearch');
$router->get('api/market/discover', MarketDataController::class, 'discover');
$router->get('api/market/attributes/{categoryId}', MarketDataController::class, 'getAttributes');
$router->get('api/market/children/{categoryId}', MarketDataController::class, 'getChildren');
$router->get('api/market/stats', MarketDataController::class, 'getStats');
$router->get('api/market/requirements/{categoryId}', MarketDataController::class, 'getRequirements');

// ========================================
// �📝 SEO Phase 3: Description Builder
// ========================================
$router->post('api/seo/description/build', \App\Controllers\SeoDescriptionController::class, 'build');
$router->post('api/seo/description/block', \App\Controllers\SeoDescriptionController::class, 'generateBlock');
$router->post('api/seo/description/faq', \App\Controllers\SeoDescriptionController::class, 'generateFaq');
$router->post('api/seo/description/validate', \App\Controllers\SeoDescriptionController::class, 'validate');
$router->post('api/seo/longtail/generate', \App\Controllers\SeoDescriptionController::class, 'generateLongTail');

// ========================================
// 🤖 ML ↔ AI Integration Pipeline
// ========================================
$router->get('api/ml-ai/health', \App\Controllers\MLAIIntegrationController::class, 'health');
$router->get('api/ml-ai/items', \App\Controllers\MLAIIntegrationController::class, 'listItems');
$router->get('api/ml-ai/status/{itemId}', \App\Controllers\MLAIIntegrationController::class, 'itemStatus');
$router->get('api/ml-ai/enrich/{itemId}', \App\Controllers\MLAIIntegrationController::class, 'enrich');
$router->post('api/ml-ai/optimize/{itemId}', \App\Controllers\MLAIIntegrationController::class, 'optimize');
$router->post('api/ml-ai/apply/{itemId}', \App\Controllers\MLAIIntegrationController::class, 'apply');
$router->put('api/ml-ai/description/{itemId}', \App\Controllers\MLAIIntegrationController::class, 'updateDescription');
$router->post('api/ml-ai/pipeline/{itemId}', \App\Controllers\MLAIIntegrationController::class, 'pipeline');
$router->post('api/ml-ai/batch', \App\Controllers\MLAIIntegrationController::class, 'batch');
$router->get('api/ml-ai/history/{itemId}', \App\Controllers\MLAIIntegrationController::class, 'history');
$router->post('api/ml-ai/rollback/{itemId}', \App\Controllers\MLAIIntegrationController::class, 'rollback');
$router->get('api/ml-ai/stats', \App\Controllers\MLAIIntegrationController::class, 'stats');
$router->get('api/ml-ai/compare', \App\Controllers\MLAIIntegrationController::class, 'compare');
$router->post('api/ml-ai/impact/{versionId}', \App\Controllers\MLAIIntegrationController::class, 'impact');
$router->post('api/ml-ai/cleanup', \App\Controllers\MLAIIntegrationController::class, 'cleanup');

// ========================================
// 🔥 SEO KILLER - Sistema Matador
// ========================================
$router->get('api/seo-killer/gsc/status', SEOKillerController::class, 'gscStatus');
$router->post('api/seo-killer/gsc/auth-url', SEOKillerController::class, 'gscAuthUrl');
$router->get('api/seo-killer/gsc/callback', SEOKillerController::class, 'gscCallback');
$router->get('api/seo-killer/gsc/data', SEOKillerController::class, 'gscData');

$router->get('api/seo-killer/diagnose', SEOKillerController::class, 'diagnose');
$router->post('api/seo-killer/title', SEOKillerController::class, 'generateTitle');
$router->post('api/seo-killer/description', SEOKillerController::class, 'generateDescription');

// FASE 4: Campos Ocultos e Cobertura
$router->get('api/seo/hidden-fields/{itemId}', \App\Controllers\SeoCoverageController::class, 'detectHiddenFields');
$router->post('api/seo/hidden-fields/generate', \App\Controllers\SeoCoverageController::class, 'generateHiddenFieldValues');
$router->post('api/seo/hidden-fields/apply', \App\Controllers\SeoCoverageController::class, 'applyHiddenFields');
$router->get('api/seo/coverage/{itemId}', \App\Controllers\SeoCoverageController::class, 'analyzeCoverage');
$router->get('api/seo/coverage/gaps/{itemId}', \App\Controllers\SeoCoverageController::class, 'getCoverageGaps');
$router->get('api/seo/compatibility/{categoryId}', \App\Controllers\SeoCoverageController::class, 'listCompatibility');

// FASE 5: Integração e Dashboard
$router->post('api/seo/strategies/optimize/full/{itemId}', \App\Controllers\SeoStrategiesController::class, 'optimizeFull');
$router->post('api/seo/strategies/optimize/partial/{itemId}', \App\Controllers\SeoStrategiesController::class, 'optimizePartial');
$router->get('api/seo/strategies/preview/{itemId}', \App\Controllers\SeoStrategiesController::class, 'preview');
$router->post('api/seo/strategies/apply/{itemId}', \App\Controllers\SeoStrategiesController::class, 'apply');
$router->get('api/seo/strategies/score/{itemId}', \App\Controllers\SeoStrategiesController::class, 'getScore');
$router->get('api/seo/strategies/history/{itemId}', \App\Controllers\SeoStrategiesController::class, 'history');
$router->post('api/seo/monitoring/schedule/{itemId}', \App\Controllers\SeoStrategiesController::class, 'scheduleMonitoring');
$router->get('api/seo/monitoring/metrics/{itemId}', \App\Controllers\SeoStrategiesController::class, 'getMetrics');


$router->post('api/seo-killer/description/analyze', SEOKillerController::class, 'analyzeDescription');
$router->post('api/seo-killer/attributes', SEOKillerController::class, 'fillAttributes');
$router->get('api/seo-killer/hidden-attributes/{categoryId}', SEOKillerController::class, 'getHiddenAttributes');
$router->post('api/seo-killer/optimize', SEOKillerController::class, 'optimizeItem');
$router->post('api/seo-killer/optimize/{productId}', SEOKillerController::class, 'engineOptimizeItem');
$router->post('api/seo-killer/sync', SEOKillerController::class, 'sync');
$router->get('api/seo-killer/report', SEOKillerController::class, 'completenessReport');
$router->get('api/seo-killer/schema/{itemId}', SEOKillerController::class, 'generateSchema');
$router->get('api/seo-killer/export/pdf/{type}/{itemId}', SEOKillerController::class, 'exportPdf');
$router->get('api/seo-killer/api-keys', SEOKillerController::class, 'listApiKeys');
$router->post('api/seo-killer/api-keys', SEOKillerController::class, 'createApiKey');
$router->delete('api/seo-killer/api-keys/{clientId}', SEOKillerController::class, 'revokeApiKey');
$router->post('api/seo-killer/backlinks/analyze', SEOKillerController::class, 'analyzeBacklinks');

// Keywords & Competitors
$router->post('api/seo-killer/keywords', SEOKillerController::class, 'researchKeywords');
$router->post('api/seo-killer/spy', SEOKillerController::class, 'spyCompetitors');
$router->post('api/seo-killer/competitors/analyze/{itemId}', SEOKillerController::class, 'analyzeCompetitors');
// Bulk Operations
$router->get('api/seo-killer/bulk/select', SEOKillerController::class, 'bulkSelect');
$router->post('api/seo-killer/bulk/start', SEOKillerController::class, 'bulkStart');
$router->post('api/seo-killer/bulk/process/{jobId}', SEOKillerController::class, 'bulkProcess');
$router->get('api/seo-killer/bulk/status/{jobId}', SEOKillerController::class, 'bulkStatus');
$router->get('api/seo-killer/bulk/jobs', SEOKillerController::class, 'bulkJobs');
$router->get('api/seo-killer/bulk/monitor', SEOKillerController::class, 'bulkMonitor');
$router->post('api/seo-killer/bulk/cancel/{jobId}', SEOKillerController::class, 'bulkCancel');
$router->post('api/seo-killer/bulk/retry/{jobId}', SEOKillerController::class, 'bulkRetry');
// Auto-Pilot
$router->get('api/seo-killer/autopilot/config', SEOKillerController::class, 'getAutopilotConfig');
$router->post('api/seo-killer/autopilot/config', SEOKillerController::class, 'saveAutopilotConfig');
$router->post('api/seo-killer/autopilot/enable', SEOKillerController::class, 'enableAutopilot');
$router->post('api/seo-killer/autopilot/disable', SEOKillerController::class, 'disableAutopilot');
$router->post('api/seo-killer/autopilot/run', SEOKillerController::class, 'runAutopilot');
$router->get('api/seo-killer/autopilot/history', SEOKillerController::class, 'autopilotHistory');
$router->get('api/seo-killer/autopilot/history/{runId}', SEOKillerController::class, 'autopilotRunDetails');
$router->get('api/seo-killer/autopilot/stats', SEOKillerController::class, 'autopilotStats');
$router->get('api/seo-killer/autopilot/scores', SEOKillerController::class, 'getScoreEvolution');
// Performance Tracking
$router->get('api/seo-killer/performance/dashboard', SEOKillerController::class, 'getPerformanceDashboard');
$router->get('api/seo-killer/performance/item/{itemId}', SEOKillerController::class, 'getItemPerformance');
$router->get('api/seo-killer/performance/compare/{itemId}', SEOKillerController::class, 'compareBeforeAfter');
$router->get('api/seo-killer/performance/top', SEOKillerController::class, 'getTopPerformers');
$router->get('api/seo-killer/performance/consolidated', SEOKillerController::class, 'getConsolidatedMetrics');
$router->get('api/seo-killer/performance/evolution', SEOKillerController::class, 'getMetricsEvolution');
$router->get('api/seo-killer/performance/categories', SEOKillerController::class, 'getCategoryPerformance');
$router->get('api/seo-killer/performance/export', SEOKillerController::class, 'exportPerformanceReport');
$router->post('api/seo-killer/performance/collect', SEOKillerController::class, 'collectPerformanceMetrics');
// Image Killer
$router->get('api/seo-killer/images/analyze/{itemId}', SEOKillerController::class, 'analyzeImages');
// A/B Testing
$router->post('api/seo-killer/ab-test', SEOKillerController::class, 'createABTest');
$router->post('api/seo-killer/ab-test/title/{itemId}', SEOKillerController::class, 'createTitleABTest');
$router->get('api/seo-killer/ab-test', SEOKillerController::class, 'listABTests');
$router->get('api/seo-killer/ab-test/{testId}', SEOKillerController::class, 'getABTest');
$router->post('api/seo-killer/ab-test/stop/{id}', SEOKillerController::class, 'stopABTest');
$router->post('api/seo-killer/ab-test/apply/{id}', SEOKillerController::class, 'applyABTestWinner');
$router->get('api/seo-killer/ab-test/analysis/{id}', SEOKillerController::class, 'getABTestAnalysis');
// Competitor Watchlist
$router->post('api/seo-killer/watchlist', SEOKillerController::class, 'addToWatchlist');
$router->get('api/seo-killer/watchlist', SEOKillerController::class, 'getWatchlist');
$router->post('api/seo-killer/watchlist/{id}/update', SEOKillerController::class, 'updateWatchlistItem');
$router->delete('api/seo-killer/watchlist/{id}', SEOKillerController::class, 'removeFromWatchlist');
$router->get('api/seo-killer/watchlist/{id}/history', SEOKillerController::class, 'getWatchlistHistory');
$router->get('api/seo-killer/alerts', SEOKillerController::class, 'getAlerts');
$router->post('api/seo-killer/alerts/{id}/read', SEOKillerController::class, 'markAlertAsRead');

// SEO Score Calculator v2
$router->get('api/seo-killer/score/{itemId}', SEOKillerController::class, 'calculateScore');
$router->get('api/seo-killer/score/history/{itemId}', SEOKillerController::class, 'getScoreHistory');
$router->get('api/seo-killer/alerts/score', SEOKillerController::class, 'getScoreAlerts');
$router->get('api/seo-killer/benchmarks', SEOKillerController::class, 'getBenchmarks');
$router->get('api/seo-killer/audit/log', SEOKillerController::class, 'getAuditLog');
$router->get('api/seo-killer/settings', SEOKillerController::class, 'getSettings');
$router->post('api/seo-killer/settings', SEOKillerController::class, 'saveSettings');
$router->get('api/seo-killer/compare/{itemId}/{categoryId}', SEOKillerController::class, 'compareWithCategory');
$router->get('api/seo-killer/top-performers', SEOKillerController::class, 'getTopPerformingItems');
$router->get('api/seo-killer/autopilot/status', SEOKillerController::class, 'getAutopilotRealStatus');

// 🚀 Advanced SEO Features
$router->post('api/seo-killer/advanced-maximize', SEOKillerController::class, 'advancedMaximizeSEO');
$router->post('api/seo-killer/predict-performance', SEOKillerController::class, 'predictPerformance');
$router->post('api/seo-killer/intelligent-auto-optimize', SEOKillerController::class, 'intelligentAutoOptimize');
$router->post('api/seo-killer/advanced-keywords', SEOKillerController::class, 'advancedKeywordsAnalysis');
$router->post('api/seo-killer/advanced-competitor-analysis', SEOKillerController::class, 'advancedCompetitorAnalysis');
$router->get('api/seo-killer/optimization-stats', SEOKillerController::class, 'getOptimizationStats');

// SEO Strategies Integration (12 Strategies Engine)
$router->get('api/seo-killer/strategies/analyze/{itemId}', SEOKillerController::class, 'runStrategiesAnalysis');
$router->get('api/seo-killer/strategies/score/{itemId}', SEOKillerController::class, 'getStrategiesScore');
$router->post('api/seo-killer/strategies/optimize/{itemId}', SEOKillerController::class, 'optimizeWithStrategies');
$router->post('api/seo-killer/strategies/batch', SEOKillerController::class, 'batchStrategiesAnalysis');
$router->get('api/seo-killer/strategies/dashboard', SEOKillerController::class, 'getStrategiesDashboard');
$router->get('api/seo-killer/strategies/cache/stats', SEOKillerController::class, 'getStrategiesCacheStats');
$router->post('api/seo-killer/strategies/cache/clear', SEOKillerController::class, 'clearStrategiesCache');

// Export PDF & Intelligence
$router->post('api/seo-killer/export/competitor', SEOKillerController::class, 'exportCompetitorPdf');
$router->get('api/seo-killer/export/watchlist/{id}', SEOKillerController::class, 'exportWatchlistPdf');
$router->get('api/seo-killer/intelligence/dashboard', SEOKillerController::class, 'getIntelligenceDashboard');
$router->post('api/seo-killer/intelligence/swot', SEOKillerController::class, 'getSwotAnalysis');
$router->post('api/seo-killer/spy/copy-strategy', SEOKillerController::class, 'copyCompetitorStrategy');
$router->post('api/seo-killer/item/update', SEOKillerController::class, 'updateItem');

// Image Killer
$router->post('api/seo-killer/images/upload', SEOKillerController::class, 'uploadImage');
$router->post('api/seo-killer/images/update/{itemId}', SEOKillerController::class, 'updateImages');

// Advanced Analytics (v1.8.0)
$router->get('api/seo-killer/analytics/demand-forecast', SEOKillerController::class, 'getDemandForecast');
$router->get('api/seo-killer/analytics/seasonality', SEOKillerController::class, 'getSeasonalityAnalysis');
$router->get('api/seo-killer/analytics/opportunities', SEOKillerController::class, 'getEmergingOpportunities');
$router->get('api/seo-killer/analytics/market-sentiment', SEOKillerController::class, 'getMarketSentiment');

// ========================================
// 🎯 SEO STRATEGIES - Estratégias Avançadas (v2.0.0)
// ========================================
// Sinônimos - Hierarquia 4 níveis (E1)
$router->post('api/seo-killer/strategies/synonyms/expand', SEOKillerController::class, 'expandSynonyms');
$router->get('api/seo-killer/strategies/synonyms/hierarchy/{categoryId}', SEOKillerController::class, 'getSynonymHierarchy');
$router->post('api/seo-killer/strategies/synonyms/generate', SEOKillerController::class, 'generateSynonymHierarchy');
$router->post('api/seo-killer/strategies/synonyms/select', SEOKillerController::class, 'selectSynonymsForField');
$router->post('api/seo-killer/strategies/synonyms/model', SEOKillerController::class, 'generateOptimizedModel');
// Score Semântico (E9)
$router->post('api/seo-killer/strategies/score/calculate', SEOKillerController::class, 'calculateSemanticScore');
$router->post('api/seo-killer/strategies/score/rank', SEOKillerController::class, 'rankBySemanticScore');
$router->post('api/seo-killer/strategies/score/filter', SEOKillerController::class, 'filterBySemanticScore');
// Keywords - Arquitetura Híbrida
$router->post('api/seo-killer/strategies/keywords/fetch', SEOKillerController::class, 'fetchKeywords');
$router->get('api/seo-killer/strategies/keywords/trending/{categoryId}', SEOKillerController::class, 'getTrendingKeywords');
$router->get('api/seo-killer/strategies/keywords/autocomplete', SEOKillerController::class, 'getAutocompleteKeywords');
$router->post('api/seo-killer/strategies/keywords/competitor', SEOKillerController::class, 'getCompetitorKeywords');
$router->delete('api/seo-killer/strategies/keywords/cache/{categoryId}', SEOKillerController::class, 'invalidateKeywordCache');
// Contextos e Configuração
$router->get('api/seo-killer/strategies/contexts/{categoryId}', SEOKillerController::class, 'getUseContexts');
$router->get('api/seo-killer/strategies/config/{categoryId}', SEOKillerController::class, 'getStrategyConfig');
$router->post('api/seo-killer/strategies/config/{categoryId}', SEOKillerController::class, 'saveStrategyConfig');
// Hidden Fields - Campos Ocultos (E2)
$router->get('api/seo-killer/strategies/hidden-fields/{itemId}', SEOKillerController::class, 'analyzeHiddenFields');
$router->post('api/seo-killer/strategies/hidden-fields/suggest', SEOKillerController::class, 'suggestHiddenFields');
$router->post('api/seo-killer/strategies/hidden-fields/apply/{itemId}', SEOKillerController::class, 'applyHiddenFields');
$router->get('api/seo-killer/strategies/hidden-fields/available/{categoryId}', SEOKillerController::class, 'getAvailableHiddenFields');
// Keyword Injector - Injeção Natural (E3)
$router->post('api/seo-killer/strategies/inject/title', SEOKillerController::class, 'injectKeywordsTitle');
$router->post('api/seo-killer/strategies/inject/description', SEOKillerController::class, 'injectKeywordsDescription');
$router->post('api/seo-killer/strategies/inject/density', SEOKillerController::class, 'analyzeKeywordDensity');
$router->post('api/seo-killer/strategies/inject/points', SEOKillerController::class, 'suggestInjectionPoints');
// Search Type Coverage - Cobertura de Busca (E4)
$router->post('api/seo-killer/strategies/coverage/analyze', SEOKillerController::class, 'analyzeCoverage');
$router->post('api/seo-killer/strategies/coverage/keywords', SEOKillerController::class, 'generateCoverageKeywords');
$router->post('api/seo-killer/strategies/coverage/optimize', SEOKillerController::class, 'optimizeForCoverage');
$router->get('api/seo-killer/strategies/coverage/classify', SEOKillerController::class, 'classifySearchQuery');
$router->post('api/seo-killer/strategies/coverage/missing', SEOKillerController::class, 'suggestMissingKeywords');
// Field Weight - Distribuição por Peso (E5)
$router->post('api/seo-killer/strategies/weight/distribute', SEOKillerController::class, 'distributeByWeight');
$router->post('api/seo-killer/strategies/weight/analyze', SEOKillerController::class, 'analyzeDistribution');
$router->post('api/seo-killer/strategies/weight/optimize', SEOKillerController::class, 'optimizeDistribution');
$router->post('api/seo-killer/strategies/weight/reallocate', SEOKillerController::class, 'suggestReallocation');
$router->post('api/seo-killer/strategies/weight/efficiency', SEOKillerController::class, 'calculateIndexingEfficiency');
$router->get('api/seo-killer/strategies/weight/fields', SEOKillerController::class, 'getFieldWeights');
$router->post('api/seo-killer/strategies/weight/maximize', SEOKillerController::class, 'getWeightMaximizationStrategy');

// Use Context - Contextos de Uso (E6)
$router->get('api/seo-killer/strategies/contexts/available', SEOKillerController::class, 'getAvailableContexts');
$router->get('api/seo-killer/strategies/contexts/category/{categoryId}', SEOKillerController::class, 'getContextsForCategory');
$router->post('api/seo-killer/strategies/contexts/detect', SEOKillerController::class, 'detectContexts');
$router->post('api/seo-killer/strategies/contexts/keywords', SEOKillerController::class, 'generateContextKeywords');
$router->post('api/seo-killer/strategies/contexts/suggest', SEOKillerController::class, 'suggestContexts');
$router->post('api/seo-killer/strategies/contexts/enrich', SEOKillerController::class, 'enrichWithContexts');

// Long Tail Generator (E7)
$router->post('api/seo-killer/strategies/longtail/generate', SEOKillerController::class, 'generateLongTails');
$router->get('api/seo-killer/strategies/longtail/autocomplete/{keyword}', SEOKillerController::class, 'generateLongTailsFromAutocomplete');
$router->post('api/seo-killer/strategies/longtail/competitors', SEOKillerController::class, 'generateLongTailsFromCompetitors');
$router->post('api/seo-killer/strategies/longtail/ai', SEOKillerController::class, 'generateLongTailsWithAI');
$router->post('api/seo-killer/strategies/longtail/analyze', SEOKillerController::class, 'analyzeLongTail');
$router->post('api/seo-killer/strategies/longtail/missing', SEOKillerController::class, 'suggestMissingLongTails');

// Compatibility Service (E10)
$router->get('api/seo-killer/strategies/compatibility/analyze/{itemId}', SEOKillerController::class, 'analyzeCompatibility');
$router->post('api/seo-killer/strategies/compatibility/expand', SEOKillerController::class, 'expandCompatibility');
$router->post('api/seo-killer/strategies/compatibility/fetch', SEOKillerController::class, 'fetchCompatibilityFromML');
$router->post('api/seo-killer/strategies/compatibility/attribute', SEOKillerController::class, 'generateCompatibleModelsAttribute');
$router->post('api/seo-killer/strategies/compatibility/suggest-by-specs', SEOKillerController::class, 'suggestCompatibilityBySpecs');
$router->post('api/seo-killer/strategies/compatibility/validate', SEOKillerController::class, 'validateCompatibility');
$router->get('api/seo-killer/strategies/compatibility/models', SEOKillerController::class, 'getAllModels');
$router->get('api/seo-killer/strategies/compatibility/models/{brand}', SEOKillerController::class, 'getAllModels');

// FAQ Optimizer (E11)
$router->post('api/seo-killer/strategies/faq/generate', SEOKillerController::class, 'generateFAQs');
$router->post('api/seo-killer/strategies/faq/ai', SEOKillerController::class, 'generateFAQsWithAI');
$router->post('api/seo-killer/strategies/faq/optimize', SEOKillerController::class, 'optimizeFAQs');
$router->post('api/seo-killer/strategies/faq/schema', SEOKillerController::class, 'generateFAQSchema');
$router->post('api/seo-killer/strategies/faq/html', SEOKillerController::class, 'generateFAQHTML');
$router->post('api/seo-killer/strategies/faq/description-text', SEOKillerController::class, 'generateFAQDescriptionText');
$router->post('api/seo-killer/strategies/faq/validate', SEOKillerController::class, 'validateFAQs');
$router->get('api/seo-killer/strategies/faq/suggest/{categoryId}', SEOKillerController::class, 'suggestFAQsForCategory');
$router->get('api/seo-killer/questions/keywords/{itemId}', SEOKillerController::class, 'mineKeywordsFromQuestions');

// SEO Strategies Engine - Orchestrator (E12)
$router->get('api/seo-killer/strategies/engine/analyze/{itemId}', SEOKillerController::class, 'engineAnalyzeItem');
$router->post('api/seo-killer/strategies/engine/analyze', SEOKillerController::class, 'engineAnalyzeData');
$router->post('api/seo-killer/strategies/engine/optimize/{itemId}', SEOKillerController::class, 'engineOptimizeItem');
$router->get('api/seo-killer/strategies/engine/dashboard', SEOKillerController::class, 'engineDashboard');
$router->get('api/seo-killer/strategies/engine/dashboard/{categoryId}', SEOKillerController::class, 'engineDashboard');
$router->get('api/seo-killer/strategies/engine/report/{itemId}', SEOKillerController::class, 'engineOptimizationReport');
$router->post('api/seo-killer/strategies/engine/compare', SEOKillerController::class, 'engineCompareItems');
$router->post('api/seo-killer/strategies/engine/monitor', SEOKillerController::class, 'engineMonitorKeywords');

// ========================================
// ✅ QUALITY CHECK - Health, Score & Validation
// ========================================
use App\Controllers\QualityController;

// Health Check - Verificação de Saúde
$router->get('api/quality/health/{itemId}', QualityController::class, 'checkHealth');
$router->post('api/quality/health/batch', QualityController::class, 'checkHealthBatch');
$router->get('api/quality/health/{itemId}/recommendations', QualityController::class, 'getHealthRecommendations');
$router->get('api/quality/purchase-experience/{itemId}', QualityController::class, 'getPurchaseExperience');

// Quality Score - Pontuação de Qualidade
$router->get('api/quality/score/{itemId}', QualityController::class, 'calculateScore');

// Validation - Validação Pré-Publicação
$router->post('api/quality/validate', QualityController::class, 'validateListing');
$router->post('api/quality/validate/batch', QualityController::class, 'validateBatch');
$router->post('api/quality/autofix', QualityController::class, 'autoFix');

// Complete Report - Relatório Completo
$router->get('api/quality/report/{itemId}', QualityController::class, 'getCompleteReport');

// Dashboard APIs (NEW - implementado)
$router->get('api/quality/dashboard/stats', QualityController::class, 'getDashboardStats');
$router->get('api/quality/dashboard/items', QualityController::class, 'getDashboardItems');

// ========================================
// 📦 SHIPPING STRATEGY OPTIMIZER - Otimização de Envios
// ========================================
use App\Controllers\ShippingController;

// Shipping Simulation - Simulação de Custos
$router->get('api/shipping/simulate/{itemId}', ShippingController::class, 'simulateItem');
$router->post('api/shipping/simulate', ShippingController::class, 'simulateCustom');
$router->post('api/shipping/compare', ShippingController::class, 'compareShipping');

// Shipping Optimization - Otimização de Estratégia
$router->get('api/shipping/optimize/{itemId}', ShippingController::class, 'optimizeItem');
$router->post('api/shipping/optimize/batch', ShippingController::class, 'optimizeBatch');

// Dimensions Calculator - Cálculos de Dimensões
$router->post('api/shipping/dimensions/cubic-weight', ShippingController::class, 'calculateCubicWeight');
$router->post('api/shipping/dimensions/chargeable-weight', ShippingController::class, 'calculateChargeableWeight');
$router->post('api/shipping/dimensions/validate', ShippingController::class, 'validateDimensions');
$router->post('api/shipping/dimensions/validate-all', ShippingController::class, 'validateAllModes');
$router->post('api/shipping/dimensions/suggest-packaging', ShippingController::class, 'suggestPackaging');
$router->post('api/shipping/dimensions/optimize', ShippingController::class, 'optimizeDimensions');
$router->post('api/shipping/dimensions/analyze', ShippingController::class, 'analyzeDimensions');

// ========================================
// 📝 LISTING BUILDER - Construtor de Anúncios
// ========================================
use App\Controllers\ListingBuilderController;

// Wizard Workflow - Fluxo do Construtor
$router->post('api/listing-builder/start', ListingBuilderController::class, 'start');
$router->post('api/listing-builder/validate/{step}', ListingBuilderController::class, 'validateStep');
$router->post('api/listing-builder/build', ListingBuilderController::class, 'build');
$router->post('api/listing-builder/publish', ListingBuilderController::class, 'publish');

// Draft Management - Gerenciamento de Rascunhos
$router->post('api/listing-builder/draft/save', ListingBuilderController::class, 'saveDraft');
$router->get('api/listing-builder/draft/{draftId}', ListingBuilderController::class, 'loadDraft');

// Clone Functionality - Clonagem de Anúncios
$router->post('api/listing-builder/clone', ListingBuilderController::class, 'clone');

// Template Management - Gerenciamento de Templates
$router->get('api/listing-builder/templates', ListingBuilderController::class, 'listTemplates');
$router->get('api/listing-builder/templates/{templateId}', ListingBuilderController::class, 'getTemplate');
$router->post('api/listing-builder/templates/{templateId}/render', ListingBuilderController::class, 'renderTemplate');
$router->post('api/listing-builder/templates/custom', ListingBuilderController::class, 'createCustomTemplate');

// Block Management - Blocos Reutilizáveis
$router->get('api/listing-builder/blocks', ListingBuilderController::class, 'listBlocks');

// ========================================
// 🏷️ TITLE GENERATOR - Gerador de Títulos
// ========================================
use App\Controllers\TitleGeneratorController;

// Title Generation - Geração de Títulos
$router->post('api/title-generator/generate', TitleGeneratorController::class, 'generate');
$router->post('api/title-generator/improve/{itemId}', TitleGeneratorController::class, 'improveFromItem');
$router->post('api/title-generator/optimize', TitleGeneratorController::class, 'optimize');

// Title Analysis - Análise de Títulos
$router->post('api/title-generator/analyze', TitleGeneratorController::class, 'analyze');
$router->post('api/title-generator/compare', TitleGeneratorController::class, 'compare');
$router->get('api/title-generator/quick-tips', TitleGeneratorController::class, 'quickTips');

// Title Variations - Variações
$router->post('api/title-generator/variations', TitleGeneratorController::class, 'generateVariations');
$router->post('api/title-generator/ab-testing', TitleGeneratorController::class, 'generateABTesting');

// Batch Operations - Operações em Lote
$router->post('api/title-generator/batch/analyze', TitleGeneratorController::class, 'batchAnalyze');

// ========================================
// 🤖 AI/ML SERVICES - Machine Learning
// ========================================
use App\Controllers\AIMLController;

// Category Learning - Padrões por Categoria
$router->post('api/ai/ml/category/learn', AIMLController::class, 'learnCategory');
$router->get('api/ai/ml/category/learning/{categoryId}', AIMLController::class, 'getCategoryLearning');
$router->get('api/ai/ml/category/template/{categoryId}', AIMLController::class, 'getCategoryTemplate');
$router->get('api/ai/ml/category/learned', AIMLController::class, 'listLearnedCategories');
$router->post('api/ai/ml/category/refresh/{categoryId}', AIMLController::class, 'refreshCategoryLearning');

// Keyword Classification - CORE/SUPPORT/LONG_TAIL
$router->post('api/ai/ml/keywords/classify', AIMLController::class, 'classifyKeywords');
$router->post('api/ai/ml/keywords/group', AIMLController::class, 'groupKeywords');
$router->post('api/ai/ml/keywords/optimize-title', AIMLController::class, 'optimizeKeywordsForTitle');
$router->get('api/ai/ml/keywords/stats', AIMLController::class, 'getKeywordStats');
$router->post('api/ai/ml/keywords/reclassify', AIMLController::class, 'reclassifyKeywords');

// Trend Prediction - Tendências e Sazonalidade
$router->get('api/ai/ml/trends/predict', AIMLController::class, 'predictTrend');
$router->get('api/ai/ml/trends/seasonality', AIMLController::class, 'analyzeSeasonality');
$router->get('api/ai/ml/trends/rising/{categoryId}', AIMLController::class, 'findRisingKeywords');
$router->get('api/ai/ml/trends/report/{categoryId}', AIMLController::class, 'getCategoryTrendReport');
$router->post('api/ai/ml/trends/batch', AIMLController::class, 'batchTrendPrediction');

// ========================================
// �🧾 FICHA TÉCNICA (SEO) - Lista + Aprovação
// ========================================
$router->get('api/seo/technical-sheet/items', TechnicalSheetController::class, 'listItems');
$router->get('api/technical-sheet/items', TechnicalSheetController::class, 'listItems');
$router->get('api/seo/technical-sheet/stats', TechnicalSheetController::class, 'stats');
$router->get('api/technical-sheet/stats', TechnicalSheetController::class, 'stats');
$router->get('api/seo/technical-sheet/items/{itemId}', TechnicalSheetController::class, 'getItem');
$router->get('api/technical-sheet/items/{itemId}', TechnicalSheetController::class, 'getItem');
$router->post('api/seo/technical-sheet/items/{itemId}/refresh', TechnicalSheetController::class, 'refreshItem');
$router->post('api/seo/technical-sheet/items/{itemId}/suggestions/generate', TechnicalSheetController::class, 'generateSuggestions');
$router->post('api/seo/technical-sheet/items/{itemId}/suggestions/quick', TechnicalSheetController::class, 'quickSuggestions');
$router->post('api/seo/technical-sheet/items/{itemId}/suggestions/model', TechnicalSheetController::class, 'modelSuggestions');
$router->post('api/seo/technical-sheet/items/{itemId}/suggestions/decisions', TechnicalSheetController::class, 'saveDecisions');
$router->post('api/seo/technical-sheet/items/{itemId}/suggestions', TechnicalSheetController::class, 'addSuggestions');
$router->post('api/seo/technical-sheet/items/{itemId}/apply', TechnicalSheetController::class, 'applyApproved');

// Batch (jobs)
$router->post('api/seo/technical-sheet/batch/suggestions/generate', TechnicalSheetController::class, 'batchGenerateSuggestions');
$router->post('api/seo/technical-sheet/batch/apply', TechnicalSheetController::class, 'batchApplyApproved');
$router->post('api/seo/technical-sheet/batch/approve', TechnicalSheetController::class, 'batchApprovePending');

// Analytics
$router->get('api/seo/technical-sheet/analytics/dashboard', TechnicalSheetController::class, 'analyticsDashboard');
$router->get('api/seo/technical-sheet/analytics/priorities', TechnicalSheetController::class, 'analyticsPriorities');
$router->get('api/seo/technical-sheet/alerts', TechnicalSheetController::class, 'getAlerts');
$router->post('api/seo/technical-sheet/auto-optimize', TechnicalSheetController::class, 'autoOptimize');
$router->get('api/seo/technical-sheet/auto-optimize/stats', TechnicalSheetController::class, 'autoOptimizeStats');
$router->get('api/seo/technical-sheet/export', TechnicalSheetController::class, 'exportSuggestions');
$router->post('api/seo/technical-sheet/import', TechnicalSheetController::class, 'importSuggestions');
$router->post('api/seo/technical-sheet/send-report', TechnicalSheetController::class, 'sendDailyReport');
$router->get('api/seo/technical-sheet/export/template/{categoryId}', TechnicalSheetController::class, 'exportCategoryTemplate');

// ML Trends Integration - Mineração de Keywords via API do Mercado Livre
$router->get('api/seo/technical-sheet/items/{itemId}/trends', TechnicalSheetController::class, 'enrichWithTrends');
$router->get('api/seo/technical-sheet/trends/site', TechnicalSheetController::class, 'getSiteTrends');
$router->get('api/seo/technical-sheet/trends/category/{categoryId}', TechnicalSheetController::class, 'getCategoryTrends');

// Charts & Visualizations
$router->get('api/seo/technical-sheet/charts', TechnicalSheetController::class, 'getCharts');

// Batch Performance
$router->post('api/seo/technical-sheet/batch/process', TechnicalSheetController::class, 'processBatch');
$router->get('api/seo/technical-sheet/batch/performance', TechnicalSheetController::class, 'getBatchPerformance');

// Scheduler
$router->get('api/seo/technical-sheet/scheduler/jobs', TechnicalSheetController::class, 'listScheduledJobs');
$router->post('api/seo/technical-sheet/scheduler/jobs', TechnicalSheetController::class, 'createScheduledJob');
$router->post('api/seo/technical-sheet/scheduler/jobs/{jobId}/run', TechnicalSheetController::class, 'runScheduledJob');
$router->put('api/seo/technical-sheet/scheduler/jobs/{jobId}/pause', TechnicalSheetController::class, 'pauseScheduledJob');
$router->put('api/seo/technical-sheet/scheduler/jobs/{jobId}/resume', TechnicalSheetController::class, 'resumeScheduledJob');
$router->delete('api/seo/technical-sheet/scheduler/jobs/{jobId}', TechnicalSheetController::class, 'deleteScheduledJob');
$router->get('api/seo/technical-sheet/scheduler/stats', TechnicalSheetController::class, 'getSchedulerStats');

// Webhooks
$router->get('api/seo/technical-sheet/webhooks', TechnicalSheetController::class, 'listWebhooks');
$router->post('api/seo/technical-sheet/webhooks', TechnicalSheetController::class, 'createWebhook');
$router->put('api/seo/technical-sheet/webhooks/{webhookId}', TechnicalSheetController::class, 'updateWebhook');
$router->delete('api/seo/technical-sheet/webhooks/{webhookId}', TechnicalSheetController::class, 'deleteWebhook');
$router->post('api/seo/technical-sheet/webhooks/{webhookId}/test', TechnicalSheetController::class, 'testWebhook');

// Alert Rules
$router->get('api/seo/technical-sheet/alerts/rules', TechnicalSheetController::class, 'listAlertRules');
$router->post('api/seo/technical-sheet/alerts/rules', TechnicalSheetController::class, 'createAlertRule');
$router->put('api/seo/technical-sheet/alerts/rules/{ruleId}', TechnicalSheetController::class, 'updateAlertRule');
$router->delete('api/seo/technical-sheet/alerts/rules/{ruleId}', TechnicalSheetController::class, 'deleteAlertRule');
$router->post('api/seo/technical-sheet/alerts/rules/{ruleId}/recipients', TechnicalSheetController::class, 'addAlertRecipient');
$router->delete('api/seo/technical-sheet/alerts/rules/{ruleId}/recipients/{email}', TechnicalSheetController::class, 'removeAlertRecipient');
$router->get('api/seo/technical-sheet/alerts/history', TechnicalSheetController::class, 'getAlertHistory');

// Advanced Features
$router->post('api/seo/technical-sheet/items/{itemId}/extract-from-title', TechnicalSheetController::class, 'extractFromTitle');
$router->get('api/seo/technical-sheet/items/{itemId}/compare-competitors', TechnicalSheetController::class, 'compareCompetitors');
$router->post('api/seo/technical-sheet/items/{itemId}/preview', TechnicalSheetController::class, 'previewChanges');

// SEO Strategies Integration
$router->get('api/seo/technical-sheet/items/{itemId}/seo-analysis', TechnicalSheetController::class, 'seoAnalysis');
$router->post('api/seo/technical-sheet/items/{itemId}/seo-suggestions', TechnicalSheetController::class, 'generateSEOSuggestions');
$router->post('api/seo/technical-sheet/items/{itemId}/optimize-title', TechnicalSheetController::class, 'optimizeItemTitle');
$router->post('api/seo/technical-sheet/items/{itemId}/optimize-description', TechnicalSheetController::class, 'optimizeItemDescription');
$router->post('api/seo/technical-sheet/items/{itemId}/apply-optimized-title', TechnicalSheetController::class, 'applyOptimizedTitle');
$router->post('api/seo/technical-sheet/items/{itemId}/apply-optimized-description', TechnicalSheetController::class, 'applyOptimizedDescription');
$router->get('api/seo/technical-sheet/items/{itemId}/description', TechnicalSheetController::class, 'getItemPlainTextDescription');
$router->get('api/seo/technical-sheet/items/{itemId}/history', TechnicalSheetController::class, 'getOptimizationHistory');
$router->post('api/seo/technical-sheet/items/{itemId}/rollback/{versionId}', TechnicalSheetController::class, 'rollbackOptimization');
$router->get('api/seo/technical-sheet/items/{itemId}/seo-score', TechnicalSheetController::class, 'getSEOScore');
$router->get('api/seo/technical-sheet/items/{itemId}/seo-report', TechnicalSheetController::class, 'getSEOReport');
$router->post('api/seo/technical-sheet/batch/seo-suggestions', TechnicalSheetController::class, 'batchSEOSuggestions');

// 🎯 Smart Gap Filler - Preenchimento inteligente de lacunas
$router->post('api/seo/technical-sheet/items/{itemId}/smart-fill', TechnicalSheetController::class, 'smartFillGaps');
$router->post('api/seo/technical-sheet/batch/smart-fill', TechnicalSheetController::class, 'batchSmartFillGaps');
$router->get('api/seo/technical-sheet/items/{itemId}/coverage-analysis', TechnicalSheetController::class, 'coverageAnalysis');

// 🎯 Smart Fill Dashboard & Metrics
$router->get('api/seo/technical-sheet/smart-fill/dashboard', TechnicalSheetController::class, 'smartFillDashboard');
$router->get('api/seo/technical-sheet/smart-fill/widget', TechnicalSheetController::class, 'smartFillWidget');
$router->get('api/seo/technical-sheet/smart-fill/by-source', TechnicalSheetController::class, 'smartFillBySource');
$router->get('api/seo/technical-sheet/smart-fill/success-rate', TechnicalSheetController::class, 'smartFillSuccessRate');
$router->post('api/seo/technical-sheet/smart-fill/auto-approve', TechnicalSheetController::class, 'smartFillAutoApprove');

// 🚀 Bulk SEO - Fluxo Seguro de Otimização em Lote
$router->post('api/seo/technical-sheet/bulk/dry-run', TechnicalSheetController::class, 'bulkDryRun');
$router->post('api/seo/technical-sheet/bulk/apply', TechnicalSheetController::class, 'bulkApply');
$router->post('api/seo/technical-sheet/bulk/apply-async', TechnicalSheetController::class, 'bulkApplyAsync');
$router->get('api/seo/technical-sheet/bulk/job/{jobId}/status', TechnicalSheetController::class, 'bulkJobStatus');
$router->get('api/seo/technical-sheet/bulk/history', TechnicalSheetController::class, 'bulkHistory');
$router->post('api/seo/technical-sheet/bulk/rollback', TechnicalSheetController::class, 'bulkRollback');

// 🔧 Attribute Suggestions - Aplicação real de campos da Ficha Técnica
$router->get('api/seo/technical-sheet/items/{itemId}/attribute-suggestions/preview', TechnicalSheetController::class, 'previewAttributeSuggestions');
$router->post('api/seo/technical-sheet/items/{itemId}/attribute-suggestions/apply', TechnicalSheetController::class, 'applyAttributeSuggestion');
$router->get('api/seo/technical-sheet/items/{itemId}/applicable-attributes', TechnicalSheetController::class, 'getApplicableAttributes');

// ========================================
// 🎯 SEO INTELLIGENCE MODULE
// ========================================

// Dashboard & Listings
$router->get('api/seo/intelligence/dashboard', \App\Controllers\SEOController::class, 'dashboard');
$router->get('api/seo/intelligence/listings', \App\Controllers\SEOController::class, 'listings');
$router->get('api/seo/intelligence/listings/{itemId}', \App\Controllers\SEOController::class, 'listingDetail');

// Audits
$router->post('api/seo/intelligence/audit/{itemId}', \App\Controllers\SEOController::class, 'auditListing');
$router->post('api/seo/intelligence/audit/batch', \App\Controllers\SEOController::class, 'batchAudit');
$router->get('api/seo/intelligence/audit/status/{jobId}', \App\Controllers\SEOController::class, 'getAuditJobStatus');
$router->get('api/seo/intelligence/history/{itemId}', \App\Controllers\SEOController::class, 'history');

// Competitors
$router->post('api/seo/intelligence/competitors/{itemId}/refresh', \App\Controllers\SEOController::class, 'refreshCompetitors');

// Hidden Attributes
$router->post('api/seo/intelligence/hidden-attributes/{itemId}/detect', \App\Controllers\SEOController::class, 'detectHiddenAttributes');
$router->post('api/seo/intelligence/hidden-attributes/{itemId}/apply', \App\Controllers\SEOController::class, 'applyHiddenAttribute');

// AI Center API
$router->get('api/ai-center/stats', \App\Controllers\AICenterController::class, 'getOverviewStats');
$router->get('api/ai-center/status', \App\Controllers\AICenterController::class, 'getAutomationStatus');
$router->post('api/ai/config/save', \App\Controllers\AICenterController::class, 'saveConfig');
$router->post('api/ai-center/workflow', \App\Controllers\AICenterController::class, 'triggerWorkflow');
$router->get('api/ai-center/insights', \App\Controllers\AICenterController::class, 'getPredictiveInsights');
$router->get('api/ai-center/autonomous-stats', \App\Controllers\AICenterController::class, 'getAutonomousStats');

// AI Optimization History API
$router->get('api/ai/optimization/history', \App\Controllers\AIOptimizationController::class, 'getHistory');
$router->get('api/ai/optimization/history/{logId}', \App\Controllers\AIOptimizationController::class, 'getHistoryDetail');

// Decision Engine API
// Versioning & Rollback
$router->post('api/seo/intelligence/rollback/{itemId}/{versionId}', \App\Controllers\SEOController::class, 'rollback');


// Multi-Account Management (v1.9.0)
$router->get('api/multi-account/dashboard', MultiAccountController::class, 'getDashboard');
$router->get('api/multi-account/compare', MultiAccountController::class, 'comparePerformance');
$router->post('api/multi-account/bulk-optimize', MultiAccountController::class, 'bulkOptimize');
$router->get('api/multi-account/report', MultiAccountController::class, 'getConsolidatedReport');
$router->post('api/multi-account/groups', MultiAccountController::class, 'manageGroups');
$router->post('api/multi-account/switch', MultiAccountController::class, 'switchAccount');
$router->get('api/multi-account/accounts', MultiAccountController::class, 'listAccounts');

// Token Management (Auto-refresh)
$router->get('api/multi-account/tokens/status', MultiAccountController::class, 'getTokensStatus');
$router->post('api/multi-account/tokens/refresh', MultiAccountController::class, 'refreshToken');
$router->post('api/multi-account/tokens/refresh-all', MultiAccountController::class, 'refreshAllTokens');

// Rotas de Mensagens Automáticas
$router->get('api/messages/templates', MessageController::class, 'index');
$router->post('api/messages/templates', MessageController::class, 'store');
$router->put('api/messages/templates/{id}', MessageController::class, 'update');
$router->delete('api/messages/templates/{id}', MessageController::class, 'delete');

// Rotas de Clonagem de Catálogo (API)
$router->post('api/catalog/clone', CatalogCloneController::class, 'cloneItem');
$router->post('api/catalog/clone/batch', CatalogCloneController::class, 'cloneBatch');
$router->post('api/catalog/clone/simulate', CatalogCloneController::class, 'simulate');
$router->post('api/catalog/clone/price-preview', CatalogCloneController::class, 'pricePreview');
$router->get('api/catalog/clone/metrics', CatalogCloneController::class, 'getMetrics');
$router->get('api/catalog/clone/schedules', CatalogCloneController::class, 'getSchedules');
$router->post('api/catalog/clone/schedules', CatalogCloneController::class, 'createSchedule');
$router->delete('api/catalog/clone/schedules/{id}', CatalogCloneController::class, 'cancelSchedule');

// ========================================
// ⚡ SEO Optimizer (SEOOptimizerService)
// ========================================
$router->get('api/seo/optimizer/health', \App\Controllers\SeoOptimizationController::class, 'healthCheck');
$router->post('api/seo/optimizer/product', \App\Controllers\SeoOptimizationController::class, 'optimizeProduct');
$router->post('api/seo/optimizer/title', \App\Controllers\SeoOptimizationController::class, 'optimizeTitle');
