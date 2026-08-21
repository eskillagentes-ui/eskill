#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Database;
use App\Services\Agents\AgentRuntimeAccountSource;
use App\Services\Agents\AgentRuntimeExecutor;
use App\Services\Agents\AgentRuntimeWorker;
use App\Services\Agents\PregaoAgentRuntimeReporter;
use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoHojeQueueService;

const AGENT_WORKER_USAGE = 'usage: php bin/agent-runtime-worker.php --once|--loop --environment=local|staging|production [--interval=SECONDS] [--max-attempts=1|2|3]';

/** @return never */
function agentWorkerInvalidArguments(): void
{
    fwrite(STDERR, "invalid arguments\n" . AGENT_WORKER_USAGE . "\n");
    exit(64);
}

/** @return array{loop: bool, environment: string, interval: int, maxAttempts: int} */
function agentWorkerParseArguments(array $arguments): array
{
    $flags = [];
    $values = [];
    foreach ($arguments as $argument) {
        if ($argument === '--help') {
            fwrite(STDOUT, AGENT_WORKER_USAGE . "\n");
            exit(0);
        }
        if (in_array($argument, ['--once', '--loop'], true)) {
            if (isset($flags[$argument])) {
                agentWorkerInvalidArguments();
            }
            $flags[$argument] = true;
            continue;
        }
        if (!is_string($argument)
            || preg_match('/^--([a-z-]+)=([^=]+)$/D', $argument, $matches) !== 1
            || !in_array($matches[1], ['environment', 'interval', 'max-attempts'], true)
            || array_key_exists($matches[1], $values)
        ) {
            agentWorkerInvalidArguments();
        }
        $values[$matches[1]] = $matches[2];
    }

    if (isset($flags['--once']) === isset($flags['--loop'])
        || !isset($values['environment'])
        || !in_array($values['environment'], ['local', 'staging', 'production'], true)
    ) {
        agentWorkerInvalidArguments();
    }

    $intervalRaw = $values['interval'] ?? '300';
    $attemptsRaw = $values['max-attempts'] ?? '2';
    if (preg_match('/^[1-9][0-9]*$/D', $intervalRaw) !== 1
        || preg_match('/^[1-3]$/D', $attemptsRaw) !== 1
    ) {
        agentWorkerInvalidArguments();
    }
    $interval = (int) $intervalRaw;
    if ($interval < 60 || $interval > 3600) {
        agentWorkerInvalidArguments();
    }

    return [
        'loop' => isset($flags['--loop']),
        'environment' => $values['environment'],
        'interval' => $interval,
        'maxAttempts' => (int) $attemptsRaw,
    ];
}

/** @param array<string, mixed> $payload */
function agentWorkerEmit(array $payload, bool $stderr = false): void
{
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
    fwrite($stderr ? STDERR : STDOUT, ($line === false ? '{"status":"failed"}' : $line) . "\n");
}

/** @param list<array<string, mixed>> $records */
function agentWorkerHeartbeat(string $path, string $cycleId, array $records, string $status): void
{
    $success = count(array_filter($records, static fn (array $record): bool => $record['status'] === 'success'));
    $blocked = count(array_filter($records, static fn (array $record): bool => $record['status'] === 'blocked'));
    $openQueues = [];
    foreach ($records as $record) {
        $queues = $record['queues'] ?? null;
        if (!is_array($queues) || ($queues['refreshed'] ?? false) !== true) {
            continue;
        }
        $openQueues[(string) $record['accountId']] = $queues['open'] ?? [];
    }
    $payload = [
        'updatedAt' => gmdate('c'),
        'cycleId' => $cycleId,
        'status' => $status,
        'accounts' => count($records),
        'success' => $success,
        'blocked' => $blocked,
        'failed' => count($records) - $success - $blocked,
        'observe_queues' => $openQueues,
        'ml_write' => false,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('heartbeat_encode_failed');
    }

    $temporary = $path . '.tmp.' . getmypid();
    if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('heartbeat_write_failed');
    }
}

$options = agentWorkerParseArguments(array_slice($argv, 1));
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/autoload.php';
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$lockHandle = @fopen($root . '/storage/agent-runtime-monitor.lock', 'c');
if ($lockHandle === false) {
    agentWorkerEmit(['type' => 'worker', 'status' => 'failed', 'reason' => 'lock_open_failed'], true);
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    agentWorkerEmit(['type' => 'worker', 'status' => 'blocked', 'reason' => 'worker_already_running'], true);
    fclose($lockHandle);
    exit(75);
}

$running = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

$heartbeatPath = $root . '/storage/agent-runtime-heartbeat.json';
$exitCode = 0;
try {
    $db = Database::getInstance();
    $worker = new AgentRuntimeWorker(
        new AgentRuntimeAccountSource($db),
        new AgentRuntimeExecutor(),
        new PregaoAgentRuntimeReporter(new PregaoEmitService($db)),
        new PregaoHojeQueueService($db)
    );

    do {
        $cycleId = 'agent24x7-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        $records = [];
        $cycleStatus = 'idle';
        try {
            $records = $worker->runCycle(
                $options['environment'],
                $cycleId,
                $options['maxAttempts']
            );
            foreach ($records as $record) {
                agentWorkerEmit(['type' => 'account'] + $record);
            }
            $statuses = array_column($records, 'status');
            $cycleStatus = in_array('failed', $statuses, true)
                ? 'failed'
                : (in_array('blocked', $statuses, true) ? 'blocked' : ($records === [] ? 'idle' : 'success'));
        } catch (Throwable) {
            $cycleStatus = 'failed';
            agentWorkerEmit([
                'type' => 'cycle', 'cycleId' => $cycleId,
                'status' => 'failed', 'reason' => 'cycle_exception',
            ], true);
        }

        try {
            agentWorkerHeartbeat($heartbeatPath, $cycleId, $records, $cycleStatus);
        } catch (Throwable) {
            $cycleStatus = 'failed';
            agentWorkerEmit([
                'type' => 'heartbeat', 'cycleId' => $cycleId,
                'status' => 'failed', 'reason' => 'heartbeat_exception',
            ], true);
        }
        agentWorkerEmit([
            'type' => 'cycle', 'cycleId' => $cycleId,
            'status' => $cycleStatus, 'accounts' => count($records),
        ]);
        if ($cycleStatus === 'failed') {
            $exitCode = 1;
        }

        if ($options['loop'] && $running) {
            sleep($options['interval']);
        }
    } while ($options['loop'] && $running);
} catch (Throwable) {
    $exitCode = 1;
    agentWorkerEmit(['type' => 'worker', 'status' => 'failed', 'reason' => 'worker_exception'], true);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit($exitCode);
