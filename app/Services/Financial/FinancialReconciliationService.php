<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Database;
use PDO;
use Throwable;

/**
 * Concilia esperado (pedido/colunas) vs realizado (ledger) e persiste divergências.
 */
final class FinancialReconciliationService
{
    private PDO $db;
    private FinancialLedgerService $ledger;

    public function __construct(
        private readonly int $accountId,
        ?PDO $db = null,
        ?FinancialLedgerService $ledger = null,
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->ledger = $ledger ?? new FinancialLedgerService($this->db);
    }

    /**
     * @return array<string, mixed>
     */
    public function reconcilePeriod(string $fromDate, string $toDate, int $limit = 0): array
    {
        $orders = $this->loadOrders($fromDate, $toDate, $limit);
        $stats = [
            'account_id' => $this->accountId,
            'from' => $fromDate,
            'to' => $toDate,
            'orders_checked' => 0,
            'discrepancies_upserted' => 0,
            'open_info' => 0,
            'open_warning' => 0,
            'open_critical' => 0,
            'by_type' => [],
        ];

        foreach ($orders as $row) {
            $found = $this->reconcileOrderRow($row);
            $stats['orders_checked']++;
            $activeTypes = [];
            foreach ($found as $d) {
                $action = $this->upsertDiscrepancy($d);
                if ($action !== 'unchanged') {
                    $stats['discrepancies_upserted']++;
                }
                $type = (string)$d['discrepancy_type'];
                $activeTypes[$type] = true;
                $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
                $sev = (string)$d['severity'];
                if ($sev === 'info') {
                    $stats['open_info']++;
                } elseif ($sev === 'critical') {
                    $stats['open_critical']++;
                } else {
                    $stats['open_warning']++;
                }
            }
            $orderId = (string)($row['ml_order_id'] ?? '');
            if ($orderId !== '') {
                $stale = $this->resolveStaleForOrder($orderId, array_keys($activeTypes));
                if ($stale > 0) {
                    $stats['stale_resolved'] = ($stats['stale_resolved'] ?? 0) + $stale;
                }
            }
        }

        return $stats;
    }

    /**
     * Fecha divergências open do pedido que não foram redetectadas nesta passagem.
     *
     * @param list<string> $activeTypes
     */
    private function resolveStaleForOrder(string $orderId, array $activeTypes): int
    {
        if ($activeTypes === []) {
            $stmt = $this->db->prepare(
                "UPDATE financial_discrepancies
                 SET status = 'resolved',
                     resolution_note = COALESCE(resolution_note, 'auto: condição não reproduzida'),
                     resolution_action = 'resolved',
                     resolved_at = NOW()
                 WHERE account_id = :a
                   AND order_id = :o
                   AND status = 'open'"
            );
            $stmt->execute([':a' => $this->accountId, ':o' => $orderId]);
            return $stmt->rowCount();
        }

        $placeholders = [];
        $params = [':a' => $this->accountId, ':o' => $orderId];
        foreach (array_values($activeTypes) as $i => $type) {
            $key = ':t' . $i;
            $placeholders[] = $key;
            $params[$key] = $type;
        }
        $in = implode(',', $placeholders);
        $stmt = $this->db->prepare(
            "UPDATE financial_discrepancies
             SET status = 'resolved',
                 resolution_note = COALESCE(resolution_note, 'auto: condição não reproduzida'),
                 resolution_action = 'resolved',
                 resolved_at = NOW()
             WHERE account_id = :a
               AND order_id = :o
               AND status = 'open'
               AND discrepancy_type NOT IN ({$in})"
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByOrder(string $orderId, ?string $status = 'open'): array
    {
        $sql = 'SELECT * FROM financial_discrepancies
                WHERE account_id = :a AND order_id = :o';
        $params = [':a' => $this->accountId, ':o' => $orderId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :s';
            $params[':s'] = $status;
        }
        $sql .= ' ORDER BY FIELD(severity, "critical","warning","info"), detected_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{sale_revenue: float, fees: float, shipping_net: float, refund_net: float, protection_net: float, marketplace_net: float, entries_count: int, covered_entries: int}|null
     */
    public function getOrderReconciliationView(string $orderId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM vw_order_financial_reconciliation
                 WHERE account_id = :a AND order_id = :o LIMIT 1'
            );
            $stmt->execute([':a' => $this->accountId, ':o' => $orderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            // view pode não existir em ambientes sem migrate — fallback ledger
            $sum = $this->ledger->summarizeOrder($this->accountId, $orderId);
            if ($sum['entries_count'] <= 0) {
                return null;
            }
            return [
                'sale_revenue' => (float)($sum['by_type'][FinancialEntryType::SALE_REVENUE] ?? 0),
                'fees' => (float)(($sum['by_type'][FinancialEntryType::SALE_FEE] ?? 0)
                    + ($sum['by_type'][FinancialEntryType::PAYMENT_FEE] ?? 0)),
                'shipping_net' => (float)($sum['by_category'][FinancialEntryCategory::SHIPPING] ?? 0),
                'refund_net' => (float)($sum['by_category'][FinancialEntryCategory::REFUND] ?? 0),
                'protection_net' => (float)($sum['by_category'][FinancialEntryCategory::PROTECTION] ?? 0),
                'marketplace_net' => (float)$sum['marketplace_net'],
                'entries_count' => (int)$sum['entries_count'],
                'covered_entries' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function reconcileOrderRow(array $row): array
    {
        $orderId = (string)$row['ml_order_id'];
        $orderData = json_decode((string)($row['order_data'] ?? '{}'), true);
        if (!is_array($orderData)) {
            $orderData = [];
        }

        $entries = $this->ledger->listByOrder($this->accountId, $orderId);
        $summary = $this->ledger->summarizeOrder($this->accountId, $orderId);
        $found = [];

        $hasRevenue = false;
        $hasFee = false;
        $hasShipping = false;
        $postedRefunds = 0;

        foreach ($entries as $e) {
            $type = (string)($e['entry_type'] ?? '');
            $status = (string)($e['status'] ?? '');
            if ($type === FinancialEntryType::SALE_REVENUE && $status === 'posted') {
                $hasRevenue = true;
            }
            if ($type === FinancialEntryType::SALE_FEE && $status === 'posted') {
                $hasFee = true;
            }
            if ($type === FinancialEntryType::SHIPPING_COST && $status === 'posted') {
                $hasShipping = true;
            }
            if ($type === FinancialEntryType::REFUND && $status === 'posted') {
                $postedRefunds++;
            }
        }

        $totalAmount = (float)($row['total_amount'] ?? 0);
        $dbCommission = (float)($row['ml_commission'] ?? 0);
        $dbShipping = (float)($row['shipping_cost'] ?? 0);
        $shipmentId = $orderData['shipping']['id'] ?? null;
        $orderStatus = (string)($row['status'] ?? '');

        if ($entries === [] && $totalAmount > 0) {
            $found[] = $this->disc(
                FinancialDiscrepancyType::ORDER_WITHOUT_PAYMENT,
                'warning',
                $orderId,
                null,
                $totalAmount,
                0.0,
                'Pedido sem lançamentos no ledger'
            );
            return $found;
        }

        if ($hasRevenue && !$hasFee && $orderStatus !== 'cancelled') {
            $found[] = $this->disc(
                FinancialDiscrepancyType::MISSING_SALE_FEE,
                'warning',
                $orderId,
                null,
                $dbCommission > 0 ? $dbCommission : null,
                0.0,
                'Receita no ledger sem comissão sale_fee'
            );
        }

        $ledgerFee = abs((float)($summary['by_type'][FinancialEntryType::SALE_FEE] ?? 0));
        if ($dbCommission > 0 && $ledgerFee > 0 && abs($dbCommission - $ledgerFee) > 0.05) {
            $found[] = $this->disc(
                FinancialDiscrepancyType::COMMISSION_MISMATCH,
                'warning',
                $orderId,
                null,
                $dbCommission,
                $ledgerFee,
                'Comissão ml_orders diverge do ledger'
            );
        }

        if ($shipmentId && !$hasShipping && $dbShipping > 0.0 && !in_array($orderStatus, ['cancelled'], true)) {
            $found[] = $this->disc(
                FinancialDiscrepancyType::MISSING_SHIPPING_COST,
                'warning',
                $orderId,
                null,
                $dbShipping,
                0.0,
                'Shipment presente sem frete seller no ledger'
            );
        }

        $ledgerShip = abs((float)($summary['by_type'][FinancialEntryType::SHIPPING_COST] ?? 0));
        if ($dbShipping > 0 && $ledgerShip > 0 && abs($dbShipping - $ledgerShip) > 0.05) {
            $found[] = $this->disc(
                FinancialDiscrepancyType::SHIPPING_COST_MISMATCH,
                'warning',
                $orderId,
                null,
                $dbShipping,
                $ledgerShip,
                'Frete ml_orders diverge do ledger'
            );
        }

        // bpp_covered (refund status=covered) é esperado — não abre divergência.

        if ($postedRefunds > 1) {
            $found[] = $this->disc(
                FinancialDiscrepancyType::REFUND_DEBITED_TWICE,
                'critical',
                $orderId,
                null,
                null,
                null,
                'Mais de um reembolso posted no ledger para o mesmo pedido'
            );
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function disc(
        string $type,
        string $severity,
        string $orderId,
        ?string $paymentId,
        ?float $expected,
        ?float $actual,
        string $explanation
    ): array {
        $diff = null;
        if ($expected !== null && $actual !== null) {
            $diff = round($actual - $expected, 2);
        }
        return [
            'discrepancy_type' => $type,
            'severity' => $severity,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'expected_amount' => $expected,
            'actual_amount' => $actual,
            'difference_amount' => $diff,
            'explanation' => $explanation,
        ];
    }

    /**
     * @param array<string, mixed> $d
     */
    private function upsertDiscrepancy(array $d): string
    {
        $fingerprint = hash('sha256', implode('|', [
            (string)$this->accountId,
            (string)$d['discrepancy_type'],
            (string)($d['order_id'] ?? ''),
            (string)($d['payment_id'] ?? ''),
        ]));

        $stmt = $this->db->prepare(
            'SELECT id, status FROM financial_discrepancies WHERE fingerprint = :f LIMIT 1'
        );
        $stmt->execute([':f' => $fingerprint]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (($existing['status'] ?? '') === 'resolved' || ($existing['status'] ?? '') === 'ignored') {
                return 'unchanged';
            }
            $upd = $this->db->prepare(
                'UPDATE financial_discrepancies SET
                    severity = :sev,
                    expected_amount = :exp,
                    actual_amount = :act,
                    difference_amount = :diff,
                    explanation = :expl,
                    detected_at = NOW()
                 WHERE id = :id'
            );
            $upd->execute([
                ':sev' => $d['severity'],
                ':exp' => $d['expected_amount'],
                ':act' => $d['actual_amount'],
                ':diff' => $d['difference_amount'],
                ':expl' => $d['explanation'],
                ':id' => $existing['id'],
            ]);
            return 'updated';
        }

        $ins = $this->db->prepare(
            'INSERT INTO financial_discrepancies (
                account_id, order_id, payment_id, discrepancy_type, severity,
                expected_amount, actual_amount, difference_amount, status, explanation,
                detected_at, fingerprint
            ) VALUES (
                :a, :o, :p, :t, :sev, :exp, :act, :diff, :st, :expl, NOW(), :f
            )'
        );
        $ins->execute([
            ':a' => $this->accountId,
            ':o' => $d['order_id'],
            ':p' => $d['payment_id'],
            ':t' => $d['discrepancy_type'],
            ':sev' => $d['severity'],
            ':exp' => $d['expected_amount'],
            ':act' => $d['actual_amount'],
            ':diff' => $d['difference_amount'],
            ':st' => 'open',
            ':expl' => $d['explanation'],
            ':f' => $fingerprint,
        ]);
        return 'created';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadOrders(string $fromDate, string $toDate, int $limit): array
    {
        $sql = 'SELECT ml_order_id, status, total_amount, ml_commission, shipping_cost, order_data
                FROM ml_orders
                WHERE ml_account_id = :a
                  AND date_created BETWEEN :f AND :t
                ORDER BY date_created ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':a' => $this->accountId,
            ':f' => $fromDate . ' 00:00:00',
            ':t' => $toDate . ' 23:59:59',
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Lista divergências abertas (ou por status) da conta no período.
     *
     * @return list<array<string, mixed>>
     */
    public function listOpen(
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $status = 'open',
        int $limit = 100
    ): array {
        $sql = 'SELECT * FROM financial_discrepancies WHERE account_id = :a';
        $params = [':a' => $this->accountId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :s';
            $params[':s'] = $status;
        }
        if ($fromDate !== null && $fromDate !== '') {
            $sql .= ' AND detected_at >= :f';
            $params[':f'] = $fromDate . ' 00:00:00';
        }
        if ($toDate !== null && $toDate !== '') {
            $sql .= ' AND detected_at <= :t';
            $params[':t'] = $toDate . ' 23:59:59';
        }
        $sql .= ' ORDER BY FIELD(severity, "critical","warning","info"), detected_at DESC LIMIT '
            . max(1, min(500, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Resolve em lote divergências info de bpp_covered (não são anomalias).
     *
     * @return int quantidade marcada como resolved
     */
    public function acknowledgeCoveredRefundInfos(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE financial_discrepancies
             SET status = 'resolved',
                 resolution_note = COALESCE(resolution_note, 'auto: bpp_covered esperado'),
                 resolved_at = NOW()
             WHERE account_id = :a
               AND status = 'open'
               AND discrepancy_type = :t
               AND severity = 'info'"
        );
        $stmt->execute([
            ':a' => $this->accountId,
            ':t' => FinancialDiscrepancyType::REFUND_WITHOUT_FINANCIAL_DEBIT,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Resolve/ignora/reabre divergência manualmente.
     *
     * @return array{success: bool, error?: string}
     */
    public function resolveDiscrepancy(
        int $discrepancyId,
        string $action,
        ?int $resolvedBy = null,
        ?string $note = null
    ): array {
        $action = strtolower(trim($action));
        if (!in_array($action, ['resolved', 'ignored', 'reopened'], true)) {
            return ['success' => false, 'error' => 'Ação inválida (resolved|ignored|reopened)'];
        }

        $stmt = $this->db->prepare(
            'SELECT id, status FROM financial_discrepancies
             WHERE id = :id AND account_id = :a LIMIT 1'
        );
        $stmt->execute([':id' => $discrepancyId, ':a' => $this->accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'error' => 'Divergência não encontrada'];
        }

        $newStatus = $action === 'reopened' ? 'open' : $action;
        $upd = $this->db->prepare(
            'UPDATE financial_discrepancies SET
                status = :st,
                resolved_at = CASE WHEN :st2 = \'open\' THEN NULL ELSE NOW() END,
                resolved_by = :by,
                resolution_note = :note,
                resolution_action = :act
             WHERE id = :id AND account_id = :a'
        );
        $upd->execute([
            ':st' => $newStatus,
            ':st2' => $newStatus,
            ':by' => $resolvedBy,
            ':note' => $note,
            ':act' => $action,
            ':id' => $discrepancyId,
            ':a' => $this->accountId,
        ]);

        return ['success' => true];
    }
}
