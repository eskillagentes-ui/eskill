<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\SeoAnalyzerService;
use App\Services\KeywordResearchService;
use App\Services\TitleOptimizerService;
use App\Services\ListingBuilderService;
// use App\Services\PricingStrategyService;

/**
 * @deprecated This controller's functionality is consolidated in SEOKillerController.
 * API endpoints remain functional but new features should go in SEOKillerController.
 *
 * Controller de SEO e Otimização de Anúncios
 * 
 * Endpoints disponíveis:
 * - GET  /api/seo/analyze/{itemId}          - Análise SEO de um anúncio
 * - POST /api/seo/analyze                   - Análise SEO de dados de anúncio
 * - POST /api/seo/analyze/batch             - Análise em lote
 * - GET  /api/seo/keywords/{categoryId}     - Pesquisa de keywords
 * - POST /api/seo/keywords/volume           - Volume de busca de keyword
 * - POST /api/seo/title/optimize            - Otimizar título
 * - POST /api/seo/title/suggest             - Sugerir título
 * - POST /api/seo/listing/build             - Construir anúncio otimizado
 * - POST /api/seo/listing/publish           - Publicar anúncio
 * - GET  /api/seo/pricing/{categoryId}      - Análise de preços
 * - POST /api/seo/pricing/suggest           - Sugestão de preço
 * - POST /api/seo/pricing/calculate         - Calcular preço com margem
 */
class SEOToolsController
{
    private ?int $accountId = null;
    private Request $request;
    
    public function __construct()
    {
        $this->request = new Request();
        // Iniciar sessão se necessário
        $this->ensureSession();
        
        // Obter account_id da sessão ou query
        $this->accountId = \App\Helpers\SessionHelper::getActiveAccountId();
        if ($this->accountId) {
            $this->accountId = (int)$this->accountId;
        }
    }
    
    /**
     * Análise SEO de um anúncio existente
     * GET /api/seo/analyze/{itemId}
     */
    public function analyzeItem(string $itemId): void
    {
        $this->json(function() use ($itemId) {
            $analyzer = new SeoAnalyzerService($this->accountId);
            return $analyzer->analyzeItem($itemId);
        });
    }
    
    /**
     * Análise SEO de dados de anúncio (pré-publicação)
     * POST /api/seo/analyze
     */
    public function analyze(): void
    {
        $this->json(function() {
            $data = $this->getJsonInput();
            
            if (empty($data)) {
                return ['error' => 'Dados do anúncio são obrigatórios'];
            }
            
            $analyzer = new SeoAnalyzerService($this->accountId);
            return $analyzer->analyzeItemData($data);
        });
    }
    
    /**
     * Análise SEO em lote
     * POST /api/seo/analyze/batch
     */
    public function analyzeBatch(): void
    {
        $this->json(function() {
            $data = $this->getJsonInput();
            $itemIds = $data['item_ids'] ?? [];
            
            if (empty($itemIds)) {
                return ['error' => 'Lista de IDs é obrigatória'];
            }
            
            $analyzer = new SeoAnalyzerService($this->accountId);
            return $analyzer->analyzeBatch($itemIds);
        });
    }
    
    /**
     * Pesquisa de keywords para uma categoria
     * GET /api/seo/keywords/{categoryId}
     */
    public function keywords(string $categoryId): void
    {
        $this->json(function() use ($categoryId) {
            $baseKeyword = $this->request->get('keyword');
            
            $keywordService = new KeywordResearchService($this->accountId);
            return $keywordService->researchKeywords($categoryId, $baseKeyword);
        });
    }
    
    /**
     * Estima volume de busca de uma keyword
     * POST /api/seo/keywords/volume
     */
    public function keywordVolume(): void
    {
        $this->json(function() {
            $data = $this->getJsonInput();
            $keyword = $data['keyword'] ?? '';
            $categoryId = $data['category_id'] ?? null;
            
            if (empty($keyword)) {
                return ['error' => 'Keyword é obrigatória'];
            }
            
            $keywordService = new KeywordResearchService($this->accountId);
            return $keywordService->estimateSearchVolume($keyword, $categoryId);
        });
    }
    
    /**
     * Gera variações de keyword
     * GET /api/seo/keywords/variations
     */
    public function keywordVariations(): void
    {
        $this->json(function() {
            $keyword = $this->request->get('keyword', '') ?? '';
            
            if (empty($keyword)) {
                return ['error' => 'Keyword é obrigatória'];
            }
            
            $keywordService = new KeywordResearchService($this->accountId);
            return [
                'keyword' => $keyword,
                'variations' => $keywordService->generateKeywordVariations($keyword),
            ];
        });
    }
    
    /**
     * Obtém trends de uma categoria
     * GET /api/seo/trends/{categoryId}
     */
    public function trends(string $categoryId): void
    {
        $this->json(function() use ($categoryId) {
            $keywordService = new KeywordResearchService($this->accountId);
            return [
                'category_id' => $categoryId,
                'trends' => $keywordService->getCategoryTrends($categoryId),
            ];
        });
    }
    
    /**
     * Otimiza um título existente
     * POST /api/seo/title/optimize
     */
    public function optimizeTitle(): void
    {
        $this->json(function() {
            $data = $this->getJsonInput();
            $title = $data['title'] ?? '';
            
            if (empty($title)) {
                return ['error' => 'Título é obrigatório'];
            }
            
            $productInfo = [
                'brand' => $data['brand'] ?? null,
                'model' => $data['model'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'attributes' => $data['attributes'] ?? [],
            ];
            
            $optimizer = new TitleOptimizerService($this->accountId);
            return $optimizer->optimize($title, $productInfo);
        });
    }
    
    /**
     * Analisa um título
     * POST /api/seo/title/analyze
     */
    public function analyzeTitle(): void
    {
        $this->json(function() {
            $data = $this->getJsonInput();
            $title = $data['title'] ?? '';
            
            if (empty($title)) {
                return ['error' => 'Título é obrigatório'];
            }
            
            $optimizer = new TitleOptimizerService($this->accountId);
            return $optimizer->analyzeTitle($title);
        });
    }
    
    /**
     * Sugere título baseado em categoria e atributos
     * POST /api/seo/title/suggest
     */
    public function suggestTitle(): void
    {
        $this->json(function() {
            $data = $this->getJsonInput();
            $categoryId = $data['category_id'] ?? '';
            $attributes = $data['attributes'] ?? [];
            
            if (empty($categoryId)) {
                return ['error' => 'Categoria é obrigatória'];
            }
            
            $optimizer = new TitleOptimizerService($this->accountId);
            return $optimizer->suggestTitle($categoryId, $attributes);
        });
    }
    
    /**
     * Constrói anúncio otimizado
     * POST /api/seo/listing/build
     */
    public function buildListing(): void
    {
        $this->json(function() {
            $data = $this->getJsonInput();
            
            if (empty($data['category_id'])) {
                return ['error' => 'Categoria é obrigatória'];
            }
            
            $builder = new ListingBuilderService($this->accountId);
            return $builder->buildListing($data);
        });
    }
    
    /**
     * Gera descrição otimizada
     * POST /api/seo/listing/description
     */
    public function buildDescription(): void
    {
        $this->json(function() {
            $data = $this->getJsonInput();
            
            if (empty($data['title']) && empty($data['category_id'])) {
                return ['error' => 'Título ou categoria são obrigatórios'];
            }
            
            $builder = new ListingBuilderService($this->accountId);
            $keywordService = new KeywordResearchService($this->accountId);
            
            $keywords = [];
            if (!empty($data['category_id'])) {
                $keywords = $keywordService->researchKeywords($data['category_id'], $data['title'] ?? '');
            }
            
            $description = $builder->buildDescription($data, $keywords);
            
            return [
                'description' => $description,
                'character_count' => mb_strlen($description),
                'keywords_used' => array_slice($keywords['primary_keywords'] ?? [], 0, 5),
            ];
        });
    }
    
    /**
     * Publica anúncio no ML
     * POST /api/seo/listing/publish
     */
    public function publishListing(): void
    {
        $this->json(function() {
            $data = $this->getJsonInput();
            
            if (empty($data)) {
                return ['error' => 'Dados do anúncio são obrigatórios'];
            }
            
            if (!$this->accountId) {
                return ['error' => 'Conta ML não selecionada'];
            }
            
            $builder = new ListingBuilderService($this->accountId);
            return $builder->publishListing($data);
        });
    }
    
    /**
     * Duplica e otimiza um anúncio existente
     * GET /api/seo/listing/duplicate/{itemId}
     */
    public function duplicateListing(string $itemId): void
    {
        $this->json(function() use ($itemId) {
            if (!$this->accountId) {
                return ['error' => 'Conta ML não selecionada'];
            }
            
            $builder = new ListingBuilderService($this->accountId);
            return $builder->duplicateAndOptimize($itemId);
        });
    }
    
    /**
     * Análise de preços da concorrência
     * GET /api/seo/pricing/{categoryId}
     */
    public function pricing(string $categoryId): void
    {
        $this->json(function() use ($categoryId) {
            return ['error' => 'Serviço de precificação temporariamente indisponível'];
            /*
            $brand = $this->request->get('brand');
            $keyword = $this->request->get('keyword');
            
            $pricingService = new PricingStrategyService($this->accountId);
            return $pricingService->analyzeCompetitorPrices($categoryId, $brand, $keyword);
            */
        });
    }
    
    /**
     * Sugere preço baseado em estratégia
     * POST /api/seo/pricing/suggest
     */
    public function suggestPrice(): void
    {
        $this->json(function() {
            return ['error' => 'Serviço de precificação temporariamente indisponível'];
            /*
            $data = $this->getJsonInput();
            $categoryId = $data['category_id'] ?? '';
            $strategy = $data['strategy'] ?? 'competitive';
            
            if (empty($categoryId)) {
                return ['error' => 'Categoria é obrigatória'];
            }
            
            $pricingService = new PricingStrategyService($this->accountId);
            $analysis = $pricingService->analyzeCompetitorPrices(
                $categoryId,
                $data['brand'] ?? null,
                $data['keyword'] ?? null
            );
            
            if (isset($analysis['error'])) {
                return $analysis;
            }
            
            $options = [
                'cost' => $data['cost'] ?? null,
                'target_margin' => $data['target_margin'] ?? null,
            ];
            
            return $pricingService->suggestPrice($analysis, $strategy, $options);
            */
        });
    }
    
    /**
     * Compara preço com concorrentes
     * POST /api/seo/pricing/compare
     */
    public function comparePrice(): void
    {
        $this->json(function() {
            return ['error' => 'Serviço de precificação temporariamente indisponível'];
            /*
            $data = $this->getJsonInput();
            $price = (float)($data['price'] ?? 0);
            $categoryId = $data['category_id'] ?? '';
            
            if ($price <= 0 || empty($categoryId)) {
                return ['error' => 'Preço e categoria são obrigatórios'];
            }
            
            $pricingService = new PricingStrategyService($this->accountId);
            return $pricingService->compareWithCompetitors(
                $price,
                $categoryId,
                $data['brand'] ?? null
            );
            */
        });
    }
    
    /**
     * Calcula preço com margem desejada
     * POST /api/seo/pricing/calculate
     */
    public function calculatePrice(): void
    {
        $this->json(function() {
            return ['error' => 'Serviço de precificação temporariamente indisponível'];
            /*
            $data = $this->getJsonInput();
            $cost = (float)($data['cost'] ?? 0);
            $margin = (float)($data['margin'] ?? 0);
            
            if ($cost <= 0 || $margin <= 0) {
                return ['error' => 'Custo e margem são obrigatórios'];
            }
            
            $fees = $data['fees'] ?? [];
            
            $pricingService = new PricingStrategyService($this->accountId);
            return $pricingService->calculatePriceWithMargin($cost, $margin, $fees);
            */
        });
    }
    
    /**
     * Monitora preço de um item
     * GET /api/seo/pricing/track/{itemId}
     */
    public function trackPrice(string $itemId): void
    {
        $this->json(function() use ($itemId) {
            return ['error' => 'Serviço de precificação temporariamente indisponível'];
            /*
            $pricingService = new PricingStrategyService($this->accountId);
            return $pricingService->trackItemPrice($itemId);
            */
        });
    }
    
    /**
     * Dashboard SEO - visão geral
     * GET /api/seo/dashboard
     */
    public function dashboard(): void
    {
        $this->json(function() {
            // Implementar dashboard com métricas gerais
            return [
                'message' => 'Dashboard SEO',
                'features' => [
                    'keyword_research' => 'Pesquisa de palavras-chave',
                    'title_optimizer' => 'Otimização de títulos',
                    'seo_analyzer' => 'Análise SEO completa',
                    'listing_builder' => 'Construtor de anúncios',
                    'pricing_strategy' => 'Estratégia de preços',
                ],
                'endpoints' => [
                    '/api/seo/analyze/{itemId}' => 'Análise SEO de anúncio',
                    '/api/seo/keywords/{categoryId}' => 'Pesquisa de keywords',
                    '/api/seo/title/optimize' => 'Otimizar título',
                    '/api/seo/listing/build' => 'Construir anúncio',
                    '/api/seo/pricing/{categoryId}' => 'Análise de preços',
                ],
            ];
        });
    }
    
    /**
     * Obtém input JSON da requisição
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Retorna resposta JSON
     */
    private function json(callable $handler): void
    {
        if ($this->canSendHeaders()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        
        try {
            $result = $handler();
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            if ($this->canSendHeaders()) {
                http_response_code(500);
            }
            echo json_encode([
                'error' => true,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    private function canSendHeaders(): bool
    {
        return PHP_SAPI !== 'cli' && !headers_sent();
    }

    private function ensureSession(): void
    {
        if (PHP_SAPI === 'cli') {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
}
