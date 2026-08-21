#!/usr/bin/env php
<?php
/**
 * Sync de irregularidades / bloqueios de venda (somente GET na API ML).
 *
 * Uso:
 *   php bin/sync-irregularities.php --account=ID [--limit=400]
 *   php bin/sync-irregularities.php --all-active --actor-user-id=100222 --limit=400
 *
 * Cron (a cada 6 horas, minuto 30):
 *   php bin/sync-irregularities.php --all-active --actor-user-id=100222 --limit=400 >> storage/logs/sync-irregularities.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$options = getopt('', ['account:', 'all-active', 'actor-user-id:', 'limit:', 'help']);

if (isset($options['help'])) {
    echo "Sync de irregularidades (read-only ML)\n\n";
    echo "  --account=ID           Processa uma conta\n";
    echo "  --all-active           Processa contas ativas autorizadas\n";
    echo "  --actor-user-id=ID     Ator SEC-001 (ownership)\n";
    echo "  --limit=N              Máximo de itens por conta (padrão: 400)\n";
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Database;
use App\Exception\AccountAccessDeniedException;
use App\Exception\AccountNotFoundException;
use App\Security\OwnerAccountAccessPolicy;
use App\Services\Catalog\ListingIrregularityScanService;
use App\Services\Catalog\SalesBlockerStore;

$accountId = isset($options['account']) ? (int) $options['account'] : 0;
$allActive = isset($options['all-active']);
$actorUserId = isset($options['actor-user-id']) ? (int) $options['actor-user-id'] : 0;
$limit = isset($options['limit']) ? (int) $options['limit'] : 400;

if ($accountId <= 0 && !$allActive) {
    fwrite(STDERR, "Informe --account=ID ou --all-active\n");
    exit(1);
}

try {
    $pdo = Database::getInstance();
    $policy = new OwnerAccountAccessPolicy($pdo);
    $store = new SalesBlockerStore($pdo);
    $scanner = new ListingIrregularityScanService($pdo, $store);

    if ($accountId > 0) {
        $ids = [$accountId];
    } else {
        $stmt = $pdo->query("SELECT id FROM ml_accounts WHERE status = 'active'");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
    }

    $processed = 0;
    $skipped = 0;
    foreach ($ids as $id) {
        try {
            if ($actorUserId > 0) {
                $policy->authorize($actorUserId, $id, 'worker');
            } else {
                $policy->authorizeWorker($id, 'worker');
            }
        } catch (AccountAccessDeniedException | AccountNotFoundException $e) {
            $skipped++;
            log_info('sync-irregularities: conta ignorada', [
                'account_id' => $id,
                'reason' => $e->getMessage(),
            ]);
            continue;
        }

        $result = $scanner->scanAccount($id, $limit, 'cron');
        $processed++;
        echo sprintf(
            "account=%d scanned=%d upserted=%d resolved=%d pending_total=%d errors=%d\n",
            $result['account_id'],
            $result['scanned'],
            $result['upserted'],
            $result['resolved'],
            $result['pending_total'],
            count($result['errors'])
        );
    }

    echo "done processed={$processed} skipped={$skipped}\n";
    exit(0);
} catch (Throwable $e) {
    log_error('sync-irregularities: falha', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}
