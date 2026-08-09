#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Questions Sync Worker
 *
 * Sincroniza perguntas do Mercado Livre para o banco local.
 * Executa continuamente ou via cron com --once.
 *
 * Uso:
 *   php bin/questions-sync-worker.php [opções]
 *
 * Opções:
 *   --once          Executa uma vez e sai (ideal para cron)
 *   --account=ID    Processa apenas a conta especificada
 *   --limit=N       Limite de perguntas por conta (padrão: 50)
 *   --verbose       Exibe saída detalhada no console
 *   --help          Exibe esta ajuda
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use App\Services\QuestionService;
use App\Services\StructuredLogService;

// ─── Lock exclusivo para evitar sobreposição ────────────────────────────────
$lockDir = __DIR__ . '/../storage/locks';
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0755, true);
}

$lockFile = $lockDir . '/questions-sync-worker.lock';
$lockHandle = fopen($lockFile, 'w');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[questions-sync-worker] Outra instância já está em execução. Saindo.\n";
    exit(0);
}

// ─── CLI Options ─────────────────────────────────────────────────────────────
$options = getopt('', ['once', 'account:', 'limit:', 'verbose', 'help']);

if (isset($options['help'])) {
    $help = <<<HELP
Questions Sync Worker — Mercado Livre

Uso: php bin/questions-sync-worker.php [opções]

Opções:
  --once          Executa uma vez e sai (ideal para cron)
  --account=ID    Processa apenas a conta especificada
  --limit=N       Limite de perguntas por conta (padrão: 50)
  --verbose       Exibe saída detalhada no console
  --help          Exibe esta ajuda

Exemplos:
  php bin/questions-sync-worker.php --once
  php bin/questions-sync-worker.php --once --account=123 --verbose
  php bin/questions-sync-worker.php --limit=100 --verbose

HELP;
    echo $help;
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(0);
}

$runOnce    = isset($options['once']);
$accountId  = isset($options['account']) ? (int) $options['account'] : null;
$limit      = isset($options['limit']) ? (int) $options['limit'] : 50;
$verbose    = isset($options['verbose']);

// ─── Logger ──────────────────────────────────────────────────────────────────
$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logger = new StructuredLogService('questions-sync-worker');

function logInfo(StructuredLogService $logger, string $msg, array $ctx = [], bool $verbose = false): void
{
    $logger->info($msg, $ctx);
    if ($verbose) {
        $time = date('Y-m-d H:i:s');
        $ctxStr = empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
        echo "[{$time}] [INFO] {$msg}{$ctxStr}\n";
    }
}

function logError(StructuredLogService $logger, string $msg, array $ctx = [], bool $verbose = false): void
{
    $logger->error($msg, $ctx);
    $time = date('Y-m-d H:i:s');
    $ctxStr = empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
    echo "[{$time}] [ERROR] {$msg}{$ctxStr}\n";
}

// ─── DB Connection ───────────────────────────────────────────────────────────
function getDbConnection(): PDO
{
    $host   = getenv('DB_HOST') ?: '127.0.0.1';
    $port   = getenv('DB_PORT') ?: '3306';
    $dbname = getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: '';
    $user   = getenv('DB_USERNAME') ?: getenv('DB_USER') ?: '';
    $pass   = getenv('DB_PASSWORD') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
}

// ─── Sync function ───────────────────────────────────────────────────────────
function syncAccountQuestions(
    int $mlAccountId,
    string $nickname,
    int $limit,
    StructuredLogService $logger,
    bool $verbose
): bool {
    try {
        $service = new QuestionService($mlAccountId);
        $result  = $service->syncQuestions($limit);

        $synced  = is_array($result) ? ($result['synced'] ?? $result['count'] ?? 0) : 0;
        $errors  = is_array($result) ? ($result['errors'] ?? 0) : 0;

        logInfo($logger, 'Perguntas sincronizadas', [
            'account_id' => $mlAccountId,
            'nickname'   => $nickname,
            'synced'     => $synced,
            'errors'     => $errors,
        ], $verbose);

        return true;
    } catch (\Throwable $e) {
        logError($logger, 'Erro ao sincronizar perguntas', [
            'account_id' => $mlAccountId,
            'nickname'   => $nickname,
            'error'      => $e->getMessage(),
        ], $verbose);
        return false;
    }
}

// ─── Main Loop ───────────────────────────────────────────────────────────────
logInfo($logger, 'Questions Sync Worker iniciado', [
    'run_once'   => $runOnce,
    'account_id' => $accountId,
    'limit'      => $limit,
], true);

do {
    $cycleStart = microtime(true);

    try {
        $db = getDbConnection();

        if ($accountId !== null) {
            $stmt = $db->prepare(
                "SELECT id, nickname FROM ml_accounts WHERE id = ? AND status != 'disconnected'"
            );
            $stmt->execute([$accountId]);
        } else {
            $stmt = $db->prepare(
                "SELECT id, nickname FROM ml_accounts WHERE status != 'disconnected' ORDER BY id"
            );
            $stmt->execute();
        }

        $accounts = $stmt->fetchAll();
        $db = null; // libera conexão

        if (empty($accounts)) {
            logInfo($logger, 'Nenhuma conta ativa encontrada', [], $verbose);
        } else {
            $total   = count($accounts);
            $success = 0;
            $failed  = 0;

            logInfo($logger, "Iniciando ciclo para {$total} conta(s)", [], $verbose);

            foreach ($accounts as $account) {
                $ok = syncAccountQuestions(
                    (int) $account['id'],
                    (string) $account['nickname'],
                    $limit,
                    $logger,
                    $verbose
                );
                $ok ? $success++ : $failed++;
            }

            $elapsed = round(microtime(true) - $cycleStart, 2);
            logInfo($logger, "Ciclo concluído", [
                'total'   => $total,
                'success' => $success,
                'failed'  => $failed,
                'elapsed' => "{$elapsed}s",
            ], true);
        }
    } catch (\Throwable $e) {
        logError($logger, 'Erro no ciclo principal', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], true);
    }

    if (!$runOnce) {
        sleep(120); // 2 minutos entre ciclos para perguntas (resposta rápida)
    }
} while (!$runOnce);

logInfo($logger, 'Questions Sync Worker encerrado', [], true);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);
