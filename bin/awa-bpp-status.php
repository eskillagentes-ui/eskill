#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Status / dry-run BPP.
 *
 *   php bin/awa-bpp-status.php --account=1335
 *   php bin/awa-bpp-status.php --account=1335 --dry-run-item=MLB123 --reason=PPPI2
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Services\AwaBrandProtectionService;

$opts = getopt('', ['account:', 'dry-run-item:', 'reason:', 'comment:', 'help']);
if (isset($opts['help'])) {
    echo "Uso: php bin/awa-bpp-status.php --account=ID [--dry-run-item=MLB...] [--reason=PPPI2]\n";
    exit(0);
}

$accountId = isset($opts['account']) ? (int) $opts['account'] : 1335;
if ($accountId <= 0) {
    fwrite(STDERR, "--account inválido\n");
    exit(1);
}

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$bpp = new AwaBrandProtectionService($accountId);
$status = $bpp->getStatus();
echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

if (!empty($opts['dry-run-item'])) {
    $reason = (string) ($opts['reason'] ?? 'PPPI2');
    $comment = (string) ($opts['comment'] ?? 'Dry-run CLI AWA BPP');
    $result = $bpp->denounceItem((string) $opts['dry-run-item'], $reason, $comment, true, null, 'cli');
    echo "\n--- dry-run ---\n";
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

exit($status['ready_to_denounce'] ? 0 : 2);
