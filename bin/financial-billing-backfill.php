#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backfill de cobranças de billing sem pedido (Product Ads etc.) — PATCH 4b.
 *
 * Uso:
 *   php bin/financial-billing-backfill.php --account-id=1335 --period=2026-07-01 --dry-run
 *   php bin/financial-billing-backfill.php --account-id=1335 --from-period=2026-06-01 --to-period=2026-08-01
 *   php bin/financial-billing-backfill.php --account=FACILYTY --period=2026-07-01 --json
 *
 * Flags:
 *   --period=YYYY-MM-01        (um único período)
 *   --from-period / --to-period (intervalo de meses, inclusive)
 *   --dry-run
 *   --json
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\Financial\BillingChargeIngestionService;

$opts = getopt('', [
    'account:',
    'account-id:',
    'period:',
    'from-period:',
    'to-period:',
    'dry-run',
    'json',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__) ?: "financial-billing-backfill\n");
    exit(0);
}

$dryRun = isset($opts['dry-run']);
$asJson = isset($opts['json']);

$period = isset($opts['period']) ? (string)$opts['period'] : null;
$fromPeriod = isset($opts['from-period']) ? (string)$opts['from-period'] : $period;
$toPeriod = isset($opts['to-period']) ? (string)$opts['to-period'] : $period;

if ($fromPeriod === null || $toPeriod === null) {
    fwrite(STDERR, "ERRO: informe --period=YYYY-MM-01 ou --from-period/--to-period\n");
    exit(1);
}

try {
    $pdo = Database::getInstance();
    $accountArg = (string)($opts['account-id'] ?? $opts['account'] ?? '1335');
    $accountId = BillingChargeIngestionService::resolveAccountId($accountArg, $pdo);

    $svc = new BillingChargeIngestionService($accountId, $pdo);
    $report = $svc->backfillPeriodRange($fromPeriod, $toPeriod, ['dry_run' => $dryRun]);

    $reportDir = dirname(__DIR__) . '/storage/reports';
    if (!is_dir($reportDir)) {
        @mkdir($reportDir, 0775, true);
    }
    $reportFile = sprintf(
        '%s/financial-billing-backfill-%s-%s-%s.json',
        $reportDir,
        $accountId,
        $fromPeriod,
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
            "account=%d period=%s..%s lines_scanned=%d without_order=%d mapped=%d created=%d updated=%d unchanged=%d errors=%d\nreport=%s\n",
            $accountId,
            $fromPeriod,
            $toPeriod,
            (int)$report['lines_scanned'],
            (int)$report['lines_without_order'],
            (int)$report['lines_mapped'],
            (int)$report['entries_created'],
            (int)$report['entries_updated'],
            (int)$report['entries_unchanged'],
            count($report['errors']),
            $reportFile
        ));
        foreach ($report['periods'] ?? [] as $p) {
            $unmapped = $p['lines_unmapped_sub_types'] ?? [];
            if ($unmapped !== []) {
                fwrite(STDOUT, sprintf("  [%s] sub_types não mapeados: %s\n", $p['period'], json_encode($unmapped, JSON_UNESCAPED_UNICODE)));
            }
        }
        if (($report['errors'] ?? []) !== []) {
            fwrite(STDOUT, "\nErros (amostra):\n");
            foreach (array_slice($report['errors'], 0, 15) as $e) {
                fwrite(STDOUT, sprintf("  period=%s %s\n", $e['period'] ?? '?', $e['error'] ?? ''));
            }
        }
    }

    exit(empty($report['errors']) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
