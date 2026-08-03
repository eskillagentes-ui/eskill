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

// 💰 Pricing Intelligence - Precificador Inteligente
// ========================================
$router->get('api/pricing-intelligence/{accountId}/status', \App\Controllers\PricingIntelligenceController::class, 'getStatus');
$router->post('api/pricing-intelligence/{accountId}/refresh-token', \App\Controllers\PricingIntelligenceController::class, 'refreshToken');
$router->post('api/pricing-intelligence/{accountId}/margin/calculate', \App\Controllers\PricingIntelligenceController::class, 'calculateMargin');
$router->post('api/pricing-intelligence/{accountId}/margin/minimum', \App\Controllers\PricingIntelligenceController::class, 'calculateMinimumPrice');
$router->post('api/pricing-intelligence/{accountId}/simulate-discount', \App\Controllers\PricingIntelligenceController::class, 'simulateDiscount');
$router->post('api/pricing-intelligence/{accountId}/ranking-impact', \App\Controllers\PricingIntelligenceController::class, 'analyzeRankingImpact');
$router->get('api/pricing-intelligence/{accountId}/costs/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'getCosts');
$router->post('api/pricing-intelligence/{accountId}/costs/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'saveCosts');
$router->get('api/pricing-intelligence/{accountId}/history/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'getHistory');
$router->get('api/pricing-intelligence/{accountId}/competitors/{categoryId}', \App\Controllers\PricingIntelligenceController::class, 'analyzeCompetitors');
$router->post('api/pricing-intelligence/{accountId}/suggest-price', \App\Controllers\PricingIntelligenceController::class, 'suggestPrice');
$router->get('api/pricing-intelligence/{accountId}/dashboard', \App\Controllers\PricingIntelligenceController::class, 'getDashboard');
$router->get('api/pricing-intelligence/{accountId}/alerts', \App\Controllers\PricingIntelligenceController::class, 'getAlerts');
$router->post('api/pricing-intelligence/{accountId}/alerts/{alertId}/read', \App\Controllers\PricingIntelligenceController::class, 'markAlertRead');
$router->post('api/pricing-intelligence/{accountId}/apply/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'applyPrice');
$router->get('api/pricing-intelligence/{accountId}/items', \App\Controllers\PricingIntelligenceController::class, 'listItems');
$router->post('api/pricing-intelligence/{accountId}/bulk-costs', \App\Controllers\PricingIntelligenceController::class, 'bulkSaveCosts');

// Ranking Alert Service - Análise e monitoramento de posição
$router->get('api/pricing-intelligence/{accountId}/alerts/analyze/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'analyzeItemRanking');
$router->post('api/pricing-intelligence/{accountId}/alerts/analyze-batch', \App\Controllers\PricingIntelligenceController::class, 'analyzeItemsBatch');
$router->get('api/pricing-intelligence/{accountId}/alerts/unresolved', \App\Controllers\PricingIntelligenceController::class, 'getUnresolvedAlerts');
$router->post('api/pricing-intelligence/{accountId}/alerts/mark-read', \App\Controllers\PricingIntelligenceController::class, 'markAlertsRead');
$router->post('api/pricing-intelligence/{accountId}/alerts/{alertId}/resolve', \App\Controllers\PricingIntelligenceController::class, 'resolveAlert');
$router->get('api/pricing-intelligence/{accountId}/alerts/item/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'getItemAlertHistory');
$router->get('api/pricing-intelligence/{accountId}/alerts/stats', \App\Controllers\PricingIntelligenceController::class, 'getAlertStats');

// Promotion Simulator - Simulador de Promoções
$router->post('api/pricing-intelligence/{accountId}/promotion/simulate/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'simulatePromotion');
$router->post('api/pricing-intelligence/{accountId}/promotion/scenarios/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'getPromotionScenarios');
$router->post('api/pricing-intelligence/{accountId}/promotion/apply/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'applyPromotion');
$router->post('api/pricing-intelligence/{accountId}/promotion/central-ofertas/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'simulateCentralOfertas');
$router->get('api/pricing-intelligence/{accountId}/promotion/history', \App\Controllers\PricingIntelligenceController::class, 'getPromotionHistory');
$router->get('api/pricing-intelligence/{accountId}/promotion/history/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'getPromotionHistory');

// Pricing Scenarios - Cenários e Estratégias
$router->get('api/pricing-intelligence/{accountId}/scenarios/strategies/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'compareStrategies');
$router->post('api/pricing-intelligence/{accountId}/scenarios/what-if/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'createWhatIfScenario');
$router->get('api/pricing-intelligence/{accountId}/scenarios/strategies', \App\Controllers\PricingIntelligenceController::class, 'listStrategies');

// Pricing Rules - Regras de Precificação Automática
$router->get('api/pricing-intelligence/{accountId}/rules', \App\Controllers\PricingIntelligenceController::class, 'listPricingRules');
$router->post('api/pricing-intelligence/{accountId}/rules', \App\Controllers\PricingIntelligenceController::class, 'createPricingRule');
$router->post('api/pricing-intelligence/{accountId}/rules/{ruleId}/execute', \App\Controllers\PricingIntelligenceController::class, 'executePricingRule');
$router->post('api/pricing-intelligence/{accountId}/rules/{ruleId}/toggle', \App\Controllers\PricingIntelligenceController::class, 'togglePricingRule');
$router->delete('api/pricing-intelligence/{accountId}/rules/{ruleId}', \App\Controllers\PricingIntelligenceController::class, 'deletePricingRule');

// Exportação e Relatórios
$router->get('api/pricing-intelligence/{accountId}/export/csv', \App\Controllers\PricingIntelligenceController::class, 'exportCsv');
$router->get('api/pricing-intelligence/{accountId}/export/history', \App\Controllers\PricingIntelligenceController::class, 'exportHistory');

// Análise de Tendências e Métricas
$router->get('api/pricing-intelligence/{accountId}/trends/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'getItemTrends');
$router->get('api/pricing-intelligence/{accountId}/metrics', \App\Controllers\PricingIntelligenceController::class, 'getAdvancedMetrics');

// Relatórios de Performance
$router->get('api/pricing-intelligence/{accountId}/performance', \App\Controllers\PricingIntelligenceController::class, 'getPerformanceReport');

// Sugestão Automática de Preço
$router->post('api/pricing-intelligence/{accountId}/auto-suggest/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'autoSuggestPrice');

// Monitoramento de Concorrentes
$router->get('api/pricing-intelligence/{accountId}/monitor/competitors/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'monitorCompetitors');

// Previsão de Margem
$router->post('api/pricing-intelligence/{accountId}/forecast', \App\Controllers\PricingIntelligenceController::class, 'forecastMargin');

// Alertas de Preço
$router->get('api/pricing-intelligence/{accountId}/price-alerts', \App\Controllers\PricingIntelligenceController::class, 'listPriceAlerts');
$router->post('api/pricing-intelligence/{accountId}/price-alerts', \App\Controllers\PricingIntelligenceController::class, 'createPriceAlert');
$router->delete('api/pricing-intelligence/{accountId}/price-alerts/{alertId}', \App\Controllers\PricingIntelligenceController::class, 'deletePriceAlert');

// Importação de Custos
$router->post('api/pricing-intelligence/{accountId}/import/costs', \App\Controllers\PricingIntelligenceController::class, 'importCosts');

// Preço Ideal e Rentabilidade
$router->post('api/pricing-intelligence/{accountId}/calculate-ideal-price', \App\Controllers\PricingIntelligenceController::class, 'calculateIdealPrice');
$router->get('api/pricing-intelligence/{accountId}/profitability', \App\Controllers\PricingIntelligenceController::class, 'analyzeProfitability');

// Auto-Otimizador de Preços
$router->get('api/pricing-intelligence/{accountId}/auto-optimizer/config', \App\Controllers\PricingIntelligenceController::class, 'getAutoOptimizerConfig');
$router->post('api/pricing-intelligence/{accountId}/auto-optimizer/config', \App\Controllers\PricingIntelligenceController::class, 'saveAutoOptimizerConfig');
$router->post('api/pricing-intelligence/{accountId}/auto-optimizer/run', \App\Controllers\PricingIntelligenceController::class, 'runAutoOptimizer');
$router->get('api/pricing-intelligence/{accountId}/auto-optimizer/analyze/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'analyzeItemForOptimization');
$router->get('api/pricing-intelligence/{accountId}/auto-optimizer/stats', \App\Controllers\PricingIntelligenceController::class, 'getAutoOptimizerStats');
$router->get('api/pricing-intelligence/{accountId}/auto-optimizer/history', \App\Controllers\PricingIntelligenceController::class, 'getAutoOptimizerHistory');
$router->post('api/pricing-intelligence/{accountId}/auto-optimizer/apply/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'applyOptimizerSuggestion');

// Testes A/B de Preços
$router->get('api/pricing-intelligence/{accountId}/ab-tests', \App\Controllers\PricingIntelligenceController::class, 'listAbTests');
$router->post('api/pricing-intelligence/{accountId}/ab-tests', \App\Controllers\PricingIntelligenceController::class, 'createAbTest');
$router->get('api/pricing-intelligence/{accountId}/ab-tests/stats', \App\Controllers\PricingIntelligenceController::class, 'getAbTestStats');
$router->get('api/pricing-intelligence/{accountId}/ab-tests/{testId}', \App\Controllers\PricingIntelligenceController::class, 'getAbTest');
$router->post('api/pricing-intelligence/{accountId}/ab-tests/{testId}/start', \App\Controllers\PricingIntelligenceController::class, 'startAbTest');
$router->post('api/pricing-intelligence/{accountId}/ab-tests/{testId}/pause', \App\Controllers\PricingIntelligenceController::class, 'pauseAbTest');
$router->post('api/pricing-intelligence/{accountId}/ab-tests/{testId}/complete', \App\Controllers\PricingIntelligenceController::class, 'completeAbTest');
$router->post('api/pricing-intelligence/{accountId}/ab-tests/{testId}/cancel', \App\Controllers\PricingIntelligenceController::class, 'cancelAbTest');
$router->get('api/pricing-intelligence/{accountId}/ab-tests/{testId}/analyze', \App\Controllers\PricingIntelligenceController::class, 'analyzeAbTest');
$router->get('api/pricing-intelligence/{accountId}/ab-tests/{testId}/results', \App\Controllers\PricingIntelligenceController::class, 'getAbTestResults');
$router->post('api/pricing-intelligence/{accountId}/ab-tests/{testId}/results', \App\Controllers\PricingIntelligenceController::class, 'recordAbTestResults');
$router->get('api/pricing-intelligence/{accountId}/ab-tests/{testId}/log', \App\Controllers\PricingIntelligenceController::class, 'getAbTestLog');

// Monitoramento de Concorrentes
$router->get('api/pricing-intelligence/{accountId}/competitors/watchlist', \App\Controllers\PricingIntelligenceController::class, 'getWatchlist');
$router->post('api/pricing-intelligence/{accountId}/competitors/watchlist', \App\Controllers\PricingIntelligenceController::class, 'addToWatchlist');
$router->delete('api/pricing-intelligence/{accountId}/competitors/watchlist/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'removeFromWatchlist');
$router->get('api/pricing-intelligence/{accountId}/competitors/scan/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'scanCompetitors');
$router->get('api/pricing-intelligence/{accountId}/competitors/analysis/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'getMarketAnalysis');
$router->get('api/pricing-intelligence/{accountId}/competitors/alerts', \App\Controllers\PricingIntelligenceController::class, 'getMarketAlerts');
$router->post('api/pricing-intelligence/{accountId}/competitors/alerts/read', \App\Controllers\PricingIntelligenceController::class, 'markMarketAlertsAsRead');
$router->get('api/pricing-intelligence/{accountId}/competitors/stats', \App\Controllers\PricingIntelligenceController::class, 'getMonitoringStats');

// Ações em Lote
$router->post('api/pricing-intelligence/{accountId}/bulk/apply-rules', \App\Controllers\PricingIntelligenceController::class, 'bulkApplyRule');
$router->post('api/pricing-intelligence/{accountId}/bulk/update-costs', \App\Controllers\PricingIntelligenceController::class, 'bulkUpdateCosts');

// ========================================
// 🚀 PHASE 3: Advanced Pricing Features
// ========================================

// Rules Engine (Motor de Regras Avançado)
$router->post('api/pricing-intelligence/{accountId}/rules-engine', \App\Controllers\PricingIntelligenceController::class, 'createEngineRule');
$router->get('api/pricing-intelligence/{accountId}/rules-engine', \App\Controllers\PricingIntelligenceController::class, 'listEngineRules');
$router->get('api/pricing-intelligence/{accountId}/rules-engine/templates', \App\Controllers\PricingIntelligenceController::class, 'getEngineRuleTemplates');
$router->get('api/pricing-intelligence/{accountId}/rules-engine/{ruleId}', \App\Controllers\PricingIntelligenceController::class, 'getEngineRule');
$router->put('api/pricing-intelligence/{accountId}/rules-engine/{ruleId}', \App\Controllers\PricingIntelligenceController::class, 'updateEngineRule');
$router->delete('api/pricing-intelligence/{accountId}/rules-engine/{ruleId}', \App\Controllers\PricingIntelligenceController::class, 'deleteEngineRule');
$router->post('api/pricing-intelligence/{accountId}/rules-engine/{ruleId}/toggle', \App\Controllers\PricingIntelligenceController::class, 'toggleEngineRule');
$router->post('api/pricing-intelligence/{accountId}/rules-engine/execute/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'executeEngineRulesForItem');
$router->post('api/pricing-intelligence/{accountId}/rules-engine/execute-all', \App\Controllers\PricingIntelligenceController::class, 'executeAllEngineRules');
$router->post('api/pricing-intelligence/{accountId}/rules-engine/simulate', \App\Controllers\PricingIntelligenceController::class, 'simulateEngineRules');

// Scheduled Prices (Agendamento de Preços)
$router->post('api/pricing-intelligence/{accountId}/schedules', \App\Controllers\PricingIntelligenceController::class, 'createSchedule');
$router->get('api/pricing-intelligence/{accountId}/schedules', \App\Controllers\PricingIntelligenceController::class, 'listSchedules');
$router->get('api/pricing-intelligence/{accountId}/schedules/calendar', \App\Controllers\PricingIntelligenceController::class, 'getScheduleCalendar');
$router->get('api/pricing-intelligence/{accountId}/schedules/summary', \App\Controllers\PricingIntelligenceController::class, 'getScheduleSummary');
$router->post('api/pricing-intelligence/{accountId}/schedules/{scheduleId}/cancel', \App\Controllers\PricingIntelligenceController::class, 'cancelSchedule');
$router->post('api/pricing-intelligence/{accountId}/schedules/campaign', \App\Controllers\PricingIntelligenceController::class, 'createCampaign');
$router->get('api/pricing-intelligence/{accountId}/schedules/campaigns', \App\Controllers\PricingIntelligenceController::class, 'listCampaigns');
$router->post('api/pricing-intelligence/{accountId}/schedules/campaign/{campaignId}/cancel', \App\Controllers\PricingIntelligenceController::class, 'cancelCampaign');

// Price Analytics (Analytics Avançados)
$router->get('api/pricing-intelligence/{accountId}/analytics/dashboard', \App\Controllers\PricingIntelligenceController::class, 'getAnalyticsDashboard');
$router->get('api/pricing-intelligence/{accountId}/analytics/trend/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'getPriceTrend');
$router->get('api/pricing-intelligence/{accountId}/analytics/elasticity/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'analyzeElasticity');
$router->get('api/pricing-intelligence/{accountId}/analytics/competitive/{categoryId}', \App\Controllers\PricingIntelligenceController::class, 'getCompetitiveAnalysis');
$router->post('api/pricing-intelligence/{accountId}/analytics/roi', \App\Controllers\PricingIntelligenceController::class, 'calculatePriceChangeROI');
$router->get('api/pricing-intelligence/{accountId}/analytics/forecast/{itemId}', \App\Controllers\PricingIntelligenceController::class, 'forecastPrice');
$router->get('api/pricing-intelligence/{accountId}/analytics/report', \App\Controllers\PricingIntelligenceController::class, 'generateAnalyticsReport');

// Bulk Price Editor (Editor em Massa)
$router->post('api/pricing-intelligence/{accountId}/bulk-editor/preview', \App\Controllers\PricingIntelligenceController::class, 'previewBulkEdit');
$router->post('api/pricing-intelligence/{accountId}/bulk-editor/apply', \App\Controllers\PricingIntelligenceController::class, 'applyBulkEdit');
$router->get('api/pricing-intelligence/{accountId}/bulk-editor/batches', \App\Controllers\PricingIntelligenceController::class, 'listBulkBatches');
$router->get('api/pricing-intelligence/{accountId}/bulk-editor/templates', \App\Controllers\PricingIntelligenceController::class, 'getBulkOperationTemplates');
$router->get('api/pricing-intelligence/{accountId}/bulk-editor/{batchId}', \App\Controllers\PricingIntelligenceController::class, 'getBulkBatch');
$router->post('api/pricing-intelligence/{accountId}/bulk-editor/{batchId}/rollback', \App\Controllers\PricingIntelligenceController::class, 'rollbackBulkEdit');

// Notifications (Alertas e Notificações)
$router->post('api/pricing-intelligence/{accountId}/notifications/send', \App\Controllers\PricingIntelligenceController::class, 'sendNotification');
$router->post('api/pricing-intelligence/{accountId}/notifications/channels', \App\Controllers\PricingIntelligenceController::class, 'createNotificationChannel');
$router->get('api/pricing-intelligence/{accountId}/notifications/channels', \App\Controllers\PricingIntelligenceController::class, 'listNotificationChannels');
$router->put('api/pricing-intelligence/{accountId}/notifications/channels/{channelId}', \App\Controllers\PricingIntelligenceController::class, 'updateNotificationChannel');
$router->delete('api/pricing-intelligence/{accountId}/notifications/channels/{channelId}', \App\Controllers\PricingIntelligenceController::class, 'deleteNotificationChannel');
$router->post('api/pricing-intelligence/{accountId}/notifications/channels/{channelId}/test', \App\Controllers\PricingIntelligenceController::class, 'testNotificationChannel');
$router->post('api/pricing-intelligence/{accountId}/notifications/channels/{channelId}/subscribe', \App\Controllers\PricingIntelligenceController::class, 'subscribeToEvent');
$router->delete('api/pricing-intelligence/{accountId}/notifications/channels/{channelId}/subscribe/{event}', \App\Controllers\PricingIntelligenceController::class, 'unsubscribeFromEvent');
$router->get('api/pricing-intelligence/{accountId}/notifications/channels/{channelId}/subscriptions', \App\Controllers\PricingIntelligenceController::class, 'getChannelSubscriptions');
$router->get('api/pricing-intelligence/{accountId}/notifications/history', \App\Controllers\PricingIntelligenceController::class, 'getNotificationHistory');
$router->get('api/pricing-intelligence/{accountId}/notifications/events', \App\Controllers\PricingIntelligenceController::class, 'getNotificationEvents');

// AI Predictions - Previsões com Machine Learning
$router->get('api/ai/{accountId}/predict-sales/{itemId}', AIPredictionsController::class, 'predictSales');
$router->get('api/ai/{accountId}/rising-stars', AIPredictionsController::class, 'identifyRisingStars');
$router->get('api/ai/{accountId}/best-promo-time/{itemId}', AIPredictionsController::class, 'predictBestPromotionTime');
$router->get('api/ai/{accountId}/category-demand/{categoryId}', AIPredictionsController::class, 'predictCategoryDemand');

// Chatbot AI - Atendimento Inteligente
$router->post('api/chatbot/{accountId}/process', ChatbotAIController::class, 'processMessage');
$router->get('api/chatbot/{accountId}/stats', ChatbotAIController::class, 'getStats');

// ========================================
// 🔥 AI Optimization Engine
// ========================================
$router->get('api/ai/items-by-score', AIOptimizationController::class, 'fetchItemsByScore');
$router->post('api/ai/optimize/title', AIOptimizationController::class, 'optimizeTitle');
$router->post('api/ai/optimize/description', AIOptimizationController::class, 'optimizeDescription');
$router->post('api/ai/optimize/tech-sheet', AIOptimizationController::class, 'optimizeTechSheet');
$router->post('api/ai/optimize/complete', AIOptimizationController::class, 'optimizeComplete');
$router->get('api/ai/suggestions/{itemId}', AIOptimizationController::class, 'suggestions');

// Batch Operations
$router->post('api/ai/batch/start', AIOptimizationController::class, 'startBatchOptimization');
$router->get('api/ai/batch/{batchId}/status', AIOptimizationController::class, 'getBatchStatus');
$router->get('api/ai/batch/{batchId}/results', AIOptimizationController::class, 'getBatchResults');
$router->get('api/ai/queue/stats', AIOptimizationController::class, 'getQueueStats');

// Preview & Apply
$router->post('api/ai/preview/generate', AIOptimizationController::class, 'generatePreview');
$router->post('api/ai/preview/{previewId}/apply', AIOptimizationController::class, 'applyPreview');

// Audit & History
$router->get('api/ai/audit/{itemId}/history', AIOptimizationController::class, 'getAuditHistory');
$router->post('api/ai/audit/{logId}/rollback', AIOptimizationController::class, 'rollbackOptimization');

// Analytics
$router->get('api/ai/analytics/dashboard', AIOptimizationController::class, 'getDashboardAnalytics');
$router->get('api/ai/analytics/summary', AIOptimizationController::class, 'getExecutiveSummary');
$router->get('api/ai/analytics/costs', AIOptimizationController::class, 'getCostAnalytics');

// ========================================
// 📊 Error Monitoring & Observability
// ========================================
$router->get('api/monitoring/errors/recent', ErrorMonitoringController::class, 'recent');
$router->get('api/monitoring/errors/stats', ErrorMonitoringController::class, 'stats');
$router->get('api/monitoring/errors/analyze-log', ErrorMonitoringController::class, 'analyzeLog');
$router->post('api/monitoring/errors/clean', ErrorMonitoringController::class, 'clean');
$router->post('api/monitoring/errors/log', ErrorMonitoringController::class, 'log');

// ========================================
// 🤖 AI-Powered Insights (v2.0.0)
// Powered by GPT-4 Turbo & Machine Learning
// ========================================

// === Strategic Insights ===
$router->post('api/ai/insights/strategic', AIController::class, 'generateStrategicInsights');
$router->post('api/ai/insights/ab-tests', AIController::class, 'suggestABTests');
$router->get('api/ai/insights/trends', AIController::class, 'analyzeTrends');
$router->post('api/ai/insights/explain-metric', AIController::class, 'explainMetric');
$router->get('api/ai/insights/recommendations', AIController::class, 'getPrioritizedRecommendations');
$router->get('api/ai/insights/sentiment', AIController::class, 'analyzeMarketSentiment');

// === Image Analysis (Computer Vision) ===
// Compat endpoint (body: { item_id })
$router->post('api/ai/images/analyze', AIController::class, 'analyzeProductImages');
// Novos endpoints usados pelo painel SEO Killer de Imagens
$router->get('api/ai/images/analyze/{itemId}', AIController::class, 'analyzeProductImagesById');
$router->post('api/ai/images/reorder/{itemId}', AIController::class, 'reorderImages');
$router->delete('api/ai/images/remove', AIController::class, 'removeImage');
$router->post('api/ai/images/upload', AIController::class, 'uploadImage');

$router->post('api/ai/images/compare-practices', AIController::class, 'compareWithBestPractices');
$router->post('api/ai/images/suggest-order', AIController::class, 'suggestOptimalOrder');
$router->post('api/ai/images/detect-similar', AIController::class, 'detectSimilarImages');

// === Dynamic Pricing (ML Optimization) ===
$router->post('api/ai/pricing/suggest', AIController::class, 'suggestOptimalPrice');
$router->post('api/ai/pricing/elasticity', AIController::class, 'analyzePriceElasticity');
$router->post('api/ai/pricing/optimize-margin', AIController::class, 'optimizeMargin');
$router->post('api/ai/pricing/dynamic-rules', AIController::class, 'createDynamicPricingRules');
$router->get('api/ai/pricing/competitive/{itemId}', AIController::class, 'analyzeCompetitivePricing');
$router->post('api/ai/pricing/forecast', AIController::class, 'forecastRevenue');

