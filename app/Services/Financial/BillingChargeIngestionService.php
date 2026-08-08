<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Database;
use App\Helpers\Log;
use PDO;
use Throwable;

/**
 * Ingestão de cobranças de billing (fatura ML) não vinculadas a pedido — PATCH 4b.
 *
 * Fonte real: GET /billing/integration/periods/key/{period}/group/ML/details
 * (já encapsulado em FeeCommissionService::getBillingDetails()).
 *
 * Escopo deliberadamente restrito a linhas com order_id = null (ex.: Product Ads
 * PADS/BPAD e garantia CSTP). Linhas com order_id (CVVML/CVVPRC/CVVFN/CVVFNU/
 * CFONPN/CXDE/CVAF, etc.) já estão contidas em sale_fee/marketplace_fee do pedido
 * — auditoria confirmou CVVML+CVVPRC(+CVVFN) == sale_fee.gross — normalizá-las
 * aqui duplicaria débito já registrado por FinancialIngestionService::fromOrder().
 */
final class BillingChargeIngestionService
{
    private PDO $db;
    private FinancialEventNormalizer $normalizer;
    private FinancialLedgerService $ledger;
    private ?FeeCommissionService $feeService = null;

    public function __construct(
        private readonly int $accountId,
        ?PDO $db = null,
        ?FinancialEventNormalizer $normalizer = null,
        ?FinancialLedgerService $ledger = null,
        ?FeeCommissionService $feeService = null,
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->normalizer = $normalizer ?? new FinancialEventNormalizer();
        $this->ledger = $ledger ?? new FinancialLedgerService($this->db);
        $this->feeService = $feeService;
    }

    /**
     * Backfill de um único período de billing (mês). $periodKey no formato YYYY-MM-01.
     *
     * @param array{dry_run?: bool} $options
     * @return array<string, mixed>
     */
    public function backfillPeriod(string $periodKey, array $options = []): array
    {
        $dryRun = (bool)($options['dry_run'] ?? false);

        $stats = [
            'account_id' => $this->accountId,
            'period' => $periodKey,
            'lines_scanned' => 0,
            'lines_without_order' => 0,
            'lines_mapped' => 0,
            'lines_unmapped_sub_types' => [],
            'entries_created' => 0,
            'entries_updated' => 0,
            'entries_unchanged' => 0,
            'errors' => [],
            'dry_run' => $dryRun,
        ];

        $entries = [];
        $fromId = 0;
        $unmapped = [];

        try {
            do {
                $page = $this->getFeeService()->getBillingDetails($periodKey, 'BILL', 1000, $fromId);
                if (isset($page['error'])) {
                    $stats['errors'][] = ['period' => $periodKey, 'error' => (string)$page['error']];
                    break;
                }
                $results = $page['results'] ?? [];
                $stats['lines_scanned'] += count($results);

                foreach ($results as $item) {
                    if (($item['order_id'] ?? null) !== null) {
                        continue;
                    }
                    $stats['lines_without_order']++;

                    $entry = $this->normalizer->fromBillingCharge($this->accountId, $item);
                    if ($entry === null) {
                        $subType = (string)($item['detail_sub_type'] ?? 'unknown');
                        $unmapped[$subType] = ($unmapped[$subType] ?? 0) + 1;
                        continue;
                    }
                    $stats['lines_mapped']++;
                    $entries[] = $entry;
                }

                $lastId = $page['last_id'] ?? null;
                $fromId = ($lastId !== null && count($results) >= 1000) ? (int)$lastId : 0;
            } while ($fromId > 0);
        } catch (Throwable $e) {
            $stats['errors'][] = ['period' => $periodKey, 'error' => $e->getMessage()];
            Log::warning('BillingChargeIngestionService: falha ao paginar billing', [
                'account_id' => $this->accountId,
                'period' => $periodKey,
                'error' => $e->getMessage(),
            ]);
        }

        $stats['lines_unmapped_sub_types'] = $unmapped;

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
     * Backfill de vários períodos consecutivos (inclusive).
     *
     * @param array{dry_run?: bool} $options
     * @return array<string, mixed>
     */
    public function backfillPeriodRange(string $fromPeriodKey, string $toPeriodKey, array $options = []): array
    {
        $periods = $this->enumerateMonths($fromPeriodKey, $toPeriodKey);
        $combined = [
            'account_id' => $this->accountId,
            'from' => $fromPeriodKey,
            'to' => $toPeriodKey,
            'periods' => [],
            'lines_scanned' => 0,
            'lines_without_order' => 0,
            'lines_mapped' => 0,
            'entries_created' => 0,
            'entries_updated' => 0,
            'entries_unchanged' => 0,
            'errors' => [],
        ];

        foreach ($periods as $periodKey) {
            $result = $this->backfillPeriod($periodKey, $options);
            $combined['periods'][] = $result;
            $combined['lines_scanned'] += $result['lines_scanned'];
            $combined['lines_without_order'] += $result['lines_without_order'];
            $combined['lines_mapped'] += $result['lines_mapped'];
            $combined['entries_created'] += $result['entries_created'];
            $combined['entries_updated'] += $result['entries_updated'];
            $combined['entries_unchanged'] += $result['entries_unchanged'];
            foreach ($result['errors'] as $e) {
                $combined['errors'][] = $e;
            }
        }

        return $combined;
    }

    /**
     * @return list<string> Chaves YYYY-MM-01 entre from e to (inclusive).
     */
    private function enumerateMonths(string $fromPeriodKey, string $toPeriodKey): array
    {
        $from = new \DateTimeImmutable(substr($fromPeriodKey, 0, 7) . '-01');
        $to = new \DateTimeImmutable(substr($toPeriodKey, 0, 7) . '-01');
        $months = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $months[] = $cursor->format('Y-m-01');
            $cursor = $cursor->modify('+1 month');
        }
        return $months;
    }

    private function getFeeService(): FeeCommissionService
    {
        return $this->feeService ??= new FeeCommissionService($this->accountId);
    }

    public static function resolveAccountId(string $accountArg, PDO $db): int
    {
        return FinancialIngestionService::resolveAccountId($accountArg, $db);
    }
}
