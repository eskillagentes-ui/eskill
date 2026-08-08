#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Conciliação ledger vs pedidos (Fase 3).
 *
 * Uso:
 *   php bin/financial-ledger-reconcile.php --account=FACILYTY --from=2026-07-06 --to=2026-08-05
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\Financial\FinancialIngestionService;
use App\Services\Financial\FinancialReconciliationService;

$opts = getopt('', ['account:', 'account-id:', 'from:', 'to:', 'limit:', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "php bin/financial-ledger-reconcile.php --account=FACILYTY --from=YYYY-MM-DD --to=YYYY-MM-DD\n");
    exit(0);
}

$from = (string)($opts['from'] ?? date('Y-m-d', strtotime('-30 days')));
$to = (string)($opts['to'] ?? date('Y-m-d'));
$limit = (int)($opts['limit'] ?? 0);

try {
    $pdo = Database::getInstance();
    $accountArg = (string)($opts['account-id'] ?? $opts['account'] ?? '1335');
    $accountId = FinancialIngestionService::resolveAccountId($accountArg, $pdo);
    $svc = new FinancialReconciliationService($accountId, $pdo);
    $report = $svc->reconcilePeriod($from, $to, $limit);

    if (isset($opts['json'])) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDOUT, sprintf(
            "account=%d checked=%d upserted=%d info=%d warning=%d critical=%d\n",
            $accountId,
            (int)$report['orders_checked'],
            (int)$report['discrepancies_upserted'],
            (int)$report['open_info'],
            (int)$report['open_warning'],
            (int)$report['open_critical']
        ));
        foreach ($report['by_type'] as $type => $count) {
            fwrite(STDOUT, "  {$type}: {$count}\n");
        }
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
