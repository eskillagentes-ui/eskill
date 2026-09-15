#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Emite / consulta / revoga ItemGoGrant (um MLB ativo por conta, TTL, consume no apply).
 *
 *   php bin/item-go-grant.php --account=1335 --mlb=MLB1234567890
 *   php bin/item-go-grant.php --account=1335 --status
 *   php bin/item-go-grant.php --account=1335 --revoke
 *
 * Não liga ML_WRITE_AUTOMATION. Não publica em lote.
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
use App\Services\HiddenSeo\ItemGoGrant;

$options = getopt('', ['account:', 'mlb:', 'ttl:', 'status', 'revoke', 'help']);
if (isset($options['help'])) {
    echo "ItemGoGrant (um MLB por conta; FACILYTY 1335 exige grant no apply)\n";
    echo "  --account=ID   conta interna\n";
    echo "  --mlb=MLB…     emite grant para este anúncio (revoga a ativa anterior)\n";
    echo "  --ttl=SEC      opcional (default ITEM_GO_GRANT_TTL_SECONDS ou 900)\n";
    echo "  --status       mostra a grant ativa da conta\n";
    echo "  --revoke       revoga a grant ativa\n";
    exit(0);
}

$accountId = isset($options['account']) ? (int) $options['account'] : 0;
if ($accountId <= 0) {
    fwrite(STDERR, "uso: php bin/item-go-grant.php --account=ID [--mlb=MLB…|--status|--revoke]\n");
    exit(2);
}

try {
    $grants = new ItemGoGrant(Database::getInstance());
    if (isset($options['revoke'])) {
        $n = $grants->revokeActive($accountId);
        echo "revoked={$n} account={$accountId}\n";
        exit(0);
    }
    if (isset($options['status'])) {
        $row = $grants->findActiveForAccount($accountId);
        if ($row === null) {
            echo "account={$accountId} active=none\n";
            exit(0);
        }
        echo 'account=' . $accountId
            . ' mlb=' . (string) $row['mlb_id']
            . ' status=' . (string) $row['status']
            . ' expires_at=' . (string) $row['expires_at']
            . "\n";
        exit(0);
    }

    $mlb = isset($options['mlb']) ? (string) $options['mlb'] : '';
    if ($mlb === '' || str_contains($mlb, ',') || preg_match('/\s/', $mlb) === 1) {
        fwrite(STDERR, "lote sem allowlist falha — um --mlb por vez\n");
        exit(2);
    }
    $ttl = isset($options['ttl']) ? (int) $options['ttl'] : null;
    $row = $grants->issue($accountId, $mlb, $ttl);
    echo 'issued account=' . $accountId
        . ' mlb=' . (string) $row['mlb_id']
        . ' expires_at=' . (string) $row['expires_at']
        . ' ttl=' . (string) $row['ttl_seconds']
        . "\n";
    echo "SAFE_MODE e ML_WRITE_AUTOMATION intactos; apply FACILYTY ainda exige --apply / allowApply.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'error=' . $e->getMessage() . "\n");
    exit(1);
}
