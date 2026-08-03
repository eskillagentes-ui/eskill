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
// 🛡️ Auth Failure Monitor API
// ========================================
$router->get('api/auth-monitor/status', AuthMonitorApiController::class, 'getStatus');
$router->get('api/auth-monitor/blocked-ips', AuthMonitorApiController::class, 'getBlockedIPs');
$router->get('api/auth-monitor/failures', AuthMonitorApiController::class, 'getFailures');
$router->get('api/auth-monitor/stats', AuthMonitorApiController::class, 'getStatistics');
$router->get('api/auth-monitor/ip/{ip}', AuthMonitorApiController::class, 'getIPDetails');
$router->post('api/auth-monitor/block-ip', AuthMonitorApiController::class, 'blockIP');
$router->delete('api/auth-monitor/unblock-ip/{ip}', AuthMonitorApiController::class, 'unblockIP');

// ========================================
// 🏠 Dashboard API - Dados Reais
// ========================================
$router->get('api/dashboard/search-suggestions', DashboardApiController::class, 'searchSuggestions');
$router->get('api/dashboard/recent-activity', DashboardApiController::class, 'recentActivity');
$router->get('api/dashboard/user-statistics', DashboardApiController::class, 'userStatistics');
$router->get('api/dashboard/notifications', DashboardApiController::class, 'notifications');
$router->get('api/dashboard/ai-insights', DashboardApiController::class, 'aiInsights');
$router->get('api/dashboard/menu-items', DashboardApiController::class, 'menuItems');
$router->get('api/dashboard/recent-documents', DashboardApiController::class, 'recentDocuments');
$router->get('api/dashboard/system-analytics', DashboardApiController::class, 'systemAnalytics');
$router->get('api/dashboard/team-status', DashboardApiController::class, 'teamStatus');
$router->get('api/dashboard/audit-trail', DashboardApiController::class, 'auditTrail');
$router->get('api/dashboard/predictive-search', DashboardApiController::class, 'predictiveSearch');
$router->get('api/menu-items', DashboardApiController::class, 'menuItems'); // Alias para compatibilidade

// Authentication endpoints (API)
$router->post('api/auth/login', AuthApiController::class, 'login');
$router->post('api/auth/refresh', AuthApiController::class, 'refresh');
$router->post('api/auth/logout', AuthApiController::class, 'logout');
$router->get('api/auth/status', AuthApiController::class, 'status');
$router->get('api/accounts/{accountId}/sync/status', AuthController::class, 'getSyncStatus');

// User preferences (theme)
$router->post('api/user/theme', UserController::class, 'updateTheme');
$router->post('api/user/profile', UserController::class, 'updateProfile');
$router->post('api/user/password', UserController::class, 'changePassword');
$router->post('api/user/2fa/disable', UserController::class, 'disable2fa');
$router->post('api/user/sessions/logout-all', UserController::class, 'logoutAllSessions');
$router->get('api/user/info', UserController::class, 'me');
$router->post('api/user/activity', UserController::class, 'activity');

// Onboarding & guided tours
$router->post('api/onboarding/complete', OnboardingController::class, 'complete');
$router->post('api/tours/complete', OnboardingController::class, 'completeTour');

// Render endpoints (E2E Testing)
$router->post('api/render', RenderController::class, 'create');
$router->get('api/render/{jobId}', RenderController::class, 'status');
$router->delete('api/render/cleanup', RenderController::class, 'cleanup');
