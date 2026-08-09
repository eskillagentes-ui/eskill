<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use App\Services\ItemService;
use PDO;
use App\Services\JobService;

/**
 * 🚀 BULK OPTIMIZER - Otimização em Massa
 * 
 * Otimiza múltiplos anúncios de uma vez:
 * - Seleção inteligente de itens prioritários
 * - Processamento em batch
 * - Tracking de progresso
 * - Relatório consolidado
 * 
 * @author AI Development Team
 * @version 1.0.0
 */
class BulkOptimizer
{
    private PDO $db;
    private int $accountId;
    private ?MercadoLivreClient $mlClient = null;
    private ?ItemService $itemService = null;
    
    // Optimization engines
    private ?TitleKiller $titleKiller = null;
    private ?DescriptionKiller $descKiller = null;
    private ?AttributeKiller $attrKiller = null;
    
    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->itemService = new ItemService($accountId);
        
        $this->titleKiller = new TitleKiller($accountId);
        $this->descKiller = new DescriptionKiller($accountId);
        $this->attrKiller = new AttributeKiller($accountId);
        
        $this->ensureTableExists();
    }
    
    /**
     * Ensure batch jobs table exists
     */
    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS seo_bulk_jobs (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NOT NULL,
                    job_type ENUM('full', 'title', 'description', 'attributes') DEFAULT 'full',
                    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
                    total_items INT DEFAULT 0,
                    processed_items INT DEFAULT 0,
                    successful_items INT DEFAULT 0,
                    failed_items INT DEFAULT 0,
                    item_ids JSON,
                    results JSON,
                    options JSON,
                    started_at TIMESTAMP NULL,
                    completed_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_account (account_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            log_error('Falha ao criar tabela seo_bulk_jobs', [
                'service' => 'BulkOptimizer',
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 🎯 Selecionar itens prioritários para otimização
     */
    public function selectPriorityItems(int $limit = 50): array
    {
        $items = [];
        $allItems = [];
        
        try {
            // Get all active items
            $result = $this->itemService->listItems(['limit' => 100, 'status' => 'active']);
            $allItems = $result['items'] ?? [];
            
            // Score each item for optimization priority
            $scoredItems = [];
            
            foreach ($allItems as $item) {
                $score = $this->calculateOptimizationPriority($item);
                
                $scoredItems[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'thumbnail' => $item['thumbnail'] ?? null,
                    'category_id' => $item['category_id'] ?? null,
                    'price' => $item['price'] ?? 0,
                    'sold_quantity' => $item['sold_quantity'] ?? 0,
                    'priority_score' => $score['total'],
                    'score' => max(0, 100 - (int)$score['total']),
                    'issues' => $score['issues'],
                    'potential_improvement' => $score['potential'],
                ];
            }
            
            // Sort by priority score (highest first)
            usort($scoredItems, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);
            
            $items = array_slice($scoredItems, 0, $limit);
            
        } catch (\Exception $e) {
            return [
                'error' => 'ml_items_unavailable',
                'message' => 'Não foi possível buscar anúncios do Mercado Livre. Verifique se a conta está conectada e com token válido.',
                'items' => [],
                'total_available' => 0,
                'selected' => 0,
                'estimated_time_minutes' => 0,
            ];
        }
        
        return [
            'items' => $items,
            'total_available' => count($allItems ?? []),
            'selected' => count($items),
            'estimated_time_minutes' => count($items) * 0.5, // ~30 sec per item
        ];
    }
    
    /**
     * 🚀 Iniciar otimização em massa
     */
    public function startBulkOptimization(array $itemIds, array $options = []): array
    {
        // Create job
        $jobId = $this->createJob($itemIds, $options);
        
        // Return job info for async processing
        return [
            'job_id' => $jobId,
            'status' => 'pending',
            'total_items' => count($itemIds),
            'message' => 'Job criado. Use getJobStatus() para acompanhar.',
        ];
    }
    
    /**
     * ⚡ Processar job de otimização
     */
    public function processJob(int $jobId): array
    {
        // Get job
        $stmt = $this->db->prepare("SELECT * FROM seo_bulk_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            return ['error' => 'Job não encontrado'];
        }
        
        if ($job['status'] === 'completed') {
            return ['status' => 'completed', 'message' => 'Job já foi processado'];
        }
        
        // Update to running
        $this->updateJobStatus($jobId, 'running');
        
        $itemIds = json_decode($job['item_ids'], true) ?? [];
        $options = json_decode($job['options'], true) ?? [];
        
        $results = [];
        $processed = 0;
        $successful = 0;
        $failed = 0;
        
        foreach ($itemIds as $itemId) {
            try {
                $result = $this->optimizeItem($itemId, $options);
                $results[$itemId] = $result;
                
                if ($result['success']) {
                    $successful++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $results[$itemId] = ['success' => false, 'error' => $e->getMessage()];
                $failed++;
            }
            
            $processed++;
            
            // Update progress
            $this->updateJobProgress($jobId, $processed, $successful, $failed);
            
            // Small delay to avoid rate limiting
            usleep(500000); // 0.5 seconds
        }
        
        // Complete job
        $this->completeJob($jobId, $results);
        
        return [
            'job_id' => $jobId,
            'status' => 'completed',
            'total' => count($itemIds),
            'successful' => $successful,
            'failed' => $failed,
            'results' => $results,
        ];
    }
    
    /**
     * 📊 Obter status do job
     */
    public function getJobStatus(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM seo_bulk_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            return ['error' => 'Job não encontrado'];
        }
        
        return [
            'job_id' => $job['id'],
            'status' => $job['status'],
            'progress' => [
                'total' => $job['total_items'],
                'processed' => $job['processed_items'],
                'successful' => $job['successful_items'],
                'failed' => $job['failed_items'],
                'percentage' => $job['total_items'] > 0 
                    ? round(($job['processed_items'] / $job['total_items']) * 100) 
                    : 0,
            ],
            'started_at' => $job['started_at'],
            'completed_at' => $job['completed_at'],
            'results' => $job['status'] === 'completed' ? json_decode($job['results'], true) : null,
        ];
    }
    
    /**
     * 📋 Listar jobs recentes
     */
    public function listJobs(int $limit = 10): array
    {
        $limitSql = max(1, min(200, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT id, job_type, status, total_items, processed_items, 
                   successful_items, failed_items, created_at, completed_at
            FROM seo_bulk_jobs
            WHERE account_id = ?
            ORDER BY created_at DESC
            LIMIT {$limitSql}
        ");
        $stmt->execute([$this->accountId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 🚀 Processar job em background (sem bloquear request)
     * 
     * Cria o job como 'pending' e retorna imediatamente.
     * O worker (bin/seo-worker.php) irá processá-lo.
     * 
     * @param array $itemIds IDs dos itens a otimizar
     * @param array $options Opções de otimização
     * @return array Job criado
     */
    public function startJobInBackground(array $itemIds, array $options = []): array
    {
        $jobType = 'full';
        if (isset($options['optimize_titles_only']) && $options['optimize_titles_only']) {
            $jobType = 'title';
        } elseif (isset($options['optimize_descriptions_only']) && $options['optimize_descriptions_only']) {
            $jobType = 'description';
        } elseif (isset($options['fill_attributes_only']) && $options['fill_attributes_only']) {
            $jobType = 'attributes';
        }
        
        // Criar job como 'pending'
        $stmt = $this->db->prepare("
            INSERT INTO seo_bulk_jobs (
                account_id, job_type, status, total_items, 
                item_ids, options, created_at
            ) VALUES (?, ?, 'pending', ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $this->accountId,
            $jobType,
            count($itemIds),
            json_encode($itemIds),
            json_encode($options),
        ]);
        
        $jobId = (int)$this->db->lastInsertId();

        // 🚀 Dispatch via JobService
        try {
            $jobService = new JobService();
            $jobIdInternal = $jobService->dispatch('bulk_optimize_exec', [
                'seo_job_id' => $jobId
            ]);
            log_info('BulkOptimizer: job despachado via JobService', [
                'service' => 'BulkOptimizer',
                'seo_job_id' => $jobId,
                'internal_job_id' => $jobIdInternal,
            ]);
        } catch (\Exception $e) {
            log_warning('BulkOptimizer: falha ao despachar job', [
                'service' => 'BulkOptimizer',
                'seo_job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
        
        return [
            'job_id' => $jobId,
            'status' => 'pending',
            'total_items' => count($itemIds),
            'message' => 'Job criado e será processado em background',
        ];
    }
    
    /**
     * 🔄 Processar próximo job pendente (usado pelo worker)
     * 
     * Busca o job mais antigo com status 'pending' e processa
     * 
     * @return array|null Resultado do processamento ou null se não há jobs
     */
    public function processNextPendingJob(): ?array
    {
        // Lock o próximo job pendente
        $stmt = $this->db->prepare("
            SELECT * FROM seo_bulk_jobs 
            WHERE status = 'pending' 
            ORDER BY created_at ASC 
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute();
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            return null; // Nenhum job pendente
        }
        
        $jobId = $job['id'];
        log_info('BulkOptimizer Worker: processando job', [
            'service' => 'BulkOptimizer',
            'job_id' => $jobId,
        ]);
        
        // Marcar como running
        $this->db->prepare("
            UPDATE seo_bulk_jobs 
            SET status = 'running', started_at = NOW() 
            WHERE id = ?
        ")->execute([$jobId]);
        
        try {
            // Processar o job
            $itemIds = json_decode($job['item_ids'], true);
            $options = json_decode($job['options'], true) ?? [];
            
            $result = $this->processBatch($itemIds, $options, $jobId);
            
            // Marcar como completed
            $this->db->prepare("
                UPDATE seo_bulk_jobs 
                SET status = 'completed', completed_at = NOW(), results = ?
                WHERE id = ?
            ")->execute([json_encode($result), $jobId]);
            
            log_info('BulkOptimizer Worker: job concluído com sucesso', [
                'service' => 'BulkOptimizer',
                'job_id' => $jobId,
            ]);
            
            return [
                'job_id' => $jobId,
                'status' => 'completed',
                'result' => $result,
            ];
            
        } catch (\Exception $e) {
            // Marcar como failed
            $errorMsg = $e->getMessage();
            log_error('BulkOptimizer Worker: job falhou', [
                'service' => 'BulkOptimizer',
                'job_id' => $jobId,
                'error' => $errorMsg,
            ]);
            
            $this->db->prepare("
                UPDATE seo_bulk_jobs 
                SET status = 'failed', completed_at = NOW(), results = ?
                WHERE id = ?
            ")->execute([json_encode(['error' => $errorMsg]), $jobId]);
            
            return [
                'job_id' => $jobId,
                'status' => 'failed',
                'error' => $errorMsg,
            ];
        }
    }

    /**
     * Otimiza um item individual (API pública para uso por outros serviços).
     */
    public function optimizeSingleItem(string $itemId, array $options = []): array
    {
        return $this->optimizeItem($itemId, $options);
    }

    /**
     * Processa um lote de itens para um job.
     *
     * @param array $itemIds
     * @param array $options
     * @param int $jobId
     */
    private function processBatch(array $itemIds, array $options, int $jobId): array
    {
        $total = count($itemIds);
        $processed = 0;
        $success = 0;
        $failed = 0;
        $results = [];

        $progressStmt = $this->db->prepare("
            UPDATE seo_bulk_jobs
            SET total_items = ?, processed_items = ?, successful_items = ?, failed_items = ?
            WHERE id = ?
        ");

        $progressStmt->execute([$total, $processed, $success, $failed, $jobId]);

        foreach ($itemIds as $rawId) {
            $itemId = is_string($rawId) ? $rawId : (string)$rawId;
            if ($itemId === '') {
                continue;
            }

            $itemResult = $this->optimizeItem($itemId, $options);
            $results[] = $itemResult;

            $processed++;
            if (!empty($itemResult['success'])) {
                $success++;
            } else {
                $failed++;
            }

            // Atualizar progresso a cada 5 itens (e no final) para reduzir I/O
            if (($processed % 5) === 0 || $processed === $total) {
                $progressStmt->execute([$total, $processed, $success, $failed, $jobId]);
            }
        }

        return [
            'job_id' => $jobId,
            'total_items' => $total,
            'processed_items' => $processed,
            'successful_items' => $success,
            'failed_items' => $failed,
            'results' => $results,
        ];
    }
    
    /**
     * 🎯 Otimizar um item individual
     */
    private function optimizeItem(string $itemId, array $options): array
    {
        $result = [
            'item_id' => $itemId,
            'success' => false,
            'optimizations' => [],
            'errors' => [],
        ];
        
        try {
            // Get item data
            $item = $this->mlClient->get("/items/{$itemId}");

            if (!is_array($item) || isset($item['error'])) {
                $result['errors'][] = $item['message'] ?? ($item['error'] ?? 'Falha ao buscar item na API do Mercado Livre');
                return $result;
            }
            
            $productData = [
                'title' => $item['title'],
                'brand' => $this->extractAttribute($item, 'BRAND'),
                'model' => $this->extractAttribute($item, 'MODEL'),
                'price' => $item['price'],
                'attributes' => $item['attributes'] ?? [],
                'category_id' => $item['category_id'],
            ];
            
            $updates = [];

            // Governança: escrita real no ML respeita SAFE_MODE/FORBIDDEN_ACCOUNTS
            // (mesma proteção usada pelo Hidden SEO/Ficha Técnica). Conta 1335 (produção
            // FACILYTY) nunca aplica automaticamente via AutoPilot/BulkOptimizer.
            $applyRequested = (bool)($options['apply'] ?? false);
            $canApply = $applyRequested;
            if ($applyRequested) {
                try {
                    (new \App\Services\HiddenSeo\SafetyGuard())->assertCanApply($this->accountId, false, true);
                } catch (\Throwable $e) {
                    $canApply = false;
                    $result['errors'][] = 'Apply bloqueado: ' . $e->getMessage();
                }
            }
            
            // Optimize title
            if ($options['optimize_title'] ?? true) {
                $titleResult = $this->titleKiller->generateKillerTitle($productData);
                if ($titleResult['success'] && !empty($titleResult['primary'])) {
                    $result['optimizations']['title'] = [
                        'before' => $item['title'],
                        'after' => $titleResult['primary'],
                        'score' => $titleResult['seo_score'],
                    ];
                    
                    if ($canApply) {
                        $updates['title'] = $titleResult['primary'];
                    }
                }
            }
            
            // Optimize description
            if ($options['optimize_description'] ?? true) {
                $descResult = $this->descKiller->generateKillerDescription($productData);
                if ($descResult['success']) {
                    $result['optimizations']['description'] = [
                        'char_count' => $descResult['char_count'],
                        'score' => $descResult['seo_score'],
                    ];
                    
                    if ($canApply) {
                        try {
                            $this->mlClient->put("/items/{$itemId}/description", [
                                'plain_text' => $descResult['description']
                            ]);
                            $result['optimizations']['description']['applied'] = true;
                        } catch (\Exception $e) {
                            $result['errors'][] = "Description: " . $e->getMessage();
                        }
                    }
                }
            }
            
            // Fill attributes
            if ($options['fill_attributes'] ?? true) {
                $attrResult = $this->attrKiller->analyzeGaps($itemId, $item['category_id']);
                $result['optimizations']['attributes'] = [
                    'completeness' => $attrResult['completeness'],
                    'missing' => $attrResult['missing'],
                ];
                
                if ($canApply && $attrResult['missing'] > 0) {
                    $fillResult = $this->attrKiller->fillMissingAttributes($itemId, $item['category_id'], $item);
                    $result['optimizations']['attributes']['filled'] = count($fillResult['filled'] ?? []);
                }
            }
            
            // Apply item updates
            if (!empty($updates) && $canApply) {
                try {
                    $this->mlClient->put("/items/{$itemId}", $updates);
                    $result['optimizations']['applied'] = true;
                } catch (\Exception $e) {
                    $result['errors'][] = "Update: " . $e->getMessage();
                }
            }
            
            $result['success'] = empty($result['errors']);
            
        } catch (\Exception $e) {
            $result['errors'][] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Calcular prioridade de otimização
     */
    private function calculateOptimizationPriority(array $item): array
    {
        $score = 0;
        $issues = [];
        
        // Title length
        $titleLen = mb_strlen($item['title'] ?? '');
        if ($titleLen < 40) {
            $score += 30;
            $issues[] = 'Título curto';
        } elseif ($titleLen > 60) {
            $score += 10;
            $issues[] = 'Título pode ser cortado';
        }
        
        // Images
        $imageCount = count($item['pictures'] ?? []);
        if ($imageCount < 4) {
            $score += 20;
            $issues[] = 'Poucas imagens';
        }
        
        // Attributes
        $attrCount = count($item['attributes'] ?? []);
        if ($attrCount < 10) {
            $score += 25;
            $issues[] = 'Poucos atributos';
        }
        
        // No sales
        if (($item['sold_quantity'] ?? 0) === 0) {
            $score += 15;
            $issues[] = 'Sem vendas';
        }
        
        // No free shipping
        if (!($item['shipping']['free_shipping'] ?? false)) {
            $score += 10;
            $issues[] = 'Sem frete grátis';
        }
        
        return [
            'total' => $score,
            'issues' => $issues,
            'potential' => min(100, $score * 2) . '%',
        ];
    }
    
    // Database helpers
    
    private function createJob(array $itemIds, array $options): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO seo_bulk_jobs 
            (account_id, job_type, total_items, item_ids, options)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->accountId,
            $options['job_type'] ?? 'full',
            count($itemIds),
            json_encode($itemIds),
            json_encode($options),
        ]);
        
        return (int) $this->db->lastInsertId();
    }
    
    private function updateJobStatus(int $jobId, string $status): void
    {
        $stmt = $this->db->prepare("
            UPDATE seo_bulk_jobs 
            SET status = ?, started_at = COALESCE(started_at, NOW())
            WHERE id = ?
        ");
        $stmt->execute([$status, $jobId]);
    }
    
    private function updateJobProgress(int $jobId, int $processed, int $successful, int $failed): void
    {
        $stmt = $this->db->prepare("
            UPDATE seo_bulk_jobs 
            SET processed_items = ?, successful_items = ?, failed_items = ?
            WHERE id = ?
        ");
        $stmt->execute([$processed, $successful, $failed, $jobId]);
    }
    
    private function completeJob(int $jobId, array $results): void
    {
        $stmt = $this->db->prepare("
            UPDATE seo_bulk_jobs 
            SET status = 'completed', completed_at = NOW(), results = ?
            WHERE id = ?
        ");
        $stmt->execute([json_encode($results), $jobId]);
    }
    
    /**
     * 📊 Dashboard de monitoramento completo
     * 
     * Retorna estatísticas consolidadas dos jobs
     * 
     * @return array Estatísticas
     */
    public function getMonitorDashboard(): array
    {
        // Jobs por status
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as count 
            FROM seo_bulk_jobs 
            WHERE account_id = ?
            GROUP BY status
        ");
        $stmt->execute([$this->accountId]);
        $byStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Jobs recentes (últimos 20)
        $stmt = $this->db->prepare("
            SELECT id, job_type, status, total_items, processed_items, 
                   successful_items, failed_items, created_at, started_at, 
                   completed_at, error_message
            FROM seo_bulk_jobs 
            WHERE account_id = ?
            ORDER BY created_at DESC 
            LIMIT 20
        ");
        $stmt->execute([$this->accountId]);
        $recentJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Estatísticas gerais
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_jobs,
                SUM(total_items) as total_items_processed,
                SUM(successful_items) as total_successful,
                SUM(failed_items) as total_failed,
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_duration_seconds
            FROM seo_bulk_jobs 
            WHERE account_id = ? AND status IN ('completed', 'failed')
        ");
        $stmt->execute([$this->accountId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Jobs em execução
        $stmt = $this->db->prepare("
            SELECT id, job_type, total_items, processed_items, 
                   started_at, TIMESTAMPDIFF(SECOND, started_at, NOW()) as running_seconds
            FROM seo_bulk_jobs 
            WHERE account_id = ? AND status = 'running'
            ORDER BY started_at ASC
        ");
        $stmt->execute([$this->accountId]);
        $runningJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'stats' => [
                'total_jobs' => (int)($stats['total_jobs'] ?? 0),
                'total_items_processed' => (int)($stats['total_items_processed'] ?? 0),
                'total_successful' => (int)($stats['total_successful'] ?? 0),
                'total_failed' => (int)($stats['total_failed'] ?? 0),
                'avg_duration_seconds' => (int)($stats['avg_duration_seconds'] ?? 0),
                'pending' => (int)($byStatus['pending'] ?? 0),
                'running' => (int)($byStatus['running'] ?? 0),
                'completed' => (int)($byStatus['completed'] ?? 0),
                'failed' => (int)($byStatus['failed'] ?? 0),
            ],
            'recent_jobs' => $recentJobs,
            'running_jobs' => $runningJobs,
        ];
    }
    
    /**
     * ❌ Cancelar job
     * 
     * @param int $jobId ID do job
     * @return array Resultado
     */
    public function cancelJob(int $jobId): array
    {
        // Verificar se job existe e pertence a esta conta
        $stmt = $this->db->prepare("
            SELECT status FROM seo_bulk_jobs 
            WHERE id = ? AND account_id = ?
        ");
        $stmt->execute([$jobId, $this->accountId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            return ['error' => 'Job não encontrado'];
        }
        
        if ($job['status'] === 'completed') {
            return ['error' => 'Job já foi concluído, não pode ser cancelado'];
        }
        
        if ($job['status'] === 'cancelled') {
            return ['error' => 'Job já está cancelado'];
        }
        
        // Cancelar job
        $stmt = $this->db->prepare("
            UPDATE seo_bulk_jobs 
            SET status = 'cancelled', completed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);
        
        return [
            'success' => true,
            'message' => 'Job cancelado com sucesso',
            'job_id' => $jobId,
        ];
    }
    
    /**
     * 🔄 Reprocessar job falhado
     * 
     * Cria novo job com os mesmos parâmetros
     * 
     * @param int $jobId ID do job falhado
     * @return array Novo job criado
     */
    public function retryJob(int $jobId): array
    {
        // Buscar job original
        $stmt = $this->db->prepare("
            SELECT item_ids, options FROM seo_bulk_jobs 
            WHERE id = ? AND account_id = ?
        ");
        $stmt->execute([$jobId, $this->accountId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            return ['error' => 'Job não encontrado'];
        }
        
        $itemIds = json_decode($job['item_ids'], true);
        $options = json_decode($job['options'], true) ?? [];
        
        // Criar novo job
        return $this->startJobInBackground($itemIds, $options);
    }
    
    private function extractAttribute(array $item, string $attrId): ?string
    {
        foreach ($item['attributes'] ?? [] as $attr) {
            if ($attr['id'] === $attrId) {
                return $attr['value_name'] ?? null;
            }
        }
        return null;
    }
}
