#!/usr/bin/env php
<?php
/**
 * Webhook Processor Worker
 *
 * Dequeue and process ML webhook events stored in `webhook_events` by
 * WebhookController::receive(). Supports replay of failed events.
 *
 * Uso:
 *   php bin/webhook-processor-worker.php [opções]
 *
 * Opções:
 *   --once              Processa uma rodada e sai (sem loop contínuo)
 *   --replay-failed     Também reprocessa eventos com status=failed (attempts < 3)
 *   --limit=N           Máximo de eventos por iteração (padrão: 50)
 *   --sleep=S           Segundos de espera quando idle (padrão: 5)
 *   --help              Exibe esta ajuda
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use App\Database;
use App\Services\MercadoLivreWebhookService;
use App\Services\StructuredLogService;

// ─── Parsing de opções ────────────────────────────────────────────────────────
$opts    = getopt('', ['once', 'replay-failed', 'limit:', 'sleep:', 'help']);
$runOnce      = isset($opts['once']);
$replayFailed = isset($opts['replay-failed']);
$batchLimit   = max(1, min(500, (int)($opts['limit'] ?? 50)));
$sleepSeconds = max(1, min(60,  (int)($opts['sleep'] ?? 5)));

if (isset($opts['help'])) {
    echo <<<HELP
Webhook Processor Worker — processa eventos ML pendentes em webhook_events

Uso: php bin/webhook-processor-worker.php [opções]

  --once              Processa uma rodada e sai
  --replay-failed     Reprocessa eventos status=failed (tentativas < 3)
  --limit=N           Eventos por rodada (padrão: 50, máx: 500)
  --sleep=S           Espera em segundos quando idle (padrão: 5, máx: 60)
  --help              Exibe esta ajuda

HELP;
    exit(0);
}

// ─── Flock: prevenir execução concorrente ─────────────────────────────────────
$lockDir  = __DIR__ . '/../storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile   = $lockDir . '/webhook-processor-worker.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[webhook-processor-worker] Outra instância já está em execução — saindo\n";
    if ($lockHandle !== false) {
        fclose($lockHandle);
    }
    exit(0);
}

/** @var \PDO $db */
$db     = null;
$logger = new StructuredLogService();

/**
 * Resolve account_id from ML user_id via ml_accounts.
 * Falls back to 0 (anonymous/system) when no match found.
 */
function resolveAccountId(\PDO $db, ?int $mlUserId): int
{
    if ($mlUserId === null || $mlUserId <= 0) {
        return 0;
    }
    $stmt = $db->prepare(
        "SELECT id FROM ml_accounts WHERE ml_user_id = ? AND status IN ('active', 'expired', 'inactive') ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'expired' THEN 1 ELSE 2 END, updated_at DESC LIMIT 1"
    );
    $stmt->execute([$mlUserId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}

/**
 * Mark an event row as processed or failed.
 */
function markEvent(\PDO $db, int $id, string $status, ?string $errorMessage): void
{
    $db->prepare(
        "UPDATE webhook_events
         SET status = ?, processed_at = NOW(), attempts = attempts + 1, last_error = ?
         WHERE id = ?"
    )->execute([$status, $errorMessage, $id]);
}

/**
 * Ensure the webhook_events table has the last_error column.
 * The column is added only if absent (idempotent, best-effort).
 */
function ensureLastErrorColumn(\PDO $db): void
{
    try {
        $db->exec(
            "ALTER TABLE webhook_events ADD COLUMN last_error TEXT NULL AFTER attempts"
        );
    } catch (\Throwable) {
        // Column already exists — ignore duplicate column error.
    }
}

/**
 * Fetch the next batch of events to process.
 *
 * @return list<array{id:int, topic:string, resource:string, user_id:int|null,
 *                    application_id:int|null, payload:string, attempts:int}>
 */
function fetchBatch(\PDO $db, bool $replayFailed, int $limit): array
{
    $statusClause = $replayFailed
        ? "(status = 'pending' OR (status = 'failed' AND attempts < 3))"
        : "status = 'pending'";

    $stmt = $db->prepare(
        "SELECT id, topic, resource, user_id, application_id, payload, attempts
         FROM webhook_events
         WHERE {$statusClause}
         ORDER BY id ASC
         LIMIT {$limit}"
    );
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Process a single row from webhook_events.
 */
function processRow(\PDO $db, array $row): void
{
    $id      = (int)$row['id'];
    $payload = json_decode((string)($row['payload'] ?? ''), true);

    if (!is_array($payload) || empty($payload)) {
        markEvent($db, $id, 'failed', 'Payload JSON inválido ou vazio');
        return;
    }

    $mlUserId  = isset($row['user_id']) && $row['user_id'] !== null ? (int)$row['user_id'] : null;
    $accountId = resolveAccountId($db, $mlUserId);

    $service = new MercadoLivreWebhookService($accountId);
    $result  = $service->processWebhookEvent($payload);

    if ($result['success'] === true) {
        markEvent($db, $id, 'processed', null);
    } else {
        $errMsg = (string)($result['error'] ?? 'desconhecido');
        $newAttempts = (int)$row['attempts'] + 1;
        $newStatus   = $newAttempts >= 3 ? 'failed' : 'pending';
        $db->prepare(
            "UPDATE webhook_events
             SET status = ?, processed_at = IF(? = 'processed', NOW(), NULL),
                 attempts = ?, last_error = ?
             WHERE id = ?"
        )->execute([$newStatus, $newStatus, $newAttempts, $errMsg, $id]);
    }
}

// ─── Main loop ────────────────────────────────────────────────────────────────
echo "[webhook-processor-worker] Iniciando"
    . ($runOnce ? ' (modo --once)' : '')
    . ($replayFailed ? ' + replay de falhos' : '')
    . " | limit={$batchLimit}"
    . " | sleep={$sleepSeconds}s\n";

try {
    $db = Database::getInstance();
    ensureLastErrorColumn($db);

    do {
        $batch = fetchBatch($db, $replayFailed, $batchLimit);

        if (empty($batch)) {
            if ($runOnce) {
                echo "[webhook-processor-worker] Nenhum evento pendente — saindo\n";
                break;
            }
            sleep($sleepSeconds);
            continue;
        }

        $processed = 0;
        $failed    = 0;
        foreach ($batch as $row) {
            try {
                processRow($db, $row);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                markEvent($db, (int)$row['id'], 'failed', $e->getMessage());
                $logger->error('[webhook-processor-worker] Exceção ao processar evento', [
                    'event_id' => $row['id'],
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        echo "[webhook-processor-worker] Rodada: processados={$processed}, falhos={$failed}\n";
    } while (!$runOnce);
} catch (\Throwable $e) {
    echo "[webhook-processor-worker] ERRO FATAL: " . $e->getMessage() . "\n";
    $logger->error('[webhook-processor-worker] Erro fatal', ['error' => $e->getMessage()]);
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit(0);
