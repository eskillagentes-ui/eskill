<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use PDO;

/**
 * 🤖 AutoPilot Status Manager
 * 
 * Gerencia status e configurações do AutoPilot
 * com persistência em database
 * 
 * @author AI Development Team
 * @version 1.0.0
 */
class AutoPilotStatusManager
{
    private int $accountId;
    private PDO $db;
    
    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->db = Database::getInstance();
        $this->ensureTableExists();
    }
    
    /**
     * ✅ Get real status from database
     */
    public function getRealStatus(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    enabled,
                    config,
                    last_run_at,
                    next_run_at,
                    total_runs,
                    total_optimizations,
                    success_rate,
                    last_error,
                    budget_used,
                    budget_limit,
                    updated_at
                FROM autopilot_config
                WHERE account_id = :account_id
                LIMIT 1
            ");
            
            $stmt->execute(['account_id' => $this->accountId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$config) {
                // Create default config if not exists
                $this->createDefaultConfig();
                return $this->getRealStatus(); // Recursive call to get newly created config
            }
            
            // Parse JSON config
            $config['config'] = json_decode($config['config'] ?? '{}', true);
            $config['enabled'] = (bool)$config['enabled'];
            
            // Add computed fields
            $config['status'] = $this->computeStatus($config);
            $config['next_run_in'] = $this->getNextRunIn($config['next_run_at']);
            $config['health'] = $this->getHealth($config);
            
            return $config;
            
        } catch (\Exception $e) {
            return [
                'enabled' => false,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 💾 Save configuration
     */
    public function saveConfig(array $config): array
    {
        try {
            // Ensure autopilot_config table exists
            $this->ensureTableExists();
            
            $stmt = $this->db->prepare("
                INSERT INTO autopilot_config 
                (account_id, enabled, config, budget_limit, updated_at)
                VALUES (:account_id, :enabled, :config, :budget_limit, NOW())
                ON DUPLICATE KEY UPDATE
                    enabled = :enabled,
                    config = :config,
                    budget_limit = :budget_limit,
                    updated_at = NOW()
            ");
            
            $stmt->execute([
                'account_id' => $this->accountId,
                'enabled' => $config['enabled'] ?? false,
                'config' => json_encode($config),
                'budget_limit' => $config['budget_limit'] ?? 100.00,
            ]);
            
            return [
                'success' => true,
                'message' => 'Configuração salva com sucesso',
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * 📊 Update statistics after run
     */
    public function updateStats(array $runData): void
    {
        $stmt = $this->db->prepare("
            UPDATE autopilot_config
            SET 
                last_run_at = :last_run_at,
                next_run_at = :next_run_at,
                total_runs = total_runs + 1,
                total_optimizations = total_optimizations + :optimizations,
                success_rate = CASE 
                    WHEN :success THEN (success_rate * total_runs + 100) / (total_runs + 1)
                    ELSE (success_rate * total_runs) / (total_runs + 1)
                END,
                last_error = :error,
                budget_used = budget_used + :cost
            WHERE account_id = :account_id
        ");
        
        $stmt->execute([
            'account_id' => $this->accountId,
            'last_run_at' => $runData['timestamp'] ?? date('Y-m-d H:i:s'),
            'next_run_at' => $runData['next_run'] ?? date('Y-m-d H:i:s', strtotime('+1 day')),
            'optimizations' => $runData['optimizations'] ?? 0,
            'success' => $runData['success'] ?? true,
            'error' => $runData['error'] ?? null,
            'cost' => $runData['cost'] ?? 0.0,
        ]);
    }
    
    /**
     * 🎯 Compute current status
     */
    private function computeStatus(array $config): string
    {
        if (!$config['enabled']) {
            return 'disabled';
        }
        
        if ($config['last_error']) {
            return 'error';
        }
        
        if ($config['next_run_at'] && strtotime($config['next_run_at']) <= time()) {
            return 'pending';
        }
        
        return 'active';
    }
    
    /**
     * ⏰ Get time until next run
     */
    private function getNextRunIn(?string $nextRunAt): ?string
    {
        if (!$nextRunAt) return null;
        
        $diff = strtotime($nextRunAt) - time();
        
        if ($diff < 0) return 'Agora';
        if ($diff < 60) return $diff . 's';
        if ($diff < 3600) return floor($diff / 60) . 'min';
        if ($diff < 86400) return floor($diff / 3600) . 'h';
        
        return floor($diff / 86400) . 'd';
    }
    
    /**
     * 🏥 Get health status
     */
    private function getHealth(array $config): string
    {
        $successRate = $config['success_rate'] ?? 0;
        $budgetUsage = $config['budget_limit'] > 0 
            ? ($config['budget_used'] / $config['budget_limit']) * 100 
            : 0;
        
        if ($successRate < 50 || $budgetUsage > 90) {
            return 'critical';
        }
        
        if ($successRate < 70 || $budgetUsage > 75) {
            return 'warning';
        }
        
        return 'healthy';
    }
    
    /**
     * 🔁 Sincroniza a flag `enabled` a partir de AutoPilot::saveConfig().
     *
     * Bug conhecido: AutoPilot (seo_autopilot_config) e AutoPilotStatusManager
     * (autopilot_config) são tabelas distintas — o botão enable/disable do
     * AutoPilot escrevia só na primeira, mas o badge de status lê a segunda,
     * então a UI ficava "desativado" mesmo com o AutoPilot habilitado.
     * Este método mantém a flag em sincronia sem migrar o schema.
     */
    public function setEnabled(bool $enabled): void
    {
        try {
            $this->ensureTableExists();

            $stmt = $this->db->prepare("
                INSERT INTO autopilot_config (account_id, enabled, config, budget_limit, updated_at)
                VALUES (:account_id, :enabled, :config, :budget_limit, NOW())
                ON DUPLICATE KEY UPDATE
                    enabled = :enabled,
                    updated_at = NOW()
            ");

            $stmt->execute([
                'account_id' => $this->accountId,
                'enabled' => $enabled,
                'config' => json_encode(['enabled' => $enabled]),
                'budget_limit' => 100.00,
            ]);
        } catch (\Exception $e) {
            log_warning('AutoPilotStatusManager: falha ao sincronizar enabled', [
                'account_id' => $this->accountId,
                'enabled' => $enabled,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 🔧 Create default configuration
     */
    private function createDefaultConfig(): void
    {
        $defaultConfig = [
            'enabled' => false,
            'schedule' => 'daily',
            'max_items_per_run' => 10,
            'optimize_title' => true,
            'optimize_description' => true,
            'fill_attributes' => true,
            'budget_limit' => 100.00,
        ];
        
        $stmt = $this->db->prepare("
            INSERT INTO autopilot_config 
            (account_id, enabled, config, budget_limit, total_runs, total_optimizations, success_rate, budget_used, created_at, updated_at)
            VALUES (:account_id, 0, :config, :budget_limit, 0, 0, 0, 0, NOW(), NOW())
        ");
        
        $stmt->execute([
            'account_id' => $this->accountId,
            'config' => json_encode($defaultConfig),
            'budget_limit' => $defaultConfig['budget_limit'],
        ]);
    }
    
    /**
     * 🗄️ Ensure table exists
     */
    private function ensureTableExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS autopilot_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL UNIQUE,
                enabled BOOLEAN DEFAULT FALSE,
                config JSON,
                last_run_at TIMESTAMP NULL,
                next_run_at TIMESTAMP NULL,
                total_runs INT DEFAULT 0,
                total_optimizations INT DEFAULT 0,
                success_rate DECIMAL(5,2) DEFAULT 0,
                last_error TEXT,
                budget_used DECIMAL(10,2) DEFAULT 0,
                budget_limit DECIMAL(10,2) DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_account (account_id),
                INDEX idx_enabled (enabled),
                INDEX idx_next_run (next_run_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * 📊 Get detailed status for API (comprehensive info)
     */
    public function getDetailedStatus(): array
    {
        $status = $this->getRealStatus();
        
        // Add execution metrics
        $status['execution_metrics'] = $this->getExecutionMetrics();
        
        // Add budget status
        $status['budget_status'] = $this->getBudgetStatus();
        
        // Add optimal run time recommendation
        $status['optimal_run_time'] = $this->getOptimalRunTime();
        
        // Add recent history
        $status['recent_history'] = $this->getRecentExecutions(5);
        
        return [
            'success' => true,
            'status' => $status
        ];
    }
    
    /**
     * 📈 Get execution metrics
     */
    public function getExecutionMetrics(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    total_runs,
                    total_optimizations,
                    success_rate,
                    budget_used,
                    COALESCE(total_optimizations / NULLIF(total_runs, 0), 0) as avg_optimizations_per_run
                FROM autopilot_config
                WHERE account_id = ?
            ");
            $stmt->execute([$this->accountId]);
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_runs' => (int)($metrics['total_runs'] ?? 0),
                'total_optimizations' => (int)($metrics['total_optimizations'] ?? 0),
                'success_rate' => (float)($metrics['success_rate'] ?? 0),
                'avg_per_run' => round((float)($metrics['avg_optimizations_per_run'] ?? 0), 2),
                'total_cost' => (float)($metrics['budget_used'] ?? 0)
            ];
        } catch (\Exception $e) {
            return [
                'total_runs' => 0,
                'total_optimizations' => 0,
                'success_rate' => 0,
                'avg_per_run' => 0,
                'total_cost' => 0
            ];
        }
    }
    
    /**
     * 💰 Get budget status
     */
    public function getBudgetStatus(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT budget_used, budget_limit
                FROM autopilot_config
                WHERE account_id = ?
            ");
            $stmt->execute([$this->accountId]);
            $budget = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $used = (float)($budget['budget_used'] ?? 0);
            $limit = (float)($budget['budget_limit'] ?? 100);
            $remaining = max(0, $limit - $used);
            $percentage = $limit > 0 ? ($used / $limit) * 100 : 0;
            
            $status = 'healthy';
            if ($percentage >= 90) $status = 'critical';
            elseif ($percentage >= 75) $status = 'warning';
            
            return [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $remaining,
                'percentage' => round($percentage, 2),
                'status' => $status,
                'can_run' => $remaining > 0
            ];
        } catch (\Exception $e) {
            return [
                'used' => 0,
                'limit' => 100,
                'remaining' => 100,
                'percentage' => 0,
                'status' => 'unknown',
                'can_run' => true
            ];
        }
    }
    
    /**
     * 💵 Track cost after operation
     */
    public function trackCost(float $cost, string $operation): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE autopilot_config
                SET budget_used = budget_used + ?
                WHERE account_id = ?
            ");
            $stmt->execute([$cost, $this->accountId]);
            
            // Log the operation
            log_info('AutoPilot: custo registrado', [
                'service' => 'AutoPilotStatusManager',
                'cost' => $cost,
                'operation' => $operation,
                'account_id' => $this->accountId,
            ]);
        } catch (\Exception $e) {
            log_warning('AutoPilot: erro ao registrar custo', [
                'service' => 'AutoPilotStatusManager',
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * ⏰ Get optimal run time (off-peak hours)
     */
    public function getOptimalRunTime(): string
    {
        // Recommend 2 AM local time (off-peak)
        $now = new \DateTime();
        $optimalTime = new \DateTime();
        $optimalTime->setTime(2, 0, 0);
        
        // If 2 AM has passed today, schedule for tomorrow
        if ($optimalTime < $now) {
            $optimalTime->modify('+1 day');
        }
        
        return $optimalTime->format('Y-m-d H:i:s');
    }
    
    /**
     * ✅ Check if can run within budget
     */
    public function canRunWithinBudget(float $estimatedCost = 1.0): bool
    {
        $budget = $this->getBudgetStatus();
        return $budget['remaining'] >= $estimatedCost;
    }
    
    /**
     * 📜 Get recent execution history
     */
    private function getRecentExecutions(int $limit = 5): array
    {
        // This would ideally query an autopilot_executions table
        // For now, return basic info
        try {
            $stmt = $this->db->prepare("
                SELECT last_run_at, total_runs, last_error
                FROM autopilot_config
                WHERE account_id = ?
            ");
            $stmt->execute([$this->accountId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($data && $data['last_run_at']) {
                return [[
                    'timestamp' => $data['last_run_at'],
                    'status' => $data['last_error'] ? 'failed' : 'success',
                    'error' => $data['last_error']
                ]];
            }
            
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
