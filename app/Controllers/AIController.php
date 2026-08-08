<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\AI\SEO\AIInsightsService;
use App\Services\AI\SEO\AIImageAnalyzer;
use App\Services\AI\SEO\AIPricingOptimizer;
use App\Services\AI\SEO\AIChatbotService;
use App\Services\AI\SEO\ImageKiller;
use App\Services\MercadoLivreClient;

/**
 * AIController - Endpoints para funcionalidades de IA powered by GPT-4
 *
 * Este controller integra 4 serviços de IA:
 * - AIInsightsService: Análises estratégicas e recomendações
 * - AIImageAnalyzer: Análise de qualidade de imagens
 * - AIPricingOptimizer: Otimização dinâmica de preços
 * - AIChatbotService: Assistente conversacional
 *
 * @package App\Controllers
 * @version 2.0.0
 */
class AIController
{
    private ?int $accountId = null;
    private Request $request;
    private ?AIInsightsService $insightsService = null;
    private ?AIImageAnalyzer $imageAnalyzer = null;
    private ?AIPricingOptimizer $pricingOptimizer = null;
    private ?AIChatbotService $chatbotService = null;
    private ?string $initializationError = null;

    public function __construct()
    {
        $this->request = new Request();
        // Get account ID from session helper (prioritizing active ML account)
        $this->accountId = \App\Helpers\SessionHelper::getActiveAccountId();

        // Fallback to generic session account_id if helper returns null (legacy support)
        if (!$this->accountId) {
            $this->accountId = $_SESSION['account_id'] ?? null;
        }

        if (!$this->accountId) {
            http_response_code(401);
            echo json_encode([
                'error' => 'Nenhuma conta Mercado Livre ativa',
                'success' => false,
                'code' => 'missing_ml_account',
            ]);
            exit;
        }

        try {
            // Initialize services
            $this->insightsService = new AIInsightsService($this->accountId);
            $this->imageAnalyzer = new AIImageAnalyzer($this->accountId);
            $this->pricingOptimizer = new AIPricingOptimizer($this->accountId);
            $this->chatbotService = new AIChatbotService($this->accountId);
        } catch (\Throwable $e) {
            $this->initializationError = $e->getMessage();
            log_error('Erro ao inicializar AIController', [
                'account_id' => $this->accountId ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =====================================================================
    // INSIGHTS ENDPOINTS - Análises Estratégicas com GPT-4
    // =====================================================================

    /**
     * POST /api/ai/insights/strategic
     * Gera insights estratégicos completos sobre a conta
     *
     * Body:
     * {
     *   "include_opportunities": true,
     *   "include_risks": true,
     *   "include_next_steps": true
     * }
     */
    public function generateStrategicInsights(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $options = [
                'include_opportunities' => $input['include_opportunities'] ?? true,
                'include_risks' => $input['include_risks'] ?? true,
                'include_next_steps' => $input['include_next_steps'] ?? true
            ];

            $insights = $this->insightsService->generateStrategicInsights($options);

            echo json_encode([
                'success' => true,
                'data' => $insights,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            $statusCode = ((int)$e->getCode() === 503) ? 503 : 500;
            http_response_code($statusCode);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/ai/insights/ab-tests
     * Sugere testes A/B baseados em performance atual
     *
     * Body:
     * {
     *   "focus_area": "all|title|description|price|images"
     * }
     */
    public function suggestABTests(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $focusArea = $input['focus_area'] ?? 'all';
            if (!is_string($focusArea)) {
                $focusArea = 'all';
            }

            $tests = $this->insightsService->suggestABTests($focusArea);

            echo json_encode([
                'success' => true,
                'data' => $tests,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            $statusCode = ((int)$e->getCode() === 503) ? 503 : 500;
            http_response_code($statusCode);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/ai/insights/trends?days=30
     * Analisa tendências em dados históricos
     */
    public function analyzeTrends(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $days = $this->request->getInt('days', 30);

            if ($days < 7 || $days > 90) {
                throw new \InvalidArgumentException('Days must be between 7 and 90');
            }

            $trends = $this->insightsService->analyzeTrends($days);

            echo json_encode([
                'success' => true,
                'data' => $trends,
                'period' => "{$days} days",
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            $statusCode = 500;
            if ($e instanceof \InvalidArgumentException) {
                $statusCode = 400;
            } elseif ((int)$e->getCode() === 503) {
                $statusCode = 503;
            }
            http_response_code($statusCode);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/ai/insights/explain-metric
     * Explica uma métrica em linguagem natural
     *
     * Body:
     * {
     *   "metric": "conversion_rate",
     *   "value": 2.5,
     *   "context": "monthly"
     * }
     */
    public function explainMetric(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['metric']) || !isset($input['value'])) {
                throw new \InvalidArgumentException('Metric and value are required');
            }

            $explanation = $this->insightsService->explainMetric(
                $input['metric'],
                $input['value'],
                $input['context'] ?? []
            );

            echo json_encode([
                'success' => true,
                'data' => $explanation,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            $statusCode = 500;
            if ($e instanceof \InvalidArgumentException) {
                $statusCode = 400;
            } elseif ((int)$e->getCode() === 503) {
                $statusCode = 503;
            }
            http_response_code($statusCode);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/ai/insights/recommendations?limit=10
     * Retorna recomendações priorizadas por impacto
     */
    public function getPrioritizedRecommendations(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $limit = $this->request->getInt('limit', 10);

            $recommendations = $this->insightsService->getPrioritizedRecommendations($limit);

            echo json_encode([
                'success' => true,
                'data' => $recommendations,
                'count' => count($recommendations),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            $statusCode = ((int)$e->getCode() === 503) ? 503 : 500;
            http_response_code($statusCode);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/ai/insights/sentiment
     * Analisa sentimento do mercado
     */
    public function analyzeMarketSentiment(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $sentiment = $this->insightsService->analyzeMarketSentiment();

            echo json_encode([
                'success' => true,
                'data' => $sentiment,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            $statusCode = ((int)$e->getCode() === 503) ? 503 : 500;
            http_response_code($statusCode);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =====================================================================
    // IMAGE ANALYZER ENDPOINTS - Análise de Imagens com Computer Vision
    // =====================================================================

    /**
     * POST /api/ai/images/analyze
     * Analisa todas as imagens de um produto
     *
     * Body:
     * {
     *   "item_id": "MLB123456789"
     * }
     */
    public function analyzeProductImages(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['item_id'])) {
                throw new \InvalidArgumentException('item_id is required');
            }

            $payload = $this->buildImageAnalysisPayload($input['item_id']);

            echo json_encode([
                'success' => true,
                'data' => $payload,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET /api/ai/images/analyze/{itemId}
     * Analisa imagens via URL com itemId na rota (usado pelo dashboard SEO Killer)
     */
    public function analyzeProductImagesById(string $itemId): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $payload = $this->buildImageAnalysisPayload($itemId);

            echo json_encode([
                'success' => true,
                'data' => $payload,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/images/compare-practices
     * Compara imagens com best practices do Mercado Livre
     *
     * Body:
     * {
     *   "image_url": "https://..."
     * }
     */
    public function compareWithBestPractices(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['image_url'])) {
                throw new \InvalidArgumentException('image_url is required');
            }

            $comparison = $this->imageAnalyzer->compareWithBestPractices($input['image_url']);

            echo json_encode([
                'success' => true,
                'data' => $comparison,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/images/suggest-order
     * Sugere ordem ótima para as imagens
     *
     * Body:
     * {
     *   "images": [
     *     {"url": "...", "current_position": 0},
     *     {"url": "...", "current_position": 1}
     *   ]
     * }
     */
    public function suggestOptimalOrder(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['images']) || !is_array($input['images'])) {
                throw new \InvalidArgumentException('images array is required');
            }

            $suggestion = $this->imageAnalyzer->suggestOptimalOrder($input['images']);

            echo json_encode([
                'success' => true,
                'data' => $suggestion,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/images/detect-similar
     * Detecta imagens similares/duplicadas
     *
     * Body:
     * {
     *   "images": [
     *     {"url": "...", "id": 1},
     *     {"url": "...", "id": 2}
     *   ]
     * }
     */
    public function detectSimilarImages(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['images']) || !is_array($input['images'])) {
                throw new \InvalidArgumentException('images array is required');
            }

            $duplicates = $this->imageAnalyzer->detectSimilarImages($input['images']);

            echo json_encode([
                'success' => true,
                'data' => $duplicates,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/images/reorder/{itemId}
     * Aplica ordem otimizada de imagens no anúncio do ML
     */
    public function reorderImages(string $itemId): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $order = $input['order'] ?? null;

            if (!is_array($order) || empty($order)) {
                throw new \InvalidArgumentException('order array is required');
            }

            // Reusar análise para mapear índices -> URLs
            $analysis = $this->imageAnalyzer->analyzeProductImages($itemId);
            $images = $analysis['images'] ?? [];

            if (empty($images)) {
                throw new \RuntimeException('No images available to reorder');
            }

            $urlsByIndex = [];
            foreach ($images as $idx => $img) {
                if (!empty($img['url'])) {
                    $urlsByIndex[$idx] = $img['url'];
                }
            }

            // Mapear URLs -> IDs reais do ML
            $mlClient = new MercadoLivreClient($this->accountId);
            $item = $mlClient->get("/items/{$itemId}");
            $pictures = $item['pictures'] ?? [];
            $idByUrl = [];
            foreach ($pictures as $pic) {
                if (!empty($pic['url'])) {
                    $idByUrl[$pic['url']] = $pic['id'];
                }
                if (!empty($pic['secure_url'])) {
                    $idByUrl[$pic['secure_url']] = $pic['id'];
                }
            }

            $orderedIds = [];
            foreach ($order as $idx) {
                if (!isset($urlsByIndex[$idx])) {
                    continue;
                }
                $url = $urlsByIndex[$idx];
                if (isset($idByUrl[$url])) {
                    $orderedIds[] = $idByUrl[$url];
                }
            }

            if (empty($orderedIds)) {
                throw new \RuntimeException('Unable to map requested order to Mercado Livre pictures');
            }

            $imageKiller = new ImageKiller($this->accountId);
            $result = $imageKiller->updateImages($itemId, [
                [
                    'type' => 'reorder',
                    'data' => $orderedIds,
                ],
            ]);

            echo json_encode([
                'success' => true,
                'data' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * DELETE /api/ai/images/remove
     * Remove uma imagem específica do anúncio no ML
     */
    public function removeImage(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $itemId = $input['item_id'] ?? null;
            $imageUrl = $input['image_url'] ?? null;

            if (!$itemId || !$imageUrl) {
                throw new \InvalidArgumentException('item_id and image_url are required');
            }

            $mlClient = new MercadoLivreClient($this->accountId);
            $item = $mlClient->get("/items/{$itemId}");
            $pictures = $item['pictures'] ?? [];

            $targetId = null;
            foreach ($pictures as $pic) {
                $urls = [];
                if (!empty($pic['url'])) {
                    $urls[] = $pic['url'];
                }
                if (!empty($pic['secure_url'])) {
                    $urls[] = $pic['secure_url'];
                }

                if (in_array($imageUrl, $urls, true)) {
                    $targetId = $pic['id'];
                    break;
                }
            }

            if (!$targetId) {
                throw new \RuntimeException('Imagem não encontrada no anúncio');
            }

            $imageKiller = new ImageKiller($this->accountId);
            $result = $imageKiller->updateImages($itemId, [
                [
                    'type' => 'remove',
                    'data' => $targetId,
                ],
            ]);

            echo json_encode([
                'success' => true,
                'data' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/images/upload
     * Faz upload de uma imagem e atualiza o anúncio (opcionalmente substituindo uma existente)
     */
    public function uploadImage(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $itemId = $this->request->post('item_id');

            if (!$itemId) {
                throw new \InvalidArgumentException('item_id is required');
            }

            if (!isset($_FILES['image'])) {
                throw new \InvalidArgumentException('image file is required');
            }

            $file = $_FILES['image'];

            $imageKiller = new ImageKiller($this->accountId);
            $uploadInfo = $imageKiller->uploadImage($itemId, $file);

            $changes = [];

            $replaceUrl = $this->request->post('replace_url');
            if ($replaceUrl) {
                $mlClient = new MercadoLivreClient($this->accountId);
                $item = $mlClient->get("/items/{$itemId}");
                $pictures = $item['pictures'] ?? [];

                $targetId = null;
                foreach ($pictures as $pic) {
                    $urls = [];
                    if (!empty($pic['url'])) {
                        $urls[] = $pic['url'];
                    }
                    if (!empty($pic['secure_url'])) {
                        $urls[] = $pic['secure_url'];
                    }

                    if (in_array($replaceUrl, $urls, true)) {
                        $targetId = $pic['id'];
                        break;
                    }
                }

                if ($targetId) {
                    $changes[] = [
                        'type' => 'remove',
                        'data' => $targetId,
                    ];
                }
            }

            $changes[] = [
                'type' => 'upload',
                'data' => ['url' => $uploadInfo['url']],
            ];

            $updateResult = $imageKiller->updateImages($itemId, $changes);

            echo json_encode([
                'success' => true,
                'url' => $uploadInfo['url'],
                'data' => $updateResult,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Monta o payload de análise de imagens no formato esperado pelo front
     */
    private function buildImageAnalysisPayload(string $itemId): array
    {
        $analysis = $this->imageAnalyzer->analyzeProductImages($itemId);

        if (isset($analysis['error'])) {
            return $analysis;
        }

        $images = $analysis['images'] ?? [];

        // Normalizar informações técnicas no nível da imagem
        $normalizedImages = [];
        foreach ($images as $img) {
            $technical = $img['technical'] ?? [];
            $normalizedImages[] = array_merge($img, [
                'width' => $technical['width'] ?? null,
                'height' => $technical['height'] ?? null,
                'format' => $technical['format'] ?? null,
                'size' => $technical['file_size'] ?? null,
            ]);
        }

        // Agregar problemas por tipo
        $issuesByType = [];
        foreach ($images as $img) {
            $imgIssues = $img['issues'] ?? [];
            foreach ($imgIssues as $issue) {
                $type = $issue['type'] ?? 'generic';
                if (!isset($issuesByType[$type])) {
                    $issuesByType[$type] = [
                        'title' => $this->mapIssueTitle($type),
                        'description' => $issue['description'] ?? ($issue['message'] ?? 'Issue detected'),
                        'severity' => $issue['severity'] ?? 'warning',
                        'affected_images' => 0,
                    ];
                }
                $issuesByType[$type]['affected_images']++;
            }
        }

        $issues = array_values($issuesByType);

        // Best practices (usar gaps da primeira imagem)
        $bestPractices = [];
        if (!empty($normalizedImages)) {
            try {
                $comparison = $this->imageAnalyzer->compareWithBestPractices($normalizedImages[0]['url']);
                $gaps = $comparison['gaps'] ?? [];
                foreach ($gaps as $gap) {
                    $practice = $gap['practice'] ?? [];
                    $bestPractices[] = [
                        'name' => $practice['name'] ?? 'Best practice',
                        'description' => sprintf(
                            'Expected %s, current: %s',
                            (string)($practice['value'] ?? ''),
                            (string)($gap['current'] ?? 'unknown')
                        ),
                        'passed' => false,
                    ];
                }
            } catch (\Throwable $e) {
                log_warning('Erro ao avaliar best practices de imagem', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Ordem ótima sugerida (retornar índices para o front)
        $optimalOrder = [];
        if (!empty($normalizedImages)) {
            try {
                $urls = array_map(fn($img) => $img['url'] ?? null, $normalizedImages);
                $urls = array_values(array_filter($urls));
                $suggestion = $this->imageAnalyzer->suggestOptimalOrder($urls);
                $suggestedUrls = $suggestion['suggested_order'] ?? [];

                foreach ($suggestedUrls as $url) {
                    $idx = array_search($url, $urls, true);
                    if ($idx !== false) {
                        $optimalOrder[] = $idx;
                    }
                }
            } catch (\Throwable $e) {
                log_warning('Erro ao sugerir ordem ótima de imagens', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Duplicatas (grupos de índices)
        $duplicates = [];
        if (!empty($normalizedImages)) {
            try {
                $urls = array_map(fn($img) => $img['url'] ?? null, $normalizedImages);
                $urls = array_values(array_filter($urls));
                $dups = $this->imageAnalyzer->detectSimilarImages($urls);
                $pairs = $dups['similar_pairs'] ?? [];

                foreach ($pairs as $pair) {
                    $url1 = $pair['image1'] ?? null;
                    $url2 = $pair['image2'] ?? null;
                    if (!$url1 || !$url2) {
                        continue;
                    }
                    $idx1 = array_search($url1, $urls, true);
                    $idx2 = array_search($url2, $urls, true);
                    if ($idx1 === false || $idx2 === false) {
                        continue;
                    }

                    $duplicates[] = [
                        'similarity' => $pair['similarity'] ?? 0,
                        'images' => [$idx1, $idx2],
                    ];
                }
            } catch (\Throwable $e) {
                log_warning('Erro ao detectar imagens similares', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'item_id' => $analysis['item_id'] ?? $itemId,
            'total_images' => $analysis['total_images'] ?? count($normalizedImages),
            'overall_score' => $analysis['overall_score'] ?? 0,
            'status' => $analysis['status'] ?? null,
            'images' => $normalizedImages,
            'issues' => $issues,
            'recommendations' => $analysis['recommendations'] ?? [],
            'best_practices' => $bestPractices,
            'optimal_order' => $optimalOrder,
            'duplicates' => $duplicates,
            'set_analysis' => $analysis['set_analysis'] ?? null,
            'meets_ml_standards' => $analysis['meets_ml_standards'] ?? null,
        ];
    }

    private function mapIssueTitle(string $type): string
    {
        switch ($type) {
            case 'low_resolution':
                return 'Baixa resolução';
            case 'aspect_ratio':
                return 'Proporção inadequada';
            case 'watermark':
                return 'Marca d\'água detectada';
            case 'text_overlay':
                return 'Texto na imagem';
            default:
                return ucfirst(str_replace('_', ' ', $type));
        }
    }

    // =====================================================================
    // PRICING OPTIMIZER ENDPOINTS - Otimização Dinâmica de Preços com ML
    // =====================================================================

    /**
     * POST /api/ai/pricing/suggest
     * Sugere preço ótimo baseado em múltiplos fatores
     *
     * Body:
     * {
     *   "item_id": "MLB123456789",
     *   "goal": "volume|profit|balanced"
     * }
     */
    public function suggestOptimalPrice(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['item_id'])) {
                throw new \InvalidArgumentException('item_id is required');
            }

            $options = [
                'goal' => $input['goal'] ?? 'balanced'
            ];

            $suggestion = $this->pricingOptimizer->suggestOptimalPrice(
                $input['item_id'],
                $options
            );

            echo json_encode([
                'success' => true,
                'data' => $suggestion,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/pricing/elasticity
     * Analisa elasticidade de preço do produto
     *
     * Body:
     * {
     *   "item_id": "MLB123456789"
     * }
     */
    public function analyzePriceElasticity(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['item_id'])) {
                throw new \InvalidArgumentException('item_id is required');
            }

            $analysis = $this->pricingOptimizer->analyzePriceElasticity($input['item_id']);

            echo json_encode([
                'success' => true,
                'data' => $analysis,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/pricing/optimize-margin
     * Otimiza margem de lucro
     *
     * Body:
     * {
     *   "cost": 100.00,
     *   "min_margin": 0.20,
     *   "max_price": 200.00
     * }
     */
    public function optimizeMargin(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['cost'])) {
                throw new \InvalidArgumentException('cost is required');
            }

            $constraints = [
                'min_margin' => $input['min_margin'] ?? 0.10,
                'max_price' => $input['max_price'] ?? null
            ];

            $optimization = $this->pricingOptimizer->optimizeMargin(
                (float)$input['cost'],
                $constraints
            );

            echo json_encode([
                'success' => true,
                'data' => $optimization,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/pricing/dynamic-rules
     * Cria regras de precificação dinâmica
     *
     * Body:
     * {
     *   "item_id": "MLB123456789",
     *   "rules": [
     *     {
     *       "condition": "competitor_price_below",
     *       "action": "decrease_percentage",
     *       "value": 0.05
     *     }
     *   ]
     * }
     */
    public function createDynamicPricingRules(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['item_id']) || !isset($input['rules'])) {
                throw new \InvalidArgumentException('item_id and rules are required');
            }

            $result = $this->pricingOptimizer->createDynamicPricingRules(
                $input['item_id'],
                $input['rules']
            );

            echo json_encode([
                'success' => true,
                'data' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET /api/ai/pricing/competitive/{itemId}
     * Analisa posicionamento competitivo de preços
     */
    public function analyzeCompetitivePricing(string $itemId): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $analysis = $this->pricingOptimizer->analyzeCompetitivePricing($itemId);

            echo json_encode([
                'success' => true,
                'data' => $analysis,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/pricing/forecast
     * Prevê receita em diferentes cenários de preço
     *
     * Body:
     * {
     *   "item_id": "MLB123456789",
     *   "price_points": [99.90, 109.90, 119.90, 129.90]
     * }
     */
    public function forecastRevenue(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['price_points']) || !is_array($input['price_points'])) {
                throw new \InvalidArgumentException('price_points array is required');
            }

            if (empty($input['item_id'])) {
                throw new \InvalidArgumentException('item_id is required');
            }

            $forecast = $this->pricingOptimizer->forecastRevenue($input['item_id'], $input['price_points']);

            echo json_encode([
                'success' => true,
                'data' => $forecast,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    // =====================================================================
    // CHATBOT ENDPOINTS - Assistente Conversacional com GPT-4
    // =====================================================================

    /**
     * POST /api/ai/chat
     * Conversa com o assistente de IA
     *
     * Body:
     * {
     *   "message": "Como melhorar minhas vendas?",
     *   "context": {
     *     "page": "dashboard",
     *     "feature": "seo-killer"
     *   }
     * }
     */
    public function chat(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['message'])) {
                throw new \InvalidArgumentException('message is required');
            }

            $response = $this->chatbotService->chat(
                $input['message'],
                $input['context'] ?? []
            );

            echo json_encode([
                'success' => true,
                'data' => $response,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/chat/explain-metric
     * Explicação rápida de uma métrica
     *
     * Body:
     * {
     *   "metric": "conversion_rate",
     *   "value": 2.5
     * }
     */
    public function explainMetricQuick(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['metric']) || !isset($input['value'])) {
                throw new \InvalidArgumentException('metric and value are required');
            }

            $response = $this->chatbotService->explainMetric(
                $input['metric'],
                $input['value']
            );

            echo json_encode([
                'success' => true,
                'data' => $response,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/ai/chat/help-feature
     * Ajuda com uma funcionalidade específica
     *
     * Body:
     * {
     *   "feature": "bulk-optimizer"
     * }
     */
    public function helpWithFeature(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['feature'])) {
                throw new \InvalidArgumentException('feature is required');
            }

            $response = $this->chatbotService->helpWithFeature($input['feature']);

            echo json_encode([
                'success' => true,
                'data' => $response,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * GET /api/ai/chat/suggest-actions
     * Sugere próximas ações baseadas no estado da conta
     */
    public function suggestNextActions(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $response = $this->chatbotService->suggestNextActions();

            echo json_encode([
                'success' => true,
                'data' => $response,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * DELETE /api/ai/chat/history
     * Limpa o histórico de conversação
     */
    public function clearHistory(): void
    {
        header('Content-Type: application/json');

        if ($this->initializationError) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'AI Service Unavailable: ' . $this->initializationError
            ]);
            return;
        }

        try {
            $this->chatbotService->clearHistory();

            echo json_encode([
                'success' => true,
                'message' => 'Conversation history cleared',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}
