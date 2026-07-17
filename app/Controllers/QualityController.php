<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Quality\HealthCheckService;
use App\Services\Quality\QualityScoreService;
use App\Services\Quality\ValidationService;

/**
 * Quality Controller — validação local e estimativa interna de qualidade.
 *
 * Não é a fonte canônica de exposição oficial ML (use ListingVisibilityController).
 * Score local = internal_quality; api_health embutido = official_ml_performance.
 *
 * Endpoints:
 * - GET  /api/quality/health/{itemId}
 * - GET  /api/quality/purchase-experience/{itemId}
 * - GET  /api/quality/score/{itemId}
 * - POST /api/quality/validate
 * - POST /api/quality/validate/batch
 * - POST /api/quality/autofix
 * - GET  /api/quality/report/{itemId}
 */
class QualityController extends BaseController
{
    private ?int $accountId;

    public function __construct()
    {
        parent::__construct();
        $this->accountId = $this->getAccountId();
    }

    /**
     * GET /api/quality/health/{itemId}
     * Verifica a saúde de um anúncio
     */
    public function checkHealth(string $itemId): void
    {
        try {
            $service = new HealthCheckService($this->accountId);
            $result = $service->checkItemHealth($itemId);
            $this->json($result);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * POST /api/quality/health/batch
     * Verifica saúde de múltiplos anúncios
     */
    public function checkHealthBatch(): void
    {
        try {
            $itemIds = $this->request->jsonField('item_ids', []);

            if (empty($itemIds)) {
                $this->jsonError('item_ids é obrigatório', 400);
            }

            $service = new HealthCheckService($this->accountId);
            $results = $service->checkMultipleItems($itemIds);

            $this->jsonSuccess([
                'total_items' => count($itemIds),
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * GET /api/quality/health/{itemId}/recommendations
     * Obtém recomendações priorizadas
     */
    public function getHealthRecommendations(string $itemId): void
    {
        try {
            $service = new HealthCheckService($this->accountId);
            $recommendations = $service->getPrioritizedRecommendations($itemId);

            $this->jsonSuccess([
                'item_id' => $itemId,
                'recommendations' => $recommendations,
            ]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * GET /api/quality/purchase-experience/{itemId}
     * Experiência de compra oficial do ML (reputação do anúncio).
     */
    public function getPurchaseExperience(string $itemId): void
    {
        try {
            $service = new HealthCheckService($this->accountId);
            $result = $service->getPurchaseExperience($itemId);

            if (isset($result['error'])) {
                $this->jsonError((string) $result['error'], 502);
                return;
            }

            $this->jsonSuccess([
                'item_id' => $itemId,
                'source' => 'official_ml_purchase_experience',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * GET /api/quality/score/{itemId}
     * Calcula quality score de um anúncio
     */
    public function calculateScore(string $itemId): void
    {
        try {
            $service = new QualityScoreService($this->accountId);
            $result = $service->calculateQualityScore($itemId);
            $this->json($result);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * POST /api/quality/validate
     * Valida dados antes de publicar
     */
    public function validateListing(): void
    {
        try {
            $data = $this->request->json();

            if (empty($data)) {
                $this->jsonError('Dados do anúncio são obrigatórios', 400);
            }

            $service = new ValidationService($this->accountId);
            $result = $service->validateListing($data);
            $this->json($result);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * POST /api/quality/validate/batch
     * Valida múltiplos anúncios
     */
    public function validateBatch(): void
    {
        try {
            $items = $this->request->jsonField('items', []);

            if (empty($items)) {
                $this->jsonError('items é obrigatório', 400);
            }

            $service = new ValidationService($this->accountId);
            $results = $service->validateBatch($items);
            $this->json($results);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * POST /api/quality/autofix
     * Corrige automaticamente erros simples
     */
    public function autoFix(): void
    {
        try {
            $data = $this->request->json();

            if (empty($data)) {
                $this->jsonError('Dados do anúncio são obrigatórios', 400);
            }

            $service = new ValidationService($this->accountId);
            $result = $service->autoFix($data);

            $this->jsonSuccess(['result' => $result]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * GET /api/quality/report/{itemId}
     * Relatório completo de qualidade
     */
    public function getCompleteReport(string $itemId): void
    {
        try {
            $healthService = new HealthCheckService($this->accountId);
            $scoreService = new QualityScoreService($this->accountId);

            $health = $healthService->checkItemHealth($itemId);
            $score = $scoreService->calculateQualityScore($itemId);

            if (!($health['success'] ?? false) || !($score['success'] ?? false)) {
                $this->jsonError('Anúncio não encontrado ou erro na análise', 404);
            }

            $this->json([
                'success' => true,
                'item_id' => $itemId,
                'title' => $health['title'] ?? '',
                'timestamp' => date('Y-m-d H:i:s'),
                'quality_score' => $score['quality_score'] ?? [],
                'health_check' => [
                    'status' => $health['health']['status'] ?? 'unknown',
                    'score' => $health['health']['score'] ?? 0,
                    'issues' => $health['issues'] ?? [],
                    'recommendations' => $health['recommendations'] ?? [],
                    'opportunities' => $health['opportunities'] ?? [],
                ],
                'summary' => [
                    'overall_quality' => $score['quality_score']['rating'] ?? 'N/A',
                    'health_status' => $health['health']['status'] ?? 'unknown',
                    'total_issues' => $health['summary']['total_issues'] ?? 0,
                    'critical_issues' => $health['summary']['critical_issues'] ?? 0,
                    'strengths' => $score['strengths'] ?? [],
                    'weaknesses' => $score['weaknesses'] ?? [],
                ],
                'action_plan' => $this->generateActionPlan($health, $score),
            ]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * GET /api/quality/dashboard
     * Dashboard com estatísticas gerais de qualidade
     */
    /**
     * GET /quality/dashboard
     * Renderiza dashboard de qualidade (HTML)
     */
    public function getDashboard(): void
    {
        $this->renderView('dashboard/quality', [
            'title' => 'Dashboard de Qualidade',
            'accountId' => $this->accountId
        ]);
    }

    /**
     * GET /api/quality/dashboard/stats
     * Retorna estatísticas do dashboard
     */
    public function getDashboardStats(): void
    {
        try {
            $db = \App\Database::getInstance();

            // Buscar itens do account
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) as total_items,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_items,
                    SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused_items
                FROM items
                WHERE account_id = :account_id
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $itemStats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Calcular scores médios de qualidade
            $healthService = new HealthCheckService($this->accountId);
            $scoreService = new QualityScoreService($this->accountId);

            // Buscar últimos 50 itens para análise rápida
            $stmt = $db->prepare("
                SELECT item_id
                FROM items
                WHERE account_id = :account_id
                AND status = 'active'
                ORDER BY updated_at DESC
                LIMIT 50
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $items = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $qualityScores = [
                'excellent' => 0,  // > 80
                'good' => 0,       // 60-80
                'fair' => 0,       // 40-60
                'poor' => 0        // < 40
            ];

            foreach ($items as $itemId) {
                try {
                    $score = $scoreService->calculateQualityScore($itemId);
                    $scoreValue = $score['quality_score']['total'] ?? 0;

                    if ($scoreValue > 80) $qualityScores['excellent']++;
                    elseif ($scoreValue > 60) $qualityScores['good']++;
                    elseif ($scoreValue > 40) $qualityScores['fair']++;
                    else $qualityScores['poor']++;
                } catch (\Exception $e) {
                    // Ignorar erros em itens individuais
                    continue;
                }
            }

            $this->jsonSuccess([
                'item_stats' => $itemStats,
                'quality_distribution' => $qualityScores,
                'analyzed_items' => count($items),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * GET /api/quality/dashboard/items
     * Lista itens com score de qualidade
     */
    public function getDashboardItems(): void
    {
        try {
            $page = $this->request->getInt('page', 1);
            $perPage = $this->request->getInt('per_page', 20);
            $minScore = $this->request->getInt('min_score', 0);
            $maxScore = $this->request->getInt('max_score', 100);
            $status = $this->request->get('status', 'active') ?? 'active';

            $db = \App\Database::getInstance();
            $page = max(1, (int)$page);
            $perPage = max(1, min(200, (int)$perPage));
            $offset = ($page - 1) * $perPage;

            // Buscar itens
            $stmt = $db->prepare("
                SELECT ml_item_id AS item_id, title, price, status, available_quantity, sold_quantity
                FROM items
                WHERE account_id = :account_id
                AND status = :status
                ORDER BY sold_quantity DESC, updated_at DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->bindValue(':account_id', $this->accountId, \PDO::PARAM_INT);
            $stmt->bindValue(':status', $status, \PDO::PARAM_STR);
            $stmt->execute();
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Adicionar scores de qualidade
            $scoreService = new QualityScoreService($this->accountId);
            $itemsWithScores = [];

            foreach ($items as $item) {
                try {
                    $score = $scoreService->calculateQualityScore($item['item_id']);
                    $scoreValue = $score['quality_score']['total'] ?? 0;

                    // Filtrar por score
                    if ($scoreValue >= $minScore && $scoreValue <= $maxScore) {
                        $item['quality_score'] = $scoreValue;
                        $item['quality_level'] = $this->getQualityLevel($scoreValue);
                        $item['issues'] = $score['quality_score']['issues'] ?? [];
                        $itemsWithScores[] = $item;
                    }
                } catch (\Exception $e) {
                    // Se falhar, incluir com score 0
                    $item['quality_score'] = 0;
                    $item['quality_level'] = 'unknown';
                    $item['issues'] = [];
                    $itemsWithScores[] = $item;
                }
            }

            // Total para paginação
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM items
                WHERE account_id = :account_id AND status = :status
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'status' => $status
            ]);
            $totalItems = $stmt->fetchColumn();

            $this->jsonSuccess([
                'items' => $itemsWithScores,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalItems,
                    'total_pages' => ceil($totalItems / $perPage)
                ]
            ]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage());
        }
    }

    /**
     * Helper: Determina nível de qualidade baseado no score
     */
    private function getQualityLevel(int $score): string
    {
        if ($score > 80) return 'excellent';
        if ($score > 60) return 'good';
        if ($score > 40) return 'fair';
        return 'poor';
    }

    /**
     * Gera plano de ação baseado nas análises
     */
    private function generateActionPlan(array $health, array $score): array
    {
        $actions = [];
        $priority = 1;

        // 1. Ações críticas (baseado em health)
        foreach (($health['issues'] ?? []) as $issue) {
            if (($issue['severity'] ?? '') === 'critical') {
                $actions[] = [
                    'priority' => $priority++,
                    'category' => $issue['category'] ?? 'unknown',
                    'action' => $issue['title'] ?? '',
                    'description' => $issue['description'] ?? '',
                    'impact' => $issue['impact'] ?? '',
                    'urgency' => 'critical',
                ];
            }
        }

        // 2. Ações de alta prioridade
        foreach (($health['recommendations'] ?? []) as $recommendation) {
            $recPriority = $recommendation['priority'] ?? '';
            if ($recPriority === 'critical' || $recPriority === 'high') {
                $actions[] = [
                    'priority' => $priority++,
                    'category' => $recommendation['category'] ?? 'unknown',
                    'action' => $recommendation['action'] ?? '',
                    'description' => $recommendation['description'] ?? '',
                    'urgency' => $recPriority,
                ];
            }
        }

        // 3. Oportunidades de melhoria (score)
        foreach (($score['improvement_potential']['top_improvements'] ?? []) as $improvement) {
            $actions[] = [
                'priority' => $priority++,
                'category' => 'improvement',
                'action' => $improvement['action'] ?? '',
                'potential_gain' => $improvement['potential_gain'] ?? 0,
                'urgency' => 'medium',
            ];
        }

        return array_slice($actions, 0, 10);
    }
}
