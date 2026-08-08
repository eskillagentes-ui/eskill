<?php

/**
 * Scheduler - Executar tarefas agendadas
 *
 * Este script deve ser executado periodicamente via cron:
 * 0/5 * * * * php /path/to/scheduler.php >> /path/to/logs/scheduler.log 2>&1
 *
 * Executa a cada 5 minutos
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Carregar variáveis de ambiente
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Database;
use App\Helpers\TimezoneHelper;
use App\Services\ApiTokenService;
use App\Services\RealTimeNotificationService;

TimezoneHelper::applyFromEnv();

// Configurar log
$logFile = __DIR__ . '/../storage/logs/scheduler.log';
function logMessage(string $message): void
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    echo "[{$timestamp}] {$message}\n";
}

// Evitar corrida quando há mais de um cron (usuário/root) invocando o mesmo script.
// /tmp evita Permission denied se outro uid criou storage/locks/scheduler.lock.
$lockPath = rtrim(sys_get_temp_dir(), '/') . '/eskill-com-br-scheduler.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false) {
    fwrite(STDERR, "[scheduler] falha ao abrir lock: {$lockPath}\n");
    exit(1);
}
@chmod($lockPath, 0666);
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] Scheduler já em execução — saindo\n";
    exit(0);
}

logMessage('=== Iniciando Scheduler ===');

try {
    $db = Database::getInstance();
    $tokenService = new ApiTokenService();
    $notificationService = new RealTimeNotificationService();

    // EmailSchedulerService removido - classe não existe mais
    // TODO: Implementar service de email scheduling se necessário
    $emailService = null;
    logMessage('[INFO] EmailSchedulerService desabilitado (classe não implementada)');

    // ============================================
    // 1. LIMPAR TOKENS EXPIRADOS
    // ============================================
    logMessage('Limpando tokens expirados...');
    $expiredCount = $tokenService->cleanExpiredTokens();
    if ($expiredCount > 0) {
        logMessage("Revogados {$expiredCount} tokens expirados");
    }

    // ============================================
    // 2. PROCESSAR RELATÓRIOS AGENDADOS
    // ============================================
    if ($emailService !== null) {
        logMessage('Processando relatórios agendados...');

        try {
            $stmt = $db->prepare(
                "SELECT * FROM scheduled_reports
                 WHERE is_active = TRUE
                   AND (next_send_at IS NULL OR next_send_at <= NOW())
                 LIMIT 100"
            );
            $stmt->execute();
            $scheduledReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($scheduledReports as $schedule) {
                try {
                    $userId = $schedule['user_id'];
                    $reportType = $schedule['report_type'];

                    logMessage("Enviando relatório '{$reportType}' para usuário {$userId}");

                    $sent = false;
                    switch ($reportType) {
                        case 'sales':
                            $sent = $emailService->sendSalesReport($userId, 'day');
                            break;
                        case 'dashboard':
                            $sent = $emailService->sendExecutiveDashboard($userId, '30 dias');
                            break;
                        case 'weekly_performance':
                            $sent = $emailService->sendWeeklyPerformance($userId);
                            break;
                    }

                    if ($sent) {
                        $nextSendAt = calculateNextSendTime($schedule);

                        $updateStmt = $db->prepare(
                            "UPDATE scheduled_reports
                             SET last_sent_at = NOW(), next_send_at = ?, updated_at = NOW()
                             WHERE id = ?"
                        );
                        $updateStmt->execute([$nextSendAt, $schedule['id']]);

                        logMessage("Relatório enviado com sucesso. Próximo envio: {$nextSendAt}");
                    } else {
                        logMessage('Falha ao enviar relatório');
                    }
                } catch (\Exception $e) {
                    logMessage("Erro ao processar agendamento {$schedule['id']}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            logMessage('Bloco de relatórios ignorado: ' . $e->getMessage());
        }
    }

    // ============================================
    // 3. LIMPAR NOTIFICAÇÕES ANTIGAS
    // ============================================
    logMessage('Limpando notificações antigas...');
    if (method_exists($notificationService, 'cleanOldNotifications')) {
        $cleanedCount = $notificationService->cleanOldNotifications(90);
    } else {
        $cleanupStmt = $db->prepare(
            "DELETE FROM notifications
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
               AND (is_read = 1 OR is_read IS NULL)"
        );
        $cleanupStmt->execute();
        $cleanedCount = $cleanupStmt->rowCount();
    }

    if ($cleanedCount > 0) {
        logMessage("Removidas {$cleanedCount} notificações antigas");
    }

    // ============================================
    // 4. VERIFICAR ESTOQUE BAIXO (exemplo)
    // ============================================
    logMessage('Verificando estoque baixo...');

    // ============================================
    // 5. REFRESH AUTOMÁTICO DE TOKENS ML
    // ============================================
    // Escritor único: bin/auto-token-refresh-worker.php (cron */30).
    // Evita corrida de lock com UnifiedTokenRefreshService a cada :00/:30.
    logMessage('Refresh de tokens ML delegado ao auto-token-refresh-worker (cron */30)');

    logMessage('=== Scheduler Concluído ===');
} catch (\Exception $e) {
    logMessage('ERRO FATAL: ' . $e->getMessage());
    logMessage($e->getTraceAsString());
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    exit(1);
}

if (isset($lockHandle) && is_resource($lockHandle)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

/**
 * Calcular próximo horário de envio baseado na frequência
 */
function calculateNextSendTime(array $schedule): string
{
    $frequency = $schedule['frequency'];
    $time = $schedule['time'] ?? '09:00:00';

    switch ($frequency) {
        case 'daily':
            return date('Y-m-d', strtotime('+1 day')) . ' ' . $time;

        case 'weekly':
            $dayOfWeek = $schedule['day_of_week'] ?? 1;
            $daysUntilNext = ($dayOfWeek - date('N') + 7) % 7 ?: 7;
            return date('Y-m-d', strtotime("+{$daysUntilNext} days")) . ' ' . $time;

        case 'monthly':
            $dayOfMonth = $schedule['day_of_month'] ?? 1;
            $nextMonth = strtotime('+1 month');
            return date('Y-m', $nextMonth) . '-' . str_pad((string)$dayOfMonth, 2, '0', STR_PAD_LEFT) . ' ' . $time;

        default:
            return date('Y-m-d H:i:s', strtotime('+1 day'));
    }
}
