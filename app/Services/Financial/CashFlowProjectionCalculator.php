<?php

declare(strict_types=1);

namespace App\Services\Financial;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Monta a grade temporal do Caixa MP a partir de dados já normalizados.
 *
 * Não chama APIs e não inventa saídas futuras. Valores desconhecidos são null;
 * o saldo futuro representa somente os compromissos conhecidos.
 */
final class CashFlowProjectionCalculator
{
    private const TIMEZONE = 'America/Sao_Paulo';
    private const MAX_DETAILS_PER_CELL = 200;

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<array<string, mixed>> $billingPeriods
     * @param array<string, mixed> $balance
     * @param list<string> $sourceWarnings
     * @return array<string, mixed>
     */
    public function build(
        int $accountId,
        string $startDate,
        string $horizonDate,
        DateTimeImmutable $asOf,
        array $balance,
        array $entries,
        array $billingPeriods = [],
        array $sourceWarnings = []
    ): array {
        if ($accountId <= 0) {
            throw new InvalidArgumentException('Conta financeira inválida.');
        }

        $timezone = new DateTimeZone(self::TIMEZONE);
        $start = $this->dateOnly($startDate, $timezone);
        $horizon = $this->dateOnly($horizonDate, $timezone);
        $asOfDate = $asOf->setTimezone($timezone)->setTime(0, 0);
        if ($start > $horizon) {
            throw new InvalidArgumentException('Data inicial não pode ser maior que o horizonte.');
        }
        if ($start > $asOfDate || $horizon < $asOfDate) {
            throw new InvalidArgumentException('O intervalo do fluxo de caixa deve incluir a data de hoje.');
        }
        if ($start->diff($horizon)->days > 731) {
            throw new InvalidArgumentException('O horizonte máximo do fluxo de caixa é de 24 meses.');
        }

        $currency = strtoupper((string)($balance['currency_id'] ?? 'BRL'));
        $buckets = $this->createBuckets($start, $horizon, $asOfDate);
        $warnings = array_values(array_filter(array_map('strval', $sourceWarnings)));
        $entries = $this->deduplicateEntries($entries);
        $unplacedPendingCents = 0;
        $latestImportedAt = null;
        $latestBillingAt = null;

        foreach ($entries as $entry) {
            $entry['account_id'] = $entry['account_id'] ?? $accountId;
            $entryCurrency = strtoupper((string)($entry['currency'] ?? $currency));
            if ($entryCurrency !== $currency) {
                $warnings[] = sprintf(
                    'Movimento %s ignorado porque usa %s, diferente de %s.',
                    (string)($entry['source_id'] ?? 'sem id'),
                    $entryCurrency,
                    $currency
                );
                continue;
            }

            $importedAt = (string)($entry['imported_at'] ?? '');
            if ($importedAt !== '' && ($latestImportedAt === null || $importedAt > $latestImportedAt)) {
                $latestImportedAt = $importedAt;
            }

            $type = (string)($entry['entry_type'] ?? '');
            $status = strtolower((string)($entry['status'] ?? ''));
            $amountCents = $this->toCents($entry['amount'] ?? abs((float)($entry['signed_amount'] ?? 0)));
            if ($amountCents <= 0) {
                continue;
            }

            if ($type === FinancialEntryType::SETTLEMENT_RELEASE) {
                if ($status === 'posted') {
                    $date = $this->entryDate($entry, ['released_at', 'occurred_at'], $timezone);
                    $this->addEntry($buckets, $date, 'released', $amountCents, $entry, $asOfDate, false);
                    continue;
                }

                if ($status === 'pending') {
                    $date = $this->pendingReleaseDate($entry, $timezone);
                    if ($date === null || $date <= $asOfDate) {
                        $unplacedPendingCents += $amountCents;
                        continue;
                    }
                    $this->addEntry($buckets, $date, 'scheduled_release', $amountCents, $entry, $asOfDate, false);
                }
                continue;
            }

            $date = $this->entryDate($entry, ['occurred_at'], $timezone);
            if ($type === FinancialEntryType::WITHDRAWAL && $status === 'posted') {
                $this->addEntry($buckets, $date, 'payouts', $amountCents, $entry, $asOfDate, false);
                continue;
            }
            if ($type === FinancialEntryType::WITHDRAWAL_REVERSAL && $status === 'posted') {
                $this->addEntry($buckets, $date, 'payouts', -$amountCents, $entry, $asOfDate, false);
                continue;
            }
            if ($type === FinancialEntryType::PROGRAM_HOLD && $status === 'posted') {
                $this->addEntry($buckets, $date, 'blocks_net', $amountCents, $entry, $asOfDate, false);
                continue;
            }
            if ($type === FinancialEntryType::PROGRAM_HOLD_RELEASE && $status === 'posted') {
                $this->addEntry($buckets, $date, 'blocks_net', -$amountCents, $entry, $asOfDate, false);
                continue;
            }

            // Billing Ads já entra como dívida. Só um movimento explicitamente marcado
            // como efeito de caixa pode ser debitado à parte.
            $raw = $this->rawData($entry);
            $isCashEffect = ($raw['cash_effect'] ?? false) === true
                || (string)($entry['source_type'] ?? '') === 'release_report';
            if (!$isCashEffect || $status !== 'posted') {
                continue;
            }

            if (in_array($type, [FinancialEntryType::ADVERTISING_FEE, FinancialEntryType::ADVERTISING_FEE_REVERSAL], true)) {
                $direction = $type === FinancialEntryType::ADVERTISING_FEE_REVERSAL ? -1 : 1;
                $this->addEntry($buckets, $date, 'ads', $direction * $amountCents, $entry, $asOfDate, false);
                continue;
            }
            if (in_array((string)($entry['entry_category'] ?? ''), [FinancialEntryCategory::SHIPPING, FinancialEntryCategory::CLAIM], true)) {
                $signed = (float)($entry['signed_amount'] ?? -$amountCents / 100);
                $direction = $signed > 0 ? -1 : 1;
                $this->addEntry($buckets, $date, 'shipping_claims', $direction * $amountCents, $entry, $asOfDate, false);
            }
        }

        if ($unplacedPendingCents > 0) {
            $warnings[] = sprintf(
                '%s pendentes não têm uma data futura confiável e ficaram fora da projeção.',
                $this->formatCurrency($unplacedPendingCents, $currency)
            );
        }

        $seenBilling = [];
        foreach ($billingPeriods as $period) {
            $period['account_id'] = $period['account_id'] ?? $accountId;
            $billingFetchedAt = (string)($period['fetched_at'] ?? '');
            if ($billingFetchedAt !== '' && ($latestBillingAt === null || $billingFetchedAt > $latestBillingAt)) {
                $latestBillingAt = $billingFetchedAt;
            }
            $periodCurrency = strtoupper((string)($period['currency_id'] ?? $currency));
            if ($periodCurrency !== $currency) {
                $warnings[] = sprintf('Dívida de billing em %s ignorada na série %s.', $periodCurrency, $currency);
                continue;
            }

            $unpaidCents = $this->toCents($period['unpaid_amount'] ?? 0);
            if ($unpaidCents <= 0) {
                continue;
            }

            $billingKey = implode(':', [
                (string)($period['group'] ?? ''),
                (string)($period['document_id'] ?? $period['key'] ?? ''),
                (string)($period['debt_expiration_date'] ?? $period['expiration_date'] ?? ''),
            ]);
            if (isset($seenBilling[$billingKey])) {
                continue;
            }
            $seenBilling[$billingKey] = true;

            $dueDate = $this->optionalDate(
                (string)($period['debt_expiration_date'] ?? $period['expiration_date'] ?? ''),
                $timezone
            );
            if ($dueDate === null) {
                $warnings[] = sprintf(
                    'Dívida %s de %s sem vencimento confiável.',
                    (string)($period['key'] ?? 'sem período'),
                    (string)($period['group'] ?? 'billing')
                );
                continue;
            }

            // Dívida vencida e ainda aberta continua sendo compromisso futuro conhecido.
            if ($dueDate <= $asOfDate) {
                $dueDate = $asOfDate->modify('+1 day');
            }
            $this->addBilling($buckets, $dueDate, $unpaidCents, $period, $asOfDate);
        }

        $availableCents = $this->toCents($balance['available_balance'] ?? 0);
        $hasObservedBalance = !isset($balance['error']) && $start <= $asOfDate && $asOfDate <= $horizon;
        $runningCents = $availableCents;

        foreach ($buckets as $index => &$bucket) {
            $isActual = $bucket['kind'] === 'actual';
            $knownNetCents = $bucket['_cents']['released']
                + $bucket['_cents']['scheduled_release']
                - $bucket['_cents']['payouts']
                - $bucket['_cents']['blocks_net']
                - $bucket['_cents']['billing_debt']
                - $bucket['_cents']['ads']
                - $bucket['_cents']['shipping_claims'];

            if ($index === 0 && $isActual && $hasObservedBalance) {
                $openingCents = $availableCents - $knownNetCents;
                $closingCents = $availableCents;
                $runningCents = $closingCents;
                $closingKind = 'observed';
            } else {
                $openingCents = $runningCents;
                $closingCents = $openingCents + $knownNetCents;
                $runningCents = $closingCents;
                $closingKind = $isActual ? 'derived' : 'known_only';
            }

            $unknownFields = [];
            if (!$isActual) {
                foreach (['payouts', 'blocks_net', 'ads', 'shipping_claims'] as $field) {
                    if ($bucket['_details'][$field] === []) {
                        $unknownFields[] = $field;
                    }
                }
            }

            $bucket['opening_balance'] = $this->fromCents($openingCents);
            foreach (['released', 'scheduled_release', 'payouts', 'blocks_net', 'billing_debt', 'ads', 'shipping_claims'] as $field) {
                $bucket[$field] = (!$isActual && in_array($field, $unknownFields, true))
                    ? null
                    : $this->fromCents($bucket['_cents'][$field]);
            }
            $bucket['closing_balance'] = $this->fromCents($closingCents);
            $bucket['closing_balance_kind'] = $closingKind;
            $bucket['unknown_fields'] = $unknownFields;
            $bucket['is_estimate'] = !$isActual;
            $bucket['completeness'] = $unknownFields === [] ? 'complete' : 'partial';
            $bucket['details'] = $bucket['_details'];
            unset($bucket['_cents'], $bucket['_details']);
        }
        unset($bucket);

        if (!$hasObservedBalance) {
            $warnings[] = 'Não foi possível ancorar a primeira faixa no saldo disponível observado.';
        }
        if ($this->hasPartialFutureBucket($buckets)) {
            $warnings[] = 'Saldo futuro considera somente compromissos conhecidos; campos N/D não foram tratados como zero confirmado.';
        }

        $pendingCents = $this->toCents($balance['unavailable_balance'] ?? 0);
        $totalCents = array_key_exists('total_amount', $balance)
            ? $this->toCents($balance['total_amount'])
            : $availableCents + $pendingCents;

        return [
            'as_of' => $asOf->setTimezone($timezone)->format(DATE_ATOM),
            'timezone' => self::TIMEZONE,
            'currency' => $currency,
            'account_id' => $accountId,
            'store_ids' => [(string)$accountId],
            'summary' => [
                'available' => $this->fromCents($availableCents),
                'pending_release' => $this->fromCents($pendingCents),
                'advance_available' => $balance['anticipation_available'] ?? null,
                'total' => $this->fromCents($totalCents),
                'source' => $balance['source'] ?? 'unknown',
            ],
            'buckets' => $buckets,
            'warnings' => array_values(array_unique($warnings)),
            'freshness' => [
                'ledger' => $latestImportedAt,
                'wallet' => $balance['updated_at'] ?? null,
                'billing' => $latestBillingAt,
            ],
            'unplaced_pending_release' => $this->fromCents($unplacedPendingCents),
            'source' => 'financial_ledger+mp_balance+billing',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function createBuckets(DateTimeImmutable $start, DateTimeImmutable $horizon, DateTimeImmutable $asOf): array
    {
        $buckets = [];
        if ($start <= $asOf && $start <= $horizon) {
            $actualEnd = $asOf < $horizon ? $asOf : $horizon;
            $buckets[] = $this->emptyBucket(
                'through-' . $actualEnd->format('Y-m-d'),
                'Até ' . $actualEnd->format('d/m/Y'),
                $start,
                $actualEnd,
                'actual'
            );
        }

        $cursor = $start > $asOf ? $start : $asOf->modify('+1 day');
        while ($cursor <= $horizon) {
            $monthEnd = $cursor->modify('last day of this month');
            $end = $monthEnd < $horizon ? $monthEnd : $horizon;
            $isRemainder = $cursor->format('Y-m') === $asOf->format('Y-m');
            $label = $isRemainder
                ? sprintf('De %s até %s', $cursor->format('d/m'), $end->format('d/m/Y'))
                : sprintf('De %s até %s', $cursor->format('d/m'), $end->format('d/m/Y'));
            $buckets[] = $this->emptyBucket(
                $cursor->format('Y-m-d') . '_' . $end->format('Y-m-d'),
                $label,
                $cursor,
                $end,
                'forecast'
            );
            $cursor = $end->modify('+1 day');
        }

        return $buckets;
    }

    /** @return array<string, mixed> */
    private function emptyBucket(
        string $key,
        string $label,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $kind
    ): array {
        $fields = ['released', 'scheduled_release', 'payouts', 'blocks_net', 'billing_debt', 'ads', 'shipping_claims'];
        return [
            'key' => $key,
            'label' => $label,
            'date_from' => $start->format('Y-m-d'),
            'date_to' => $end->format('Y-m-d'),
            'kind' => $kind,
            '_cents' => array_fill_keys($fields, 0),
            '_details' => array_fill_keys($fields, []),
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function deduplicateEntries(array $entries): array
    {
        $deduplicated = [];
        foreach ($entries as $entry) {
            $type = (string)($entry['entry_type'] ?? '');
            if ($type === FinancialEntryType::SETTLEMENT_RELEASE) {
                $key = 'settlement:' . (string)($entry['payment_id'] ?? $entry['source_id'] ?? '');
            } else {
                $key = implode(':', [
                    (string)($entry['source_system'] ?? ''),
                    (string)($entry['source_type'] ?? ''),
                    (string)($entry['source_id'] ?? ''),
                    (string)($entry['source_detail_id'] ?? ''),
                    $type,
                ]);
            }

            if (!isset($deduplicated[$key])) {
                $deduplicated[$key] = $entry;
                continue;
            }

            $current = $deduplicated[$key];
            $currentPriority = strtolower((string)($current['status'] ?? '')) === 'posted' ? 2 : 1;
            $candidatePriority = strtolower((string)($entry['status'] ?? '')) === 'posted' ? 2 : 1;
            if ($candidatePriority > $currentPriority
                || ($candidatePriority === $currentPriority
                    && (string)($entry['updated_at'] ?? '') > (string)($current['updated_at'] ?? ''))
            ) {
                $deduplicated[$key] = $entry;
            }
        }

        return array_values($deduplicated);
    }

    /**
     * @param list<array<string, mixed>> $buckets
     * @param array<string, mixed> $entry
     */
    private function addEntry(
        array &$buckets,
        ?DateTimeImmutable $date,
        string $field,
        int $amountCents,
        array $entry,
        DateTimeImmutable $asOf,
        bool $estimated
    ): void {
        if ($date === null) {
            return;
        }
        $index = $this->bucketIndex($buckets, $date);
        if ($index === null) {
            return;
        }
        $buckets[$index]['_cents'][$field] += $amountCents;
        if (count($buckets[$index]['_details'][$field]) < self::MAX_DETAILS_PER_CELL) {
            $buckets[$index]['_details'][$field][] = [
                'source' => (string)($entry['source_system'] ?? 'ledger'),
                'source_type' => (string)($entry['source_type'] ?? ''),
                'source_record_id' => (string)($entry['source_id'] ?? $entry['id'] ?? ''),
                'account_id' => (int)($entry['account_id'] ?? 0),
                'external_reference' => $entry['order_id'] ?? $entry['payment_id'] ?? null,
                'date' => $date->format('Y-m-d'),
                'amount' => $this->fromCents($amountCents),
                'status' => (string)($entry['status'] ?? ''),
                'description' => (string)($entry['description'] ?? ''),
                'estimated' => $estimated || $date > $asOf,
                'fetched_at' => $entry['imported_at'] ?? null,
            ];
        }
    }

    /**
     * @param list<array<string, mixed>> $buckets
     * @param array<string, mixed> $period
     */
    private function addBilling(
        array &$buckets,
        DateTimeImmutable $date,
        int $amountCents,
        array $period,
        DateTimeImmutable $asOf
    ): void {
        $index = $this->bucketIndex($buckets, $date);
        if ($index === null) {
            return;
        }
        $buckets[$index]['_cents']['billing_debt'] += $amountCents;
        if (count($buckets[$index]['_details']['billing_debt']) < self::MAX_DETAILS_PER_CELL) {
            $buckets[$index]['_details']['billing_debt'][] = [
                'source' => 'ml_billing',
                'source_type' => (string)($period['group'] ?? 'billing'),
                'source_record_id' => (string)($period['document_id'] ?? $period['key'] ?? ''),
                'account_id' => (int)($period['account_id'] ?? 0),
                'external_reference' => (string)($period['key'] ?? ''),
                'date' => $date->format('Y-m-d'),
                'amount' => $this->fromCents($amountCents),
                'status' => (string)($period['period_status'] ?? 'open'),
                'description' => sprintf('Dívida de faturamento %s', (string)($period['group'] ?? 'ML/MP')),
                'estimated' => $date > $asOf,
                'fetched_at' => $period['fetched_at'] ?? null,
            ];
        }
    }

    /** @param list<array<string, mixed>> $buckets */
    private function bucketIndex(array $buckets, DateTimeImmutable $date): ?int
    {
        $day = $date->format('Y-m-d');
        foreach ($buckets as $index => $bucket) {
            if ($day >= $bucket['date_from'] && $day <= $bucket['date_to']) {
                return $index;
            }
        }
        return null;
    }

    /** @param list<string> $fields */
    private function entryDate(array $entry, array $fields, DateTimeZone $timezone): ?DateTimeImmutable
    {
        foreach ($fields as $field) {
            $date = $this->optionalDate((string)($entry[$field] ?? ''), $timezone);
            if ($date !== null) {
                return $date;
            }
        }
        return null;
    }

    private function pendingReleaseDate(array $entry, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $date = $this->entryDate($entry, ['available_at'], $timezone);
        if ($date !== null) {
            return $date;
        }
        $raw = $this->rawData($entry);
        return $this->optionalDate((string)($raw['money_release_date'] ?? ''), $timezone);
    }

    /** @return array<string, mixed> */
    private function rawData(array $entry): array
    {
        $raw = $entry['raw_data'] ?? [];
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function dateOnly(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), $timezone);
        if ($date === false || $date->format('Y-m-d') !== substr($value, 0, 10)) {
            throw new InvalidArgumentException('Data inválida: ' . $value);
        }
        return $date;
    }

    private function optionalDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone($timezone)->setTime(0, 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param float|int|string|null $value */
    private function toCents($value): int
    {
        return (int)round((float)($value ?? 0) * 100);
    }

    private function fromCents(int $value): float
    {
        return round($value / 100, 2);
    }

    private function formatCurrency(int $cents, string $currency): string
    {
        return sprintf('%s %s', $currency, number_format($this->fromCents($cents), 2, ',', '.'));
    }

    /** @param list<array<string, mixed>> $buckets */
    private function hasPartialFutureBucket(array $buckets): bool
    {
        foreach ($buckets as $bucket) {
            if (($bucket['kind'] ?? '') === 'forecast' && ($bucket['completeness'] ?? '') === 'partial') {
                return true;
            }
        }
        return false;
    }
}
