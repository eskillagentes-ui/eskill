<?php

declare(strict_types=1);

/**
 * Worker de Monitoramento Proativo de Erros
 *
 * Verifica saúde da aplicação, detecta erros nos logs,
 * e envia alertas por e-mail quando problemas são detectados.
 *
 * Uso:
 *   php bin/error-monitor.php              # Execução normal
 *   php bin/error-monitor.php --status     # Ver último status
 *   php bin/error-monitor.php --verbose    # Saída detalhada
 *
 * Cron (a cada 5 min):
 *   Veja crontab.error-monitor.example
 */

require_once __DIR__ . '/../autoload.php';

use App\Services\ErrorMonitoringService;

// Carregar .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
}

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$statusOnly = in_array('--status', $argv, true);

/**
 * @param string $text
 * @param string $color 'green'|'red'|'yellow'|'cyan'
 */
function colorize(string $text, string $color): string
{
    $colors = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'cyan' => "\033[36m",
        'bold' => "\033[1m",
        'reset' => "\033[0m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

try {
    $monitor = new ErrorMonitoringService();

    if ($statusOnly) {
        $status = $monitor->getQuickStatus();
        echo colorize("=== Status do Monitoramento ===\n", 'bold');
        echo "Status: " . colorize($status['status'], $status['status'] === 'ok' ? 'green' : 'red') . "\n";
        echo "Último check: " . ($status['last_check'] ?? 'nunca') . "\n";
        echo "Erros (último run): " . ($status['errors_last_run'] ?? 0) . "\n";
        echo "App healthy: " . ($status['app_healthy'] === null ? '?' : ($status['app_healthy'] ? 'sim' : 'NAO')) . "\n";
        echo "DB conectado: " . ($status['db_connected'] === null ? '?' : ($status['db_connected'] ? 'sim' : 'NAO')) . "\n";
        exit(0);
    }

    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Iniciando ciclo de monitoramento...\n";

    $results = $monitor->runMonitoringCycle();

    // Resumo
    $errCount = $results['errors_found'];
    $alertsSent = $results['alerts_sent'];

    if ($errCount === 0) {
        echo colorize("[OK]", 'green') . " Nenhum problema detectado.\n";
    } else {
        echo colorize("[ALERTA]", 'red') . " {$errCount} problema(s) detectado(s).\n";

        if ($alertsSent > 0) {
            echo colorize("[EMAIL]", 'cyan') . " {$alertsSent} alerta(s) enviado(s).\n";
        }
    }

    // Saída detalhada
    if ($verbose || $errCount > 0) {
        echo "\n--- Detalhes ---\n";

        foreach ($results['checks'] as $checkName => $checkResult) {
            $label = str_replace('_', ' ', ucfirst($checkName));
            echo "\n{$label}:\n";

            switch ($checkName) {
                case 'recent_errors':
                    echo "  Erros: {$checkResult['count']} | Críticos: {$checkResult['critical']} | Warnings: {$checkResult['warnings']}\n";
                    foreach ($checkResult['errors'] ?? [] as $err) {
                        $icon = $err['critical'] ? colorize('[CRIT]', 'red') : colorize('[ERR]', 'yellow');
                        $occ = $err['occurrences'] > 1 ? " (x{$err['occurrences']})" : '';
                        echo "  {$icon} " . mb_substr($err['message'], 0, 120) . "{$occ}\n";
                    }
                    break;

                case 'app_health':
                    $status = $checkResult['healthy'] ? colorize('OK', 'green') : colorize('DOWN', 'red');
                    echo "  Status: {$status} | HTTP: {$checkResult['status_code']} | Tempo: {$checkResult['response_time_ms']}ms\n";
                    if ($checkResult['error']) {
                        echo "  Erro: {$checkResult['error']}\n";
                    }
                    break;

                case 'disk_space':
                    $status = ($checkResult['critical'] ?? false)
                        ? colorize('CRITICO', 'red')
                        : (($checkResult['warning'] ?? false) ? colorize('ALERTA', 'yellow') : colorize('OK', 'green'));
                    echo "  Status: {$status} | Uso: " . ($checkResult['used_percent'] ?? '?') . "% | Livre: " . ($checkResult['free_gb'] ?? '?') . "GB\n";
                    break;

                case 'log_sizes':
                    echo "  Total: {$checkResult['total_mb']}MB\n";
                    foreach ($checkResult['large_files'] ?? [] as $lf) {
                        echo "  " . colorize("[GRANDE]", 'yellow') . " {$lf['file']}: {$lf['size_mb']}MB\n";
                    }
                    break;

                case 'database':
                    $status = $checkResult['connected'] ? colorize('OK', 'green') : colorize('DOWN', 'red');
                    echo "  Status: {$status} | Tempo: {$checkResult['response_time_ms']}ms\n";
                    if ($checkResult['error']) {
                        echo "  Erro: {$checkResult['error']}\n";
                    }
                    break;

                case 'php_errors':
                    echo "  Erros PHP: {$checkResult['count']}\n";
                    foreach ($checkResult['samples'] ?? [] as $s) {
                        $icon = $s['fatal'] ? colorize('[FATAL]', 'red') : colorize('[PHP]', 'yellow');
                        echo "  {$icon} " . mb_substr($s['message'], 0, 120) . "\n";
                    }
                    break;

                case 'unresolved_db_errors':
                    echo "  Não resolvidos (2h): {$checkResult['count']} | Críticos: {$checkResult['critical']}\n";
                    break;

                default:
                    echo "  " . json_encode($checkResult) . "\n";
            }
        }
    }

    echo "\n[{$timestamp}] Ciclo concluído.\n";

    exit($errCount > 0 ? 1 : 0);
} catch (\Throwable $e) {
    $msg = "[FATAL] Error monitor falhou: {$e->getMessage()} em {$e->getFile()}:{$e->getLine()}";
    fwrite(STDERR, $msg . "\n");
    exit(2);
}
