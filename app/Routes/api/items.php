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
use App\Controllers\BulkCompatibilityController;
use App\Controllers\QuestionController;
use App\Controllers\SEOToolsController;
use App\Controllers\DeepResearchController;
use App\Controllers\AgentController;
use App\Controllers\SecurityController;
use App\Controllers\PerformanceController;
use App\Controllers\AdvancedReportController;
use App\Controllers\PdfController;
use App\Controllers\BrandAnalyzerController;
use App\Controllers\AwaSellerController;
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

$router->get('api/sync/history', SyncController::class, 'history');

// Rotas de dashboard API
$router->get('api/dashboard/metrics', DashboardController::class, 'metrics');
$router->get('api/dashboard/preferences', DashboardController::class, 'getPreferences');
$router->post('api/dashboard/preferences', DashboardController::class, 'savePreferences');
$router->get('api/dashboard/accounts', DashboardController::class, 'accounts');
$router->post('api/dashboard/switch-account', DashboardController::class, 'switchAccount');
$router->post('api/settings/global', \App\Controllers\SettingsController::class, 'saveGlobal');
$router->get('api/settings/global', \App\Controllers\SettingsController::class, 'getGlobal');
$router->get('api/settings/ml-diagnostico', \App\Controllers\SettingsController::class, 'mlDiagnostico');
$router->post('api/settings/ml-refresh', \App\Controllers\SettingsController::class, 'mlRefresh');
$router->post('api/settings/notifications', \App\Controllers\SettingsController::class, 'saveNotifications');
$router->post('api/settings/telegram', \App\Controllers\SettingsController::class, 'saveTelegram');
$router->post('api/settings/sync', \App\Controllers\SettingsController::class, 'saveSync');

// Proxy management

// Rotas de pedidos
$router->get('api/orders', OrderController::class, 'index');
$router->get('api/orders/all', OrderController::class, 'all');
$router->get('api/orders/{id}', OrderController::class, 'show');
$router->post('api/orders/sync', OrderController::class, 'sync');
// GET /api/orders/sync removido — operação mutante deve usar POST

// Rotas de alertas
$router->get('api/alerts', AlertController::class, 'index');
$router->get('api/alerts/count', AlertController::class, 'count');
$router->get('api/alerts/detect-new-products', AlertController::class, 'detectNewProducts');
$router->post('api/alerts/{id}/read', AlertController::class, 'markRead');
$router->post('api/alerts/read-all', AlertController::class, 'markAllRead');

// Rotas de análise de concorrência
$router->get('api/competitors', CompetitorController::class, 'index');
$router->post('api/competitors', CompetitorController::class, 'add');
$router->delete('api/competitors/{sellerId}', CompetitorController::class, 'remove');
$router->get('api/competitors/analyze', CompetitorController::class, 'analyze');
$router->get('api/competitors/opportunities', CompetitorController::class, 'opportunities');

// Rotas de oportunidades
$router->get('api/opportunities/products-without-catalog', OpportunityController::class, 'productsWithoutCatalog');
$router->get('api/opportunities/low-competition', OpportunityController::class, 'lowCompetitionCategories');
$router->get('api/opportunities/best-sellers', OpportunityController::class, 'bestSellersWithoutListing');

// Rotas de histórico de preços (removidas - PriceHistoryController sem dados)

// Rotas de auditoria
$router->get('api/audit', AuditController::class, 'index');

// Rotas de jobs
$router->get('api/jobs/stats', JobController::class, 'stats');
$router->post('api/jobs/status', JobController::class, 'status');
$router->post('api/jobs', JobController::class, 'dispatch');
$router->post('api/jobs/process', JobController::class, 'process');
$router->post('api/jobs/clean', JobController::class, 'clean');
$router->get('api/jobs/{id}', JobController::class, 'getJob');

// Rotas de relatórios
$router->get('api/reports/account/{accountId}', ReportController::class, 'byAccount');
$router->get('api/reports/category/{categoryId}', ReportController::class, 'byCategory');
$router->get('api/reports/brand/{brand}', ReportController::class, 'byBrand');
$router->get('api/reports/consolidated', ReportController::class, 'consolidated');

// Rotas de polling
$router->get('api/polling/status', PollingController::class, 'status');
$router->post('api/polling/orders', PollingController::class, 'pollOrders');
$router->post('api/polling/items', PollingController::class, 'pollItems');
$router->post('api/polling/all', PollingController::class, 'pollAll');

// Rotas de compatibilidade
$router->get('api/compatibility/search', CompatibilityController::class, 'search');
$router->post('api/compatibility/validate/{itemId}', CompatibilityController::class, 'validate');
$router->get('api/compatibility/suggest', CompatibilityController::class, 'suggest');
$router->get('api/compatibility/attributes/{categoryId}', CompatibilityController::class, 'attributes');

// Bulk Compatibility Manager
$router->get('api/compatibility/bulk/missing', BulkCompatibilityController::class, 'listMissing');
$router->post('api/compatibility/bulk/suggest-by-title', BulkCompatibilityController::class, 'suggestForItems');
$router->post('api/compatibility/bulk/apply', BulkCompatibilityController::class, 'applyBulk');

// Rotas de Perguntas e Respostas (Q&A)
$router->get('api/questions', QuestionController::class, 'index');
$router->get('api/questions/stats', QuestionController::class, 'stats');
$router->get('api/questions/unanswered/count', QuestionController::class, 'countUnanswered');
$router->get('api/questions/auto-answer/settings', QuestionController::class, 'getAutoAnswerSettings');
$router->post('api/questions/auto-answer/settings', QuestionController::class, 'saveAutoAnswerSettings');
$router->post('api/questions/sync', QuestionController::class, 'sync');
$router->get('api/questions/{id}', QuestionController::class, 'show');
$router->post('api/questions/{id}/answer', QuestionController::class, 'answer');
$router->post('api/questions/{id}/analyze', QuestionController::class, 'analyze');
$router->post('api/questions/{id}/draft', QuestionController::class, 'draft');
$router->delete('api/questions/{id}', QuestionController::class, 'delete');

// ROTAS DE SEO
$router->get('api/seo/dashboard', \App\Controllers\SEOToolsController::class, 'dashboard');
$router->get('api/seo/analyze/{itemId}', \App\Controllers\SEOToolsController::class, 'analyzeItem');
$router->post('api/seo/analyze', \App\Controllers\SEOToolsController::class, 'analyze');
$router->post('api/seo/analyze/batch', \App\Controllers\SEOToolsController::class, 'analyzeBatch');
$router->get('api/seo/keywords/{categoryId}', \App\Controllers\SEOToolsController::class, 'keywords');
$router->post('api/seo/keywords/volume', \App\Controllers\SEOToolsController::class, 'keywordVolume');
$router->get('api/seo/keywords/variations', \App\Controllers\SEOToolsController::class, 'keywordVariations');
$router->get('api/seo/trends/{categoryId}', \App\Controllers\SEOToolsController::class, 'trends');
$router->post('api/seo/title/optimize', \App\Controllers\SEOToolsController::class, 'optimizeTitle');
$router->post('api/seo/title/analyze', \App\Controllers\SEOToolsController::class, 'analyzeTitle');
$router->post('api/seo/title/suggest', \App\Controllers\SEOToolsController::class, 'suggestTitle');
$router->post('api/seo/listing/build', \App\Controllers\SEOToolsController::class, 'buildListing');
$router->post('api/seo/listing/description', \App\Controllers\SEOToolsController::class, 'buildDescription');
$router->post('api/seo/listing/publish', \App\Controllers\SEOToolsController::class, 'publishListing');
$router->get('api/seo/listing/duplicate/{itemId}', \App\Controllers\SEOToolsController::class, 'duplicateListing');
$router->get('api/seo/pricing/{categoryId}', \App\Controllers\SEOToolsController::class, 'pricing');
$router->post('api/seo/pricing/suggest', \App\Controllers\SEOToolsController::class, 'suggestPrice');
$router->post('api/seo/pricing/compare', \App\Controllers\SEOToolsController::class, 'comparePrice');
$router->post('api/seo/pricing/calculate', \App\Controllers\SEOToolsController::class, 'calculatePrice');
$router->get('api/seo/pricing/track/{itemId}', \App\Controllers\SEOToolsController::class, 'trackPrice');

// DEEP RESEARCH
$router->get('api/research/brand/{categoryId}/{brand}', DeepResearchController::class, 'researchBrand');
$router->post('api/research/brand', DeepResearchController::class, 'researchBrandPost');
$router->get('api/research/quick/{categoryId}/{brand}', DeepResearchController::class, 'quickResearch');
$router->get('api/research/compare/{categoryId}/{brand1}/{brand2}', DeepResearchController::class, 'compareBrands');
$router->get('api/research/sellers/{categoryId}', DeepResearchController::class, 'topSellersCategory');
$router->get('api/research/opportunities/{categoryId}/{brand}', DeepResearchController::class, 'getOpportunities');
$router->get('api/research/pricing-analysis/{categoryId}/{brand}', DeepResearchController::class, 'pricingAnalysis');
$router->get('api/research/shipping-analysis/{categoryId}/{brand}', DeepResearchController::class, 'shippingAnalysis');
$router->post('api/research/simulate-profitability', DeepResearchController::class, 'simulateProfitability');
$router->post('api/research/keywords/analyze', DeepResearchController::class, 'analyzeKeywords');

// AGENTS
$router->post('api/agent/projects/start', AgentController::class, 'startProject');
$router->post('api/agent/projects/{id}/session', AgentController::class, 'runCodingSession');
$router->get('api/agent/projects/{id}/status', AgentController::class, 'getStatus');
$router->post('api/agent/projects/{id}/test', AgentController::class, 'testFeature');
$router->get('api/agent/projects', AgentController::class, 'listProjects');

// SECURITY
$router->get('security', SecurityController::class, 'dashboard');
$router->get('api/security/events', SecurityController::class, 'listEvents');
$router->get('api/security/stats', SecurityController::class, 'stats');
$router->get('api/security/blocked-ips', SecurityController::class, 'listBlockedIps');
$router->post('api/security/block-ip', SecurityController::class, 'blockIp');
$router->post('api/security/unblock-ip', SecurityController::class, 'unblockIp');
$router->post('api/security/migrate-tokens', SecurityController::class, 'migrateTokens');
$router->get('api/security/tokens-status', SecurityController::class, 'tokensStatus');
$router->post('api/security/cleanup-logs', SecurityController::class, 'cleanupLogs');
$router->get('api/security/export', SecurityController::class, 'exportReport');

// PERFORMANCE
$router->get('api/performance/dashboard', PerformanceController::class, 'dashboard');
$router->get('api/performance/cache', PerformanceController::class, 'cacheStats');
$router->post('api/performance/cache/flush', PerformanceController::class, 'flushCache');
$router->get('api/performance/slow-queries', PerformanceController::class, 'slowQueries');
$router->get('api/performance/api-metrics', PerformanceController::class, 'apiMetrics');
$router->get('api/performance/jobs', PerformanceController::class, 'jobs');
$router->post('api/performance/optimize', PerformanceController::class, 'optimizeTables');
$router->post('api/performance/cleanup', PerformanceController::class, 'cleanup');
$router->get('api/performance/config', PerformanceController::class, 'config');
$router->post('api/performance/config', PerformanceController::class, 'updateConfig');

// RELATÓRIOS AVANÇADOS
$router->get('api/reports/sales-timeline', AdvancedReportController::class, 'salesTimeline');
$router->get('api/reports/top-products', AdvancedReportController::class, 'topProducts');
$router->get('api/reports/hourly', AdvancedReportController::class, 'hourly');
$router->get('api/reports/by-category', AdvancedReportController::class, 'byCategory');
$router->get('api/export/dashboard', AdvancedReportController::class, 'export');

// PDF
$router->get('api/pdf/sales', PdfController::class, 'salesReport');
$router->get('api/pdf/market', PdfController::class, 'marketAnalysis');
$router->get('api/pdf/orders', PdfController::class, 'ordersReport');
$router->get('api/pdf/dashboard', PdfController::class, 'executiveDashboard');
$router->get('api/pdf/listing/{itemId}', PdfController::class, 'listingAnalysis');
$router->get('api/pdf/brand/awa', PdfController::class, 'brandAnalysisReport');

// BRAND ANALYZER
$router->get('api/brand/awa/dashboard', BrandAnalyzerController::class, 'dashboard');
$router->get('api/brand/awa/analyze', BrandAnalyzerController::class, 'analyzeAwa');
$router->get('api/brand/awa/quick', BrandAnalyzerController::class, 'quickAnalysis');
$router->get('api/brand/awa/gaps', BrandAnalyzerController::class, 'getGaps');
$router->get('api/brand/awa/inconsistencies', BrandAnalyzerController::class, 'getInconsistencies');
$router->get('api/brand/awa/sellers/live', BrandAnalyzerController::class, 'getSellers');
$router->get('api/brand/awa/sellers', AwaSellerController::class, 'listSellers');
$router->post('api/brand/awa/sellers/scan', AwaSellerController::class, 'scan');
$router->get('api/brand/awa/sellers/filters/options', AwaSellerController::class, 'getFiltersOptions');
$router->get('api/brand/awa/sellers/export/csv', AwaSellerController::class, 'exportCsv');
$router->get('api/brand/awa/sellers/identification/summary', AwaSellerController::class, 'identificationSummary');
$router->get('api/brand/awa/sellers/history', AwaSellerController::class, 'getHistory');
$router->get('api/brand/awa/sellers/alerts', AwaSellerController::class, 'getAlerts');
$router->post('api/brand/awa/sellers/track', AwaSellerController::class, 'trackSeller');
$router->post('api/brand/awa/sellers/identification/auto-batch', AwaSellerController::class, 'autoIdentifyBatch');
$router->get('api/brand/awa/sellers/scan/{scanId}', AwaSellerController::class, 'getScan');
$router->get('api/brand/awa/sellers/metrics', AwaSellerController::class, 'getMetrics');
$router->get('api/brand/awa/sellers/{id}', AwaSellerController::class, 'getSellerDetail');
$router->get('api/brand/awa/sellers/{id}/items', AwaSellerController::class, 'getSellerItems');
$router->get('api/brand/awa/sellers/{id}/export/items/csv', AwaSellerController::class, 'exportItemsCsv');
$router->get('api/brand/awa/sellers/{id}/identification', AwaSellerController::class, 'getIdentification');
$router->get('api/brand/awa/sellers/{id}/identification/history', AwaSellerController::class, 'getIdentificationHistory');
$router->put('api/brand/awa/sellers/{id}/identification', AwaSellerController::class, 'saveIdentification');
$router->post('api/brand/awa/sellers/{id}/identification/verify', AwaSellerController::class, 'verifyIdentification');
$router->post('api/brand/awa/sellers/{id}/identification/auto', AwaSellerController::class, 'autoIdentify');
$router->get('api/brand/awa/summary', BrandAnalyzerController::class, 'getSummary');
$router->get('api/brand/awa/history', BrandAnalyzerController::class, 'getHistory');
$router->get('api/brand/awa/pricing', BrandAnalyzerController::class, 'getPricing');
$router->get('api/brand/awa/shipping', BrandAnalyzerController::class, 'getShipping');
$router->get('api/brand/awa/items', BrandAnalyzerController::class, 'listItems');
$router->get('api/brand/awa/export/csv', BrandAnalyzerController::class, 'exportCSV');
$router->get('api/brand/awa/export/json', BrandAnalyzerController::class, 'exportJSON');
$router->get('api/brand/awa/compare', BrandAnalyzerController::class, 'compareCompetitors');
$router->get('api/brand/awa/trends', BrandAnalyzerController::class, 'getTrends');
$router->get('api/brand/awa/alerts', BrandAnalyzerController::class, 'getAlerts');
$router->get('api/brand/awa/top-products', BrandAnalyzerController::class, 'getTopProducts');
$router->get('api/brand/awa/opportunities', BrandAnalyzerController::class, 'getOpportunities');
$router->get('api/brand/awa/seller-stats', BrandAnalyzerController::class, 'getSellerStats');
$router->get('api/brand/awa/patterns', BrandAnalyzerController::class, 'getPatterns');
$router->get('api/brand/awa/report', BrandAnalyzerController::class, 'getFullReport');
$router->get('api/brand/awa/export/fix-list', BrandAnalyzerController::class, 'exportFixList');
$router->get('api/brand/awa/metrics', BrandAnalyzerController::class, 'getMetrics');

// BRAND SEARCH — Módulo 20 BRAND-003
use App\Controllers\BrandSearchController;
$router->post('api/brand-search/start',                BrandSearchController::class, 'start');
$router->get('api/brand-search/{id}/progress',         BrandSearchController::class, 'progress');
$router->get('api/brand-search/{id}/sellers',          BrandSearchController::class, 'sellers');
$router->get('api/brand-search/{id}/items/{sellerId}', BrandSearchController::class, 'items');
$router->get('api/brand-search/{id}/export',           BrandSearchController::class, 'export');

// PUSH
$router->get('api/push/vapid-key', PushController::class, 'vapidKey');
$router->post('api/push/subscribe', PushController::class, 'subscribe');
$router->post('api/push/unsubscribe', PushController::class, 'unsubscribe');
$router->get('api/push/subscriptions', PushController::class, 'subscriptions');
$router->post('api/push/test', PushController::class, 'test');
$router->post('api/push/send', PushController::class, 'send');
$router->get('api/push/stats', PushController::class, 'stats');
$router->get('api/push/status', PushController::class, 'status');
$router->post('api/push/track-install', PushController::class, 'trackInstall');
$router->post('api/push/device/register', PushController::class, 'registerDevice');
$router->post('api/push/device/unregister', PushController::class, 'unregisterDevice');

// REALTIME NOTIFICATIONS
$router->get('api/notifications/poll', RealTimeNotificationController::class, 'poll');
$router->get('api/notifications/realtime/poll', RealTimeNotificationController::class, 'poll');
$router->get('api/notifications/realtime/unread', RealTimeNotificationController::class, 'unread');
$router->post('api/notifications/realtime/{id}/read', RealTimeNotificationController::class, 'markRead');
$router->post('api/notifications/realtime/read-all', RealTimeNotificationController::class, 'markAllRead');
$router->get('api/notifications/realtime/settings', RealTimeNotificationController::class, 'getSettings');
$router->post('api/notifications/realtime/settings', RealTimeNotificationController::class, 'saveSettings');
$router->get('api/notifications/realtime/stats', RealTimeNotificationController::class, 'stats');
$router->post('api/notifications/test-sound', RealTimeNotificationController::class, 'testSound');

// HEALTH
$router->get('api/health', HealthController::class, 'check');
$router->get('api/health/live', HealthController::class, 'live');
$router->get('api/health/ready', HealthController::class, 'ready');
$router->get('api/health/ml', HealthController::class, 'mercadoLivre');
$router->get('api/health/integrations', HealthController::class, 'integrations');

// API TOKENS
$router->get('api/tokens', ApiTokenController::class, 'index');
$router->post('api/tokens', ApiTokenController::class, 'create');
$router->delete('api/tokens/{tokenId}', ApiTokenController::class, 'revoke');
$router->put('api/tokens/{tokenId}', ApiTokenController::class, 'update');
$router->get('api/tokens/{tokenId}/stats', ApiTokenController::class, 'stats');
$router->get('api/tokens/scopes', ApiTokenController::class, 'scopes');

// EAN
$router->get('api/ean/packages', EanController::class, 'packages');
$router->get('api/ean/validate/{ean}', EanController::class, 'validate');
$router->get('api/ean/balance', EanController::class, 'balance');
$router->get('api/ean/my-eans', EanController::class, 'myEans');
$router->get('api/ean/purchases', EanController::class, 'purchases');
$router->get('api/ean/transactions', EanController::class, 'transactions');
$router->post('api/ean/purchase', EanController::class, 'purchase');
$router->post('api/ean/use', EanController::class, 'useEan');
$router->get('api/ean/suggest', EanController::class, 'suggest');
$router->post('api/ean/use-for-item', EanController::class, 'useForItem');
$router->get('api/ean/by-item/{mlItemId}', EanController::class, 'getByItem');
$router->get('api/ean/export', EanController::class, 'exportEans');
$router->get('api/ean/stats', EanController::class, 'sellerStats');
$router->post('api/ean/unlink', EanController::class, 'unlinkEan');
$router->get('api/ean/low-stock', EanController::class, 'checkLowStock');
$router->get('api/ean/widget', EanController::class, 'widget');
$router->get('api/ean/preview', EanController::class, 'preview');
$router->post('api/ean/auto-assign', EanController::class, 'autoAssign');
$router->post('api/ean/webhook/mercadopago', EanController::class, 'webhookMercadoPago');
$router->get('api/ean/admin/webhook-status', EanController::class, 'adminWebhookStatus');
$router->get('api/ean/admin/webhook-sla', EanController::class, 'adminWebhookSla');
$router->get('api/ean/admin/alerts/operational', EanController::class, 'adminOperationalAlerts');
$router->get('api/ean/admin/runbook/status', EanController::class, 'adminRunbookStatus');
$router->get('api/ean/admin/runbook/escalation', EanController::class, 'adminRunbookEscalation');
$router->get('api/ean/admin/operational/trend', EanController::class, 'adminOperationalTrend');
$router->get('api/ean/admin/operational/predictive', EanController::class, 'adminOperationalPredictive');
$router->get('api/ean/admin/operational/circuit-breaker', EanController::class, 'adminOperationalCircuitBreaker');
$router->post('api/ean/admin/operational/circuit-breaker/reset', EanController::class, 'adminOperationalCircuitBreakerReset');
$router->post('api/ean/admin/runbook/execute', EanController::class, 'adminRunbookExecute');
$router->get('api/ean/admin/dashboard', EanController::class, 'adminDashboard');
$router->get('api/ean/admin/purchases', EanController::class, 'adminPurchases');
$router->get('api/ean/admin/inventory', EanController::class, 'adminInventory');
$router->post('api/ean/admin/inventory/add', EanController::class, 'adminAddInventory');
$router->post('api/ean/admin/inventory/import', EanController::class, 'adminImportInventory');
$router->post('api/ean/admin/confirm-payment', EanController::class, 'confirmPayment');
$router->post('api/ean/admin/config/mercadopago', EanController::class, 'adminConfigMercadoPago');
$router->get('api/ean/admin/config/mercadopago/test', EanController::class, 'adminTestMercadoPago');
$router->post('api/ean/admin/reconcile-payments', EanController::class, 'adminReconcilePayments');
$router->get('api/ean/admin/reconcile-status', EanController::class, 'adminReconcileStatus');
$router->get('api/ean/admin/reconciliation/preview', EanController::class, 'adminReconciliationPreview');
$router->get('api/ean/admin/reconciliation/preview-history', EanController::class, 'adminReconciliationPreviewHistory');
$router->get('api/ean/admin/reconciliation/drift', EanController::class, 'adminReconciliationDrift');
$router->get('api/ean/admin/reconciliation/divergences', EanController::class, 'adminReconciliationDivergences');
$router->post('api/ean/admin/reconciliation/auto-heal-safe', EanController::class, 'adminAutoHealReconciliationSafe');
$router->post('api/ean/admin/reconciliation/remediate-low-risk', EanController::class, 'adminRemediateLowRiskReconciliation');
$router->get('api/ean/admin/reports/sales', EanController::class, 'adminSalesReport');
$router->get('api/ean/admin/reports/usage', EanController::class, 'adminUsageReport');
$router->get('api/ean/admin/reports/inventory', EanController::class, 'adminInventoryReport');
$router->get('api/ean/admin/reports/sales/export', EanController::class, 'adminExportSales');
$router->post('api/ean/admin/reports/send-daily', EanController::class, 'adminSendDailyReport');

// ========================================
// 🏪 FASE 3 - Brand Central, Trends, Inventory Advanced, Messaging
// ========================================

// Brand Central - Lojas Oficiais
$router->get('api/brand/{accountId}/store', BrandCentralController::class, 'getStore');
$router->put('api/brand/{accountId}/store', BrandCentralController::class, 'updateStore');
$router->get('api/brand/{accountId}/products', BrandCentralController::class, 'getProducts');
$router->post('api/brand/{accountId}/showcase', BrandCentralController::class, 'addToShowcase');
$router->delete('api/brand/{accountId}/showcase/{itemId}', BrandCentralController::class, 'removeFromShowcase');
$router->get('api/brand/{accountId}/performance', BrandCentralController::class, 'getPerformance');
$router->put('api/brand/{accountId}/sections', BrandCentralController::class, 'manageSections');

// Trends - Análise de Tendências
$router->get('api/trends/{accountId}/category/{categoryId}', TrendsController::class, 'getCategoryTrends');
$router->get('api/trends/{accountId}/hot-products', TrendsController::class, 'getHotProducts');
$router->get('api/trends/{accountId}/seasonality/{keyword}', TrendsController::class, 'analyzeSeasonality');
$router->get('api/trends/{accountId}/opportunities', TrendsController::class, 'findOpportunities');
$router->get('api/trends/{accountId}/forecast/{keyword}', TrendsController::class, 'forecastDemand');

// Inventory Advanced - Estoque Multi-Origem
$router->get('api/inventory/{accountId}/multi-origin/{sku}', InventoryAdvancedController::class, 'getMultiOrigin');
$router->put('api/inventory/{accountId}/origin', InventoryAdvancedController::class, 'updateOrigin');
$router->post('api/inventory/{accountId}/reservation', InventoryAdvancedController::class, 'createReservation');
$router->delete('api/inventory/{accountId}/reservation/{reservationId}', InventoryAdvancedController::class, 'releaseReservation');
$router->post('api/inventory/{accountId}/cleanup-reservations', InventoryAdvancedController::class, 'cleanupReservations');
$router->post('api/inventory/{accountId}/bulk-sync', InventoryAdvancedController::class, 'bulkSync');
$router->get('api/inventory/{accountId}/movements/{sku}', InventoryAdvancedController::class, 'getMovements');

// Messaging - Sistema de Mensagens
$router->get('api/messaging/{accountId}/conversations', MessagingController::class, 'listConversations');
$router->get('api/messaging/{accountId}/messages/{threadId}', MessagingController::class, 'getMessages');
$router->post('api/messaging/{accountId}/send', MessagingController::class, 'sendMessage');
$router->post('api/messaging/{accountId}/template', MessagingController::class, 'createTemplate');
$router->get('api/messaging/{accountId}/templates', MessagingController::class, 'listTemplates');
$router->post('api/messaging/{accountId}/send-template', MessagingController::class, 'sendFromTemplate');
$router->post('api/messaging/{accountId}/auto-response', MessagingController::class, 'setAutoResponse');
$router->get('api/messaging/{accountId}/auto-responses', MessagingController::class, 'listAutoResponses');
$router->post('api/messaging/{accountId}/webhook', MessagingController::class, 'processWebhook');
$router->get('api/messaging/{accountId}/stats', MessagingController::class, 'getStats');

// ========================================
// 🤖 FASE 4 - Dynamic Pricing, AI Predictions, Chatbot AI
// ========================================

// Dynamic Pricing - Precificação Automática (Legacy)
$router->post('api/pricing/{accountId}/calculate/{itemId}', DynamicPricingController::class, 'calculateOptimalPrice');
$router->post('api/pricing/{accountId}/demand/{itemId}', DynamicPricingController::class, 'demandBasedPricing');
$router->post('api/pricing/{accountId}/liquidation/{sku}', DynamicPricingController::class, 'inventoryLiquidation');
$router->post('api/pricing/{accountId}/apply/{itemId}', DynamicPricingController::class, 'applyPriceAdjustment');
$router->post('api/pricing/{accountId}/batch', DynamicPricingController::class, 'batchAnalysis');

// ========================================
