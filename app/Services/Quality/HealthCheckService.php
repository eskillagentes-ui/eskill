<?php

declare(strict_types=1);

namespace App\Services\Quality;

use App\Services\MercadoLivreClient;
use App\Services\CategoryService;
use App\Services\Shipping\ShippingOptimizerService;
use App\Services\Shipping\DimensionCalculatorService;

/**
 * Health Check Service — validação local + sinais oficiais ML de exposição.
 *
 * Camadas de score (não misturar):
 * - api_health (official_ml): GET /item/{id}/performance (substitui /items/{id}/health desde fev/2025)
 * - health.score (internal_quality): heurística local pré/pós publicação
 *
 * Para fila operacional de busca/moderação use ListingSearchVisibilityService.
 */
class HealthCheckService
{
    private MercadoLivreClient $client;
    private CategoryService $categoryService;

    // Status de saúde possíveis
    public const HEALTH_STATUS = [
        'healthy' => 'Saudável - sem problemas detectados',
        'warning' => 'Atenção - melhorias recomendadas',
        'critical' => 'Crítico - ação imediata necessária',
        'unknown' => 'Desconhecido - não foi possível avaliar',
    ];

    // Categorias de problemas
    public const ISSUE_CATEGORIES = [
        'visibility' => 'Visibilidade',
        'quality' => 'Qualidade',
        'compliance' => 'Conformidade',
        'catalog' => 'Catálogo',
        'attributes' => 'Atributos',
        'images' => 'Imagens',
        'description' => 'Descrição',
        'pricing' => 'Preços',
        'shipping' => 'Envio',
    ];

    // Severidade dos problemas
    public const SEVERITY = [
        'critical' => 3,
        'high' => 2,
        'medium' => 1,
        'low' => 0,
    ];

    public function __construct(?int $accountId = null)
    {
        $this->client = new MercadoLivreClient($accountId);
        $this->categoryService = new CategoryService($accountId);
    }

    /**
     * Verifica a saúde completa de um anúncio
     */
    public function checkItemHealth(string $itemId): array
    {
        try {
            $item = $this->client->get("/items/{$itemId}");
        } catch (\Exception $e) {
            log_error('Falha ao buscar item para health check', [
                'service' => 'HealthCheckService',
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        if (isset($item['error'])) {
            return [
                'success' => false,
                'error' => $item['message'] ?? 'Item não encontrado',
            ];
        }

        // Buscar health oficial da API (se disponível)
        $apiHealth = $this->getApiHealth($itemId);

        // Análise customizada
        $customHealth = $this->analyzeItemHealth($item);

        // Combinar resultados
        return [
            'success' => true,
            'item_id' => $itemId,
            'title' => $item['title'] ?? '',
            'status' => $item['status'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s'),
            'health' => [
                'status' => $this->determineOverallHealth($customHealth['issues']),
                'score' => $customHealth['health_score'],
                'api_health' => $apiHealth,
            ],
            'issues' => $customHealth['issues'],
            'recommendations' => $customHealth['recommendations'],
            'opportunities' => $customHealth['opportunities'],
            'summary' => $this->generateHealthSummary($customHealth),
        ];
    }

    /**
     * Sinais oficiais de exposição/qualidade via /item/{id}/performance.
     *
     * @return array{available: bool, source?: string, status?: string, level_wording?: string, issues?: list<array<string, mixed>>, recommendations?: list<string>, score?: int|null, calculated_at?: mixed}
     */
    private function getApiHealth(string $itemId): array
    {
        try {
            $health = $this->client->getItemHealth($itemId);

            if (!isset($health['error'])) {
                return [
                    'available' => true,
                    'source' => 'official_ml_performance',
                    'status' => $health['status'] ?? 'unknown',
                    'level_wording' => $health['level_wording'] ?? '',
                    'issues' => $health['issues'] ?? [],
                    'recommendations' => $health['recommendations'] ?? [],
                    'score' => $health['health_score'] ?? null,
                    'calculated_at' => $health['calculated_at'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            log_error('Falha ao obter performance ML no health check', [
                'service' => 'HealthCheckService',
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
        }

        return ['available' => false, 'source' => 'official_ml_performance'];
    }

    /**
     * Análise customizada de saúde do item
     */
    private function analyzeItemHealth(array $item): array
    {
        $issues = [];
        $recommendations = [];
        $opportunities = [];
        $scores = [];

        // 1. VERIFICAÇÃO DE CATÁLOGO
        $catalogCheck = $this->checkCatalogIssues($item);
        $issues = array_merge($issues, $catalogCheck['issues']);
        $recommendations = array_merge($recommendations, $catalogCheck['recommendations']);
        $scores[] = $catalogCheck['score'];

        // 2. VERIFICAÇÃO DE ATRIBUTOS
        $attributesCheck = $this->checkAttributeIssues($item);
        $issues = array_merge($issues, $attributesCheck['issues']);
        $recommendations = array_merge($recommendations, $attributesCheck['recommendations']);
        $scores[] = $attributesCheck['score'];

        // 3. VERIFICAÇÃO DE IMAGENS
        $imagesCheck = $this->checkImageIssues($item);
        $issues = array_merge($issues, $imagesCheck['issues']);
        $recommendations = array_merge($recommendations, $imagesCheck['recommendations']);
        $scores[] = $imagesCheck['score'];

        // 4. VERIFICAÇÃO DE DESCRIÇÃO
        $descriptionCheck = $this->checkDescriptionIssues($item);
        $issues = array_merge($issues, $descriptionCheck['issues']);
        $recommendations = array_merge($recommendations, $descriptionCheck['recommendations']);
        $scores[] = $descriptionCheck['score'];

        // 5. VERIFICAÇÃO DE PREÇO
        $pricingCheck = $this->checkPricingIssues($item);
        $issues = array_merge($issues, $pricingCheck['issues']);
        $recommendations = array_merge($recommendations, $pricingCheck['recommendations']);
        $scores[] = $pricingCheck['score'];

        // 6. VERIFICAÇÃO DE SHIPPING
        $shippingCheck = $this->checkShippingIssues($item);
        $issues = array_merge($issues, $shippingCheck['issues']);
        $recommendations = array_merge($recommendations, $shippingCheck['recommendations']);
        $opportunities = array_merge($opportunities, $shippingCheck['opportunities']);
        $scores[] = $shippingCheck['score'];

        // 7. VERIFICAÇÃO DE STATUS
        $statusCheck = $this->checkStatusIssues($item);
        $issues = array_merge($issues, $statusCheck['issues']);
        $recommendations = array_merge($recommendations, $statusCheck['recommendations']);

        // Calcular score geral
        $healthScore = !empty($scores) ? round(array_sum($scores) / count($scores), 1) : 0;

        return [
            'health_score' => $healthScore,
            'issues' => $issues,
            'recommendations' => $recommendations,
            'opportunities' => $opportunities,
        ];
    }

    /**
     * Verifica problemas relacionados ao catálogo
     */
    private function checkCatalogIssues(array $item): array
    {
        $issues = [];
        $recommendations = [];
        $score = 100;

        $catalogProductId = $item['catalog_product_id'] ?? null;
        $categoryId = $item['category_id'] ?? null;

        // Verificar se está no catálogo
        if (empty($catalogProductId)) {
            $score -= 15;
            $issues[] = [
                'category' => 'catalog',
                'severity' => 'medium',
                'title' => 'Anúncio não vinculado ao catálogo',
                'description' => 'Anúncios no catálogo têm melhor visibilidade e conversão',
                'impact' => 'Menor visibilidade nas buscas',
            ];
            $recommendations[] = [
                'category' => 'catalog',
                'priority' => 'high',
                'action' => 'Vincular ao catálogo',
                'description' => 'Busque o produto no catálogo do ML e vincule seu anúncio',
            ];
        }

        return [
            'score' => max(0, $score),
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Verifica problemas com atributos
     */
    private function checkAttributeIssues(array $item): array
    {
        $issues = [];
        $recommendations = [];
        $score = 100;

        $attributes = $item['attributes'] ?? [];
        $categoryId = $item['category_id'] ?? '';

        // Buscar atributos obrigatórios da categoria
        $categoryAttributes = $this->categoryService->getCategoryAttributes($categoryId);
        $requiredAttributes = array_filter(
            $categoryAttributes,
            fn($attr) =>
            isset($attr['tags']['required']) && $attr['tags']['required']
        );

        // Verificar atributos obrigatórios faltantes
        $itemAttributeIds = array_column($attributes, 'id');
        $missingRequired = [];

        foreach ($requiredAttributes as $reqAttr) {
            if (!in_array($reqAttr['id'], $itemAttributeIds)) {
                $missingRequired[] = $reqAttr['name'];
            }
        }

        if (!empty($missingRequired)) {
            $count = count($missingRequired);
            $score -= ($count * 10);

            $issues[] = [
                'category' => 'attributes',
                'severity' => 'high',
                'title' => "Faltam {$count} atributos obrigatórios",
                'description' => 'Atributos: ' . implode(', ', array_slice($missingRequired, 0, 3)),
                'impact' => 'Anúncio pode ser pausado ou ter visibilidade reduzida',
            ];

            $recommendations[] = [
                'category' => 'attributes',
                'priority' => 'critical',
                'action' => 'Completar atributos obrigatórios',
                'description' => 'Adicione os atributos: ' . implode(', ', $missingRequired),
            ];
        }

        // Verificar BRAND (marca)
        $hasBrand = false;
        foreach ($attributes as $attr) {
            if ($attr['id'] === 'BRAND') {
                $hasBrand = true;
                break;
            }
        }

        if (!$hasBrand) {
            $score -= 8;
            $issues[] = [
                'category' => 'attributes',
                'severity' => 'medium',
                'title' => 'Marca (BRAND) não informada',
                'description' => 'A marca é importante para SEO e credibilidade',
                'impact' => 'Menor confiança e visibilidade',
            ];
        }

        // Verificar GTIN (EAN/UPC)
        $hasGtin = false;
        foreach ($attributes as $attr) {
            if ($attr['id'] === 'GTIN') {
                $hasGtin = true;
                break;
            }
        }

        if (!$hasGtin) {
            $score -= 5;
            $recommendations[] = [
                'category' => 'attributes',
                'priority' => 'medium',
                'action' => 'Adicionar GTIN (código de barras)',
                'description' => 'EAN/UPC ajuda na integração com catálogo',
            ];
        }

        return [
            'score' => max(0, $score),
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Verifica problemas com imagens
     */
    private function checkImageIssues(array $item): array
    {
        $issues = [];
        $recommendations = [];
        $score = 100;

        $pictures = $item['pictures'] ?? [];
        $pictureCount = count($pictures);

        // Verificar quantidade mínima
        if ($pictureCount < 6) {
            $score -= 15;
            $issues[] = [
                'category' => 'images',
                'severity' => 'high',
                'title' => "Apenas {$pictureCount} imagens (mínimo recomendado: 6)",
                'description' => 'Anúncios com mais imagens têm melhor conversão',
                'impact' => 'Menor taxa de conversão',
            ];
            $recommendations[] = [
                'category' => 'images',
                'priority' => 'high',
                'action' => 'Adicionar mais imagens',
                'description' => 'Adicione pelo menos ' . (6 - $pictureCount) . ' imagens de qualidade',
            ];
        }

        if ($pictureCount === 0) {
            $score -= 50;
            $issues[] = [
                'category' => 'images',
                'severity' => 'critical',
                'title' => 'Sem imagens',
                'description' => 'Anúncio sem imagens não será exibido nas buscas',
                'impact' => 'Visibilidade zero',
            ];
        }

        // Verificar qualidade das imagens (resolução)
        $lowQualityImages = 0;
        foreach ($pictures as $picture) {
            $size = $picture['size'] ?? '';
            if (!empty($size)) {
                list($width, $height) = explode('x', $size);
                if ($width < 800 || $height < 800) {
                    $lowQualityImages++;
                }
            }
        }

        if ($lowQualityImages > 0) {
            $score -= 10;
            $recommendations[] = [
                'category' => 'images',
                'priority' => 'medium',
                'action' => 'Melhorar qualidade das imagens',
                'description' => "{$lowQualityImages} imagens com resolução baixa (recomendado: 1200x1200+)",
            ];
        }

        return [
            'score' => max(0, $score),
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Verifica problemas com descrição
     */
    private function checkDescriptionIssues(array $item): array
    {
        $issues = [];
        $recommendations = [];
        $score = 100;

        // Nota: descrição completa requer chamada separada
        // Por hora, verificamos apenas se existe

        // Verificar se tem video
        $hasVideo = !empty($item['video_id']);

        if (!$hasVideo) {
            $recommendations[] = [
                'category' => 'description',
                'priority' => 'low',
                'action' => 'Adicionar vídeo do produto',
                'description' => 'Anúncios com vídeo têm até 30% mais conversão',
            ];
        }

        return [
            'score' => $score,
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Verifica problemas com preços
     */
    private function checkPricingIssues(array $item): array
    {
        $issues = [];
        $recommendations = [];
        $score = 100;

        $price = $item['price'] ?? 0;
        $originalPrice = $item['original_price'] ?? null;

        if ($price <= 0) {
            $score -= 100;
            $issues[] = [
                'category' => 'pricing',
                'severity' => 'critical',
                'title' => 'Preço inválido ou zero',
                'description' => 'O anúncio não pode ser publicado sem preço válido',
                'impact' => 'Anúncio será pausado',
            ];
        }

        // Verificar se tem preço promocional
        if ($originalPrice > $price) {
            // Isso é bom, não reduz score
        } else if ($originalPrice !== null && $originalPrice <= $price) {
            $recommendations[] = [
                'category' => 'pricing',
                'priority' => 'low',
                'action' => 'Revisar preço original',
                'description' => 'O preço original deve ser maior que o preço de venda',
            ];
        }

        return [
            'score' => max(0, $score),
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Verifica problemas com shipping
     */
    private function checkShippingIssues(array $item): array
    {
        $issues = [];
        $recommendations = [];
        $opportunities = [];
        $score = 100;

        $shipping = $item['shipping'] ?? [];
        $freeShipping = $shipping['free_shipping'] ?? false;
        $mode = $shipping['mode'] ?? '';
        $logisticType = $shipping['logistic_type'] ?? '';
        $dimensions = $shipping['dimensions'] ?? null;

        // INTEGRAR COM SHIPPING OPTIMIZER
        try {
            $optimizer = new \App\Services\Shipping\ShippingOptimizerService();
            $itemId = $item['id'] ?? null;

            if ($itemId) {
                // Obter análise completa de shipping do novo serviço
                $optimization = $optimizer->optimizeShipping($itemId);

                if ($optimization['success']) {
                    // Usar issues detectados pelo optimizer
                    $shippingIssues = $optimization['current_shipping']['issues'] ?? [];

                    foreach ($shippingIssues as $issue) {
                        $score -= $this->getScoreDeduction($issue['severity']);

                        $issues[] = [
                            'category' => 'shipping',
                            'severity' => $issue['severity'],
                            'title' => $issue['issue'],
                            'description' => $issue['impact'],
                            'impact' => $issue['solution'],
                        ];
                    }

                    // Usar recomendação do optimizer
                    $recommendation = $optimization['recommendation'] ?? [];
                    if (!empty($recommendation['next_steps'])) {
                        foreach ($recommendation['next_steps'] as $step) {
                            $recommendations[] = [
                                'category' => 'shipping',
                                'priority' => $step['priority'],
                                'action' => $step['step'],
                                'description' => $step['description'],
                            ];
                        }
                    }

                    // Opportunities baseadas em aumento de conversão
                    if (!empty($recommendation['estimated_conversion_increase'])) {
                        $conversionIncrease = $recommendation['estimated_conversion_increase'];
                        if ($conversionIncrease !== '+0%') {
                            $opportunities[] = [
                                'category' => 'shipping',
                                'title' => 'Otimizar estratégia de envio',
                                'description' => 'Migrar para ' . ($recommendation['recommended_mode'] ?? 'melhor opção'),
                                'potential_impact' => 'Aumento estimado: ' . $conversionIncrease,
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback para análise básica se integração falhar
        }

        // ANÁLISE BÁSICA (mantida como fallback)
        if ($mode === 'not_specified' || empty($mode)) {
            $score -= 20;
            $issues[] = [
                'category' => 'shipping',
                'severity' => 'high',
                'title' => 'Modo de envio não especificado',
                'description' => 'Configure as opções de envio do anúncio',
                'impact' => 'Anúncio pode ter visibilidade reduzida',
            ];
        }

        // Verificar frete grátis
        if (!$freeShipping && $logisticType !== 'fulfillment' && $logisticType !== 'flex') {
            if (empty($issues)) { // Só adiciona se não foi adicionado pelo optimizer
                $score -= 10;
                $opportunities[] = [
                    'category' => 'shipping',
                    'title' => 'Ativar frete grátis',
                    'description' => 'Frete grátis aumenta visibilidade e conversão em até 40%',
                    'potential_impact' => '+40% conversão',
                ];
            }
        }

        // Verificar Full
        if ($logisticType !== 'fulfillment' && empty($opportunities)) {
            $opportunities[] = [
                'category' => 'shipping',
                'title' => 'Considerar Mercado Envios Full',
                'description' => 'Full oferece melhor ranking, entrega mais rápida e selo de confiança',
                'potential_impact' => 'Melhor posicionamento nas buscas',
            ];
        }

        // Validar dimensões (usando DimensionCalculatorService)
        if ($dimensions && !empty($dimensions)) {
            try {
                $dimCalc = new \App\Services\Shipping\DimensionCalculatorService();
                $validation = $dimCalc->validateForAllModes(
                    $dimensions['length'] ?? 0,
                    $dimensions['width'] ?? 0,
                    $dimensions['height'] ?? 0,
                    $dimensions['weight'] ?? 0
                );

                // Se não é compatível com modos melhores, sugerir otimização
                $compatibleModes = $validation['compatible_modes'] ?? [];
                if (!in_array('full', $compatibleModes) && !in_array('flex', $compatibleModes)) {
                    $opportunities[] = [
                        'category' => 'shipping',
                        'title' => 'Otimizar dimensões da embalagem',
                        'description' => 'Dimensões atuais limitam opções de envio',
                        'potential_impact' => 'Acesso a melhores modalidades de envio',
                    ];
                }
            } catch (\Exception $e) {
                // Ignorar erros de validação
            }
        }

        return [
            'score' => max(0, $score),
            'issues' => $issues,
            'recommendations' => $recommendations,
            'opportunities' => $opportunities,
        ];
    }

    /**
     * Helper para calcular dedução de score baseado na severidade
     */
    private function getScoreDeduction(string $severity): int
    {
        return match ($severity) {
            'critical' => 25,
            'high' => 15,
            'medium' => 8,
            'low' => 3,
            default => 0,
        };
    }

    /**
     * Verifica problemas de status
     */
    private function checkStatusIssues(array $item): array
    {
        $issues = [];
        $recommendations = [];

        $status = $item['status'] ?? '';
        $subStatus = $item['sub_status'] ?? [];

        if ($status === 'paused') {
            $issues[] = [
                'category' => 'compliance',
                'severity' => 'critical',
                'title' => 'Anúncio pausado',
                'description' => 'Verifique os motivos da pausa: ' . implode(', ', $subStatus),
                'impact' => 'Anúncio não está visível',
            ];
            $recommendations[] = [
                'category' => 'compliance',
                'priority' => 'critical',
                'action' => 'Resolver problemas e reativar',
                'description' => 'Corrija os problemas indicados e reative o anúncio',
            ];
        }

        if ($status === 'inactive') {
            $issues[] = [
                'category' => 'compliance',
                'severity' => 'high',
                'title' => 'Anúncio inativo',
                'description' => 'O anúncio foi desativado',
                'impact' => 'Anúncio não está visível',
            ];
        }

        return [
            'issues' => $issues,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Determina status geral de saúde
     */
    private function determineOverallHealth(array $issues): string
    {
        if (empty($issues)) {
            return 'healthy';
        }

        $hasCritical = false;
        $highCount = 0;

        foreach ($issues as $issue) {
            if ($issue['severity'] === 'critical') {
                $hasCritical = true;
                break;
            }
            if ($issue['severity'] === 'high') {
                $highCount++;
            }
        }

        if ($hasCritical) {
            return 'critical';
        }

        if ($highCount >= 2) {
            return 'critical';
        }

        if ($highCount >= 1 || count($issues) >= 3) {
            return 'warning';
        }

        return 'warning';
    }

    /**
     * Gera resumo de saúde
     */
    private function generateHealthSummary(array $healthData): array
    {
        $issues = $healthData['issues'];
        $recommendations = $healthData['recommendations'];

        $criticalCount = 0;
        $highCount = 0;
        $mediumCount = 0;

        foreach ($issues as $issue) {
            switch ($issue['severity']) {
                case 'critical':
                    $criticalCount++;
                    break;
                case 'high':
                    $highCount++;
                    break;
                case 'medium':
                    $mediumCount++;
                    break;
            }
        }

        return [
            'total_issues' => count($issues),
            'critical_issues' => $criticalCount,
            'high_priority_issues' => $highCount,
            'medium_priority_issues' => $mediumCount,
            'total_recommendations' => count($recommendations),
            'health_score' => $healthData['health_score'],
        ];
    }

    /**
     * Verifica múltiplos itens em lote
     */
    public function checkMultipleItems(array $itemIds): array
    {
        $results = [];

        foreach ($itemIds as $itemId) {
            $results[$itemId] = $this->checkItemHealth($itemId);
        }

        return $results;
    }

    /**
     * Obtém recomendações priorizadas
     */
    public function getPrioritizedRecommendations(string $itemId): array
    {
        $health = $this->checkItemHealth($itemId);

        if (!$health['success']) {
            return [];
        }

        $recommendations = $health['recommendations'] ?? [];

        // Ordenar por prioridade
        usort($recommendations, function ($a, $b) {
            $priorities = ['critical' => 3, 'high' => 2, 'medium' => 1, 'low' => 0];
            $aPriority = $priorities[$a['priority']] ?? 0;
            $bPriority = $priorities[$b['priority']] ?? 0;
            return $bPriority - $aPriority;
        });

        return $recommendations;
    }

    /**
     * Obtém experiência de compra de um anúncio via ML API
     * Endpoint: GET /reputation/items/{itemId}/purchase_experience/integrators
     *
     * @return array Raw purchase experience data from ML, or ['error' => ...]
     */
    public function getPurchaseExperience(string $itemId): array
    {
        return $this->client->getPurchaseExperience($itemId);
    }
}
