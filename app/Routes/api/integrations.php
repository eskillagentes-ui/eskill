<?php
declare(strict_types=1);
/** @var \App\Router $router */

use App\Controllers\MlObservabilityController;
use App\Controllers\MlOrderAuditController;
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
// 📩 Integrações - Brevo (Marketing API)
// ========================================
$router->get('api/integrations/brevo/health', BrevoIntegrationController::class, 'health');
$router->get('api/integrations/brevo/status', BrevoIntegrationController::class, 'status');
$router->get('api/integrations/brevo/contacts', BrevoIntegrationController::class, 'listContacts');
$router->post('api/integrations/brevo/contacts', BrevoIntegrationController::class, 'createContact');
$router->get('api/integrations/brevo/contacts/{email}', BrevoIntegrationController::class, 'getContact');
$router->put('api/integrations/brevo/contacts/{email}', BrevoIntegrationController::class, 'updateContact');
$router->delete('api/integrations/brevo/contacts/{email}', BrevoIntegrationController::class, 'deleteContact');
$router->get('api/integrations/brevo/lists', BrevoIntegrationController::class, 'listLists');
$router->post('api/integrations/brevo/lists', BrevoIntegrationController::class, 'createList');
$router->get('api/integrations/brevo/lists/{listId}', BrevoIntegrationController::class, 'getList');
$router->put('api/integrations/brevo/lists/{listId}', BrevoIntegrationController::class, 'updateList');
$router->delete('api/integrations/brevo/lists/{listId}', BrevoIntegrationController::class, 'deleteList');
$router->post('api/integrations/brevo/lists/{listId}/contacts/add', BrevoIntegrationController::class, 'addContactsToList');
$router->post('api/integrations/brevo/lists/{listId}/contacts/remove', BrevoIntegrationController::class, 'removeContactsFromList');
$router->post('api/integrations/brevo/sync/lists', BrevoIntegrationController::class, 'syncLists');
$router->post('api/integrations/brevo/sync/contacts', BrevoIntegrationController::class, 'syncContacts');
$router->post('api/integrations/brevo/sync/all', BrevoIntegrationController::class, 'syncAll');


// ========================================
// 🤖 Integrações - CLAWDBOT (Webhook público)
// ========================================
// Observação: /api/webhook/* é isento de auth global e CSRF (ver public/index.php)
$router->get('api/webhook/clawdbot/health', ClawdbotWebhookController::class, 'health');
$router->post('api/webhook/clawdbot', ClawdbotWebhookController::class, 'receive');


// ========================================
// 🧩 Assistant Connector (API tokens + multi-conta)
// ========================================
// Observação: rotas /api/assistant/* são protegidas pelo auth global de /api/* (ver public/index.php)
$router->get('api/assistant/health', AssistantConnectorController::class, 'health');
$router->get('api/assistant/sellers', AssistantConnectorController::class, 'sellers');
$router->post('api/assistant/events', AssistantConnectorController::class, 'ingestEvent');
$router->post('api/assistant/actions', AssistantConnectorController::class, 'createAction');
$router->get('api/assistant/actions/{id}', AssistantConnectorController::class, 'getAction');


// ========================================
// 🐾 OpenClaw Connector (API tokens + multi-conta)
// ========================================
// Observação: rotas /api/openclaw/* são protegidas pelo auth global de /api/* (ver public/index.php)
$router->get('api/openclaw', OpenClawConnectorController::class, 'index');
$router->get('api/openclaw/health', OpenClawConnectorController::class, 'health');
$router->get('api/openclaw/sellers', OpenClawConnectorController::class, 'sellers');
$router->get('api/openclaw/sellers/{id}', OpenClawConnectorController::class, 'getSeller');
$router->get('api/openclaw/sellers/{id}/items', OpenClawConnectorController::class, 'listItems');
$router->get('api/openclaw/sellers/{id}/items/stats', OpenClawConnectorController::class, 'itemsStats');
$router->get('api/openclaw/sellers/{id}/items/{itemId}', OpenClawConnectorController::class, 'getItem');
$router->get('api/openclaw/sellers/{id}/orders', OpenClawConnectorController::class, 'listOrders');
$router->get('api/openclaw/sellers/{id}/orders/{orderId}', OpenClawConnectorController::class, 'getOrder');
$router->post('api/openclaw/actions', OpenClawConnectorController::class, 'createAction');
$router->get('api/openclaw/actions/{id}', OpenClawConnectorController::class, 'getAction');
$router->get('api/openclaw/webhooks', OpenClawConnectorController::class, 'listWebhooks');
$router->post('api/openclaw/webhooks', OpenClawConnectorController::class, 'createWebhook');
$router->delete('api/openclaw/webhooks/{id}', OpenClawConnectorController::class, 'deleteWebhook');
$router->post('api/openclaw/webhooks/{id}/test', OpenClawConnectorController::class, 'testWebhook');
$router->get('api/openclaw/webhook-events', OpenClawConnectorController::class, 'webhookEvents');
// ========================================
// 📊 Mercado Livre — Observabilidade
// ========================================
$router->get('api/ml/observability/summary', MlObservabilityController::class, 'summary');

// ========================================
// 🧾 Mercado Livre — Trilha de Auditoria (ML-BLG-060)
// ========================================
$router->get('api/ml/orders/{orderId}/trail', MlOrderAuditController::class, 'trail');

