#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backfill do livro financeiro (Fase 2).
 *
 * Uso:
 *   php bin/financial-ledger-backfill.php --account=FACILYTY --from=2026-07-01 --to=2026-08-05
 *   php bin/financial-ledger-backfill.php --account-id=1335 --from=2026-07-06 --to=2026-08-05 --json
 *   php bin/financial-ledger-backfill.php --account-id=1335 --from=2026-07-06 --to=2026-08-05 --dry-run
 *   php bin/financial-ledger-backfill.php --account-id=1335 --from=2026-07-06 --to=2026-08-05 --no-fetch-refunds
 *
 * Flags:
 *   --fetch-shipping / --no-fetch-shipping  (default: fetch se shipping_cost DB = 0)
 *   --fetch-refunds / --no-fetch-refunds    (default: on para cancelled/refunded)
 *   --limit=N
 *   --dry-run
 *   --json
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\Financial\FinancialIngestionService;

$opts = getopt('', [
    'account:',
    'account-id:',
    'from:',
    'to:',
    'limit:',
    'dry-run',
    'json',
    'fetch-shipping',
    'no-fetch-shipping',
    'fetch-refunds',
    'no-fetch-refunds',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__) ?: "financial-ledger-backfill\n");
    exit(0);
}

$from = (string)($opts['from'] ?? date('Y-m-d', strtotime('-30 days')));
$to = (string)($opts['to'] ?? date('Y-m-d'));
$limit = (int)($opts['limit'] ?? 0);
$dryRun = isset($opts['dry-run']);
$asJson = isset($opts['json']);
$fetchShipping = !isset($opts['no-fetch-shipping']);
$fetchRefunds = !isset($opts['no-fetch-refunds']);

try {
    $pdo = Database::getInstance();
    $accountArg = (string)($opts['account-id'] ?? $opts['account'] ?? '1335');
    $accountId = FinancialIngestionService::resolveAccountId($accountArg, $pdo);

    $svc = new FinancialIngestionService($accountId, $pdo);
    $report = $svc->backfillOrders($from, $to, [
        'fetch_shipping' => $fetchShipping,
        'fetch_refunds' => $fetchRefunds,
        'dry_run' => $dryRun,
        'limit' => $limit,
        'sleep_us' => 60000,
    ]);

    $reportDir = dirname(__DIR__) . '/storage/reports';
    if (!is_dir($reportDir)) {
        @mkdir($reportDir, 0775, true);
    }
    $reportFile = sprintf(
        '%s/financial-ledger-backfill-%s-%s-%s.json',
        $reportDir,
        $accountId,
        $from,
        date('YmdHis')
    );
    file_put_contents(
        $reportFile,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    $report['report_file'] = $reportFile;

    // Fase 3: conciliação automática pós-backfill
    try {
        $recon = new App\Services\Financial\FinancialReconciliationService($accountId, $pdo);
        $reconStats = $recon->reconcilePeriod($from, $to, $limit);
        $report['reconciliation'] = $reconStats;
    } catch (Throwable $e) {
        $report['reconciliation'] = ['error' => $e->getMessage()];
    }

    if ($asJson) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDOUT, sprintf(
            "account=%d period=%s..%s orders=%d created=%d updated=%d unchanged=%d shipping_db=%d shipping_api=%d refunds=%d covered=%d chargebacks=%d discrepancies=%d recon_upserted=%d errors=%d\nreport=%s\n",
            $accountId,
            $from,
            $to,
            (int)$report['orders_scanned'],
            (int)$report['entries_created'],
            (int)$report['entries_updated'],
            (int)$report['entries_unchanged'],
            (int)$report['shipping_from_db'],
            (int)$report['shipping_from_api'],
            (int)$report['refunds_ingested'],
            (int)$report['refunds_covered'],
            (int)($report['chargebacks_ingested'] ?? 0),
            (int)$report['discrepancy_count'],
            (int)($report['reconciliation']['discrepancies_upserted'] ?? 0),
            count($report['errors']),
            $reportFile
        ));
        if (($report['discrepancies'] ?? []) !== []) {
            fwrite(STDOUT, "\nDivergências ingestão (amostra):\n");
            foreach (array_slice($report['discrepancies'], 0, 15) as $d) {
                fwrite(STDOUT, sprintf(
                    "  [%s/%s] order=%s %s\n",
                    $d['severity'] ?? '?',
                    $d['type'] ?? '?',
                    $d['order_id'] ?? '?',
                    $d['explanation'] ?? ''
                ));
            }
        }
    }

    exit(empty($report['errors']) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
