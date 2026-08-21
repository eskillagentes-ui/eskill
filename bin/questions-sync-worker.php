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
 *   --limit=N       Limite de perguntas recentes por conta (padrão: 200; unanswered pagina até 200+)
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
  --limit=N       Limite de perguntas recentes por conta (padrão: 200)
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
$limit      = isset($options['limit']) ? (int) $options['limit'] : 200;
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

        $synced    = is_array($result) ? (int) ($result['synced'] ?? $result['count'] ?? 0) : 0;
        $errors    = is_array($result) ? (int) ($result['errors'] ?? 0) : 0;
        $forbidden = is_array($result) && !empty($result['forbidden']);
        $lastError = is_array($result) ? (string) ($result['last_error'] ?? '') : '';

        // Always log per-account so 403/empty unanswered is visible without --verbose.
        logInfo($logger, 'Perguntas sincronizadas', [
            'account_id'           => $mlAccountId,
            'nickname'             => $nickname,
            'synced'               => $synced,
            'errors'               => $errors,
            'forbidden'            => $forbidden,
            'pages'                => is_array($result) ? ($result['pages'] ?? 0) : 0,
            'unanswered_fetched'   => is_array($result) ? ($result['unanswered_fetched'] ?? 0) : 0,
            'recent_fetched'       => is_array($result) ? ($result['recent_fetched'] ?? 0) : 0,
        ], true);

        if ($lastError !== '') {
            logError($logger, 'Sync perguntas fail-soft', [
                'account_id' => $mlAccountId,
                'nickname'   => $nickname,
                'error'      => $lastError,
                'forbidden'  => $forbidden,
            ], true);
        }

        // 403 on GET must not fail the worker (no exit 2). Continue other accounts.
        return true;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $forbidden = str_contains(strtolower($msg), 'forbidden') || str_contains($msg, 'HTTP 403');
        logError($logger, 'Erro ao sincronizar perguntas', [
            'account_id' => $mlAccountId,
            'nickname'   => $nickname,
            'error'      => $msg,
            'forbidden'  => $forbidden,
        ], true);
        // Fail-soft: 403/token holes do not abort the cycle.
        return !$forbidden ? false : true;
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

        // Active accounts with tokens only — never last-active-only, never mix ids.
        $tokenSql = "status != 'disconnected' AND access_token IS NOT NULL AND TRIM(access_token) != ''";
        if ($accountId !== null) {
            $stmt = $db->prepare(
                "SELECT id, nickname FROM ml_accounts WHERE id = ? AND {$tokenSql}"
            );
            $stmt->execute([$accountId]);
        } else {
            $stmt = $db->prepare(
                "SELECT id, nickname FROM ml_accounts WHERE {$tokenSql} ORDER BY id"
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
// Always 0: 403/fail-soft on a single account must not exit 2 for cron.
exit(0);
