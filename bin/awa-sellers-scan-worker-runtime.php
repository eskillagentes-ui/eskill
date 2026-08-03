#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * AWA Sellers Scan Worker (runtime / deep scan)
 *
 * Usado pelo cron:
 *   0 9,21 * * * php bin/awa-sellers-scan-worker-runtime.php
 *
 * Executa deep scan via /products/search (caminho disponível quando
 * /sites/MLB/search está bloqueado por PolicyAgent).
 *
 * Uso:
 *   php bin/awa-sellers-scan-worker-runtime.php [--account=ID] [--max-items=N] [--verbose]
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Database;
use App\Services\AwaSellerDeepScanService;

$opts = getopt('', ['account:', 'max-items:', 'verbose', 'help', 'dry-run']);

if (isset($opts['help'])) {
    echo "Uso: php bin/awa-sellers-scan-worker-runtime.php [--account=ID] [--max-items=3000] [--verbose]\n";
    exit(0);
}

$targetAccountId = isset($opts['account']) ? (int) $opts['account'] : null;
$maxItems = isset($opts['max-items']) ? max(100, min(20000, (int) $opts['max-items'])) : 3000;
$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);

$lockFile = __DIR__ . '/../storage/locks/awa-sellers-scan-worker.lock';
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[awa-sellers-scan-worker-runtime] Outra instancia em execucao — saindo\n");
    if ($lockHandle !== false) {
        fclose($lockHandle);
    }
    exit(0);
}

$log = static function (string $msg, string $level = 'INFO') use ($verbose): void {
    $line = '[' . date('Y-m-d H:i:s') . "] [$level] $msg\n";
    if ($level !== 'DEBUG' || $verbose) {
        echo $line;
    }
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . '/awa-sellers-scan.log', $line, FILE_APPEND | LOCK_EX);
};

$log('=== AWA Sellers Deep Scan Worker iniciado ===');

try {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();

    $db = Database::getInstance();
    if ($targetAccountId !== null) {
        $stmt = $db->prepare("SELECT id FROM ml_accounts WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute(['id' => $targetAccountId]);
    } else {
        $stmt = $db->query("SELECT id FROM ml_accounts WHERE status = 'active' ORDER BY id");
    }

    $accounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($accounts === []) {
        $log('Nenhuma conta ativa encontrada', 'WARN');
        exit(0);
    }

    foreach ($accounts as $accountId) {
        $accountId = (int) $accountId;
        $log("Deep scan conta {$accountId} (max_items={$maxItems})");

        if ($dryRun) {
            $log("DRY-RUN: skip persistência conta {$accountId}", 'WARN');
            continue;
        }

        try {
            $service = new AwaSellerDeepScanService($accountId);
            $result = $service->runScan(['max_items' => $maxItems]);
            $log(sprintf(
                'Conta %d OK scan_id=%s sellers=%d items=%d mode=%s time=%s',
                $accountId,
                (string) ($result['scan_id'] ?? '?'),
                (int) ($result['sellers_found'] ?? 0),
                (int) ($result['items_found'] ?? 0),
                (string) ($result['collection_mode'] ?? '?'),
                (string) ($result['execution_time'] ?? '?')
            ));
        } catch (Throwable $e) {
            $log('Falha conta ' . $accountId . ': ' . $e->getMessage(), 'ERROR');
        }
    }

    $log('=== Deep Scan Worker concluído ===');
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(0);
} catch (Throwable $e) {
    $log('ERRO FATAL: ' . $e->getMessage(), 'ERROR');
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    exit(1);
}
