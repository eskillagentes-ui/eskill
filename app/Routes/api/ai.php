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
use App\Controllers\ProxyController;

// === Conversational AI Assistant ===
$router->post('api/ai/chat', AIController::class, 'chat');
$router->post('api/ai/chat/explain-metric', AIController::class, 'explainMetricQuick');
$router->post('api/ai/chat/help-feature', AIController::class, 'helpWithFeature');
$router->get('api/ai/chat/suggest-actions', AIController::class, 'suggestNextActions');
$router->delete('api/ai/chat/history', AIController::class, 'clearHistory');

// ========================================
//  Competitor Monitoring Automation
// ========================================
$router->get('api/competitor/tracked', CompetitorMonitorController::class, 'getTracked');
$router->get('api/competitor/alerts', CompetitorMonitorController::class, 'getAlerts');
$router->get('api/competitor/stats', CompetitorMonitorController::class, 'getStats');
$router->post('api/competitor/track', CompetitorMonitorController::class, 'track');
$router->post('api/competitor/monitoring/start', CompetitorMonitorController::class, 'startMonitoring');
$router->post('api/competitor/monitoring/pause', CompetitorMonitorController::class, 'pauseMonitoring');
$router->post('api/competitor/toggle/{id}', CompetitorMonitorController::class, 'toggleMonitoring');
$router->delete('api/competitor/{id}', CompetitorMonitorController::class, 'remove');
$router->post('api/competitor/alert/{id}/read', CompetitorMonitorController::class, 'markAlertRead');
$router->post('api/competitor/settings', CompetitorMonitorController::class, 'saveSettings');

// ========================================
// 🔍 SEO API - Otimização com IA Real
// ========================================
use App\Controllers\SEOApiController;

// Status do serviço
$router->get('api/seo/status', SEOApiController::class, 'status');

// SEO Optimizer — canônico: SEOToolsController em api/items.php (POST /api/seo/analyze).
// Rota IA dedicada (evita sombra sobre o endpoint canônico).
$router->post('api/seo/ai/analyze', SEOApiController::class, 'analyze');
$router->post('api/seo/optimize-title', SEOApiController::class, 'optimizeTitle');
$router->post('api/seo/generate-description', SEOApiController::class, 'generateDescription');
$router->post('api/seo/keywords', SEOApiController::class, 'researchKeywords');
$router->post('api/seo/competitors', SEOApiController::class, 'analyzeCompetitors');
$router->post('api/seo/optimize', SEOApiController::class, 'optimizeProduct');

// SEO Dashboard (análise avançada)
$router->post('api/seo/analyze-product', SEOApiController::class, 'analyzeProduct');
$router->post('api/seo/analyze-keyword-gaps', SEOApiController::class, 'analyzeKeywordGaps');
$router->post('api/seo/analyze-semantic', SEOApiController::class, 'analyzeSemantic');
$router->post('api/seo/optimize-model-attribute', SEOApiController::class, 'optimizeModelAttribute');
$router->post('api/seo/monitor-optimization', SEOApiController::class, 'monitorOptimization');

// Tech Sheet (Ficha Técnica)
$router->post('api/seo/tech-sheet/generate', SEOApiController::class, 'generateTechSheet');
$router->post('api/seo/tech-sheet/extract', SEOApiController::class, 'extractFromTitle');
$router->post('api/seo/tech-sheet/complete', SEOApiController::class, 'completeTechSheet');
$router->post('api/seo/tech-sheet/validate', SEOApiController::class, 'validateTechSheet');
$router->post('api/seo/tech-sheet/suggest', SEOApiController::class, 'suggestAttributes');

// ========================================
// 🔑 TOKEN MANAGEMENT - Gerenciamento de Tokens ML
// ========================================

// Dashboard de Tokens
$router->get('api/tokens/dashboard', TokenDashboardController::class, 'getMetrics');
$router->get('api/tokens/accounts', TokenDashboardController::class, 'listAccounts');
$router->get('api/tokens/stats', TokenDashboardController::class, 'getStats');

// Renovação de Tokens
$router->post('api/tokens/refresh/{accountId}', TokenDashboardController::class, 'refreshAccount');
$router->post('api/tokens/refresh-all', TokenDashboardController::class, 'refreshAll');

// Auditoria e Histórico
$router->get('api/tokens/audit/{accountId}', TokenDashboardController::class, 'getAuditHistory');

// ========================================
// 🤖 AUTOMATION - Workflows e Automações
// ========================================

use App\Controllers\AutomationController;

$router->post('api/automation/workflow/create', AutomationController::class, 'createWorkflow');
$router->post('api/automation/workflow/{workflowId}/execute', AutomationController::class, 'executeWorkflow');
$router->post('api/automation/queue/process', AutomationController::class, 'processQueue');
$router->post('api/automation/smart-automation/create', AutomationController::class, 'createSmartAutomation');
$router->post('api/automation/optimize', AutomationController::class, 'optimizeAutomations');
$router->get('api/automation/dashboard', AutomationController::class, 'getDashboard');
$router->get('api/automation/workflow/templates', AutomationController::class, 'createWorkflowTemplates');
$router->post('api/automation/workflow/template/{templateId}/instantiate', AutomationController::class, 'instantiateFromTemplate');
$router->get('api/automation/workflow/{workflowId}/status', AutomationController::class, 'getWorkflowStatus');

// ========================================
// 📊 MONITORING - Monitoramento do Sistema
// ========================================

use App\Controllers\MonitoringController;

$router->get('api/monitoring/realtime-metrics', MonitoringController::class, 'realTimeMetrics');
$router->get('api/monitoring/alerts', MonitoringController::class, 'checkAlerts');
$router->get('api/monitoring/performance-report', MonitoringController::class, 'performanceReport');
$router->get('api/monitoring/job-stats', MonitoringController::class, 'jobStats');
$router->get('api/monitoring/feature-flags', MonitoringController::class, 'featureFlags');
$router->post('api/monitoring/feature-flags', MonitoringController::class, 'featureFlags');
$router->get('api/monitoring/logs', MonitoringController::class, 'systemLogs');
$router->get('api/monitoring/system-logs', MonitoringController::class, 'systemLogs');
$router->get('api/monitoring/health', MonitoringController::class, 'healthCheck');
$router->get('api/monitoring/system-metrics', MonitoringController::class, 'systemMetrics');
$router->get('api/monitoring/health-advanced', MonitoringController::class, 'healthAdvanced');
$router->get('api/monitoring/system-alerts', MonitoringController::class, 'systemAlerts');
$router->get('api/monitoring/performance-advanced', MonitoringController::class, 'performanceAdvanced');
$router->get('api/monitoring/metrics', MonitoringController::class, 'metrics');
$router->post('api/monitoring/clean', MonitoringController::class, 'clean');

// ========================================
// 🔧 OPTIMIZATION - Otimização de Banco de Dados
// ========================================

use App\Controllers\OptimizationController;

$router->get('api/optimization/analyze', OptimizationController::class, 'analyze');
$router->post('api/optimization/analyze-tables', OptimizationController::class, 'analyzeTables');
$router->post('api/optimization/optimize-table', OptimizationController::class, 'optimizeTable');
$router->get('api/optimization/index-stats', OptimizationController::class, 'indexStats');

// ========================================
// 📦 STOCK SYNC - Sincronização de Estoque entre Contas
// ========================================

use App\Controllers\StockSyncController;

// Regras CRUD
$router->get('api/stock-sync/rules', StockSyncController::class, 'listRules');
$router->post('api/stock-sync/rules', StockSyncController::class, 'createRule');
$router->put('api/stock-sync/rules/{id}', StockSyncController::class, 'updateRule');
$router->delete('api/stock-sync/rules/{id}', StockSyncController::class, 'deleteRule');

// Sincronização
$router->post('api/stock-sync/full', StockSyncController::class, 'fullSync');
$router->post('api/stock-sync/process', StockSyncController::class, 'processQueue');
$router->post('api/stock-sync/manual', StockSyncController::class, 'manualSync');

// Webhook ML
$router->post('api/stock-sync/webhook', StockSyncController::class, 'webhook');

// Histórico e Stats
$router->get('api/stock-sync/history', StockSyncController::class, 'history');
$router->get('api/stock-sync/stats', StockSyncController::class, 'stats');

// Configurações
$router->get('api/stock-sync/settings', StockSyncController::class, 'getSettings');
$router->post('api/stock-sync/settings', StockSyncController::class, 'updateSettings');
