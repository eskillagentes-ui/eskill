<?php

declare(strict_types=1);

/**
 * Coleta Sentinela (read-only) — riscos de conta.
 *
 * Uso:
 *   php bin/sentinela-collect.php --account-id=1335
 *   php bin/sentinela-collect.php --all
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database;
use App\Services\Sentinela\Sentinela;

$opts = getopt('', ['account-id:', 'all']);

function sentinela_resolve_ids(array $opts): array
{
    if (isset($opts['account-id'])) {
        return [(int) $opts['account-id']];
    }
    if (!isset($opts['all'])) {
        fwrite(STDERR, "Informe --account-id=N ou --all\n");
        exit(1);
    }
    $pdo = Database::getInstance();
    $rows = $pdo->query(
        "SELECT id FROM ml_accounts WHERE status IN ('active','connected') OR status IS NULL LIMIT 200"
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows ?: []);
}

$svc = new Sentinela();
foreach (sentinela_resolve_ids($opts) as $accountId) {
    if ($accountId <= 0) {
        continue;
    }
    try {
        $r = $svc->collect($accountId);
        fwrite(STDOUT, sprintf(
            "[%s] account=%d semaforo=%s monitored=%d podeExpandir=%s\n",
            date('H:i:s'),
            $accountId,
            $r['semaforo'],
            $r['monitored'],
            $svc->podeExpandir($accountId) ? 'sim' : 'nao'
        ));
        foreach ($r['risks'] as $risk) {
            fwrite(STDOUT, sprintf(
                "  - %-14s %-10s %s\n",
                $risk['risk_key'],
                $risk['status'],
                $risk['reason'] ?? ''
            ));
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "[err] account={$accountId} {$e->getMessage()}\n");
    }
}
