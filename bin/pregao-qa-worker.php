#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Pregao\PregaoEmitService;
use App\Services\Pregao\PregaoQaProof;
use App\Services\Pregao\PregaoQaRunService;
use App\Services\Pregao\PregaoQaSessionService;
use App\Services\Pregao\PregaoQaStatusProducer;
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
$claim = $runs->claimNext();
if ($claim === null) {
    exit(0);
}

$manifest = $claim['manifest'];
$runId = $manifest['run_id'];
$lockToken = $claim['lock_token'];
$sessionPrefix = (string) ($_ENV['PREGAO_QA_SESSION_PREFIX'] ?? 'PHPREDIS_SESSION:');
$sessions = new PregaoQaSessionService($redis, $sessionPrefix);
$sessionId = '';
$process = null;
$producer = new PregaoQaStatusProducer(new PregaoEmitService(null, $redis), $proof);
$previousSequence = 0;
$terminalResult = null;
$privateRoot = $root . '/storage/private/pregao-qa';

try {
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
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment = array_merge($environment, $_ENV);
    $environment['PREGAO_QA_RUN_ID'] = $runId;
    $environment['PREGAO_QA_BASE_URL'] = $baseUrl;
    $environment['PREGAO_QA_OUTPUT_DIR'] = $outputDirectory;
    $environment['PREGAO_QA_SESSION_COOKIE'] = 'PHPSESSID=' . $sessionId;
    $process = proc_open($command, $descriptors, $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('qa_browser_start_failed');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], true);
    stream_set_timeout($pipes[1], 120);
    stream_set_blocking($pipes[2], false);

    while (($line = fgets($pipes[1])) !== false) {
        $protocol = PregaoQaWorkerProtocol::decode($line, $runId, $previousSequence);
        if ($protocol === null) {
            proc_terminate($process);
            throw new RuntimeException('qa_browser_protocol_invalid');
        }
        $previousSequence = $protocol['sequence'];
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
                'step' => 'console_http',
                'result' => 'blocked',
                'screenshot' => null,
                'cursor' => null,
                'observed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
            $runs->updateState($manifest, $blocked);
            $producer->emit($manifest, $blocked);
        } catch (Throwable) {
            log_error('Pregao QA blocked status emit failed', [
                'reason' => 'qa_blocked_status_emit_failed',
                'run_id' => $runId,
            ]);
        }
    }
    log_error('Pregao QA worker failed', ['reason' => 'qa_worker_failed', 'run_id' => $runId]);
    exit(1);
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($sessionId !== '') {
        $sessions->destroy($sessionId);
    }
    $runs->release($runId, $lockToken);
    $runs->releaseActive($manifest['account_id'], $runId);
}

exit(0);
