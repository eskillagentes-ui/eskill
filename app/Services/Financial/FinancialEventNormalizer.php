<?php

declare(strict_types=1);

namespace App\Services\Financial;

use DateTimeImmutable;
use Throwable;

/**
 * Converte payloads ML/MP/internos em NormalizedFinancialEntry.
 * Não chama API e não calcula lucro — só normaliza eventos.
 */
final class FinancialEventNormalizer
{
    /**
     * Extrai lançamentos básicos de um pedido ML (receita, comissão, taxa pagamento, desconto).
     * Frete seller NÃO vem do order.shipping.cost (comprador) — use fromShipmentCosts().
     *
     * @param array<string, mixed> $order
     * @return list<NormalizedFinancialEntry>
     */
    public function fromOrder(int $accountId, array $order): array
    {
        $orderId = (string)($order['id'] ?? '');
        if ($orderId === '') {
            return [];
        }

        $occurredAt = $this->parseDate($order['date_created'] ?? null) ?? new DateTimeImmutable('now');
        $currency = (string)($order['currency_id'] ?? 'BRL');
        // Status do LANÇAMENTO ≠ status do pedido: se houve pagamento aprovado,
        // receita/fee permanecem posted mesmo com pedido cancelled (ex.: bpp_covered).
        $status = $this->resolveOrderEntryStatus($order);
        $entries = [];

        $totalAmount = round((float)($order['total_amount'] ?? 0), 2);
        if ($totalAmount > 0) {
            $entries[] = NormalizedFinancialEntry::fromType(
                accountId: $accountId,
                sourceSystem: 'ml',
                sourceType: 'order',
                sourceId: $orderId,
                entryType: FinancialEntryType::SALE_REVENUE,
                amount: $totalAmount,
                occurredAt: $occurredAt,
                sourceDetailId: '',
                orderId: $orderId,
                status: $status,
                description: 'Receita bruta do pedido',
                currency: $currency,
                rawData: ['total_amount' => $totalAmount],
            );
        }

        $saleFeeTotal = 0.0;
        foreach ($order['order_items'] ?? [] as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $fee = round((float)($item['sale_fee'] ?? 0), 2);
            if ($fee <= 0) {
                continue;
            }
            $saleFeeTotal += $fee;
            $itemId = (string)($item['item']['id'] ?? $idx);
            $entries[] = NormalizedFinancialEntry::fromType(
                accountId: $accountId,
                sourceSystem: 'ml',
                sourceType: 'order',
                sourceId: $orderId,
                entryType: FinancialEntryType::SALE_FEE,
                amount: $fee,
                occurredAt: $occurredAt,
                sourceDetailId: 'item:' . $itemId,
                orderId: $orderId,
                status: $status,
                description: 'Comissão ML (sale_fee)',
                currency: $currency,
                rawData: ['sale_fee' => $fee, 'item_id' => $itemId],
            );
        }

        // Fallback: marketplace_fee no payment se itens sem sale_fee
        if ($saleFeeTotal <= 0) {
            foreach ($order['payments'] ?? [] as $payment) {
                if (!is_array($payment)) {
                    continue;
                }
                $mktFee = round((float)($payment['marketplace_fee'] ?? 0), 2);
                if ($mktFee <= 0) {
                    continue;
                }
                $payId = (string)($payment['id'] ?? '');
                $entries[] = NormalizedFinancialEntry::fromType(
                    accountId: $accountId,
                    sourceSystem: 'ml',
                    sourceType: 'order',
                    sourceId: $orderId,
                    entryType: FinancialEntryType::SALE_FEE,
                    amount: $mktFee,
                    occurredAt: $occurredAt,
                    sourceDetailId: 'payment_fee:' . ($payId !== '' ? $payId : '0'),
                    orderId: $orderId,
                    paymentId: $payId !== '' ? $payId : null,
                    status: $status,
                    description: 'Comissão ML (marketplace_fee)',
                    currency: $currency,
                    rawData: ['marketplace_fee' => $mktFee],
                );
            }
        }

        foreach ($order['payments'] ?? [] as $payment) {
            if (!is_array($payment) || ($payment['status'] ?? '') !== 'approved') {
                continue;
            }
            $payId = (string)($payment['id'] ?? '');
            $feeSum = 0.0;
            foreach ($payment['fee_details'] ?? [] as $feeDetail) {
                if (!is_array($feeDetail)) {
                    continue;
                }
                $feeSum += (float)($feeDetail['amount'] ?? 0);
            }
            $feeSum = round($feeSum, 2);
            if ($feeSum <= 0) {
                continue;
            }
            $entries[] = NormalizedFinancialEntry::fromType(
                accountId: $accountId,
                sourceSystem: 'ml',
                sourceType: 'order',
                sourceId: $orderId,
                entryType: FinancialEntryType::PAYMENT_FEE,
                amount: $feeSum,
                occurredAt: $occurredAt,
                sourceDetailId: 'payment:' . ($payId !== '' ? $payId : '0'),
                orderId: $orderId,
                paymentId: $payId !== '' ? $payId : null,
                status: $status,
                description: 'Taxa de pagamento',
                currency: $currency,
                rawData: ['fee_details' => $payment['fee_details'] ?? []],
            );
        }

        $coupon = round((float)($order['coupon']['amount'] ?? 0), 2);
        if ($coupon <= 0) {
            foreach ($order['payments'] ?? [] as $payment) {
                if (is_array($payment)) {
                    $coupon = max($coupon, round((float)($payment['coupon_amount'] ?? 0), 2));
                }
            }
        }
        if ($coupon > 0) {
            $entries[] = NormalizedFinancialEntry::fromType(
                accountId: $accountId,
                sourceSystem: 'ml',
                sourceType: 'order',
                sourceId: $orderId,
                entryType: FinancialEntryType::COMMERCIAL_DISCOUNT,
                amount: $coupon,
                occurredAt: $occurredAt,
                orderId: $orderId,
                status: $status,
                description: 'Cupom / desconto comercial',
                currency: $currency,
                rawData: ['coupon_amount' => $coupon],
            );
        }

        return $entries;
    }

    /**
     * Frete do vendedor a partir de GET /shipments/{id}/costs (senders[].cost).
     *
     * @param array<string, mixed> $costsPayload
     * @return list<NormalizedFinancialEntry>
     */
    public function fromShipmentCosts(
        int $accountId,
        string $shipmentId,
        array $costsPayload,
        ?string $orderId = null,
        ?DateTimeImmutable $occurredAt = null
    ): array {
        if ($shipmentId === '') {
            return [];
        }

        $occurredAt ??= new DateTimeImmutable('now');
        $entries = [];
        $senders = $costsPayload['senders'] ?? [];
        if (!is_array($senders)) {
            return [];
        }

        // Um lançamento por shipment (source_detail_id estável = "seller") para
        // DB backfill e API /costs colidirem na UNIQUE e não duplicarem frete.
        $totalSellerCost = 0.0;
        $senderRaw = [];
        foreach ($senders as $sender) {
            if (!is_array($sender)) {
                continue;
            }
            $cost = round((float)($sender['cost'] ?? 0), 2);
            if ($cost <= 0) {
                continue;
            }
            $totalSellerCost += $cost;
            $senderRaw[] = $sender;
        }
        $totalSellerCost = round($totalSellerCost, 2);

        if ($totalSellerCost > 0) {
            $entries[] = NormalizedFinancialEntry::fromType(
                accountId: $accountId,
                sourceSystem: 'ml',
                sourceType: 'shipment',
                sourceId: $shipmentId,
                entryType: FinancialEntryType::SHIPPING_COST,
                amount: $totalSellerCost,
                occurredAt: $occurredAt,
                sourceDetailId: 'seller',
                orderId: $orderId,
                shipmentId: $shipmentId,
                description: 'Frete pago pelo vendedor',
                rawData: ['senders' => $senderRaw, 'seller_cost' => $totalSellerCost],
            );
        }

        // Créditos / compensações no receiver (ex.: proteção às vezes aparece em compensations)
        $receivers = $costsPayload['receivers'] ?? [];
        if (is_array($receivers)) {
            foreach ($receivers as $idx => $receiver) {
                if (!is_array($receiver)) {
                    continue;
                }
                // compensations no costs payload (quando presentes)
                foreach ($receiver['compensations'] ?? [] as $cIdx => $comp) {
                    if (!is_array($comp)) {
                        continue;
                    }
                    $compAmount = round(abs((float)($comp['amount'] ?? $comp['cost'] ?? 0)), 2);
                    if ($compAmount <= 0) {
                        continue;
                    }
                    $entries[] = NormalizedFinancialEntry::fromType(
                        accountId: $accountId,
                        sourceSystem: 'ml',
                        sourceType: 'shipment',
                        sourceId: $shipmentId,
                        entryType: FinancialEntryType::SHIPPING_PROTECTION,
                        amount: $compAmount,
                        occurredAt: $occurredAt,
                        sourceDetailId: 'comp:' . (string)($comp['id'] ?? $cIdx),
                        orderId: $orderId,
                        shipmentId: $shipmentId,
                        description: 'Compensação / proteção logística',
                        rawData: ['compensation' => $comp],
                    );
                }
            }
        }

        return $entries;
    }

    /**
     * Reembolsos de um pagamento MP/ML.
     * Importante: o lançamento REFUND aqui registra o evento de reembolso ao comprador.
     * Quem debita o seller é decidido depois (conciliação / proteção).
     * Por padrão status=informational se covered_by_protection=true no contexto.
     *
     * @param array<string, mixed> $payment
     * @param list<array<string, mixed>> $refunds
     * @return list<NormalizedFinancialEntry>
     */
    public function fromPaymentRefunds(
        int $accountId,
        array $payment,
        array $refunds,
        ?string $orderId = null,
        bool $sellerDebited = true
    ): array {
        $paymentId = (string)($payment['id'] ?? '');
        if ($paymentId === '') {
            return [];
        }

        $orderId ??= isset($payment['order_id']) ? (string)$payment['order_id'] : null;
        if ($orderId === null && isset($payment['order']['id'])) {
            $orderId = (string)$payment['order']['id'];
        }

        $currency = (string)($payment['currency_id'] ?? 'BRL');
        $entries = [];

        foreach ($refunds as $refund) {
            if (!is_array($refund)) {
                continue;
            }
            $refundId = (string)($refund['id'] ?? '');
            $amount = round(abs((float)($refund['amount'] ?? 0)), 2);
            if ($amount <= 0) {
                continue;
            }
            $occurredAt = $this->parseDate($refund['date_created'] ?? $refund['date_approved'] ?? null)
                ?? new DateTimeImmutable('now');
            $refundStatus = (string)($refund['status'] ?? 'approved');

            // Se o seller NÃO foi debitado (ex.: proteção de envio), marca informational
            // e NÃO entra no marketplace_net via status cancelled/rejected — usamos 'covered'.
            $status = $sellerDebited ? $this->mapRefundStatus($refundStatus) : 'covered';

            $entries[] = NormalizedFinancialEntry::fromType(
                accountId: $accountId,
                sourceSystem: 'mp',
                sourceType: 'refund',
                sourceId: $paymentId,
                entryType: FinancialEntryType::REFUND,
                amount: $amount,
                occurredAt: $occurredAt,
                sourceDetailId: $refundId !== '' ? $refundId : hash('crc32b', (string)json_encode($refund)),
                orderId: $orderId,
                paymentId: $paymentId,
                refundId: $refundId !== '' ? $refundId : null,
                status: $status,
                description: $sellerDebited
                    ? 'Reembolso debitado do vendedor'
                    : 'Reembolso ao comprador coberto (sem débito seller)',
                currency: $currency,
                rawData: $refund,
                directionOverride: 'debit',
            );
        }

        return $entries;
    }

    /**
     * Chargeback (contestação) de um pagamento — comprador contesta junto à
     * operadora do cartão e o valor é revertido, mesmo que o seller já tenha
     * recebido/tenha o pagamento como aprovado.
     *
     * Fonte: GET /collections/{paymentId} (MP), campo status === 'charged_back'.
     * Usa o MESMO payload já buscado para refunds — não fabrica um novo request.
     * Valor = transaction_amount (montante bruto revertido pela operadora).
     *
     * @param array<string, mixed> $collection Payload de /collections/{paymentId}
     */
    public function fromChargeback(
        int $accountId,
        string $orderId,
        string $paymentId,
        array $collection,
        DateTimeImmutable $occurredAt,
    ): ?NormalizedFinancialEntry {
        if ($paymentId === '') {
            return null;
        }

        $status = (string)($collection['status'] ?? '');
        if ($status !== 'charged_back') {
            // Só registra quando a origem confirma explicitamente o chargeback.
            return null;
        }

        $amount = round(abs((float)($collection['transaction_amount'] ?? 0)), 2);
        if ($amount <= 0) {
            return null;
        }

        $currency = (string)($collection['currency_id'] ?? 'BRL');

        return NormalizedFinancialEntry::fromType(
            accountId: $accountId,
            sourceSystem: 'mp',
            sourceType: 'chargeback',
            sourceId: $paymentId,
            entryType: FinancialEntryType::CHARGEBACK,
            amount: $amount,
            occurredAt: $occurredAt,
            sourceDetailId: '',
            orderId: $orderId !== '' ? $orderId : null,
            paymentId: $paymentId,
            status: 'posted',
            description: 'Chargeback — valor revertido ao comprador pela operadora do cartão',
            currency: $currency,
            rawData: $collection,
        );
    }

    /**
     * Cobrança/bônus do relatório de billing (FeeCommissionService::getBillingDetails())
     * que NÃO está vinculada a nenhum pedido (order_id null) — ex.: Product Ads.
     *
     * Restrição deliberada: só normaliza detail_sub_type explicitamente mapeado
     * abaixo. Os demais detail_sub_type de billing com order_id (CVVML/CVVPRC/
     * CVVFN/CVVFNU/CFONPN) já estão contidos em sale_fee/marketplace_fee do pedido
     * (fromOrder()) — confirmado por auditoria: CVVML+CVVPRC(+CVVFN) == sale_fee.gross.
     * Normalizar esses aqui duplicaria o mesmo débito.
     *
     * @param array<string, mixed> $item Item já parseado por FeeCommissionService::getBillingDetails()
     */
    public function fromBillingCharge(int $accountId, array $item): ?NormalizedFinancialEntry
    {
        if (($item['order_id'] ?? null) !== null) {
            // Linha vinculada a pedido: fora de escopo deste método (evita duplicar sale_fee).
            return null;
        }

        $detailId = (string)($item['detail_id'] ?? '');
        if ($detailId === '') {
            return null;
        }

        $subType = (string)($item['detail_sub_type'] ?? '');
        $entryType = match ($subType) {
            'PADS' => FinancialEntryType::ADVERTISING_FEE,
            'BPAD' => FinancialEntryType::ADVERTISING_FEE_REVERSAL,
            'CSTP' => FinancialEntryType::PROGRAM_HOLD,
            default => null,
        };
        if ($entryType === null) {
            // detail_sub_type ainda não mapeado — não fabricar categoria/direção.
            return null;
        }

        $amount = round(abs((float)($item['detail_amount'] ?? 0)), 2);
        if ($amount <= 0) {
            return null;
        }

        $occurredAt = $this->parseDate($item['creation_date_time'] ?? null) ?? new DateTimeImmutable('now');

        return NormalizedFinancialEntry::fromType(
            accountId: $accountId,
            sourceSystem: 'ml',
            sourceType: 'billing',
            sourceId: $detailId,
            entryType: $entryType,
            amount: $amount,
            occurredAt: $occurredAt,
            sourceDetailId: '',
            status: 'posted',
            description: (string)($item['transaction_detail'] ?? 'Cobrança de billing'),
            rawData: $item,
        );
    }

    /**
     * Saque (withdrawal) da carteira Mercado Pago para conta bancária.
     * Evento de caixa — NÃO é despesa operacional (categoria WITHDRAWAL).
     *
     * @param array<string, mixed> $movement
     */
    public function fromWithdrawal(
        int $accountId,
        array $movement,
        DateTimeImmutable $occurredAt,
    ): ?NormalizedFinancialEntry {
        $sourceId = (string)($movement['id'] ?? $movement['movement_id'] ?? '');
        if ($sourceId === '') {
            return null;
        }

        $amount = round(abs((float)($movement['amount'] ?? 0)), 2);
        if ($amount <= 0) {
            return null;
        }

        $rawStatus = strtolower(trim((string)($movement['status'] ?? 'approved')));
        $typeHint = strtolower(trim((string)($movement['type'] ?? $movement['operation_type'] ?? 'withdrawal')));

        $entryType = str_contains($typeHint, 'reversal')
            ? FinancialEntryType::WITHDRAWAL_REVERSAL
            : FinancialEntryType::WITHDRAWAL;

        $status = match ($rawStatus) {
            'approved', 'completed', 'done', 'released', 'available' => 'posted',
            'pending', 'in_process', 'processing' => 'pending',
            'cancelled', 'canceled', 'rejected' => 'cancelled',
            default => $rawStatus !== '' ? $rawStatus : 'posted',
        };

        $availableAt = null;
        if ($status === 'posted') {
            $availableAt = $this->parseDate($movement['date_created'] ?? $movement['date'] ?? null) ?? $occurredAt;
        }

        $currency = (string)($movement['currency_id'] ?? $movement['currency'] ?? 'BRL');

        return NormalizedFinancialEntry::fromType(
            accountId: $accountId,
            sourceSystem: 'mp',
            sourceType: 'withdrawal',
            sourceId: $sourceId,
            entryType: $entryType,
            amount: $amount,
            occurredAt: $occurredAt,
            sourceDetailId: '',
            status: $status,
            description: $entryType === FinancialEntryType::WITHDRAWAL_REVERSAL
                ? 'Reversão de saque (crédito de volta à carteira MP)'
                : 'Saque da carteira MP para conta bancária',
            currency: $currency,
            rawData: $movement,
            availableAt: $availableAt,
        );
    }

    /**
     * Crédito explícito de proteção de envio / ressarcimento ao vendedor.
     *
     * @param array<string, mixed> $raw
     */
    public function fromShippingProtection(
        int $accountId,
        string $sourceId,
        float $amount,
        DateTimeImmutable $occurredAt,
        ?string $orderId = null,
        ?string $shipmentId = null,
        ?string $claimId = null,
        string $sourceDetailId = '',
        array $raw = []
    ): ?NormalizedFinancialEntry {
        $amount = round(abs($amount), 2);
        if ($amount <= 0 || $sourceId === '') {
            return null;
        }

        return NormalizedFinancialEntry::fromType(
            accountId: $accountId,
            sourceSystem: 'ml',
            sourceType: 'protection',
            sourceId: $sourceId,
            entryType: FinancialEntryType::SHIPPING_PROTECTION,
            amount: $amount,
            occurredAt: $occurredAt,
            sourceDetailId: $sourceDetailId,
            orderId: $orderId,
            shipmentId: $shipmentId,
            claimId: $claimId,
            description: 'Proteção de envio / ressarcimento ao vendedor',
            rawData: $raw,
        );
    }

    /**
     * Liberação (release/settlement) de um pagamento junto ao Mercado Pago.
     *
     * Fontes reais combinadas pelo chamador (Ingestion), este método NÃO chama API:
     *  - net_received_amount e money_release_date: GET /collections/{paymentId}  (MP)
     *  - money_release_status: GET /billing/integration/group/ML/order/details   (ML)
     *
     * Regra: nunca fabricar released_at. Só é marcado como liberado (status=posted,
     * released_at preenchido) quando money_release_status === 'released'. Caso
     * contrário (pending/desconhecido), fica status=pending e released_at=null;
     * money_release_date é preservado em available_at como previsão da origem.
     *
     * @param array{
     *   net_received_amount?: float|int|string|null,
     *   money_release_date?: string|null,
     *   money_release_status?: string|null,
     *   currency_id?: string|null,
     * } $payload
     */
    public function fromRelease(
        int $accountId,
        string $orderId,
        string $paymentId,
        array $payload,
        DateTimeImmutable $occurredAt,
    ): ?NormalizedFinancialEntry {
        if ($paymentId === '' || $orderId === '') {
            return null;
        }

        $amount = round(abs((float)($payload['net_received_amount'] ?? 0)), 2);
        if ($amount <= 0) {
            // Sem valor líquido informado pela origem — não fabricar entrada.
            return null;
        }

        $releaseStatus = strtolower(trim((string)($payload['money_release_status'] ?? '')));
        $releaseDate = $this->parseDate($payload['money_release_date'] ?? null);
        $releasedAt = null;
        if ($releaseStatus === 'released') {
            $status = 'posted';
            $releasedAt = $releaseDate;
        } elseif ($releaseStatus === 'pending' || $releaseStatus === '') {
            $status = 'pending';
        } else {
            // Status desconhecido/novo da API: registra rastreável sem assumir liberação.
            $status = 'pending';
        }

        $currency = (string)($payload['currency_id'] ?? 'BRL');

        return NormalizedFinancialEntry::fromType(
            accountId: $accountId,
            sourceSystem: 'mp',
            sourceType: 'settlement',
            sourceId: $paymentId,
            entryType: FinancialEntryType::SETTLEMENT_RELEASE,
            amount: $amount,
            occurredAt: $occurredAt,
            sourceDetailId: '',
            orderId: $orderId,
            paymentId: $paymentId,
            status: $status,
            description: $status === 'posted'
                ? 'Liberação do pagamento (MP) — dinheiro disponível'
                : 'Liberação do pagamento (MP) — pendente de liberação',
            currency: $currency,
            rawData: $payload,
            releasedAt: $releasedAt,
            availableAt: $releaseDate,
        );
    }

    /**
     * Status contábil dos lançamentos derivados do pedido.
     * Pedido cancelled com pagamento aprovado (bpp) → posted (seller reteve).
     *
     * @param array<string, mixed> $order
     */
    private function resolveOrderEntryStatus(array $order): string
    {
        $orderStatus = (string)($order['status'] ?? 'unknown');
        $hasApprovedPayment = false;
        foreach ($order['payments'] ?? [] as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $payStatus = (string)($payment['status'] ?? '');
            // refunded/bpp ainda implica que o pagamento chegou a ser aprovado
            if (in_array($payStatus, ['approved', 'refunded', 'charged_back'], true)) {
                $hasApprovedPayment = true;
                break;
            }
        }

        if ($hasApprovedPayment) {
            return 'posted';
        }

        return match ($orderStatus) {
            'paid', 'confirmed', 'ready_to_ship', 'shipped', 'delivered', 'handling' => 'posted',
            'cancelled' => 'cancelled',
            'payment_required', 'payment_in_process' => 'pending',
            default => $orderStatus !== '' ? $orderStatus : 'posted',
        };
    }

    private function mapRefundStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'posted',
            'rejected', 'cancelled' => 'cancelled',
            default => $status !== '' ? $status : 'posted',
        };
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
