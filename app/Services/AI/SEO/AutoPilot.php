<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use App\Services\ItemService;
use PDO;

/**
 * 🤖 AUTO-PILOT MODE - Otimização Automática
 *
 * Modo piloto automático para otimização contínua:
 * - Agendamento de otimizações
 * - Monitoramento de performance
 * - Ajustes automáticos
 * - Alertas proativos
 *
 * @author AI Development Team
 * @version 1.0.0
 */
class AutoPilot
{
    private PDO $db;
    private int $accountId;
    private ?MercadoLivreClient $mlClient = null;
    private ?ItemService $itemService = null;

    // Auto-pilot configuration
    private const DEFAULT_CONFIG = [
        'enabled' => false,
        'optimization_frequency' => 'weekly', // daily, weekly, monthly
        'max_items_per_run' => 20,
        'auto_apply' => false, // Whether to apply changes automatically
        'notify_on_completion' => true,
        'optimize_titles' => true,
        'optimize_descriptions' => true,
        'fill_attributes' => true,
        'min_score_threshold' => 70, // Only optimize items below this score
        'excluded_items' => [],
        'priority_categories' => [],
    ];

    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->itemService = new ItemService($accountId);

        $this->ensureTablesExist();
    }

    /**
     * Ensure auto-pilot tables exist
     */
    private function ensureTablesExist(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS seo_autopilot_config (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NOT NULL UNIQUE,
                    config JSON NOT NULL,
                    last_run TIMESTAMP NULL,
                    next_run TIMESTAMP NULL,
                    total_runs INT DEFAULT 0,
                    total_optimizations INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_account (account_id),
                    INDEX idx_next_run (next_run)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS seo_autopilot_runs (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NOT NULL,
                    status ENUM('scheduled', 'running', 'completed', 'failed') DEFAULT 'scheduled',
                    items_analyzed INT DEFAULT 0,
                    items_optimized INT DEFAULT 0,
                    items_skipped INT DEFAULT 0,
                    items_failed INT DEFAULT 0,
                    avg_score_before DECIMAL(5,2) DEFAULT 0,
                    avg_score_after DECIMAL(5,2) DEFAULT 0,
                    details JSON,
                    started_at TIMESTAMP NULL,
                    completed_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_account (account_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS seo_item_scores (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    account_id INT NOT NULL,
                    item_id VARCHAR(50) NOT NULL,
                    score_date DATE NOT NULL,
                    overall_score INT DEFAULT 0,
                    title_score INT DEFAULT 0,
                    description_score INT DEFAULT 0,
                    attributes_score INT DEFAULT 0,
                    images_score INT DEFAULT 0,
                    visibility_score INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_item_date (item_id, score_date),
                    INDEX idx_account (account_id),
                    INDEX idx_item (item_id),
                    INDEX idx_date (score_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            log_error('Falha ao criar tabelas do AutoPilot', [
                'service' => 'AutoPilot',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 🔧 Obter configuração atual
     */
    public function getConfig(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM seo_autopilot_config WHERE account_id = ?");
        $stmt->execute([$this->accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return array_merge(self::DEFAULT_CONFIG, [
                'last_run' => null,
                'next_run' => null,
                'total_runs' => 0,
                'total_optimizations' => 0,
            ]);
        }

        $configData = json_decode((string)$row['config'], true);

        return array_merge(
            self::DEFAULT_CONFIG,
            is_array($configData) ? $configData : [],
            [
                'last_run' => $row['last_run'],
                'next_run' => $row['next_run'],
                'total_runs' => (int)($row['total_runs'] ?? 0),
                'total_optimizations' => (int)($row['total_optimizations'] ?? 0),
            ]
        );
    }

    /**
     * 💾 Salvar configuração
     */
    public function saveConfig(array $config): array
    {
        $config = array_merge(self::DEFAULT_CONFIG, $config);
        $nextRun = $this->calculateNextRun($config);

        $stmt = $this->db->prepare("
            INSERT INTO seo_autopilot_config (account_id, config, next_run)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
            config = VALUES(config),
            next_run = VALUES(next_run),
            updated_at = NOW()
        ");

        $stmt->execute([
            $this->accountId,
            json_encode($config),
            $nextRun
        ]);

        // Sincroniza a flag `enabled` com AutoPilotStatusManager (tabela autopilot_config),
        // que é a fonte de dados do badge de status na dashboard. Sem isso, enable/disable
        // aqui não refletia no status exibido ao usuário (bug conhecido, duas tabelas distintas).
        try {
            (new AutoPilotStatusManager($this->accountId))->setEnabled((bool)($config['enabled'] ?? false));
        } catch (\Exception $e) {
            // Não bloqueia o salvamento principal por falha na sincronização de status.
        }

        return [
            'success' => true,
            'config' => $config,
            'next_run' => $nextRun,
        ];
    }

    /**
     * ▶️ Ativar auto-pilot
     */
    public function enable(): array
    {
        $config = $this->getConfig();
        $config['enabled'] = true;
        return $this->saveConfig($config);
    }

    /**
     * ⏸️ Desativar auto-pilot
     */
    public function disable(): array
    {
        $config = $this->getConfig();
        $config['enabled'] = false;
        return $this->saveConfig($config);
    }

    /**
     * 🚀 Executar otimização automática
     */
    public function run(): array
    {
        $config = $this->getConfig();

        if (!$config['enabled']) {
            return ['error' => 'Auto-pilot está desativado'];
        }

        // Create run record
        $runId = $this->createRun();

        $result = [
            'run_id' => $runId,
            'status' => 'running',
            'items_analyzed' => 0,
            'items_optimized' => 0,
            'items_skipped' => 0,
            'items_failed' => 0,
            'details' => [],
        ];

        try {
            // Get items to optimize
            $items = $this->getItemsToOptimize($config);
            $result['items_analyzed'] = count($items);

            $scoresBefore = [];
            $scoresAfter = [];

            foreach ($items as $item) {
                try {
                    // Calculate score before
                    $scoreBefore = $this->calculateItemScore($item);
                    $scoresBefore[] = $scoreBefore['overall'];

                    // Skip if above threshold
                    if ($scoreBefore['overall'] >= $config['min_score_threshold']) {
                        $result['items_skipped']++;
                        continue;
                    }

                    // Optimize
                    $optimized = $this->optimizeItem($item, $config);

                    if ($optimized['success']) {
                        $result['items_optimized']++;
                        $scoresAfter[] = $optimized['new_score'] ?? $scoreBefore['overall'];

                        // Save score
                        $this->saveItemScore($item['id'], $optimized['score_details'] ?? $scoreBefore);
                    } else {
                        $result['items_failed']++;
                    }

                    $result['details'][] = [
                        'item_id' => $item['id'],
                        'title' => $item['title'],
                        'score_before' => $scoreBefore['overall'],
                        'score_after' => $optimized['new_score'] ?? $scoreBefore['overall'],
                        'optimized' => $optimized['success'],
                    ];

                    // Small delay
                    usleep(500000);
                } catch (\Exception $e) {
                    $result['items_failed']++;
                    $result['details'][] = [
                        'item_id' => $item['id'],
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Calculate averages
            $result['avg_score_before'] = count($scoresBefore) ? round(array_sum($scoresBefore) / count($scoresBefore), 1) : 0;
            $result['avg_score_after'] = count($scoresAfter) ? round(array_sum($scoresAfter) / count($scoresAfter), 1) : 0;
            $result['improvement'] = $result['avg_score_after'] - $result['avg_score_before'];

            // Complete run
            $result['status'] = 'completed';
            $this->completeRun($runId, $result);

            // Update next run
            $this->updateNextRun($config);
        } catch (\Exception $e) {
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
            $this->failRun($runId, $e->getMessage());
        }

        return $result;
    }

    /**
     * 📊 Obter histórico de runs (Legacy alias)
     */
    public function getHistory(int $limit = 20): array
    {
        return $this->getRunHistory($limit);
    }

    /**
     * 📈 Estatísticas consolidadas do AutoPilot
     */
    public function getStats(): array
    {
        // Buscar configuração
        $stmt = $this->db->prepare("
            SELECT total_runs, total_optimizations, last_run, next_run
            FROM seo_autopilot_config
            WHERE account_id = ?
        ");
        $stmt->execute([$this->accountId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($config)) {
            $config = [];
        }

        // Estatísticas dos últimos 30 dias
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as runs_last_30_days,
                SUM(items_optimized) as items_optimized_30d,
                AVG(avg_score_after - avg_score_before) as avg_improvement,
                SUM(items_failed) as total_failures
            FROM seo_autopilot_runs
            WHERE account_id = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND status = 'completed'
        ");
        $stmt->execute([$this->accountId]);
        $recent = $stmt->fetch(PDO::FETCH_ASSOC);

        // Última run
        $stmt = $this->db->prepare("
            SELECT status, items_optimized, avg_score_before, avg_score_after,
                   completed_at
            FROM seo_autopilot_runs
            WHERE account_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$this->accountId]);
        $lastRun = $stmt->fetch(PDO::FETCH_ASSOC);

        // Score médio atual dos itens
        $stmt = $this->db->prepare("
            SELECT AVG(overall_score) as current_avg_score
            FROM seo_item_scores
            WHERE account_id = ?
            AND score_date = CURDATE()
        ");
        $stmt->execute([$this->accountId]);
        $currentScore = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_runs' => (int)($config['total_runs'] ?? 0),
            'total_optimizations' => (int)($config['total_optimizations'] ?? 0),
            'last_run' => $config['last_run'] ?? null,
            'next_run' => $config['next_run'] ?? null,
            'runs_last_30_days' => (int)($recent['runs_last_30_days'] ?? 0),
            'items_optimized_30d' => (int)($recent['items_optimized_30d'] ?? 0),
            'avg_improvement' => round((float)($recent['avg_improvement'] ?? 0), 2),
            'total_failures' => (int)($recent['total_failures'] ?? 0),
            'current_avg_score' => round((float)($currentScore['current_avg_score'] ?? 0), 2),
            'last_run_details' => $lastRun,
        ];
    }

    /**
     * 📈 Obter evolução de scores
     */
    public function getScoreEvolution(int $days = 30): array
    {
        // Fonte principal: seo_scores_history (SEOScoreCalculator).
        // Fallback: seo_item_scores (AutoPilot).
        $stmt = $this->db->prepare("
            SELECT
                DATE(created_at) AS score_date,
                AVG(overall_score) AS avg_score,
                COUNT(*) AS items_count
            FROM seo_scores_history
            WHERE account_id = ?
            AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY score_date ASC
        ");
        $stmt->execute([$this->accountId, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            $stmt = $this->db->prepare("
                SELECT
                    score_date,
                    AVG(overall_score) as avg_score,
                    AVG(title_score) as avg_title,
                    AVG(description_score) as avg_description,
                    AVG(attributes_score) as avg_attributes,
                    COUNT(*) as items_count
                FROM seo_item_scores
                WHERE account_id = ?
                AND score_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                GROUP BY score_date
                ORDER BY score_date ASC
            ");
            $stmt->execute([$this->accountId, $days]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Contrato UI (performance-tracker-tab): date + score
        return array_map(static function (array $row): array {
            $date = (string) ($row['score_date'] ?? '');
            $score = round((float) ($row['avg_score'] ?? 0), 1);
            return array_merge($row, [
                'date' => $date,
                'score' => $score,
            ]);
        }, $rows);
    }

    /**
     * 🎯 Calcular score do item
     */
    public function calculateItemScore(array $item): array
    {
        $scores = [
            'title' => $this->scoreTi($item),
            'description' => $this->scoreDescription($item),
            'attributes' => $this->scoreAttributes($item),
            'images' => $this->scoreImages($item),
            'visibility' => $this->scoreVisibility($item),
        ];

        // Weighted average
        $weights = [
            'title' => 25,
            'description' => 20,
            'attributes' => 25,
            'images' => 15,
            'visibility' => 15,
        ];

        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($scores as $key => $score) {
            $weightedSum += $score * $weights[$key];
            $totalWeight += $weights[$key];
        }

        $scores['overall'] = (int) round($weightedSum / $totalWeight);
        $scores['grade'] = $this->getGrade($scores['overall']);

        return $scores;
    }

    // Scoring methods

    private function scoreTi(array $item): int
    {
        $score = 100;
        $title = $item['title'] ?? '';
        $len = mb_strlen($title);

        if ($len < 30) $score -= 30;
        elseif ($len < 40) $score -= 15;
        elseif ($len > 60) $score -= 10;

        if (!preg_match('/\d/', $title)) $score -= 10;
        if ($title === mb_strtoupper($title)) $score -= 15;

        return max(0, $score);
    }

    private function scoreDescription(array $item): int
    {
        // Would need to fetch description
        return 70; // Default for now
    }

    private function scoreAttributes(array $item): int
    {
        $attrCount = count($item['attributes'] ?? []);

        if ($attrCount >= 20) return 100;
        if ($attrCount >= 15) return 85;
        if ($attrCount >= 10) return 70;
        if ($attrCount >= 5) return 50;
        return 30;
    }

    private function scoreImages(array $item): int
    {
        $imgCount = count($item['pictures'] ?? []);

        if ($imgCount >= 6) return 100;
        if ($imgCount >= 4) return 80;
        if ($imgCount >= 2) return 60;
        if ($imgCount >= 1) return 40;
        return 0;
    }

    private function scoreVisibility(array $item): int
    {
        $score = 50;

        if ($item['shipping']['free_shipping'] ?? false) $score += 25;
        if (($item['sold_quantity'] ?? 0) > 0) $score += 15;
        if ($item['listing_type_id'] !== 'free') $score += 10;

        return min(100, $score);
    }

    private function getGrade(int $score): string
    {
        if ($score >= 90) return 'A+';
        if ($score >= 80) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 60) return 'C';
        if ($score >= 50) return 'D';
        return 'F';
    }

    // Private helpers

    private function getItemsToOptimize(array $config): array
    {
        try {
            $result = $this->itemService->listItems([
                'limit' => $config['max_items_per_run'],
                'status' => 'active'
            ]);

            $items = $result['items'] ?? [];

            // Filter excluded items
            if (!empty($config['excluded_items'])) {
                $items = array_filter(
                    $items,
                    fn(array $i): bool =>
                    !in_array($i['id'], $config['excluded_items'])
                );
            }

            return $items;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function optimizeItem(array $item, array $config): array
    {
        $optimizer = new BulkOptimizer($this->accountId);

        $result = $optimizer->optimizeSingleItem($item['id'], [
            'optimize_title' => $config['optimize_titles'],
            'optimize_description' => $config['optimize_descriptions'],
            'fill_attributes' => $config['fill_attributes'],
            'apply' => $config['auto_apply'],
        ]);

        return $result;
    }

    private function calculateNextRun(array $config): string
    {
        $now = new \DateTime();

        switch ($config['optimization_frequency']) {
            case 'daily':
                $now->modify('+1 day');
                break;
            case 'weekly':
                $now->modify('+1 week');
                break;
            case 'monthly':
                $now->modify('+1 month');
                break;
            default:
                $now->modify('+1 week');
        }

        return $now->format('Y-m-d H:i:s');
    }

    private function updateNextRun(array $config): void
    {
        $nextRun = $this->calculateNextRun($config);

        $stmt = $this->db->prepare("
            UPDATE seo_autopilot_config
            SET last_run = NOW(),
                next_run = ?,
                total_runs = total_runs + 1
            WHERE account_id = ?
        ");
        $stmt->execute([$nextRun, $this->accountId]);
    }

    private function createRun(): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO seo_autopilot_runs (account_id, status, started_at)
            VALUES (?, 'running', NOW())
        ");
        $stmt->execute([$this->accountId]);

        return (int) $this->db->lastInsertId();
    }

    private function completeRun(int $runId, array $result): void
    {
        $stmt = $this->db->prepare("
            UPDATE seo_autopilot_runs
            SET status = 'completed',
                items_analyzed = ?,
                items_optimized = ?,
                items_skipped = ?,
                items_failed = ?,
                avg_score_before = ?,
                avg_score_after = ?,
                details = ?,
                completed_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $result['items_analyzed'],
            $result['items_optimized'],
            $result['items_skipped'],
            $result['items_failed'],
            $result['avg_score_before'],
            $result['avg_score_after'],
            json_encode($result['details']),
            $runId
        ]);

        // Update total optimizations
        $stmt = $this->db->prepare("
            UPDATE seo_autopilot_config
            SET total_optimizations = total_optimizations + ?
            WHERE account_id = ?
        ");
        $stmt->execute([$result['items_optimized'], $this->accountId]);
    }

    private function failRun(int $runId, string $error): void
    {
        $stmt = $this->db->prepare("
            UPDATE seo_autopilot_runs
            SET status = 'failed',
                details = ?,
                completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([json_encode(['error' => $error]), $runId]);
    }

    /**
     * 📜 Obter histórico de execuções
     */
    public function getRunHistory(int $limit = 20): array
    {
        $limitSql = max(1, min(200, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT * FROM seo_autopilot_runs
            WHERE account_id = ?
            ORDER BY completed_at DESC
            LIMIT {$limitSql}
        ");
        $stmt->execute([$this->accountId]);
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($runs as &$run) {
            $run['details'] = json_decode($run['details'], true);
        }
        return ['runs' => $runs];
    }

    /**
     * 📈 Obter evolução de scores (Legacy alias)
     */
    public function getEvolution(int $days = 30): array
    {
        return $this->getScoreEvolution($days);
    }

    private function saveItemScore(string $itemId, array $scores): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO seo_item_scores
            (account_id, item_id, score_date, overall_score, title_score,
             description_score, attributes_score, images_score, visibility_score)
            VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            overall_score = VALUES(overall_score),
            title_score = VALUES(title_score),
            description_score = VALUES(description_score),
            attributes_score = VALUES(attributes_score),
            images_score = VALUES(images_score),
            visibility_score = VALUES(visibility_score)
        ");

        $stmt->execute([
            $this->accountId,
            $itemId,
            $scores['overall'] ?? 0,
            $scores['title'] ?? 0,
            $scores['description'] ?? 0,
            $scores['attributes'] ?? 0,
            $scores['images'] ?? 0,
            $scores['visibility'] ?? 0,
        ]);
    }

    /**
     * Get details of a specific run
     */
    public function getRunDetails(int $runId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM seo_autopilot_runs
            WHERE id = ? AND account_id = ?
        ");
        $stmt->execute([$runId, $this->accountId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$run) {
            return ['error' => 'Execução não encontrada'];
        }

        // Decode JSON details
        if (isset($run['details'])) {
            $run['details'] = json_decode($run['details'], true);
        }

        return $run;
    }
}
