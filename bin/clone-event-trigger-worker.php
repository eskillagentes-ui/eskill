#!/usr/bin/env php
<?php

/**
 * Clone Event Trigger Worker
 *
 * Monitora e processa triggers de eventos para clonagem automática
 *
 * Uso:
 *   php bin/clone-event-trigger-worker.php [options]
 *
 * Opções:
 *   --once           Executa uma vez e encerra
 *   --dry-run        Simula sem executar ações
 *   --trigger=ID     Processa apenas trigger específico
 *   --interval=30    Intervalo entre ciclos (segundos)
 *   --verbose        Output detalhado
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\Services\CloneEventTriggerService;
use App\Services\CloneSlackDiscordNotificationService;
use App\Database;

// Configurações
$options = getopt('', ['once', 'dry-run', 'trigger:', 'interval:', 'verbose', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Clone Event Trigger Worker
==========================

Monitora sellers/categorias e executa ações quando eventos são detectados.

Uso:
  php bin/clone-event-trigger-worker.php [options]

Opções:
  --once            Executa uma vez e encerra
  --dry-run         Simula sem executar ações
  --trigger=ID      Processa apenas trigger específico
  --interval=30     Intervalo entre ciclos em segundos (padrão: 60)
  --verbose         Output detalhado
  --help            Exibe esta ajuda

Eventos Suportados:
  - new_items         Novos itens de um seller
  - price_drop        Queda de preço acima do threshold
  - stock_available   Itens com estoque disponível
  - competitor_out    Concorrente sem estoque

Exemplos:
  php bin/clone-event-trigger-worker.php --once
  php bin/clone-event-trigger-worker.php --trigger=TRG12345678
  php bin/clone-event-trigger-worker.php --dry-run --verbose

HELP;
    exit(0);
}

$runOnce = isset($options['once']);
$dryRun = isset($options['dry-run']);
$specificTrigger = $options['trigger'] ?? null;
$interval = (int) ($options['interval'] ?? 60);
$verbose = isset($options['verbose']);

// Lock file para evitar execuções paralelas
$lockFile = sys_get_temp_dir() . '/clone-event-trigger-worker.lock';

if (!$runOnce && file_exists($lockFile)) {
    $pid = (int) file_get_contents($lockFile);
    if (posix_kill($pid, 0)) {
        logMessage("Worker já em execução (PID: {$pid})", 'WARNING');
        exit(1);
    }
}

file_put_contents($lockFile, getmypid());
register_shutdown_function(function () use ($lockFile) {
    @unlink($lockFile);
});

// Signal handlers
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use ($lockFile) {
        logMessage("Recebido SIGTERM, encerrando...", 'INFO');
        @unlink($lockFile);
        exit(0);
    });
    pcntl_signal(SIGINT, function () use ($lockFile) {
        logMessage("Recebido SIGINT, encerrando...", 'INFO');
        @unlink($lockFile);
        exit(0);
    });
}

logMessage("=== Clone Event Trigger Worker Iniciado ===", 'INFO');
logMessage("Modo: " . ($runOnce ? 'Execução única' : 'Daemon'), 'INFO');
if ($dryRun) {
    logMessage("DRY-RUN: Nenhuma ação será executada", 'WARNING');
}
if ($specificTrigger) {
    logMessage("Trigger específico: {$specificTrigger}", 'INFO');
}

$db = Database::getInstance();
$totalProcessed = 0;
$totalEvents = 0;
$startTime = time();

do {
    try {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        $cycleStart = microtime(true);
        $triggers = getTriggers($db, $specificTrigger);

        if (empty($triggers)) {
            if ($verbose) {
                logMessage("Nenhum trigger pendente", 'DEBUG');
            }
        } else {
            logMessage("Processando " . count($triggers) . " trigger(s)...", 'INFO');

            foreach ($triggers as $trigger) {
                $result = processTrigger($trigger, $dryRun, $verbose);
                $totalProcessed++;
                $totalEvents += $result['events_detected'];

                if ($result['events_detected'] > 0) {
                    logMessage(sprintf(
                        "Trigger %s: %d evento(s), %d ação(ões)",
                        $trigger['trigger_id'],
                        $result['events_detected'],
                        $result['actions_executed']
                    ), 'SUCCESS');
                }
            }
        }

        $cycleDuration = round(microtime(true) - $cycleStart, 2);

        if ($verbose) {
            logMessage("Ciclo completado em {$cycleDuration}s", 'DEBUG');
        }

        if (!$runOnce) {
            sleep($interval);
        }
    } catch (\Exception $e) {
        logMessage("Erro no ciclo: " . $e->getMessage(), 'ERROR');

        // Notificar erro crítico
        try {
            notifyError($e->getMessage());
        } catch (\Exception $ne) {
            // Silently fail
        }

        if (!$runOnce) {
            sleep($interval * 2); // Esperar mais em caso de erro
        }
    }
} while (!$runOnce);

// Relatório final
$runtime = time() - $startTime;
logMessage("=== Worker Encerrado ===", 'INFO');
logMessage("Triggers processados: {$totalProcessed}", 'INFO');
logMessage("Eventos detectados: {$totalEvents}", 'INFO');
logMessage("Tempo de execução: {$runtime}s", 'INFO');

exit(0);

// ============================================================================
// Funções Auxiliares
// ============================================================================

/**
 * Obtém triggers pendentes
 */
function getTriggers(PDO $db, ?string $specificTrigger): array
{
    if ($specificTrigger) {
        $stmt = $db->prepare("
            SELECT * FROM clone_event_triggers
            WHERE trigger_id = :trigger_id AND is_active = 1
        ");
        $stmt->execute(['trigger_id' => $specificTrigger]);
        $trigger = $stmt->fetch(PDO::FETCH_ASSOC);
        return $trigger ? [$trigger] : [];
    }

    $stmt = $db->prepare("
        SELECT * FROM clone_event_triggers
        WHERE is_active = 1
        AND (
            last_check_at IS NULL
            OR last_check_at < DATE_SUB(NOW(), INTERVAL check_interval_minutes MINUTE)
        )
        ORDER BY last_check_at ASC
        LIMIT 10
    ");
    $stmt->execute();

    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($triggers as &$trigger) {
        $trigger['conditions'] = json_decode($trigger['conditions'] ?? '[]', true);
        $trigger['actions'] = json_decode($trigger['actions'] ?? '[]', true);
    }

    return $triggers;
}

/**
 * Processa um trigger
 */
function processTrigger(array $trigger, bool $dryRun, bool $verbose): array
{
    $accountId = (int) $trigger['account_id'];
    $service = new CloneEventTriggerService($accountId);

    if ($verbose) {
        logMessage("Processando: {$trigger['name']} ({$trigger['event_type']})", 'DEBUG');
    }

    if ($dryRun) {
        // Simular detecção de eventos sem executar
        return [
            'trigger_id' => $trigger['trigger_id'],
            'events_detected' => 0,
            'actions_executed' => 0,
            'dry_run' => true,
        ];
    }

    return $service->processTrigger($trigger);
}

/**
 * Notifica erro crítico
 */
function notifyError(string $message): void
{
    // Tentar enviar notificação via Slack/Discord
    try {
        $stmt = Database::getInstance()->query("
            SELECT id FROM ml_accounts WHERE status = 'active' LIMIT 1
        ");
        $accountId = $stmt->fetchColumn();

        if ($accountId) {
            $notifier = new CloneSlackDiscordNotificationService((int) $accountId);
            $notifier->sendSlackNotification('worker_error', [
                'worker' => 'clone-event-trigger-worker',
                'error' => $message,
                'time' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (\Exception $e) {
        // Ignore notification errors
    }
}

/**
 * Log formatado
 */
function logMessage(string $message, string $level = 'INFO'): void
{
    $timestamp = date('Y-m-d H:i:s');
    $colors = [
        'INFO' => "\033[0;36m",    // Cyan
        'SUCCESS' => "\033[0;32m", // Green
        'WARNING' => "\033[0;33m", // Yellow
        'ERROR' => "\033[0;31m",   // Red
        'DEBUG' => "\033[0;37m",   // Light gray
    ];
    $reset = "\033[0m";
    $color = $colors[$level] ?? $colors['INFO'];

    $output = "[{$timestamp}] {$color}[{$level}]{$reset} {$message}";

    if (php_sapi_name() === 'cli') {
        echo $output . PHP_EOL;
    }

    // Log em arquivo
    $logFile = __DIR__ . '/../storage/logs/clone-event-trigger.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    file_put_contents(
        $logFile,
        "[{$timestamp}] [{$level}] {$message}" . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
