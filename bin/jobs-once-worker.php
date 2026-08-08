#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Processa jobs pendentes em lote (modo --once para cron).
 *
 * Uso:
 *   php bin/jobs-once-worker.php --limit=40
 *   php bin/jobs-once-worker.php --limit=40 --type=ml_webhook
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/vendor/autoload.php';

if (file_exists(ROOT_PATH . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
    $dotenv->safeLoad();
}

$limit = 40;
$typeFilter = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(200, (int)substr($arg, 8)));
    }
    if (str_starts_with($arg, '--type=')) {
        $typeFilter = substr($arg, 7);
    }
}

use App\Services\JobService;

$started = microtime(true);
echo '[' . date('Y-m-d H:i:s') . "] jobs-once-worker start limit={$limit}"
    . ($typeFilter ? " type={$typeFilter}" : '') . PHP_EOL;

try {
    $jobService = new JobService();
    $results = $jobService->process($limit);

    $ok = 0;
    $fail = 0;
    $other = 0;
    $byType = [];

    foreach ($results as $result) {
        $status = (string)($result['status'] ?? '');
        $type = (string)($result['type'] ?? 'unknown');
        $byType[$type] = ($byType[$type] ?? 0) + 1;

        if ($typeFilter !== null && $type !== $typeFilter) {
            continue;
        }

        if ($status === 'completed') {
            $ok++;
        } elseif ($status === 'failed') {
            $fail++;
            $err = (string)($result['error'] ?? '');
            echo '[' . date('Y-m-d H:i:s') . "] FAIL #{$result['id']} ({$type}): {$err}" . PHP_EOL;
        } else {
            $other++;
        }
    }

    $elapsed = round(microtime(true) - $started, 2);
    echo '[' . date('Y-m-d H:i:s') . '] done processed=' . count($results)
        . " ok={$ok} fail={$fail} other={$other} elapsed={$elapsed}s"
        . ' types=' . json_encode($byType, JSON_UNESCAPED_UNICODE)
        . PHP_EOL;

    exit($fail > 0 && $ok === 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
