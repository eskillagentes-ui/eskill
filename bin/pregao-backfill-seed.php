#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backfill auditável: marca eventos de smoke como source=seed, remove DEFAULT
 * silencioso de source e recalcula account_index_daily (tick live).
 *
 * Uso:
 *   php bin/pregao-backfill-seed.php --dry-run
 *   php bin/pregao-backfill-seed.php --account-id=1335
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Services\Pregao\PregaoProvenanceService;

$opts = getopt('', ['dry-run', 'account-id:', 'skip-recalc']);
$dry = isset($opts['dry-run']);
$accountId = isset($opts['account-id']) ? (int) $opts['account-id'] : (int) ($_ENV['PREGAO_ACCOUNT_ID'] ?? 1335);

$svc = new PregaoProvenanceService();

fwrite(STDOUT, "=== Pregão provenance backfill ===\n");
$before = $svc->sourceTotals();
fwrite(STDOUT, 'ANTES: seed=' . $before['seed'] . ' live=' . $before['live'] . "\n");

$result = $svc->backfillSmokeAsSeed($dry);
fwrite(STDOUT, 'Critério: ' . $result['criteria'] . "\n");
fwrite(STDOUT, ($dry ? 'DRY seed_marked=' : 'seed_marked=') . $result['seed_marked'] . "\n");
fwrite(STDOUT, 'DEPOIS: seed=' . $result['seed_total'] . ' live=' . $result['live_total'] . "\n");

if (!$dry) {
    $svc->dropSourceDefault();
    fwrite(STDOUT, "OK: source sem DEFAULT silencioso\n");
}

if (!$dry && !isset($opts['skip-recalc']) && $accountId > 0) {
    $recalc = $svc->recalculateDailyExcludingSeed($accountId);
    fwrite(STDOUT, $recalc['log'] . "\n");
}

fwrite(STDOUT, "Concluído.\n");
