<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Controllers\ViewController;
use App\Controllers\OrderController;
use App\Controllers\OrdersController; // Keeping both as per original file check
use App\Controllers\AICenterController; // NEW: AI Center Controller
use App\Controllers\AccountHealthController;
use App\Controllers\AccountGovernanceController;
use App\Controllers\AccountXRayController;
use App\Controllers\CatalogCloneController;

/** @var \App\Router $router */

// Rotas protegidas (dashboard)
$router->get('', DashboardController::class, 'index');
$router->get('dashboard', DashboardController::class, 'index');
$router->get('ai-center', AICenterController::class, 'index'); // Alias for /dashboard/ai-center
$router->get('ai-optimization', AICenterController::class, 'index'); // AI Optimization page
$router->get('dashboard/ai-center', AICenterController::class, 'index'); // NEW: AI Center
$router->get('dashboard/metrics', DashboardController::class, 'metrics'); // API endpoint called by frontend

// Quality Dashboard (NEW - implementado)
use App\Controllers\QualityController;

$router->get('dashboard/quality', QualityController::class, 'getDashboard');

// Diagnóstico da Conta (Account Health)
$router->get('dashboard/account-health', AccountHealthController::class, 'index');
$router->get('api/account-health/diagnostic', AccountHealthController::class, 'getDiagnostic');
$router->get('api/account-health/pillar/{pillarName}', AccountHealthController::class, 'getPillar');
$router->get('api/account-health/history', AccountHealthController::class, 'getHistory');
$router->post('api/account-health/refresh', AccountHealthController::class, 'refresh');

// Pregão — painel read-only em tempo real (índice ESKL11)
use App\Controllers\PregaoController;
use App\Controllers\SentinelaController;
$router->get('dashboard/pregao', PregaoController::class, 'index');
$router->get('dashboard/sentinela', SentinelaController::class, 'index');
// APIs em app/Routes/api/pregao.php

// 🆕 Advanced Account Health Diagnostics
$router->get('api/account-health/advanced/status', AccountHealthController::class, 'getAdvancedStatus');
$router->get('api/account-health/advanced/customer-service', AccountHealthController::class, 'getAdvancedCustomerService');
$router->get('api/account-health/advanced/catalog', AccountHealthController::class, 'getAdvancedCatalog');
$router->get('api/account-health/advanced/complete', AccountHealthController::class, 'getAdvancedComplete');

// Account Governance & Recovery
$router->get('dashboard/account-governance', AccountGovernanceController::class, 'index');
$router->post('api/account-governance/diagnostic', AccountGovernanceController::class, 'runDiagnostic');
$router->post('api/account-governance/diagnostic-ml', AccountGovernanceController::class, 'runDiagnosticFromML');
$router->post('api/account-governance/validate', AccountGovernanceController::class, 'validateInput');
$router->get('api/account-governance/classifications', AccountGovernanceController::class, 'getClassifications');

// ── Raio X — Diagnóstico Sistemático de Conta ─────────────────────────────
$router->get('dashboard/raio-x', AccountXRayController::class, 'index');
$router->get('api/xray/accounts', AccountXRayController::class, 'accounts');
$router->post('api/xray/run', AccountXRayController::class, 'run');
$router->get('api/xray/list', AccountXRayController::class, 'list');
$router->get('api/xray/results/{id}', AccountXRayController::class, 'results');
$router->get('api/xray/item-scores/{reportId}', AccountXRayController::class, 'itemScores');
$router->post('api/xray/apply/{reportId}', AccountXRayController::class, 'applyRecovery');
$router->post('api/xray/queue', AccountXRayController::class, 'queueAnalysis');
$router->get('api/xray/job-status/{jobId}', AccountXRayController::class, 'jobStatus');
$router->get('api/xray/recovery-history/{accountId}', AccountXRayController::class, 'recoveryHistory');
$router->get('api/xray/export/pdf/{reportId}', AccountXRayController::class, 'exportPdf');
$router->get('api/xray/unlock-plan', AccountXRayController::class, 'unlockPlan');
$router->post('api/xray/unlock-plan/{mlbId}/resolve', AccountXRayController::class, 'resolveUnlockItem');

// ── Onda 4 — Governança de Escrita / Rank / SEO KPI / Ads readiness ──
use App\Controllers\WriteGovernanceController;
$router->get('dashboard/write-governance', WriteGovernanceController::class, 'index');
$router->get('api/write-governance/checklist', WriteGovernanceController::class, 'checklist');
$router->get('api/write-governance/audit', WriteGovernanceController::class, 'audit');
$router->post('api/write-governance/dry-run', WriteGovernanceController::class, 'dryRunDemo');
$router->get('api/ads/readiness', WriteGovernanceController::class, 'adsReadiness');
$router->get('api/seo/kpi/interventions', WriteGovernanceController::class, 'seoKpiList');
$router->post('api/seo/kpi/baseline', WriteGovernanceController::class, 'seoKpiBaseline');
$router->get('api/rank/status', WriteGovernanceController::class, 'rankStatus');
$router->get('api/rank/history/{mlbId}', WriteGovernanceController::class, 'rankHistory');

// OpenSpec Module
$router->get('dashboard/openspec', 'App\\Controllers\\OpenSpecController', 'index');
$router->get('dashboard/openspec/changes', 'App\\Controllers\\OpenSpecController', 'listChanges');
$router->get('dashboard/openspec/change/{id}', 'App\\Controllers\\OpenSpecController', 'showChange');
$router->post('api/openspec/validate/{id}', 'App\\Controllers\\OpenSpecController', 'validateChange');
$router->get('dashboard/openspec/create', 'App\\Controllers\\OpenSpecController', 'createProposal');



// VIEW ROUTES (Legacy migrated to ViewController)
$router->get('dashboard/analysis', ViewController::class, 'analysis');
$router->get('dashboard/categories', ViewController::class, 'categories');

// Order View Routes
// Note: Checking file list: There IS an OrdersController.php AND an OrderController.php. Using OrdersController to match original logic.
$router->get('dashboard/orders', 'App\Controllers\OrdersController', 'index');

$router->get('dashboard/profile', 'App\Controllers\ProfileController', 'index');
$router->get('dashboard/settings', 'App\Controllers\SettingsController', 'index');
$router->get('dashboard/accounts', ViewController::class, 'accounts');
$router->get('dashboard/help', ViewController::class, 'help');
$router->get('dashboard/activities', ViewController::class, 'activities');
$router->get('dashboard/api-tokens', ViewController::class, 'apiTokens');
$router->get('dashboard/tokens', ViewController::class, 'tokens');
$router->get('dashboard/ean', ViewController::class, 'ean');
$router->get('dashboard/ean/admin', ViewController::class, 'eanAdmin');
$router->get('dashboard/questions', DashboardController::class, 'questions');
$router->get('dashboard/items', DashboardController::class, 'items');
$router->get('dashboard/cogs', DashboardController::class, 'cogs');
$router->get('dashboard/items/bulk-compatibility', DashboardController::class, 'bulkCompatibilidades');
$router->get('dashboard/messages', DashboardController::class, 'messages');
$router->get('dashboard/catalog/clone', DashboardController::class, 'catalogClone');
$router->get('dashboard/catalog/clone-batch', DashboardController::class, 'catalogCloneBatch');
$router->get('dashboard/catalog/clone-wizard', DashboardController::class, 'cloneWizard');
$router->get('dashboard/catalog/clone-metrics', DashboardController::class, 'catalogCloneMetrics');
$router->get('dashboard/catalog/clone-monitoring', DashboardController::class, 'catalogCloneMonitoring');
$router->get('dashboard/catalog/clone-notifications', DashboardController::class, 'cloneNotifications');
$router->get('dashboard/catalog/clone-automation', DashboardController::class, 'cloneAutomation');
$router->get('dashboard/catalog/clone-realtime', DashboardController::class, 'cloneRealtimeDashboard');
$router->get('dashboard/catalog/clone-compliance', DashboardController::class, 'cloneCompliance');
$router->get('dashboard/catalog/clone-analytics', DashboardController::class, 'cloneAnalytics');
$router->get('dashboard/catalog/clone-widget-embed', DashboardController::class, 'cloneWidgetEmbed');
$router->get('dashboard/catalog/clone-ab-testing', DashboardController::class, 'cloneABTesting');
$router->get('dashboard/catalog/clone-roi-analysis', DashboardController::class, 'cloneROIAnalysis');
$router->get('dashboard/catalog/clone-seller-recommendations', DashboardController::class, 'cloneSellerRecommendations');
$router->get('dashboard/catalog/clone-items', DashboardController::class, 'cloneItemsManagement');
$router->get('dashboard/catalog/clone-operations', DashboardController::class, 'cloneOperations');
$router->get('dashboard/catalog/clone-scheduler', DashboardController::class, 'cloneScheduler');
$router->get('dashboard/catalog/clone-triggers', DashboardController::class, 'cloneTriggers');
$router->get('dashboard/catalog/clonar-anuncios', DashboardController::class, 'clonarAnuncios');
$router->get('api/catalog/clone/search', CatalogCloneController::class, 'unifiedSearch');
$router->get('api/catalog/clone/batch-jobs', CatalogCloneController::class, 'listBatchJobs');
$router->post('api/catalog/clone/jobs/{jobId}/retry-failed', CatalogCloneController::class, 'retryFailed');
$router->post('api/catalog/clone/seller-job', CatalogCloneController::class, 'startSellerJob');
$router->get('dashboard/advanced', DashboardController::class, 'advanced');
$router->get('dashboard/agents', ViewController::class, 'agents');
$router->get('dashboard/research', ViewController::class, 'research');
$router->get('research', ViewController::class, 'research');
$router->get('dashboard/shipping', 'App\Controllers\ShippingController', 'index');
$router->get('dashboard/picking', 'App\Controllers\ShippingController', 'index'); // Alias for shipping
$router->post('api/shipping/picking-list', 'App\Controllers\ShippingController', 'generatePickingList');
$router->get('dashboard/alerts', ViewController::class, 'alerts');
$router->get('dashboard/monitoring', ViewController::class, 'monitoring');
$router->get('dashboard/notifications', ViewController::class, 'notifications');
$router->get('dashboard/search', ViewController::class, 'search');
$router->get('dashboard/statistics', ViewController::class, 'statistics');
$router->get('dashboard/whatsapp', 'App\Controllers\WhatsAppController', 'index');
$router->post('dashboard/whatsapp/save', 'App\Controllers\WhatsAppController', 'save');
$router->post('api/whatsapp/test', 'App\Controllers\WhatsAppController', 'test');
$router->get('dashboard/opportunities', ViewController::class, 'opportunities');
$router->get('dashboard/pricing', ViewController::class, 'pricingDashboard');
$router->get('dashboard/pricing/history', ViewController::class, 'pricingHistory');
$router->get('dashboard/precificador', ViewController::class, 'pricingDashboard');
$router->get('pricing/dashboard', ViewController::class, 'pricingDashboard');
$router->get('dashboard/financials', 'App\Controllers\FinancialReportController', 'index');
$router->get('api/financials/pnl', 'App\Controllers\FinancialReportController', 'getPnLData');
$router->get('api/financials/export', 'App\Controllers\FinancialReportController', 'exportPdf');

// Financial API - Real-time Data from ML API
$router->get('api/financials/balance', 'App\Controllers\FinancialReportController', 'getBalance');
$router->get('api/financials/orders', 'App\Controllers\FinancialReportController', 'getOrders');
$router->get('api/financials/orders/{orderId}', 'App\Controllers\FinancialReportController', 'getOrderDetail');
$router->get('api/financials/realtime', 'App\Controllers\FinancialReportController', 'getRealTimeSummary');
$router->get('api/financials/fees', 'App\Controllers\FinancialReportController', 'getFeesBreakdown');
$router->get('api/financials/projection', 'App\Controllers\FinancialReportController', 'getProjection');
$router->get('api/financials/categories', 'App\Controllers\FinancialReportController', 'getRevenueByCategory');
$router->get('api/financials/movements', 'App\Controllers\FinancialReportController', 'getMovements');
$router->get('api/financials/settlements', 'App\Controllers\FinancialReportController', 'getSettlements');
$router->post('api/financials/sync', 'App\Controllers\FinancialReportController', 'syncOrders');
$router->get('api/financials/payments/{paymentId}', 'App\Controllers\FinancialReportController', 'getPaymentDetail');
$router->get('api/financials/profitability', 'App\Controllers\FinancialReportController', 'getProfitability');
$router->get('api/financials/metrics', 'App\Controllers\FinancialReportController', 'getMetrics');
$router->get('api/financials/compare', 'App\Controllers\FinancialReportController', 'comparePeriods');
$router->get('api/financials/cashflow', 'App\Controllers\FinancialReportController', 'getCashFlow');
$router->get('api/financials/dashboard', 'App\Controllers\FinancialReportController', 'getDashboardSummary');
$router->get('api/financials/daily', 'App\Controllers\FinancialReportController', 'getDailyRevenue');

// Financial Billing API - Real ML Billing Integration
$router->get('api/financials/billing/ml', 'App\Controllers\FinancialReportController', 'getBillingDetails');
$router->get('api/financials/billing/mp', 'App\Controllers\FinancialReportController', 'getMPBillingDetails');
$router->get('api/financials/billing/order', 'App\Controllers\FinancialReportController', 'getBillingByOrder');
$router->get('api/financials/billing/fulfillment', 'App\Controllers\FinancialReportController', 'getFulfillmentBilling');
$router->get('api/financials/billing/flex', 'App\Controllers\FinancialReportController', 'getFlexBilling');
$router->get('api/financials/billing/summary', 'App\Controllers\FinancialReportController', 'getBillingSummary');
$router->get('api/financials/order/{orderId}/buyer-billing', 'App\Controllers\FinancialReportController', 'getBuyerBillingInfo');
$router->get('api/financials/order/{orderId}/fees', 'App\Controllers\FinancialReportController', 'getOrderFees');
$router->get('api/financials/reconciliation', 'App\Controllers\FinancialReportController', 'getReconciliationReport');

// Financial Percepciones (Argentina Tax Withholdings)
$router->get('api/financials/perceptions/summary', 'App\Controllers\FinancialReportController', 'getPerceptionsSummary');
$router->get('api/financials/perceptions/details', 'App\Controllers\FinancialReportController', 'getPerceptionsDetails');

// Financial Payments Reports
$router->get('api/financials/payments/report', 'App\Controllers\FinancialReportController', 'getPaymentReport');
$router->get('api/financials/payments/{paymentId}/charges', 'App\Controllers\FinancialReportController', 'getPaymentCharges');

// Financial Shipping Costs
$router->get('api/financials/shipments/{shipmentId}/costs', 'App\Controllers\FinancialReportController', 'getShipmentCosts');
$router->get('api/financials/order/{orderId}/shipments', 'App\Controllers\FinancialReportController', 'getOrderShipments');

// Financial Item Analysis
$router->get('api/financials/items/{itemId}/sale-terms', 'App\Controllers\FinancialReportController', 'getItemSaleTerms');

// Financial Reports Consolidados
$router->get('api/financials/realtime-report', 'App\Controllers\FinancialReportController', 'getRealTimeReport');
$router->get('api/financials/products/top', 'App\Controllers\FinancialReportController', 'getTopProductsMetrics');
$router->post('api/financials/products/{itemId}/roi', 'App\Controllers\FinancialReportController', 'calculateProductROI');

// Order Discounts & Complete Financial Data
$router->get('api/financials/orders/{orderId}/discounts', 'App\Controllers\FinancialReportController', 'getOrderDiscounts');
$router->get('api/financials/orders/{orderId}/total', 'App\Controllers\FinancialReportController', 'calculateOrderTotalWithShipping');
$router->get('api/financials/orders/{orderId}/complete', 'App\Controllers\FinancialReportController', 'getCompleteOrderFinancialData');
$router->get('api/financials/orders/{orderId}/product', 'App\Controllers\FinancialReportController', 'getOrderProductData');

// Claims & Returns (Reclamações e Devoluções)
$router->get('api/financials/claims', 'App\Controllers\FinancialReportController', 'getClaims');
$router->get('api/financials/claims/report', 'App\Controllers\FinancialReportController', 'getClaimsFinancialReport');
$router->get('api/financials/claims/{claimId}', 'App\Controllers\FinancialReportController', 'getClaimDetails');
$router->get('api/financials/claims/{claimId}/reputation', 'App\Controllers\FinancialReportController', 'getClaimReputationImpact');
$router->get('api/financials/claims/{claimId}/history', 'App\Controllers\FinancialReportController', 'getClaimActionsHistory');
$router->get('api/financials/claims/{claimId}/return', 'App\Controllers\FinancialReportController', 'getReturnDetails');
$router->get('api/financials/claims/{claimId}/return-cost', 'App\Controllers\FinancialReportController', 'getReturnShippingCost');

// Currency Conversion
$router->get('api/financials/currency/conversion', 'App\Controllers\FinancialReportController', 'getCurrencyConversion');

// Seller Reputation & Performance
$router->get('api/financials/seller/reputation', 'App\Controllers\FinancialReportController', 'getSellerReputation');
$router->get('api/financials/seller/visits', 'App\Controllers\FinancialReportController', 'getSellerVisits');
$router->get('api/financials/seller/visits/daily', 'App\Controllers\FinancialReportController', 'getSellerVisitsDaily');
$router->get('api/financials/seller/questions', 'App\Controllers\FinancialReportController', 'getSellerQuestionsMetrics');
$router->get('api/financials/seller/conversion', 'App\Controllers\FinancialReportController', 'getConversionRate');
$router->get('api/financials/seller/performance', 'App\Controllers\FinancialReportController', 'getSellerPerformanceReport');
$router->get('api/financials/seller/ltv', 'App\Controllers\FinancialReportController', 'getCustomerLTV');

// Item Visits & Reviews
$router->get('api/financials/items/{itemId}/visits', 'App\Controllers\FinancialReportController', 'getItemVisits');
$router->get('api/financials/items/{itemId}/visits/daily', 'App\Controllers\FinancialReportController', 'getItemVisitsDaily');
$router->get('api/financials/items/{itemId}/reviews', 'App\Controllers\FinancialReportController', 'getProductReviews');

// Order Feedback
$router->get('api/financials/orders/{orderId}/feedback', 'App\Controllers\FinancialReportController', 'getOrderFeedback');

// Chargebacks & Refunds (Mercado Pago)
$router->get('api/financials/chargebacks/{chargebackId}', 'App\Controllers\FinancialReportController', 'getChargebackDetails');
$router->get('api/financials/chargebacks/report', 'App\Controllers\FinancialReportController', 'getChargebacksRefundsReport');

// Mercado Pago Payments API
$router->get('api/financials/mp/payments', 'App\Controllers\FinancialReportController', 'searchMPPayments');
$router->get('api/financials/mp/payments/{paymentId}', 'App\Controllers\FinancialReportController', 'getMPPaymentDetails');
$router->get('api/financials/mp/payments/{paymentId}/refunds', 'App\Controllers\FinancialReportController', 'getPaymentRefunds');
$router->post('api/financials/mp/payments/{paymentId}/refunds', 'App\Controllers\FinancialReportController', 'createRefund');
$router->get('api/financials/mp/merchant-orders', 'App\Controllers\FinancialReportController', 'searchMerchantOrders');

// Financial Health & Analytics
$router->get('api/financials/seller/health-score', 'App\Controllers\FinancialReportController', 'getFinancialHealthScore');
$router->get('api/financials/orders/{orderId}/fiscal', 'App\Controllers\FinancialReportController', 'getOrderFiscalData');
$router->get('api/financials/products/abc', 'App\Controllers\FinancialReportController', 'getABCAnalysis');

// Mercado Pago Reports - Releases (Liberações)
$router->post('api/financials/mp/reports/releases', 'App\Controllers\FinancialReportController', 'createReleasesReport');
$router->get('api/financials/mp/reports/releases', 'App\Controllers\FinancialReportController', 'listReleasesReports');
$router->get('api/financials/mp/reports/releases/config', 'App\Controllers\FinancialReportController', 'getReleasesReportConfig');
$router->post('api/financials/mp/reports/releases/config', 'App\Controllers\FinancialReportController', 'saveReleasesReportConfig');
$router->put('api/financials/mp/reports/releases/config', 'App\Controllers\FinancialReportController', 'saveReleasesReportConfig');
$router->post('api/financials/mp/reports/releases/schedule', 'App\Controllers\FinancialReportController', 'enableReleasesAutoGeneration');
$router->delete('api/financials/mp/reports/releases/schedule', 'App\Controllers\FinancialReportController', 'disableReleasesAutoGeneration');
$router->get('api/financials/mp/reports/releases/{reportId}', 'App\Controllers\FinancialReportController', 'getReleasesReportStatus');
$router->get('api/financials/mp/reports/releases/download/{fileName}', 'App\Controllers\FinancialReportController', 'downloadReleasesReport');

// Mercado Pago Reports - Settlements (Dinheiro em Conta)
$router->post('api/financials/mp/reports/settlements', 'App\Controllers\FinancialReportController', 'createSettlementsReport');
$router->get('api/financials/mp/reports/settlements', 'App\Controllers\FinancialReportController', 'listSettlementsReports');
$router->get('api/financials/mp/reports/settlements/config', 'App\Controllers\FinancialReportController', 'getSettlementsReportConfig');
$router->post('api/financials/mp/reports/settlements/config', 'App\Controllers\FinancialReportController', 'saveSettlementsReportConfig');
$router->put('api/financials/mp/reports/settlements/config', 'App\Controllers\FinancialReportController', 'saveSettlementsReportConfig');
$router->post('api/financials/mp/reports/settlements/schedule', 'App\Controllers\FinancialReportController', 'enableSettlementsAutoGeneration');
$router->delete('api/financials/mp/reports/settlements/schedule', 'App\Controllers\FinancialReportController', 'disableSettlementsAutoGeneration');
$router->get('api/financials/mp/reports/settlements/{reportId}', 'App\Controllers\FinancialReportController', 'getSettlementsReportStatus');
$router->get('api/financials/mp/reports/settlements/download/{fileName}', 'App\Controllers\FinancialReportController', 'downloadSettlementsReport');

// Consolidated MP Reports
$router->post('api/financials/mp/reports/consolidated', 'App\Controllers\FinancialReportController', 'generateConsolidatedMPReports');
$router->get('api/financials/mp/reports/pending', 'App\Controllers\FinancialReportController', 'checkPendingReports');
$router->get('api/financials/mp/reports/ready', 'App\Controllers\FinancialReportController', 'getReadyReports');

// Financial Forecasting & Goals
$router->get('api/financials/forecast', 'App\Controllers\FinancialReportController', 'getFinancialForecast');
$router->get('api/financials/goals/progress', 'App\Controllers\FinancialReportController', 'getGoalProgress');

// Withdrawals & Alerts
$router->get('api/financials/mp/withdrawals', 'App\Controllers\FinancialReportController', 'getWithdrawalHistory');
$router->get('api/financials/alerts', 'App\Controllers\FinancialReportController', 'checkFinancialAlerts');

// Period Comparisons (detailed)
$router->post('api/financials/compare-detailed', 'App\Controllers\FinancialReportController', 'compareFinancialPeriods');

// Mercado Pago - Subscriptions (Assinaturas)
$router->post('api/financials/mp/subscriptions', 'App\Controllers\FinancialReportController', 'createSubscription');
$router->get('api/financials/mp/subscriptions', 'App\Controllers\FinancialReportController', 'searchSubscriptions');
$router->get('api/financials/mp/subscriptions/export', 'App\Controllers\FinancialReportController', 'exportSubscriptions');
$router->get('api/financials/mp/subscriptions/{subscriptionId}', 'App\Controllers\FinancialReportController', 'getSubscription');
$router->put('api/financials/mp/subscriptions/{subscriptionId}', 'App\Controllers\FinancialReportController', 'updateSubscription');
$router->post('api/financials/mp/subscriptions/{subscriptionId}/pause', 'App\Controllers\FinancialReportController', 'pauseSubscription');
$router->post('api/financials/mp/subscriptions/{subscriptionId}/activate', 'App\Controllers\FinancialReportController', 'activateSubscription');
$router->post('api/financials/mp/subscriptions/{subscriptionId}/cancel', 'App\Controllers\FinancialReportController', 'cancelSubscription');

// Mercado Pago - Subscription Plans (Planos de Assinatura)
$router->post('api/financials/mp/subscription-plans', 'App\Controllers\FinancialReportController', 'createSubscriptionPlan');
$router->get('api/financials/mp/subscription-plans', 'App\Controllers\FinancialReportController', 'searchSubscriptionPlans');
$router->get('api/financials/mp/subscription-plans/{planId}', 'App\Controllers\FinancialReportController', 'getSubscriptionPlan');
$router->put('api/financials/mp/subscription-plans/{planId}', 'App\Controllers\FinancialReportController', 'updateSubscriptionPlan');

// Mercado Pago - Subscription Invoices (Faturas de Assinatura)
$router->get('api/financials/mp/subscription-invoices', 'App\Controllers\FinancialReportController', 'searchSubscriptionInvoices');
$router->get('api/financials/mp/subscription-invoices/{invoiceId}', 'App\Controllers\FinancialReportController', 'getSubscriptionInvoice');

// Mercado Pago - Recurring Revenue Analysis (MRR/ARR)
$router->get('api/financials/mp/recurring-revenue', 'App\Controllers\FinancialReportController', 'getRecurringRevenueAnalysis');
$router->get('api/financials/mp/subscription-churn', 'App\Controllers\FinancialReportController', 'calculateSubscriptionChurn');

// Mercado Pago - Customers (Clientes)
$router->post('api/financials/mp/customers', 'App\Controllers\FinancialReportController', 'createCustomer');
$router->get('api/financials/mp/customers', 'App\Controllers\FinancialReportController', 'searchCustomers');
$router->get('api/financials/mp/customers/{customerId}', 'App\Controllers\FinancialReportController', 'getCustomer');
$router->put('api/financials/mp/customers/{customerId}', 'App\Controllers\FinancialReportController', 'updateCustomer');

// Mercado Pago - Customer Cards (Cartões de Clientes)
$router->post('api/financials/mp/customers/{customerId}/cards', 'App\Controllers\FinancialReportController', 'saveCustomerCard');
$router->get('api/financials/mp/customers/{customerId}/cards', 'App\Controllers\FinancialReportController', 'getCustomerCards');
$router->get('api/financials/mp/customers/{customerId}/cards/{cardId}', 'App\Controllers\FinancialReportController', 'getCustomerCard');
$router->delete('api/financials/mp/customers/{customerId}/cards/{cardId}', 'App\Controllers\FinancialReportController', 'deleteCustomerCard');

// Mercado Pago - Claims (Reclamações MP)
$router->get('api/financials/mp/claims', 'App\Controllers\FinancialReportController', 'searchClaims');
$router->get('api/financials/mp/claims/analysis', 'App\Controllers\FinancialReportController', 'analyzeClaimsPerformance');
$router->get('api/financials/mp/claims/{claimId}', 'App\Controllers\FinancialReportController', 'getClaim');
$router->get('api/financials/mp/claims/{claimId}/history', 'App\Controllers\FinancialReportController', 'getClaimHistory');
$router->get('api/financials/mp/claims/{claimId}/messages', 'App\Controllers\FinancialReportController', 'getClaimMessages');
$router->post('api/financials/mp/claims/{claimId}/messages', 'App\Controllers\FinancialReportController', 'sendClaimMessage');
$router->post('api/financials/mp/claims/{claimId}/mediation', 'App\Controllers\FinancialReportController', 'requestClaimMediation');

// Mercado Pago - Payment Methods & Identification
$router->get('api/financials/mp/payment-methods', 'App\Controllers\FinancialReportController', 'getPaymentMethods');
$router->get('api/financials/mp/identification-types', 'App\Controllers\FinancialReportController', 'getIdentificationTypes');

// Consolidated Financial Dashboard
$router->get('api/financials/dashboard/consolidated', 'App\Controllers\FinancialReportController', 'getConsolidatedDashboard');


// User Management (Admin Only)
$router->get('dashboard/settings/users', 'App\Controllers\UserManagementController', 'index');
$router->get('api/users', 'App\Controllers\UserManagementController', 'listUsers');
$router->post('api/users/invite', 'App\Controllers\UserManagementController', 'invite');
$router->post('api/users/role', 'App\Controllers\UserManagementController', 'updateRole');

// Mercado Ads
$router->get('dashboard/ads', 'App\Controllers\AdsController', 'index');
$router->get('dashboard/ads/criar', 'App\Controllers\AdsController', 'createWizard');
$router->get('api/ads/dashboard', 'App\Controllers\AdsController', 'getDashboardData');
$router->get('api/ads/observation', 'App\Controllers\AdsController', 'getObservationData');
$router->get('api/ads/products', 'App\Controllers\AdsController', 'getProducts');
$router->get('api/ads/glossary', 'App\Controllers\AdsController', 'getGlossary');
$router->post('api/ads/suggest-budget', 'App\Controllers\AdsController', 'suggestBudget');
$router->post('api/ads/create', 'App\Controllers\AdsController', 'createCampaign');
$router->post('api/ads/quick-action', 'App\Controllers\AdsController', 'quickAction');
$router->post('api/ads/toggle/{campaignId}', 'App\Controllers\AdsController', 'toggleCampaign');
$router->post('api/ads/budget/{campaignId}', 'App\Controllers\AdsController', 'updateBudget');

// Notificações Inteligentes
$router->get('dashboard/notifications/settings', 'App\Controllers\NotificationSettingsController', 'index');
$router->get('api/notifications/settings', 'App\Controllers\NotificationSettingsController', 'getSettings');
$router->post('api/notifications/settings', 'App\Controllers\NotificationSettingsController', 'saveSettings');

// Health Check
$router->get('dashboard/health', 'App\Controllers\HealthController', 'index');
$router->get('api/health/check', 'App\Controllers\HealthController', 'check');

// Jobs Monitor
$router->get('dashboard/jobs', 'App\Controllers\DashboardController', 'jobs');

// Backups
$router->get('dashboard/backups', 'App\Controllers\DashboardController', 'backups');

// System Logs
$router->get('dashboard/logs', 'App\Controllers\LogController', 'index');
$router->get('api/logs/search', 'App\Controllers\LogController', 'search');
$router->get('api/logs/statistics', 'App\Controllers\LogController', 'statistics');
$router->post('api/logs/cleanup', 'App\Controllers\LogController', 'cleanup');
$router->get('api/logs/export', 'App\Controllers\LogController', 'export');

// Activities API
$router->get('api/activities', 'App\Controllers\ActivityController', 'index');
$router->get('api/activities/all', 'App\Controllers\ActivityController', 'all');
$router->get('api/activities/export', 'App\Controllers\ActivityController', 'export');

// System Cache
$router->get('dashboard/cache', 'App\Controllers\CacheController', 'index');
$router->get('api/cache/statistics', 'App\Controllers\CacheController', 'statistics');
$router->get('api/cache/list', 'App\Controllers\CacheController', 'list');
$router->get('api/cache/get', 'App\Controllers\CacheController', 'get');
$router->post('api/cache/delete', 'App\Controllers\CacheController', 'delete');
$router->post('api/cache/clear', 'App\Controllers\CacheController', 'clear');
$router->post('api/cache/clear-expired', 'App\Controllers\CacheController', 'clearExpired');
$router->post('api/cache/invalidate-tags', 'App\Controllers\CacheController', 'invalidateTags');

// Claims
$router->get('dashboard/claims', 'App\Controllers\ClaimsController', 'index');
$router->get('api/claims/list', 'App\Controllers\ClaimsController', 'list');
$router->post('api/claims/send-message', 'App\Controllers\ClaimsController', 'sendMessage');

// Returns (Devoluções & RMA)
$router->get('dashboard/returns', 'App\Controllers\ReturnsController', 'index');

// Catalog
$router->get('dashboard/catalog/competition', 'App\Controllers\CatalogController', 'index');
$router->get('api/catalog/losing', 'App\Controllers\CatalogController', 'listLosingItems');

// Logistics Full
$router->get('dashboard/logistics/full', 'App\Controllers\FullController', 'index');
$router->get('api/logistics/full/suggestions', 'App\Controllers\FullController', 'getRestockSuggestions');

// Mercado Envio Flex
$router->get('dashboard/logistics/flex', 'App\Controllers\FlexController', 'index');
$router->get('api/logistics/flex/orders', 'App\Controllers\FlexController', 'orders');
$router->post('api/logistics/flex/assign', 'App\Controllers\FlexController', 'assign');

// Bulk Editor
$router->get('dashboard/items/bulk', 'App\Controllers\BulkEditorController', 'index');
$router->post('api/items/bulk-update', 'App\Controllers\BulkEditorController', 'applyUpdates');

// Item Editor
$router->get('dashboard/items/{itemId}/edit', 'App\Controllers\DashboardController', 'editItem');

// Promotions
$router->get('dashboard/marketing/promotions', 'App\Controllers\PromotionController', 'index');
$router->get('api/marketing/promotions', 'App\Controllers\PromotionController', 'listPromotions');
$router->get('api/marketing/promotions/items', 'App\Controllers\PromotionController', 'detail');
$router->post('api/marketing/promotions/join', 'App\Controllers\PromotionController', 'join');
$router->get('api/marketing/promotions/performance', 'App\Controllers\PromotionController', 'performanceAnalysis');
$router->get('api/marketing/promotions/suggested-items', 'App\Controllers\PromotionController', 'suggestedItems');
$router->get('api/marketing/promotions/simulate', 'App\Controllers\PromotionController', 'simulateDiscount');
$router->get('api/marketing/coupons', 'App\Controllers\PromotionController', 'listCoupons');
$router->post('api/marketing/coupons', 'App\Controllers\PromotionController', 'createCoupon');
$router->post('api/marketing/coupons/{id}/status', 'App\Controllers\PromotionController', 'updateCouponStatus');
$router->get('api/marketing/coupons/{id}/performance', 'App\Controllers\PromotionController', 'couponPerformance');

// Customer CRM
$router->get('dashboard/customers', 'App\Controllers\CustomerController', 'index');
$router->get('api/crm/customers', 'App\Controllers\CustomerController', 'listCustomers');
$router->get('api/crm/customer', 'App\Controllers\CustomerController', 'detail');

// Reports
$router->get('dashboard/reports', 'App\Controllers\ReportController', 'index');
$router->post('api/reports/generate-pdf', 'App\Controllers\ReportController', 'generatePdf');
$router->post('api/reports/generate-csv', 'App\Controllers\ReportController', 'generateCsv');

// Security & Audit
$router->get('dashboard/audit', 'App\Controllers\AuditController', 'index');

// Financial Conciliation (Phase 14)
$router->get('dashboard/financials/conciliation', 'App\Controllers\SettlementController', 'index');
$router->post('dashboard/financials/conciliation/upload', 'App\Controllers\SettlementController', 'upload');
$router->get('dashboard/financials/conciliation/reconcile', 'App\Controllers\SettlementController', 'reconcile');

// Competitor Intelligence (Phase 16)
// HTML do dashboard (API JSON fica em /api/competitors via CompetitorController::index)
$router->get('dashboard/competitors', DashboardController::class, 'competitors');
$router->post('dashboard/competitors/add', 'App\Controllers\CompetitorController', 'add');
$router->get('dashboard/competitors/details/{id}', DashboardController::class, 'competitors');

// SEO & Gap Advanced Routes
$router->get('api/seo/gap-analysis', DashboardController::class, 'gapAnalysis');
$router->post('api/seo/generate-content', DashboardController::class, 'generateContent');

// SEO Killer Dashboard
$router->get('dashboard/seo-killer', ViewController::class, 'seoKiller');

// Tech Sheet (Ficha Técnica) Dashboard
$router->get('dashboard/seo/ficha-tecnica', ViewController::class, 'techSheet');
$router->get('dashboard/tech-sheet', ViewController::class, 'techSheet');

// Brand Analysis Dashboard
$router->get('dashboard/brand-analysis', 'App\Controllers\BrandAnalyzerController', 'index');
$router->get('dashboard/awa-sellers', 'App\Controllers\AwaSellerController', 'index');
$router->get('brand-analysis', 'App\Controllers\BrandAnalyzerController', 'index');

// Brand Search Dashboard (busca genérica por marca)
$router->get('dashboard/brand-search', 'App\Controllers\BrandSearchController', 'index');
$router->get('brand-search', 'App\Controllers\BrandSearchController', 'index');

// SEO Killer Assets (Served via Controller to avoid 404s in protected structure)
$router->get('assets/css/seo-killer.css', 'App\Controllers\AssetController', 'seoKillerCss');
$router->get('assets/js/seo-killer-utils.js', 'App\Controllers\AssetController', 'seoKillerUtilsJs');
$router->get('assets/js/seo-killer.js', 'App\Controllers\AssetController', 'seoKillerJs');

// Public SEO Routes (No Auth)
$router->get('p/{slug}', \App\Controllers\PublicProductController::class, 'show');
$router->get('sitemap.xml', \App\Controllers\SitemapController::class, 'index');
$router->get('api/jobs/{id}', \App\Controllers\DashboardController::class, 'jobStatus');

// AI Agents (Phase 20 & NextGen)
$router->get('api/agent/projects', 'App\Controllers\AgentController', 'listProjects');
$router->post('api/agent/projects/start', 'App\Controllers\AgentController', 'startProject');
$router->get('api/agent/projects/{id}/status', 'App\Controllers\AgentController', 'getStatus');
$router->post('api/agent/projects/{id}/session', 'App\Controllers\AgentController', 'runCodingSession');
$router->get('api/agent/autonomous', 'App\Controllers\AgentController', 'listAutonomous');
$router->get('api/agent/autonomous/logs', 'App\Controllers\AgentController', 'listLogs');

// Phase 21: Mobile API
$router->post('api/mobile/login', 'App\Controllers\Mobile\AuthController', 'login');
$router->get('api/mobile/dashboard', 'App\Controllers\Mobile\DashboardController', 'overview');

// Phase 22: Shopee
$router->get('dashboard/shopee', 'App\Controllers\ShopeeController', 'index');
$router->post('api/shopee/sync', 'App\Controllers\ShopeeController', 'sync');

// Phase 24: Advanced Analytics
$router->get('dashboard/analytics', 'App\Controllers\AnalyticsController', 'index');
$router->get('api/analytics/summary', 'App\Controllers\AnalyticsController', 'getSummary');
$router->get('api/analytics/revenue-trend', 'App\Controllers\AnalyticsController', 'getRevenueTrend');
$router->get('api/analytics/customer-ltv', 'App\Controllers\AnalyticsController', 'getCustomerLTV');
$router->get('api/analytics/profit-margins', 'App\Controllers\AnalyticsController', 'getProfitMargins');
$router->get('api/analytics/inventory-turnover', 'App\Controllers\AnalyticsController', 'getInventoryTurnover');
$router->get('api/analytics/forecast', 'App\Controllers\AnalyticsController', 'getForecast');

// AI Optimization System (Phase 1)
$router->get('dashboard/ai-optimization', 'App\Controllers\AIOptimizationController', 'index');
$router->get('dashboard/ai-optimization/{itemId}', 'App\Controllers\AIOptimizationController', 'show');
$router->post('api/ai/optimize/title', 'App\Controllers\AIOptimizationController', 'optimizeTitle');
$router->post('api/ai/optimize/complete', 'App\Controllers\AIOptimizationController', 'optimizeComplete');
$router->post('api/ai/optimize/batch', 'App\Controllers\AIOptimizationController', 'batchOptimize');
$router->get('api/ai/suggestions/{itemId}', 'App\Controllers\AIOptimizationController', 'suggestions');
$router->post('api/ai/analyze/title', 'App\Controllers\AIOptimizationController', 'analyzeTitle');
$router->get('api/ai/info', 'App\Controllers\AIOptimizationController', 'info');
$router->post('api/ai/optimize/description', 'App\Controllers\AIOptimizationController', 'optimizeDescription');
$router->post('api/ai/optimize/tech-sheet', 'App\Controllers\AIOptimizationController', 'optimizeTechSheet');

// Advanced Analytics Dashboard
$router->get('dashboard/advanced-analytics', 'App\Controllers\DashboardController', 'advancedAnalytics');

// Competitor Monitoring Dashboard
$router->get('dashboard/competitor-monitor', 'App\Controllers\DashboardController', 'competitorMonitor');
// AI Advanced Features
$router->get('api/ai/keywords/research', 'App\Controllers\AIOptimizationController', 'researchKeywords');
$router->get('api/ai/competitors/analyze', 'App\Controllers\AIOptimizationController', 'analyzeCompetitors');
$router->get('api/ai/providers/status', 'App\Controllers\AIOptimizationController', 'getProviderStatus');

// Batch Queue System
$router->post('api/ai/batch/start', 'App\Controllers\AIOptimizationController', 'startBatchOptimization');
$router->get('api/ai/batch/{batchId}/status', 'App\Controllers\AIOptimizationController', 'getBatchStatus');
$router->get('api/ai/batch/{batchId}/results', 'App\Controllers\AIOptimizationController', 'getBatchResults');
$router->get('api/ai/queue/stats', 'App\Controllers\AIOptimizationController', 'getQueueStats');

// A/B Testing
$router->post('api/ai/ab-test/create', 'App\Controllers\AIOptimizationController', 'createABTest');
$router->get('api/ai/ab-test/{testId}/results', 'App\Controllers\AIOptimizationController', 'getABTestResults');
$router->post('api/ai/ab-test/{testId}/end', 'App\Controllers\AIOptimizationController', 'endABTest');

// Audit & History
$router->get('api/ai/audit/{itemId}/history', 'App\Controllers\AIOptimizationController', 'getAuditHistory');
$router->post('api/ai/audit/{logId}/rollback', 'App\Controllers\AIOptimizationController', 'rollbackOptimization');

// Preview System
$router->post('api/ai/preview/generate', 'App\Controllers\AIOptimizationController', 'generatePreview');
$router->post('api/ai/preview/{previewId}/apply', 'App\Controllers\AIOptimizationController', 'applyPreview');

// Advanced Analytics
$router->get('api/ai/analytics/dashboard', 'App\Controllers\AIOptimizationController', 'getDashboardAnalytics');
$router->get('api/ai/analytics/summary', 'App\Controllers\AIOptimizationController', 'getExecutiveSummary');
$router->get('api/ai/analytics/costs', 'App\Controllers\AIOptimizationController', 'getCostAnalytics');

// SEO Dashboard & SEO Killer
$router->get('dashboard/seo', DashboardController::class, 'seo');

// SEO com IA - Novo Dashboard
$router->get('seo', ViewController::class, 'seoDashboard');
$router->get('seo/dashboard', ViewController::class, 'seoDashboard');

// SEO Intelligence Module
$router->get('dashboard/seo-intelligence', ViewController::class, 'seoIntelligence');
$router->get('dashboard/seo-intelligence/listing', ViewController::class, 'seoIntelligenceDetail');
