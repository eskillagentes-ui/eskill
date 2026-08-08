#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Worker de ingestão contínua do livro financeiro (PATCH 5).
 *
 * Executa, por conta ativa:
 *  1) backfill de pedidos/refunds/chargebacks (últimos N dias)
 *  2) backfill de liberações (settlement/release)
 *  3) backfill de billing sem pedido (mês corrente)
 *  4) tentativa de withdrawals (graceful se API bloqueada)
 *  5) conciliação de divergências
 *
 * Uso:
 *   php bin/financial-ledger-tick.php --once --account=1335
 *   php bin/financial-ledger-tick.php --once --days=7
 *   php bin/financial-ledger-tick.php --once --json
 *
 * Cron sugerido (a cada hora):
 *   15 * * * * cd /path && php bin/financial-ledger-tick.php --once >> storage/logs/financial-ledger-tick.log 2>&1
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\Financial\BillingChargeIngestionService;
use App\Services\Financial\FinancialIngestionService;
use App\Services\Financial\FinancialReconciliationService;
use App\Services\Financial\SettlementIngestionService;
use App\Services\Financial\WithdrawalIngestionService;

define('WORKER_NAME', 'financial-ledger-tick');
define('LOCK_FILE', dirname(__DIR__) . '/storage/locks/financial-ledger-tick.lock');

$opts = getopt('', ['once', 'account:', 'days:', 'json', 'dry-run', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__) ?: "financial-ledger-tick\n");
    exit(0);
}

$days = max(1, min(90, (int)($opts['days'] ?? 7)));
$dryRun = isset($opts['dry-run']);
$asJson = isset($opts['json']);
$specificAccount = isset($opts['account']) ? (int)$opts['account'] : null;

$lockDir = dirname(LOCK_FILE);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockHandle = fopen(LOCK_FILE, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, '[' . WORKER_NAME . "] Outra instância em execução — saindo\n");
    if ($lockHandle !== false) {
        fclose($lockHandle);
    }
    exit(0);
}

try {
    $pdo = Database::getInstance();
    $to = date('Y-m-d');
    $from = date('Y-m-d', strtotime("-{$days} days"));
    $periodKey = date('Y-m-01');

    $accounts = [];
    if ($specificAccount !== null && $specificAccount > 0) {
        $accounts[] = ['id' => $specificAccount];
    } else {
        $stmt = $pdo->query(
            "SELECT id FROM ml_accounts
             WHERE status IS NULL OR status NOT IN ('disconnected','revoked','inactive')
             ORDER BY id ASC"
        );
        $accounts = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    $report = [
        'worker' => WORKER_NAME,
        'from' => $from,
        'to' => $to,
        'period_billing' => $periodKey,
        'dry_run' => $dryRun,
        'accounts' => [],
        'started_at' => date('c'),
    ];

    foreach ($accounts as $acc) {
        $accountId = (int)$acc['id'];
        $accReport = ['account_id' => $accountId, 'steps' => []];

        try {
            $orders = (new FinancialIngestionService($accountId, $pdo))->backfillOrders($from, $to, [
                'fetch_shipping' => true,
                'fetch_refunds' => true,
                'dry_run' => $dryRun,
                'sleep_us' => 40000,
            ]);
            $accReport['steps']['orders'] = [
                'scanned' => $orders['orders_scanned'] ?? 0,
                'created' => $orders['entries_created'] ?? 0,
                'updated' => $orders['entries_updated'] ?? 0,
                'chargebacks' => $orders['chargebacks_ingested'] ?? 0,
                'errors' => count($orders['errors'] ?? []),
            ];
        } catch (Throwable $e) {
            $accReport['steps']['orders'] = ['error' => $e->getMessage()];
        }

        try {
            $releases = (new SettlementIngestionService($accountId, $pdo))->backfillReleases($from, $to, [
                'dry_run' => $dryRun,
                'sleep_us' => 40000,
            ]);
            $accReport['steps']['releases'] = [
                'with_payment' => $releases['orders_with_payment'] ?? 0,
                'created' => $releases['entries_created'] ?? 0,
                'released' => $releases['released_count'] ?? 0,
                'pending' => $releases['pending_count'] ?? 0,
                'errors' => count($releases['errors'] ?? []),
            ];
        } catch (Throwable $e) {
            $accReport['steps']['releases'] = ['error' => $e->getMessage()];
        }

        try {
            $billing = (new BillingChargeIngestionService($accountId, $pdo))->backfillPeriod($periodKey, [
                'dry_run' => $dryRun,
            ]);
            $accReport['steps']['billing'] = [
                'mapped' => $billing['lines_mapped'] ?? 0,
                'created' => $billing['entries_created'] ?? 0,
                'unmapped' => $billing['lines_unmapped_sub_types'] ?? [],
                'errors' => count($billing['errors'] ?? []),
            ];
        } catch (Throwable $e) {
            $accReport['steps']['billing'] = ['error' => $e->getMessage()];
        }

        try {
            $wd = (new WithdrawalIngestionService($accountId, $pdo))->backfillWithdrawals($from, $to, [
                'dry_run' => $dryRun,
            ]);
            $accReport['steps']['withdrawals'] = [
                'api_blocked' => !empty($wd['api_blocked']),
                'created' => $wd['entries_created'] ?? 0,
                'cash' => $wd['cash_reconciliation'] ?? null,
            ];
        } catch (Throwable $e) {
            $accReport['steps']['withdrawals'] = ['error' => $e->getMessage()];
        }

        try {
            $recon = (new FinancialReconciliationService($accountId, $pdo))->reconcilePeriod($from, $to);
            $accReport['steps']['reconciliation'] = [
                'checked' => $recon['orders_checked'] ?? 0,
                'upserted' => $recon['discrepancies_upserted'] ?? 0,
                'critical' => $recon['open_critical'] ?? 0,
                'warning' => $recon['open_warning'] ?? 0,
            ];
        } catch (Throwable $e) {
            $accReport['steps']['reconciliation'] = ['error' => $e->getMessage()];
        }

        $report['accounts'][] = $accReport;
    }

    $report['finished_at'] = date('c');
    $reportDir = dirname(__DIR__) . '/storage/reports';
    if (!is_dir($reportDir)) {
        @mkdir($reportDir, 0775, true);
    }
    $reportFile = sprintf('%s/financial-ledger-tick-%s.json', $reportDir, date('YmdHis'));
    file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $report['report_file'] = $reportFile;

    if ($asJson) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDOUT, sprintf(
            "[%s] accounts=%d period=%s..%s report=%s\n",
            WORKER_NAME,
            count($report['accounts']),
            $from,
            $to,
            $reportFile
        ));
        foreach ($report['accounts'] as $a) {
            fwrite(STDOUT, sprintf(
                "  account=%d orders_c=%s releases_c=%s billing_c=%s wd_blocked=%s recon_up=%s\n",
                $a['account_id'],
                (string)($a['steps']['orders']['created'] ?? $a['steps']['orders']['error'] ?? '?'),
                (string)($a['steps']['releases']['created'] ?? $a['steps']['releases']['error'] ?? '?'),
                (string)($a['steps']['billing']['created'] ?? $a['steps']['billing']['error'] ?? '?'),
                !empty($a['steps']['withdrawals']['api_blocked']) ? 'yes' : 'no',
                (string)($a['steps']['reconciliation']['upserted'] ?? $a['steps']['reconciliation']['error'] ?? '?')
            ));
        }
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . WORKER_NAME . '] ERRO: ' . $e->getMessage() . "\n");
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    exit(1);
}
