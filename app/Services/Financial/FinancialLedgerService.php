<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Database;
use PDO;
use Throwable;

/**
 * Persistência idempotente do livro financeiro.
 * Mesma chave (account + source + entry_type) → um único lançamento.
 */
final class FinancialLedgerService
{
    private PDO $db;

    /** Status que não entram no marketplace_net */
    private const EXCLUDED_FROM_NET = ['cancelled', 'rejected', 'covered'];

    /**
     * Categorias de caixa (liberação/saque) — não são resultado de venda (P&L).
     * Ficam de fora do marketplace_net para não duplicar receita/despesa já
     * contabilizada via sale_revenue/sale_fee/shipping_cost/refund etc.
     * Somadas separadamente em settlement_net (ver aggregateEntries()).
     */
    private const CASH_CATEGORIES = [
        FinancialEntryCategory::SETTLEMENT,
        FinancialEntryCategory::WITHDRAWAL,
        FinancialEntryCategory::HOLD,
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Insere ou atualiza se payload_hash mudou. Retorna id + created|updated|unchanged.
     *
     * @return array{id: int, action: string}
     */
    public function upsert(NormalizedFinancialEntry $entry): array
    {
        $hash = $entry->payloadHash();
        $rawJson = $entry->rawData === []
            ? null
            : (json_encode($entry->rawData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null);

        $existing = $this->findByUniqueKey(
            $entry->accountId,
            $entry->sourceSystem,
            $entry->sourceType,
            $entry->sourceId,
            $entry->sourceDetailId,
            $entry->entryType
        );

        if ($existing !== null) {
            $id = (int)$existing['id'];
            if (($existing['payload_hash'] ?? '') === $hash
                && (string)($existing['status'] ?? '') === $entry->status
                && abs((float)$existing['signed_amount'] - $entry->signedAmount) < 0.001
            ) {
                return ['id' => $id, 'action' => 'unchanged'];
            }

            $stmt = $this->db->prepare(
                'UPDATE financial_ledger_entries SET
                    order_id = :order_id,
                    payment_id = :payment_id,
                    shipment_id = :shipment_id,
                    claim_id = :claim_id,
                    refund_id = :refund_id,
                    settlement_id = :settlement_id,
                    entry_category = :entry_category,
                    direction = :direction,
                    amount = :amount,
                    signed_amount = :signed_amount,
                    currency = :currency,
                    occurred_at = :occurred_at,
                    released_at = :released_at,
                    available_at = :available_at,
                    status = :status,
                    description = :description,
                    raw_data = :raw_data,
                    payload_hash = :payload_hash,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':order_id' => $entry->orderId,
                ':payment_id' => $entry->paymentId,
                ':shipment_id' => $entry->shipmentId,
                ':claim_id' => $entry->claimId,
                ':refund_id' => $entry->refundId,
                ':settlement_id' => $entry->settlementId,
                ':entry_category' => $entry->category,
                ':direction' => $entry->direction,
                ':amount' => $entry->amount,
                ':signed_amount' => $entry->signedAmount,
                ':currency' => $entry->currency,
                ':occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s'),
                ':released_at' => $entry->releasedAt?->format('Y-m-d H:i:s'),
                ':available_at' => $entry->availableAt?->format('Y-m-d H:i:s'),
                ':status' => $entry->status,
                ':description' => $entry->description,
                ':raw_data' => $rawJson,
                ':payload_hash' => $hash,
                ':id' => $id,
            ]);

            return ['id' => $id, 'action' => 'updated'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO financial_ledger_entries (
                account_id, source_system, source_type, source_id, source_detail_id,
                order_id, payment_id, shipment_id, claim_id, refund_id, settlement_id,
                entry_type, entry_category, direction, amount, signed_amount, currency,
                occurred_at, released_at, available_at, imported_at, status, description,
                raw_data, payload_hash, created_at, updated_at
            ) VALUES (
                :account_id, :source_system, :source_type, :source_id, :source_detail_id,
                :order_id, :payment_id, :shipment_id, :claim_id, :refund_id, :settlement_id,
                :entry_type, :entry_category, :direction, :amount, :signed_amount, :currency,
                :occurred_at, :released_at, :available_at, NOW(), :status, :description,
                :raw_data, :payload_hash, NOW(), NOW()
            )'
        );
        $stmt->execute([
            ':account_id' => $entry->accountId,
            ':source_system' => $entry->sourceSystem,
            ':source_type' => $entry->sourceType,
            ':source_id' => $entry->sourceId,
            ':source_detail_id' => $entry->sourceDetailId,
            ':order_id' => $entry->orderId,
            ':payment_id' => $entry->paymentId,
            ':shipment_id' => $entry->shipmentId,
            ':claim_id' => $entry->claimId,
            ':refund_id' => $entry->refundId,
            ':settlement_id' => $entry->settlementId,
            ':entry_type' => $entry->entryType,
            ':entry_category' => $entry->category,
            ':direction' => $entry->direction,
            ':amount' => $entry->amount,
            ':signed_amount' => $entry->signedAmount,
            ':currency' => $entry->currency,
            ':occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s'),
            ':released_at' => $entry->releasedAt?->format('Y-m-d H:i:s'),
            ':available_at' => $entry->availableAt?->format('Y-m-d H:i:s'),
            ':status' => $entry->status,
            ':description' => $entry->description,
            ':raw_data' => $rawJson,
            ':payload_hash' => $hash,
        ]);

        return ['id' => (int)$this->db->lastInsertId(), 'action' => 'created'];
    }

    /**
     * @param list<NormalizedFinancialEntry> $entries
     * @return array{created: int, updated: int, unchanged: int, ids: list<int>}
     */
    public function upsertMany(array $entries): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $ids = [];

        foreach ($entries as $entry) {
            if (!$entry instanceof NormalizedFinancialEntry) {
                continue;
            }
            $result = $this->upsert($entry);
            $ids[] = $result['id'];
            match ($result['action']) {
                'created' => $created++,
                'updated' => $updated++,
                default => $unchanged++,
            };
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'ids' => $ids,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByOrder(int $accountId, string $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM financial_ledger_entries
             WHERE account_id = :account_id AND order_id = :order_id
             ORDER BY occurred_at ASC, id ASC'
        );
        $stmt->execute([
            ':account_id' => $accountId,
            ':order_id' => $orderId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Soma signed_amount do pedido excluindo status covered/cancelled/rejected.
     *
     * @return array{
     *   marketplace_net: float,
     *   settlement_net: float,
     *   released_amount: float,
     *   pending_release_amount: float,
     *   withdrawn_amount: float,
     *   hold_amount: float,
     *   by_type: array<string, float>,
     *   by_category: array<string, float>,
     *   entries_count: int
     * }
     */
    public function summarizeOrder(int $accountId, string $orderId): array
    {
        return self::aggregateEntries($this->listByOrder($accountId, $orderId));
    }

    /**
     * Lista lançamentos do período (por occurred_at).
     *
     * @return list<array<string, mixed>>
     */
    public function listByPeriod(int $accountId, string $fromDate, string $toDate, ?string $entryCategory = null): array
    {
        $from = strlen($fromDate) === 10 ? $fromDate . ' 00:00:00' : $fromDate;
        $to = strlen($toDate) === 10 ? $toDate . ' 23:59:59' : $toDate;
        $sql = 'SELECT * FROM financial_ledger_entries
                WHERE account_id = :account_id
                  AND occurred_at BETWEEN :from AND :to';
        $params = [
            ':account_id' => $accountId,
            ':from' => $from,
            ':to' => $to,
        ];
        if ($entryCategory !== null && $entryCategory !== '') {
            $sql .= ' AND entry_category = :cat';
            $params[':cat'] = $entryCategory;
        }
        $sql .= ' ORDER BY occurred_at ASC, id ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{
     *   marketplace_net: float,
     *   settlement_net: float,
     *   released_amount: float,
     *   pending_release_amount: float,
     *   withdrawn_amount: float,
     *   hold_amount: float,
     *   by_type: array<string, float>,
     *   by_category: array<string, float>,
     *   entries_count: int
     * }
     */
    public function summarizePeriod(int $accountId, string $fromDate, string $toDate): array
    {
        return self::aggregateEntries($this->listByPeriod($accountId, $fromDate, $toDate));
    }

    /**
     * Agrega linhas já carregadas do ledger (puro, sem I/O — testável sem DB).
     * marketplace_net = resultado da VENDA (receita/comissão/frete/refund/proteção/ads).
     * Categorias de caixa (settlement/withdrawal/hold) NUNCA entram no marketplace_net.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{
     *   marketplace_net: float,
     *   settlement_net: float,
     *   released_amount: float,
     *   pending_release_amount: float,
     *   withdrawn_amount: float,
     *   hold_amount: float,
     *   by_type: array<string, float>,
     *   by_category: array<string, float>,
     *   entries_count: int
     * }
     */
    public static function aggregateEntries(array $rows): array
    {
        $net = 0.0;
        $settlementNet = 0.0;
        $releasedAmount = 0.0;
        $pendingReleaseAmount = 0.0;
        $withdrawnAmount = 0.0;
        $holdAmount = 0.0;
        $byType = [];
        $byCategory = [];
        $count = 0;

        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if (in_array($status, self::EXCLUDED_FROM_NET, true)) {
                continue;
            }
            $signed = (float)$row['signed_amount'];
            $type = (string)$row['entry_type'];
            $cat = (string)$row['entry_category'];
            $isCash = in_array($cat, self::CASH_CATEGORIES, true);

            if (!$isCash) {
                $net += $signed;
            } else {
                $settlementNet += $signed;
                if ($type === FinancialEntryType::SETTLEMENT_RELEASE) {
                    if ($status === 'posted') {
                        $releasedAmount += abs($signed);
                    } elseif ($status === 'pending') {
                        $pendingReleaseAmount += abs($signed);
                    }
                }
                if ($type === FinancialEntryType::WITHDRAWAL && $status === 'posted') {
                    $withdrawnAmount += abs($signed);
                }
                if ($type === FinancialEntryType::PROGRAM_HOLD && $status === 'posted') {
                    $holdAmount += abs($signed);
                }
            }

            $byType[$type] = ($byType[$type] ?? 0.0) + $signed;
            $byCategory[$cat] = ($byCategory[$cat] ?? 0.0) + $signed;
            $count++;
        }

        foreach ($byType as $k => $v) {
            $byType[$k] = round($v, 2);
        }
        foreach ($byCategory as $k => $v) {
            $byCategory[$k] = round($v, 2);
        }

        return [
            'marketplace_net' => round($net, 2),
            'settlement_net' => round($settlementNet, 2),
            'released_amount' => round($releasedAmount, 2),
            'pending_release_amount' => round($pendingReleaseAmount, 2),
            'withdrawn_amount' => round($withdrawnAmount, 2),
            'hold_amount' => round($holdAmount, 2),
            'by_type' => $byType,
            'by_category' => $byCategory,
            'entries_count' => $count,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByUniqueKey(
        int $accountId,
        string $sourceSystem,
        string $sourceType,
        string $sourceId,
        string $sourceDetailId,
        string $entryType
    ): ?array {
        $stmt = $this->db->prepare(
            'SELECT id, payload_hash, status, signed_amount
             FROM financial_ledger_entries
             WHERE account_id = :account_id
               AND source_system = :source_system
               AND source_type = :source_type
               AND source_id = :source_id
               AND source_detail_id = :source_detail_id
               AND entry_type = :entry_type
             LIMIT 1'
        );
        $stmt->execute([
            ':account_id' => $accountId,
            ':source_system' => $sourceSystem,
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':source_detail_id' => $sourceDetailId,
            ':entry_type' => $entryType,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Agrega ledger para vários pedidos de uma vez (evita N+1 na listagem).
     *
     * @param list<string> $orderIds
     * @return array<string, array{
     *   marketplace_net: float,
     *   settlement_net: float,
     *   released_amount: float,
     *   pending_release_amount: float,
     *   by_type: array<string, float>,
     *   by_category: array<string, float>,
     *   entries_count: int,
     *   refund_covered: float,
     *   has_ledger: bool
     * }>
     */
    public function summarizeOrders(int $accountId, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds))));
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $params = array_merge([$accountId], $orderIds);
        $stmt = $this->db->prepare(
            "SELECT order_id, entry_type, entry_category, status, signed_amount
             FROM financial_ledger_entries
             WHERE account_id = ? AND order_id IN ({$placeholders})"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rowsByOrder = [];
        foreach ($orderIds as $oid) {
            $rowsByOrder[$oid] = [];
        }
        $refundCovered = array_fill_keys($orderIds, 0.0);
        foreach ($rows as $row) {
            $oid = (string)$row['order_id'];
            if (!isset($rowsByOrder[$oid])) {
                continue;
            }
            $rowsByOrder[$oid][] = $row;
            if ((string)$row['entry_type'] === FinancialEntryType::REFUND && (string)($row['status'] ?? '') === 'covered') {
                $refundCovered[$oid] += abs((float)$row['signed_amount']);
            }
        }

        $out = [];
        foreach ($orderIds as $oid) {
            $agg = self::aggregateEntries($rowsByOrder[$oid]);
            $out[$oid] = $agg + [
                'refund_covered' => round($refundCovered[$oid], 2),
                'has_ledger' => $rowsByOrder[$oid] !== [],
            ];
        }

        return $out;
    }

    /**
     * Remove lançamentos de teste (somente source_system=internal + prefixo).
     */
    public function deleteTestEntries(int $accountId, string $sourceIdPrefix): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM financial_ledger_entries
             WHERE account_id = :account_id
               AND source_system = :sys
               AND source_id LIKE :prefix'
        );
        $stmt->execute([
            ':account_id' => $accountId,
            ':sys' => 'internal',
            ':prefix' => $sourceIdPrefix . '%',
        ]);

        return $stmt->rowCount();
    }
}
