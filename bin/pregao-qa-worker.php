#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use App\Services\Pregao\PregaoQaSessionService;
use App\Services\Pregao\PregaoQaStatusProducer;
use App\Services\Pregao\PregaoQaWorkerEnvironment;
use App\Services\Pregao\PregaoQaWorkerProcess;
use App\Services\Pregao\PregaoQaWorkerProtocol;
use App\Services\Pregao\PregaoQaWorkerSignals;

const BROWSER_TIMEOUT_SECONDS = 120;
const LOCK_RENEW_INTERVAL_SECONDS = 30;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$proof = PregaoQaProof::fromEnvironment();
if ($proof === null) {
    fwrite(STDERR, "pregao-qa-worker: assinatura indisponível\n");
    exit(1);
}

$baseUrl = (string) ($_ENV['PREGAO_QA_BASE_URL'] ?? getenv('PREGAO_QA_BASE_URL') ?: '');
$baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
$baseScheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
if (!in_array($baseScheme, ['http', 'https'], true)
    || $baseHost === ''
    || (in_array($baseHost, ['eskill.com.br', 'www.eskill.com.br'], true)
        && (string) ($_ENV['PREGAO_QA_ALLOW_PRODUCTION_READONLY'] ?? getenv('PREGAO_QA_ALLOW_PRODUCTION_READONLY') ?: '') !== 'true')
) {
    fwrite(STDERR, "pregao-qa-worker: PREGAO_QA_BASE_URL inválida ou produção sem gate read-only\n");
    exit(1);
}
if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
    fwrite(STDERR, "pregao-qa-worker: suporte a sinais indisponível\n");
    exit(1);
}

$privateRoot = (string) ($_ENV['PREGAO_QA_PRIVATE_ROOT'] ?? getenv('PREGAO_QA_PRIVATE_ROOT') ?: '');
if ($privateRoot === '') {
    $privateRoot = $root . '/storage/private/pregao-qa';
}
if (!is_dir($privateRoot) && !mkdir($privateRoot, 0700, true) && !is_dir($privateRoot)) {
    fwrite(STDERR, "pregao-qa-worker: diretório privado indisponível\n");
    exit(1);
}

$redis = PregaoQaRunService::connectRedis();
$runs = new PregaoQaRunService($redis, $proof);
$producer = new PregaoQaStatusProducer(new PregaoEmitService(null, $redis), $proof, $runs);
PregaoQaRunService::purgeExpiredFrames($privateRoot);
$runs->recoverPending(
    static fn (array $manifest, array $state): bool => $producer->repairEvidence($manifest, $state)
);
$claim = $runs->claimNext();
if ($claim === null) {
    exit(0);
}

$manifest = $claim['manifest'];
$runId = $manifest['run_id'];
$lockToken = $claim['lock_token'];
$pendingJob = $claim['pending_job'];
$sessionPrefix = (string) ($_ENV['PREGAO_QA_SESSION_PREFIX'] ?? 'PHPREDIS_SESSION:');
$sessions = new PregaoQaSessionService($redis, $sessionPrefix);
$sessionId = '';
$process = null;
$pipes = [];
$previousSequence = 0;
$previousResult = null;
$terminalResult = null;
$workerExitCode = 0;
$shutdownRequested = false;
$preservePending = false;
$nextLockRenewalAt = microtime(true) + LOCK_RENEW_INTERVAL_SECONDS;
$signals = new PregaoQaWorkerSignals();
$signals->install();

try {
    if (!$runs->renew($runId, $lockToken)) {
        throw new RuntimeException('qa_worker_lock_lost');
    }
    $existingState = $runs->loadState($runId, (int) $manifest['account_id']);
    if (!is_array($existingState)
        || !is_int($existingState['sequence'] ?? null)
        || !is_string($existingState['status'] ?? null)
    ) {
        throw new RuntimeException('qa_state_unavailable');
    }
    $previousSequence = $existingState['sequence'];
    $previousResult = $existingState['status'] === 'queued' ? null : $existingState['status'];
    if ($previousSequence > 0) {
        throw new RuntimeException('qa_recovered_after_worker_crash');
    }
    $sessionId = $sessions->create(
        $manifest['user_id'],
        $manifest['account_id'],
        PregaoQaRunService::RUN_TTL_SECONDS,
        $runId
    );
    $outputDirectory = dirname(PregaoQaRunService::framePath($privateRoot, $runId));
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0700, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('qa_output_unavailable');
    }

    $runner = $root . '/bin/pregao-qa-browser.mjs';
    if (!is_file($runner)) {
        throw new RuntimeException('qa_browser_runner_unavailable');
    }
    $command = ['node', $runner];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $hostEnvironment = getenv();
    if (!is_array($hostEnvironment)) {
        $hostEnvironment = [];
    }
    $hostEnvironment = [...$hostEnvironment, ...$_ENV];
    $environment = PregaoQaWorkerEnvironment::build($hostEnvironment, [
        'PREGAO_QA_RUN_ID' => $runId,
        'PREGAO_QA_BASE_URL' => $baseUrl,
        'PREGAO_QA_OUTPUT_DIR' => $outputDirectory,
        'PREGAO_QA_SESSION_COOKIE' => 'PHPSESSID=' . $sessionId,
        'PREGAO_QA_ACCOUNT_ID' => (string) $manifest['account_id'],
    ]);
    $process = proc_open($command, $descriptors, $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('qa_browser_start_failed');
    }
    $signals->track($process);
    fclose($pipes[0]);
    unset($pipes[0]);

    $processResult = PregaoQaWorkerProcess::drain(
        $process,
        $pipes[1],
        $pipes[2],
        function (string $line) use (
            $runId,
            $manifest,
            $runs,
            $producer,
            $outputDirectory,
            $privateRoot,
            &$previousSequence,
            &$previousResult,
            &$terminalResult
        ): void {
            $protocol = PregaoQaWorkerProtocol::decode($line, $runId, $previousSequence, $previousResult);
            if ($protocol === null) {
                throw new RuntimeException('qa_browser_protocol_invalid');
            }
            $previousSequence = $protocol['sequence'];
            $previousResult = $protocol['result'];
            if ($protocol['screenshot'] === 'latest.png') {
                PregaoQaRunService::retainLatestFrame(
                    $outputDirectory . '/latest.png',
                    $privateRoot,
                    $runId
                );
            }
            $runs->updateState($manifest, $protocol);
            $producer->emit($manifest, $protocol);
            if (in_array($protocol['result'], ['passed', 'failed', 'blocked'], true)) {
                $terminalResult = $protocol['result'];
            }
        },
        function () use ($runs, $runId, $lockToken, &$nextLockRenewalAt): void {
            if (microtime(true) < $nextLockRenewalAt) {
                return;
            }
            if (!$runs->renew($runId, $lockToken)) {
                throw new RuntimeException('qa_worker_lock_lost');
            }
            $nextLockRenewalAt = microtime(true) + LOCK_RENEW_INTERVAL_SECONDS;
        },
        static function () use ($signals): bool {
            return $signals->isRequested();
        },
        BROWSER_TIMEOUT_SECONDS
    );
    $process = null;
    $pipes = [];
    if ($processResult['exit_code'] !== 0 || $terminalResult === null) {
        log_error('Pregao QA browser failed', [
            'reason' => 'qa_browser_failed',
            'run_id' => $runId,
            'exit_code' => $processResult['exit_code'],
            'stderr_present' => $processResult['stderr_present'],
        ]);
        throw new RuntimeException('qa_browser_failed');
    }
} catch (Throwable) {
    $shutdownRequested = $signals->isRequested();
    $preservePending = $shutdownRequested && $terminalResult === null;
    if (!$preservePending && $terminalResult === null) {
        try {
            $blocked = [
                'run_id' => $runId,
                'sequence' => $previousSequence + 1,
                'step' => PregaoQaWorkerProtocol::STEPS[$previousSequence] ?? 'console_http',
                'result' => 'blocked',
                'screenshot' => null,
                'cursor' => null,
                'observed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
            $runs->updateState($manifest, $blocked);
            $producer->emit($manifest, $blocked);
            $terminalResult = 'blocked';
        } catch (Throwable) {
            log_error('Pregao QA blocked status emit failed', [
                'reason' => 'qa_blocked_status_emit_failed',
                'run_id' => $runId,
            ]);
        }
    }
    log_error('Pregao QA worker failed', [
        'reason' => $preservePending ? 'qa_worker_shutdown_requested' : 'qa_worker_failed',
        'run_id' => $runId,
    ]);
    $workerExitCode = $preservePending ? 0 : 1;
} finally {
    PregaoQaWorkerProcess::terminate($process);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        proc_close($process);
    }
    if ($sessionId !== '') {
        $sessions->destroy($sessionId);
    }
    if (!$preservePending && $terminalResult !== null) {
        $runs->releaseActive($manifest['account_id'], $runId);
        if (!$runs->ackPending($runId, $pendingJob)) {
            log_error('Pregao QA pending ack failed', ['reason' => 'qa_pending_ack_failed', 'run_id' => $runId]);
            $workerExitCode = 1;
        }
    }
    $runs->release($runId, $lockToken);
}

exit($workerExitCode);
