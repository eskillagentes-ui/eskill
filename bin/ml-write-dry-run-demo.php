#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Demo dry-run do MLWriteGateway (Onda 4 / T3).
 * NÃO chama a API do ML — força dry-run e registra auditoria.
 *
 *   php bin/ml-write-dry-run-demo.php --account-id=1335 --mlb=MLB7346643828
 */

use App\Services\ML\MLWriteGateway;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$opts = getopt('', ['account-id:', 'mlb:', 'allowlist']);
$accountId = (int) ($opts['account-id'] ?? 1335);
$mlb = strtoupper((string) ($opts['mlb'] ?? 'MLB7346643828'));

$gw = new MLWriteGateway(forceDryRun: true);

echo "ML_WRITE_AUTOMATION=" . ($_ENV['ML_WRITE_AUTOMATION'] ?? getenv('ML_WRITE_AUTOMATION') ?: 'false') . PHP_EOL;

// 1) Sem allowlist — deve bloquear
$blocked = $gw->execute(MLWriteGateway::ACTION_PAUSE, [
    'mlb_id' => $mlb,
    'status' => 'paused',
    'reason' => 'MORTO/Plano de Destravamento — demo',
], [
    'account_id' => $accountId,
    'user_id' => 100222,
    'mlb_id' => $mlb,
    'dry_run' => true,
    'before' => ['status' => 'active', 'source' => 'unlock_plan'],
    'expected_after' => ['status' => 'paused'],
]);
echo "=== sem allowlist ===\n";
echo json_encode($blocked, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

// 2) Com allowlist — ainda dry-run; kill switch / flags / TRAVADA podem bloquear antes
if (array_key_exists('allowlist', $opts)) {
    $gw->addToAllowlist($accountId, $mlb, 100222);
}
$withList = $gw->execute(MLWriteGateway::ACTION_PAUSE, [
    'mlb_id' => $mlb,
    'status' => 'paused',
    'reason' => 'MORTO/Plano de Destravamento — demo',
], [
    'account_id' => $accountId,
    'user_id' => 100222,
    'mlb_id' => $mlb,
    'dry_run' => true,
    'before' => ['status' => 'active'],
    'expected_after' => ['status' => 'paused'],
]);
echo "=== com contexto allowlist=" . (array_key_exists('allowlist', $opts) ? 'yes' : 'no') . " ===\n";
echo json_encode($withList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$apiCalled = !empty($blocked['api_called']) || !empty($withList['api_called']);
echo "api_called_any=" . ($apiCalled ? 'YES_FAIL' : 'no') . PHP_EOL;
exit($apiCalled ? 1 : 0);
