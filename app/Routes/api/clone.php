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

$router->get('api/catalog/metrics', CatalogCloneController::class, 'getMetrics');
$router->get('api/catalog/schedules', CatalogCloneController::class, 'getSchedules');
$router->post('api/catalog/schedules', CatalogCloneController::class, 'createSchedule');
$router->delete('api/catalog/schedules/{id}', CatalogCloneController::class, 'cancelSchedule');
$router->get('api/catalog/losing', CatalogController::class, 'listLosingItems');

// FASE 1-4: Novas rotas de Clonagem em Lote (Multi-conta)
$router->get('api/catalog/clone/source/seller/search', CatalogCloneController::class, 'searchSeller');
$router->get('api/catalog/clone/source/seller/{sellerId}/items', CatalogCloneController::class, 'listSellerItems');
$router->get('api/catalog/clone/source/seller/{sellerId}/summary', CatalogCloneController::class, 'getSellerSummary');
$router->post('api/catalog/clone/source/items', CatalogCloneController::class, 'resolveItemIds');
$router->post('api/catalog/clone/validate', CatalogCloneController::class, 'validatePreExecution');
$router->post('api/catalog/clone/dry-run', CatalogCloneController::class, 'dryRun');
$router->post('api/catalog/clone/item', CatalogCloneController::class, 'cloneItemNew');
$router->get('api/catalog/clone/jobs', CatalogCloneController::class, 'listJobs');
$router->post('api/catalog/clone/jobs/seller', CatalogCloneController::class, 'createSellerJob');
$router->post('api/catalog/clone/jobs', CatalogCloneController::class, 'createJob');
$router->get('api/catalog/clone/jobs/{jobId}/status', CatalogCloneController::class, 'getJobStatus');
$router->get('api/catalog/clone/history', CatalogCloneController::class, 'getHistory');

// FASE 5: Templates de Clonagem
$router->get('api/catalog/clone/templates', CatalogCloneController::class, 'listTemplates');
$router->get('api/catalog/clone/templates/{idOrSlug}', CatalogCloneController::class, 'getTemplate');
$router->post('api/catalog/clone/templates', CatalogCloneController::class, 'createTemplate');
$router->put('api/catalog/clone/templates/{id}', CatalogCloneController::class, 'updateTemplate');
$router->delete('api/catalog/clone/templates/{id}', CatalogCloneController::class, 'deleteTemplate');
$router->post('api/catalog/clone/templates/preview', CatalogCloneController::class, 'previewTemplate');

// FASE 6: Métricas e Observabilidade
$router->get('api/catalog/clone/metrics/dashboard', CatalogCloneController::class, 'getMetricsDashboard');
$router->get('api/catalog/clone/metrics/jobs', CatalogCloneController::class, 'getJobsMetrics');
$router->get('api/catalog/clone/metrics/errors', CatalogCloneController::class, 'getTopErrors');
$router->get('api/catalog/clone/metrics/weekly', CatalogCloneController::class, 'getWeeklyComparison');

// FASE 6: Ações Pós-Clone
$router->get('api/catalog/clone/post-actions/stats', CatalogCloneController::class, 'getPostActionsStats');
$router->post('api/catalog/clone/post-actions/process', CatalogCloneController::class, 'processPostActions');

// FASE 6: Monitoramento e Hardening
$router->get('api/catalog/clone/monitoring/health', CatalogCloneController::class, 'getSystemHealth');
$router->get('api/catalog/clone/monitoring/alerts', CatalogCloneController::class, 'listAlerts');
$router->post('api/catalog/clone/monitoring/alerts/{id}/acknowledge', CatalogCloneController::class, 'acknowledgeAlert');
$router->get('api/catalog/clone/monitoring/flags', CatalogCloneController::class, 'listFeatureFlags');
$router->put('api/catalog/clone/monitoring/flags/{name}', CatalogCloneController::class, 'updateFeatureFlag');
$router->get('api/catalog/clone/monitoring/report', CatalogCloneController::class, 'getDailyReport');

// FASE 9: Notificações Slack/Discord
$router->get('api/clone/notifications/webhooks', CloneNotificationController::class, 'listWebhooks');
$router->post('api/clone/notifications/slack', CloneNotificationController::class, 'configureSlack');
$router->post('api/clone/notifications/discord', CloneNotificationController::class, 'configureDiscord');
$router->post('api/clone/notifications/webhook/{webhookId}/test', CloneNotificationController::class, 'testWebhook');
$router->put('api/clone/notifications/webhook/{webhookId}/enable', CloneNotificationController::class, 'enableWebhook');
$router->put('api/clone/notifications/webhook/{webhookId}/disable', CloneNotificationController::class, 'disableWebhook');
$router->delete('api/clone/notifications/webhook/{webhookId}', CloneNotificationController::class, 'deleteWebhook');
$router->get('api/clone/notifications/history', CloneNotificationController::class, 'getHistory');
$router->get('api/clone/notifications/events', CloneNotificationController::class, 'listEvents');

// FASE 9: A/B Testing de Variações
$router->get('api/clone/ab-tests', CloneABTestingController::class, 'listTests');
$router->post('api/clone/ab-tests', CloneABTestingController::class, 'createTest');
$router->get('api/clone/ab-tests/{testId}', CloneABTestingController::class, 'getTest');
$router->post('api/clone/ab-tests/{testId}/start', CloneABTestingController::class, 'startTest');
$router->post('api/clone/ab-tests/{testId}/pause', CloneABTestingController::class, 'pauseTest');
$router->post('api/clone/ab-tests/{testId}/complete', CloneABTestingController::class, 'completeTest');
$router->delete('api/clone/ab-tests/{testId}', CloneABTestingController::class, 'cancelTest');
$router->post('api/clone/ab-tests/{testId}/apply-winner', CloneABTestingController::class, 'applyWinner');
$router->post('api/clone/ab-tests/{testId}/sync-metrics', CloneABTestingController::class, 'syncMetrics');
$router->get('api/clone/ab-tests/{testId}/winner', CloneABTestingController::class, 'getWinner');
$router->post('api/clone/ab-tests/generate-variations', CloneABTestingController::class, 'generateVariations');
$router->post('api/clone/ab-tests/variations/{variationId}/metrics', CloneABTestingController::class, 'recordMetrics');

// FASE 9: Auto-Clonagem Programada por Regras
$router->get('api/clone/automation/rules', CloneAutomationController::class, 'listRules');
$router->post('api/clone/automation/rules', CloneAutomationController::class, 'createRule');
$router->get('api/clone/automation/rules/{id}', CloneAutomationController::class, 'getRule');
$router->put('api/clone/automation/rules/{id}', CloneAutomationController::class, 'updateRule');
$router->delete('api/clone/automation/rules/{id}', CloneAutomationController::class, 'deleteRule');
$router->post('api/clone/automation/rules/{id}/enable', CloneAutomationController::class, 'enableRule');
$router->post('api/clone/automation/rules/{id}/pause', CloneAutomationController::class, 'pauseRule');
$router->post('api/clone/automation/rules/{id}/execute', CloneAutomationController::class, 'executeRule');
$router->post('api/clone/automation/rules/{id}/preview', CloneAutomationController::class, 'previewExecution');
$router->get('api/clone/automation/rules/{id}/history', CloneAutomationController::class, 'getExecutionHistory');
$router->get('api/clone/automation/stats', CloneAutomationController::class, 'getStats');
$router->get('api/clone/automation/triggers', CloneAutomationController::class, 'getTriggerTypes');

// FASE 10: Recomendações de Sellers (ML-powered)
$router->get('api/clone/recommendations/sellers', CloneSellerRecommendationController::class, 'getRecommendations');
$router->get('api/clone/recommendations/sellers/{sellerId}/similar', CloneSellerRecommendationController::class, 'getSimilarSellers');
$router->get('api/clone/recommendations/sellers/by-category', CloneSellerRecommendationController::class, 'getTopByCategory');
$router->get('api/clone/recommendations/trends', CloneSellerRecommendationController::class, 'getTrends');
$router->get('api/clone/recommendations/stats', CloneSellerRecommendationController::class, 'getStats');

// FASE 10: Análise de ROI e Performance
$router->get('api/clone/roi/analysis', CloneROIAnalysisController::class, 'getAnalysis');
$router->get('api/clone/roi/items/{itemId}', CloneROIAnalysisController::class, 'getItemComparison');
$router->post('api/clone/roi/items/{itemId}/metrics', CloneROIAnalysisController::class, 'recordMetrics');
$router->post('api/clone/roi/sync', CloneROIAnalysisController::class, 'syncMetrics');
$router->get('api/clone/roi/timeline', CloneROIAnalysisController::class, 'getTimeline');

// FASE 10: SEO Integration no Fluxo de Clone
$router->post('api/clone/seo/optimize', CloneSEOIntegrationController::class, 'optimize');
$router->post('api/clone/seo/score', CloneSEOIntegrationController::class, 'getScore');
$router->get('api/clone/seo/settings', CloneSEOIntegrationController::class, 'getSettings');
$router->put('api/clone/seo/settings', CloneSEOIntegrationController::class, 'updateSettings');
$router->get('api/clone/seo/stats', CloneSEOIntegrationController::class, 'getStats');
$router->post('api/clone/seo/preview-batch', CloneSEOIntegrationController::class, 'previewBatch');

// FASE 11: Compliance e Auditoria
$router->get('api/clone/compliance/logs', \App\Controllers\CloneComplianceController::class, 'getLogs');
$router->get('api/clone/compliance/jobs/{jobId}/trail', \App\Controllers\CloneComplianceController::class, 'getJobTrail');
$router->get('api/clone/compliance/items/{itemId}/trail', \App\Controllers\CloneComplianceController::class, 'getItemTrail');
$router->get('api/clone/compliance/report', \App\Controllers\CloneComplianceController::class, 'getReport');
$router->get('api/clone/compliance/stats', \App\Controllers\CloneComplianceController::class, 'getStats');
$router->post('api/clone/compliance/export', \App\Controllers\CloneComplianceController::class, 'exportLogs');
$router->get('api/clone/compliance/download/{filename}', \App\Controllers\CloneComplianceController::class, 'downloadExport');
$router->get('api/clone/compliance/event-types', \App\Controllers\CloneComplianceController::class, 'getEventTypes');

// FASE 11: Analytics Integration
$router->get('api/clone/analytics/dashboard', \App\Controllers\CloneAnalyticsController::class, 'getDashboard');
$router->get('api/clone/analytics/kpis', \App\Controllers\CloneAnalyticsController::class, 'getKPIs');
$router->get('api/clone/analytics/trends', \App\Controllers\CloneAnalyticsController::class, 'getTrends');
$router->get('api/clone/analytics/performance', \App\Controllers\CloneAnalyticsController::class, 'getPerformance');
$router->get('api/clone/analytics/breakdown', \App\Controllers\CloneAnalyticsController::class, 'getBreakdown');
$router->get('api/clone/analytics/compare', \App\Controllers\CloneAnalyticsController::class, 'comparePeriods');
$router->get('api/clone/analytics/projection', \App\Controllers\CloneAnalyticsController::class, 'getProjection');
$router->post('api/clone/analytics/events', \App\Controllers\CloneAnalyticsController::class, 'trackEvent');
$router->get('api/clone/analytics/events', \App\Controllers\CloneAnalyticsController::class, 'getEvents');

// FASE 12: Gerenciamento de Itens Clonados e Sincronização
$router->get('api/clone/items', CloneManagementController::class, 'listItems');
$router->get('api/clone/items/stats', CloneManagementController::class, 'getStats');
$router->get('api/clone/items/top-sellers', CloneManagementController::class, 'getTopSellers');
$router->get('api/clone/items/attention', CloneManagementController::class, 'getItemsNeedingAttention');
$router->get('api/clone/items/distribution/category', CloneManagementController::class, 'getCategoryDistribution');
$router->get('api/clone/items/distribution/seller', CloneManagementController::class, 'getSellerDistribution');
$router->get('api/clone/items/export', CloneManagementController::class, 'exportItems');
$router->get('api/clone/items/{itemId}', CloneManagementController::class, 'getItem');
$router->put('api/clone/items/{itemId}', CloneManagementController::class, 'updateItem');
$router->post('api/clone/items/{itemId}/pause', CloneManagementController::class, 'pauseItem');
$router->post('api/clone/items/{itemId}/activate', CloneManagementController::class, 'activateItem');
$router->post('api/clone/items/{itemId}/close', CloneManagementController::class, 'closeItem');
$router->post('api/clone/items/batch', CloneManagementController::class, 'batchOperation');

// FASE 12: Sincronização Bidirecional
$router->post('api/clone/sync/all', CloneManagementController::class, 'syncAll');
$router->post('api/clone/sync/item/{itemId}', CloneManagementController::class, 'syncItem');
$router->put('api/clone/sync/price/{itemId}', CloneManagementController::class, 'updatePrice');
$router->put('api/clone/sync/stock/{itemId}', CloneManagementController::class, 'updateStock');
$router->put('api/clone/sync/status/{itemId}', CloneManagementController::class, 'updateStatus');
$router->post('api/clone/sync/prices/batch', CloneManagementController::class, 'batchUpdatePrices');
$router->get('api/clone/sync/history/{itemId}', CloneManagementController::class, 'getSyncHistory');
$router->get('api/clone/sync/settings', CloneManagementController::class, 'getSyncSettings');
$router->put('api/clone/sync/settings', CloneManagementController::class, 'updateSyncSettings');
$router->get('api/clone/sync/alerts', CloneManagementController::class, 'getAlerts');
$router->post('api/clone/sync/alerts/{alertId}/resolve', CloneManagementController::class, 'resolveAlert');

// FASE 12+: SEO Avançado, Export, Health, Batch Operations
$router->post('api/clone/seo/analyze', \App\Controllers\CloneAdvancedController::class, 'analyzeSeo');
$router->post('api/clone/seo/analyze/batch', \App\Controllers\CloneAdvancedController::class, 'analyzeBatchSeo');
$router->post('api/clone/seo/optimize/title', \App\Controllers\CloneAdvancedController::class, 'optimizeTitle');
$router->post('api/clone/seo/optimize/description', \App\Controllers\CloneAdvancedController::class, 'optimizeDescription');

// Export de Dados
$router->post('api/clone/export/items/csv', \App\Controllers\CloneAdvancedController::class, 'exportItemsCsv');
$router->post('api/clone/export/items/json', \App\Controllers\CloneAdvancedController::class, 'exportItemsJson');
$router->post('api/clone/export/jobs', \App\Controllers\CloneAdvancedController::class, 'exportJobs');
$router->post('api/clone/export/metrics', \App\Controllers\CloneAdvancedController::class, 'exportMetrics');
$router->post('api/clone/export/report', \App\Controllers\CloneAdvancedController::class, 'exportFullReport');
$router->get('api/clone/export/list', \App\Controllers\CloneAdvancedController::class, 'listExports');
$router->get('api/clone/export/download/{filename}', \App\Controllers\CloneAdvancedController::class, 'downloadExport');

// Health Monitoring
$router->get('api/clone/health', \App\Controllers\CloneAdvancedController::class, 'getHealth');
$router->get('api/clone/health/diagnostics', \App\Controllers\CloneAdvancedController::class, 'getDiagnostics');

// Batch Operations Avançadas
$router->post('api/clone/batch/repricing', \App\Controllers\CloneAdvancedController::class, 'batchRepricing');
$router->post('api/clone/batch/stock', \App\Controllers\CloneAdvancedController::class, 'batchStockUpdate');
$router->post('api/clone/batch/status', \App\Controllers\CloneAdvancedController::class, 'batchStatusChange');
$router->post('api/clone/batch/titles', \App\Controllers\CloneAdvancedController::class, 'batchTitleUpdate');
$router->post('api/clone/batch/prices', \App\Controllers\CloneAdvancedController::class, 'batchPriceUpdate');
$router->post('api/clone/batch/sync-metrics', \App\Controllers\CloneAdvancedController::class, 'batchSyncMetrics');
$router->post('api/clone/batch/seo-optimize', \App\Controllers\CloneAdvancedController::class, 'batchSeoOptimize');
$router->post('api/clone/batch/close-stale', \App\Controllers\CloneAdvancedController::class, 'closeStaleItems');
$router->get('api/clone/batch/history', \App\Controllers\CloneAdvancedController::class, 'getBatchHistory');

// Analytics Extras
$router->get('api/clone/analytics/summary', \App\Controllers\CloneAdvancedController::class, 'getAnalyticsSummary');

// Auto-Scheduler (Clonagem Automática Programada)
$router->get('api/clone/schedules', \App\Controllers\CloneSchedulerController::class, 'listSchedules');
$router->get('api/clone/schedules/stats', \App\Controllers\CloneSchedulerController::class, 'getScheduleStats');
$router->post('api/clone/schedules', \App\Controllers\CloneSchedulerController::class, 'createSchedule');
$router->get('api/clone/schedules/{id}', \App\Controllers\CloneSchedulerController::class, 'getSchedule');
$router->put('api/clone/schedules/{id}', \App\Controllers\CloneSchedulerController::class, 'updateSchedule');
$router->delete('api/clone/schedules/{id}', \App\Controllers\CloneSchedulerController::class, 'deleteSchedule');
$router->post('api/clone/schedules/{id}/pause', \App\Controllers\CloneSchedulerController::class, 'pauseSchedule');
$router->post('api/clone/schedules/{id}/resume', \App\Controllers\CloneSchedulerController::class, 'resumeSchedule');
$router->post('api/clone/schedules/{id}/execute', \App\Controllers\CloneSchedulerController::class, 'executeSchedule');
$router->get('api/clone/schedules/{id}/history', \App\Controllers\CloneSchedulerController::class, 'getScheduleHistory');

// ML Recommendations (Recomendações Inteligentes)
// Note: 'sellers' and 'trends' routes are defined above (CloneSellerRecommendationController, FASE 10)
$router->get('api/clone/recommendations/products', \App\Controllers\CloneSchedulerController::class, 'getProductRecommendations');
$router->get('api/clone/recommendations/categories', \App\Controllers\CloneSchedulerController::class, 'getCategoryRecommendations');
$router->get('api/clone/recommendations/predict/{itemId}', \App\Controllers\CloneSchedulerController::class, 'predictPerformance');

// Clone Event Triggers
$router->get('api/clone/triggers', \App\Controllers\CloneEventTriggerController::class, 'listTriggers');
$router->post('api/clone/triggers', \App\Controllers\CloneEventTriggerController::class, 'createTrigger');
$router->get('api/clone/triggers/stats', \App\Controllers\CloneEventTriggerController::class, 'getTriggerStats');
$router->get('api/clone/triggers/{triggerId}', \App\Controllers\CloneEventTriggerController::class, 'getTrigger');
$router->put('api/clone/triggers/{triggerId}', \App\Controllers\CloneEventTriggerController::class, 'updateTrigger');
$router->delete('api/clone/triggers/{triggerId}', \App\Controllers\CloneEventTriggerController::class, 'deleteTrigger');
$router->post('api/clone/triggers/{triggerId}/activate', \App\Controllers\CloneEventTriggerController::class, 'activateTrigger');
$router->post('api/clone/triggers/{triggerId}/deactivate', \App\Controllers\CloneEventTriggerController::class, 'deactivateTrigger');
$router->post('api/clone/triggers/{triggerId}/test', \App\Controllers\CloneEventTriggerController::class, 'testTrigger');
$router->get('api/clone/triggers/{triggerId}/history', \App\Controllers\CloneEventTriggerController::class, 'getTriggerHistory');

// Clone Trend Charts
$router->get('api/clone/charts/dashboard', \App\Controllers\CloneEventTriggerController::class, 'getDashboardCharts');
$router->get('api/clone/charts/clones-per-day', \App\Controllers\CloneEventTriggerController::class, 'getClonesPerDayChart');
$router->get('api/clone/charts/success-by-hour', \App\Controllers\CloneEventTriggerController::class, 'getSuccessByHourChart');
$router->get('api/clone/charts/by-category', \App\Controllers\CloneEventTriggerController::class, 'getByCategoryChart');
$router->get('api/clone/charts/seller-performance', \App\Controllers\CloneEventTriggerController::class, 'getSellerPerformanceChart');
$router->get('api/clone/charts/clone-time', \App\Controllers\CloneEventTriggerController::class, 'getCloneTimeChart');
$router->get('api/clone/charts/status-distribution', \App\Controllers\CloneEventTriggerController::class, 'getStatusDistributionChart');
$router->get('api/clone/charts/schedule-executions', \App\Controllers\CloneEventTriggerController::class, 'getScheduleExecutionsChart');
$router->get('api/clone/charts/events-by-type', \App\Controllers\CloneEventTriggerController::class, 'getEventsByTypeChart');
$router->get('api/clone/charts/quality-metrics', \App\Controllers\CloneEventTriggerController::class, 'getQualityMetricsChart');

// Rotas de categorias
$router->get('api/categories', CategoryController::class, 'index');
$router->get('api/categories/tree', CategoryController::class, 'tree');
$router->get('api/categories/search', CategoryController::class, 'search');
$router->get('api/categories/{id}', CategoryController::class, 'show');
$router->get('api/categories/{id}/brands', CategoryController::class, 'brands');
$router->get('api/categories/{id}/subcategories', CategoryController::class, 'subcategories');
$router->get('api/categories/{id}/attributes', CategoryController::class, 'attributes');
$router->get('api/categories/{id}/filterable-attributes', CategoryController::class, 'filterableAttributes');
$router->get('api/categories/{id}/required-attributes', CategoryController::class, 'requiredAttributes');
$router->post('api/categories/{id}/validate-attributes', CategoryController::class, 'validateAttributes');
$router->get('api/categories/{id}/attributes/{attributeId}/values', CategoryController::class, 'attributeValues');

// Rotas de busca
$router->get('api/search', SearchController::class, 'search');
$router->get('api/search/analyze', SearchController::class, 'analyze');
$router->get('api/search/lazy', SearchController::class, 'lazySearch');
$router->get('api/search/load-more', SearchController::class, 'loadMore');

// Rotas de anúncios (items)
$router->get('api/items', ItemController::class, 'index');
$router->get('api/items/stats', ItemController::class, 'stats');
$router->get('api/items/categories', ItemController::class, 'categories');
$router->get('api/items/{id}', ItemController::class, 'show');
$router->post('api/items', ItemController::class, 'create');
$router->put('api/items/{id}', ItemController::class, 'update');
$router->delete('api/items/{id}', ItemController::class, 'delete');
$router->post('api/items/sync', ItemController::class, 'sync');
$router->post('api/items/{id}/pause', ItemController::class, 'pause');
$router->post('api/items/{id}/activate', ItemController::class, 'activate');
$router->post('api/items/{id}/close', ItemController::class, 'close');
$router->put('api/items/{id}/price', ItemController::class, 'updatePrice');
$router->put('api/items/{id}/stock', ItemController::class, 'updateStock');
$router->put('api/items/{id}/description', ItemController::class, 'updateDescription');
$router->get('api/items/status/{status}', ItemController::class, 'byStatus');
$router->get('api/items/category/{categoryId}', ItemController::class, 'byCategory');

// Rotas de exportação
$router->get('api/export/analysis/csv', ExportController::class, 'analysisCSV');
$router->get('api/export/analysis/json', ExportController::class, 'analysisJSON');
$router->get('api/export/report/pdf', ExportController::class, 'reportPDF');
$router->get('api/export/user/json', ExportController::class, 'userDataJSON');
$router->get('api/export/user/csv', ExportController::class, 'userDataCSV');

// Rotas de backup
$router->get('api/backup/list', BackupController::class, 'list');
$router->post('api/backup/create', BackupController::class, 'create');
$router->post('api/backup/restore', BackupController::class, 'restore');
$router->post('api/backup/clean', BackupController::class, 'clean');

// Rotas de estatísticas
$router->get('api/statistics', StatisticsController::class, 'index');
$router->get('api/statistics/period', StatisticsController::class, 'byPeriod');
$router->get('api/statistics/top-products', StatisticsController::class, 'topProducts');
$router->get('api/statistics/by-category', StatisticsController::class, 'byCategory');

// Rotas de analytics
$router->get('api/analytics/dashboard', AnalyticsController::class, 'getDashboard');
$router->get('api/analytics/api-stats', AnalyticsController::class, 'apiStats');
$router->get('api/analytics/requests-chart', AnalyticsController::class, 'requestsChart');
$router->get('api/analytics/sales', AnalyticsController::class, 'salesMetrics');
$router->get('api/analytics/listings', AnalyticsController::class, 'listingsMetrics');
$router->get('api/analytics/questions', AnalyticsController::class, 'questionsMetrics');
$router->get('api/analytics/export', AnalyticsController::class, 'exportReport');

// Rotas de notificações
$router->get('api/notifications', NotificationController::class, 'index');
$router->get('api/notifications/unread-count', NotificationController::class, 'unreadCount');
$router->post('api/notifications/{id}/read', NotificationController::class, 'markAsRead');
$router->post('api/notifications/mark-all-read', NotificationController::class, 'markAllAsRead');
$router->delete('api/notifications/{id}', NotificationController::class, 'delete');
$router->delete('api/notifications/clear-old', NotificationController::class, 'clearOld');
$router->get('api/notifications/preferences', NotificationController::class, 'preferences');
$router->put('api/notifications/preferences', NotificationController::class, 'updatePreferences');

// Rotas de sincronização
$router->get('api/sync/status', SyncController::class, 'status');
$router->post('api/sync/trigger', SyncController::class, 'trigger');
