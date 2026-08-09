<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\Financial\PnlReportService;
use App\Services\Financial\FeeCommissionService;
use App\Services\Financial\ShippingCostService;
use App\Services\Financial\OrderFinancialService;
use App\Services\Financial\ProductProfitabilityService;
use App\Services\Financial\ClaimDisputeService;
use App\Services\Financial\SellerReputationService;
use App\Services\Financial\PaymentRefundService;
use App\Services\Financial\SubscriptionService;
use App\Services\Financial\CustomerPaymentMethodService;
use App\Services\Financial\SettlementReportService;
use App\Services\Financial\FinancialForecastService;

/**
 * Financial Service — Facade
 *
 * Mantém a interface pública original para compatibilidade.
 * Delega para serviços focados em app/Services/Financial/.
 */
class FinancialService
{
    private ?int $accountId;

    // Lazy-loaded service instances
    private ?PnlReportService $pnlReportService = null;
    private ?FeeCommissionService $feeCommissionService = null;
    private ?ShippingCostService $shippingCostService = null;
    private ?OrderFinancialService $orderFinancialService = null;
    private ?ProductProfitabilityService $productProfitabilityService = null;
    private ?ClaimDisputeService $claimDisputeService = null;
    private ?SellerReputationService $sellerReputationService = null;
    private ?PaymentRefundService $paymentRefundService = null;
    private ?SubscriptionService $subscriptionServiceInstance = null;
    private ?CustomerPaymentMethodService $customerPaymentMethodService = null;
    private ?SettlementReportService $settlementReportService = null;
    private ?FinancialForecastService $financialForecastService = null;

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
    }

    // ─── Lazy-loading service factories ─────────────────────────────

    private function pnlReport(): PnlReportService
    {
        return $this->pnlReportService ??= new PnlReportService($this->accountId);
    }

    private function feeCommission(): FeeCommissionService
    {
        return $this->feeCommissionService ??= new FeeCommissionService($this->accountId);
    }

    private function shippingCost(): ShippingCostService
    {
        return $this->shippingCostService ??= new ShippingCostService($this->accountId);
    }

    private function orderFinancial(): OrderFinancialService
    {
        return $this->orderFinancialService ??= new OrderFinancialService($this->accountId);
    }

    private function productProfitability(): ProductProfitabilityService
    {
        return $this->productProfitabilityService ??= new ProductProfitabilityService($this->accountId);
    }

    private function claimDispute(): ClaimDisputeService
    {
        return $this->claimDisputeService ??= new ClaimDisputeService($this->accountId);
    }

    private function sellerReputation(): SellerReputationService
    {
        return $this->sellerReputationService ??= new SellerReputationService($this->accountId);
    }

    private function paymentRefund(): PaymentRefundService
    {
        return $this->paymentRefundService ??= new PaymentRefundService($this->accountId);
    }

    private function subscription(): SubscriptionService
    {
        return $this->subscriptionServiceInstance ??= new SubscriptionService($this->accountId);
    }

    private function customerPaymentMethod(): CustomerPaymentMethodService
    {
        return $this->customerPaymentMethodService ??= new CustomerPaymentMethodService($this->accountId);
    }

    private function settlementReport(): SettlementReportService
    {
        return $this->settlementReportService ??= new SettlementReportService($this->accountId);
    }

    private function financialForecast(): FinancialForecastService
    {
        return $this->financialForecastService ??= new FinancialForecastService($this->accountId);
    }

    // ─── PnlReportService ───────────────────────────────────────────

    public function getPnL(string $startDate, string $endDate): array
    {
        return $this->pnlReport()->getPnL($startDate, $endDate);
    }

    public function getDailyRevenue(string $startDate, string $endDate): array
    {
        return $this->pnlReport()->getDailyRevenue($startDate, $endDate);
    }

    public function getCashFlow(string $startDate, string $endDate): array
    {
        return $this->pnlReport()->getCashFlow($startDate, $endDate);
    }

    public function getMetrics(string $startDate, string $endDate): array
    {
        return $this->pnlReport()->getMetrics($startDate, $endDate);
    }

    public function comparePeriods(
        string $currentStart,
        string $currentEnd,
        string $previousStart,
        string $previousEnd
    ): array {
        return $this->pnlReport()->comparePeriods($currentStart, $currentEnd, $previousStart, $previousEnd);
    }

    public function getDashboardSummary(): array
    {
        return $this->pnlReport()->getDashboardSummary();
    }

    public function getAccountBalance(): array
    {
        return $this->pnlReport()->getAccountBalance();
    }

    public function getConsolidatedFinancialDashboard(string $period = 'month'): array
    {
        return $this->pnlReport()->getConsolidatedFinancialDashboard($period);
    }

    // ─── FeeCommissionService ───────────────────────────────────────

    public function getBillingInfo(): array
    {
        return $this->feeCommission()->getBillingInfo();
    }

    public function getFeesBreakdown(string $startDate, string $endDate): array
    {
        return $this->feeCommission()->getFeesBreakdown($startDate, $endDate);
    }

    public function getBillingDetails(
        string $periodKey,
        string $documentType = 'BILL',
        int $limit = 150,
        int $fromId = 0
    ): array {
        return $this->feeCommission()->getBillingDetails($periodKey, $documentType, $limit, $fromId);
    }

    public function getMercadoPagoBillingDetails(
        string $periodKey,
        string $documentType = 'BILL',
        int $limit = 150,
        int $fromId = 0
    ): array {
        return $this->feeCommission()->getMercadoPagoBillingDetails($periodKey, $documentType, $limit, $fromId);
    }

    public function getBillingByOrder(array $orderIds, ?string $packId = null): array
    {
        return $this->feeCommission()->getBillingByOrder($orderIds, $packId);
    }

    public function getFulfillmentBillingDetails(
        string $periodKey,
        string $documentType = 'BILL',
        int $limit = 150
    ): array {
        return $this->feeCommission()->getFulfillmentBillingDetails($periodKey, $documentType, $limit);
    }

    public function getFlexShippingBillingDetails(
        string $periodKey,
        string $documentType = 'BILL',
        int $limit = 150
    ): array {
        return $this->feeCommission()->getFlexShippingBillingDetails($periodKey, $documentType, $limit);
    }

    public function getBillingPeriodSummary(string $periodKey): array
    {
        return $this->feeCommission()->getBillingPeriodSummary($periodKey);
    }

    public function getBuyerBillingInfo(string $orderId): array
    {
        return $this->feeCommission()->getBuyerBillingInfo($orderId);
    }

    public function getOrderSaleFeeDetails(string $orderId): array
    {
        return $this->feeCommission()->getOrderSaleFeeDetails($orderId);
    }

    public function generateReconciliationReport(string $startDate, string $endDate): array
    {
        return $this->feeCommission()->generateReconciliationReport($startDate, $endDate);
    }

    public function getPerceptionsSummary(string $periodKey, string $group = 'ML'): array
    {
        return $this->feeCommission()->getPerceptionsSummary($periodKey, $group);
    }

    public function getPerceptionsDetails(
        string $group,
        int $documentId,
        string $taxType,
        ?int $taxId = null,
        int $limit = 150
    ): array {
        return $this->feeCommission()->getPerceptionsDetails($group, $documentId, $taxType, $taxId, $limit);
    }

    public function getPaymentReport(string $periodKey, int $limit = 150, int $offset = 0, int $fromId = 0): array
    {
        return $this->feeCommission()->getPaymentReport($periodKey, $limit, $offset, $fromId);
    }

    public function getPaymentChargesDetail(string $paymentId, int $limit = 150): array
    {
        return $this->feeCommission()->getPaymentChargesDetail($paymentId, $limit);
    }

    // ─── ShippingCostService ────────────────────────────────────────

    public function getShipmentCosts(string $shipmentId): array
    {
        return $this->shippingCost()->getShipmentCosts($shipmentId);
    }

    public function getOrderShipments(string $orderId): array
    {
        return $this->shippingCost()->getOrderShipments($orderId);
    }

    public function getItemSaleTerms(string $itemId): array
    {
        return $this->shippingCost()->getItemSaleTerms($itemId);
    }

    // ─── OrderFinancialService ──────────────────────────────────────

    public function getOrdersFromApi(string $startDate, string $endDate, int $limit = 50, int $offset = 0): array
    {
        return $this->orderFinancial()->getOrdersFromApi($startDate, $endDate, $limit, $offset);
    }

    public function listLocalSalesWithProfitability(
        string $startDate,
        string $endDate,
        int $limit = 50,
        int $offset = 0,
        ?string $status = null,
        ?string $search = null
    ): array {
        return $this->orderFinancial()->listLocalSalesWithProfitability(
            $startDate,
            $endDate,
            $limit,
            $offset,
            $status,
            $search
        );
    }

    public function getOrderDetails(string $orderId): array
    {
        return $this->orderFinancial()->getOrderDetails($orderId);
    }

    public function syncOrdersWithFinancials(string $startDate, string $endDate, bool $forceSync = false): array
    {
        return $this->orderFinancial()->syncOrdersWithFinancials($startDate, $endDate, $forceSync);
    }

    public function getRealTimeFinancialSummary(string $startDate, string $endDate): array
    {
        return $this->orderFinancial()->getRealTimeFinancialSummary($startDate, $endDate);
    }

    public function generateRealTimeFinancialReport(string $startDate, string $endDate): array
    {
        return $this->orderFinancial()->generateRealTimeFinancialReport($startDate, $endDate);
    }

    public function getOrderDiscounts(string $orderId): array
    {
        return $this->orderFinancial()->getOrderDiscounts($orderId);
    }

    public function calculateOrderTotalWithShipping(string $orderId): array
    {
        return $this->orderFinancial()->calculateOrderTotalWithShipping($orderId);
    }

    public function getCompleteOrderFinancialData(string $orderId): array
    {
        return $this->orderFinancial()->getCompleteOrderFinancialData($orderId);
    }

    public function getOrderProductData(string $orderId): array
    {
        return $this->orderFinancial()->getOrderProductData($orderId);
    }

    public function getOrderFiscalData(string $orderId): array
    {
        return $this->orderFinancial()->getOrderFiscalData($orderId);
    }

    public function searchMerchantOrders(array $filters = []): array
    {
        return $this->orderFinancial()->searchMerchantOrders($filters);
    }

    // ─── ProductProfitabilityService ────────────────────────────────

    public function getProfitabilityByProduct(string $startDate, string $endDate, int $limit = 20): array
    {
        return $this->productProfitability()->getProfitabilityByProduct($startDate, $endDate, $limit);
    }

    public function getRevenueByCategory(string $startDate, string $endDate): array
    {
        return $this->productProfitability()->getRevenueByCategory($startDate, $endDate);
    }

    public function getAccountMovements(string $startDate, string $endDate, int $limit = 50): array
    {
        return $this->productProfitability()->getAccountMovements($startDate, $endDate, $limit);
    }

    public function getTopProductsFinancialMetrics(
        string $startDate,
        string $endDate,
        int $limit = 20
    ): array {
        return $this->productProfitability()->getTopProductsFinancialMetrics($startDate, $endDate, $limit);
    }

    public function calculateProductROI(
        string $itemId,
        float $productCost,
        string $startDate,
        string $endDate
    ): array {
        return $this->productProfitability()->calculateProductROI($itemId, $productCost, $startDate, $endDate);
    }

    public function calculateABCAnalysis(string $startDate, string $endDate): array
    {
        return $this->productProfitability()->calculateABCAnalysis($startDate, $endDate);
    }

    // ─── ClaimDisputeService ────────────────────────────────────────

    public function getClaims(
        string $status = 'opened',
        ?string $stage = null,
        int $limit = 30
    ): array {
        return $this->claimDispute()->getClaims($status, $stage, $limit);
    }

    public function getClaimDetails(string $claimId): array
    {
        return $this->claimDispute()->getClaimDetails($claimId);
    }

    public function getClaimReputationImpact(string $claimId): array
    {
        return $this->claimDispute()->getClaimReputationImpact($claimId);
    }

    public function getReturnDetails(string $claimId): array
    {
        return $this->claimDispute()->getReturnDetails($claimId);
    }

    public function getReturnShippingCost(string $claimId, bool $calculateUsd = false): array
    {
        return $this->claimDispute()->getReturnShippingCost($claimId, $calculateUsd);
    }

    public function getClaimsFinancialReport(string $startDate, string $endDate): array
    {
        return $this->claimDispute()->getClaimsFinancialReport($startDate, $endDate);
    }

    public function getClaimActionsHistory(string $claimId): array
    {
        return $this->claimDispute()->getClaimActionsHistory($claimId);
    }

    public function searchClaims(array $filters = []): array
    {
        return $this->claimDispute()->searchClaims($filters);
    }

    public function getClaim(string $claimId): array
    {
        return $this->claimDispute()->getClaim($claimId);
    }

    public function getClaimReason(string $claimId): array
    {
        return $this->claimDispute()->getClaimReason($claimId);
    }

    public function getClaimHistory(string $claimId): array
    {
        return $this->claimDispute()->getClaimHistory($claimId);
    }

    public function getClaimEvidence(string $claimId): array
    {
        return $this->claimDispute()->getClaimEvidence($claimId);
    }

    public function getClaimNotifications(string $claimId): array
    {
        return $this->claimDispute()->getClaimNotifications($claimId);
    }

    public function getClaimMessages(string $claimId): array
    {
        return $this->claimDispute()->getClaimMessages($claimId);
    }

    public function sendClaimMessage(string $claimId, string $message, array $attachments = []): array
    {
        return $this->claimDispute()->sendClaimMessage($claimId, $message, $attachments);
    }

    public function attachClaimFile(string $claimId, string $filePath, string $fileName): array
    {
        return $this->claimDispute()->attachClaimFile($claimId, $filePath, $fileName);
    }

    public function requestClaimMediation(string $claimId): array
    {
        return $this->claimDispute()->requestClaimMediation($claimId);
    }

    public function getExpectedResolutions(string $claimId): array
    {
        return $this->claimDispute()->getExpectedResolutions($claimId);
    }

    public function uploadShippingEvidence(string $claimId, array $shippingData): array
    {
        return $this->claimDispute()->uploadShippingEvidence($claimId, $shippingData);
    }

    public function analyzeClaimsPerformance(string $startDate, string $endDate): array
    {
        return $this->claimDispute()->analyzeClaimsPerformance($startDate, $endDate);
    }

    // ─── SellerReputationService ────────────────────────────────────

    public function getSellerReputation(): array
    {
        return $this->sellerReputation()->getSellerReputation();
    }

    public function getSellerTotalVisits(string $startDate, string $endDate): array
    {
        return $this->sellerReputation()->getSellerTotalVisits($startDate, $endDate);
    }

    public function getSellerVisitsByTimeWindow(int $lastDays = 30): array
    {
        return $this->sellerReputation()->getSellerVisitsByTimeWindow($lastDays);
    }

    public function getItemVisitsTotal(string $itemId): array
    {
        return $this->sellerReputation()->getItemVisitsTotal($itemId);
    }

    public function getItemVisitsByPeriod(string $itemId, string $startDate, string $endDate): array
    {
        return $this->sellerReputation()->getItemVisitsByPeriod($itemId, $startDate, $endDate);
    }

    public function getItemVisitsByTimeWindow(string $itemId, int $lastDays = 30): array
    {
        return $this->sellerReputation()->getItemVisitsByTimeWindow($itemId, $lastDays);
    }

    public function getSellerQuestionsMetrics(string $startDate, string $endDate): array
    {
        return $this->sellerReputation()->getSellerQuestionsMetrics($startDate, $endDate);
    }

    public function getSellerPhoneViewsMetrics(string $startDate, string $endDate): array
    {
        return $this->sellerReputation()->getSellerPhoneViewsMetrics($startDate, $endDate);
    }

    public function calculateConversionRate(string $startDate, string $endDate): array
    {
        return $this->sellerReputation()->calculateConversionRate($startDate, $endDate);
    }

    public function generateSellerPerformanceReport(string $startDate, string $endDate): array
    {
        return $this->sellerReputation()->generateSellerPerformanceReport($startDate, $endDate);
    }

    public function getOrderFeedback(string $orderId): array
    {
        return $this->sellerReputation()->getOrderFeedback($orderId);
    }

    public function getProductReviews(string $itemId, int $limit = 50): array
    {
        return $this->sellerReputation()->getProductReviews($itemId, $limit);
    }

    public function calculateCustomerLTV(int $months = 12): array
    {
        return $this->sellerReputation()->calculateCustomerLTV($months);
    }

    // ─── PaymentRefundService ───────────────────────────────────────

    public function getPaymentDetails(string $paymentId): array
    {
        return $this->paymentRefund()->getPaymentDetails($paymentId);
    }

    public function getCurrencyConversion(string $from, string $to): array
    {
        return $this->paymentRefund()->getCurrencyConversion($from, $to);
    }

    public function getChargebackDetails(string $chargebackId): array
    {
        return $this->paymentRefund()->getChargebackDetails($chargebackId);
    }

    public function searchMPPayments(array $filters = []): array
    {
        return $this->paymentRefund()->searchMPPayments($filters);
    }

    public function getMPPaymentDetails(string $paymentId): array
    {
        return $this->paymentRefund()->getMPPaymentDetails($paymentId);
    }

    public function getPaymentRefunds(string $paymentId): array
    {
        return $this->paymentRefund()->getPaymentRefunds($paymentId);
    }

    public function createRefund(string $paymentId, ?float $amount = null): array
    {
        return $this->paymentRefund()->createRefund($paymentId, $amount);
    }

    public function getChargebacksRefundsReport(string $startDate, string $endDate): array
    {
        return $this->paymentRefund()->getChargebacksRefundsReport($startDate, $endDate);
    }

    public function getWithdrawalHistory(int $limit = 20, int $offset = 0): array
    {
        return $this->paymentRefund()->getWithdrawalHistory($limit, $offset);
    }

    public function searchPayments(array $params = []): array
    {
        return $this->paymentRefund()->searchPayments($params);
    }

    public function getPaymentMethods(): array
    {
        return $this->paymentRefund()->getPaymentMethods();
    }

    public function getIdentificationTypes(): array
    {
        return $this->paymentRefund()->getIdentificationTypes();
    }

    // ─── SubscriptionService ────────────────────────────────────────

    public function createSubscription(array $subscriptionData): array
    {
        return $this->subscription()->createSubscription($subscriptionData);
    }

    public function searchSubscriptions(array $filters = []): array
    {
        return $this->subscription()->searchSubscriptions($filters);
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->subscription()->getSubscription($subscriptionId);
    }

    public function updateSubscription(string $subscriptionId, array $updateData): array
    {
        return $this->subscription()->updateSubscription($subscriptionId, $updateData);
    }

    public function pauseSubscription(string $subscriptionId): array
    {
        return $this->subscription()->pauseSubscription($subscriptionId);
    }

    public function activateSubscription(string $subscriptionId): array
    {
        return $this->subscription()->activateSubscription($subscriptionId);
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->subscription()->cancelSubscription($subscriptionId);
    }

    public function exportSubscriptions(array $filters = []): array
    {
        return $this->subscription()->exportSubscriptions($filters);
    }

    public function createSubscriptionPlan(array $planData): array
    {
        return $this->subscription()->createSubscriptionPlan($planData);
    }

    public function searchSubscriptionPlans(array $filters = []): array
    {
        return $this->subscription()->searchSubscriptionPlans($filters);
    }

    public function getSubscriptionPlan(string $planId): array
    {
        return $this->subscription()->getSubscriptionPlan($planId);
    }

    public function updateSubscriptionPlan(string $planId, array $updateData): array
    {
        return $this->subscription()->updateSubscriptionPlan($planId, $updateData);
    }

    public function getSubscriptionInvoice(string $invoiceId): array
    {
        return $this->subscription()->getSubscriptionInvoice($invoiceId);
    }

    public function searchSubscriptionInvoices(array $filters = []): array
    {
        return $this->subscription()->searchSubscriptionInvoices($filters);
    }

    public function getRecurringRevenueAnalysis(): array
    {
        return $this->subscription()->getRecurringRevenueAnalysis();
    }

    public function calculateSubscriptionChurn(string $month): array
    {
        return $this->subscription()->calculateSubscriptionChurn($month);
    }

    // ─── CustomerPaymentMethodService ───────────────────────────────

    public function createCustomer(array $customerData): array
    {
        return $this->customerPaymentMethod()->createCustomer($customerData);
    }

    public function searchCustomers(array $filters = []): array
    {
        return $this->customerPaymentMethod()->searchCustomers($filters);
    }

    public function getCustomer(string $customerId): array
    {
        return $this->customerPaymentMethod()->getCustomer($customerId);
    }

    public function updateCustomer(string $customerId, array $updateData): array
    {
        return $this->customerPaymentMethod()->updateCustomer($customerId, $updateData);
    }

    public function saveCustomerCard(string $customerId, string $cardToken): array
    {
        return $this->customerPaymentMethod()->saveCustomerCard($customerId, $cardToken);
    }

    public function getCustomerCards(string $customerId): array
    {
        return $this->customerPaymentMethod()->getCustomerCards($customerId);
    }

    public function getCustomerCard(string $customerId, string $cardId): array
    {
        return $this->customerPaymentMethod()->getCustomerCard($customerId, $cardId);
    }

    public function updateCustomerCard(string $customerId, string $cardId, array $updateData): array
    {
        return $this->customerPaymentMethod()->updateCustomerCard($customerId, $cardId, $updateData);
    }

    public function deleteCustomerCard(string $customerId, string $cardId): array
    {
        return $this->customerPaymentMethod()->deleteCustomerCard($customerId, $cardId);
    }

    // ─── SettlementReportService ────────────────────────────────────

    public function getSettlementReport(string $startDate, string $endDate): array
    {
        return $this->settlementReport()->getSettlementReport($startDate, $endDate);
    }

    public function createReleasesReport(string $beginDate, string $endDate): array
    {
        return $this->settlementReport()->createReleasesReport($beginDate, $endDate);
    }

    public function listReleasesReports(int $limit = 50, int $offset = 0): array
    {
        return $this->settlementReport()->listReleasesReports($limit, $offset);
    }

    public function getReleasesReportStatus(int $reportId): array
    {
        return $this->settlementReport()->getReleasesReportStatus($reportId);
    }

    public function downloadReleasesReport(string $fileName): array|string
    {
        return $this->settlementReport()->downloadReleasesReport($fileName);
    }

    public function getReleasesReportConfig(): array
    {
        return $this->settlementReport()->getReleasesReportConfig();
    }

    public function saveReleasesReportConfig(array $config, bool $update = false): array
    {
        return $this->settlementReport()->saveReleasesReportConfig($config, $update);
    }

    public function enableReleasesAutoGeneration(): array
    {
        return $this->settlementReport()->enableReleasesAutoGeneration();
    }

    public function disableReleasesAutoGeneration(): array
    {
        return $this->settlementReport()->disableReleasesAutoGeneration();
    }

    public function createSettlementsReport(string $beginDate, string $endDate): array
    {
        return $this->settlementReport()->createSettlementsReport($beginDate, $endDate);
    }

    public function listSettlementsReports(int $limit = 50, int $offset = 0): array
    {
        return $this->settlementReport()->listSettlementsReports($limit, $offset);
    }

    public function getSettlementsReportStatus(int $reportId): array
    {
        return $this->settlementReport()->getSettlementsReportStatus($reportId);
    }

    public function downloadSettlementsReport(string $fileName): array|string
    {
        return $this->settlementReport()->downloadSettlementsReport($fileName);
    }

    public function getSettlementsReportConfig(): array
    {
        return $this->settlementReport()->getSettlementsReportConfig();
    }

    public function saveSettlementsReportConfig(array $config, bool $update = false): array
    {
        return $this->settlementReport()->saveSettlementsReportConfig($config, $update);
    }

    public function enableSettlementsAutoGeneration(): array
    {
        return $this->settlementReport()->enableSettlementsAutoGeneration();
    }

    public function disableSettlementsAutoGeneration(): array
    {
        return $this->settlementReport()->disableSettlementsAutoGeneration();
    }

    public function generateConsolidatedMPReports(string $beginDate, string $endDate): array
    {
        return $this->settlementReport()->generateConsolidatedMPReports($beginDate, $endDate);
    }

    public function checkPendingReports(): array
    {
        return $this->settlementReport()->checkPendingReports();
    }

    public function getReadyReports(int $limit = 20): array
    {
        return $this->settlementReport()->getReadyReports($limit);
    }

    // ─── FinancialForecastService ───────────────────────────────────

    public function getFinancialProjection(int $daysAhead = 30): array
    {
        return $this->financialForecast()->getFinancialProjection($daysAhead);
    }

    public function calculateFinancialHealthScore(string $startDate, string $endDate): array
    {
        return $this->financialForecast()->calculateFinancialHealthScore($startDate, $endDate);
    }

    public function calculateFinancialForecast(int $monthsAhead = 3): array
    {
        return $this->financialForecast()->calculateFinancialForecast($monthsAhead);
    }

    public function calculateGoalProgress(float $monthlyTarget): array
    {
        return $this->financialForecast()->calculateGoalProgress($monthlyTarget);
    }

    public function checkFinancialAlerts(): array
    {
        return $this->financialForecast()->checkFinancialAlerts();
    }

    public function compareFinancialPeriods(
        string $period1Start,
        string $period1End,
        string $period2Start,
        string $period2End
    ): array {
        return $this->financialForecast()->compareFinancialPeriods($period1Start, $period1End, $period2Start, $period2End);
    }
}
