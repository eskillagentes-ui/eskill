#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Deep scan AWA via /products/search paginado + winners + descrição (CF proxy).
 *
 * Uso:
 *   php bin/awa-catalog-deep-scan.php --account=1335
 *   php bin/awa-catalog-deep-scan.php --account=1335 --query="Awa Motos" --max-products=520 --enrich=120
 *   php bin/awa-catalog-deep-scan.php --account=1335 --use-default-plans --max-products=400 --enrich=120
 *   php bin/awa-catalog-deep-scan.php --account=1335 --dry-run --max-products=50
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../app/Services/_runtime/bootstrap.php';

use App\Services\AwaCatalogDeepDiscoveryService;

$opts = getopt('', ['account:', 'query:', 'max-products:', 'enrich:', 'use-default-plans', 'dry-run', 'help']);
if (isset($opts['help'])) {
    echo "Uso: php bin/awa-catalog-deep-scan.php --account=1335 [--query=Awa Motos] [--use-default-plans] [--max-products=520] [--enrich=120] [--dry-run]\n";
    exit(0);
}

$accountId = isset($opts['account']) ? (int) $opts['account'] : 1335;
$maxProducts = isset($opts['max-products']) ? (int) $opts['max-products'] : 400;
$enrich = isset($opts['enrich']) ? (int) $opts['enrich'] : 120;
$dryRun = isset($opts['dry-run']);
$useDefaultPlans = isset($opts['use-default-plans']) || !isset($opts['query']);

$runOptions = [
    'max_products' => $maxProducts,
    'enrich_descriptions' => $enrich,
    'dry_run' => $dryRun,
];

if (!$useDefaultPlans && isset($opts['query']) && trim((string) $opts['query']) !== '') {
    $runOptions['queries'] = [trim((string) $opts['query'])];
}

if ($accountId <= 0) {
    fwrite(STDERR, "account inválido\n");
    exit(2);
}

$service = new AwaCatalogDeepDiscoveryService($accountId);
$result = $service->run($runOptions);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
