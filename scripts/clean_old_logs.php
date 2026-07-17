<?php

/**
 * Script para Limpar Logs Antigos
 * Remove logs e atividades antigas do sistema
 * Executar via CRON: 0 4 * * 0 php scripts/clean_old_logs.php (semanal)
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Carregar variáveis de ambiente
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

echo "==============================================\n";
echo "🧹 Limpeza de Logs: " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

$totalRemoved = 0;

try {
    $db = App\Database::getInstance();

    // 1. Limpar logs do banco de dados
    echo "[1/5] Limpando logs do banco de dados...\n";

    // Sync logs (30 dias)
    $stmt = $db->prepare("DELETE FROM sync_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "   ✅ sync_logs: {$count} registros removidos\n";
    $totalRemoved += $count;

    // Activity logs (90 dias)
    $stmt = $db->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "   ✅ activity_logs: {$count} registros removidos\n";
    $totalRemoved += $count;

    // Audit logs (90 dias)
    $stmt = $db->prepare("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "   ✅ audit_logs: {$count} registros removidos\n";
    $totalRemoved += $count;

    // Webhook logs (30 dias)
    $stmt = $db->prepare("DELETE FROM webhook_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "   ✅ webhook_logs: {$count} registros removidos\n";
    $totalRemoved += $count;

    // Query log (7 dias)
    $stmt = $db->prepare("DELETE FROM query_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "   ✅ query_log: {$count} registros removidos\n";
    $totalRemoved += $count;

    // Email logs (60 dias)
    $stmt = $db->prepare("DELETE FROM email_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "   ✅ email_logs: {$count} registros removidos\n";
    $totalRemoved += $count;

    // Password resets expirados
    $stmt = $db->prepare("DELETE FROM password_resets WHERE expires_at < NOW()");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "   ✅ password_resets: {$count} tokens expirados removidos\n";
    $totalRemoved += $count;

    // Rate limits antigos (1 dia)
    $stmt = $db->prepare("DELETE FROM rate_limits WHERE last_request < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "   ✅ rate_limits: {$count} registros removidos\n";
    $totalRemoved += $count;
} catch (Exception $e) {
    echo "   ⚠️ Erro ao limpar banco: " . $e->getMessage() . "\n";
}

// 2. Limpar arquivos de log físicos
echo "\n[2/5] Limpando arquivos de log físicos...\n";
$logsDir = __DIR__ . '/../storage/logs';
$filesRemoved = 0;
$bytesFreed = 0;

if (is_dir($logsDir)) {
    $files = glob($logsDir . '/*.log');
    foreach ($files as $file) {
        // Manter apenas logs dos últimos 30 dias
        if (filemtime($file) < strtotime('-30 days')) {
            $bytesFreed += filesize($file);
            unlink($file);
            $filesRemoved++;
        }
    }

    // Limpar logs rotacionados (*.log.1, *.log.2, etc)
    $rotatedFiles = glob($logsDir . '/*.log.*');
    foreach ($rotatedFiles as $file) {
        if (filemtime($file) < strtotime('-7 days')) {
            $bytesFreed += filesize($file);
            unlink($file);
            $filesRemoved++;
        }
    }

    echo "   ✅ {$filesRemoved} arquivos removidos (" . formatBytes($bytesFreed) . " liberados)\n";
}

// 3. Limpar cache antigo
echo "\n[3/5] Limpando cache expirado...\n";
$cacheDir = __DIR__ . '/../storage/cache';
$cacheRemoved = 0;
$cacheBytesFreed = 0;

if (is_dir($cacheDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getMTime() < strtotime('-7 days')) {
            $cacheBytesFreed += $file->getSize();
            unlink($file->getPathname());
            $cacheRemoved++;
        }
    }

    echo "   ✅ {$cacheRemoved} arquivos de cache removidos (" . formatBytes($cacheBytesFreed) . " liberados)\n";
}

// 4. Limpar backups antigos
echo "\n[4/5] Limpando backups antigos (>30 dias)...\n";
$backupDir = __DIR__ . '/../storage/backups';
$backupsRemoved = 0;

if (is_dir($backupDir)) {
    $backupFiles = glob($backupDir . '/*.gz');
    foreach ($backupFiles as $file) {
        if (filemtime($file) < strtotime('-30 days')) {
            unlink($file);
            $backupsRemoved++;
        }
    }
    echo "   ✅ {$backupsRemoved} backups antigos removidos\n";
}

// 5. Otimizar tabelas do banco
echo "\n[5/5] Otimizando tabelas do banco...\n";
try {
    $tables = ['sync_logs', 'activity_logs', 'audit_logs', 'webhook_logs', 'query_log'];
    foreach ($tables as $table) {
        $db->exec("OPTIMIZE TABLE {$table}");
    }
    echo "   ✅ Tabelas otimizadas\n";
} catch (Exception $e) {
    echo "   ⚠️ Não foi possível otimizar: " . $e->getMessage() . "\n";
}

// Resumo
echo "\n==============================================\n";
echo "RESUMO DA LIMPEZA:\n";
echo "  Registros BD removidos: {$totalRemoved}\n";
echo "  Arquivos de log removidos: {$filesRemoved}\n";
echo "  Arquivos de cache removidos: {$cacheRemoved}\n";
echo "  Backups antigos removidos: {$backupsRemoved}\n";
echo "  Espaço total liberado: " . formatBytes($bytesFreed + $cacheBytesFreed) . "\n";
echo "==============================================\n";

// Log da limpeza
$logFile = $logsDir . '/cleanup.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " | BD: {$totalRemoved} | Logs: {$filesRemoved} | Cache: {$cacheRemoved} | Backups: {$backupsRemoved}\n", FILE_APPEND);

function formatBytes($bytes, $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}
