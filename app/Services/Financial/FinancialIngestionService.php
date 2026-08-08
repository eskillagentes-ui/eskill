<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Database;
use App\Helpers\Log;
use App\Services\MercadoLivreClient;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Ingestão de eventos financeiros para o ledger.
 * Busca dados (local + API), normaliza e persiste. Não calcula lucro operacional.
 */
final class FinancialIngestionService
{
    private PDO $db;
    private FinancialEventNormalizer $normalizer;
    private FinancialLedgerService $ledger;
    private ?MercadoLivreClient $client = null;

    public function __construct(
        private readonly int $accountId,
        ?PDO $db = null,
        ?FinancialEventNormalizer $normalizer = null,
        ?FinancialLedgerService $ledger = null,
        ?MercadoLivreClient $client = null,
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->normalizer = $normalizer ?? new FinancialEventNormalizer();
        $this->ledger = $ledger ?? new FinancialLedgerService($this->db);
        $this->client = $client;
    }

    /**
     * Backfill de pedidos locais no período.
     *
     * @param array{
     *   fetch_shipping?: bool,
     *   fetch_refunds?: bool,
     *   dry_run?: bool,
     *   limit?: int,
     *   sleep_us?: int
     * } $options
     * @return array<string, mixed>
     */
    public function backfillOrders(string $fromDate, string $toDate, array $options = []): array
    {
        $fetchShipping = (bool)($options['fetch_shipping'] ?? true);
        $fetchRefunds = (bool)($options['fetch_refunds'] ?? true);
        $dryRun = (bool)($options['dry_run'] ?? false);
        $limit = max(0, (int)($options['limit'] ?? 0));
        $sleepUs = max(0, (int)($options['sleep_us'] ?? 50000));

        $orders = $this->loadLocalOrders($fromDate, $toDate, $limit);
        $stats = [
            'account_id' => $this->accountId,
            'from' => $fromDate,
            'to' => $toDate,
            'orders_scanned' => count($orders),
            'orders_processed' => 0,
            'entries_created' => 0,
            'entries_updated' => 0,
            'entries_unchanged' => 0,
            'shipping_from_db' => 0,
            'shipping_from_api' => 0,
            'refunds_ingested' => 0,
            'refunds_covered' => 0,
            'chargebacks_ingested' => 0,
            'errors' => [],
            'discrepancies' => [],
            'dry_run' => $dryRun,
        ];

        foreach ($orders as $row) {
            try {
                $result = $this->ingestLocalOrderRow($row, $fetchShipping, $fetchRefunds, $dryRun, $sleepUs);
                $stats['orders_processed']++;
                $stats['entries_created'] += $result['created'];
                $stats['entries_updated'] += $result['updated'];
                $stats['entries_unchanged'] += $result['unchanged'];
                $stats['shipping_from_db'] += $result['shipping_from_db'];
                $stats['shipping_from_api'] += $result['shipping_from_api'];
                $stats['refunds_ingested'] += $result['refunds_ingested'];
                $stats['refunds_covered'] += $result['refunds_covered'];
                $stats['chargebacks_ingested'] += $result['chargebacks_ingested'];
                foreach ($result['discrepancies'] as $d) {
                    $stats['discrepancies'][] = $d;
                }
            } catch (Throwable $e) {
                $orderId = (string)($row['ml_order_id'] ?? '');
                $stats['errors'][] = [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ];
                Log::warning('FinancialIngestionService: falha no pedido', [
                    'account_id' => $this->accountId,
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $stats['discrepancy_count'] = count($stats['discrepancies']);
        return $stats;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   created: int,
     *   updated: int,
     *   unchanged: int,
     *   shipping_from_db: int,
     *   shipping_from_api: int,
     *   refunds_ingested: int,
     *   refunds_covered: int,
     *   chargebacks_ingested: int,
     *   discrepancies: list<array<string, mixed>>
     * }
     */
    private function ingestLocalOrderRow(
        array $row,
        bool $fetchShipping,
        bool $fetchRefunds,
        bool $dryRun,
        int $sleepUs
    ): array {
        $orderId = (string)$row['ml_order_id'];
        $orderData = json_decode((string)($row['order_data'] ?? '{}'), true);
        if (!is_array($orderData)) {
            $orderData = [];
        }
        if (!isset($orderData['id'])) {
            $orderData['id'] = $orderId;
        }

        $entries = $this->normalizer->fromOrder($this->accountId, $orderData);
        $shippingFromDb = 0;
        $shippingFromApi = 0;
        $refundsIngested = 0;
        $refundsCovered = 0;
        $chargebacksIngested = 0;
        $discrepancies = [];

        $shipmentId = $orderData['shipping']['id'] ?? null;
        $shipmentId = ($shipmentId !== null && $shipmentId !== '' && $shipmentId !== 'null')
            ? (string)$shipmentId
            : null;
        $dbShipping = round((float)($row['shipping_cost'] ?? 0), 2);

        $hasShippingEntry = false;
        if ($shipmentId !== null) {
            if ($dbShipping > 0) {
                $shipEntries = $this->normalizer->fromShipmentCosts(
                    $this->accountId,
                    $shipmentId,
                    ['senders' => [['user_id' => 'db', 'cost' => $dbShipping]]],
                    $orderId,
                    $this->parseDate($orderData['date_created'] ?? $row['date_created'] ?? null)
                );
                $entries = array_merge($entries, $shipEntries);
                $shippingFromDb = count($shipEntries);
                $hasShippingEntry = $shippingFromDb > 0;
            } elseif ($fetchShipping) {
                $costs = $this->fetchShipmentCostsPayload($shipmentId);
                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
                if ($costs !== null) {
                    $shipEntries = $this->normalizer->fromShipmentCosts(
                        $this->accountId,
                        $shipmentId,
                        $costs,
                        $orderId,
                        $this->parseDate($orderData['date_created'] ?? $row['date_created'] ?? null)
                    );
                    $entries = array_merge($entries, $shipEntries);
                    $shippingFromApi = count($shipEntries);
                    $hasShippingEntry = $shippingFromApi > 0;
                }
            }

            if (!$hasShippingEntry) {
                $orderStatus = (string)($row['status'] ?? $orderData['status'] ?? '');
                if (!in_array($orderStatus, ['cancelled'], true)) {
                    $discrepancies[] = [
                        'type' => 'missing_shipping_cost',
                        'severity' => 'warning',
                        'order_id' => $orderId,
                        'shipment_id' => $shipmentId,
                        'explanation' => 'Pedido com shipment_id sem frete seller no ledger',
                    ];
                }
            }
        }

        $hasSaleFee = false;
        $hasRevenue = false;
        foreach ($entries as $entry) {
            if ($entry->entryType === FinancialEntryType::SALE_FEE) {
                $hasSaleFee = true;
            }
            if ($entry->entryType === FinancialEntryType::SALE_REVENUE) {
                $hasRevenue = true;
            }
        }
        if ($hasRevenue && !$hasSaleFee) {
            $discrepancies[] = [
                'type' => 'missing_sale_fee',
                'severity' => 'warning',
                'order_id' => $orderId,
                'explanation' => 'Receita sem comissão sale_fee/marketplace_fee detectada',
            ];
        }

        // Refunds
        if ($fetchRefunds) {
            $payments = $orderData['payments'] ?? [];
            if (is_array($payments)) {
                foreach ($payments as $payment) {
                    if (!is_array($payment)) {
                        continue;
                    }
                    $paymentId = (string)($payment['id'] ?? '');
                    if ($paymentId === '') {
                        continue;
                    }

                    $shouldProbeRefund = $this->paymentLooksRefunded($payment, (string)($row['status'] ?? ''));
                    if (!$shouldProbeRefund) {
                        continue;
                    }

                    $collection = $this->fetchCollectionRaw($paymentId);
                    if ($sleepUs > 0) {
                        usleep($sleepUs);
                    }
                    if ($collection === null) {
                        continue;
                    }

                    $refunds = $this->extractRefundsFromCollection($collection);
                    if ($refunds !== []) {
                        $sellerDebited = $this->inferSellerDebitedByRefund(
                            $payment,
                            (string)($row['status'] ?? ''),
                            $refunds
                        );
                        $refundEntries = $this->normalizer->fromPaymentRefunds(
                            $this->accountId,
                            $payment,
                            $refunds,
                            $orderId,
                            $sellerDebited
                        );
                        $entries = array_merge($entries, $refundEntries);
                        $refundsIngested += count($refundEntries);

                        foreach ($refundEntries as $re) {
                            if ($re->status === 'covered') {
                                $refundsCovered++;
                                $discrepancies[] = [
                                    'type' => 'refund_without_financial_debit',
                                    'severity' => 'info',
                                    'order_id' => $orderId,
                                    'payment_id' => $paymentId,
                                    'refund_id' => $re->refundId,
                                    'expected_amount' => null,
                                    'actual_amount' => 0.0,
                                    'difference_amount' => 0.0,
                                    'explanation' => 'Comprador reembolsado (bpp_covered/proteção). '
                                        . 'Lançamento covered — sem débito adicional ao vendedor. '
                                        . 'Receita/comissões/frete do pedido permanecem no resultado marketplace.',
                                ];
                            }
                        }
                    }

                    // Chargeback: mesmo payload de /collections/{id}, sem request extra.
                    // Não depende de haver refunds[] — o chargeback pode não gerar item nesse array.
                    $chargebackOccurredAt = $this->parseDate(
                        $collection['date_last_updated'] ?? $collection['date_approved'] ?? $collection['date_created'] ?? null
                    ) ?? new DateTimeImmutable('now');
                    $cbEntry = $this->normalizer->fromChargeback(
                        $this->accountId,
                        $orderId,
                        $paymentId,
                        $collection,
                        $chargebackOccurredAt
                    );
                    if ($cbEntry !== null) {
                        $entries[] = $cbEntry;
                        $chargebacksIngested++;
                        $discrepancies[] = [
                            'type' => 'chargeback_detected',
                            'severity' => 'warning',
                            'order_id' => $orderId,
                            'payment_id' => $paymentId,
                            'expected_amount' => null,
                            'actual_amount' => $cbEntry->amount,
                            'difference_amount' => $cbEntry->amount,
                            'explanation' => 'Pagamento com status charged_back — valor revertido pela '
                                . 'operadora do cartão. Verificar cobertura/proteção aplicável ao caso.',
                        ];
                    }
                }
            }
        }

        if ($dryRun) {
            return [
                'created' => 0,
                'updated' => 0,
                'unchanged' => count($entries),
                'shipping_from_db' => $shippingFromDb,
                'shipping_from_api' => $shippingFromApi,
                'refunds_ingested' => $refundsIngested,
                'refunds_covered' => $refundsCovered,
                'chargebacks_ingested' => $chargebacksIngested,
                'discrepancies' => $discrepancies,
            ];
        }

        $upsert = $this->ledger->upsertMany($entries);

        return [
            'created' => $upsert['created'],
            'updated' => $upsert['updated'],
            'unchanged' => $upsert['unchanged'],
            'shipping_from_db' => $shippingFromDb,
            'shipping_from_api' => $shippingFromApi,
            'refunds_ingested' => $refundsIngested,
            'refunds_covered' => $refundsCovered,
            'chargebacks_ingested' => $chargebacksIngested,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadLocalOrders(string $fromDate, string $toDate, int $limit): array
    {
        $from = $fromDate . ' 00:00:00';
        $to = $toDate . ' 23:59:59';
        $sql = 'SELECT ml_order_id, status, shipping_cost, order_data, date_created
                FROM ml_orders
                WHERE ml_account_id = :account_id
                  AND date_created BETWEEN :from AND :to
                ORDER BY date_created ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':from' => $from,
            ':to' => $to,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchShipmentCostsPayload(string $shipmentId): ?array
    {
        try {
            $client = $this->getClient();
            $response = $client->get('/shipments/' . $shipmentId . '/costs');
            if (isset($response['body']) && is_array($response['body'])) {
                $response = $response['body'];
            }
            if (isset($response['data']) && is_array($response['data']) && isset($response['data']['senders'])) {
                $response = $response['data'];
            }
            if (isset($response['error'])) {
                return null;
            }
            return is_array($response) ? $response : null;
        } catch (Throwable $e) {
            Log::warning('FinancialIngestionService: falha shipment costs', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Busca o collection (pagamento) bruto uma única vez. Usado tanto para
     * extrair refunds[] quanto para detectar chargeback (status=charged_back) —
     * evita um segundo request para o mesmo payment_id.
     *
     * @return array<string, mixed>|null
     */
    private function fetchCollectionRaw(string $paymentId): ?array
    {
        try {
            $client = $this->getClient();
            // ML: reembolsos/chargeback ficam em GET /collections/{paymentId}
            // (GET /v1/payments/{id}/refunds retorna 404 no proxy atual)
            $response = $client->get('/collections/' . $paymentId);
            if (isset($response['body']) && is_array($response['body'])) {
                $response = $response['body'];
            }
            if (isset($response['error']) || !is_array($response)) {
                return null;
            }
            return $response;
        } catch (Throwable $e) {
            Log::warning('FinancialIngestionService: falha ao buscar collection', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $collection
     * @return list<array<string, mixed>>
     */
    private function extractRefundsFromCollection(array $collection): array
    {
        $refunds = $collection['refunds'] ?? [];
        if (!is_array($refunds)) {
            return [];
        }

        $out = [];
        foreach ($refunds as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            // Anexa contexto do collection para heurística bpp_covered
            $item['_collection_status'] = $collection['status'] ?? null;
            $item['_collection_status_detail'] = $collection['status_detail'] ?? null;
            $item['_amount_refunded'] = $collection['amount_refunded'] ?? null;
            $item['_net_received_amount'] = $collection['net_received_amount'] ?? null;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function paymentLooksRefunded(array $payment, string $orderStatus): bool
    {
        $payStatus = (string)($payment['status'] ?? '');
        if (in_array($payStatus, ['refunded', 'charged_back', 'cancelled'], true)) {
            return true;
        }
        if (in_array($orderStatus, ['cancelled'], true)) {
            return true;
        }
        $statusDetail = (string)($payment['status_detail'] ?? '');
        if (str_contains($statusDetail, 'refund') || str_contains($statusDetail, 'bpp_covered') || str_contains($statusDetail, 'partially_refunded')) {
            return true;
        }
        return false;
    }

    /**
     * Heurística: bpp_covered / source.type=bpp = reembolso ao comprador sem débito seller.
     *
     * @param array<string, mixed> $payment
     * @param list<array<string, mixed>> $refunds
     */
    private function inferSellerDebitedByRefund(array $payment, string $orderStatus, array $refunds = []): bool
    {
        $payStatus = (string)($payment['status'] ?? '');
        $statusDetail = (string)($payment['status_detail'] ?? '');

        foreach ($refunds as $refund) {
            if (!is_array($refund)) {
                continue;
            }
            $detail = (string)($refund['_collection_status_detail'] ?? $statusDetail);
            if ($detail === 'bpp_covered' || str_contains($detail, 'bpp_covered')) {
                return false;
            }
            $sourceType = (string)($refund['source']['type'] ?? '');
            if ($sourceType === 'bpp') {
                return false;
            }
            if (isset($refund['metadata']['coverage']) && is_array($refund['metadata']['coverage'])) {
                return false;
            }
        }

        if ($statusDetail === 'bpp_covered') {
            return false;
        }
        if ($payStatus === 'refunded' || $payStatus === 'charged_back') {
            return true;
        }
        if ($orderStatus === 'cancelled' && $payStatus === 'approved') {
            return false;
        }
        $release = (string)($payment['money_release_status'] ?? '');
        if ($orderStatus === 'cancelled' && in_array($release, ['available', 'pending', 'released'], true)) {
            return false;
        }
        return true;
    }

    private function getClient(): MercadoLivreClient
    {
        return $this->client ??= new MercadoLivreClient($this->accountId);
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable(is_string($value) ? $value : (string)$value);
        } catch (Throwable) {
            return null;
        }
    }

    public static function resolveAccountId(string $accountArg, PDO $db): int
    {
        if (ctype_digit($accountArg)) {
            return (int)$accountArg;
        }
        $stmt = $db->prepare(
            'SELECT id FROM ml_accounts
             WHERE nickname = :n OR CAST(ml_user_id AS CHAR) = :n2
             LIMIT 1'
        );
        $stmt->execute([
            ':n' => $accountArg,
            ':n2' => $accountArg,
        ]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException('Conta não encontrada: ' . $accountArg);
        }
        return (int)$id;
    }
}
