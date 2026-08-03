#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Auto-identifica CNPJ em lote para sellers AWA sem identificação.
 *
 * Uso:
 *   php bin/awa-auto-id-worker.php --account=1335 --limit=100 --verbose
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Services\AwaSellerIdentificationService;
use App\Services\MercadoLivreClient;

$opts = getopt('', ['account:', 'limit:', 'verbose', 'help']);
if (isset($opts['help'])) {
    echo "Uso: php bin/awa-auto-id-worker.php --account=ID [--limit=100] [--verbose]\n";
    exit(0);
}

$accountId = isset($opts['account']) ? (int) $opts['account'] : 0;
$limit = isset($opts['limit']) ? max(1, min(200, (int) $opts['limit'])) : 100;
$verbose = isset($opts['verbose']);

if ($accountId <= 0) {
    fwrite(STDERR, "--account=ID obrigatório\n");
    exit(1);
}

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$log = static function (string $msg) use ($verbose): void {
    $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    echo $line;
    @file_put_contents(
        dirname(__DIR__) . '/storage/logs/awa-auto-id.log',
        $line,
        FILE_APPEND | LOCK_EX
    );
};

$log("Auto-ID batch account={$accountId} limit={$limit}");

try {
    $client = new MercadoLivreClient($accountId);
    $svc = new AwaSellerIdentificationService($accountId);
    $result = $svc->autoIdentifyBatch($client, $limit);
    $log('result=' . json_encode($result, JSON_UNESCAPED_UNICODE));
    exit(0);
} catch (Throwable $e) {
    $log('ERROR ' . $e->getMessage());
    exit(1);
}
