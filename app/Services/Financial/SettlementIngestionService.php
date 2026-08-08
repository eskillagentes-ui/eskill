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
 * Ingestão de liberações (settlement/release) do Mercado Pago para o ledger.
 *
 * Fase A do módulo financeiro: NÃO cobre withdrawals (PATCH 3) nem
 * chargebacks/claims/billing detalhado (PATCH 4). Responsabilidade única:
 * buscar o status de liberação por pagamento e persistir no ledger.
 *
 * Fontes reais combinadas (sem fabricar valores):
 *  - GET /collections/{paymentId}                      → net_received_amount, money_release_date
 *  - GET /billing/integration/group/ML/order/details   → money_release_status (released|pending)
 *
 * Quando a origem não confirma liberação, o lançamento fica status=pending
 * e released_at=null — nunca assume liberação por data projetada.
 */
final class SettlementIngestionService
{
    private PDO $db;
    private FinancialEventNormalizer $normalizer;
    private FinancialLedgerService $ledger;
    private ?MercadoLivreClient $client = null;
    private ?FeeCommissionService $feeService = null;

    public function __construct(
        private readonly int $accountId,
        ?PDO $db = null,
        ?FinancialEventNormalizer $normalizer = null,
        ?FinancialLedgerService $ledger = null,
        ?MercadoLivreClient $client = null,
        ?FeeCommissionService $feeService = null,
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->normalizer = $normalizer ?? new FinancialEventNormalizer();
        $this->ledger = $ledger ?? new FinancialLedgerService($this->db);
        $this->client = $client;
        $this->feeService = $feeService;
    }

    /**
     * Backfill de liberações para pedidos locais pagos no período.
     * Um pagamento por pedido (o principal); pedidos sem payment_id aprovado são ignorados.
     *
     * @param array{
     *   dry_run?: bool,
     *   limit?: int,
     *   sleep_us?: int,
     *   billing_chunk_size?: int
     * } $options
     * @return array<string, mixed>
     */
    public function backfillReleases(string $fromDate, string $toDate, array $options = []): array
    {
        $dryRun = (bool)($options['dry_run'] ?? false);
        $limit = max(0, (int)($options['limit'] ?? 0));
        $sleepUs = max(0, (int)($options['sleep_us'] ?? 80000));
        $chunkSize = max(1, min(50, (int)($options['billing_chunk_size'] ?? 20)));

        $orders = $this->loadPaidOrders($fromDate, $toDate, $limit);
        $stats = [
            'account_id' => $this->accountId,
            'from' => $fromDate,
            'to' => $toDate,
            'orders_scanned' => count($orders),
            'orders_with_payment' => 0,
            'entries_created' => 0,
            'entries_updated' => 0,
            'entries_unchanged' => 0,
            'released_count' => 0,
            'pending_count' => 0,
            'skipped_no_amount' => 0,
            'skipped_no_payment' => 0,
            'errors' => [],
            'dry_run' => $dryRun,
        ];

        // 1) money_release_status em lote via billing (ML), por order_id.
        $releaseStatusByOrder = [];
        $orderIds = array_values(array_filter(array_map(
            static fn (array $o): string => (string)$o['order_id'],
            $orders
        )));
        foreach (array_chunk($orderIds, $chunkSize) as $chunk) {
            try {
                $billing = $this->getFeeService()->getBillingByOrder($chunk);
                foreach ($billing['results'] ?? [] as $result) {
                    $oid = (string)($result['order_id'] ?? '');
                    if ($oid === '') {
                        continue;
                    }
                    $releaseStatusByOrder[$oid] = $result['payment'] ?? [];
                }
            } catch (Throwable $e) {
                Log::warning('SettlementIngestionService: falha billing/order/details', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        // 2) net_received_amount + money_release_date por pagamento via /collections/{id}.
        $entries = [];
        foreach ($orders as $row) {
            $orderId = (string)$row['order_id'];
            $paymentId = (string)($row['payment_id'] ?? '');
            if ($paymentId === '') {
                $stats['skipped_no_payment']++;
                continue;
            }
            $stats['orders_with_payment']++;

            try {
                $collection = $this->fetchCollection($paymentId);
                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
                if ($collection === null) {
                    $stats['skipped_no_amount']++;
                    continue;
                }

                $billingPayment = $releaseStatusByOrder[$orderId] ?? [];
                $payload = [
                    'net_received_amount' => $collection['net_received_amount'] ?? null,
                    'money_release_date' => $collection['money_release_date'] ?? null,
                    'money_release_status' => $billingPayment['money_release_status'] ?? null,
                    'currency_id' => $collection['currency_id'] ?? 'BRL',
                ];

                $occurredAt = $this->parseDate($collection['date_created'] ?? $row['date_created'] ?? null)
                    ?? new DateTimeImmutable('now');

                $entry = $this->normalizer->fromRelease($this->accountId, $orderId, $paymentId, $payload, $occurredAt);
                if ($entry === null) {
                    $stats['skipped_no_amount']++;
                    continue;
                }

                if ($entry->status === 'posted') {
                    $stats['released_count']++;
                } else {
                    $stats['pending_count']++;
                }
                $entries[] = $entry;
            } catch (Throwable $e) {
                $stats['errors'][] = ['order_id' => $orderId, 'payment_id' => $paymentId, 'error' => $e->getMessage()];
                Log::warning('SettlementIngestionService: falha no pedido', [
                    'account_id' => $this->accountId,
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($dryRun) {
            $stats['entries_unchanged'] = count($entries);
            return $stats;
        }

        $upsert = $this->ledger->upsertMany($entries);
        $stats['entries_created'] = $upsert['created'];
        $stats['entries_updated'] = $upsert['updated'];
        $stats['entries_unchanged'] = $upsert['unchanged'];

        return $stats;
    }

    /**
     * @return list<array{order_id: string, payment_id: string, date_created: ?string}>
     */
    private function loadPaidOrders(string $fromDate, string $toDate, int $limit): array
    {
        $from = $fromDate . ' 00:00:00';
        $to = $toDate . ' 23:59:59';
        $sql = "SELECT ml_order_id, order_data, date_created
                FROM ml_orders
                WHERE ml_account_id = :account_id
                  AND date_created BETWEEN :from AND :to
                  AND status IN ('paid', 'confirmed', 'ready_to_ship', 'shipped', 'delivered', 'handling')
                ORDER BY date_created ASC";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':from' => $from,
            ':to' => $to,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $orderData = json_decode((string)($row['order_data'] ?? '{}'), true);
            if (!is_array($orderData)) {
                $orderData = [];
            }
            $paymentId = '';
            foreach ($orderData['payments'] ?? [] as $payment) {
                if (is_array($payment) && ($payment['status'] ?? '') === 'approved' && !empty($payment['id'])) {
                    $paymentId = (string)$payment['id'];
                    break;
                }
            }
            $out[] = [
                'order_id' => (string)$row['ml_order_id'],
                'payment_id' => $paymentId,
                'date_created' => $row['date_created'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCollection(string $paymentId): ?array
    {
        try {
            $client = $this->getClient();
            $response = $client->get('/collections/' . $paymentId);
            if (isset($response['body']) && is_array($response['body'])) {
                $response = $response['body'];
            }
            if (isset($response['error']) || !is_array($response)) {
                return null;
            }
            if (!isset($response['net_received_amount'])) {
                return null;
            }
            return $response;
        } catch (Throwable $e) {
            Log::warning('SettlementIngestionService: falha /collections', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function getClient(): MercadoLivreClient
    {
        return $this->client ??= new MercadoLivreClient($this->accountId);
    }

    private function getFeeService(): FeeCommissionService
    {
        return $this->feeService ??= new FeeCommissionService($this->accountId);
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
}
