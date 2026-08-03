#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seed lojas oficiais/revendedores AWA via catálogo (sem /sites/search).
 *
 *   php bin/awa-official-store-seed.php --account=1335
 *   php bin/awa-official-store-seed.php --account=1335 --dry-run --max-per-query=10 --enrich=20
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../app/Services/_runtime/bootstrap.php';

use App\Services\AwaOfficialStoreSeedService;

$opts = getopt('', ['account:', 'max-per-query:', 'enrich:', 'dry-run', 'help']);
if (isset($opts['help'])) {
    echo "Uso: php bin/awa-official-store-seed.php --account=1335 [--max-per-query=15] [--enrich=40] [--dry-run]\n";
    exit(0);
}

$accountId = isset($opts['account']) ? (int) $opts['account'] : 1335;
$service = new AwaOfficialStoreSeedService($accountId);
$result = $service->run([
    'max_products_per_query' => isset($opts['max-per-query']) ? (int) $opts['max-per-query'] : 12,
    'enrich_descriptions' => isset($opts['enrich']) ? (int) $opts['enrich'] : 30,
    'dry_run' => isset($opts['dry-run']),
]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
