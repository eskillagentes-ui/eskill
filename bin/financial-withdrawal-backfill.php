#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backfill / conciliação de saques (withdrawals) — PATCH 3.
 *
 * Uso:
 *   php bin/financial-withdrawal-backfill.php --account-id=1335 --from=2026-07-01 --to=2026-08-05 --dry-run
 *   php bin/financial-withdrawal-backfill.php --account-id=1335 --from=2026-07-01 --to=2026-08-05 --json
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\Financial\FinancialIngestionService;
use App\Services\Financial\WithdrawalIngestionService;

$opts = getopt('', ['account:', 'account-id:', 'from:', 'to:', 'limit:', 'dry-run', 'json', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__) ?: "financial-withdrawal-backfill\n");
    exit(0);
}

$from = (string)($opts['from'] ?? date('Y-m-d', strtotime('-30 days')));
$to = (string)($opts['to'] ?? date('Y-m-d'));
$limit = (int)($opts['limit'] ?? 100);
$dryRun = isset($opts['dry-run']);
$asJson = isset($opts['json']);

try {
    $pdo = Database::getInstance();
    $accountArg = (string)($opts['account-id'] ?? $opts['account'] ?? '1335');
    $accountId = FinancialIngestionService::resolveAccountId($accountArg, $pdo);

    $svc = new WithdrawalIngestionService($accountId, $pdo);
    $report = $svc->backfillWithdrawals($from, $to, [
        'dry_run' => $dryRun,
        'limit' => $limit,
    ]);

    $reportDir = dirname(__DIR__) . '/storage/reports';
    if (!is_dir($reportDir)) {
        @mkdir($reportDir, 0775, true);
    }
    $reportFile = sprintf(
        '%s/financial-withdrawal-backfill-%s-%s-%s.json',
        $reportDir,
        $accountId,
        $from,
        date('YmdHis')
    );
    file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $report['report_file'] = $reportFile;

    if ($asJson) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        $cash = $report['cash_reconciliation'] ?? [];
        fwrite(STDOUT, sprintf(
            "account=%d period=%s..%s api_blocked=%s scanned=%d created=%d updated=%d unchanged=%d released=%.2f withdrawn=%.2f pending=%.2f hold=%.2f errors=%d\nreport=%s\n",
            $accountId,
            $from,
            $to,
            !empty($report['api_blocked']) ? 'yes' : 'no',
            (int)$report['movements_scanned'],
            (int)$report['entries_created'],
            (int)$report['entries_updated'],
            (int)$report['entries_unchanged'],
            (float)($cash['released_amount'] ?? 0),
            (float)($cash['withdrawn_amount'] ?? 0),
            (float)($cash['pending_release_amount'] ?? 0),
            (float)($cash['hold_amount'] ?? 0),
            count($report['errors']),
            $reportFile
        ));
        if (!empty($report['api_block_reason'])) {
            fwrite(STDOUT, "bloqueio: " . $report['api_block_reason'] . "\n");
        }
    }

    // api_blocked sem erros = exit 0 (estado conhecido, não falha de código)
    exit(empty($report['errors']) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
