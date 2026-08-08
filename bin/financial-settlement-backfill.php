#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backfill de liberações (settlement/release) do Mercado Pago (Fase A / PATCH 2).
 *
 * Uso:
 *   php bin/financial-settlement-backfill.php --account-id=1335 --from=2026-07-06 --to=2026-08-05 --dry-run
 *   php bin/financial-settlement-backfill.php --account-id=1335 --from=2026-07-06 --to=2026-08-05 --json
 *   php bin/financial-settlement-backfill.php --account=FACILYTY --from=2026-07-06 --to=2026-08-05 --limit=50
 *
 * Flags:
 *   --limit=N
 *   --dry-run
 *   --json
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\Financial\FinancialIngestionService;
use App\Services\Financial\SettlementIngestionService;

$opts = getopt('', [
    'account:',
    'account-id:',
    'from:',
    'to:',
    'limit:',
    'dry-run',
    'json',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__) ?: "financial-settlement-backfill\n");
    exit(0);
}

$from = (string)($opts['from'] ?? date('Y-m-d', strtotime('-30 days')));
$to = (string)($opts['to'] ?? date('Y-m-d'));
$limit = (int)($opts['limit'] ?? 0);
$dryRun = isset($opts['dry-run']);
$asJson = isset($opts['json']);

try {
    $pdo = Database::getInstance();
    $accountArg = (string)($opts['account-id'] ?? $opts['account'] ?? '1335');
    $accountId = FinancialIngestionService::resolveAccountId($accountArg, $pdo);

    $svc = new SettlementIngestionService($accountId, $pdo);
    $report = $svc->backfillReleases($from, $to, [
        'dry_run' => $dryRun,
        'limit' => $limit,
        'sleep_us' => 80000,
    ]);

    $reportDir = dirname(__DIR__) . '/storage/reports';
    if (!is_dir($reportDir)) {
        @mkdir($reportDir, 0775, true);
    }
    $reportFile = sprintf(
        '%s/financial-settlement-backfill-%s-%s-%s.json',
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

    if ($asJson) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDOUT, sprintf(
            "account=%d period=%s..%s orders=%d with_payment=%d created=%d updated=%d unchanged=%d released=%d pending=%d skipped_no_amount=%d skipped_no_payment=%d errors=%d\nreport=%s\n",
            $accountId,
            $from,
            $to,
            (int)$report['orders_scanned'],
            (int)$report['orders_with_payment'],
            (int)$report['entries_created'],
            (int)$report['entries_updated'],
            (int)$report['entries_unchanged'],
            (int)$report['released_count'],
            (int)$report['pending_count'],
            (int)$report['skipped_no_amount'],
            (int)$report['skipped_no_payment'],
            count($report['errors']),
            $reportFile
        ));
        if (($report['errors'] ?? []) !== []) {
            fwrite(STDOUT, "\nErros (amostra):\n");
            foreach (array_slice($report['errors'], 0, 15) as $e) {
                fwrite(STDOUT, sprintf("  order=%s payment=%s %s\n", $e['order_id'] ?? '?', $e['payment_id'] ?? '?', $e['error'] ?? ''));
            }
        }
    }

    exit(empty($report['errors']) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
