#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use App\Services\Pregao\PregaoQaSessionService;
use App\Services\Pregao\PregaoQaStatusProducer;
use App\Services\Pregao\PregaoQaWorkerEnvironment;
use App\Services\Pregao\PregaoQaWorkerProtocol;

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

$redis = PregaoQaRunService::connectRedis();
$runs = new PregaoQaRunService($redis, $proof);
$producer = new PregaoQaStatusProducer(new PregaoEmitService(null, $redis), $proof, $runs);
$privateRoot = $root . '/storage/private/pregao-qa';
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
$previousSequence = 0;
$previousResult = null;
$terminalResult = null;
$workerExitCode = 0;

try {
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
    $command = [
        'node',
        $runner,
    ];
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
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], true);
    stream_set_timeout($pipes[1], 120);
    stream_set_blocking($pipes[2], false);

    while (($line = fgets($pipes[1])) !== false) {
        $protocol = PregaoQaWorkerProtocol::decode($line, $runId, $previousSequence, $previousResult);
        if ($protocol === null) {
            proc_terminate($process);
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
    }
    $stdoutMeta = stream_get_meta_data($pipes[1]);
    if (($stdoutMeta['timed_out'] ?? false) === true) {
        proc_terminate($process);
        throw new RuntimeException('qa_browser_timeout');
    }
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2], 4096);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $process = null;
    if ($exitCode !== 0 || $terminalResult === null) {
        log_error('Pregao QA browser failed', [
            'reason' => 'qa_browser_failed',
            'run_id' => $runId,
            'exit_code' => $exitCode,
            'stderr_present' => is_string($stderr) && trim($stderr) !== '',
        ]);
        throw new RuntimeException('qa_browser_failed');
    }
} catch (Throwable) {
    if ($terminalResult === null) {
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
    log_error('Pregao QA worker failed', ['reason' => 'qa_worker_failed', 'run_id' => $runId]);
    $workerExitCode = 1;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($sessionId !== '') {
        $sessions->destroy($sessionId);
    }
    if ($terminalResult !== null) {
        $runs->releaseActive($manifest['account_id'], $runId);
        if (!$runs->ackPending($runId, $pendingJob)) {
            log_error('Pregao QA pending ack failed', ['reason' => 'qa_pending_ack_failed', 'run_id' => $runId]);
            $workerExitCode = 1;
        }
    }
    $runs->release($runId, $lockToken);
}

exit($workerExitCode);
