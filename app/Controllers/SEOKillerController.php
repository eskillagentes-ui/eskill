<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\SessionHelper;
use App\Services\AI\SEO\SEOKillerEngine;
use App\Services\AI\SEO\TitleKiller;
use App\Services\AI\SEO\AttributeKiller;
use App\Services\AI\SEO\DescriptionKiller;
use App\Services\AI\SEO\KeywordKiller;
use App\Services\AI\SEO\CompetitorSpy;
use App\Services\AI\SEO\BulkOptimizer;
use App\Services\AI\SEO\AutoPilot;
use App\Services\AI\SEO\PerformanceTracker;
use App\Services\AI\SEO\ImageKiller;
use App\Services\AI\SEO\ABTester;
use App\Services\AI\SEO\Strategies\SEOStrategiesEngine;
use App\Services\AI\SEO\GoogleSearchConsoleService;
use App\Services\AI\SEO\SchemaGenerator;
use App\Services\AI\SEO\BacklinkAnalyzer;
use App\Services\ApiKeyService;
use App\Services\AI\SEO\PdfExporter;
use App\Services\AI\SEO\SEOScoreCalculator;
use App\Services\AI\SEO\AutoPilotStatusManager;
use App\Services\AI\SEO\Strategies\SynonymExpansionService;
use App\Services\AI\SEO\Strategies\SemanticScoreService;
use App\Services\AI\SEO\Strategies\KeywordSourceService;
use App\Services\AI\SEO\Strategies\HiddenFieldsService;
use App\Services\AI\SEO\Strategies\KeywordInjectorService;
use App\Services\AI\SEO\Strategies\SearchTypeCoverageService;
use App\Services\AI\SEO\Strategies\FieldWeightService;
use App\Services\AI\SEO\AdvancedSEOMaximizer;
use App\Services\AI\SEO\SEOPerformancePredictor;
use App\Services\AI\SEO\IntelligentAutoOptimizer;
use App\Services\UserService;

/**
 * 🔥 SEO Killer Controller
 *
 * API para o Sistema SEO Matador do Mercado Livre
 */
class SEOKillerController extends BaseController
{
    private ?int $accountId;
    private UserService $userService;

    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();

        // Strict API Authentication
        if (!$this->userService->isAuthenticated()) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $this->accountId = SessionHelper::getActiveAccountId()
            ?? $this->getActiveAccountId()
            ?? $this->getAccountId();
    }

    /**
     * 🔍 Diagnóstico completo da conta
     * GET /api/seo-killer/diagnose
     */
    public function diagnose(): void
    {
        $this->json(function () {

            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $engine = new SEOKillerEngine($this->accountId);
            $diagnosis = $engine->diagnoseAccount();

            $ficha = is_array($diagnosis['ficha_gaps'] ?? null) ? $diagnosis['ficha_gaps'] : [];
            $total = (int) ($ficha['universe_active'] ?? $diagnosis['total_items'] ?? 0);
            $avgScore = (float)($diagnosis['health_score'] ?? 0);
            $pending = (int) ($ficha['pending_unique'] ?? 0);
            $optimized = max(0, $total - $pending);

            return [
                'success' => true,
                'stats' => [
                    'total' => $total,
                    'pending' => $pending,
                    'optimized' => $optimized,
                    'avgScore' => $avgScore,
                    'potential' => max(0, (int)round(100 - $avgScore)),
                ],
                'ficha_gaps' => $ficha,
                'diagnosis' => $diagnosis,
            ];
        });
    }

    /**
     * 🚀 Gerar título matador
     * POST /api/seo-killer/title
     */
    public function generateTitle(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['title']) && empty($data['item_id'])) {
                return ['error' => 'Informe title ou item_id'];
            }

            $killer = new TitleKiller($this->accountId);

            // Get product data from item_id if provided
            if (!empty($data['item_id'])) {
                $client = new \App\Services\MercadoLivreClient($this->accountId);
                $item = $client->get("/items/{$data['item_id']}");
                $data = array_merge($data, [
                    'title' => $item['title'] ?? '',
                    'brand' => $this->extractAttribute($item, 'BRAND'),
                    'model' => $this->extractAttribute($item, 'MODEL'),
                    'attributes' => $item['attributes'] ?? [],
                    'category_id' => $item['category_id'] ?? '',
                ]);
            }

            return $killer->generateKillerTitle($data);
        });
    }

    /**
     * 🔧 Analisar e preencher atributos
     * POST /api/seo-killer/attributes
     */
    public function fillAttributes(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_id'])) {
                return ['error' => 'Informe item_id'];
            }

            $killer = new AttributeKiller($this->accountId);

            // Get category if not provided
            if (empty($data['category_id'])) {
                $client = new \App\Services\MercadoLivreClient($this->accountId);
                $item = $client->get("/items/{$data['item_id']}");
                $data['category_id'] = $item['category_id'] ?? '';
            }

            if (isset($data['analyze_only']) && $data['analyze_only']) {
                return $killer->analyzeGaps($data['item_id'], $data['category_id']);
            }

            return $killer->fillMissingAttributes($data['item_id'], $data['category_id']);
        });
    }

    /**
     * 👁️ Obter atributos ocultos de categoria
     * GET /api/seo-killer/hidden-attributes/{categoryId}
     */
    public function getHiddenAttributes(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $killer = new AttributeKiller($this->accountId);
            return $killer->getHiddenAttributes($categoryId);
        });
    }

    /**
     * 📝 Gerar descrição matadora
     * POST /api/seo-killer/description
     */
    public function generateDescription(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['title']) && empty($data['item_id'])) {
                return ['error' => 'Informe title ou item_id'];
            }

            $killer = new DescriptionKiller($this->accountId);

            // Get product data from item_id if provided
            if (!empty($data['item_id'])) {
                $client = new \App\Services\MercadoLivreClient($this->accountId);
                $item = $client->get("/items/{$data['item_id']}");
                $data = array_merge($data, [
                    'title' => $item['title'] ?? '',
                    'brand' => $this->extractAttribute($item, 'BRAND'),
                    'price' => $item['price'] ?? 0,
                    'attributes' => $item['attributes'] ?? [],
                ]);
            }

            return $killer->generateKillerDescription($data);
        });
    }

    /**
     * 📊 Analisar descrição existente
     * POST /api/seo-killer/description/analyze
     */
    public function analyzeDescription(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            $description = $data['description'] ?? '';

            // Get from item_id if provided
            if (empty($description) && !empty($data['item_id'])) {
                $client = new \App\Services\MercadoLivreClient($this->accountId);
                $desc = $client->get("/items/{$data['item_id']}/description");
                $description = $desc['plain_text'] ?? $desc['text'] ?? '';
            }

            if (empty($description)) {
                return ['error' => 'Informe description ou item_id'];
            }

            $killer = new DescriptionKiller($this->accountId);
            return $killer->analyzeDescription($description);
        });
    }

    /**
     * 🎯 Otimizar item completo (one-click)
     * POST /api/seo-killer/optimize
     */
    public function optimizeItem(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_id'])) {
                return ['error' => 'Informe item_id'];
            }

            $itemId = $data['item_id'];
            $results = [
                'item_id' => $itemId,
                'optimizations' => [],
                'success' => false,
            ];

            // Get item data
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            $item = $client->get("/items/{$itemId}");

            if (!$item || isset($item['error'])) {
                return ['success' => false, 'error' => $item['error'] ?? 'Erro ao buscar item no ML'];
            }

            $productData = [
                'id' => $itemId,
                'title' => $item['title'] ?? '',
                'brand' => $this->extractAttribute($item, 'BRAND'),
                'model' => $this->extractAttribute($item, 'MODEL'),
                'price' => $item['price'] ?? 0,
                'attributes' => $item['attributes'] ?? [],
                'category_id' => $item['category_id'] ?? '',
                'pictures' => $item['pictures'] ?? [],
            ];

            // 1. Optimize Title
            if ($data['optimize_title'] ?? true) {
                $titleKiller = new TitleKiller($this->accountId);
                $titleResult = $titleKiller->optimize($itemId);
                $results['optimizations']['title'] = $titleResult;

                if ($titleResult['success'] && !empty($data['apply'])) {
                    $newTitle = $titleResult['killer_title'] ?? $titleResult['primary'] ?? $titleResult['title'] ?? null;
                    if ($newTitle) {
                        $client->put("/items/{$itemId}", ['title' => $newTitle]);
                        $results['optimizations']['title']['applied'] = true;
                    }
                }
            }

            // 2. Optimize Description
            if ($data['optimize_description'] ?? true) {
                $descKiller = new DescriptionKiller($this->accountId);
                $descResult = $descKiller->optimize($itemId);
                $results['optimizations']['description'] = $descResult;

                if ($descResult['success'] && !empty($data['apply'])) {
                    $newDesc = $descResult['killer_description'] ?? $descResult['description'] ?? null;
                    if ($newDesc) {
                        $client->put("/items/{$itemId}/description", [
                            'plain_text' => $newDesc
                        ]);
                        $results['optimizations']['description']['applied'] = true;
                    }
                }
            }

            // 3. Fill Attributes
            if ($data['fill_attributes'] ?? true) {
                $attrKiller = new AttributeKiller($this->accountId);
                $attrResult = $attrKiller->optimize($itemId);
                $results['optimizations']['attributes'] = $attrResult;
            }

            // 4. Optimize Keywords
            if ($data['optimize_keywords'] ?? true) {
                $keywordKiller = new KeywordKiller($this->accountId);
                $keywordResult = $keywordKiller->optimize($itemId);
                $results['optimizations']['keywords'] = $keywordResult;
            }

            // 5. Image Analysis
            if ($data['optimize_images'] ?? true) {
                $imageKiller = new ImageKiller($this->accountId);
                $imageResult = $imageKiller->optimize($itemId);
                $results['optimizations']['images'] = $imageResult;
            }

            $results['success'] = true;

            return $results;
        });
    }

    /**
     * 🔄 Sincronizar anúncios da conta vinculada
     * POST /api/seo-killer/sync
     */
    public function sync(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $limit = $data['limit'] ?? 100;

            $itemService = new \App\Services\ItemService($this->accountId);
            $result = $itemService->syncItems($limit);

            return array_merge(['success' => true], $result);
        });
    }

    /**
     * 📊 Relatório de completude da conta
     * GET /api/seo-killer/report
     */
    public function completenessReport(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $engine = new SEOKillerEngine($this->accountId);
            return $engine->officialListingCompletenessReport();
        });
    }

    /**
     * 🔎 Pesquisa de Keywords
     * POST /api/seo-killer/keywords
     */
    public function researchKeywords(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['title']) && empty($data['item_id'])) {
                return ['error' => 'Informe title ou item_id'];
            }

            // Get product data from item_id if provided
            if (!empty($data['item_id'])) {
                $client = new \App\Services\MercadoLivreClient($this->accountId);
                $item = $client->get("/items/{$data['item_id']}");
                $data = array_merge($data, [
                    'title' => $item['title'] ?? '',
                    'brand' => $this->extractAttribute($item, 'BRAND'),
                    'model' => $this->extractAttribute($item, 'MODEL'),
                    'attributes' => $item['attributes'] ?? [],
                ]);
            }

            $killer = new KeywordKiller($this->accountId);
            return $killer->researchKeywords($data);
        });
    }

    /**
     * 🕵️ Espionar concorrentes
     * POST /api/seo-killer/spy
     */
    public function spyCompetitors(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['search_term']) && empty($data['item_id'])) {
                return ['error' => 'Informe search_term ou item_id'];
            }

            $spy = new CompetitorSpy($this->accountId);

            if (!empty($data['item_id'])) {
                return $spy->compareWithCompetitors($data['item_id']);
            }

            return $spy->spyProduct($data['search_term'], $data['limit'] ?? 20);
        });
    }

    /**
     * 🕵️ Analisar Concorrência (Estratégia E13)
     * POST /api/seo-killer/competitors/analyze/{itemId}
     */
    public function analyzeCompetitors(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $engine = new SEOStrategiesEngine($this->accountId);

            // Trigger engine with competitor analysis enabled
            return $engine->analyzeItemData([
                'id' => $itemId,
                'analyze_competitors' => true
            ]);
        });
    }

    /**
     * 🚀 Otimização em massa - selecionar itens
     * GET /api/seo-killer/bulk/select
     */
    public function bulkSelect(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $limit = $this->request->getInt('limit', 50);

            $optimizer = new BulkOptimizer($this->accountId);
            $payload = $optimizer->selectPriorityItems($limit);

            if (is_array($payload) && isset($payload['error'])) {
                return array_merge(['success' => false], $payload);
            }

            return array_merge(['success' => true], is_array($payload) ? $payload : []);
        });
    }

    /**
     * 🚀 Otimização em massa - iniciar
     * POST /api/seo-killer/bulk/start
     */
    public function bulkStart(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_ids'])) {
                return ['error' => 'Informe item_ids'];
            }

            $optimizer = new BulkOptimizer($this->accountId);
            return $optimizer->startBulkOptimization($data['item_ids'], $data['options'] ?? []);
        });
    }

    /**
     * 🚀 Otimização em massa - processar job
     * POST /api/seo-killer/bulk/process/{jobId}
     */
    public function bulkProcess(int $jobId): void
    {
        $this->json(function () use ($jobId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $optimizer = new BulkOptimizer($this->accountId);
            return $optimizer->processJob($jobId);
        });
    }

    /**
     * 📊 Otimização em massa - status
     * GET /api/seo-killer/bulk/status/{jobId}
     */
    public function bulkStatus(int $jobId): void
    {
        $this->json(function () use ($jobId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $optimizer = new BulkOptimizer($this->accountId);
            return $optimizer->getJobStatus($jobId);
        });
    }

    /**
     * 📋 Otimização em massa - listar jobs
     * GET /api/seo-killer/bulk/jobs
     */
    public function bulkJobs(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $optimizer = new BulkOptimizer($this->accountId);
            return $optimizer->listJobs();
        });
    }

    /**
     * 📊 Monitor de jobs - Dashboard completo
     * GET /api/seo-killer/bulk/monitor
     */
    public function bulkMonitor(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $optimizer = new BulkOptimizer($this->accountId);
            return $optimizer->getMonitorDashboard();
        });
    }

    /**
     * ❌ Cancelar job
     * POST /api/seo-killer/bulk/cancel/{jobId}
     */
    public function bulkCancel(int $jobId): void
    {
        $this->json(function () use ($jobId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $optimizer = new BulkOptimizer($this->accountId);
            return $optimizer->cancelJob($jobId);
        });
    }

    /**
     * 🔄 Reprocessar job falhado
     * POST /api/seo-killer/bulk/retry/{jobId}
     */
    public function bulkRetry(int $jobId): void
    {
        $this->json(function () use ($jobId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $optimizer = new BulkOptimizer($this->accountId);
            return $optimizer->retryJob($jobId);
        });
    }

    // ========================================
    // 🤖 AUTO-PILOT METHODS
    // ========================================

    /**
     * 🔧 Obter configuração do auto-pilot
     * GET /api/seo-killer/autopilot/config
     */
    public function getAutopilotConfig(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $autopilot = new AutoPilot($this->accountId);
            return [
                'success' => true,
                'config' => $autopilot->getConfig()
            ];
        });
    }

    /**
     * 💾 Salvar configuração do auto-pilot
     * POST /api/seo-killer/autopilot/config
     */
    public function saveAutopilotConfig(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $autopilot = new AutoPilot($this->accountId);
            return $autopilot->saveConfig($data);
        });
    }

    /**
     * ▶️ Ativar auto-pilot
     * POST /api/seo-killer/autopilot/enable
     */
    public function enableAutopilot(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $autopilot = new AutoPilot($this->accountId);
            return $autopilot->enable();
        });
    }

    /**
     * ⏸️ Desativar auto-pilot
     * POST /api/seo-killer/autopilot/disable
     */
    public function disableAutopilot(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $autopilot = new AutoPilot($this->accountId);
            return $autopilot->disable();
        });
    }

    /**
     * 🚀 Executar auto-pilot manualmente
     * POST /api/seo-killer/autopilot/run
     */
    public function runAutopilot(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $autopilot = new AutoPilot($this->accountId);
            return $autopilot->run();
        });
    }

    /**
     * 📊 Histórico de execuções do AutoPilot
     * GET /api/seo-killer/autopilot/history
     */
    public function autopilotHistory(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $limit = $this->request->getInt('limit', 20);
            $autopilot = new AutoPilot($this->accountId);

            // Compatibilidade: versões diferentes do AutoPilot podem expor métodos distintos
            if (method_exists($autopilot, 'getHistory')) {
                return $autopilot->getHistory($limit);
            }

            if (method_exists($autopilot, 'getRunHistory')) {
                return $autopilot->getRunHistory();
            }

            return ['runs' => []];
        });
    }

    /**
     * 🔍 Detalhes de uma execução do AutoPilot
     * GET /api/seo-killer/autopilot/history/{runId}
     */
    public function autopilotRunDetails(string $runId): void
    {
        $this->json(function () use ($runId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $autopilot = new AutoPilot($this->accountId);
            return $autopilot->getRunDetails((int)$runId);
        });
    }

    /**
     * 📈 Estatísticas do AutoPilot
     * GET /api/seo-killer/autopilot/stats
     */
    public function autopilotStats(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $autopilot = new AutoPilot($this->accountId);
            return $autopilot->getStats();
        });
    }

    /**
     * 📈 Evolução de scores
     * GET /api/seo-killer/autopilot/scores
     */
    public function getScoreEvolution(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $days = $this->request->getInt('days', 30);
            $autopilot = new AutoPilot($this->accountId);
            return $autopilot->getScoreEvolution($days);
        });
    }

    /**
     * 📜 Log de auditoria global
     * GET /api/seo-killer/audit/log
     */
    public function getAuditLog(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }
            $limit = $this->request->getInt('limit', 50);

            $audit = new \App\Services\AI\Core\AuditLogService();
            if (!method_exists($audit, 'getRecentLog')) {
                // Return empty if service method missing (safety)
                return [];
            }
            return $audit->getRecentLog($limit);
        });
    }

    /**
     * ⚙️ Obter configurações de IA
     * GET /api/seo-killer/settings
     */
    public function getSettings(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            try {
                $db = \App\Database::getInstance();

                $stmt = $db->prepare("
                    SELECT settings, updated_at
                    FROM seo_killer_settings
                    WHERE account_id = ?
                ");
                $stmt->execute([$this->accountId]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($result && !empty($result['settings'])) {
                    $settings = json_decode($result['settings'], true);
                    if (!is_array($settings)) {
                        $settings = $this->getDefaultSettings();
                    }
                    return [
                        'success' => true,
                        'settings' => $settings,
                        'updated_at' => $result['updated_at']
                    ];
                }

                return [
                    'success' => true,
                    'settings' => $this->getDefaultSettings(),
                    'updated_at' => null
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        });
    }

    /**
     * 💾 Salvar configurações de IA
     * POST /api/seo-killer/settings
     */
    public function saveSettings(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            try {
                $db = \App\Database::getInstance();

                // Save or update settings
                $stmt = $db->prepare("
                    INSERT INTO seo_killer_settings (account_id, settings, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        settings = VALUES(settings),
                        updated_at = NOW()
                ");

                $stmt->execute([
                    $this->accountId,
                    json_encode($data, JSON_UNESCAPED_UNICODE)
                ]);

                return [
                    'success' => true,
                    'message' => 'Configurações salvas com sucesso'
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        });
    }

    // ========================================
    // 📈 PERFORMANCE TRACKER METHODS
    // ========================================

    /**
     * 📊 Dashboard de performance
     * GET /api/seo-killer/performance/dashboard
     */
    public function getPerformanceDashboard(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $tracker = new PerformanceTracker($this->accountId);
            return $tracker->getDashboard();
        });
    }

    /**
     * 📈 Performance de um item
     * GET /api/seo-killer/performance/item/{itemId}
     */
    public function getItemPerformance(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $days = $this->request->getInt('days', 30);
            $tracker = new PerformanceTracker($this->accountId);
            return $tracker->getItemPerformance($itemId, $days);
        });
    }

    /**
     * 📊 Comparar antes/depois
     * GET /api/seo-killer/performance/compare/{itemId}
     */
    public function compareBeforeAfter(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $tracker = new PerformanceTracker($this->accountId);
            return $tracker->compareBeforeAfter($itemId);
        });
    }

    /**
     * 🏆 Top performers
     * GET /api/seo-killer/performance/top
     */
    public function getTopPerformers(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $limit = $this->request->getInt('limit', 10);
            $tracker = new PerformanceTracker($this->accountId);
            return $tracker->getTopPerformers($limit);
        });
    }

    /**
     * 📊 Métricas consolidadas
     * GET /api/seo-killer/performance/consolidated
     */
    public function getConsolidatedMetrics(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $tracker = new PerformanceTracker($this->accountId);
            return $tracker->getConsolidatedMetrics();
        });
    }

    /**
     * 📈 Evolução temporal de métricas
     * GET /api/seo-killer/performance/evolution
     */
    public function getMetricsEvolution(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $days = $this->request->getInt('days', 30);
            $tracker = new PerformanceTracker($this->accountId);
            return $tracker->getMetricsEvolution($days);
        });
    }

    /**
     * 🏆 Performance por categoria
     * GET /api/seo-killer/performance/categories
     */
    public function getCategoryPerformance(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $tracker = new PerformanceTracker($this->accountId);
            return $tracker->getCategoryPerformance();
        });
    }

    /**
     * 📄 Exportar relatório
     * GET /api/seo-killer/performance/export
     */
    public function exportPerformanceReport(): void
    {
        if (!$this->accountId) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Nenhuma conta conectada']);
            return;
        }

        $format = $this->request->get('format', 'json');
        $tracker = new PerformanceTracker($this->accountId);
        $data = $tracker->exportPerformanceReport($format);

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="seo-killer-report-' . date('Y-m-d') . '.csv"');
            echo $data;
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    }

    // ========================================
    // 📊 SEO METRICS COLLECTION (ML API)
    // ========================================

    /**
     * 🔄 Coletar metricas de performance da API do Mercado Livre
     * POST /api/seo-killer/performance/collect
     *
     * Busca visitas e vendas via ML API e salva em seo_performance_metrics.
     * Params: days (1-30), max_items (1-500)
     */
    public function collectPerformanceMetrics(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $days = $this->request->getInt('days', 1);
            $maxItems = $this->request->getInt('max_items', 200);

            $collector = new \App\Services\MercadoLivre\SEOMetricsCollectorService($this->accountId);
            $collector->setMaxItemsPerRun(min(max(1, $maxItems), 500));

            $stats = $collector->collect(min(max(1, $days), 30), false);

            return [
                'success' => $stats['errors'] === 0,
                'message' => $stats['metrics_saved'] > 0
                    ? "Coletadas {$stats['metrics_saved']} metricas de {$stats['items_processed']} itens"
                    : 'Nenhuma metrica coletada — verifique se ha itens ativos',
                'stats' => $stats,
            ];
        });
    }

    // ========================================
    // 📊 SEO SCORE METHODS
    // ========================================

    /**
     * 📊 Calculate SEO score for an item
     * GET /api/seo-killer/score/{itemId}
     */
    public function calculateScore(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $calculator = new SEOScoreCalculator($this->accountId);
            return $calculator->calculateScore($itemId);
        });
    }

    /**
     * 📈 Get score history for an item
     * GET /api/seo-killer/score/history/{itemId}
     */
    public function getScoreHistory(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $days = $this->request->getInt('days', 30);
            $calculator = new SEOScoreCalculator($this->accountId);
            return $calculator->getHistoricalScores($itemId, $days);
        });
    }

    /**
     * 🤖 Get detailed AutoPilot status
     * GET /api/seo-killer/autopilot/status
     */
    public function getAutopilotStatus(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $statusManager = new AutoPilotStatusManager($this->accountId);
            return $statusManager->getDetailedStatus();
        });
    }

    /**
     * 🔔 Get SEO score alerts
     * GET /api/seo-killer/alerts/score
     */
    public function getScoreAlerts(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $limit = $this->request->getInt('limit', 10);
            $calculator = new SEOScoreCalculator($this->accountId);
            return $calculator->getUnreadAlerts($limit);
        });
    }

    /**
     * 📊 Get SEO benchmarks
     * GET /api/seo-killer/benchmarks
     */
    public function getBenchmarks(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $categoryId = $this->request->get('category_id');
            $calculator = new SEOScoreCalculator($this->accountId);
            return $calculator->getBenchmarks($categoryId);
        });
    }

    /**
     * 🆚 Compare item score with category average
     * GET /api/seo-killer/compare/{itemId}/{categoryId}
     */
    public function compareWithCategory(string $itemId, string $categoryId): void
    {
        $this->json(function () use ($itemId, $categoryId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $calculator = new SEOScoreCalculator($this->accountId);
            return $calculator->compareWithCategoryAverage($itemId, $categoryId);
        });
    }

    /**
     * 🏆 Get top performing items (for dashboard)
     * GET /api/seo-killer/top-performers
     */
    public function getTopPerformingItems(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $limit = $this->request->getInt('limit', 10);
            $period = $this->request->get('period', '30d');

            // Cache local + score offline: evita storm de /sites/*/search (403 datacenter/CF)
            // e 50x GET /items no widget do dashboard.
            $itemService = new \App\Services\ItemService($this->accountId);
            $items = $itemService->listItems(['limit' => 50, 'source' => 'local']);

            $calculator = new SEOScoreCalculator($this->accountId);
            $scoredItems = [];

            foreach ($items['items'] ?? [] as $item) {
                $score = $calculator->calculateDashboardScore($item['id'], $item);
                if (isset($score['error'])) {
                    continue;
                }

                $scoredItems[] = [
                    'item_id' => $item['id'],
                    'title' => $item['title'] ?? '',
                    'price' => $item['price'] ?? 0,
                    'sold_quantity' => $item['sold_quantity'] ?? 0,
                    'score' => $score['overall_score'],
                    'grade' => $score['grade'],
                    'thumbnail' => $item['thumbnail'] ?? '',
                ];
            }

            usort($scoredItems, fn($a, $b) => $b['score'] <=> $a['score']);

            return [
                'success' => true,
                'items' => array_slice($scoredItems, 0, $limit),
                'period' => $period,
            ];
        });
    }

    public function getAutopilotRealStatus(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $statusManager = new AutoPilotStatusManager($this->accountId);
            return $statusManager->getRealStatus();
        });
    }

    /**
     * 📋 Get default settings
     */
    private function getDefaultSettings(): array
    {
        return [
            'providers' => [
                'openai' => [
                    'enabled' => false,
                    'key' => '',
                    'model' => 'gpt-4'
                ],
                'claude' => [
                    'enabled' => false,
                    'key' => '',
                    'model' => 'claude-3-opus'
                ],
                'strategy' => 'openai'
            ],
            'budget' => [
                'monthly' => 100,
                'alert_threshold' => 80,
                'max_per_optimization' => 2,
                'pause_on_limit' => true
            ],
            'automation' => [
                'auto_new_items' => false,
                'auto_low_score' => false,
                'min_score' => 70,
                'schedule_start' => '00:00',
                'schedule_end' => '23:59',
                'auto_apply' => false,
                'rate_limit' => 50
            ],
            'preferences' => [
                'default_optimization' => 'complete',
                'temperature' => 0.7,
                'notify_complete' => true,
                'notify_budget' => true,
                'notify_errors' => false,
                'enable_cache' => true,
                'cache_ttl' => 24
            ]
        ];
    }

    // ========================================
    // 📸 IMAGE KILLER METHODS
    // ========================================

    /**
     * 📸 Analisar imagens
     * GET /api/seo-killer/images/analyze/{itemId}
     */
    public function analyzeImages(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $killer = new ImageKiller($this->accountId);
            return $killer->analyzeImages($itemId);
        });
    }

    // ========================================
    // 🧪 A/B TESTER METHODS
    // ========================================

    /**
     * 🆕 Criar Teste A/B
     * POST /api/seo-killer/ab-test
     */
    public function createABTest(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_id']) || empty($data['type']) || empty($data['variant_b'])) {
                return ['error' => 'Informe item_id, type e variant_b'];
            }

            $tester = new ABTester($this->accountId);
            return $tester->createTest(
                $data['item_id'],
                $data['type'],
                $data['variant_b'],
                (int)($data['duration'] ?? 14)
            );
        });
    }

    /**
     * 🧪 Criar Teste A/B de Título com geração automática de variante SEO
     * POST /api/seo-killer/ab-test/title/{itemId}
     */
    public function createTitleABTest(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            // 1. Get item data
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            $item = $client->get("/items/{$itemId}");

            if (!$item || !isset($item['title'])) {
                return ['error' => 'Item não encontrado'];
            }

            // 2. Generate optimized title using TitleKiller
            $killer = new TitleKiller($this->accountId);
            $result = $killer->generateKillerTitle([
                'title' => $item['title'],
                'brand' => $this->extractAttribute($item, 'BRAND'),
                'model' => $this->extractAttribute($item, 'MODEL'),
                'attributes' => $item['attributes'] ?? [],
                'category_id' => $item['category_id'] ?? '',
            ]);

            if (isset($result['error'])) {
                return ['error' => 'Falha ao gerar título: ' . $result['error']];
            }

            $newTitle = $result['killer_title'] ?? $result['title'] ?? null;
            if (!$newTitle || $newTitle === $item['title']) {
                return ['error' => 'Não foi possível gerar um título diferente'];
            }

            // 3. Create A/B Test
            $tester = new ABTester($this->accountId);
            $testResult = $tester->createTest($itemId, 'title', $newTitle, 14);

            return array_merge($testResult, [
                'original_title' => $item['title'],
                'new_title' => $newTitle,
                'seo_score_improvement' => $result['score_improvement'] ?? null
            ]);
        });
    }

    /**
     * 📊 Listar Testes A/B
     * GET /api/seo-killer/ab-test
     */
    public function listABTests(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $tester = new ABTester($this->accountId);
            return $tester->listTests();
        });
    }

    /**
     * ✋ Parar Teste A/B
     * POST /api/seo-killer/ab-test/stop/{id}
     */
    public function stopABTest(int $id): void
    {
        $this->json(function () use ($id) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $tester = new ABTester($this->accountId);
            return $tester->stopTest($id);
        });
    }

    /**
     * 📊 Análise Estatística de Teste A/B
     * GET /api/seo-killer/ab-test/analysis/{id}
     */
    public function getABTestAnalysis(int $id): void
    {
        $this->json(function () use ($id) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $tester = new ABTester($this->accountId);
            return $tester->getTestAnalysis($id);
        });
    }

    /**
     * 🔍 Obter detalhes de um Teste A/B
     * GET /api/seo-killer/ab-test/{testId}
     */
    public function getABTest(int $testId): void
    {
        $this->json(function () use ($testId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $tester = new ABTester($this->accountId);
            $analysis = $tester->getTestAnalysis($testId);

            if (isset($analysis['error'])) {
                return $analysis;
            }

            return array_merge($analysis, [
                'results' => [
                    'confidence' => $analysis['confidence'] ?? 0,
                    'winner' => $analysis['winner'] ?? null,
                ],
            ]);
        });
    }

    /**
     * ✅ Aplicar vencedor do Teste A/B
     * POST /api/seo-killer/ab-test/apply/{id}
     */
    public function applyABTestWinner(int $id): void
    {
        $this->json(function () use ($id) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $db = \App\Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM seo_ab_tests WHERE id = ? AND account_id = ?");
            $stmt->execute([$id, $this->accountId]);
            $test = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$test) {
                return ['error' => 'Teste não encontrado'];
            }

            $winner = $test['winner_variant'] ?? null;
            if (!$winner) {
                return ['error' => 'Nenhum vencedor definido. Aguarde mais dados.'];
            }

            $winnerData = json_decode($test["variant_{$winner}_data"] ?? '{}', true);
            $value = $winnerData['value'] ?? null;

            if ($value === null) {
                return ['error' => 'Dados da variante vencedora não encontrados'];
            }

            $tester = new ABTester($this->accountId);
            $tester->stopTest($id);

            return ['success' => true, 'message' => "Variante {$winner} aplicada permanentemente"];
        });
    }

    // ==========================================
    // 🔖 WATCHLIST ENDPOINTS
    // ==========================================

    /**
     * 📌 Adicionar concorrente à watchlist
     * POST /api/seo-killer/watchlist
     */
    public function addToWatchlist(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['competitor_item_id'])) {
                return ['error' => 'Informe competitor_item_id'];
            }

            $spy = new CompetitorSpy($this->accountId);
            return $spy->addToWatchlist($data['competitor_item_id'], $data);
        });
    }

    /**
     * 📋 Listar watchlist
     * GET /api/seo-killer/watchlist
     */
    public function getWatchlist(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $filters = [
                'status' => $this->request->get('status'),
                'category_id' => $this->request->get('category_id'),
                'tags' => $this->request->get('tags'),
                'order_by' => $this->request->get('order_by', 'created_at DESC'),
                'limit' => $this->request->getInt('limit', 50),
            ];

            $spy = new CompetitorSpy($this->accountId);
            return ['success' => true, 'watchlist' => $spy->getWatchlist($filters)];
        });
    }

    /**
     * 🔄 Atualizar item da watchlist
     * POST /api/seo-killer/watchlist/{id}/update
     */
    public function updateWatchlistItem(int $id): void
    {
        $this->json(function () use ($id) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $spy = new CompetitorSpy($this->accountId);
            return $spy->updateWatchlistItem($id);
        });
    }

    /**
     * 🗑️ Remover da watchlist
     * DELETE /api/seo-killer/watchlist/{id}
     */
    public function removeFromWatchlist(int $id): void
    {
        $this->json(function () use ($id) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $spy = new CompetitorSpy($this->accountId);
            $success = $spy->removeFromWatchlist($id);

            return [
                'success' => $success,
                'message' => $success ? 'Removido da watchlist' : 'Erro ao remover',
            ];
        });
    }

    /**
     * 📜 Histórico de mudanças de um concorrente
     * GET /api/seo-killer/watchlist/{id}/history
     */
    public function getWatchlistHistory(int $id): void
    {
        $this->json(function () use ($id) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $days = $this->request->getInt('days', 30);
            $spy = new CompetitorSpy($this->accountId);

            return [
                'success' => true,
                'history' => $spy->getHistory($id, $days),
            ];
        });
    }

    /**
     * 🔔 Listar alertas
     * GET /api/seo-killer/alerts
     */
    public function getAlerts(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $filters = [
                'status' => $this->request->get('status'),
                'priority' => $this->request->get('priority'),
                'limit' => $this->request->getInt('limit', 50),
            ];

            $spy = new CompetitorSpy($this->accountId);
            return ['success' => true, 'alerts' => $spy->getAlerts($filters)];
        });
    }

    /**
     * ✅ Marcar alerta como lido
     * POST /api/seo-killer/alerts/{id}/read
     */
    public function markAlertAsRead(int $id): void
    {
        $this->json(function () use ($id) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $spy = new CompetitorSpy($this->accountId);
            $success = $spy->markAlertAsRead($id);

            return [
                'success' => $success,
                'message' => $success ? 'Alerta marcado como lido' : 'Erro',
            ];
        });
    }

    /**
     * 📄 Exportar análise de concorrentes em PDF
     * POST /api/seo-killer/export/competitor
     */
    public function exportCompetitorPdf(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $itemId = $data['item_id'] ?? null;
            $competitors = $data['competitors'] ?? [];
            $options = $data['options'] ?? [];

            if (!$itemId || empty($competitors)) {
                return ['error' => 'item_id e competitors são obrigatórios'];
            }

            $exporter = new \App\Services\AI\SEO\PdfExporter($this->accountId);
            return $exporter->exportCompetitorAnalysis($itemId, $competitors, $options);
        });
    }

    /**
     * 📤 Upload de imagem (Temp)
     * POST /api/seo-killer/images/upload
     */
    public function uploadImage(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $itemId = $this->request->post('item_id');
            if (!$itemId) {
                return ['error' => 'Item ID é obrigatório'];
            }

            if (empty($_FILES['images'])) {
                return ['error' => 'Nenhum arquivo enviado'];
            }

            $uploaded = [];
            $errors = [];

            $analyzer = new \App\Services\AI\SEO\ImageKiller($this->accountId);

            if (isset($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
                foreach ($_FILES['images']['name'] as $key => $name) {
                    $file = [
                        'name' => $_FILES['images']['name'][$key],
                        'type' => $_FILES['images']['type'][$key],
                        'tmp_name' => $_FILES['images']['tmp_name'][$key],
                        'error' => $_FILES['images']['error'][$key],
                        'size' => $_FILES['images']['size'][$key],
                    ];

                    try {
                        $uploaded[] = $analyzer->uploadImage($itemId, $file);
                    } catch (\Exception $e) {
                        $errors[] = $name . ': ' . $e->getMessage();
                    }
                }
            } else {
                return ['error' => 'Formato de upload inválido. Use images[]'];
            }

            if (empty($uploaded) && !empty($errors)) {
                return ['error' => implode(', ', $errors)];
            }

            return [
                'success' => true,
                'uploaded' => $uploaded,
                ...(count($uploaded) === 1 ? $uploaded[0] : [])
            ];
        });
    }

    /**
     * 🔄 Atualizar imagens (Apply Changes)
     * POST /api/seo-killer/images/update/{itemId}
     */
    public function updateImages(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }
            $data = $this->getJsonInput();

            if (empty($data['changes'])) {
                return ['error' => 'Nenhuma mudança enviada'];
            }

            $analyzer = new \App\Services\AI\SEO\ImageKiller($this->accountId);
            $response = $analyzer->updateImages($itemId, $data['changes']);

            return [
                'success' => true,
                'ml_response' => $response
            ];
        });
    }

    /**
     * 📄 Exportar histórico de watchlist em PDF
     * GET /api/seo-killer/export/watchlist/{id}
     */
    public function exportWatchlistPdf(int $id): void
    {
        $this->json(function () use ($id) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $days = $this->request->getInt('days', 30);
            $exporter = new \App\Services\AI\SEO\PdfExporter($this->accountId);
            return $exporter->exportWatchlistHistory($id, $days);
        });
    }

    /**
     * 📊 Dashboard de Inteligência Competitiva
     * GET /api/seo-killer/intelligence/dashboard
     */
    public function getIntelligenceDashboard(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $categoryId = $this->request->get('category_id');
            $intel = new \App\Services\AI\SEO\CompetitiveIntelligence($this->accountId);
            return $intel->getDashboard($categoryId);
        });
    }

    /**
     * 🎯 Análise SWOT
     * POST /api/seo-killer/intelligence/swot
     */
    public function getSwotAnalysis(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->request->json();
            $itemId = $data['item_id'] ?? null;
            $competitorIds = $data['competitor_ids'] ?? [];

            if (!$itemId || empty($competitorIds)) {
                return ['error' => 'item_id e competitor_ids são obrigatórios'];
            }

            $intel = new \App\Services\AI\SEO\CompetitiveIntelligence($this->accountId);
            return $intel->swotAnalysis($itemId, $competitorIds);
        });
    }

    /**
     * 📈 Previsão de Demanda
     * GET /api/seo-killer/analytics/demand-forecast
     */
    public function getDemandForecast(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $categoryId = $this->request->get('category_id');
            if (!$categoryId) {
                return ['error' => 'category_id é obrigatório'];
            }

            $analytics = new \App\Services\AI\SEO\MarketAnalytics($this->accountId);
            return $analytics->predictDemand($categoryId);
        });
    }

    /**
     * 🌿 Detecção de Sazonalidade
     * GET /api/seo-killer/analytics/seasonality
     */
    public function getSeasonalityAnalysis(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $categoryId = $this->request->get('category_id');
            if (!$categoryId) {
                return ['error' => 'category_id é obrigatório'];
            }

            $analytics = new \App\Services\AI\SEO\MarketAnalytics($this->accountId);
            return $analytics->detectSeasonality($categoryId);
        });
    }

    /**
     * 🔥 Oportunidades Emergentes
     * GET /api/seo-killer/analytics/opportunities
     */
    public function getEmergingOpportunities(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $analytics = new \App\Services\AI\SEO\MarketAnalytics($this->accountId);
            return $analytics->detectEmergingOpportunities();
        });
    }

    /**
     * 📊 Sentimento de Mercado
     * GET /api/seo-killer/analytics/market-sentiment
     */
    public function getMarketSentiment(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $categoryId = $this->request->get('category_id');
            $analytics = new \App\Services\AI\SEO\MarketAnalytics($this->accountId);
            return $analytics->analyzeMarketSentiment($categoryId);
        });
    }
    public function copyCompetitorStrategy(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $competitorId = $data['competitor_id'] ?? null;
            $myItemId = $data['my_item_id'] ?? null;

            if (!$competitorId || !$myItemId) {
                return ['error' => 'competitor_id e my_item_id são obrigatórios'];
            }

            $spy = new \App\Services\AI\SEO\CompetitorSpy($this->accountId);
            return $spy->generateOptimizationFromCompetitor($competitorId, $myItemId);
        });
    }

    /**
     * GSC: Get Auth URL
     */
    public function gscAuthUrl()
    {
        $this->json(function () {
            $accountId = $this->accountId ?? $this->getAccountId();

            if (!$accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $service = new GoogleSearchConsoleService((int) $accountId);
            $url = $service->getAuthUrl();

            return ['success' => true, 'url' => $url];
        });
    }

    /**
     * GSC: Handle Callback
     */
    public function gscCallback()
    {
        $code = $this->request->get('code', '');

        if (empty($code)) {
            // Should redirect to error page or dashboard with error
            header('Location: /dashboard/seo-killer?error=gsc_auth_failed');
            exit;
        }

        try {
            $accountId = $this->accountId ?? $this->getAccountId();

            if (!$accountId) {
                header('Location: /dashboard/seo-killer?error=no_account');
                exit;
            }

            $service = new GoogleSearchConsoleService((int) $accountId);
            $service->handleCallback($code);

            header('Location: /dashboard/seo-killer?success=gsc_connected');
        } catch (\Exception $e) {
            log_error('Erro no callback GSC', [
                'error' => $e->getMessage(),
            ]);
            header('Location: /dashboard/seo-killer?error=' . urlencode($e->getMessage()));
        }
        exit;
    }

    /**
     * GSC: Get Status
     */
    public function gscStatus()
    {
        $this->json(function () {
            $accountId = $this->accountId ?? $this->getAccountId();

            if (!$accountId) {
                return ['success' => true, 'status' => ['connected' => false]];
            }

            $service = new GoogleSearchConsoleService((int) $accountId);
            $status = $service->getStatus();

            return ['success' => true, 'status' => $status];
        });
    }

    /**
     * GSC: Get Analytics Data for Dashboard
     * GET /api/seo-killer/gsc/data
     */
    public function gscData(): void
    {
        $this->json(function () {
            $accountId = $this->accountId ?? $this->getAccountId();

            if (!$accountId) {
                return [
                    'success' => false,
                    'error' => 'Nenhuma conta conectada',
                ];
            }

            $service = new GoogleSearchConsoleService((int) $accountId);

            $startDate = $this->request->get('start_date', date('Y-m-d', strtotime('-29 days')));
            $endDate = $this->request->get('end_date', date('Y-m-d'));

            $analytics = $service->getAnalyticsData($startDate, $endDate);

            return [
                'success' => true,
                'data' => [
                    'clicks' => $analytics['clicks'] ?? 0,
                    'impressions' => $analytics['impressions'] ?? 0,
                    'ctr' => $analytics['ctr'] ?? '0%',
                    'position' => $analytics['position'] ?? 0,
                    'chartLabels' => $analytics['chart']['labels'] ?? [],
                    'chartClicks' => $analytics['chart']['clicks'] ?? [],
                    'chartImpressions' => $analytics['chart']['impressions'] ?? [],
                    'queries' => $analytics['queries'] ?? [],
                ],
            ];
        });
    }


    /**
     * 📝 Atualizar Item (Título, Preço)
     * POST /api/seo-killer/item/update
     */
    public function updateItem(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $itemId = $data['item_id'] ?? null;

            if (!$itemId) {
                return ['error' => 'item_id é obrigatório'];
            }

            $mlClient = new \App\Services\MercadoLivreClient($this->accountId);
            $updateData = [];

            if (isset($data['title'])) $updateData['title'] = $data['title'];
            if (isset($data['price'])) $updateData['price'] = $data['price'];

            if (empty($updateData)) {
                return ['error' => 'Nenhum dado para atualizar'];
            }

            return $mlClient->put("/items/{$itemId}", $updateData);
        });
    }

    /**
     * 📊 Dashboard View
     * GET /dashboard/seo-killer
     */
    public function dashboard(): void
    {
        require __DIR__ . '/../Views/dashboard/seo-killer.php';
    }
    /**
     * Schema: Generate JSON-LD
     */
    public function generateSchema($itemId)
    {
        $this->json(function () use ($itemId) {
            $service = new SchemaGenerator($this->accountId);
            return $service->generateProductSchema($itemId);
        });
    }

    /**
     * 🔗 Análise de Backlinks
     * POST /api/seo-killer/backlinks/analyze
     */
    public function analyzeBacklinks()
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $itemId = $data['item_id'] ?? null;

            if (!$itemId) {
                return ['error' => 'item_id é obrigatório'];
            }

            $analyzer = new BacklinkAnalyzer($this->accountId);
            return $analyzer->analyzeOpportunities($itemId);
        });
    }

    /**
     * 🔑 Listar Chaves de API
     * GET /api/seo-killer/api-keys
     */
    public function listApiKeys()
    {
        $this->json(function () {
            if (!$this->accountId) return ['error' => 'Login requerido'];
            $service = new ApiKeyService($this->accountId);
            return ['success' => true, 'keys' => $service->listKeys()];
        });
    }

    /**
     * 🔑 Criar Chave de API
     * POST /api/seo-killer/api-keys
     */
    public function createApiKey()
    {
        $this->json(function () {
            if (!$this->accountId) return ['error' => 'Login requerido'];
            $data = $this->getJsonInput();
            $name = $data['name'] ?? 'Nova Chave';

            $service = new ApiKeyService($this->accountId);
            return ['success' => true, 'key' => $service->createKey($name)];
        });
    }

    /**
     * 🔑 Revogar Chave de API
     * DELETE /api/seo-killer/api-keys/{clientId}
     */
    public function revokeApiKey($clientId)
    {
        $this->json(function () use ($clientId) {
            if (!$this->accountId) return ['error' => 'Login requerido'];
            $service = new ApiKeyService($this->accountId);
            $service->revokeKey($clientId);
        });
    }

    /**
     * 📄 Exportar Relatório PDF
     * GET /api/seo-killer/export/pdf/{type}/{itemId}
     * Types: competitor, history, performance
     */
    public function exportPdf($type, $itemId)
    {
        $this->json(function () use ($type, $itemId) {
            if (!$this->accountId) return ['error' => 'Login requerido'];
            $exporter = new PdfExporter($this->accountId);

            // For file download, we might bypass json wrapper or return base64/url
            // Here we assume the service returns an array with file path or url

            switch ($type) {
                case 'competitor':
                    // Need competitor ID, might need query param for it if itemId is not enough
                    // Assuming itemId here is actually the primary subject ID
                    return $exporter->exportCompetitorAnalysis($itemId, []);
                case 'history':
                    return $exporter->exportWatchlistHistory($itemId, 30);
                case 'performance':
                    return $exporter->exportMonthlyReport($itemId, date('Y-m'));
                default:
                    throw new \Exception('Tipo de relatório inválido');
            }
        });
    }

    // ========================================================================
    // 🎯 SEO STRATEGIES - Estratégias Avançadas de SEO
    // ========================================================================

    /**
     * 📖 Expandir sinônimos com hierarquia de 4 níveis
     * POST /api/seo-killer/strategies/synonyms/expand
     *
     * @body {
     *   "keyword": "bauleto",
     *   "category_id": "MLB3530",
     *   "options": {"levels": [1,2,3,4], "limit_per_level": 5}
     * }
     */
    public function expandSynonyms(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['keyword'])) {
                return ['error' => 'Informe keyword'];
            }

            $service = new SynonymExpansionService($this->accountId);

            $options = $data['options'] ?? [];
            $categoryId = $data['category_id'] ?? null;

            return $service->expand($data['keyword'], $categoryId, $options);
        });
    }

    /**
     * 📊 Obter hierarquia de sinônimos para categoria
     * GET /api/seo-killer/strategies/synonyms/hierarchy/{categoryId}
     */
    public function getSynonymHierarchy(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $service = new SynonymExpansionService($this->accountId);
            return $service->getHierarchy($categoryId);
        });
    }

    /**
     * 🏗️ Gerar hierarquia de sinônimos para nova categoria
     * POST /api/seo-killer/strategies/synonyms/generate
     *
     * @body {
     *   "category_id": "MLB1234",
     *   "seed_keywords": ["palavra1", "palavra2"],
     *   "force_regenerate": false
     * }
     */
    public function generateSynonymHierarchy(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['category_id'])) {
                return ['error' => 'Informe category_id'];
            }

            $service = new SynonymExpansionService($this->accountId);

            $seedKeywords = $data['seed_keywords'] ?? [];
            $forceRegenerate = $data['force_regenerate'] ?? false;

            return $service->generateHierarchyForCategory(
                $data['category_id'],
                $seedKeywords,
                $forceRegenerate
            );
        });
    }

    /**
     * 🎯 Selecionar sinônimos para campo específico
     * POST /api/seo-killer/strategies/synonyms/select
     *
     * @body {
     *   "keyword": "bauleto",
     *   "category_id": "MLB3530",
     *   "field": "title",
     *   "limit": 3
     * }
     */
    public function selectSynonymsForField(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['keyword']) || empty($data['field'])) {
                return ['error' => 'Informe keyword e field'];
            }

            $validFields = ['title', 'model', 'description', 'keywords'];
            if (!in_array($data['field'], $validFields)) {
                return ['error' => 'Field inválido. Use: ' . implode(', ', $validFields)];
            }

            $service = new SynonymExpansionService($this->accountId);

            return $service->selectForField(
                $data['keyword'],
                $data['category_id'] ?? null,
                $data['field'],
                $data['limit'] ?? 5
            );
        });
    }

    /**
     * ⚡ Gerar modelo otimizado com sinônimos
     * POST /api/seo-killer/strategies/synonyms/model
     *
     * @body {
     *   "keywords": ["bauleto", "moto"],
     *   "category_id": "MLB3530",
     *   "max_length": 60
     * }
     */
    public function generateOptimizedModel(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['keywords']) || !is_array($data['keywords'])) {
                return ['error' => 'Informe array de keywords'];
            }

            $service = new SynonymExpansionService($this->accountId);

            $title = is_array($data['keywords']) ? implode(' ', $data['keywords']) : $data['keywords'];
            return $service->generateOptimizedModel(
                $title,
                $data['category_id'] ?? ''
            );
        });
    }

    /**
     * 📈 Calcular score semântico de keywords
     * POST /api/seo-killer/strategies/score/calculate
     *
     * @body {
     *   "keywords": ["bauleto", "moto", "delivery"],
     *   "category_id": "MLB3530",
     *   "context": "profissional"
     * }
     */
    public function calculateSemanticScore(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['keywords'])) {
                return ['error' => 'Informe keywords'];
            }

            $keywords = is_array($data['keywords']) ? $data['keywords'] : [$data['keywords']];
            $service = new SemanticScoreService($this->accountId);

            return $service->scoreWords(
                $keywords,
                $data['category_id'] ?? null,
                $data['context'] ?? null
            );
        });
    }

    /**
     * 🏆 Rankear keywords por score semântico
     * POST /api/seo-killer/strategies/score/rank
     *
     * @body {
     *   "keywords": ["bauleto", "moto", "delivery", "caixa"],
     *   "category_id": "MLB3530",
     *   "limit": 10
     * }
     */
    public function rankBySemanticScore(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['keywords']) || !is_array($data['keywords'])) {
                return ['error' => 'Informe array de keywords'];
            }

            $service = new SemanticScoreService($this->accountId);

            return $service->rankByScore(
                $data['keywords'],
                $data['category_id'] ?? null,
                $data['limit'] ?? 20
            );
        });
    }

    /**
     * 🔍 Filtrar keywords por score mínimo
     * POST /api/seo-killer/strategies/score/filter
     *
     * @body {
     *   "keywords": ["bauleto", "caixa", "maleta"],
     *   "category_id": "MLB3530",
     *   "min_score": 0.5
     * }
     */
    public function filterBySemanticScore(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['keywords']) || !is_array($data['keywords'])) {
                return ['error' => 'Informe array de keywords'];
            }

            $service = new SemanticScoreService($this->accountId);

            return $service->filterByMinScore(
                $data['keywords'],
                $data['category_id'] ?? null,
                $data['min_score'] ?? 0.5
            );
        });
    }

    /**
     * 🔑 Obter keywords via arquitetura híbrida
     * POST /api/seo-killer/strategies/keywords/fetch
     *
     * @body {
     *   "base_keyword": "bauleto moto",
     *   "category_id": "MLB3530",
     *   "options": {"include_trending": true, "include_autocomplete": true}
     * }
     */
    public function fetchKeywords(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['base_keyword'])) {
                return ['error' => 'Informe base_keyword'];
            }

            $service = new KeywordSourceService($this->accountId);

            return $service->getKeywords(
                $data['base_keyword'],
                $data['category_id'] ?? null,
                $data['options'] ?? []
            );
        });
    }

    /**
     * 📊 Obter keywords em tendência
     * GET /api/seo-killer/strategies/keywords/trending/{categoryId}
     */
    public function getTrendingKeywords(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $limit = $this->request->getInt('limit', 20);

            $service = new KeywordSourceService($this->accountId);
            return $service->getTrendingKeywords($categoryId, $limit);
        });
    }

    /**
     * 🔮 Obter sugestões de autocomplete
     * GET /api/seo-killer/strategies/keywords/autocomplete
     *
     * @query q=bauleto&limit=10
     */
    public function getAutocompleteKeywords(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $query = $this->request->get('q', '');
            if (empty($query)) {
                return ['error' => 'Informe parâmetro q'];
            }

            $categoryId = $this->request->get('category_id');

            $service = new KeywordSourceService($this->accountId);
            return $service->getAutocompleteKeywords($query, $categoryId);
        });
    }

    /**
     * 🕵️ Obter keywords dos concorrentes
     * POST /api/seo-killer/strategies/keywords/competitor
     *
     * @body {
     *   "category_id": "MLB3530",
     *   "search_query": "bauleto moto",
     *   "limit": 20
     * }
     */
    public function getCompetitorKeywords(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['category_id'])) {
                return ['error' => 'Informe category_id'];
            }

            $service = new KeywordSourceService($this->accountId);

            return $service->getCompetitorKeywords(
                $data['category_id'],
                $data['search_query'] ?? null,
                $data['limit'] ?? 20
            );
        });
    }

    /**
     * 🗑️ Invalidar cache de keywords
     * DELETE /api/seo-killer/strategies/keywords/cache/{categoryId}
     */
    public function invalidateKeywordCache(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $service = new KeywordSourceService($this->accountId);
            $service->invalidateCache($categoryId);

            return [
                'success' => true,
                'message' => "Cache de keywords para {$categoryId} invalidado"
            ];
        });
    }

    /**
     * 📋 Obter contextos de uso para categoria
     * GET /api/seo-killer/strategies/contexts/{categoryId}
     */
    public function getUseContexts(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\AI\SEO\Strategies\UseContextService($this->accountId);
            return $service->getContextsForCategory($categoryId);
        });
    }

    /**
     * ⚙️ Obter configuração de estratégia para categoria
     * GET /api/seo-killer/strategies/config/{categoryId}
     */
    public function getStrategyConfig(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $db = \App\Database::getInstance();

            $stmt = $db->prepare("
                SELECT config_key, config_value
                FROM seo_category_config
                WHERE category_id = :category_id
            ");
            $stmt->execute(['category_id' => $categoryId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $config = [];
            foreach ($rows as $row) {
                $config[$row['config_key']] = json_decode($row['config_value'], true);
            }

            return [
                'category_id' => $categoryId,
                'config' => $config,
                'has_config' => !empty($config)
            ];
        });
    }

    /**
     * 💾 Salvar configuração de estratégia para categoria
     * POST /api/seo-killer/strategies/config/{categoryId}
     *
     * @body {
     *   "title_max_length": 60,
     *   "density_min": 0.5,
     *   "density_max": 3.0,
     *   "use_contexts": ["profissional", "lazer"]
     * }
     */
    public function saveStrategyConfig(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $db = \App\Database::getInstance();

            $stmt = $db->prepare("
                INSERT INTO seo_category_config (category_id, config_key, config_value)
                VALUES (:category_id, :config_key, :config_value)
                ON DUPLICATE KEY UPDATE
                    config_value = VALUES(config_value),
                    updated_at = NOW()
            ");

            $saved = 0;
            foreach ($data as $key => $value) {
                $stmt->execute([
                    'category_id' => $categoryId,
                    'config_key' => $key,
                    'config_value' => json_encode($value)
                ]);
                $saved++;
            }

            return [
                'success' => true,
                'category_id' => $categoryId,
                'saved_configs' => $saved
            ];
        });
    }

    // ========================================================================
    // 🔒 HIDDEN FIELDS - Campos Ocultos (E2)
    // ========================================================================

    /**
     * 🔍 Analisar campos ocultos de um item
     * GET /api/seo-killer/strategies/hidden-fields/{itemId}
     */
    public function analyzeHiddenFields(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $service = new HiddenFieldsService($this->accountId);
            return $service->analyzeItem($itemId);
        });
    }

    /**
     * 💡 Gerar sugestões para campos ocultos
     * POST /api/seo-killer/strategies/hidden-fields/suggest
     *
     * @body {
     *   "title": "Bauleto Moto 45L",
     *   "brand": "Givi",
     *   "model": "E45N",
     *   "category_id": "MLB3530"
     * }
     */
    public function suggestHiddenFields(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['title'])) {
                return ['error' => 'Informe title'];
            }

            $service = new HiddenFieldsService($this->accountId);
            return $service->generateSuggestions($data, $data['category_id'] ?? null);
        });
    }

    /**
     * ✅ Aplicar campos ocultos a um item
     * POST /api/seo-killer/strategies/hidden-fields/apply/{itemId}
     *
     * @body {
     *   "KEYWORDS": "sinonimo1 sinonimo2",
     *   "MPN": "GIVI-E45N",
     *   "dry_run": false
     * }
     */
    public function applyHiddenFields(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $dryRun = $data['dry_run'] ?? false;
            unset($data['dry_run']);

            if (empty($data)) {
                return ['error' => 'Informe pelo menos um campo para aplicar'];
            }

            $service = new HiddenFieldsService($this->accountId);
            return $service->applyToItem($itemId, $data, $dryRun);
        });
    }

    /**
     * 📋 Obter campos ocultos disponíveis para categoria
     * GET /api/seo-killer/strategies/hidden-fields/available/{categoryId}
     */
    public function getAvailableHiddenFields(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $service = new HiddenFieldsService($this->accountId);
            return $service->getAvailableFields($categoryId);
        });
    }

    // ========================================================================
    // 💉 KEYWORD INJECTOR - Injeção Natural (E3)
    // ========================================================================

    /**
     * 💉 Injetar keywords em título
     * POST /api/seo-killer/strategies/inject/title
     *
     * @body {
     *   "title": "Bauleto Moto Universal",
     *   "keywords": ["delivery", "motoboy", "45 litros"],
     *   "category_id": "MLB3530"
     * }
     */
    public function injectKeywordsTitle(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['title']) || empty($data['keywords'])) {
                return ['error' => 'Informe title e keywords'];
            }

            $service = new KeywordInjectorService($this->accountId);
            return $service->injectInTitle(
                $data['title'],
                $data['keywords'],
                $data['category_id'] ?? null
            );
        });
    }

    /**
     * 💉 Injetar keywords em descrição
     * POST /api/seo-killer/strategies/inject/description
     *
     * @body {
     *   "description": "Descrição do produto...",
     *   "keywords": ["bauleto", "moto", "delivery"],
     *   "target_density": 1.5,
     *   "category_id": "MLB3530"
     * }
     */
    public function injectKeywordsDescription(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['description']) || empty($data['keywords'])) {
                return ['error' => 'Informe description e keywords'];
            }

            $service = new KeywordInjectorService($this->accountId);
            return $service->injectInDescription(
                $data['description'],
                $data['keywords'],
                $data['category_id'] ?? null,
                [
                    'target_density' => $data['target_density'] ?? 1.5,
                    'min_length' => $data['min_length'] ?? 500
                ]
            );
        });
    }

    /**
     * 📊 Analisar densidade de keywords
     * POST /api/seo-killer/strategies/inject/density
     *
     * @body {
     *   "text": "Texto para analisar...",
     *   "keywords": ["bauleto", "moto"]
     * }
     */
    public function analyzeKeywordDensity(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['text']) || empty($data['keywords'])) {
                return ['error' => 'Informe text e keywords'];
            }

            $service = new KeywordInjectorService($this->accountId);
            return $service->analyzeDensity($data['text'], $data['keywords']);
        });
    }

    /**
     * 📍 Sugerir pontos de injeção
     * POST /api/seo-killer/strategies/inject/points
     */
    public function suggestInjectionPoints(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['text']) || empty($data['keywords'])) {
                return ['error' => 'Informe text e keywords'];
            }

            $service = new KeywordInjectorService($this->accountId);
            return $service->suggestInjectionPoints($data['text'], $data['keywords']);
        });
    }

    // ========================================================================
    // 🔍 SEARCH TYPE COVERAGE - Cobertura de Tipos de Busca (E4)
    // ========================================================================

    /**
     * 📊 Analisar cobertura de tipos de busca
     * POST /api/seo-killer/strategies/coverage/analyze
     *
     * @body {
     *   "title": "Bauleto Moto 45L Givi",
     *   "description": "...",
     *   "model": "E45N",
     *   "brand": "Givi",
     *   "category_id": "MLB3530"
     * }
     */
    public function analyzeCoverage(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['title'])) {
                return ['error' => 'Informe pelo menos title'];
            }

            $service = new SearchTypeCoverageService($this->accountId);
            return $service->analyzeCoverage($data);
        });
    }

    /**
     * 🔑 Gerar keywords para cobertura completa
     * POST /api/seo-killer/strategies/coverage/keywords
     *
     * @body {
     *   "base_keyword": "bauleto",
     *   "brand": "Givi",
     *   "model": "E45N",
     *   "specs": {"capacidade": "45 litros"},
     *   "category_id": "MLB3530"
     * }
     */
    public function generateCoverageKeywords(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['base_keyword'])) {
                return ['error' => 'Informe base_keyword'];
            }

            $service = new SearchTypeCoverageService($this->accountId);
            return $service->generateCoverageKeywords(
                $data['base_keyword'],
                [
                    'brand' => $data['brand'] ?? '',
                    'model' => $data['model'] ?? '',
                    'specs' => $data['specs'] ?? [],
                    'use_case' => $data['use_case'] ?? 'geral'
                ],
                $data['category_id'] ?? null
            );
        });
    }

    /**
     * ⚡ Otimizar item para cobertura completa
     * POST /api/seo-killer/strategies/coverage/optimize
     */
    public function optimizeForCoverage(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_data']) || empty($data['keywords'])) {
                return ['error' => 'Informe item_data e keywords'];
            }

            $service = new SearchTypeCoverageService($this->accountId);
            return $service->optimizeForCoverage($data['item_data'], $data['keywords']);
        });
    }

    /**
     * 🏷️ Classificar query de busca
     * GET /api/seo-killer/strategies/coverage/classify
     *
     * @query q=bauleto moto 45 litros givi
     */
    public function classifySearchQuery(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $query = $this->request->get('q', '');
            if (empty($query)) {
                return ['error' => 'Informe parâmetro q'];
            }

            $service = new SearchTypeCoverageService($this->accountId);
            return $service->classifySearchQuery($query);
        });
    }

    /**
     * 💡 Sugerir keywords faltantes
     * POST /api/seo-killer/strategies/coverage/missing
     */
    public function suggestMissingKeywords(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['base_keyword'])) {
                return ['error' => 'Informe base_keyword'];
            }

            // Primeiro analisar cobertura atual
            $service = new SearchTypeCoverageService($this->accountId);
            $currentCoverage = $service->analyzeCoverage($data['item_data'] ?? []);

            return $service->suggestMissingKeywords(
                $currentCoverage,
                $data['base_keyword'],
                $data['category_id'] ?? null
            );
        });
    }

    // ========================================================================
    // ⚖️ FIELD WEIGHT - Distribuição por Peso (E5)
    // ========================================================================

    /**
     * 📊 Distribuir keywords por peso dos campos
     * POST /api/seo-killer/strategies/weight/distribute
     *
     * @body {
     *   "keywords": ["bauleto", "moto", "delivery", "45 litros"],
     *   "category_id": "MLB3530",
     *   "current_values": {
     *     "title": "Bauleto Moto Universal",
     *     "model": "..."
     *   }
     * }
     */
    public function distributeByWeight(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['keywords']) || !is_array($data['keywords'])) {
                return ['error' => 'Informe array de keywords'];
            }

            $service = new FieldWeightService($this->accountId);
            return $service->distributeKeywords(
                $data['keywords'],
                $data['category_id'] ?? null,
                $data['current_values'] ?? []
            );
        });
    }

    /**
     * 🔍 Analisar distribuição atual
     * POST /api/seo-killer/strategies/weight/analyze
     */
    public function analyzeDistribution(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['title'])) {
                return ['error' => 'Informe pelo menos title'];
            }

            $service = new FieldWeightService($this->accountId);
            return $service->analyzeCurrentDistribution($data);
        });
    }

    /**
     * ⚡ Otimizar distribuição
     * POST /api/seo-killer/strategies/weight/optimize
     */
    public function optimizeDistribution(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_data'])) {
                return ['error' => 'Informe item_data'];
            }

            $service = new FieldWeightService($this->accountId);
            return $service->optimizeDistribution(
                $data['item_data'],
                $data['additional_keywords'] ?? [],
                $data['category_id'] ?? null
            );
        });
    }

    /**
     * 🔄 Sugerir realocação de keywords
     * POST /api/seo-killer/strategies/weight/reallocate
     */
    public function suggestReallocation(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_data'])) {
                return ['error' => 'Informe item_data'];
            }

            $service = new FieldWeightService($this->accountId);
            return $service->suggestReallocation(
                $data['item_data'],
                $data['category_id'] ?? null
            );
        });
    }

    /**
     * 📈 Calcular eficiência de indexação
     * POST /api/seo-killer/strategies/weight/efficiency
     */
    public function calculateIndexingEfficiency(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data)) {
                return ['error' => 'Informe dados do item'];
            }

            $service = new FieldWeightService($this->accountId);
            return $service->calculateIndexingEfficiency($data);
        });
    }

    /**
     * 📋 Obter pesos dos campos
     * GET /api/seo-killer/strategies/weight/fields
     */
    public function getFieldWeights(): void
    {
        $this->json(function () {
            $service = new FieldWeightService($this->accountId);
            return [
                'field_weights' => $service->getFieldWeights(),
                'description' => 'Pesos de indexação por campo do Mercado Livre'
            ];
        });
    }

    /**
     * 🎯 Estratégia para maximizar peso
     * POST /api/seo-killer/strategies/weight/maximize
     */
    public function getWeightMaximizationStrategy(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data)) {
                return ['error' => 'Informe dados do item'];
            }

            $service = new FieldWeightService($this->accountId);
            return $service->getWeightMaximizationStrategy($data);
        });
    }

    // ========================================================================
    // E6: USE CONTEXT ENDPOINTS
    // ========================================================================

    /**
     * 📋 Obter contextos disponíveis
     * GET /api/seo-killer/strategies/contexts/available
     */
    public function getAvailableContexts(): void
    {
        $this->json(function () {
            $service = new \App\Services\AI\SEO\Strategies\UseContextService($this->accountId);
            return $service->getAvailableContexts();
        });
    }

    /**
     * 📋 Obter contextos para categoria
     * GET /api/seo-killer/strategies/contexts/category/{categoryId}
     */
    public function getContextsForCategory(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            $service = new \App\Services\AI\SEO\Strategies\UseContextService($this->accountId);
            return $service->getContextsForCategory($categoryId);
        });
    }

    /**
     * 🔍 Detectar contextos em texto
     * POST /api/seo-killer/strategies/contexts/detect
     */
    public function detectContexts(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['text'])) {
                return ['error' => 'Informe text'];
            }

            $service = new \App\Services\AI\SEO\Strategies\UseContextService($this->accountId);
            return $service->detectContexts($data['text']);
        });
    }

    /**
     * 🔑 Gerar keywords de contexto
     * POST /api/seo-killer/strategies/contexts/keywords
     */
    public function generateContextKeywords(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['contexts'])) {
                return ['error' => 'Informe contexts (array)'];
            }

            $service = new \App\Services\AI\SEO\Strategies\UseContextService($this->accountId);
            return $service->generateContextKeywords(
                $data['contexts'],
                $data['category_id'] ?? null,
                $data['limit'] ?? 10
            );
        });
    }

    /**
     * 💡 Sugerir contextos para produto
     * POST /api/seo-killer/strategies/contexts/suggest
     */
    public function suggestContexts(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data)) {
                return ['error' => 'Informe dados do produto'];
            }

            $service = new \App\Services\AI\SEO\Strategies\UseContextService($this->accountId);
            return $service->suggestContexts($data);
        });
    }

    /**
     * ✨ Enriquecer anúncio com contextos
     * POST /api/seo-killer/strategies/contexts/enrich
     */
    public function enrichWithContexts(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['item_data']) || empty($data['contexts'])) {
                return ['error' => 'Informe item_data e contexts'];
            }

            $service = new \App\Services\AI\SEO\Strategies\UseContextService($this->accountId);
            return $service->enrichWithContexts(
                $data['item_data'],
                $data['contexts'],
                $data['category_id'] ?? null
            );
        });
    }

    // ========================================================================
    // E7: LONG TAIL ENDPOINTS
    // ========================================================================

    /**
     * 🔗 Gerar long-tails
     * POST /api/seo-killer/strategies/longtail/generate
     */
    public function generateLongTails(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['base_keyword'])) {
                return ['error' => 'Informe base_keyword'];
            }

            $service = new \App\Services\AI\SEO\Strategies\LongTailGeneratorService($this->accountId);
            return $service->generate($data['base_keyword'], $data);
        });
    }

    /**
     * 🔍 Gerar long-tails do autocomplete
     * GET /api/seo-killer/strategies/longtail/autocomplete/{keyword}
     */
    public function generateLongTailsFromAutocomplete(string $keyword): void
    {
        $this->json(function () use ($keyword) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\AI\SEO\Strategies\LongTailGeneratorService($this->accountId);
            return $service->generateFromAutocomplete(urldecode($keyword));
        });
    }

    /**
     * 🕵️ Gerar long-tails de concorrentes
     * POST /api/seo-killer/strategies/longtail/competitors
     */
    public function generateLongTailsFromCompetitors(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['category_id']) || empty($data['search_query'])) {
                return ['error' => 'Informe category_id e search_query'];
            }

            $service = new \App\Services\AI\SEO\Strategies\LongTailGeneratorService($this->accountId);
            return $service->generateFromCompetitors(
                $data['category_id'],
                $data['search_query'],
                $data['limit'] ?? 20
            );
        });
    }

    /**
     * 🤖 Gerar long-tails com IA
     * POST /api/seo-killer/strategies/longtail/ai
     */
    public function generateLongTailsWithAI(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['base_keyword'])) {
                return ['error' => 'Informe base_keyword'];
            }

            $service = new \App\Services\AI\SEO\Strategies\LongTailGeneratorService($this->accountId);
            return $service->generateWithAI(
                $data['base_keyword'],
                $data['context'] ?? [],
                $data['limit'] ?? 15
            );
        });
    }

    /**
     * 📊 Analisar keyword long-tail
     * POST /api/seo-killer/strategies/longtail/analyze
     */
    public function analyzeLongTail(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['keyword'])) {
                return ['error' => 'Informe keyword'];
            }

            $service = new \App\Services\AI\SEO\Strategies\LongTailGeneratorService($this->accountId);
            return $service->analyzeLongTail($data['keyword']);
        });
    }

    /**
     * 💡 Sugerir long-tails faltantes
     * POST /api/seo-killer/strategies/longtail/missing
     */
    public function suggestMissingLongTails(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data)) {
                return ['error' => 'Informe dados do item'];
            }

            $service = new \App\Services\AI\SEO\Strategies\LongTailGeneratorService($this->accountId);
            return $service->suggestMissing($data);
        });
    }

    // ========================================================================
    // E10: COMPATIBILITY ENDPOINTS
    // ========================================================================

    /**
     * 🚗 Analisar compatibilidade de item
     * GET /api/seo-killer/strategies/compatibility/analyze/{itemId}
     */
    public function analyzeCompatibility(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $service = new \App\Services\AI\SEO\Strategies\CompatibilityService($this->accountId);
            return $service->analyzeCompatibility($itemId);
        });
    }

    /**
     * 🔄 Expandir compatibilidade
     * POST /api/seo-killer/strategies/compatibility/expand
     */
    public function expandCompatibility(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['models'])) {
                return ['error' => 'Informe models (array)'];
            }

            $service = new \App\Services\AI\SEO\Strategies\CompatibilityService($this->accountId);
            return $service->expandCompatibility($data['models']);
        });
    }

    /**
     * 🔍 Buscar compatibilidade do ML API
     * POST /api/seo-killer/strategies/compatibility/fetch
     */
    public function fetchCompatibilityFromML(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['category_id']) || empty($data['search_query'])) {
                return ['error' => 'Informe category_id e search_query'];
            }

            $service = new \App\Services\AI\SEO\Strategies\CompatibilityService($this->accountId);
            return $service->fetchFromMLApi($data['category_id'], $data['search_query']);
        });
    }

    /**
     * 📋 Gerar atributo COMPATIBLE_MODELS
     * POST /api/seo-killer/strategies/compatibility/attribute
     */
    public function generateCompatibleModelsAttribute(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['models'])) {
                return ['error' => 'Informe models (array)'];
            }

            $service = new \App\Services\AI\SEO\Strategies\CompatibilityService($this->accountId);
            return $service->generateCompatibleModelsAttribute($data['models']);
        });
    }

    /**
     * 💡 Sugerir por especificações
     * POST /api/seo-killer/strategies/compatibility/suggest-by-specs
     */
    public function suggestCompatibilityBySpecs(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['specs'])) {
                return ['error' => 'Informe specs'];
            }

            $service = new \App\Services\AI\SEO\Strategies\CompatibilityService($this->accountId);
            return $service->suggestBySpecs($data['specs']);
        });
    }

    /**
     * ✅ Validar compatibilidade
     * POST /api/seo-killer/strategies/compatibility/validate
     */
    public function validateCompatibility(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['models'])) {
                return ['error' => 'Informe models (array)'];
            }

            $service = new \App\Services\AI\SEO\Strategies\CompatibilityService($this->accountId);
            return $service->validateCompatibility(
                $data['models'],
                $data['category_id'] ?? null
            );
        });
    }

    /**
     * 📋 Obter todos os modelos
     * GET /api/seo-killer/strategies/compatibility/models
     * GET /api/seo-killer/strategies/compatibility/models/{brand}
     */
    public function getAllModels(?string $brand = null): void
    {
        $this->json(function () use ($brand) {
            $service = new \App\Services\AI\SEO\Strategies\CompatibilityService($this->accountId);
            return $service->getAllModels($brand);
        });
    }

    // ========================================================================
    // E11: FAQ ENDPOINTS
    // ========================================================================

    /**
     * ❓ Gerar FAQs
     * POST /api/seo-killer/strategies/faq/generate
     */
    public function generateFAQs(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data)) {
                return ['error' => 'Informe dados do produto'];
            }

            $service = new \App\Services\AI\SEO\Strategies\FAQOptimizerService($this->accountId);
            return $service->generateFAQs($data, $data['count'] ?? 5);
        });
    }

    /**
     * 📊 Minerar Keywords de Perguntas de Clientes
     * GET /api/seo-killer/questions/keywords/{itemId}
     */
    public function mineKeywordsFromQuestions(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $client = new \App\Services\MercadoLivreClient($this->accountId);

            // Fetch questions for this item
            $response = $client->get("/questions/search", ['item' => $itemId, 'limit' => 50]);
            $questions = $response['questions'] ?? [];

            if (empty($questions)) {
                return ['keywords' => [], 'faqs' => [], 'message' => 'Nenhuma pergunta encontrada'];
            }

            // Extract keywords from questions
            $allWords = [];
            $questionTexts = [];
            foreach ($questions as $q) {
                $text = $q['text'] ?? '';
                $questionTexts[] = $text;

                // Simple keyword extraction
                $words = preg_split('/[\s\-\/:,;.!?]+/', mb_strtolower($text));
                foreach ($words as $word) {
                    $word = trim($word);
                    if (mb_strlen($word) >= 4 && !in_array($word, ['como', 'para', 'esse', 'essa', 'qual', 'quais', 'pode', 'vocês', 'voces', 'olla', 'bom', 'boa'])) {
                        $allWords[$word] = ($allWords[$word] ?? 0) + 1;
                    }
                }
            }

            // Sort by frequency
            arsort($allWords);
            $topKeywords = array_slice($allWords, 0, 20, true);

            // Generate FAQ suggestions from top questions
            $faqService = new \App\Services\AI\SEO\Strategies\FAQOptimizerService($this->accountId);
            $faqs = [];

            // Use most frequent question patterns
            $seen = [];
            foreach (array_slice($questions, 0, 10) as $q) {
                $text = $q['text'] ?? '';
                $normalized = mb_strtolower(preg_replace('/[?!.]+/', '', $text));

                if (mb_strlen($normalized) < 10 || isset($seen[$normalized])) continue;
                $seen[$normalized] = true;

                $faqs[] = [
                    'question' => ucfirst(trim($text)) . '?',
                    'answer' => $q['answer']['text'] ?? 'Resposta pendente',
                    'source' => 'customer_question'
                ];
            }

            return [
                'keywords' => $topKeywords,
                'faqs' => $faqs,
                'total_questions' => count($questions),
                'recommendation' => 'Utilize estas keywords no título e descrição para melhorar ranqueamento'
            ];
        });
    }

    /**
     * 🤖 Gerar FAQs com IA
     * POST /api/seo-killer/strategies/faq/ai
     */
    public function generateFAQsWithAI(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data)) {
                return ['error' => 'Informe dados do produto'];
            }

            $service = new \App\Services\AI\SEO\Strategies\FAQOptimizerService($this->accountId);
            return $service->generateWithAI($data, $data['count'] ?? 5);
        });
    }

    /**
     * ✨ Otimizar FAQs existentes
     * POST /api/seo-killer/strategies/faq/optimize
     */
    public function optimizeFAQs(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['faqs']) || empty($data['keywords'])) {
                return ['error' => 'Informe faqs e keywords'];
            }

            $service = new \App\Services\AI\SEO\Strategies\FAQOptimizerService($this->accountId);
            return $service->optimizeFAQs($data['faqs'], $data['keywords']);
        });
    }

    /**
     * 📋 Gerar Schema.org para FAQs
     * POST /api/seo-killer/strategies/faq/schema
     */
    public function generateFAQSchema(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['faqs'])) {
                return ['error' => 'Informe faqs'];
            }

            $service = new \App\Services\AI\SEO\Strategies\FAQOptimizerService($this->accountId);
            return $service->generateSchema($data['faqs']);
        });
    }

    /**
     * 🖥️ Gerar HTML para FAQs
     * POST /api/seo-killer/strategies/faq/html
     */
    public function generateFAQHTML(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['faqs'])) {
                return ['error' => 'Informe faqs'];
            }

            $service = new \App\Services\AI\SEO\Strategies\FAQOptimizerService($this->accountId);
            return [
                'html' => $service->generateHTML($data['faqs'], $data['style'] ?? 'accordion')
            ];
        });
    }

    /**
     * 📝 Gerar texto de FAQ para descrição
     * POST /api/seo-killer/strategies/faq/description-text
     */
    public function generateFAQDescriptionText(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['faqs'])) {
                return ['error' => 'Informe faqs'];
            }

            $service = new \App\Services\AI\SEO\Strategies\FAQOptimizerService($this->accountId);
            return [
                'text' => $service->generateDescriptionText($data['faqs'])
            ];
        });
    }

    /**
     * ✅ Validar FAQs
     * POST /api/seo-killer/strategies/faq/validate
     */
    public function validateFAQs(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['faqs'])) {
                return ['error' => 'Informe faqs'];
            }

            $service = new \App\Services\AI\SEO\Strategies\FAQOptimizerService($this->accountId);
            return $service->validateFAQs($data['faqs']);
        });
    }

    /**
     * 💡 Sugerir FAQs para categoria
     * GET /api/seo-killer/strategies/faq/suggest/{categoryId}
     */
    public function suggestFAQsForCategory(string $categoryId): void
    {
        $this->json(function () use ($categoryId) {
            $service = new \App\Services\AI\SEO\Strategies\FAQOptimizerService($this->accountId);
            return $service->suggestForCategory($categoryId);
        });
    }

    // ========================================================================
    // E12: ENGINE (ORCHESTRATOR) ENDPOINTS
    // ========================================================================

    /**
     * 🎯 Análise completa do item (todas as estratégias)
     * GET /api/seo-killer/strategies/engine/analyze/{itemId}
     */
    public function engineAnalyzeItem(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $engine = new \App\Services\AI\SEO\Strategies\SEOStrategiesEngine($this->accountId);
            return $engine->analyzeItem($itemId);
        });
    }

    /**
     * 📊 Análise completa de dados (pré-publicação)
     * POST /api/seo-killer/strategies/engine/analyze
     */
    public function engineAnalyzeData(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data)) {
                return ['error' => 'Informe dados do item'];
            }

            $engine = new \App\Services\AI\SEO\Strategies\SEOStrategiesEngine($this->accountId);
            return $engine->analyzeItemData($data);
        });
    }

    /**
     * 🚀 Otimizar item automaticamente
     * POST /api/seo-killer/strategies/engine/optimize/{itemId}
     */
    public function engineOptimizeItem(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            $engine = new \App\Services\AI\SEO\Strategies\SEOStrategiesEngine($this->accountId);
            return $engine->optimizeItem($itemId, $data);
        });
    }

    /**
     * 📈 Dashboard de estratégias
     * GET /api/seo-killer/strategies/engine/dashboard
     * GET /api/seo-killer/strategies/engine/dashboard/{categoryId}
     */
    public function engineDashboard(?string $categoryId = null): void
    {
        $this->json(function () use ($categoryId) {
            $engine = new \App\Services\AI\SEO\Strategies\SEOStrategiesEngine($this->accountId);
            return $engine->getDashboard($categoryId);
        });
    }

    /**
     * 📊 Relatório de otimização
     * GET /api/seo-killer/strategies/engine/report/{itemId}
     */
    public function engineOptimizationReport(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $engine = new \App\Services\AI\SEO\Strategies\SEOStrategiesEngine($this->accountId);
            return $engine->getOptimizationReport($itemId);
        });
    }

    /**
     * ⚖️ Comparar dois itens
     * POST /api/seo-killer/strategies/engine/compare
     */
    public function engineCompareItems(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_id_1']) || empty($data['item_id_2'])) {
                return ['error' => 'Informe item_id_1 e item_id_2'];
            }

            $engine = new \App\Services\AI\SEO\Strategies\SEOStrategiesEngine($this->accountId);
            return $engine->compareItems($data['item_id_1'], $data['item_id_2']);
        });
    }

    /**
     * 📉 Monitorar keywords
     * POST /api/seo-killer/strategies/engine/monitor
     */
    public function engineMonitorKeywords(): void
    {
        $this->json(function () {
            $data = $this->getJsonInput();

            if (empty($data['category_id']) || empty($data['keywords'])) {
                return ['error' => 'Informe category_id e keywords'];
            }

            $engine = new \App\Services\AI\SEO\Strategies\SEOStrategiesEngine($this->accountId);
            return $engine->monitorKeywords($data['category_id'], $data['keywords']);
        });
    }

    // ========================================================================
    // 🎯 SEO STRATEGIES - Main Integration Endpoints
    // ========================================================================

    /**
     * Run full SEO strategies analysis
     * GET /api/seo-killer/strategies/analyze/{itemId}
     */
    public function runStrategiesAnalysis(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $engine = new \App\Services\AI\SEO\SEOKillerEngine($this->accountId);
            return [
                'success' => true,
                'analysis' => $engine->runStrategiesAnalysis($itemId),
            ];
        });
    }

    /**
     * Get SEO strategies score
     * GET /api/seo-killer/strategies/score/{itemId}
     */
    public function getStrategiesScore(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $engine = new \App\Services\AI\SEO\SEOKillerEngine($this->accountId);
            return [
                'success' => true,
                'data' => $engine->getStrategiesScore($itemId),
            ];
        });
    }

    /**
     * Optimize item with all 12 strategies
     * POST /api/seo-killer/strategies/optimize/{itemId}
     */
    public function optimizeWithStrategies(string $itemId): void
    {
        $this->json(function () use ($itemId) {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $engine = new \App\Services\AI\SEO\SEOKillerEngine($this->accountId);
            return [
                'success' => true,
                'optimization' => $engine->optimizeWithStrategies($itemId),
            ];
        });
    }

    /**
     * Batch analyze items with strategies
     * POST /api/seo-killer/strategies/batch
     */
    public function batchStrategiesAnalysis(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $itemIds = $data['item_ids'] ?? [];
            $limit = $data['limit'] ?? 10;

            if (empty($itemIds)) {
                return ['success' => false, 'error' => 'Informe item_ids'];
            }

            $engine = new \App\Services\AI\SEO\SEOKillerEngine($this->accountId);
            return [
                'success' => true,
                'batch_results' => $engine->batchStrategiesAnalysis($itemIds, $limit),
            ];
        });
    }

    /**
     * Get strategies dashboard
     * GET /api/seo-killer/strategies/dashboard
     */
    public function getStrategiesDashboard(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $engine = new \App\Services\AI\SEO\SEOKillerEngine($this->accountId);
            return [
                'success' => true,
                'dashboard' => $engine->getStrategiesDashboard(),
            ];
        });
    }

    /**
     * Get strategies cache stats
     * GET /api/seo-killer/strategies/cache/stats
     */
    public function getStrategiesCacheStats(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $cache = new \App\Services\AI\SEO\Strategies\SEOAnalysisCacheService($this->accountId);
            return [
                'success' => true,
                'stats' => $cache->getStats(),
                'distribution' => $cache->getScoreDistribution(),
            ];
        });
    }

    /**
     * Clear strategies cache
     * POST /api/seo-killer/strategies/cache/clear
     */
    public function clearStrategiesCache(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['success' => false, 'error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();
            $itemId = $data['item_id'] ?? null;

            $cache = new \App\Services\AI\SEO\Strategies\SEOAnalysisCacheService($this->accountId);

            if ($itemId) {
                $cache->invalidate($itemId);
                return ['success' => true, 'message' => "Cache invalidado para {$itemId}"];
            }

            $deleted = $cache->invalidateAll();
            return ['success' => true, 'message' => "Cache limpo", 'deleted' => $deleted];
        });
    }

    /**
     * Helper method to execute callable and return JSON response.
     * Overrides parent to also accept a callable that returns the data array.
     */
    protected function json(array|callable $dataOrCallback, int $status = 200): void
    {
        if (is_callable($dataOrCallback)) {
            try {
                $result = $dataOrCallback();

                http_response_code(200);
                header('Content-Type: application/json');
                echo json_encode($result);
            } catch (\Exception $e) {
                log_error('Erro no SEOKiller JSON handler', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => $e->getMessage()
                ]);
            }
            exit;
        }

        parent::json($dataOrCallback, $status);
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        return $this->request->json() ?? [];
    }

    /**
     * 🚀 Advanced SEO Maximizer
     * POST /api/seo-killer/advanced-maximize
     */
    public function advancedMaximizeSEO(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_id'])) {
                return ['error' => 'Informe item_id'];
            }

            $maximizer = new AdvancedSEOMaximizer($this->accountId);
            $result = $maximizer->maximizeItemSEO($data['item_id']);

            return $result;
        });
    }

    /**
     * 🔮 Predict Performance
     * POST /api/seo-killer/predict-performance
     */
    public function predictPerformance(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_id'])) {
                return ['error' => 'Informe item_id'];
            }

            // Get item data
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            $item = $client->get("/items/{$data['item_id']}");

            if (!$item || isset($item['error'])) {
                return ['error' => $item['error'] ?? 'Erro ao buscar item'];
            }

            $predictor = new SEOPerformancePredictor($this->accountId);
            $prediction = $predictor->predictPerformance($item);

            return $prediction;
        });
    }

    /**
     * 🤖 Intelligent Auto-Optimize
     * POST /api/seo-killer/intelligent-auto-optimize
     */
    public function intelligentAutoOptimize(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            $optimizer = new IntelligentAutoOptimizer($this->accountId);
            $result = $optimizer->intelligentAutoOptimize($data);

            return $result;
        });
    }

    /**
     * 📊 Advanced Keywords Analysis
     * POST /api/seo-killer/advanced-keywords
     */
    public function advancedKeywordsAnalysis(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_id'])) {
                return ['error' => 'Informe item_id'];
            }

            // Get item data
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            $item = $client->get("/items/{$data['item_id']}");

            if (!$item || isset($item['error'])) {
                return ['error' => $item['error'] ?? 'Erro ao buscar item'];
            }

            $maximizer = new AdvancedSEOMaximizer($this->accountId);
            $keywords = $maximizer->generateAdvancedKeywords($item);

            return [
                'success' => true,
                'item_id' => $data['item_id'],
                'keywords' => $keywords,
                'total_keywords' => array_sum(array_map('count', $keywords))
            ];
        });
    }

    /**
     * 🕵️ Advanced Competitor Analysis
     * POST /api/seo-killer/advanced-competitor-analysis
     */
    public function advancedCompetitorAnalysis(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $data = $this->getJsonInput();

            if (empty($data['item_id'])) {
                return ['error' => 'Informe item_id'];
            }

            // Get item data
            $client = new \App\Services\MercadoLivreClient($this->accountId);
            $item = $client->get("/items/{$data['item_id']}");

            if (!$item || isset($item['error'])) {
                return ['error' => $item['error'] ?? 'Erro ao buscar item'];
            }

            $maximizer = new AdvancedSEOMaximizer($this->accountId);
            $analysis = $maximizer->advancedCompetitorAnalysis($data['item_id'], $item);

            return [
                'success' => true,
                'item_id' => $data['item_id'],
                'analysis' => $analysis
            ];
        });
    }

    /**
     * 📈 Optimization Statistics
     * GET /api/seo-killer/optimization-stats
     */
    public function getOptimizationStats(): void
    {
        $this->json(function () {
            if (!$this->accountId) {
                return ['error' => 'Nenhuma conta conectada'];
            }

            $optimizer = new IntelligentAutoOptimizer($this->accountId);
            $stats = $optimizer->getOptimizationStats([
                'date_from' => $this->request->get('date_from'),
                'date_to' => $this->request->get('date_to'),
            ]);

            return [
                'success' => true,
                'stats' => $stats
            ];
        });
    }

    /**
     * Extract attribute value from ML item
     */
    private function extractAttribute(array $item, string $attributeId): ?string
    {
        $attributes = $item['attributes'] ?? [];

        foreach ($attributes as $attr) {
            if ($attr['id'] === $attributeId) {
                return $attr['value_name'] ?? $attr['value_id'] ?? null;
            }
        }

        return null;
    }
}
