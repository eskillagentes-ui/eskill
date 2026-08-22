#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Listing apply job — dry-run default. Never Premium/frete/MODEL stuffing.
 *
 *   php bin/listing-apply-job.php --account=1335 --mlb=MLB1234567890
 *   php bin/listing-apply-job.php --account=1335 --mlb=MLB1234567890 --apply
 *
 * Sem --apply: grava listing_apply_jobs status=dry_run e imprime o PUT.
 * --apply: um MLB allowlisted por vez; lote falha. FACILYTY 1335 só com --mlb na linha de comando.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/autoload.php';

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

use App\Database;
use App\Services\ListingApply\ListingApplyJobService;
use App\Services\ML\MLWriteGateway;

$options = getopt('', ['account:', 'mlb:', 'apply', 'help']);
if (isset($options['help'])) {
    echo "Listing apply job (dry-run default, no Premium/frete batch)\n";
    echo "  --account=ID   conta (1335 e 1336 isoladas)\n";
    echo "  --mlb=MLB…     um id por vez (allowlist do comando)\n";
    echo "  --apply        tenta PUT só title/catalog_listing via MLWriteGateway\n";
    exit(0);
}

$accountId = isset($options['account']) ? (int) $options['account'] : 0;
$mlbRaw = isset($options['mlb']) ? (string) $options['mlb'] : '';
$apply = isset($options['apply']);

if ($accountId <= 0 || $mlbRaw === '') {
    fwrite(STDERR, "uso: php bin/listing-apply-job.php --account=ID --mlb=MLB… [--apply]\n");
    exit(2);
}
if (str_contains($mlbRaw, ',') || preg_match('/\s/', $mlbRaw) === 1) {
    fwrite(STDERR, "lote sem allowlist falha — um --mlb por vez\n");
    exit(2);
}
if (str_contains($root, 'staging.eskill.com.br') && $accountId === ListingApplyJobService::FACILYTY_ACCOUNT && $apply) {
    fwrite(STDERR, "staging workers must not apply FACILYTY 1335\n");
    exit(2);
}

echo "Listing apply job\n";
echo 'account=' . $accountId . ' mlb=' . strtoupper($mlbRaw) . ' apply=' . ($apply ? 'yes' : 'no') . "\n";
echo 'SAFE_MODE=' . (getenv('SAFE_MODE') ?: ($_ENV['SAFE_MODE'] ?? 'unset')) . ' ML_WRITE_AUTOMATION=' . (getenv('ML_WRITE_AUTOMATION') ?: ($_ENV['ML_WRITE_AUTOMATION'] ?? 'false')) . "\n";

$putter = null;
if ($apply) {
    $putter = static function (int $acc, string $mlb, array $payload) {
        $gw = new MLWriteGateway();
        return $gw->execute(MLWriteGateway::ACTION_HTTP, $payload, [
            'account_id' => $acc,
            'mlb_id' => $mlb,
            'dry_run' => false,
        ]);
    };
}

try {
    $svc = new ListingApplyJobService(Database::getInstance(), null, $putter);
    $row = $svc->run($accountId, $mlbRaw, $apply);
    echo 'status=' . $row['status'] . " ml_write=" . (!empty($row['ml_write']) ? 'true' : 'false') . " api_called=" . (!empty($row['api_called']) ? 'true' : 'false') . "\n";
    if (!empty($row['blocked_by'])) {
        echo 'blocked_by=' . $row['blocked_by'] . "\n";
    }
    echo 'payload=' . json_encode($row['payload'], JSON_UNESCAPED_UNICODE) . "\n";
    echo "sem Premium/frete em massa; MODEL stuffing e original_price fora do payload\n";
    if ($row['status'] === ListingApplyJobService::STATUS_DRY_RUN) {
        exit(0);
    }
    if ($row['status'] === ListingApplyJobService::STATUS_APPLIED) {
        exit(0);
    }
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, 'error=' . $e->getMessage() . "\n");
    exit(1);
}
