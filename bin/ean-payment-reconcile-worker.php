<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\EanService;

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

$limit = 200;
$minAgeMinutes = 2;
$runOnce = false;
$sleepSeconds = 60;
$retryFailedWebhooks = true;
$retryWebhookLimit = 50;
$lockFile = __DIR__ . '/../storage/cache/locks/ean-payment-reconcile-worker.lock';
$autoHealSafe = false;
$autoHealHoursBack = 72;
$lowRiskRemediation = false;
$lowRiskRemediationDryRun = true;
$lowRiskRollbackOnWorsening = true;
$previewPlanOnly = false;
$previewSaveSnapshot = false;
$previewSource = 'worker_plan';

$knownFlags = [
    '--once',
    '--no-retry-webhooks',
    '--auto-heal-safe',
    '--low-risk-remediation',
    '--low-risk-remediation-apply',
    '--low-risk-no-rollback',
    '--plan',
    '--plan-save',
    '--help',
    '-h',
];

$knownPrefixes = [
    '--limit=',
    '--min-age=',
    '--sleep=',
    '--retry-webhooks-limit=',
    '--lock-file=',
    '--auto-heal-hours-back=',
    '--plan-source=',
];

$printHelp = static function (): void {
    echo "EAN Payment Reconcile Worker\n";
    echo "Uso:\n";
    echo "  php bin/ean-payment-reconcile-worker.php [opções]\n\n";
    echo "Opções:\n";
    echo "  --once                         Executa um único ciclo\n";
    echo "  --limit=N                      Limite de compras por ciclo (1-1000)\n";
    echo "  --min-age=N                    Idade mínima em minutos para reconciliar\n";
    echo "  --sleep=N                      Intervalo entre ciclos em modo daemon\n";
    echo "  --no-retry-webhooks            Desabilita retentativa de webhooks falhos\n";
    echo "  --retry-webhooks-limit=N       Limite de webhooks para retentativa\n";
    echo "  --lock-file=CAMINHO            Arquivo de lock exclusivo\n";
    echo "  --auto-heal-safe               Executa auto-healing seguro\n";
    echo "  --auto-heal-hours-back=N       Janela de divergências (horas)\n";
    echo "  --low-risk-remediation         Executa remediação de baixo risco (dry-run)\n";
    echo "  --low-risk-remediation-apply   Executa remediação de baixo risco (apply)\n";
    echo "  --low-risk-no-rollback         Desabilita rollback automático por piora\n";
    echo "  --plan                         Gera preview operacional sem executar mudanças\n";
    echo "  --plan-save                    Salva snapshot do preview no histórico\n";
    echo "  --plan-source=NOME             Origem do snapshot salvo em --plan\n";
    echo "  --help, -h                     Exibe esta ajuda\n";
};

foreach ($argv as $arg) {
    if ($arg === $argv[0]) {
        continue;
    }

    $isKnown = in_array($arg, $knownFlags, true);
    if (!$isKnown) {
        foreach ($knownPrefixes as $prefix) {
            if (strpos($arg, $prefix) === 0) {
                $isKnown = true;
                break;
            }
        }
    }

    if (!$isKnown) {
        fwrite(STDERR, "Parâmetro inválido: {$arg}\n\n");
        $printHelp();
        exit(2);
    }

    if ($arg === '--help' || $arg === '-h') {
        $printHelp();
        exit(0);
    }

    if ($arg === '--once') {
        $runOnce = true;
    }
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    }
    if (strpos($arg, '--min-age=') === 0) {
        $minAgeMinutes = (int)substr($arg, 10);
    }
    if (strpos($arg, '--sleep=') === 0) {
        $sleepSeconds = (int)substr($arg, 8);
    }
    if ($arg === '--no-retry-webhooks') {
        $retryFailedWebhooks = false;
    }
    if (strpos($arg, '--retry-webhooks-limit=') === 0) {
        $retryWebhookLimit = (int)substr($arg, 23);
    }
    if (strpos($arg, '--lock-file=') === 0) {
        $lockFile = (string)substr($arg, 12);
    }
    if ($arg === '--auto-heal-safe') {
        $autoHealSafe = true;
    }
    if (strpos($arg, '--auto-heal-hours-back=') === 0) {
        $autoHealHoursBack = (int)substr($arg, 23);
    }
    if ($arg === '--low-risk-remediation') {
        $lowRiskRemediation = true;
    }
    if ($arg === '--low-risk-remediation-apply') {
        $lowRiskRemediation = true;
        $lowRiskRemediationDryRun = false;
    }
    if ($arg === '--low-risk-no-rollback') {
        $lowRiskRollbackOnWorsening = false;
    }
    if ($arg === '--plan') {
        $previewPlanOnly = true;
    }
    if ($arg === '--plan-save') {
        $previewSaveSnapshot = true;
    }
    if (strpos($arg, '--plan-source=') === 0) {
        $previewSource = (string)substr($arg, 14);
    }
}

$limit = max(1, min(1000, $limit));
$minAgeMinutes = max(0, min(1440, $minAgeMinutes));
$sleepSeconds = max(5, min(600, $sleepSeconds));
$retryWebhookLimit = max(1, min(500, $retryWebhookLimit));
$autoHealHoursBack = max(1, min(720, $autoHealHoursBack));

$log = static function (string $message): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    echo $line;
};

$log('EAN payment reconcile worker started');
$log('Config => limit=' . $limit
    . ', minAgeMinutes=' . $minAgeMinutes
    . ', once=' . ($runOnce ? 'yes' : 'no')
    . ', retryFailedWebhooks=' . ($retryFailedWebhooks ? 'yes' : 'no')
    . ', retryWebhookLimit=' . $retryWebhookLimit
    . ', autoHealSafe=' . ($autoHealSafe ? 'yes' : 'no')
    . ', autoHealHoursBack=' . $autoHealHoursBack
    . ', lowRiskRemediation=' . ($lowRiskRemediation ? 'yes' : 'no')
    . ', lowRiskRemediationMode=' . ($lowRiskRemediationDryRun ? 'dry_run' : 'apply')
    . ', lowRiskRollbackOnWorsening=' . ($lowRiskRollbackOnWorsening ? 'yes' : 'no')
    . ', previewPlanOnly=' . ($previewPlanOnly ? 'yes' : 'no')
    . ', previewSaveSnapshot=' . ($previewSaveSnapshot ? 'yes' : 'no')
    . ', previewSource=' . $previewSource
    . ', lockFile=' . $lockFile);

$service = new EanService();

if ($previewPlanOnly) {
    try {
        $plan = $service->previewReconciliationPlan($autoHealHoursBack, $limit);
        if ($previewSaveSnapshot) {
            $service->storeReconciliationPreviewSnapshot($plan, $previewSource);
        }
        echo json_encode([
            'success' => true,
            'plan' => $plan,
            'snapshot_saved' => $previewSaveSnapshot,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Falha ao gerar preview: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

$breaker = $service->getOperationalCircuitBreakerStatus();
$breakerOpen = (string)($breaker['state'] ?? 'closed') === 'open';

if ($breakerOpen) {
    $log('CircuitBreaker => OPEN; aplicando modo seguro de execução');
    $autoHealSafe = false;
    $lowRiskRemediation = false;
}

$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}

$lockHandle = @fopen($lockFile, 'c+');
if ($lockHandle === false) {
    $log('Falha ao abrir lock file: ' . $lockFile);
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $runningPid = trim((string)@stream_get_contents($lockHandle));
    $runningPid = $runningPid !== '' ? $runningPid : 'desconhecido';
    $log('Worker já em execução. lock=' . $lockFile . ' pid=' . $runningPid);
    fclose($lockHandle);
    exit(0);
}

ftruncate($lockHandle, 0);
rewind($lockHandle);
fwrite($lockHandle, (string)getmypid());
fflush($lockHandle);

register_shutdown_function(static function () use ($lockHandle): void {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
});

$service->updateReconcileWorkerHeartbeat([
    'state' => 'started',
    'started_at' => date('c'),
    'run_once' => $runOnce,
    'limit' => $limit,
    'min_age_minutes' => $minAgeMinutes,
    'retry_failed_webhooks' => $retryFailedWebhooks,
    'retry_webhook_limit' => $retryWebhookLimit,
    'auto_heal_safe' => $autoHealSafe,
    'auto_heal_hours_back' => $autoHealHoursBack,
    'low_risk_remediation' => $lowRiskRemediation,
    'low_risk_remediation_dry_run' => $lowRiskRemediationDryRun,
    'low_risk_rollback_on_worsening' => $lowRiskRollbackOnWorsening,
    'circuit_breaker_open' => $breakerOpen,
]);

do {
    $cycleStartedAt = date('c');
    try {
        $result = [];
        $retry = null;

        if ($autoHealSafe) {
            $autoHeal = $service->autoHealSafeDivergences($autoHealHoursBack, $limit, $retryFailedWebhooks);
            $result = $autoHeal['safe_actions']['reconcile_pending_payments'] ?? [];
            $retry = $autoHeal['safe_actions']['retry_failed_webhooks'] ?? null;

            $log('AutoHealSafe => total_divergences_before='
                . (int)($autoHeal['divergences_before']['total_divergences'] ?? 0)
                . ' total_divergences_after=' . (int)($autoHeal['divergences_after']['total_divergences'] ?? 0)
                . ' quarantine=' . (int)($autoHeal['quarantine']['count'] ?? 0));
        } else {
            $result = $service->reconcilePendingPayments($limit, $minAgeMinutes);
            $log('Reconcile => checked=' . (int)$result['checked']
                . ' confirmed=' . (int)$result['confirmed']
                . ' pending=' . (int)$result['still_pending']
                . ' failed=' . (int)$result['failed_or_cancelled']
                . ' without_payment_id=' . (int)$result['without_payment_id']
                . ' errors=' . (int)$result['errors']);

            if ($retryFailedWebhooks) {
                $retry = $service->retryFailedMercadoPagoWebhookEvents($retryWebhookLimit);
                $log('WebhookRetry => retried=' . (int)$retry['retried']
                    . ' recovered=' . (int)$retry['recovered']
                    . ' failed=' . (int)$retry['failed']);
            }
        }

        $lowRiskResult = null;
        if ($lowRiskRemediation) {
            $lowRiskResult = $service->remediateLowRiskDivergences(
                $autoHealHoursBack,
                $limit,
                $lowRiskRemediationDryRun,
                $lowRiskRollbackOnWorsening
            );

            $log('LowRiskRemediation => mode=' . ($lowRiskRemediationDryRun ? 'dry_run' : 'apply')
                . ' checked=' . (int)($lowRiskResult['checked'] ?? 0)
                . ' remediated=' . (int)($lowRiskResult['remediated'] ?? 0)
                . ' skipped=' . (int)($lowRiskResult['skipped'] ?? 0)
                . ' rolled_back=' . ((bool)($lowRiskResult['rolled_back'] ?? false) ? 'yes' : 'no'));
        }

        $service->storeReconcileExecution([
            'source' => 'worker',
            'started_at' => $cycleStartedAt,
            'finished_at' => date('c'),
            'ok' => true,
            'config' => [
                'limit' => $limit,
                'min_age_minutes' => $minAgeMinutes,
                'retry_failed_webhooks' => $retryFailedWebhooks,
                'retry_webhook_limit' => $retryWebhookLimit,
                'run_once' => $runOnce,
                'auto_heal_safe' => $autoHealSafe,
                'auto_heal_hours_back' => $autoHealHoursBack,
                'low_risk_remediation' => $lowRiskRemediation,
                'low_risk_remediation_dry_run' => $lowRiskRemediationDryRun,
                'low_risk_rollback_on_worsening' => $lowRiskRollbackOnWorsening,
            ],
            'result' => $result,
            'retry' => $retry ?? null,
            'low_risk_remediation' => $lowRiskResult,
        ]);

        $service->updateReconcileWorkerHeartbeat([
            'state' => $runOnce ? 'finished' : 'running',
            'last_cycle_started_at' => $cycleStartedAt,
            'last_cycle_finished_at' => date('c'),
            'last_cycle_ok' => true,
            'last_cycle_result' => [
                'checked' => (int)($result['checked'] ?? 0),
                'confirmed' => (int)($result['confirmed'] ?? 0),
                'still_pending' => (int)($result['still_pending'] ?? 0),
                'failed_or_cancelled' => (int)($result['failed_or_cancelled'] ?? 0),
                'without_payment_id' => (int)($result['without_payment_id'] ?? 0),
                'errors' => (int)($result['errors'] ?? 0),
            ],
        ]);
    } catch (Throwable $e) {
        $log('Worker error: ' . $e->getMessage());

        $service->storeReconcileExecution([
            'source' => 'worker',
            'started_at' => $cycleStartedAt,
            'finished_at' => date('c'),
            'ok' => false,
            'config' => [
                'limit' => $limit,
                'min_age_minutes' => $minAgeMinutes,
                'retry_failed_webhooks' => $retryFailedWebhooks,
                'retry_webhook_limit' => $retryWebhookLimit,
                'run_once' => $runOnce,
                'auto_heal_safe' => $autoHealSafe,
                'auto_heal_hours_back' => $autoHealHoursBack,
                'low_risk_remediation' => $lowRiskRemediation,
                'low_risk_remediation_dry_run' => $lowRiskRemediationDryRun,
                'low_risk_rollback_on_worsening' => $lowRiskRollbackOnWorsening,
            ],
            'error' => $e->getMessage(),
        ]);

        $service->updateReconcileWorkerHeartbeat([
            'state' => 'error',
            'last_cycle_started_at' => $cycleStartedAt,
            'last_cycle_finished_at' => date('c'),
            'last_cycle_ok' => false,
            'last_error' => $e->getMessage(),
        ]);
    }

    if ($runOnce) {
        break;
    }

    sleep($sleepSeconds);
} while (true);

$service->updateReconcileWorkerHeartbeat([
    'state' => 'stopped',
    'stopped_at' => date('c'),
]);

$log('EAN payment reconcile worker finished');
