<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Service para tracking granular de progresso de clonagem
 *
 * Rastreia o progresso detalhado por fase:
 * - Validação de itens
 * - Preparação de dados
 * - Publicação no ML
 * - Pós-ações (templates, pricing)
 *
 * Fornece:
 * - Porcentagem por fase
 * - ETA estimado
 * - Status em tempo real
 * - Histórico de progresso
 *
 * @package App\Services
 */
class CloneProgressTrackerService
{
    private PDO $db;

    // Fases do processo de clonagem
    public const PHASE_VALIDATION = 'validation';
    public const PHASE_PREPARATION = 'preparation';
    public const PHASE_PUBLICATION = 'publication';
    public const PHASE_POST_ACTIONS = 'post_actions';
    public const PHASE_COMPLETED = 'completed';

    // Pesos de cada fase (total = 100%)
    private const PHASE_WEIGHTS = [
        self::PHASE_VALIDATION => 10,
        self::PHASE_PREPARATION => 20,
        self::PHASE_PUBLICATION => 50,
        self::PHASE_POST_ACTIONS => 20,
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Inicializar tracking para um job
     *
     * @param int $jobId
     * @param int $totalItems
     * @return bool
     */
    public function initializeJobTracking(int $jobId, int $totalItems): bool
    {
        try {
            $sql = "
                INSERT INTO clone_progress_tracking
                (job_id, total_items, current_phase, phase_progress, overall_progress, started_at)
                VALUES (:job_id, :total_items, :phase, 0, 0, NOW())
                ON DUPLICATE KEY UPDATE
                total_items = :total_items,
                current_phase = :phase,
                phase_progress = 0,
                overall_progress = 0,
                started_at = NOW()
            ";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'job_id' => $jobId,
                'total_items' => $totalItems,
                'phase' => self::PHASE_VALIDATION,
            ]);
        } catch (\Exception $e) {
            log_error('Erro ao inicializar tracking de clone', [
                'job_id' => $jobId,
                'total_items' => $totalItems,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Atualizar progresso de uma fase
     *
     * @param int $jobId
     * @param string $phase Fase atual
     * @param int $itemsProcessed Items processados nesta fase
     * @param int $totalItems Total de items
     * @return bool
     */
    public function updatePhaseProgress(int $jobId, string $phase, int $itemsProcessed, int $totalItems): bool
    {
        try {
            // Calcular progresso da fase atual (0-100)
            $phaseProgress = $totalItems > 0
                ? round(($itemsProcessed / $totalItems) * 100, 2)
                : 0;

            // Calcular progresso geral considerando pesos das fases
            $overallProgress = $this->calculateOverallProgress($phase, $phaseProgress);

            // Estimar tempo restante
            $eta = $this->estimateTimeRemaining($jobId, $overallProgress);

            $sql = "
                UPDATE clone_progress_tracking
                SET current_phase = :phase,
                    phase_progress = :phase_progress,
                    overall_progress = :overall_progress,
                    eta_seconds = :eta,
                    updated_at = NOW()
                WHERE job_id = :job_id
            ";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                'job_id' => $jobId,
                'phase' => $phase,
                'phase_progress' => $phaseProgress,
                'overall_progress' => $overallProgress,
                'eta' => $eta,
            ]);

            // Registrar histórico
            if ($result) {
                $this->logPhaseProgress($jobId, $phase, $phaseProgress, $itemsProcessed);
            }

            return $result;
        } catch (\Exception $e) {
            log_error('Erro ao atualizar progresso de clone', [
                'job_id' => $jobId,
                'phase' => $phase,
                'items_processed' => $itemsProcessed,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Avançar para próxima fase
     *
     * @param int $jobId
     * @param string $nextPhase
     * @return bool
     */
    public function advanceToPhase(int $jobId, string $nextPhase): bool
    {
        try {
            // Marcar fase anterior como completa (100%)
            $currentPhase = $this->getCurrentPhase($jobId);
            if ($currentPhase) {
                $this->updatePhaseProgress(
                    $jobId,
                    $currentPhase,
                    $this->getTotalItems($jobId),
                    $this->getTotalItems($jobId)
                );
            }

            // Avançar para próxima fase
            $sql = "
                UPDATE clone_progress_tracking
                SET current_phase = :phase,
                    phase_progress = 0,
                    updated_at = NOW()
                WHERE job_id = :job_id
            ";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'job_id' => $jobId,
                'phase' => $nextPhase,
            ]);
        } catch (\Exception $e) {
            log_error('Erro ao avançar fase de clone', [
                'job_id' => $jobId,
                'next_phase' => $nextPhase,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Marcar job como completo
     *
     * @param int $jobId
     * @return bool
     */
    public function completeJob(int $jobId): bool
    {
        try {
            $sql = "
                UPDATE clone_progress_tracking
                SET current_phase = :phase,
                    phase_progress = 100,
                    overall_progress = 100,
                    eta_seconds = 0,
                    completed_at = NOW(),
                    updated_at = NOW()
                WHERE job_id = :job_id
            ";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'job_id' => $jobId,
                'phase' => self::PHASE_COMPLETED,
            ]);
        } catch (\Exception $e) {
            log_error('Erro ao completar job de clone', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Obter progresso completo de um job
     *
     * @param int $jobId
     * @return array{
     *     job_id: int,
     *     current_phase: string,
     *     phase_progress: float,
     *     overall_progress: float,
     *     eta_seconds: int|null,
     *     eta_formatted: string|null,
     *     started_at: string,
     *     elapsed_seconds: int,
     *     phases: array,
     *     status: string
     * }|null
     */
    public function getJobProgress(int $jobId): ?array
    {
        try {
            $sql = "
                SELECT
                    job_id,
                    total_items,
                    current_phase,
                    phase_progress,
                    overall_progress,
                    eta_seconds,
                    started_at,
                    completed_at,
                    TIMESTAMPDIFF(SECOND, started_at, COALESCE(completed_at, NOW())) as elapsed_seconds
                FROM clone_progress_tracking
                WHERE job_id = :job_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['job_id' => $jobId]);
            $progress = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$progress) {
                return null;
            }

            // Obter detalhes de cada fase
            $phases = $this->getPhaseDetails($jobId, $progress['current_phase']);

            // Determinar status
            $status = 'in_progress';
            if ($progress['current_phase'] === self::PHASE_COMPLETED) {
                $status = 'completed';
            } elseif ($progress['overall_progress'] === 0) {
                $status = 'starting';
            }

            return [
                'job_id' => (int)$progress['job_id'],
                'current_phase' => $progress['current_phase'],
                'phase_progress' => (float)$progress['phase_progress'],
                'overall_progress' => (float)$progress['overall_progress'],
                'eta_seconds' => $progress['eta_seconds'] ? (int)$progress['eta_seconds'] : null,
                'eta_formatted' => $progress['eta_seconds'] ? $this->formatDuration($progress['eta_seconds']) : null,
                'started_at' => $progress['started_at'],
                'elapsed_seconds' => (int)$progress['elapsed_seconds'],
                'elapsed_formatted' => $this->formatDuration($progress['elapsed_seconds']),
                'phases' => $phases,
                'status' => $status,
            ];
        } catch (\Exception $e) {
            log_error('Erro ao obter progresso de clone', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Obter histórico de progresso
     *
     * @param int $jobId
     * @param int $limit
     * @return array<array{
     *     phase: string,
     *     progress: float,
     *     items_processed: int,
     *     timestamp: string
     * }>
     */
    public function getProgressHistory(int $jobId, int $limit = 50): array
    {
        try {
            $limitSql = max(1, min((int)$limit, 500));

            $sql = "
                SELECT
                    phase,
                    progress,
                    items_processed,
                    created_at as timestamp
                FROM clone_progress_history
                WHERE job_id = :job_id
                ORDER BY created_at DESC
                LIMIT {$limitSql}
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue('job_id', $jobId, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            log_error('Erro ao obter histórico de progresso', [
                'job_id' => $jobId,
                'limit' => $limit,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Obter progresso de múltiplos jobs
     *
     * @param array $jobIds
     * @return array<int, array> Array indexado por job_id
     */
    public function getBatchProgress(array $jobIds): array
    {
        if (empty($jobIds)) {
            return [];
        }

        $result = [];
        foreach ($jobIds as $jobId) {
            $progress = $this->getJobProgress((int)$jobId);
            if ($progress) {
                $result[$jobId] = $progress;
            }
        }

        return $result;
    }

    /**
     * Calcular progresso geral baseado na fase e progresso da fase
     *
     * @param string $currentPhase
     * @param float $phaseProgress Progresso da fase atual (0-100)
     * @return float Progresso geral (0-100)
     */
    private function calculateOverallProgress(string $currentPhase, float $phaseProgress): float
    {
        $completedWeight = 0;
        $phases = [
            self::PHASE_VALIDATION,
            self::PHASE_PREPARATION,
            self::PHASE_PUBLICATION,
            self::PHASE_POST_ACTIONS,
        ];

        // Somar pesos das fases completadas
        foreach ($phases as $phase) {
            if ($phase === $currentPhase) {
                // Fase atual: adicionar progresso proporcional
                $completedWeight += (self::PHASE_WEIGHTS[$phase] * $phaseProgress) / 100;
                break;
            } else {
                // Fase já completada
                $completedWeight += self::PHASE_WEIGHTS[$phase];
            }
        }

        return round($completedWeight, 2);
    }

    /**
     * Estimar tempo restante
     *
     * @param int $jobId
     * @param float $overallProgress Progresso geral (0-100)
     * @return int|null Segundos restantes ou null se não puder estimar
     */
    private function estimateTimeRemaining(int $jobId, float $overallProgress): ?int
    {
        if ($overallProgress <= 0 || $overallProgress >= 100) {
            return null;
        }

        try {
            // Obter tempo decorrido
            $sql = "
                SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) as elapsed
                FROM clone_progress_tracking
                WHERE job_id = :job_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['job_id' => $jobId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result || $result['elapsed'] <= 0) {
                return null;
            }

            $elapsed = (int)$result['elapsed'];

            // Calcular velocidade (% por segundo)
            $rate = $elapsed > 0 ? $overallProgress / $elapsed : 0;

            // Estimar tempo restante
            $remaining = $rate > 0 ? (100 - $overallProgress) / $rate : null;

            if ($remaining === null) {
                return null;
            }

            return (int)$remaining;
        } catch (\Exception $e) {
            log_error('Erro ao estimar ETA do clone', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Obter detalhes de cada fase
     *
     * @param int $jobId
     * @param string $currentPhase
     * @return array
     */
    private function getPhaseDetails(int $jobId, string $currentPhase): array
    {
        $phases = [
            self::PHASE_VALIDATION => [
                'name' => 'Validação',
                'description' => 'Validando itens e permissões',
                'icon' => '✓',
            ],
            self::PHASE_PREPARATION => [
                'name' => 'Preparação',
                'description' => 'Preparando dados para clonagem',
                'icon' => '⚙️',
            ],
            self::PHASE_PUBLICATION => [
                'name' => 'Publicação',
                'description' => 'Publicando anúncios no Mercado Livre',
                'icon' => '📤',
            ],
            self::PHASE_POST_ACTIONS => [
                'name' => 'Pós-Ações',
                'description' => 'Aplicando templates e estratégias',
                'icon' => '🎯',
            ],
        ];

        $result = [];
        $currentFound = false;

        foreach ($phases as $phase => $details) {
            $status = 'pending';
            $progress = 0;

            if ($phase === $currentPhase) {
                $status = 'in_progress';
                $currentFound = true;
                // Progresso vem do banco
                $progress = $this->getPhaseProgressValue($jobId, $phase);
            } elseif (!$currentFound) {
                // Fase já completada
                $status = 'completed';
                $progress = 100;
            }

            $result[] = [
                'phase' => $phase,
                'name' => $details['name'],
                'description' => $details['description'],
                'icon' => $details['icon'],
                'status' => $status,
                'progress' => $progress,
                'weight' => self::PHASE_WEIGHTS[$phase] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Obter progresso atual da fase
     *
     * @param int $jobId
     * @param string $phase
     * @return float
     */
    private function getPhaseProgressValue(int $jobId, string $phase): float
    {
        try {
            $sql = "
                SELECT phase_progress
                FROM clone_progress_tracking
                WHERE job_id = :job_id AND current_phase = :phase
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['job_id' => $jobId, 'phase' => $phase]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (float)$result['phase_progress'] : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Registrar histórico de progresso
     *
     * @param int $jobId
     * @param string $phase
     * @param float $progress
     * @param int $itemsProcessed
     * @return void
     */
    private function logPhaseProgress(int $jobId, string $phase, float $progress, int $itemsProcessed): void
    {
        try {
            $sql = "
                INSERT INTO clone_progress_history
                (job_id, phase, progress, items_processed, created_at)
                VALUES (:job_id, :phase, :progress, :items_processed, NOW())
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'job_id' => $jobId,
                'phase' => $phase,
                'progress' => $progress,
                'items_processed' => $itemsProcessed,
            ]);
        } catch (\Exception $e) {
            // Silently fail - não é crítico
            log_warning('Erro ao registrar histórico de progresso', [
                'job_id' => $jobId,
                'phase' => $phase,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Obter fase atual
     *
     * @param int $jobId
     * @return string|null
     */
    private function getCurrentPhase(int $jobId): ?string
    {
        try {
            $sql = "SELECT current_phase FROM clone_progress_tracking WHERE job_id = :job_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['job_id' => $jobId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? $result['current_phase'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obter total de items
     *
     * @param int $jobId
     * @return int
     */
    private function getTotalItems(int $jobId): int
    {
        try {
            $sql = "SELECT total_items FROM clone_progress_tracking WHERE job_id = :job_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['job_id' => $jobId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int)$result['total_items'] : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Formatar duração em formato legível
     *
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return "{$minutes}m {$secs}s";
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "{$hours}h {$minutes}m";
    }

    /**
     * Resetar tracking de um job
     *
     * @param int $jobId
     * @return bool
     */
    public function resetJobTracking(int $jobId): bool
    {
        try {
            $sql = "DELETE FROM clone_progress_tracking WHERE job_id = :job_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['job_id' => $jobId]);

            $sql2 = "DELETE FROM clone_progress_history WHERE job_id = :job_id";
            $stmt2 = $this->db->prepare($sql2);
            $stmt2->execute(['job_id' => $jobId]);

            return true;
        } catch (\Exception $e) {
            log_error('Erro ao resetar tracking de clone', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Obter estatísticas de performance
     *
     * @param int $jobId
     * @return array{
     *     avg_items_per_second: float,
     *     fastest_phase: string,
     *     slowest_phase: string,
     *     total_duration: int
     * }|null
     */
    public function getPerformanceStats(int $jobId): ?array
    {
        try {
            $progress = $this->getJobProgress($jobId);
            if (!$progress || $progress['status'] !== 'completed') {
                return null;
            }

            $history = $this->getProgressHistory($jobId, 1000);

            // Calcular velocidade média
            $totalItems = $this->getTotalItems($jobId);
            $avgRate = $totalItems / max($progress['elapsed_seconds'], 1);

            return [
                'avg_items_per_second' => round($avgRate, 2),
                'total_duration' => $progress['elapsed_seconds'],
                'phases_count' => count($progress['phases']),
            ];
        } catch (\Exception $e) {
            log_error('Erro ao obter estatísticas de clone', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
