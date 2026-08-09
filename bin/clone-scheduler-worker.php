#!/usr/bin/env php
<?php

/**
 * Clone Auto-Scheduler Worker
 * 
 * Processa agendamentos automáticos de clonagem.
 * 
 * Uso:
 *   php bin/clone-scheduler-worker.php                  # Loop contínuo
 *   php bin/clone-scheduler-worker.php --once           # Única execução
 *   php bin/clone-scheduler-worker.php --schedule=123   # Executa schedule específico
 *   php bin/clone-scheduler-worker.php --dry-run        # Apenas lista schedules pendentes
 * 
 * Crontab recomendado:
 *   * * * * * cd /path/to/project && php bin/clone-scheduler-worker.php --once >> storage/logs/clone-scheduler.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\Services\CloneAutoSchedulerService;
use App\Services\CloneSlackDiscordNotificationService;
use App\Database;

class CloneSchedulerWorker
{
    private $db;
    private bool $dryRun = false;
    private bool $once = false;
    private ?int $specificSchedule = null;
    private string $logFile;
    
    public function __construct(array $args)
    {
        $this->db = Database::getInstance();
        $this->logFile = __DIR__ . '/../storage/logs/clone-scheduler.log';
        
        $this->parseArgs($args);
    }
    
    private function parseArgs(array $args): void
    {
        foreach ($args as $arg) {
            if ($arg === '--once') {
                $this->once = true;
            } elseif ($arg === '--dry-run') {
                $this->dryRun = true;
            } elseif (str_starts_with($arg, '--schedule=')) {
                $this->specificSchedule = (int) substr($arg, 11);
            }
        }
    }
    
    public function run(): void
    {
        $this->log("=== Clone Scheduler Worker Started ===");
        $this->log("Mode: " . ($this->once ? 'Once' : 'Continuous'));
        
        if ($this->dryRun) {
            $this->log("DRY-RUN: Apenas listando schedules pendentes");
            $this->listDueSchedules();
            return;
        }
        
        if ($this->specificSchedule) {
            $this->processSpecificSchedule($this->specificSchedule);
            return;
        }
        
        do {
            $this->processSchedules();
            
            if (!$this->once) {
                $this->log("Aguardando 60 segundos...");
                sleep(60);
            }
        } while (!$this->once);
        
        $this->log("=== Worker Finalizado ===");
    }
    
    private function processSchedules(): void
    {
        // Buscar todas as contas com schedules ativos
        $stmt = $this->db->query("
            SELECT DISTINCT account_id 
            FROM clone_schedules 
            WHERE is_active = 1 
            AND next_run_at <= NOW()
        ");
        
        $accounts = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        if (empty($accounts)) {
            $this->log("Nenhum schedule pendente");
            return;
        }
        
        $this->log("Encontradas " . count($accounts) . " contas com schedules pendentes");
        
        foreach ($accounts as $accountId) {
            $this->processAccountSchedules((int) $accountId);
        }
    }
    
    private function processAccountSchedules(int $accountId): void
    {
        $this->log("Processando conta: $accountId");
        
        try {
            $scheduler = new CloneAutoSchedulerService($accountId);
            $dueSchedules = $scheduler->getDueSchedules();
            
            $this->log("  {$dueSchedules} schedules prontos para execução");
            
            foreach ($dueSchedules as $schedule) {
                $this->executeSchedule($scheduler, $schedule, $accountId);
            }
            
        } catch (\Exception $e) {
            $this->log("  ERRO: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function executeSchedule(CloneAutoSchedulerService $scheduler, array $schedule, int $accountId): void
    {
        $scheduleId = $schedule['id'];
        $scheduleName = $schedule['name'];
        
        $this->log("  Executando schedule #$scheduleId: $scheduleName");
        
        try {
            $result = $scheduler->executeSchedule($scheduleId);
            
            if ($result['success']) {
                $itemsFound = $result['items_found'] ?? 0;
                $jobId = $result['job_id'] ?? 'N/A';
                
                $this->log("    ✓ Sucesso: $itemsFound itens encontrados, Job #$jobId criado");
                
                // Notificar sucesso se configurado
                $this->sendNotification($accountId, 'success', [
                    'schedule_name' => $scheduleName,
                    'items_found' => $itemsFound,
                    'job_id' => $jobId,
                ]);
            } else {
                $this->log("    ✗ Falha: " . ($result['message'] ?? 'Erro desconhecido'), 'WARNING');
            }
            
        } catch (\Exception $e) {
            $this->log("    ✗ ERRO: " . $e->getMessage(), 'ERROR');
            
            // Notificar erro
            $this->sendNotification($accountId, 'error', [
                'schedule_name' => $scheduleName,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    private function processSpecificSchedule(int $scheduleId): void
    {
        $this->log("Executando schedule específico: #$scheduleId");
        
        // Buscar conta do schedule
        $stmt = $this->db->prepare("SELECT account_id FROM clone_schedules WHERE id = :id");
        $stmt->execute(['id' => $scheduleId]);
        $accountId = $stmt->fetchColumn();
        
        if (!$accountId) {
            $this->log("Schedule não encontrado: #$scheduleId", 'ERROR');
            return;
        }
        
        $scheduler = new CloneAutoSchedulerService((int) $accountId);
        $schedule = $scheduler->getSchedule($scheduleId);
        
        if (!$schedule) {
            $this->log("Schedule não acessível: #$scheduleId", 'ERROR');
            return;
        }
        
        $this->executeSchedule($scheduler, $schedule, (int) $accountId);
    }
    
    private function listDueSchedules(): void
    {
        $stmt = $this->db->query("
            SELECT cs.*, 
                   (SELECT nickname FROM ml_accounts WHERE id = cs.account_id) as account_name
            FROM clone_schedules cs
            WHERE cs.is_active = 1
            AND cs.next_run_at <= NOW()
            ORDER BY cs.next_run_at ASC
        ");
        
        $schedules = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        if (empty($schedules)) {
            $this->log("Nenhum schedule pendente no momento");
            return;
        }
        
        $this->log("Schedules pendentes: " . count($schedules));
        $this->log(str_repeat('-', 80));
        
        foreach ($schedules as $s) {
            $this->log(sprintf(
                "  #%d | %s | Conta: %s | Próx: %s | Fonte: %s:%s",
                $s['id'],
                $s['name'],
                $s['account_name'] ?? $s['account_id'],
                $s['next_run_at'],
                $s['source_type'],
                $s['source_value']
            ));
        }
    }
    
    private function sendNotification(int $accountId, string $type, array $data): void
    {
        try {
            $notifier = new CloneSlackDiscordNotificationService($accountId);
            
            if ($type === 'success') {
                $notifier->sendToSlack(
                    CloneSlackDiscordNotificationService::ALERT_JOB_STARTED,
                    "Auto-Clone: {$data['schedule_name']}",
                    "{$data['items_found']} itens encontrados, Job #{$data['job_id']} criado",
                    [],
                    CloneSlackDiscordNotificationService::SEVERITY_INFO
                );
            } else {
                $notifier->sendToSlack(
                    CloneSlackDiscordNotificationService::ALERT_JOB_FAILED,
                    "Erro no Auto-Clone: {$data['schedule_name']}",
                    $data['error'],
                    [],
                    CloneSlackDiscordNotificationService::SEVERITY_ERROR
                );
            }
        } catch (\Exception $e) {
            // Ignore notification errors
        }
    }
    
    private function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[$timestamp] [$level] $message\n";
        
        echo $logLine;
        
        @file_put_contents($this->logFile, $logLine, FILE_APPEND);
    }
}

// Executar
$worker = new CloneSchedulerWorker($argv);
$worker->run();
