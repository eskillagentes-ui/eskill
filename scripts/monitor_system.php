<?php

/**
 * Script de monitoramento automatizado do sistema
 * 
 * Este script deve ser executado periodicamente via CRON
 * Exemplo de CRON (a cada 5 minutos):
 * 0/5 * * * * php /caminho/para/eskill/scripts/monitor_system.php
 */

// Carregar autoload
require_once __DIR__ . '/../vendor/autoload.php';

// Carregar variáveis de ambiente
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

use App\Services\MonitoringService;
use App\Services\TelegramService;

try {
    $monitoringService = new MonitoringService();
    $telegramService = new TelegramService();

    echo "[" . date('Y-m-d H:i:s') . "] Iniciando monitoramento do sistema...\n";

    // Verificar saúde do sistema
    $health = $monitoringService->checkSystemHealth();

    echo "Status geral: {$health['status']}\n";

    // Verificar cada componente
    foreach ($health['checks'] as $checkName => $check) {
        echo "  {$checkName}: {$check['status']}\n";

        // Enviar alerta via Telegram se crítico
        if ($check['status'] === 'critical' && $telegramService->isEnabled()) {
            $message = "🚨 Sistema Crítico: {$checkName}\n";
            $message .= "Status: {$check['status']}\n";
            if (isset($check['message'])) {
                $message .= "Detalhes: {$check['message']}\n";
            }

            $telegramService->sendCustomNotification(
                'Alerta Crítico do Sistema',
                $message,
                'danger'
            );
        }
    }

    // Verificar métricas específicas
    $diskStats = $monitoringService->getMetricStats('disk_usage_percent', 1);
    if ($diskStats['latest'] > 90) {
        echo "⚠️  Alerta: Uso de disco acima de 90%\n";

        if ($telegramService->isEnabled()) {
            $telegramService->sendCustomNotification(
                'Alerta de Disco',
                "Uso de disco: {$diskStats['latest']}%\nAção recomendada: Limpar espaço",
                'warning'
            );
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Monitoramento concluído.\n";
} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERRO: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";

    // Tentar enviar erro via Telegram
    try {
        $telegramService = new TelegramService();
        if ($telegramService->isEnabled()) {
            $telegramService->sendCustomNotification(
                'Erro no Monitoramento',
                "Erro ao executar monitoramento:\n{$e->getMessage()}",
                'danger'
            );
        }
    } catch (\Exception $telegramError) {
        // Ignorar erro do Telegram
    }

    exit(1);
}
