<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SEO\SEOOptimizerService;
use App\Services\SEO\TechSheetService;
use App\Services\SEO\AIClient;

/**
 * @deprecated Prefer SEOKillerController for new product features.
 * Endpoints remain for AI helpers. Analyze moved to POST /api/seo/ai/analyze
 * to avoid shadowing the canonical SEOToolsController POST /api/seo/analyze.
 *
 * SEO API Controller - Endpoints REST para funcionalidades de SEO com IA
 */
class SEOApiController extends BaseController
{
    private ?SEOOptimizerService $seoService = null;
    private ?TechSheetService $techService = null;

    /**
     * Helper para respostas JSON com callback
     */
    private function jsonResponse(callable $handler): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $result = $handler();
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Obtém input JSON do request
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }

    /**
     * Lazy load do SEO Service
     */
    private function getSeoService(): SEOOptimizerService
    {
        if ($this->seoService === null) {
            $this->seoService = new SEOOptimizerService();
        }
        return $this->seoService;
    }

    /**
     * Lazy load do Tech Sheet Service
     */
    private function getTechService(): TechSheetService
    {
        if ($this->techService === null) {
            $this->techService = new TechSheetService($this->getActiveAccountId() ?? 0);
        }
        return $this->techService;
    }

    // ==================== ENDPOINTS ====================

    /**
     * GET /api/seo/status
     * Verifica status do serviço de SEO
     */
    public function status(): void
    {
        $this->jsonResponse(function () {
            $ai = new AIClient();

            return [
                'success' => true,
                'status' => 'operational',
                'ai' => [
                    'available' => $ai->isAvailable(),
                    'provider' => $ai->getProviderName(),
                ],
                'services' => [
                    'seo_optimizer' => class_exists(SEOOptimizerService::class),
                    'tech_sheet' => class_exists(TechSheetService::class),
                ],
                'timestamp' => date('c'),
            ];
        });
    }

    /**
     * POST /api/seo/analyze
     * Analisa SEO de um produto
     *
     * Body: { title, description?, category?, price?, attributes? }
     */
    public function analyze(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['title'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "title" é obrigatório'
                ];
            }

            $product = [
                'title' => $input['title'],
                'description' => $input['description'] ?? '',
                'category' => $input['category'] ?? '',
                'price' => $input['price'] ?? 0,
                'attributes' => $input['attributes'] ?? [],
            ];

            $result = $this->getSeoService()->analyze($product);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/optimize-title
     * Otimiza título de produto
     *
     * Body: { title, category?, brand?, keywords?, attributes? }
     */
    public function optimizeTitle(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['title'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "title" é obrigatório'
                ];
            }

            $context = [
                'category' => $input['category'] ?? '',
                'brand' => $input['brand'] ?? '',
                'keywords' => $input['keywords'] ?? [],
                'attributes' => $input['attributes'] ?? [],
            ];

            $result = $this->getSeoService()->optimizeTitle($input['title'], $context);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/generate-description
     * Gera descrição otimizada
     *
     * Body: { title, category?, brand?, features?, specifications?, description? }
     */
    public function generateDescription(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['title'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "title" é obrigatório'
                ];
            }

            $product = [
                'title' => $input['title'],
                'category' => $input['category'] ?? '',
                'brand' => $input['brand'] ?? '',
                'features' => $input['features'] ?? [],
                'specifications' => $input['specifications'] ?? [],
                'description' => $input['description'] ?? '',
            ];

            $result = $this->getSeoService()->generateDescription($product);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/keywords
     * Pesquisa de palavras-chave
     *
     * Body: { product, category?, competitors? }
     */
    public function researchKeywords(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['product'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "product" é obrigatório'
                ];
            }

            $context = [
                'category' => $input['category'] ?? '',
                'competitors' => $input['competitors'] ?? [],
            ];

            $result = $this->getSeoService()->researchKeywords($input['product'], $context);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/competitors
     * Análise de concorrentes
     *
     * Body: { title, category?, price?, competitors? }
     */
    public function analyzeCompetitors(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['title'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "title" é obrigatório'
                ];
            }

            $product = [
                'title' => $input['title'],
                'category' => $input['category'] ?? '',
                'price' => $input['price'] ?? 0,
            ];

            $competitors = $input['competitors'] ?? [];

            $result = $this->getSeoService()->analyzeCompetitors($product, $competitors);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/optimize
     * Otimização completa de produto
     *
     * Body: { title, description?, category?, brand?, price?, attributes? }
     */
    public function optimizeProduct(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['title'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "title" é obrigatório'
                ];
            }

            $product = [
                'title' => $input['title'],
                'description' => $input['description'] ?? '',
                'category' => $input['category'] ?? '',
                'brand' => $input['brand'] ?? '',
                'price' => $input['price'] ?? 0,
                'attributes' => $input['attributes'] ?? [],
            ];

            $result = $this->getSeoService()->optimizeProduct($product);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    // ==================== TECH SHEET ENDPOINTS ====================

    /**
     * POST /api/seo/tech-sheet/generate
     * Gera ficha técnica completa
     *
     * Body: { title, category?, brand?, current_attributes? }
     */
    public function generateTechSheet(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['title'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "title" é obrigatório'
                ];
            }

            $product = [
                'title' => $input['title'],
                'category' => $input['category'] ?? '',
                'brand' => $input['brand'] ?? '',
                'current_attributes' => $input['current_attributes'] ?? [],
            ];

            $result = $this->getTechService()->generate($product);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/tech-sheet/extract
     * Extrai atributos do título
     *
     * Body: { title, category? }
     */
    public function extractFromTitle(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['title'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "title" é obrigatório'
                ];
            }

            $category = $input['category'] ?? '';

            $result = $this->getTechService()->extractFromTitle($input['title'], $category);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/tech-sheet/complete
     * Completa ficha técnica parcial
     *
     * Body: { title, category?, current_attributes }
     */
    public function completeTechSheet(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['title'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "title" é obrigatório'
                ];
            }

            $product = [
                'title' => $input['title'],
                'category' => $input['category'] ?? '',
            ];

            $attributes = $input['current_attributes'] ?? [];

            $result = $this->getTechService()->complete($product, $attributes);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/tech-sheet/validate
     * Valida ficha técnica
     *
     * Body: { attributes, category? }
     */
    public function validateTechSheet(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['attributes'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "attributes" é obrigatório'
                ];
            }

            $category = $input['category'] ?? '';

            $result = $this->getTechService()->validate($input['attributes'], $category);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/tech-sheet/suggest
     * Sugere atributos faltantes
     *
     * Body: { title, category?, current_attributes? }
     */
    public function suggestAttributes(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();

            if (empty($input['title'])) {
                http_response_code(422);
                return [
                    'success' => false,
                    'error' => 'Campo "title" é obrigatório'
                ];
            }

            $product = [
                'title' => $input['title'],
                'category' => $input['category'] ?? '',
            ];

            $currentAttributes = $input['current_attributes'] ?? [];

            $result = $this->getTechService()->suggestAttributes($product['category'], $product['title']);

            return [
                'success' => $result['success'] ?? false,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/analyze-product
     * Análise SEO completa de produto (chamado pelo dashboard JS)
     */
    public function analyzeProduct(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();
            $productData = $input['product'] ?? $input;

            if (empty($productData['title']) && empty($productData['id'])) {
                http_response_code(422);
                return ['success' => false, 'error' => 'Produto requer title ou id'];
            }

            $product = [
                'title' => $productData['title'] ?? '',
                'description' => $productData['description'] ?? '',
                'category' => $productData['category'] ?? '',
                'keywords' => $productData['keywords'] ?? [],
            ];

            $result = $this->getSeoService()->analyze($product);

            return [
                'success' => $result['success'] ?? true,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/analyze-keyword-gaps
     * Análise de lacunas de keywords vs concorrentes
     */
    public function analyzeKeywordGaps(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();
            $productId = $input['product_id'] ?? '';
            $context = $input['context'] ?? [];

            if (empty($productId)) {
                http_response_code(422);
                return ['success' => false, 'error' => 'product_id é obrigatório'];
            }

            $product = ['title' => $productId, 'category' => ''];
            $result = $this->getSeoService()->analyzeCompetitors($product);

            return [
                'success' => $result['success'] ?? true,
                'data' => array_merge($result, [
                    'gap_analysis' => $result['gap_analysis'] ?? [
                        'gap_severity' => ['critical' => [], 'moderate' => []],
                    ],
                    'long_tail_opportunities' => $result['long_tail_opportunities'] ?? [],
                ]),
            ];
        });
    }

    /**
     * POST /api/seo/analyze-semantic
     * Análise semântica de texto/título
     */
    public function analyzeSemantic(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();
            $product = $input['product'] ?? [];

            $analysisProduct = [
                'title' => $product['title'] ?? $input['keyword'] ?? '',
                'description' => $product['description'] ?? '',
                'category' => $product['category'] ?? '',
            ];

            $result = $this->getSeoService()->analyze($analysisProduct);

            return [
                'success' => $result['success'] ?? true,
                'data' => $result,
            ];
        });
    }

    /**
     * POST /api/seo/optimize-model-attribute
     * Otimização do atributo MODEL do produto
     */
    public function optimizeModelAttribute(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();
            $product = $input['product'] ?? [];
            $action = $input['action'] ?? 'extract';
            $currentModel = $input['current_model'] ?? '';

            $techProduct = [
                'title' => $product['title'] ?? '',
                'category' => $product['category'] ?? '',
                'brand' => $product['brand'] ?? '',
            ];

            $result = $this->getTechService()->suggestAttributes($techProduct['category'], $techProduct['title']);

            return [
                'success' => $result['success'] ?? true,
                'data' => array_merge($result, [
                    'action' => $action,
                    'current_model' => $currentModel,
                    'suggestions' => $result['suggestions'] ?? [],
                    'confidence_score' => $result['confidence_score'] ?? 0,
                ]),
            ];
        });
    }

    /**
     * POST /api/seo/monitor-optimization
     * Monitoramento de otimizações aplicadas
     */
    public function monitorOptimization(): void
    {
        $this->jsonResponse(function () {
            $input = $this->getJsonInput();
            $productIds = $input['product_ids'] ?? [];
            $timeRange = $input['time_range'] ?? '7d';

            if (empty($productIds)) {
                http_response_code(422);
                return ['success' => false, 'error' => 'product_ids é obrigatório'];
            }

            return [
                'success' => true,
                'data' => [
                    'products' => array_map(fn($id) => [
                        'id' => $id,
                        'status' => 'monitored',
                        'changes' => [],
                        'metrics' => ['views' => 0, 'clicks' => 0, 'conversion' => 0],
                    ], $productIds),
                    'time_range' => $timeRange,
                    'summary' => [
                        'total_products' => count($productIds),
                        'optimized' => 0,
                        'pending' => count($productIds),
                    ],
                ],
            ];
        });
    }
}
