<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\TechSheetService;
use App\Services\TechSheetAnalyticsService;
use App\Services\TechSheetNotificationService;
use App\Services\TechSheetAutoOptimizerService;
use App\Services\TechSheetEmailService;
use App\Services\TechSheetExportService;
use App\Services\TechSheetBatchOptimizerService;
use App\Services\TechSheetChartsService;
use App\Services\TechSheetSchedulerService;
use App\Services\TechSheetWebhookService;
use App\Services\TechSheetAlertService;
use App\Services\TechSheetSmartGapFillerService;
use App\Services\JobService;

/**
 * API: Ficha Técnica (SEO)
 *
 * Endpoints:
 * - GET  /api/seo/technical-sheet/items
 * - GET  /api/seo/technical-sheet/items/{itemId}
 * - POST /api/seo/technical-sheet/items/{itemId}/suggestions/generate
 * - POST /api/seo/technical-sheet/items/{itemId}/suggestions/decisions
 * - POST /api/seo/technical-sheet/items/{itemId}/apply
 * - POST /api/seo/technical-sheet/batch/*
 */
class TechnicalSheetController extends BaseController
{
    private ?int $accountId;
    private array $config;

    public function __construct()
    {
        parent::__construct();
        // Align with SEOKillerController: SessionHelper resolves DB preference when session key is unset after login.
        $this->accountId = \App\Helpers\SessionHelper::getActiveAccountId()
            ?? $this->getActiveAccountId();
        $this->config = \App\Core\Config::getInstance()->all();
    }

    /**
     * Render the Tech Sheet dashboard view
     * GET /dashboard/tech-sheet
     */
    public function index(): void
    {
        if (!$this->getUserId()) {
            header('Location: /login');
            exit;
        }

        $pageTitle = 'Ficha Técnica';

        ob_start();
        require __DIR__ . '/../Views/dashboard/tech-sheet/index.php';
        $content = ob_get_clean();

        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    public function listItems(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);

            $filters = [
                'page' => $this->request->getInt('page', 1),
                'per_page' => $this->request->getInt('per_page', 20),
                'q' => $this->request->get('q'),
                'category_id' => $this->request->get('category_id'),
                'status' => $this->request->get('status'),
                'tab' => $this->request->get('tab'),
                'sort' => $this->request->get('sort'),
                'has_pending_suggestions' => $this->request->get('has_pending_suggestions'),
                'min_completeness' => $this->request->get('min_completeness'),
                'max_completeness' => $this->request->get('max_completeness'),
            ];

            return $service->listItems($filters);
        });
    }

    /**
     * KPIs/Stats para a tela de listagem
     * GET /api/seo/technical-sheet/stats
     */
    public function stats(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);

            $filters = [
                'q' => $this->request->get('q'),
                'category_id' => $this->request->get('category_id'),
                'status' => $this->request->get('status'),
                'tab' => $this->request->get('tab'),
                'has_pending_suggestions' => $this->request->get('has_pending_suggestions'),
                'min_completeness' => $this->request->get('min_completeness'),
                'max_completeness' => $this->request->get('max_completeness'),
            ];

            $result = $service->stats($filters);

            return $result;
        });
    }

    public function getItem(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);
            return $service->getItem($itemId);
        });
    }

    public function generateSuggestions(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);
            return $service->generateSuggestions($itemId);
        });
    }

    /**
     * POST /api/seo/technical-sheet/items/{itemId}/suggestions/quick
     * Gera sugestões rápidas usando apenas extração por regex (sem AI)
     */
    public function quickSuggestions(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);
            return $service->generateQuickSuggestions($itemId);
        });
    }

    /**
     * POST /api/seo/technical-sheet/items/{itemId}/suggestions/model
     * Gera sugestões avançadas para o atributo MODEL usando estratégias de busca:
     * - Autocomplete Mining: sugestões do ML baseadas no título
     * - Category Trends: tendências de busca da categoria
     * - Competitor Analysis: modelos usados pelos top sellers
     * - Search Volume Scoring: prioriza por volume de busca estimado
     */
    public function modelSuggestions(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);
            return $service->generateModelSuggestions($itemId);
        });
    }

    /**
     * Refresh: Busca dados frescos da API ML e re-analisa o item
     * POST /api/seo/technical-sheet/items/{itemId}/refresh
     */
    public function refreshItem(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);
            return $service->refreshItemFromApi($itemId);
        });
    }

    public function saveDecisions(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $userId = $this->getUserId() ?? 0;
            if ($userId <= 0) {
                return ['success' => false, 'error' => 'Usuário não autenticado'];
            }

            $input = $this->getJsonInput();
            $decisions = $input['decisions'] ?? [];

            if (!is_array($decisions)) {
                return ['success' => false, 'error' => 'Formato inválido: decisions deve ser array'];
            }

            $service = new TechSheetService($this->accountId);
            return $service->saveDecisions($itemId, $decisions, $userId);
        });
    }

    public function applyApproved(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $userId = $this->getUserId() ?? 0;
            if ($userId <= 0) {
                return ['success' => false, 'error' => 'Usuário não autenticado'];
            }

            $service = new TechSheetService($this->accountId);
            return $service->applyApproved($itemId, $userId);
        });
    }

    /**
     * Adiciona sugestões manuais (de extração ou análise de concorrentes)
     * POST /api/seo/technical-sheet/items/{itemId}/suggestions
     * Body: {"suggestions": [{attribute_id, suggested_value, confidence, source}]}
     */
    public function addSuggestions(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $input = $this->getJsonInput();
            $suggestions = $input['suggestions'] ?? [];

            if (!is_array($suggestions) || empty($suggestions)) {
                return ['success' => false, 'error' => 'Nenhuma sugestão fornecida'];
            }

            $service = new TechSheetService($this->accountId);
            return $service->addSuggestions($itemId, $suggestions);
        });
    }

    /**
     * Gera sugestões em lote (síncrono).
     * POST /api/seo/technical-sheet/batch/suggestions/generate
     * Body: {"item_ids": ["MLB..."]}
     */
    public function batchGenerateSuggestions(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $userId = $this->getUserId() ?? 0;
            if ($userId <= 0) {
                return ['success' => false, 'error' => 'Usuário não autenticado'];
            }

            $input = $this->getJsonInput();
            $itemIds = $input['item_ids'] ?? [];
            if (!is_array($itemIds)) {
                return ['success' => false, 'error' => 'Formato inválido: item_ids deve ser array'];
            }

            $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
            if (!$itemIds) {
                return ['success' => false, 'error' => 'Nenhum item selecionado'];
            }

            if (count($itemIds) > 50) {
                return ['success' => false, 'error' => 'Limite excedido: máximo 50 itens por geração síncrona'];
            }

            $service = new TechSheetService($this->accountId);
            $processed = 0;
            $successful = 0;
            $failed = 0;
            $createdTotal = 0;
            $failures = [];

            foreach ($itemIds as $itemId) {
                $processed++;
                try {
                    $res = $service->generateSuggestions($itemId);
                    if (($res['success'] ?? false) === true) {
                        $successful++;
                        $createdTotal += (int)($res['created'] ?? 0);
                    } else {
                        $failed++;
                        if (count($failures) < 20) {
                            $failures[] = [
                                'item_id' => $itemId,
                                'error' => $res['error'] ?? 'Falha ao gerar sugestões',
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    if (count($failures) < 20) {
                        $failures[] = [
                            'item_id' => $itemId,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            return [
                'success' => true,
                'mode' => 'sync',
                'total_items' => $processed,
                'successful_items' => $successful,
                'failed_items' => $failed,
                'suggestions_created_total' => $createdTotal,
                'failures' => $failures,
            ];
        });
    }

    /**
     * Aplica sugestões aprovadas em lote (síncrono — escreve no ML).
     * POST /api/seo/technical-sheet/batch/apply
     * Body: {"item_ids": ["MLB..."]} ou {"scope":"eligible"}
     */
    public function batchApplyApproved(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $userId = $this->getUserId() ?? 0;
            if ($userId <= 0) {
                return ['success' => false, 'error' => 'Usuário não autenticado'];
            }

            $input = $this->getJsonInput();
            $itemIds = $input['item_ids'] ?? [];
            if (!is_array($itemIds)) {
                return ['success' => false, 'error' => 'Formato inválido: item_ids deve ser array'];
            }

            $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
            $service = new TechSheetService($this->accountId);
            $scope = strtolower(trim((string)($input['scope'] ?? '')));

            if (!$itemIds && $scope === 'eligible') {
                $resolved = $service->listActiveItemIdsWithApprovedSuggestions(50);
                $itemIds = $resolved['item_ids'] ?? [];
                if (!$itemIds) {
                    return [
                        'success' => false,
                        'error' => 'Nenhuma sugestão aprovada em anúncios active para aplicar',
                    ];
                }
            }

            if (!$itemIds) {
                return ['success' => false, 'error' => 'Nenhum item selecionado'];
            }

            if (count($itemIds) > 50) {
                return ['success' => false, 'error' => 'Limite excedido: máximo 50 itens por aplicação síncrona'];
            }

            $processed = 0;
            $successful = 0;
            $failed = 0;
            $appliedTotal = 0;
            $failures = [];

            foreach ($itemIds as $itemId) {
                $processed++;
                try {
                    $res = $service->applyApproved($itemId, $userId);
                    if (($res['success'] ?? false) === true) {
                        $successful++;
                        $appliedTotal += (int)($res['applied'] ?? 0);
                    } else {
                        $failed++;
                        if (count($failures) < 20) {
                            $failures[] = [
                                'item_id' => $itemId,
                                'error' => $res['error'] ?? 'Falha ao aplicar',
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    if (count($failures) < 20) {
                        $failures[] = [
                            'item_id' => $itemId,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            return [
                'success' => true,
                'mode' => 'sync',
                'scope' => $scope !== '' ? $scope : 'selected',
                'total_items' => $processed,
                'successful_items' => $successful,
                'failed_items' => $failed,
                'attributes_applied_total' => $appliedTotal,
                'failures' => $failures,
            ];
        });
    }

    /**
     * Aprova sugestões pendentes em lote por confiança (síncrono).
     * POST /api/seo/technical-sheet/batch/approve
     * Body: {"item_ids": ["MLB..."], "min_confidence": 85}
     *       ou {"scope": "eligible", "min_confidence": 85} para todos active elegíveis
     * Não aplica no Mercado Livre — apenas marca status=approved.
     */
    public function batchApprovePending(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $userId = $this->getUserId() ?? 0;
            if ($userId <= 0) {
                return ['success' => false, 'error' => 'Usuário não autenticado'];
            }

            $input = $this->getJsonInput();
            $itemIds = $input['item_ids'] ?? [];
            if (!is_array($itemIds)) {
                return ['success' => false, 'error' => 'Formato inválido: item_ids deve ser array'];
            }

            $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));

            $minConfidence = isset($input['min_confidence']) ? (int)$input['min_confidence'] : 85;
            if ($minConfidence < 0) {
                $minConfidence = 0;
            }
            if ($minConfidence > 100) {
                $minConfidence = 100;
            }

            $service = new TechSheetService($this->accountId);
            $scope = strtolower(trim((string)($input['scope'] ?? '')));
            if (!$itemIds && $scope === 'eligible') {
                $resolved = $service->listActiveItemIdsWithPendingMinConfidence($minConfidence, 200);
                $itemIds = $resolved['item_ids'] ?? [];
                if (!$itemIds) {
                    return [
                        'success' => false,
                        'error' => 'Nenhuma sugestão elegível (active + pending ≥ confiança mínima)',
                    ];
                }
            }

            if (!$itemIds) {
                return ['success' => false, 'error' => 'Nenhum item selecionado'];
            }

            if (count($itemIds) > 200) {
                return ['success' => false, 'error' => 'Limite excedido: máximo 200 itens'];
            }

            $processed = 0;
            $successful = 0;
            $failed = 0;
            $approvedTotal = 0;
            $failures = [];

            foreach ($itemIds as $itemId) {
                $processed++;
                try {
                    $res = $service->approvePendingByConfidence($itemId, $userId, $minConfidence);
                    if (($res['success'] ?? false) === true) {
                        $successful++;
                        $approvedTotal += (int)($res['approved'] ?? 0);
                    } else {
                        $failed++;
                        if (count($failures) < 20) {
                            $failures[] = [
                                'item_id' => $itemId,
                                'error' => $res['error'] ?? 'Falha ao aprovar pendentes',
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    if (count($failures) < 20) {
                        $failures[] = [
                            'item_id' => $itemId,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            return [
                'success' => true,
                'mode' => 'sync',
                'scope' => $scope !== '' ? $scope : 'selected',
                'min_confidence' => $minConfidence,
                'total_items' => $processed,
                'successful_items' => $successful,
                'failed_items' => $failed,
                'suggestions_approved_total' => $approvedTotal,
                'failures' => $failures,
            ];
        });
    }

    private function getJsonInput(): array
    {
        return $this->request->json() ?? [];
    }

    /**
     * Dashboard de analytics agregado
     * GET /api/seo/technical-sheet/analytics/dashboard
     */
    public function analyticsDashboard(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $analytics = new TechSheetAnalyticsService($this->accountId);
            $dashboard = $analytics->getDashboard();

            return [
                'success' => true,
                'data' => $dashboard,
            ];
        });
    }

    /**
     * Categorias prioritárias para otimização
     * GET /api/seo/technical-sheet/analytics/priorities
     */
    public function analyticsPriorities(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $limit = $this->request->getInt('limit', 10);
            $analytics = new TechSheetAnalyticsService($this->accountId);
            $priorities = $analytics->getPriorityCategoriesForOptimization($limit);

            return [
                'success' => true,
                'priorities' => $priorities,
            ];
        });
    }

    /**
     * GET /api/seo/technical-sheet/alerts
     */
    public function getAlerts(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $notificationService = new TechSheetNotificationService($this->accountId);
            $alerts = $notificationService->getAlerts();

            return [
                'success' => true,
                'alerts' => $alerts,
            ];
        });
    }

    /**
     * POST /api/seo/technical-sheet/auto-optimize
     */
    public function autoOptimize(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $input = $this->request->json();
            $options = [
                'dry_run' => $input['dry_run'] ?? false,
                'limit' => $input['limit'] ?? 100,
                'force' => $input['force'] ?? false,
            ];

            $optimizer = new TechSheetAutoOptimizerService($this->accountId);
            return $optimizer->autoOptimize($options);
        });
    }

    /**
     * GET /api/seo/technical-sheet/auto-optimize/stats
     */
    public function autoOptimizeStats(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $optimizer = new TechSheetAutoOptimizerService($this->accountId);
            $stats = $optimizer->getStats();

            return [
                'success' => true,
                'stats' => $stats,
            ];
        });
    }

    /**
     * GET /api/seo/technical-sheet/export
     */
    public function exportSuggestions(): void
    {
        if (!$this->accountId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta conectada']);
            return;
        }

        $format = $this->request->get('format', 'csv');
        $exportService = new TechSheetExportService($this->accountId);

        $rawItemIds = (string)($this->request->get('item_ids', ''));
        $itemIds = array_values(array_filter(array_map('trim', explode(',', $rawItemIds)), static function ($v) {
            return $v !== '';
        }));

        $includeGaps = $this->request->getBool('include_gaps', false);
        $includeSuggestions = $this->request->getBool('include_suggestions', true);

        $tab = (string)($this->request->get('tab', ''));
        $statusFromTab = null;
        if ($tab === 'pending' || $tab === 'approved' || $tab === 'applied' || $tab === 'rejected') {
            $statusFromTab = $tab;
        }

        $options = [
            'status' => $this->request->get('status') ?? $statusFromTab,
            'source' => $this->request->get('source'),
            'min_confidence' => $this->request->get('min_confidence') !== null ? $this->request->getInt('min_confidence') : null,
            'category_id' => $this->request->get('category_id'),
            'limit' => $this->request->get('limit') !== null ? $this->request->getInt('limit') : 10000,
            'item_ids' => $itemIds,
            'include_gaps' => $includeGaps,
            'include_suggestions' => $includeSuggestions,
        ];

        try {
            $useReport = $includeGaps || $includeSuggestions || !empty($itemIds);

            if ($format === 'json') {
                $content = $useReport
                    ? $exportService->exportReportToJSON($options)
                    : $exportService->exportToJSON($options);
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="tech-sheet-report-' . date('Y-m-d') . '.json"');
            } else {
                $content = $useReport
                    ? $exportService->exportReportToCSV($options)
                    : $exportService->exportToCSV($options);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="tech-sheet-report-' . date('Y-m-d') . '.csv"');
            }

            echo $content;
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/seo/technical-sheet/import
     */
    public function importSuggestions(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $input = $this->request->json();
            $format = $input['format'] ?? 'csv';
            $content = $input['content'] ?? '';

            $options = [
                'overwrite' => $input['overwrite'] ?? false,
                'force_status' => $input['force_status'] ?? 'pending',
            ];

            $exportService = new TechSheetExportService($this->accountId);

            if ($format === 'json') {
                return $exportService->importFromJSON($content, $options);
            } else {
                return $exportService->importFromCSV($content, $options);
            }
        });
    }

    /**
     * POST /api/seo/technical-sheet/send-report
     */
    public function sendDailyReport(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $input = $this->request->json();
            $email = $input['email'] ?? '';
            $name = $input['name'] ?? 'Usuário';

            if (empty($email)) {
                return ['success' => false, 'error' => 'Email obrigatório'];
            }

            $emailService = new TechSheetEmailService();
            $sent = $emailService->sendDailyReport($this->accountId, $email, $name);

            return [
                'success' => $sent,
                'message' => $sent ? 'Relatório enviado com sucesso' : 'Erro ao enviar relatório',
            ];
        });
    }

    /**
     * GET /api/seo/technical-sheet/export/template/{categoryId}
     */
    public function exportCategoryTemplate(string $categoryId): void
    {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="template-' . $categoryId . '.json"');

        if (!$this->accountId) {
            echo json_encode(['success' => false, 'error' => 'Nenhuma conta conectada']);
            return;
        }

        try {
            $exportService = new TechSheetExportService($this->accountId);
            echo $exportService->exportCategoryTemplate($categoryId);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/seo/technical-sheet/charts
     * Dados para gráficos do dashboard
     */
    public function getCharts(): void
    {
        $this->handleJson(function() {
            $chartsService = new TechSheetChartsService($this->accountId);

            $type = $this->request->get('type', 'all');

            if ($type === 'all') {
                return $chartsService->getDashboardCharts();
            }

            return match($type) {
                'completeness' => $chartsService->getCompletenessTrend($this->request->getInt('days', 30)),
                'categories' => $chartsService->getCategoryDistribution(),
                'status' => $chartsService->getSuggestionsStatus(),
                'sources' => $chartsService->getSourcePerformance(),
                'timeline' => $chartsService->getImprovementsTimeline(),
                'heatmap' => $chartsService->getActivityHeatmap(),
                default => ['error' => 'Tipo de gráfico inválido'],
            };
        });
    }

    /**
     * POST /api/seo/technical-sheet/batch/process
     * Processa itens em lote
     */
    public function processBatch(): void
    {
        $this->handleJson(function() {
            $data = $this->request->json();

            if (empty($data['item_ids'])) {
                throw new \Exception("item_ids é obrigatório");
            }

            $batchOptimizer = new TechSheetBatchOptimizerService($this->accountId);

            $action = $data['action'] ?? 'generate';

            return match($action) {
                'generate' => $batchOptimizer->generateBatchSuggestions(
                    $data['item_ids'],
                    $data['options'] ?? []
                ),
                'apply' => $batchOptimizer->applyBatchSuggestions(
                    $data['item_ids'],
                    $data['options'] ?? []
                ),
                default => throw new \Exception("Ação inválida: {$action}"),
            };
        });
    }

    /**
     * GET /api/seo/technical-sheet/batch/performance
     * Análise de performance de batches
     */
    public function getBatchPerformance(): void
    {
        $this->handleJson(function() {
            $batchOptimizer = new TechSheetBatchOptimizerService($this->accountId);

            return [
                'history' => $batchOptimizer->analyzeBatchPerformance(),
                'suggestions' => $batchOptimizer->getOptimizationSuggestions(),
            ];
        });
    }

    /**
     * GET /api/seo/technical-sheet/scheduler/jobs
     * Lista jobs agendados
     */
    public function listScheduledJobs(): void
    {
        $this->handleJson(function() {
            $scheduler = new TechSheetSchedulerService($this->accountId);

            return $scheduler->listJobs([
                'status' => $this->request->get('status'),
                'job_type' => $this->request->get('job_type'),
            ]);
        });
    }

    /**
     * POST /api/seo/technical-sheet/scheduler/jobs
     * Cria novo job agendado
     */
    public function createScheduledJob(): void
    {
        $this->handleJson(function() {
            $data = $this->request->json();

            if (empty($data['job_type'])) {
                throw new \Exception("job_type é obrigatório");
            }

            $scheduler = new TechSheetSchedulerService($this->accountId);

            $jobId = $scheduler->scheduleJob(
                $data['job_type'],
                $data['config'] ?? []
            );

            return [
                'success' => true,
                'job_id' => $jobId,
            ];
        });
    }

    /**
     * POST /api/seo/technical-sheet/scheduler/jobs/{jobId}/run
     * Executa job manualmente
     */
    public function runScheduledJob(int $jobId): void
    {
        $this->handleJson(function() use ($jobId) {
            $scheduler = new TechSheetSchedulerService($this->accountId);

            return $scheduler->runJob($jobId);
        });
    }

    /**
     * PUT /api/seo/technical-sheet/scheduler/jobs/{jobId}/pause
     * Pausa job
     */
    public function pauseScheduledJob(int $jobId): void
    {
        $this->handleJson(function() use ($jobId) {
            $scheduler = new TechSheetSchedulerService($this->accountId);

            $success = $scheduler->pauseJob($jobId);

            return ['success' => $success];
        });
    }

    /**
     * PUT /api/seo/technical-sheet/scheduler/jobs/{jobId}/resume
     * Reativa job
     */
    public function resumeScheduledJob(int $jobId): void
    {
        $this->handleJson(function() use ($jobId) {
            $scheduler = new TechSheetSchedulerService($this->accountId);

            $success = $scheduler->resumeJob($jobId);

            return ['success' => $success];
        });
    }

    /**
     * DELETE /api/seo/technical-sheet/scheduler/jobs/{jobId}
     * Deleta job
     */
    public function deleteScheduledJob(int $jobId): void
    {
        $this->handleJson(function() use ($jobId) {
            $scheduler = new TechSheetSchedulerService($this->accountId);

            $success = $scheduler->deleteJob($jobId);

            return ['success' => $success];
        });
    }

    /**
     * GET /api/seo/technical-sheet/scheduler/stats
     * Estatísticas de jobs
     */
    public function getSchedulerStats(): void
    {
        $this->handleJson(function() {
            $scheduler = new TechSheetSchedulerService($this->accountId);

            return [
                'stats' => $scheduler->getJobsStats(),
                'due_jobs' => $scheduler->checkDueJobs(),
            ];
        });
    }

    /**
     * GET /api/seo/technical-sheet/webhooks
     * Lista webhooks configurados
     */
    public function listWebhooks(): void
    {
        $this->handleJson(function() {
            $webhookService = new TechSheetWebhookService($this->accountId);

            return $webhookService->listWebhooks([
                'type' => $this->request->get('type'),
                'status' => $this->request->get('status'),
            ]);
        });
    }

    /**
     * POST /api/seo/technical-sheet/webhooks
     * Registra novo webhook
     */
    public function createWebhook(): void
    {
        $this->handleJson(function() {
            $data = $this->request->json();

            if (empty($data['type'])) {
                throw new \Exception("type é obrigatório (slack|telegram|http)");
            }

            $webhookService = new TechSheetWebhookService($this->accountId);

            $webhookId = $webhookService->registerWebhook(
                $data['type'],
                $data['config'] ?? []
            );

            return [
                'success' => true,
                'webhook_id' => $webhookId,
            ];
        });
    }

    /**
     * PUT /api/seo/technical-sheet/webhooks/{webhookId}
     * Atualiza webhook
     */
    public function updateWebhook(int $webhookId): void
    {
        $this->handleJson(function() use ($webhookId) {
            $data = $this->request->json();

            $webhookService = new TechSheetWebhookService($this->accountId);

            $success = $webhookService->updateWebhook($webhookId, $data);

            return ['success' => $success];
        });
    }

    /**
     * DELETE /api/seo/technical-sheet/webhooks/{webhookId}
     * Deleta webhook
     */
    public function deleteWebhook(int $webhookId): void
    {
        $this->handleJson(function() use ($webhookId) {
            $webhookService = new TechSheetWebhookService($this->accountId);

            $success = $webhookService->deleteWebhook($webhookId);

            return ['success' => $success];
        });
    }

    /**
     * POST /api/seo/technical-sheet/webhooks/{webhookId}/test
     * Testa webhook
     */
    public function testWebhook(int $webhookId): void
    {
        $this->handleJson(function() use ($webhookId) {
            $webhookService = new TechSheetWebhookService($this->accountId);

            return $webhookService->testWebhook($webhookId);
        });
    }

    /**
     * GET /api/seo/technical-sheet/alerts/rules
     * Lista regras de alerta
     */
    public function listAlertRules(): void
    {
        $this->handleJson(function() {
            $alertService = new TechSheetAlertService($this->accountId);

            return $alertService->listAlertRules([
                'type' => $this->request->get('type'),
                'status' => $this->request->get('status'),
            ]);
        });
    }

    /**
     * POST /api/seo/technical-sheet/alerts/rules
     * Cria regra de alerta
     */
    public function createAlertRule(): void
    {
        $this->handleJson(function() {
            $data = $this->request->json();

            if (empty($data['name']) || empty($data['type']) || empty($data['conditions'])) {
                throw new \Exception("name, type e conditions são obrigatórios");
            }

            $alertService = new TechSheetAlertService($this->accountId);

            $ruleId = $alertService->createAlertRule($data);

            return [
                'success' => true,
                'rule_id' => $ruleId,
            ];
        });
    }

    /**
     * PUT /api/seo/technical-sheet/alerts/rules/{ruleId}
     * Atualiza regra de alerta
     */
    public function updateAlertRule(int $ruleId): void
    {
        $this->handleJson(function() use ($ruleId) {
            $data = $this->request->json();

            $alertService = new TechSheetAlertService($this->accountId);

            $success = $alertService->updateAlertRule($ruleId, $data);

            return ['success' => $success];
        });
    }

    /**
     * DELETE /api/seo/technical-sheet/alerts/rules/{ruleId}
     * Deleta regra de alerta
     */
    public function deleteAlertRule(int $ruleId): void
    {
        $this->handleJson(function() use ($ruleId) {
            $alertService = new TechSheetAlertService($this->accountId);

            $success = $alertService->deleteAlertRule($ruleId);

            return ['success' => $success];
        });
    }

    /**
     * POST /api/seo/technical-sheet/alerts/rules/{ruleId}/recipients
     * Adiciona destinatário
     */
    public function addAlertRecipient(int $ruleId): void
    {
        $this->handleJson(function() use ($ruleId) {
            $data = $this->request->json();

            if (empty($data['email'])) {
                throw new \Exception("email é obrigatório");
            }

            $alertService = new TechSheetAlertService($this->accountId);

            $success = $alertService->addRecipient($ruleId, $data['email']);

            return ['success' => $success];
        });
    }

    /**
     * DELETE /api/seo/technical-sheet/alerts/rules/{ruleId}/recipients/{email}
     * Remove destinatário
     */
    public function removeAlertRecipient(int $ruleId, string $email): void
    {
        $this->handleJson(function() use ($ruleId, $email) {
            $alertService = new TechSheetAlertService($this->accountId);

            $success = $alertService->removeRecipient($ruleId, urldecode($email));

            return ['success' => $success];
        });
    }

    /**
     * GET /api/seo/technical-sheet/alerts/history
     * Histórico de alertas
     */
    public function getAlertHistory(): void
    {
        $this->handleJson(function() {
            $alertService = new TechSheetAlertService($this->accountId);

            return $alertService->getAlertHistory([
                'rule_id' => $this->request->get('rule_id'),
                'days' => $this->request->getInt('days', 7),
                'limit' => $this->request->getInt('limit', 100),
            ]);
        });
    }

    /**
     * POST /api/seo/technical-sheet/items/{itemId}/extract-from-title
     * Extrai atributos do título do anúncio usando IA
     */
    public function extractFromTitle(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);
            $item = $service->getItem($itemId);

            if (!$item['success']) {
                return $item;
            }

            $title = $item['item']['title'] ?? '';
            $categoryId = $item['item']['category_id'] ?? '';

            if (empty($title)) {
                return ['success' => false, 'error' => 'Anúncio sem título'];
            }

            // Usar AttributeKiller para extrair atributos do título
            $attributeKiller = new \App\Services\AI\SEO\AttributeKiller($this->accountId);
            $extracted = $attributeKiller->extractAttributesFromTitle($title, $categoryId);

            return [
                'success' => true,
                'item_id' => $itemId,
                'title' => $title,
                'extracted_attributes' => $extracted['attributes'] ?? [],
                'suggestions' => $extracted['suggestions'] ?? [],
                'confidence' => $extracted['confidence'] ?? 0,
            ];
        });
    }

    /**
     * GET /api/seo/technical-sheet/items/{itemId}/compare-competitors
     * Compara atributos com concorrentes da mesma categoria
     */
    public function compareCompetitors(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);
            $item = $service->getItem($itemId);

            if (!$item['success']) {
                return $item;
            }

            $categoryId = $item['item']['category_id'] ?? '';

            if (empty($categoryId)) {
                return ['success' => false, 'error' => 'Categoria não encontrada para este item'];
            }

            // Buscar atributos atuais do item via API ML
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            $mlItem = $client->get("/items/{$itemId}");

            if (isset($mlItem['error'])) {
                return ['success' => false, 'error' => 'Erro ao buscar item: ' . ($mlItem['message'] ?? 'API error')];
            }

            $currentAttributes = $mlItem['attributes'] ?? [];

            try {
                // Buscar concorrentes mais vendidos da categoria
                $searchResult = $client->get('/sites/MLB/search', [
                    'category' => $categoryId,
                    'limit' => 15,
                    'sort' => 'sold_quantity_desc',
                ]);

                if (isset($searchResult['error'])) {
                    return ['success' => false, 'error' => 'Erro na busca: ' . ($searchResult['message'] ?? 'Search failed')];
                }

                $competitors = [];
                $attributeUsage = [];
                $attributeUsage = [];

                foreach ($searchResult['results'] ?? [] as $result) {
                    if (($result['id'] ?? '') === $itemId) {
                        continue; // Pular o próprio item
                    }

                    $competitorDetail = $client->get("/items/{$result['id']}");

                    $compAttrs = [];
                    foreach ($competitorDetail['attributes'] ?? [] as $attr) {
                        $attrId = $attr['id'] ?? '';
                        $attrName = $attr['name'] ?? '';
                        $attrValue = $attr['value_name'] ?? '';

                        $compAttrs[$attrId] = [
                            'name' => $attrName,
                            'value' => $attrValue,
                        ];

                        if (!isset($attributeUsage[$attrId])) {
                            $attributeUsage[$attrId] = [
                                'name' => $attrName,
                                'count' => 0,
                                'values' => [],
                            ];
                        }
                        $attributeUsage[$attrId]['count']++;
                        if ($attrValue && !in_array($attrValue, $attributeUsage[$attrId]['values'])) {
                            $attributeUsage[$attrId]['values'][] = $attrValue;
                        }
                    }

                    $competitors[] = [
                        'id' => $result['id'],
                        'title' => $result['title'] ?? '',
                        'price' => $result['price'] ?? 0,
                        'sold_quantity' => $result['sold_quantity'] ?? 0,
                        'attributes' => $compAttrs,
                    ];
                }

                // Identificar gaps - atributos que concorrentes têm e nós não
                $gaps = [];
                $currentAttrIds = array_column($currentAttributes, 'id');
                $totalCompetitors = count($competitors);
                $minUsage = max(2, (int)($totalCompetitors * 0.3)); // Pelo menos 30% dos concorrentes

                foreach ($attributeUsage as $attrId => $usage) {
                    if (!in_array($attrId, $currentAttrIds) && $usage['count'] >= $minUsage) {
                        $usagePercent = $totalCompetitors > 0 ? round(($usage['count'] / $totalCompetitors) * 100) : 0;
                        $gaps[] = [
                            'attribute_id' => $attrId,
                            'name' => $usage['name'],
                            'competitor_usage' => $usage['count'],
                            'usage_percent' => $usagePercent,
                            'common_values' => array_slice($usage['values'], 0, 5),
                            'priority' => $usagePercent >= 70 ? 'high' : ($usagePercent >= 50 ? 'medium' : 'low'),
                        ];
                    }
                }

                // Ordenar gaps por uso (mais usados primeiro)
                usort($gaps, fn($a, $b) => $b['competitor_usage'] <=> $a['competitor_usage']);

                return [
                    'success' => true,
                    'item_id' => $itemId,
                    'category_id' => $categoryId,
                    'competitors_analyzed' => count($competitors),
                    'attribute_gaps' => $gaps,
                    'competitors' => array_slice($competitors, 0, 5), // Top 5
                ];

            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Erro ao analisar concorrentes: ' . $e->getMessage(),
                ];
            }
        });
    }

    /**
     * POST /api/seo/technical-sheet/items/{itemId}/preview
     * Gera preview das alterações antes de aplicar
     */
    public function previewChanges(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new TechSheetService($this->accountId);
            $item = $service->getItem($itemId);

            if (!$item['success']) {
                return $item;
            }

            // Buscar atributos atuais da API ML
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            $mlItem = $client->get("/items/{$itemId}");

            // Buscar sugestões aprovadas
            $db = \App\Database::getInstance();
            $stmt = $db->prepare("
                SELECT attribute_id, attribute_name, suggested_value, source, confidence
                FROM tech_sheet_suggestions
                WHERE item_id = :item_id
                  AND account_id = :account_id
                  AND status = 'approved'
                ORDER BY confidence DESC
            ");
            $stmt->execute([
                'item_id' => $itemId,
                'account_id' => $this->accountId,
            ]);
            $approvedSuggestions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $currentAttributes = [];
            foreach ($mlItem['attributes'] ?? [] as $attr) {
                $currentAttributes[$attr['id']] = [
                    'id' => $attr['id'],
                    'name' => $attr['name'] ?? '',
                    'value' => $attr['value_name'] ?? '',
                ];
            }

            $changes = [];
            // Usar completeness_percent (campo real do DB) em vez de completeness_score
            $currentScore = $item['summary']['completeness_percent'] ?? 0;
            $newScore = $currentScore;

            foreach ($approvedSuggestions as $suggestion) {
                $attrId = $suggestion['attribute_id'];
                $currentValue = $currentAttributes[$attrId]['value'] ?? null;

                $changes[] = [
                    'attribute_id' => $attrId,
                    'attribute_name' => $suggestion['attribute_name'],
                    'current_value' => $currentValue,
                    'new_value' => $suggestion['suggested_value'],
                    'is_new' => $currentValue === null,
                    'source' => $suggestion['source'],
                    'confidence' => (int)$suggestion['confidence'],
                ];

                // Estimar impacto no score
                if ($currentValue === null) {
                    $newScore = min(100, $newScore + 2);
                }
            }

            return [
                'success' => true,
                'item_id' => $itemId,
                'title' => $item['item']['title'] ?? $mlItem['title'] ?? '',
                'current_score' => $currentScore,
                'estimated_score' => $newScore,
                'total_changes' => count($changes),
                'changes' => $changes,
                'warnings' => $this->getPreviewWarnings($changes),
            ];
        });
    }

    /**
     * Helper para gerar warnings do preview
     */
    private function getPreviewWarnings(array $changes): array
    {
        $warnings = [];

        $lowConfidenceCount = count(array_filter($changes, fn($c) => $c['confidence'] < 70));
        if ($lowConfidenceCount > 0) {
            $warnings[] = [
                'type' => 'low_confidence',
                'message' => "{$lowConfidenceCount} alteração(ões) com confiança abaixo de 70%",
                'severity' => 'warning',
            ];
        }

        $overwriteCount = count(array_filter($changes, fn($c) => !$c['is_new']));
        if ($overwriteCount > 0) {
            $warnings[] = [
                'type' => 'overwrite',
                'message' => "{$overwriteCount} valor(es) existente(s) será(ão) sobrescrito(s)",
                'severity' => 'info',
            ];
        }

        if (count($changes) > 20) {
            $warnings[] = [
                'type' => 'bulk_change',
                'message' => 'Muitas alterações de uma vez. Considere revisar com atenção.',
                'severity' => 'warning',
            ];
        }

        return $warnings;
    }

    // ========================================================================
    // SEO STRATEGIES INTEGRATION
    // ========================================================================

    /**
     * 🎯 Análise SEO completa para um item
     * GET /api/seo/technical-sheet/items/{itemId}/seo-analysis
     */
    public function seoAnalysis(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            return $service->analyzeSEO($itemId);
        });
    }

    /**
     * 💡 Gerar sugestões SEO para campos da Ficha Técnica
     * POST /api/seo/technical-sheet/items/{itemId}/seo-suggestions
     */
    public function generateSEOSuggestions(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            return $service->generateSEOSuggestions($itemId);
        });
    }

    /**
     * ✨ Otimizar título com estratégias SEO
     * POST /api/seo/technical-sheet/items/{itemId}/optimize-title
     */
    public function optimizeItemTitle(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            return $service->optimizeTitle($itemId);
        });
    }

    /**
     * 📝 Otimizar descrição com FAQs e keywords
     * POST /api/seo/technical-sheet/items/{itemId}/optimize-description
     */
    public function optimizeItemDescription(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            return $service->optimizeDescription($itemId);
        });
    }

    /**
     * ✅ Aplicar título otimizado no Mercado Livre (com snapshot p/ rollback)
     * POST /api/seo/technical-sheet/items/{itemId}/apply-optimized-title
     * Body opcional:
     * {
     *   "title": "...",
     *   "use_generated": true,
     *   "meta": {"reason": "..."}
     * }
     */
    public function applyOptimizedTitle(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $userId = $this->getUserId() ?? 0;
            if ($userId <= 0) {
                return ['success' => false, 'error' => 'Usuário não autenticado'];
            }

            $input = $this->getJsonInput();
            $title = trim((string)($input['title'] ?? ''));
            $useGenerated = (bool)($input['use_generated'] ?? ($title === ''));
            $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            $generated = null;

            if ($useGenerated) {
                $generated = $service->optimizeTitle($itemId);
                if (!($generated['success'] ?? false)) {
                    return $generated;
                }
                $title = trim((string)($generated['optimized_title'] ?? ''));
            }

            if ($title === '') {
                return ['success' => false, 'error' => 'Título é obrigatório para aplicar.'];
            }

            $meta['generated'] = $generated;
            return $service->applyOptimizedTitle($itemId, $title, $userId, $meta);
        });
    }

    /**
     * ✅ Aplicar descrição otimizada no Mercado Livre (com snapshot p/ rollback)
     * POST /api/seo/technical-sheet/items/{itemId}/apply-optimized-description
     * Body opcional:
     * {
     *   "plain_text": "...",
     *   "use_generated": true,
     *   "meta": {"reason": "..."}
     * }
     */
    public function applyOptimizedDescription(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $userId = $this->getUserId() ?? 0;
            if ($userId <= 0) {
                return ['success' => false, 'error' => 'Usuário não autenticado'];
            }

            $input = $this->getJsonInput();
            $plainText = trim((string)($input['plain_text'] ?? $input['description_plain_text'] ?? ''));
            $useGenerated = (bool)($input['use_generated'] ?? ($plainText === ''));
            $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            $generated = null;

            if ($useGenerated) {
                $generated = $service->optimizeDescription($itemId);
                if (!($generated['success'] ?? false)) {
                    return $generated;
                }
                $plainText = trim((string)($generated['optimized_description'] ?? ''));
            }

            if ($plainText === '') {
                return ['success' => false, 'error' => 'Descrição (plain_text) é obrigatória para aplicar.'];
            }

            $meta['generated'] = $generated;
            return $service->applyOptimizedDescription($itemId, $plainText, $userId, $meta);
        });
    }

    /**
     * 🧾 Buscar descrição atual (plain_text)
     * GET /api/seo/technical-sheet/items/{itemId}/description
     */
    public function getItemPlainTextDescription(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            return $service->getPlainTextDescription($itemId);
        });
    }

    /**
     * 🕒 Histórico de otimizações (seo_optimization_history)
     * GET /api/seo/technical-sheet/items/{itemId}/history?limit=50
     */
    public function getOptimizationHistory(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $limit = min($this->request->getInt('limit', 50), 100);
            if ($limit <= 0) {
                $limit = 50;
            }

            $versioning = new \App\Services\SEO\VersioningService($this->accountId);
            $history = $versioning->getHistory($itemId, $limit);

            return [
                'success' => true,
                'item_id' => $itemId,
                'data' => $history,
            ];
        });
    }

    /**
     * ↩️ Rollback de otimização por versionId
     * POST /api/seo/technical-sheet/items/{itemId}/rollback/{versionId}
     * Body JSON opcional: {"reason": "..."}
     */
    public function rollbackOptimization(string $itemId, int $versionId): void
    {
        $this->handleJson(function () use ($itemId, $versionId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $input = $this->getJsonInput();
            $reason = trim((string)($input['reason'] ?? 'Rollback solicitado via Ficha Técnica'));
            if ($reason === '') {
                $reason = 'Rollback solicitado via Ficha Técnica';
            }

            $versioning = new \App\Services\SEO\VersioningService($this->accountId);

            try {
                $userId = $this->getUserId() ?? 0;
                $ok = $versioning->rollback($itemId, $versionId, $reason, $userId > 0 ? $userId : null, 'user');

                return $ok
                    ? ['success' => true, 'message' => 'Rollback concluído com sucesso']
                    : ['success' => false, 'error' => 'Rollback falhou'];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * 📊 Score SEO consolidado
     * GET /api/seo/technical-sheet/items/{itemId}/seo-score
     */
    public function getSEOScore(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            return $service->getSEOScore($itemId);
        });
    }

    /**
     * 📋 Relatório SEO completo
     * GET /api/seo/technical-sheet/items/{itemId}/seo-report
     */
    public function getSEOReport(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            return $service->generateSEOReport($itemId);
        });
    }

    /**
     * 🔄 Aplicar sugestões SEO em batch
     * POST /api/seo/technical-sheet/batch/seo-suggestions
     */
    public function batchSEOSuggestions(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->request->json() ?? [];
            $itemIds = $data['item_ids'] ?? [];

            if (empty($itemIds)) {
                return ['success' => false, 'error' => 'Informe item_ids'];
            }

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            return $service->batchApplySEOSuggestions($itemIds);
        });
    }

    // ========================================================================
    // 🔥 ML TRENDS API INTEGRATION - Mineração de Keywords
    // ========================================================================

    /**
     * 📈 Enriquece item com dados de Trends do Mercado Livre
     * GET /api/seo/technical-sheet/items/{itemId}/trends
     *
     * Usa endpoints oficiais:
     * - /trends/sites/{site_id}
     * - /trends/sites/{site_id}/categories/{category_id}
     */
    public function enrichWithTrends(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\TechSheetSEOIntegrationService($this->accountId);
            return $service->enrichWithTrends($itemId);
        });
    }

    /**
     * 🌐 Tendências gerais do site (país)
     * GET /api/seo/technical-sheet/trends/site
     *
     * Endpoint ML: /trends/sites/{site_id}
     */
    public function getSiteTrends(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $siteId = $this->request->get('site_id', 'MLB');

            $trendsService = new \App\Services\TrendsService($this->accountId);

            // Buscar tendências gerais
            $trends = $trendsService->getHotProducts(['site_id' => $siteId, 'limit' => 20]);

            return [
                'success' => true,
                'site_id' => $siteId,
                'trends' => $trends,
                'fetched_at' => date('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * 📊 Tendências por categoria
     * GET /api/seo/technical-sheet/trends/category/{categoryId}
     *
     * Endpoint ML: /trends/sites/{site_id}/categories/{category_id}
     */
    public function getCategoryTrends(string $categoryId): void
    {
        $this->handleJson(function () use ($categoryId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $siteId = $this->request->get('site_id', 'MLB');

            $trendsService = new \App\Services\TrendsService($this->accountId);

            // Buscar tendências da categoria
            $categoryTrends = $trendsService->getCategoryTrends($categoryId, ['site_id' => $siteId]);

            // Buscar produtos em alta na categoria
            $hotProducts = $trendsService->getHotProducts([
                'site_id' => $siteId,
                'category_id' => $categoryId,
                'limit' => 10
            ]);

            // Analisar sazonalidade
            $seasonality = $trendsService->analyzeSeasonality($categoryId);

            return [
                'success' => true,
                'category_id' => $categoryId,
                'site_id' => $siteId,
                'trends' => $categoryTrends,
                'hot_products' => $hotProducts,
                'seasonality' => $seasonality,
                'fetched_at' => date('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * 🎯 Smart Gap Filler - Preenche lacunas usando múltiplas fontes SEO
     * POST /api/seo/technical-sheet/items/{itemId}/smart-fill
     *
     * Body JSON (opcional):
     * {
     *   "sources": ["title", "description", "benchmark", "autocomplete", "trends", "history", "ai"],
     *   "min_confidence": 50,
     *   "max_suggestions": 3
     * }
     */
    public function smartFillGaps(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $input = $this->request->json() ?? [];

            $options = [
                'sources' => $input['sources'] ?? ['title', 'description', 'benchmark', 'autocomplete', 'trends'],
                'min_confidence' => (int)($input['min_confidence'] ?? 50),
                'max_suggestions' => (int)($input['max_suggestions'] ?? 3),
            ];

            $service = new TechSheetSmartGapFillerService($this->accountId);
            return $service->fillGaps($itemId, $options);
        });
    }

    /**
     * 🎯 Smart Gap Filler em Lote
     * POST /api/seo/technical-sheet/batch/smart-fill
     *
     * Body JSON:
     * {
     *   "item_ids": ["MLB123", "MLB456"],
     *   "sources": ["title", "benchmark"],
     *   "min_confidence": 60,
     *   "limit": 50
     * }
     */
    public function batchSmartFillGaps(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $input = $this->request->json() ?? [];
            $itemIds = $input['item_ids'] ?? [];
            $limit = min(100, max(1, (int)($input['limit'] ?? 50)));

            // Se não passar item_ids, buscar itens com lacunas
            if (empty($itemIds)) {
                $db = \App\Database::getInstance();
                $limitSql = max(1, min(100, (int)$limit));
                $stmt = $db->prepare("
                    SELECT DISTINCT i.ml_item_id
                    FROM items i
                    LEFT JOIN tech_sheet_item_summary s
                        ON s.account_id = i.account_id AND s.item_id = i.ml_item_id
                    WHERE i.account_id = :account_id
                      AND i.status = 'active'
                      AND (s.item_id IS NULL OR s.missing_required > 0 OR s.missing_filter > 0)
                    ORDER BY COALESCE(s.missing_required, 999) DESC
                                        LIMIT {$limitSql}
                ");
                $stmt->bindValue(':account_id', $this->accountId, \PDO::PARAM_INT);
                $stmt->execute();
                $itemIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            }

            if (empty($itemIds)) {
                return [
                    'success' => true,
                    'message' => 'Nenhum item com lacunas encontrado',
                    'processed' => 0,
                ];
            }

            $options = [
                'sources' => $input['sources'] ?? ['title', 'description', 'benchmark', 'autocomplete'],
                'min_confidence' => (int)($input['min_confidence'] ?? 50),
                'max_suggestions' => (int)($input['max_suggestions'] ?? 3),
            ];

            $service = new TechSheetSmartGapFillerService($this->accountId);
            $results = [];
            $totalSuggestions = 0;
            $errors = 0;

            foreach ($itemIds as $itemId) {
                try {
                    $result = $service->fillGaps($itemId, $options);
                    $results[] = [
                        'item_id' => $itemId,
                        'success' => $result['success'] ?? false,
                        'suggestions' => $result['saved_count'] ?? 0,
                        'gaps_covered' => $result['gaps_covered'] ?? 0,
                    ];
                    $totalSuggestions += $result['saved_count'] ?? 0;
                } catch (\Exception $e) {
                    $results[] = [
                        'item_id' => $itemId,
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                    $errors++;
                }

                // Rate limit
                usleep(100000); // 100ms
            }

            return [
                'success' => true,
                'processed' => count($itemIds),
                'total_suggestions' => $totalSuggestions,
                'errors' => $errors,
                'results' => $results,
            ];
        });
    }

    /**
     * 🎯 Análise de Coverage - Quantos gaps podem ser preenchidos por cada fonte
     * GET /api/seo/technical-sheet/items/{itemId}/coverage-analysis
     */
    public function coverageAnalysis(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            // Testar cada fonte individualmente
            $sources = ['title', 'description', 'benchmark', 'autocomplete', 'trends', 'history', 'ai'];
            $service = new TechSheetSmartGapFillerService($this->accountId);

            $coverage = [];
            $allGaps = null;

            foreach ($sources as $source) {
                $result = $service->fillGaps($itemId, [
                    'sources' => [$source],
                    'min_confidence' => 50,
                    'max_suggestions' => 1,
                ]);

                if ($allGaps === null) {
                    $allGaps = $result['gaps_analyzed'] ?? 0;
                }

                $coverage[$source] = [
                    'gaps_covered' => $result['gaps_covered'] ?? 0,
                    'suggestions' => $result['total_suggestions'] ?? 0,
                    'coverage_percent' => $allGaps > 0
                        ? round(($result['gaps_covered'] ?? 0) / $allGaps * 100, 1)
                        : 0,
                ];
            }

            // Combinação de todas as fontes
            $allResult = $service->fillGaps($itemId, [
                'sources' => $sources,
                'min_confidence' => 50,
                'max_suggestions' => 3,
            ]);

            return [
                'success' => true,
                'item_id' => $itemId,
                'total_gaps' => $allGaps,
                'coverage_by_source' => $coverage,
                'combined_coverage' => [
                    'gaps_covered' => $allResult['gaps_covered'] ?? 0,
                    'coverage_percent' => $allGaps > 0
                        ? round(($allResult['gaps_covered'] ?? 0) / $allGaps * 100, 1)
                        : 0,
                    'total_suggestions' => $allResult['total_suggestions'] ?? 0,
                ],
                'recommendation' => $this->getSourceRecommendation($coverage),
            ];
        });
    }

    /**
     * Helper: Recomenda fontes com base na análise de coverage
     */
    private function getSourceRecommendation(array $coverage): string
    {
        $best = [];
        foreach ($coverage as $source => $data) {
            if ($data['coverage_percent'] > 20) {
                $best[$source] = $data['coverage_percent'];
            }
        }

        arsort($best);
        $topSources = array_keys(array_slice($best, 0, 3, true));

        if (empty($topSources)) {
            return 'Considere usar IA para atributos que permitem inferência';
        }

        return 'Fontes recomendadas: ' . implode(', ', $topSources);
    }

    // =========================================================================
    // 🎯 SMART FILL DASHBOARD & METRICS
    // =========================================================================

    /**
     * GET /api/seo/technical-sheet/smart-fill/dashboard
     * Dashboard completo do Smart Fill
     */
    public function smartFillDashboard(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $chartsService = new TechSheetChartsService($this->accountId);

            return [
                'success' => true,
                'data' => $chartsService->getSmartFillDashboard(),
            ];
        });
    }

    /**
     * GET /api/seo/technical-sheet/smart-fill/widget
     * Widget resumido para dashboard principal
     */
    public function smartFillWidget(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $chartsService = new TechSheetChartsService($this->accountId);

            return [
                'success' => true,
                'widget' => $chartsService->getSmartFillWidget(),
            ];
        });
    }

    /**
     * GET /api/seo/technical-sheet/smart-fill/by-source
     * Análise de sugestões por fonte
     */
    public function smartFillBySource(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $chartsService = new TechSheetChartsService($this->accountId);

            return [
                'success' => true,
                'data' => $chartsService->getSmartFillBySource(),
            ];
        });
    }

    /**
     * GET /api/seo/technical-sheet/smart-fill/success-rate
     * Taxa de sucesso por nível de confiança
     */
    public function smartFillSuccessRate(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $chartsService = new TechSheetChartsService($this->accountId);

            return [
                'success' => true,
                'data' => $chartsService->getSmartFillSuccessRate(),
            ];
        });
    }

    /**
     * POST /api/seo/technical-sheet/smart-fill/auto-approve
     * Auto-aprovar sugestões com confiança >= threshold
     */
    public function smartFillAutoApprove(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->request->json();
            $threshold = $data['threshold'] ?? 85;
            $limit = $data['limit'] ?? 100;

            $limitSql = max(1, min(200, (int)$limit));

            $db = \App\Database::getInstance();

            // Buscar sugestões elegíveis
            $stmt = $db->prepare("
                SELECT id, item_id, attribute_id, suggested_value, confidence
                FROM tech_sheet_suggestions
                WHERE account_id = :account_id
                  AND status = 'pending'
                  AND confidence >= :threshold
                ORDER BY confidence DESC
                                LIMIT {$limitSql}
            ");
            $stmt->bindValue(':account_id', $this->accountId, \PDO::PARAM_INT);
            $stmt->bindValue(':threshold', $threshold, \PDO::PARAM_INT);
            $stmt->execute();
            $suggestions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($suggestions)) {
                return [
                    'success' => true,
                    'approved_count' => 0,
                    'message' => "Nenhuma sugestão pendente com confiança >= {$threshold}%",
                ];
            }

            // Aprovar em lote
            $ids = array_column($suggestions, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $updateStmt = $db->prepare("
                UPDATE tech_sheet_suggestions
                SET status = 'approved',
                    decided_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), ' [auto-approved: confidence >= {$threshold}%]')
                WHERE id IN ({$placeholders})
            ");
            $updateStmt->execute($ids);

            return [
                'success' => true,
                'approved_count' => count($ids),
                'threshold' => $threshold,
                'suggestions' => array_map(fn($s) => [
                    'item_id' => $s['item_id'],
                    'attribute_id' => $s['attribute_id'],
                    'confidence' => $s['confidence'],
                ], $suggestions),
            ];
        });
    }

    // ========================================================================
    // 🚀 BULK SEO - Fluxo Seguro de Otimização em Lote
    // ========================================================================

    /**
     * 🔍 Dry-run em lote: gera preview/diff sem aplicar
     * POST /api/seo/technical-sheet/bulk/dry-run
     *
     * Body: {
     *   "item_ids": ["MLB123", "MLB456"],
     *   "optimize_title": true,
     *   "optimize_description": true
     * }
     */
    public function bulkDryRun(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->request->json() ?? [];
            $itemIds = $data['item_ids'] ?? [];

            if (empty($itemIds)) {
                return ['success' => false, 'error' => 'Informe item_ids para análise'];
            }

            $service = new \App\Services\BulkSEOService($this->accountId);

            return $service->dryRunBatch($itemIds, [
                'optimize_title' => (bool)($data['optimize_title'] ?? true),
                'optimize_description' => (bool)($data['optimize_description'] ?? true),
            ]);
        });
    }

    /**
     * ✅ Aplica otimizações aprovadas em lote
     * POST /api/seo/technical-sheet/bulk/apply
     *
     * Body: {
     *   "items": [
     *     {
     *       "item_id": "MLB123",
     *       "apply_title": true,
     *       "apply_description": false,
     *       "title": "Título otimizado...",
     *       "description": "..."
     *     }
     *   ],
     *   "reason": "Otimização SEO em lote"
     * }
     */
    public function bulkApply(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->request->json() ?? [];
            $items = $data['items'] ?? [];

            if (empty($items)) {
                return ['success' => false, 'error' => 'Nenhum item aprovado para aplicar'];
            }

            $userId = $this->getUserId() ?? 0;
            $meta = [
                'reason' => (string)($data['reason'] ?? 'Bulk SEO apply'),
                'strategy' => (string)($data['strategy'] ?? 'bulk_optimization'),
                'source' => 'bulk_seo_ui',
            ];

            $service = new \App\Services\BulkSEOService($this->accountId);

            return $service->applyBatch($items, $userId, $meta);
        });
    }

    /**
     * 📊 Status do job de aplicação em lote
     * GET /api/seo/technical-sheet/bulk/job/{jobId}/status
     */
    public function bulkJobStatus(string $jobId): void
    {
        $this->handleJson(function () use ($jobId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            // Usar o Service para centralizar a lógica
            $service = new \App\Services\BulkSEOService($this->accountId);
            $result = $service->getJobStatus($jobId);

            if (!$result['success']) {
                return $result;
            }

            // Formatar resposta compatível com frontend
            return [
                'success' => true,
                'job' => [
                    'job_id' => $result['job_id'],
                    'status' => $result['status'],
                    'total_items' => (int)$result['total_items'],
                    'processed_items' => (int)$result['processed_items'],
                    'successful_items' => (int)$result['successful_items'],
                    'failed_items' => (int)$result['failed_items'],
                    'created_at' => $result['created_at'],
                    'started_at' => $result['started_at'],
                    'completed_at' => $result['completed_at'],
                    'results' => $result['results'] ?? null,
                ],
            ];
        });
    }

    /**
     * 🔄 Inicia job assíncrono de aplicação em lote
     * POST /api/seo/technical-sheet/bulk/apply-async
     *
     * Para lotes grandes (>10 itens), executa em background com progresso
     */
    public function bulkApplyAsync(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->request->json() ?? [];
            $items = $data['items'] ?? [];

            if (empty($items)) {
                return ['success' => false, 'error' => 'Nenhum item aprovado para aplicar'];
            }

            $userId = $this->getUserId() ?? 0;
            $meta = [
                'reason' => (string)($data['reason'] ?? 'Bulk SEO apply'),
                'strategy' => (string)($data['strategy'] ?? 'bulk_optimization'),
                'source' => 'bulk_seo_ui',
            ];

            $service = new \App\Services\BulkSEOService($this->accountId);

            return $service->startBatchJob($items, $userId, $meta);
        });
    }

    /**
     * 📜 Histórico de operações Bulk SEO
     * GET /api/seo/technical-sheet/bulk/history
     */
    public function bulkHistory(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $limit = $this->request->getInt('limit', 50);
            $offset = $this->request->getInt('offset', 0);

            $service = new \App\Services\BulkSEOService($this->accountId);

            return $service->getBulkHistory($limit, $offset);
        });
    }

    /**
     * ↩️ Rollback em lote
     * POST /api/seo/technical-sheet/bulk/rollback
     *
     * Body: {
     *   "version_ids": [1, 2, 3],
     *   "reason": "Revertendo otimização incorreta"
     * }
     */
    public function bulkRollback(): void
    {
        $this->handleJson(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->request->json() ?? [];
            $versionIds = $data['version_ids'] ?? [];

            if (empty($versionIds)) {
                return ['success' => false, 'error' => 'Informe version_ids para rollback'];
            }

            $userId = $this->getUserId() ?? 0;
            $reason = (string)($data['reason'] ?? 'Bulk rollback');

            $service = new \App\Services\BulkSEOService($this->accountId);

            return $service->rollbackBatch($versionIds, $userId, $reason);
        });
    }

    // ========================================================================
    // 🔧 ATTRIBUTE SUGGESTIONS - Aplicação real de campos da Ficha Técnica
    // ========================================================================

    /**
     * 🔍 Preview de sugestões de atributos com antes/depois
     * GET /api/seo/technical-sheet/items/{itemId}/attribute-suggestions/preview
     */
    public function previewAttributeSuggestions(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\AttributeSuggestionService($this->accountId);

            return $service->previewSuggestions($itemId);
        });
    }

    /**
     * ✅ Aplicar sugestão de atributo específico
     * POST /api/seo/technical-sheet/items/{itemId}/attribute-suggestions/apply
     *
     * Body: {
     *   "attribute_id": "BRAND",
     *   "value": "Samsung",
     *   "confirm": true
     * }
     */
    public function applyAttributeSuggestion(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->request->json() ?? [];
            $attributeId = (string)($data['attribute_id'] ?? '');
            $value = $data['value'] ?? null;
            $confirm = (bool)($data['confirm'] ?? false);

            if (!$attributeId) {
                return ['success' => false, 'error' => 'Informe attribute_id'];
            }

            if (!$confirm) {
                return ['success' => false, 'error' => 'Confirmação necessária (confirm: true)'];
            }

            $userId = $this->getUserId() ?? 0;
            $service = new \App\Services\AttributeSuggestionService($this->accountId);

            return $service->applySuggestion($itemId, $attributeId, $value, $userId);
        });
    }

    /**
     * 📋 Lista atributos que podem ser aplicados via ML API
     * GET /api/seo/technical-sheet/items/{itemId}/applicable-attributes
     */
    public function getApplicableAttributes(string $itemId): void
    {
        $this->handleJson(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\AttributeSuggestionService($this->accountId);

            return $service->getApplicableAttributes($itemId);
        });
    }

    private function handleJson(callable $handler): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $result = $handler();
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    }
}
