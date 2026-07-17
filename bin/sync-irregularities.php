#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cron read-only: sincroniza irregularidades ML → ml_sales_blockers.
 *
 * Uso:
 *   php bin/sync-irregularities.php --account-id=123 --actor-user-id=1
 *   php bin/sync-irregularities.php --all-active --actor-user-id=1
 *
 * Requer account_id explícito (ou --all-active) + actor (SEC-001 worker).
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (is_readable($root . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($root);
    $dotenv->safeLoad();
}

use App\Database;
use App\Security\AccountContextResolver;
use App\Services\MercadoLivre\IrregularitySyncService;
use App\Services\MercadoLivreClient;

$opts = getopt('', ['account-id:', 'actor-user-id:', 'all-active', 'limit:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/sync-irregularities.php --actor-user-id=ID (--account-id=ID|--all-active) [--limit=30]\n");
    exit(0);
}

$actorUserId = isset($opts['actor-user-id']) ? (int) $opts['actor-user-id'] : 0;
$limit = isset($opts['limit']) ? (int) $opts['limit'] : 30;

if ($actorUserId <= 0) {
    fwrite(STDERR, "ERROR: --actor-user-id é obrigatório (SEC-001 worker)\n");
    exit(1);
}

$resolver = new AccountContextResolver();
$accountIds = [];

/** @var list<array{id:int,user_id:int}> $targets */
$targets = [];

if (isset($opts['all-active'])) {
    $pdo = Database::getInstance();
    // SEC-001: cada conta autorizada com seu owner (actor-user-id deve ser o dono ou igual)
    $stmt = $pdo->query("SELECT id, user_id FROM ml_accounts WHERE status = 'active'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $targets[] = ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
    }
} elseif (isset($opts['account-id'])) {
    $targets[] = ['id' => (int) $opts['account-id'], 'user_id' => $actorUserId];
} else {
    fwrite(STDERR, "ERROR: informe --account-id ou --all-active\n");
    exit(1);
}

if ($targets === []) {
    fwrite(STDERR, "Nenhuma conta para sincronizar\n");
    exit(0);
}

$exitCode = 0;
foreach ($targets as $target) {
    $accountId = $target['id'];
    // Para --all-active usa o owner da conta; para --account-id usa o actor informado
    $effectiveActor = isset($opts['all-active']) ? $target['user_id'] : $actorUserId;
    try {
        $context = $resolver->authorizeForWorker($effectiveActor, $accountId, 'irregularities.sync');
        $client = MercadoLivreClient::fromAuthorizedContext($context);
        $sync = IrregularitySyncService::forClient($client);
        $result = $sync->syncAccount($context, $limit);
        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    } catch (Throwable $e) {
        $exitCode = 1;
        fwrite(STDERR, json_encode([
            'account_id' => $accountId,
            'error' => $e->getMessage(),
            'write_enabled' => false,
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }
    // Rate-limit gentil entre contas
    usleep(250000);
}

exit($exitCode);
