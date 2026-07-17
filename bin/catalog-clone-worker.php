#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Catalog Clone Worker
 *
 * Processor especializado para jobs de clonagem (type='catalog_clone_item')
 * da tabela 'jobs'.
 *
 * Uso:
 *   php bin/catalog-clone-worker.php
 *   php bin/catalog-clone-worker.php --once
 *   php bin/catalog-clone-worker.php --verbose
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

\App\Helpers\MlWriteAutomation::exitIfDisabledForCli(basename(__FILE__));

use App\Database;
use App\Services\CloneNotificationService;
use App\Services\JobService;
use App\Services\StructuredLogService;

// Setup
define('WORKER_NAME', 'catalog-clone-worker');
define('LOG_FILE', __DIR__ . '/../storage/logs/catalog-clone-worker.log');
define('LOCK_FILE', __DIR__ . '/../storage/locks/catalog-clone-worker.lock');

// Options
$options = getopt('', ['once', 'job:', 'recover-stuck', 'dry-run', 'help', 'verbose']);

if (isset($options['help'])) {
    echo "Usage: php bin/catalog-clone-worker.php [--once] [--job=ID] [--verbose] [--recover-stuck]\n";
    exit(0);
}

if (isset($options['recover-stuck'])) {
    $db = Database::getInstance();
    $stmt = $db->query("UPDATE jobs SET status='pending', attempts = attempts + 1 WHERE status='processing' AND type='catalog_clone_item' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    echo "Recovered stuck jobs: " . $stmt->rowCount() . "\n";
    exit(0);
}

$runOnce = isset($options['once']);
$specificJobId = $options['job'] ?? null;
$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

// ─── Flock: prevenir execução concorrente ─────────────────────────────────────
$lockDir = dirname(LOCK_FILE);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockHandle = fopen(LOCK_FILE, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[catalog-clone-worker] Outra instância já está em execução — saindo\n";
    if ($lockHandle !== false) {
        fclose($lockHandle);
    }
    exit(0);
}

// ─── Logging estruturado ─────────────────────────────────────────────────────
// Mantém logs do worker separados.
putenv('LOG_PATH=' . LOG_FILE);
$logger = new StructuredLogService();

function logMessage(string $msg, string $level = 'info', array $context = []): void
{
    global $verbose, $logger;

    $level = strtolower($level);
    $context = array_merge(['worker' => WORKER_NAME], $context);

    try {
        if (method_exists($logger, $level)) {
            $logger->{$level}($msg, $context);
        } else {
            $logger->info($msg, $context);
        }
    } catch (Throwable $e) {
        // Best-effort fallback
        error_log("[" . WORKER_NAME . "] log failure: " . $e->getMessage());
    }

    if ($verbose || in_array($level, ['error', 'critical'], true)) {
        $ts = date('Y-m-d H:i:s');
        echo "[{$ts}] [" . strtoupper($level) . "] {$msg}\n";
    }
}

try {
    $db = Database::getInstance();
    $jobService = new JobService();

    logMessage('Worker iniciado', 'info', ['dry_run' => $dryRun, 'once' => $runOnce, 'job' => $specificJobId]);

    while (true) {
        $job = null;

        // 1. Fetch Job
        if ($specificJobId) {
            $stmt = $db->prepare("SELECT * FROM jobs WHERE id = :id AND type = 'catalog_clone_item'");
            $stmt->execute(['id' => $specificJobId]);
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);
        } else {
            // Fetch pending catalog_clone_item
            // Priority to older jobs
            $stmt = $db->query("SELECT * FROM jobs WHERE type = 'catalog_clone_item' AND status = 'pending' AND (scheduled_at IS NULL OR scheduled_at <= NOW()) ORDER BY created_at ASC LIMIT 1");
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$job) {
            if ($runOnce) {
                logMessage("Nenhum job encontrado. Encerrando (--once).");
                break;
            }
            if ($verbose) echo ".";
            sleep(5);
            continue;
        }

        logMessage('Job encontrado', 'info', ['job_id' => (int)$job['id'], 'type' => $job['type'] ?? null]);

        if ($dryRun) {
            $payloadPreview = $job['payload'] ?? '';
            if (is_string($payloadPreview) && strlen($payloadPreview) > 500) {
                $payloadPreview = substr($payloadPreview, 0, 500) . '...';
            }
            logMessage('Dry-run: não processando job', 'warning', [
                'job_id' => (int)$job['id'],
                'status' => $job['status'] ?? null,
                'attempts' => $job['attempts'] ?? null,
                'payload_preview' => $payloadPreview,
            ]);
            break;
        }

        // Obter account_id do job
        $accountId = (int)($job['account_id'] ?? 0);
        $notifier = $accountId > 0 ? new CloneNotificationService($accountId) : null;

        // Notificar início (apenas se houver notifier configurado)
        $jobPayload = json_decode($job['payload'] ?? '{}', true);
        if (!is_array($jobPayload)) {
            $jobPayload = [];
        }
        if ($notifier) {
            try {
                $notifier->notifyJobStarted((int)$job['id'], [
                    'total_items' => count($jobPayload['item_ids'] ?? []),
                    'source_type' => $jobPayload['source_type'] ?? 'items',
                    'target_account' => $jobPayload['target_account_id'] ?? null,
                ]);
            } catch (Exception $e) {
                logMessage('Erro ao notificar início', 'warning', ['job_id' => (int)$job['id'], 'error' => $e->getMessage()]);
            }
        }

        // 2. Process via JobService
        try {
            // JobService handles status updates
            $result = $jobService->processJob($job);

            if ($result['status'] === 'completed') {
                logMessage('Job concluído com sucesso', 'info', ['job_id' => (int)$job['id']]);

                // Notificar conclusão
                if ($notifier) {
                    try {
                        $notifier->notifyJobCompleted((int)$job['id'], [
                            'total' => $result['total'] ?? count($jobPayload['item_ids'] ?? []),
                            'success' => $result['success'] ?? $result['total'] ?? 0,
                            'failed' => $result['failed'] ?? 0,
                            'duration' => $result['duration'] ?? 'N/A',
                        ]);
                    } catch (Exception $e) {
                        logMessage('Erro ao notificar conclusão', 'warning', ['job_id' => (int)$job['id'], 'error' => $e->getMessage()]);
                    }
                }
            } else {
                logMessage('Job falhou/retentativa', 'error', ['job_id' => (int)$job['id'], 'error' => ($result['error'] ?? 'Unknown')]);

                // Notificar falha
                if ($notifier) {
                    try {
                        $notifier->notifyJobFailed((int)$job['id'], $result['error'] ?? 'Unknown error');
                    } catch (Exception $e) {
                        logMessage('Erro ao notificar falha', 'warning', ['job_id' => (int)$job['id'], 'error' => $e->getMessage()]);
                    }
                }
            }
        } catch (Exception $e) {
            logMessage('Exception ao processar job', 'error', ['job_id' => (int)$job['id'], 'error' => $e->getMessage()]);

            // Notificar exceção
            if ($notifier) {
                try {
                    $notifier->notifyJobFailed((int)$job['id'], $e->getMessage());
                } catch (Exception $notifyEx) {
                    // Ignora
                }
            }
        }

        // Rate limit: 0.5s pause
        usleep(500000);

        if ($runOnce) break;
    }
} catch (Exception $e) {
    logMessage('Fatal error', 'critical', ['error' => $e->getMessage()]);
    exit(1);
} finally {
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
