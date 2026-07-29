<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

/**
 * Batch Optimization Queue
 * Manages background processing of multiple optimizations
 * Uses database for queue (Redis alternative)
 */
class BatchOptimizationQueue
{
    private \PDO $db;
    
    public function __construct()
    {
        // Get database connection via Singleton
        $this->db = \App\Database::getInstance();
        $this->createQueueTable();
    }
    
    /**
     * Create queue table if not exists
     */
    private function createQueueTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS ai_optimization_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(50) NOT NULL,
            item_id VARCHAR(50) NOT NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            options JSON,
            result JSON,
            error_message TEXT,
            priority INT DEFAULT 0,
            attempts INT DEFAULT 0,
            max_attempts INT DEFAULT 3,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            INDEX idx_batch_id (batch_id),
            INDEX idx_status (status),
            INDEX idx_priority (priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }
    
    /**
     * Add items to queue
     * 
     * @param array $itemIds Array of item IDs to optimize
     * @param array $options Optimization options
     * @param int $priority Priority (higher = first)
     * @return string Batch ID
     */
    public function addBatch(array $itemIds, array $options = [], int $priority = 0): string
    {
        $batchId = $this->generateBatchId();
        
        $stmt = $this->db->prepare(
            "INSERT INTO ai_optimization_queue 
            (batch_id, item_id, options, priority) 
            VALUES (?, ?, ?, ?)"
        );
        
        foreach ($itemIds as $itemId) {
            $stmt->execute([
                $batchId,
                $itemId,
                json_encode($options),
                $priority
            ]);
        }
        
        return $batchId;
    }
    
    /**
     * Process next item in queue
     * 
     * @return array|null Processed result or null if queue empty
     */
    public function processNext(): ?array
    {
        // Get next pending item
        $stmt = $this->db->prepare(
            "SELECT * FROM ai_optimization_queue 
            WHERE status = 'pending' 
            AND attempts < max_attempts
            ORDER BY priority DESC, created_at ASC 
            LIMIT 1
            FOR UPDATE"
        );
        
        $this->db->beginTransaction();
        
        try {
            $stmt->execute();
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$job) {
                $this->db->commit();
                return null;
            }
            
            // Mark as processing
            $updateStmt = $this->db->prepare(
                "UPDATE ai_optimization_queue 
                SET status = 'processing', 
                    started_at = NOW(), 
                    attempts = attempts + 1
                WHERE id = ?"
            );
            $updateStmt->execute([$job['id']]);
            
            $this->db->commit();
            
            // Process the optimization
            $result = $this->processOptimization($job);
            
            // Update with result
            $this->updateJobResult($job['id'], $result);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            
            // Mark as failed
            $this->markFailed($job['id'] ?? null, $e->getMessage());
            
            throw $e;
        }
    }
    
    /**
     * Process optimization for a job
     * 
     * @param array $job
     * @return array
     */
    private function processOptimization(array $job): array
    {
        $itemId = $job['item_id'];
        $options = json_decode($job['options'], true) ?? [];
        
        $engine = new AIOptimizationEngine();
        
        $result = $engine->optimizeListing($itemId, $options);
        
        return [
            'job_id' => $job['id'],
            'batch_id' => $job['batch_id'],
            'item_id' => $itemId,
            'success' => $result['success'] ?? false,
            'result' => $result,
        ];
    }
    
    /**
     * Update job with result
     * 
     * @param int $jobId
     * @param array $result
     */
    private function updateJobResult(int $jobId, array $result): void
    {
        $status = $result['success'] ? 'completed' : 'failed';
        
        $stmt = $this->db->prepare(
            "UPDATE ai_optimization_queue 
            SET status = ?, 
                result = ?, 
                completed_at = NOW()
            WHERE id = ?"
        );
        
        $stmt->execute([
            $status,
            json_encode($result['result'] ?? []),
            $jobId
        ]);
    }
    
    /**
     * Mark job as failed
     * 
     * @param int|null $jobId
     * @param string $error
     */
    private function markFailed(?int $jobId, string $error): void
    {
        if (!$jobId) return;
        
        $stmt = $this->db->prepare(
            "UPDATE ai_optimization_queue 
            SET status = 'failed', 
                error_message = ?,
                completed_at = NOW()
            WHERE id = ?"
        );
        
        $stmt->execute([$error, $jobId]);
    }
    
    /**
     * Get batch status
     * 
     * @param string $batchId
     * @return array
     */
    public function getBatchStatus(string $batchId): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                status,
                COUNT(*) as count,
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_duration
            FROM ai_optimization_queue 
            WHERE batch_id = ?
            GROUP BY status"
        );
        
        $stmt->execute([$batchId]);
        $statusCounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $summary = [
            'batch_id' => $batchId,
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'avg_duration' => 0,
        ];
        
        foreach ($statusCounts as $row) {
            $status = $row['status'];
            $count = (int)$row['count'];
            
            $summary['total'] += $count;
            $summary[$status] = $count;
            
            if ($row['avg_duration']) {
                $summary['avg_duration'] = round($row['avg_duration'], 2);
            }
        }
        
        // Calculate progress
        $summary['progress'] = $summary['total'] > 0
            ? round((($summary['completed'] + $summary['failed']) / $summary['total']) * 100, 1)
            : 0;
        
        // Calculate ETA
        if ($summary['avg_duration'] > 0 && $summary['pending'] > 0) {
            $summary['eta_seconds'] = round($summary['pending'] * $summary['avg_duration']);
            $summary['eta_formatted'] = $this->formatDuration($summary['eta_seconds']);
        }
        
        return $summary;
    }
    
    /**
     * Get batch results
     * 
     * @param string $batchId
     * @return array
     */
    public function getBatchResults(string $batchId): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                item_id,
                status,
                result,
                error_message,
                TIMESTAMPDIFF(SECOND, started_at, completed_at) as duration
            FROM ai_optimization_queue 
            WHERE batch_id = ?
            ORDER BY id ASC"
        );
        
        $stmt->execute([$batchId]);
        $jobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $results = [];
        $stats = [
            'total' => count($jobs),
            'successful' => 0,
            'failed' => 0,
            'total_improvement' => 0,
            'avg_improvement' => 0,
        ];
        
        foreach ($jobs as $job) {
            $result = json_decode($job['result'], true) ?? [];
            
            $results[] = [
                'item_id' => $job['item_id'],
                'status' => $job['status'],
                'improvement' => $result['improvement'] ?? 0,
                'score_before' => $result['score_before'] ?? 0,
                'score_after' => $result['score_after'] ?? 0,
                'duration' => $job['duration'],
                'error' => $job['error_message'],
            ];
            
            if ($job['status'] === 'completed') {
                $stats['successful']++;
                $stats['total_improvement'] += $result['improvement'] ?? 0;
            } else if ($job['status'] === 'failed') {
                $stats['failed']++;
            }
        }
        
        if ($stats['successful'] > 0) {
            $stats['avg_improvement'] = round($stats['total_improvement'] / $stats['successful'], 1);
        }
        
        return [
            'batch_id' => $batchId,
            'results' => $results,
            'stats' => $stats,
        ];
    }
    
    /**
     * Clean old completed jobs
     * 
     * @param int $daysOld
     * @return int Number of deleted jobs
     */
    public function cleanOldJobs(int $daysOld = 30): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM ai_optimization_queue 
            WHERE status IN ('completed', 'failed')
            AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        
        $stmt->execute([$daysOld]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Get queue statistics
     * 
     * @return array
     */
    public function getQueueStats(): array
    {
        $stmt = $this->db->query(
            "SELECT 
                status,
                COUNT(*) as count
            FROM ai_optimization_queue
            GROUP BY status"
        );
        
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];
        
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $stats[$row['status']] = (int)$row['count'];
        }
        
        return $stats;
    }
    
    /**
     * Generate unique batch ID
     * 
     * @return string
     */
    private function generateBatchId(): string
    {
        return 'batch_' . date('YmdHis') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Format duration in human readable format
     * 
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        } else if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            return "{$minutes}min";
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return "{$hours}h {$minutes}min";
        }
    }
    
    /**
     * Get database connection
     * 
     * @return \PDO
     */

    /**
     * Get aggregate optimization counts
     * 
     * @return array
     */
    public function getOptimizationCounts(): array
    {
        $stmt = $this->db->query(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as success
            FROM ai_optimization_queue"
        );
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return [
            'total' => (int)($result['total'] ?? 0),
            'success' => (int)($result['success'] ?? 0)
        ];
    }
}
