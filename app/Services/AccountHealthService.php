<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * AccountHealthService - Diagnóstico completo da conta ML
 *
 * Orquestra dados de múltiplos serviços para gerar um diagnóstico
 * unificado com score geral, 5 pilares e ações prioritárias.
 *
 * Pilares: Reputação, SEO/Qualidade, Competitividade, Operação, Vendas
 */
class AccountHealthService
{
    private MercadoLivreClient $client;
    private PDO $db;
    private ?int $accountId;
    private ?CacheService $cache;
    private ?array $cachedStaleListings = null;

    // Cache de chamadas API reutilizadas entre pilares
    private ?string $cachedSellerId = null;
    private ?array $cachedUserData = null;
    private ?array $cachedActiveItemIds = null;
    private ?int $cachedTotalActive = null;

    // Cache de atributos obrigatórios por categoria (evita re-fetch)
    private array $categoryRequiredAttrs = [];

    // Pesos dos pilares no score geral (soma = 100)
    private const PILLAR_WEIGHTS = [
        'reputation'      => 25,
        'seo_quality'     => 25,
        'competitiveness' => 20,
        'operation'       => 15,
        'sales'           => 15,
    ];

    // Limites para classificação do score
    private const SCORE_THRESHOLDS = [
        'critical' => 30,
        'warning'  => 50,
        'good'     => 70,
        'great'    => 85,
    ];

    /** @var array<string, float> Tempos de execução por pilar (ms) */
    private array $timings = [];

    public function __construct(?int $accountId = null)
    {
        if ($accountId !== null && $accountId <= 0) {
            throw new \InvalidArgumentException('accountId deve ser um inteiro positivo');
        }

        $this->accountId = $accountId;
        $this->client = new MercadoLivreClient($accountId);
        $this->db = Database::getInstance();

        try {
            $this->cache = new CacheService();
        } catch (\Exception $e) {
            log_warning('CacheService indisponível para AccountHealth', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $this->cache = null;
        }
    }

    /**
     * Mede o tempo de execução de um callable e armazena em $this->timings.
     */
    private function timed(string $label, callable $fn): mixed
    {
        $start = hrtime(true);
        $result = $fn();
        $this->timings[$label] = round((hrtime(true) - $start) / 1e6, 1);
        return $result;
    }

    /**
     * Retorna o sellerId, cacheando para evitar chamadas repetidas.
     */
    private function getCachedSellerId(): ?string
    {
        if ($this->cachedSellerId === null) {
            $this->cachedSellerId = $this->client->getSellerId() ?: '';
        }
        return $this->cachedSellerId ?: null;
    }

    /**
     * Retorna dados do usuário (/users/{sellerId}), cacheando.
     */
    private function getCachedUserData(): ?array
    {
        if ($this->cachedUserData === null) {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return null;
            }
            $this->cachedUserData = $this->client->get("/users/{$sellerId}");
        }
        return $this->cachedUserData;
    }

    /**
     * Retorna IDs de itens ativos e total, cacheando.
     * Faz apenas 1 request (limit=50) para uso nos pilares.
     */
    private function getCachedActiveItems(): array
    {
        if ($this->cachedActiveItemIds === null) {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                $this->cachedActiveItemIds = [];
                $this->cachedTotalActive = 0;
                return ['ids' => [], 'total' => 0];
            }
            $response = $this->client->get("/users/{$sellerId}/items/search", [
                'status' => 'active',
                'limit'  => 50,
                'offset' => 0,
            ]);
            $this->cachedActiveItemIds = $response['results'] ?? [];
            $this->cachedTotalActive = (int)($response['paging']['total'] ?? count($this->cachedActiveItemIds));
        }
        return ['ids' => $this->cachedActiveItemIds, 'total' => $this->cachedTotalActive];
    }

    /**
     * Diagnóstico completo da conta - dados para a página inteira
     */
    public function getFullDiagnostic(): array
    {
        $cacheKey = "account_health_{$this->accountId}";

        if ($this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        // Pré-popular caches compartilhados (1 request cada em vez de 7+4+2)
        $this->timed('cache_warmup', function () {
            $this->getCachedSellerId();
            $this->getCachedUserData();
            $this->getCachedActiveItems();
        });

        // Computar stale listings UMA VEZ (reutilizado por sales pillar + attention items)
        $this->cachedStaleListings = $this->timed('stale_listings', fn() => $this->getStaleListings());

        $pillars = [
            'reputation'      => $this->timed('pillar_reputation', fn() => $this->getReputationPillar()),
            'seo_quality'     => $this->timed('pillar_seo', fn() => $this->getSeoQualityPillar()),
            'competitiveness' => $this->timed('pillar_comp', fn() => $this->getCompetitivenessPillar()),
            'operation'       => $this->timed('pillar_ops', fn() => $this->getOperationPillar()),
            'sales'           => $this->timed('pillar_sales', fn() => $this->getSalesPillar()),
        ];

        $overallScore = $this->calculateOverallScore($pillars);
        $actionItems = $this->generateActionItems($pillars);
        $itemsAttention = $this->timed('items_attention', fn() => $this->getItemsNeedingAttention());
        $staleListings = $this->cachedStaleListings;

        // Dados enriquecidos: deltas, categorias, pausados
        $previousScores = $this->timed('previous_scores', fn() => $this->getPreviousScores());
        $categoryBreakdown = $this->timed('category_breakdown', fn() => $this->getCategoryBreakdown($pillars));
        $pausedRecovery = $this->timed('paused_recovery', fn() => $this->getPausedItemsRecovery());

        // Verificar se os dados são reais ou mock
        $dataQuality = $this->assessDataQuality($pillars);

        $result = [
            'overall_score'      => $overallScore,
            'overall_level'      => $this->getScoreLevel($overallScore),
            'overall_label'      => $this->getScoreLabel($overallScore),
            'pillars'            => $pillars,
            'action_items'       => $actionItems,
            'items_attention'    => $itemsAttention,
            'stale_listings'     => $staleListings,
            'cross_insights'     => $this->generateCrossPillarInsights($pillars),
            'data_sources'       => $this->getDataSourcesInfo($pillars),
            'summary'            => $this->generateSummary($overallScore, $pillars, $actionItems),
            'previous_scores'    => $previousScores,
            'category_breakdown' => $categoryBreakdown,
            'paused_recovery'    => $pausedRecovery,
            'weekly_plan'        => $this->generateWeeklyPlan($actionItems, $pillars),
            'timings'            => $this->timings,
            'generated_at'       => date('Y-m-d H:i:s'),
            'data_quality'       => $dataQuality, // NEW: Indica se dados são reais
            'account_id'         => $this->accountId,
        ];

        if ($this->cache) {
            $this->cache->set($cacheKey, $result, 900); // 15 min cache
        }

        // Salvar histórico para tendências (máx 1x por hora)
        $this->saveScoreHistory($result);

        return $result;
    }

    /**
     * Salva o score atual no histórico (limita a 1 registro por hora)
     */
    private function saveScoreHistory(array $diagnostic): void
    {
        if (!$this->accountId) {
            return;
        }

        try {
            // Verificar se já salvou na última hora
            $stmt = $this->db->prepare(
                "SELECT id FROM account_health_history
                 WHERE account_id = :aid AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 LIMIT 1"
            );
            $stmt->execute(['aid' => $this->accountId]);
            if ($stmt->fetch()) {
                return; // Já salvou recentemente
            }

            $pillars = $diagnostic['pillars'] ?? [];
            $summary = $diagnostic['summary'] ?? [];

            $stmt = $this->db->prepare(
                "INSERT INTO account_health_history
                 (account_id, overall_score, reputation_score, seo_score,
                  competitiveness_score, operation_score, sales_score,
                  action_count, critical_count, created_at)
                 VALUES (:aid, :overall, :rep, :seo, :comp, :ops, :sales, :actions, :critical, NOW())"
            );
            $stmt->execute([
                'aid'      => $this->accountId,
                'overall'  => $diagnostic['overall_score'] ?? 0,
                'rep'      => $pillars['reputation']['score'] ?? 0,
                'seo'      => $pillars['seo_quality']['score'] ?? 0,
                'comp'     => $pillars['competitiveness']['score'] ?? 0,
                'ops'      => $pillars['operation']['score'] ?? 0,
                'sales'    => $pillars['sales']['score'] ?? 0,
                'actions'  => $summary['total_actions'] ?? 0,
                'critical' => $summary['critical_count'] ?? 0,
            ]);

            // Limpar registros antigos (manter só últimos 90 dias)
            $this->db->prepare(
                "DELETE FROM account_health_history
                 WHERE account_id = :aid AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
            )->execute(['aid' => $this->accountId]);
        } catch (\Exception $e) {
            log_error('Erro ao salvar histórico de saúde da conta', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retorna histórico de scores para gráfico de tendência
     *
     * @param int $days Período em dias (padrão 30)
     * @return array Lista de snapshots com datas e scores
     */
    public function getScoreHistory(int $days = 30): array
    {
        if (!$this->accountId) {
            return ['history' => [], 'trend' => null];
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT overall_score, reputation_score, seo_score,
                        competitiveness_score, operation_score, sales_score,
                        action_count, critical_count,
                        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as date
                 FROM account_health_history
                 WHERE account_id = :aid
                   AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                 ORDER BY created_at ASC"
            );
            $stmt->execute(['aid' => $this->accountId, 'days' => $days]);
            $history = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Calcular tendência comparando primeiro e último registro
            $trend = null;
            if (count($history) >= 2) {
                $first = $history[0];
                $last = $history[count($history) - 1];
                $trend = [
                    'overall'         => (int)$last['overall_score'] - (int)$first['overall_score'],
                    'reputation'      => (int)$last['reputation_score'] - (int)$first['reputation_score'],
                    'seo'             => (int)$last['seo_score'] - (int)$first['seo_score'],
                    'competitiveness' => (int)$last['competitiveness_score'] - (int)$first['competitiveness_score'],
                    'operation'       => (int)$last['operation_score'] - (int)$first['operation_score'],
                    'sales'           => (int)$last['sales_score'] - (int)$first['sales_score'],
                    'period_days'     => $days,
                    'data_points'     => count($history),
                ];
            }

            return [
                'history' => $history,
                'trend'   => $trend,
            ];
        } catch (\Exception $e) {
            log_error('Erro ao buscar histórico de saúde da conta', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return ['history' => [], 'trend' => null];
        }
    }

    /**
     * Limpa o cache forçando recálculo no próximo getFullDiagnostic()
     */
    public function clearCache(): bool
    {
        if ($this->cache) {
            return $this->cache->forget("account_health_{$this->accountId}");
        }
        return false;
    }

    // =========================================================================
    // PILAR 1: REPUTAÇÃO
    // =========================================================================

    public function getReputationPillar(): array
    {
        $score = 0;
        $details = [];
        $issues = [];

        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptyPillar('reputation', 'Conta não conectada');
            }

            $userData = $this->getCachedUserData();
            $rep = $userData['seller_reputation'] ?? [];
            $metrics = $rep['metrics'] ?? [];
            $transactions = $rep['transactions'] ?? [];

            // Level e power seller
            $levelId = $rep['level_id'] ?? 'unknown';
            $powerSeller = $rep['power_seller_status'] ?? null;

            $details['level_id'] = $levelId;
            $details['power_seller'] = $powerSeller;
            $details['protection_end_date'] = $rep['protection_end_date'] ?? null;

            // Score de nível (0-40 pts)
            $levelScore = match (true) {
                str_contains($levelId, '5_green')  => 40,
                str_contains($levelId, '4_light')  => 30,
                str_contains($levelId, '3_yellow') => 20,
                str_contains($levelId, '2_orange') => 10,
                default                            => 0,
            };

            // Score de power seller (0-20 pts)
            $powerScore = match ($powerSeller) {
                'platinum' => 20,
                'gold'     => 15,
                'silver'   => 10,
                default    => 0,
            };

            // Claims rate (0-15 pts) - quanto menor, melhor
            $claimsRate = $metrics['claims']['rate'] ?? 0;
            $details['claims_rate'] = round($claimsRate * 100, 2);
            $details['claims_value'] = $metrics['claims']['value'] ?? 0;
            $claimsScore = $claimsRate <= 0.01 ? 15 : ($claimsRate <= 0.03 ? 10 : ($claimsRate <= 0.05 ? 5 : 0));

            if ($claimsRate > 0.03) {
                $issues[] = [
                    'type'     => 'high_claims',
                    'severity' => $claimsRate > 0.05 ? 'critical' : 'warning',
                    'message'  => "Taxa de reclamações: {$details['claims_rate']}% (máx recomendado: 3%)",
                    'impact'   => 'Reduz visibilidade dos anúncios e pode causar penalidades',
                    'action'   => 'Revise a descrição dos produtos, melhore embalagens e atendimento',
                ];
            }

            // Cancellations rate (0-15 pts)
            $cancelRate = $metrics['cancellations']['rate'] ?? 0;
            $details['cancellations_rate'] = round($cancelRate * 100, 2);
            $details['cancellations_value'] = $metrics['cancellations']['value'] ?? 0;
            $cancelScore = $cancelRate <= 0.01 ? 15 : ($cancelRate <= 0.03 ? 10 : ($cancelRate <= 0.05 ? 5 : 0));

            if ($cancelRate > 0.02) {
                $issues[] = [
                    'type'     => 'high_cancellations',
                    'severity' => $cancelRate > 0.05 ? 'critical' : 'warning',
                    'message'  => "Taxa de cancelamentos: {$details['cancellations_rate']}%",
                    'impact'   => 'Prejudica reputação e ranking dos anúncios',
                    'action'   => 'Mantenha estoque atualizado e verifique antes de aceitar pedidos',
                ];
            }

            // Delayed handling time (0-10 pts)
            $delayRate = $metrics['delayed_handling_time']['rate'] ?? 0;
            $details['delayed_rate'] = round($delayRate * 100, 2);
            $details['delayed_value'] = $metrics['delayed_handling_time']['value'] ?? 0;
            $delayScore = $delayRate <= 0.05 ? 10 : ($delayRate <= 0.10 ? 6 : ($delayRate <= 0.15 ? 3 : 0));

            if ($delayRate > 0.05) {
                $issues[] = [
                    'type'     => 'delayed_shipping',
                    'severity' => $delayRate > 0.15 ? 'critical' : 'warning',
                    'message'  => "Taxa de atrasos no despacho: {$details['delayed_rate']}%",
                    'impact'   => 'Atrasos reduzem sua reputação e exposição',
                    'action'   => 'Despache pedidos no mesmo dia ou use Fulfillment',
                ];
            }

            // Transações
            $details['total_sales'] = $transactions['completed'] ?? 0;
            $details['total_canceled'] = $transactions['canceled'] ?? 0;
            $details['period'] = $transactions['period'] ?? 'historic';

            // Ratings
            $ratings = $transactions['ratings'] ?? [];
            $details['positive_ratings'] = $ratings['positive'] ?? 0;
            $details['neutral_ratings'] = $ratings['neutral'] ?? 0;
            $details['negative_ratings'] = $ratings['negative'] ?? 0;

            $totalRatings = ($details['positive_ratings'] + $details['neutral_ratings'] + $details['negative_ratings']);
            $details['positive_pct'] = $totalRatings > 0
                ? round($details['positive_ratings'] / $totalRatings * 100, 1) : 0;

            if ($details['positive_pct'] > 0 && $details['positive_pct'] < 90) {
                $issues[] = [
                    'type'     => 'low_positive_ratings',
                    'severity' => $details['positive_pct'] < 80 ? 'critical' : 'warning',
                    'message'  => "Avaliações positivas: {$details['positive_pct']}% (ideal: 95%+)",
                    'impact'   => 'Compradores evitam vendedores com avaliações baixas',
                    'action'   => 'Responda reclamações rapidamente e ofereça soluções',
                ];
            }

            $score = min(100, $levelScore + $powerScore + $claimsScore + $cancelScore + $delayScore);

            // Score breakdown (transparência)
            $details['score_breakdown'] = [
                'level'         => ['score' => $levelScore, 'max' => 40, 'label' => 'Nível de Reputação'],
                'power_seller'  => ['score' => $powerScore, 'max' => 20, 'label' => 'MercadoLíder'],
                'claims'        => ['score' => $claimsScore, 'max' => 15, 'label' => 'Reclamações'],
                'cancellations' => ['score' => $cancelScore, 'max' => 15, 'label' => 'Cancelamentos'],
                'delays'        => ['score' => $delayScore, 'max' => 10, 'label' => 'Atrasos no Despacho'],
            ];
        } catch (\Exception $e) {
            log_error('Erro no pilar Reputação', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyPillar('reputation', 'Erro ao carregar reputação');
        }

        return [
            'name'    => 'Reputação',
            'icon'    => 'bi-star-fill',
            'score'   => $score,
            'level'   => $this->getScoreLevel($score),
            'details' => $details,
            'issues'  => $issues,
        ];
    }

    // =========================================================================
    // PILAR 2: SEO & QUALIDADE DOS ANÚNCIOS
    // =========================================================================

    public function getSeoQualityPillar(): array
    {
        $score = 0;
        $details = [];
        $issues = [];

        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptyPillar('seo_quality', 'Conta não conectada');
            }

            // Buscar itens ativos (cache compartilhado)
            $activeData = $this->getCachedActiveItems();
            $itemIds = $activeData['ids'];
            $details['total_active'] = $activeData['total'];
            $details['analyzed'] = 0;

            if (empty($itemIds)) {
                $issues[] = [
                    'type'     => 'no_active_items',
                    'severity' => 'critical',
                    'message'  => 'Nenhum anúncio ativo encontrado',
                    'impact'   => 'Sem anúncios ativos, não há vendas',
                    'action'   => 'Crie e ative anúncios dos seus produtos',
                ];
                return [
                    'name'    => 'SEO & Qualidade',
                    'icon'    => 'bi-search',
                    'score'   => 0,
                    'level'   => 'critical',
                    'details' => $details,
                    'issues'  => $issues,
                ];
            }

            // Analisar até 50 itens
            $seoScores = [];
            $titleIssues = 0;
            $descriptionIssues = 0;
            $imageIssues = 0;
            $attributeIssues = 0;
            $shippingIssues = 0;
            $catalogItems = 0;
            $nonCatalogItems = 0;
            $incompleteSpecs = 0;
            $itemProblems = [];

            // Score oficial ML de qualidade dos anúncios (1 request)
            $mlQuality = null;
            try {
                $mlQualityData = $this->client->get("/users/{$sellerId}/listings_quality");
                if (!isset($mlQualityData['error'])) {
                    $mlQuality = [
                        'overall_score' => $mlQualityData['score'] ?? null,
                        'total'         => $mlQualityData['total_items'] ?? 0,
                        'excellent'     => $mlQualityData['excellent'] ?? 0,
                        'good'          => $mlQualityData['good'] ?? 0,
                        'regular'       => $mlQualityData['regular'] ?? 0,
                        'poor'          => $mlQualityData['poor'] ?? 0,
                    ];
                }
            } catch (\Exception $e) {
                // listings_quality não disponível, usar scoring local
            }
            $details['ml_quality'] = $mlQuality;

            // Buscar descrições individualmente (endpoint correto)
            $descriptionsMap = [];
            foreach ($itemIds as $itemId) {
                try {
                    $descData = $this->client->get("/items/{$itemId}/description");
                    if (is_array($descData) && isset($descData['plain_text'])) {
                        $descriptionsMap[$itemId] = $descData['plain_text'];
                    } elseif (is_array($descData) && isset($descData['text'])) {
                        $descriptionsMap[$itemId] = strip_tags($descData['text']);
                    }
                } catch (\Exception $e) {
                    // Descrição indisponível, continuar com próximo item
                }
            }

            // Buscar detalhes dos itens em lote (max 20 por request)
            $batches = array_chunk($itemIds, 20);
            foreach ($batches as $batch) {
                $idsParam = implode(',', $batch);
                $itemsData = $this->client->get("/items", ['ids' => $idsParam]);

                foreach ($itemsData as $itemWrapper) {
                    $item = $itemWrapper['body'] ?? $itemWrapper;
                    if (empty($item['id'])) {
                        continue;
                    }

                    // Passar descrição pré-buscada
                    $preloadedDesc = $descriptionsMap[$item['id']] ?? null;
                    $itemScore = $this->analyzeItemSeo($item, $preloadedDesc);
                    $seoScores[] = $itemScore['score'];
                    $details['analyzed']++;

                    // Sinais ML nativos: catalog e tags (0 requests extras)
                    if (!empty($item['catalog_product_id'])) {
                        $catalogItems++;
                    } else {
                        $nonCatalogItems++;
                    }

                    $tags = $item['tags'] ?? [];
                    if (in_array('incomplete_technical_specs', $tags)) {
                        $incompleteSpecs++;
                    }

                    if ($itemScore['title_score'] < 60) {
                        $titleIssues++;
                    }
                    if ($itemScore['description_score'] < 50) {
                        $descriptionIssues++;
                    }
                    if ($itemScore['images_score'] < 60) {
                        $imageIssues++;
                    }
                    if ($itemScore['attributes_score'] < 50) {
                        $attributeIssues++;
                    }
                    if (!$itemScore['has_free_shipping']) {
                        $shippingIssues++;
                    }

                    // Guardar itens com problemas (enriquecido com breakdown)
                    if ($itemScore['score'] < 70) {
                        $itemProblems[] = [
                            'id'              => $item['id'],
                            'title'           => $item['title'] ?? '',
                            'thumbnail'       => $item['thumbnail'] ?? '',
                            'price'           => $item['price'] ?? 0,
                            'score'           => $itemScore['score'],
                            'problems'        => $itemScore['problems'],
                            'permalink'       => $item['permalink'] ?? '',
                            'in_catalog'      => $itemScore['in_catalog'],
                            'ml_bonus'        => $itemScore['ml_bonus'],
                            'title_score'     => $itemScore['title_score'],
                            'images_score'    => $itemScore['images_score'],
                            'attributes_score' => $itemScore['attributes_score'],
                            'missing_required' => $itemScore['missing_required'],
                        ];
                    }
                }
            }

            // Score médio local (análise por item)
            $avgScore = !empty($seoScores) ? array_sum($seoScores) / count($seoScores) : 0;

            // Se disponível, combinar score ML oficial (60%) com análise local (40%)
            // ML quality score é a fonte mais confiável pois reflete o algoritmo real
            if ($mlQuality !== null && $mlQuality['overall_score'] !== null) {
                $mlScore = (float) $mlQuality['overall_score'];
                $score = (int) round($mlScore * 0.6 + $avgScore * 0.4);
                $details['score_source'] = 'ml_quality_60_local_40';
            } else {
                $score = (int) round($avgScore);
                $details['score_source'] = 'local_only';
            }

            $details['avg_seo_score'] = $score;
            $details['title_issues'] = $titleIssues;
            $details['description_issues'] = $descriptionIssues;
            $details['image_issues'] = $imageIssues;
            $details['attribute_issues'] = $attributeIssues;
            $details['shipping_issues'] = $shippingIssues;
            $details['items_below_70'] = count($itemProblems);

            // Sinais ML nativos (dados do multiget, 0 requests extras)
            $details['catalog_items'] = $catalogItems;
            $details['non_catalog_items'] = $nonCatalogItems;
            $catalogPct = $details['analyzed'] > 0
                ? round($catalogItems / $details['analyzed'] * 100, 1)
                : 0;
            $details['catalog_pct'] = $catalogPct;
            $details['incomplete_specs'] = $incompleteSpecs;

            // Ordenar piores primeiro
            usort($itemProblems, fn($a, $b) => $a['score'] <=> $b['score']);
            $details['worst_items'] = array_slice($itemProblems, 0, 10);

            // Gerar issues
            if ($titleIssues > 0) {
                $pct = round($titleIssues / $details['analyzed'] * 100);
                $issues[] = [
                    'type'     => 'poor_titles',
                    'severity' => $pct > 50 ? 'critical' : 'warning',
                    'message'  => "{$titleIssues} anúncios com títulos fracos ({$pct}%)",
                    'impact'   => 'Títulos ruins = menos buscas encontram seu produto',
                    'action'   => 'Otimize títulos com palavras-chave relevantes (45-58 caracteres)',
                    'count'    => $titleIssues,
                ];
            }

            if ($imageIssues > 0) {
                $pct = round($imageIssues / $details['analyzed'] * 100);
                $issues[] = [
                    'type'     => 'few_images',
                    'severity' => $pct > 50 ? 'critical' : 'warning',
                    'message'  => "{$imageIssues} anúncios com poucas imagens ({$pct}%)",
                    'impact'   => 'Anúncios com 6+ imagens vendem até 3x mais',
                    'action'   => 'Adicione pelo menos 6 fotos de alta qualidade (1200x1200px)',
                    'count'    => $imageIssues,
                ];
            }

            if ($shippingIssues > 0) {
                $pct = round($shippingIssues / $details['analyzed'] * 100);
                $issues[] = [
                    'type'     => 'no_free_shipping',
                    'severity' => $pct > 30 ? 'critical' : 'warning',
                    'message'  => "{$shippingIssues} anúncios sem frete grátis ({$pct}%)",
                    'impact'   => 'Frete grátis é o fator #1 para conversão no ML',
                    'action'   => 'Ative frete grátis (pode embutir no preço)',
                    'count'    => $shippingIssues,
                ];
            }

            if ($attributeIssues > 0) {
                $pct = round($attributeIssues / $details['analyzed'] * 100);
                $issues[] = [
                    'type'     => 'missing_attributes',
                    'severity' => $pct > 40 ? 'warning' : 'info',
                    'message'  => "{$attributeIssues} anúncios com atributos incompletos ({$pct}%)",
                    'impact'   => 'Atributos completos melhoram filtros e ranking de busca',
                    'action'   => 'Preencha marca, modelo, GTIN e atributos da categoria',
                    'count'    => $attributeIssues,
                ];
            }

            if ($descriptionIssues > 0) {
                $pct = round($descriptionIssues / $details['analyzed'] * 100);
                $issues[] = [
                    'type'     => 'weak_descriptions',
                    'severity' => $pct > 50 ? 'warning' : 'info',
                    'message'  => "{$descriptionIssues} anúncios com descrições fracas ({$pct}%)",
                    'impact'   => 'Descrições completas aumentam a confiança do comprador',
                    'action'   => 'Escreva descrições com 500+ caracteres, bullet points e especificações',
                    'count'    => $descriptionIssues,
                ];
            }

            // Issues de sinais ML nativos
            if ($nonCatalogItems > 0 && $details['analyzed'] > 0) {
                $pctNoCatalog = round($nonCatalogItems / $details['analyzed'] * 100);
                if ($pctNoCatalog > 20) {
                    $issues[] = [
                        'type'     => 'not_in_catalog',
                        'severity' => $pctNoCatalog > 60 ? 'critical' : 'warning',
                        'message'  => "{$nonCatalogItems} anúncios fora do catálogo ML ({$pctNoCatalog}%)",
                        'impact'   => 'Itens no catálogo ganham buybox, selo oficial e prioridade na busca',
                        'action'   => 'Associe seus produtos ao catálogo oficial do Mercado Livre',
                        'count'    => $nonCatalogItems,
                    ];
                }
            }

            if ($incompleteSpecs > 0) {
                $pctSpecs = round($incompleteSpecs / $details['analyzed'] * 100);
                $issues[] = [
                    'type'     => 'incomplete_specs',
                    'severity' => $pctSpecs > 40 ? 'warning' : 'info',
                    'message'  => "{$incompleteSpecs} anúncios com ficha técnica incompleta ({$pctSpecs}%)",
                    'impact'   => 'O ML sinaliza estes itens com specs incompletas — perdem relevância nos filtros',
                    'action'   => 'Complete a ficha técnica com todas as especificações exigidas pela categoria',
                    'count'    => $incompleteSpecs,
                ];
            }

            // Issue baseada no score oficial ML de qualidade
            if ($mlQuality !== null) {
                $poorCount = ($mlQuality['poor'] ?? 0) + ($mlQuality['regular'] ?? 0);
                $totalQuality = $mlQuality['total'] ?? 0;
                if ($totalQuality > 0 && $poorCount > 0) {
                    $pctPoor = round($poorCount / $totalQuality * 100);
                    if ($pctPoor > 15) {
                        $issues[] = [
                            'type'     => 'ml_quality_low',
                            'severity' => $pctPoor > 40 ? 'critical' : 'warning',
                            'message'  => "{$poorCount} anúncios com qualidade Regular/Ruim segundo o ML ({$pctPoor}%)",
                            'impact'   => 'O Mercado Livre classifica esses anúncios como baixa qualidade — perdem exposição',
                            'action'   => 'Revise os anúncios com pior classificação: melhore fotos, ficha técnica e condições',
                            'count'    => $poorCount,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            log_error('Erro no pilar SEO Quality', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyPillar('seo_quality', 'Erro ao analisar qualidade');
        }

        return [
            'name'    => 'SEO & Qualidade',
            'icon'    => 'bi-search',
            'score'   => $score,
            'level'   => $this->getScoreLevel($score),
            'details' => $details,
            'issues'  => $issues,
        ];
    }

    /**
     * Análise SEO rápida de um item individual
     * @param string|null $preloadedDescription Texto da descrição pré-buscado em batch
     */
    private function analyzeItemSeo(array $item, ?string $preloadedDescription = null): array
    {
        $problems = [];
        $tags = $item['tags'] ?? [];

        // ======== TÍTULO (0-20 pts) ========
        $title = $item['title'] ?? '';
        $titleLen = mb_strlen($title);
        $titleScore = 0;
        if ($titleLen >= 45 && $titleLen <= 60) {
            $titleScore = 20;
        } elseif ($titleLen >= 30 && $titleLen <= 70) {
            $titleScore = 12;
        } elseif ($titleLen > 0) {
            $titleScore = 4;
        }
        if ($titleLen < 30) {
            $problems[] = 'Título muito curto';
        }
        if ($titleLen > 60) {
            $problems[] = 'Título muito longo';
        }
        if (mb_strtoupper($title) === $title && $titleLen > 5) {
            $titleScore = max(0, $titleScore - 5);
            $problems[] = 'Título todo em MAIÚSCULAS';
        }

        // ======== IMAGENS (0-20 pts) — com sinal ML ========
        $imageCount = count($item['pictures'] ?? []);
        // Base: quantidade de imagens (max 12 pts)
        $imagesScore = min(12, $imageCount * 2);
        // Bônus ML: qualidade verificada pelo algoritmo (até +8 pts)
        if (in_array('good_quality_picture', $tags)) {
            $imagesScore += 5;
        }
        if (in_array('good_quality_thumbnail', $tags)) {
            $imagesScore += 3;
        }
        $imagesScore = min(20, $imagesScore);
        if ($imageCount < 3) {
            $problems[] = "Apenas {$imageCount} imagem(ns) — mínimo 6";
        } elseif ($imageCount < 6) {
            $problems[] = "Apenas {$imageCount} imagens — recomendado 6+";
        }
        if (!in_array('good_quality_picture', $tags) && $imageCount >= 6) {
            $problems[] = 'Fotos não aprovadas como alta qualidade pelo ML';
        }

        // ======== ATRIBUTOS (0-20 pts) — dinâmico por categoria ========
        $attributes = $item['attributes'] ?? [];
        $categoryId = $item['category_id'] ?? '';
        $filledAttrIds = [];
        $filledAttrs = 0;

        foreach ($attributes as $attr) {
            $attrId = $attr['id'] ?? '';
            $attrValue = $attr['value_name'] ?? '';
            if ($attrValue !== '' && $attrValue !== 'N/A') {
                $filledAttrs++;
                $filledAttrIds[] = $attrId;
            }
        }

        // Buscar atributos obrigatórios da categoria (cache local)
        $requiredAttrs = $this->getCategoryRequiredAttributes($categoryId);
        $missingRequired = [];
        $attributesScore = 0;

        if (!empty($requiredAttrs)) {
            $filledRequired = count(array_intersect($requiredAttrs, $filledAttrIds));
            $totalRequired = count($requiredAttrs);
            // Score proporcional: todos preenchidos = 15 pts
            $attributesScore = $totalRequired > 0
                ? (int) round(($filledRequired / $totalRequired) * 15)
                : 10;
            $missingRequired = array_diff($requiredAttrs, $filledAttrIds);
            if (count($missingRequired) > 0) {
                $missingCount = count($missingRequired);
                $problems[] = "{$missingCount} atributo(s) obrigatório(s) da categoria não preenchido(s)";
            }
        } else {
            // Fallback: checar BRAND/MODEL/GTIN como antes
            $hasBrand = in_array('BRAND', $filledAttrIds);
            $hasModel = in_array('MODEL', $filledAttrIds);
            $hasGtin = !empty(array_intersect(['GTIN', 'EAN', 'UPC'], $filledAttrIds));
            if ($hasBrand) {
                $attributesScore += 5;
            } else {
                $problems[] = 'Sem marca definida';
            }
            if ($hasGtin) {
                $attributesScore += 5;
            } else {
                $problems[] = 'Sem código de barras (GTIN/EAN)';
            }
            if ($hasModel) {
                $attributesScore += 3;
            }
        }
        // Bônus por volume de atributos preenchidos (max +5)
        $attributesScore += min(5, intdiv($filledAttrs, 4));
        $attributesScore = min(20, $attributesScore);

        // Penalidade ML: ficha técnica incompleta detectada pelo ML
        if (in_array('incomplete_technical_specs', $tags)) {
            $attributesScore = max(0, $attributesScore - 4);
            if (!in_array('Ficha técnica incompleta (sinal ML)', $problems)) {
                $problems[] = 'Ficha técnica incompleta (sinal ML)';
            }
        }

        // ======== DESCRIÇÃO (0-10 pts) ========
        $descriptionText = '';
        $descPlainLen = 0;
        if ($preloadedDescription !== null) {
            $descriptionText = $preloadedDescription;
            $descPlainLen = mb_strlen(trim($descriptionText));
        } else {
            try {
                $itemId = $item['id'] ?? '';
                if ($itemId) {
                    $descData = $this->client->get("/items/{$itemId}/description");
                    $descriptionText = $descData['plain_text'] ?? strip_tags($descData['text'] ?? '');
                    $descPlainLen = mb_strlen(trim($descriptionText));
                }
            } catch (\Exception $e) {
                // Descrição não disponível, score neutro
            }
        }

        $descriptionScore = 0;
        if ($descPlainLen >= 500) {
            $descriptionScore = 10;
        } elseif ($descPlainLen >= 300) {
            $descriptionScore = 7;
        } elseif ($descPlainLen >= 100) {
            $descriptionScore = 4;
        } elseif ($descPlainLen > 0) {
            $descriptionScore = 2;
        }
        // Score % para compatibilidade com threshold check externo
        $descriptionScorePct = min(100, $descPlainLen >= 500 ? 100 : (int)($descPlainLen / 5));
        if ($descPlainLen < 100) {
            $problems[] = 'Descrição muito curta (' . $descPlainLen . ' chars)';
        } elseif ($descPlainLen < 300) {
            $problems[] = 'Descrição curta (recomendado 500+ chars)';
        }

        // ======== ENVIO & TIPO (0-20 pts) ========
        $shipping = $item['shipping'] ?? [];
        $hasFreeShipping = $shipping['free_shipping'] ?? false;
        $logisticType = $shipping['logistic_type'] ?? '';
        $listingType = $item['listing_type_id'] ?? '';

        $shippingScore = 0;
        if ($hasFreeShipping) {
            $shippingScore += 8;
        } else {
            $problems[] = 'Sem frete grátis';
        }

        if ($logisticType === 'fulfillment') {
            $shippingScore += 7;
        } elseif (str_contains($logisticType, 'cross_docking') || $logisticType === 'xd_drop_off') {
            $shippingScore += 4;
        }

        if ($listingType === 'gold_pro' || $listingType === 'gold_premium') {
            $shippingScore += 5;
        } elseif ($listingType === 'gold_special') {
            $shippingScore += 3;
        } else {
            $problems[] = 'Tipo de anúncio básico (não Clássico/Premium)';
        }
        $shippingScore = min(20, $shippingScore);

        // ======== BÔNUS ML NATIVOS (0-10 pts) ========
        $mlBonus = 0;
        // Item no catálogo oficial = buybox + selo
        if (!empty($item['catalog_product_id'])) {
            $mlBonus += 5;
        } else {
            $problems[] = 'Não associado ao catálogo oficial ML';
        }
        // Elegível ao carrinho
        if (in_array('cart_eligible', $tags)) {
            $mlBonus += 2;
        }
        // Frete garantido pelo ML
        if (in_array('shipping_guaranteed', $tags)) {
            $mlBonus += 2;
        }
        // Candidato best seller
        if (in_array('best_seller_candidate', $tags)) {
            $mlBonus += 1;
        }
        $mlBonus = min(10, $mlBonus);

        // ======== TOTAL (0-100) ========
        // Título 20 + Imagens 20 + Atributos 20 + Descrição 10 + Envio 20 + ML Bônus 10
        $totalScore = $titleScore + $imagesScore + $attributesScore
            + $descriptionScore + $shippingScore + $mlBonus;
        $totalScore = max(0, min(100, $totalScore));

        return [
            'score'             => $totalScore,
            'title_score'       => round($titleScore / 20 * 100),
            'images_score'      => round($imagesScore / 20 * 100),
            'attributes_score'  => round($attributesScore / 20 * 100),
            'description_score' => $descriptionScorePct,
            'has_free_shipping' => $hasFreeShipping,
            'listing_type'      => $listingType,
            'logistic_type'     => $logisticType,
            'image_count'       => $imageCount,
            'in_catalog'        => !empty($item['catalog_product_id']),
            'ml_bonus'          => $mlBonus,
            'missing_required'  => array_slice($missingRequired, 0, 5),
            'problems'          => $problems,
        ];
    }

    /**
     * Busca atributos obrigatórios da categoria no ML (cache local por execução).
     * 1 request por categoria única; retorna array de IDs obrigatórios.
     */
    private function getCategoryRequiredAttributes(string $categoryId): array
    {
        if ($categoryId === '') {
            return [];
        }

        if (isset($this->categoryRequiredAttrs[$categoryId])) {
            return $this->categoryRequiredAttrs[$categoryId];
        }

        try {
            $attrs = $this->client->get("/categories/{$categoryId}/attributes");
            $required = [];
            if (is_array($attrs)) {
                foreach ($attrs as $attr) {
                    $tags = $attr['tags']['required'] ?? false;
                    $isCatalogRequired = $attr['tags']['catalog_required'] ?? false;
                    if ($tags || $isCatalogRequired) {
                        $required[] = $attr['id'];
                    }
                }
            }
            $this->categoryRequiredAttrs[$categoryId] = $required;
            return $required;
        } catch (\Exception $e) {
            // Se falhar, cache vazio para evitar retry
            $this->categoryRequiredAttrs[$categoryId] = [];
            return [];
        }
    }

    // =========================================================================
    // PILAR 3: COMPETITIVIDADE (Posicionamento no ML)
    // =========================================================================

    public function getCompetitivenessPillar(): array
    {
        $score = 0;
        $details = [];
        $issues = [];

        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptyPillar('competitiveness', 'Conta não conectada');
            }

            // Buscar itens ativos (cache compartilhado)
            $activeData = $this->getCachedActiveItems();
            $itemIds = $activeData['ids'];
            $totalItems = $activeData['total'];
            $details['total_items'] = $totalItems;

            if (empty($itemIds)) {
                return $this->emptyPillar('competitiveness', 'Nenhum anúncio para analisar');
            }

            // Buscar detalhes dos itens (20 de cada vez)
            $allItems = [];
            $chunks = array_chunk($itemIds, 20);
            foreach (array_slice($chunks, 0, 3) as $chunk) { // max 60 itens
                $idsParam = implode(',', $chunk);
                $batchData = $this->client->get("/items", ['ids' => $idsParam]);
                foreach ($batchData as $wrapper) {
                    $item = $wrapper['body'] ?? $wrapper;
                    if (!empty($item['id'])) {
                        $allItems[] = $item;
                    }
                }
            }

            // Buscar tendências da categoria principal (para relevância)
            $categoryCount = [];
            foreach ($allItems as $item) {
                $cat = $item['category_id'] ?? '';
                if ($cat) {
                    $categoryCount[$cat] = ($categoryCount[$cat] ?? 0) + 1;
                }
            }
            arsort($categoryCount);
            $mainCategory = array_key_first($categoryCount);
            $trendKeywords = [];
            if ($mainCategory) {
                try {
                    $trends = $this->client->get("/trends/MLB/{$mainCategory}", [], 600, true);
                    foreach ($trends as $trend) {
                        if (isset($trend['keyword'])) {
                            $trendKeywords[] = mb_strtolower($trend['keyword']);
                        }
                    }
                } catch (\Exception $e) {
                    // Trends não é crítico
                }
            }

            // Analisar cada item
            $itemScores = [];
            $noFreeShipping = [];
            $noGoldPro = [];
            $lowHealth = [];
            $noFullfillment = [];
            $lowSales = [];
            $goodItems = [];
            $catalogCount = 0;
            $draggedVisits = [];
            $bestSellerCandidates = [];

            foreach ($allItems as $item) {
                $itemScore = $this->scoreItemCompetitiveness($item, $trendKeywords);
                $itemScores[] = $itemScore;

                $id = $item['id'];
                $title = $item['title'] ?? '';
                $thumb = $item['thumbnail'] ?? '';
                $permalink = $item['permalink'] ?? '';
                $price = $item['price'] ?? 0;
                $itemSummary = [
                    'id'        => $id,
                    'title'     => $title,
                    'thumbnail' => $thumb,
                    'permalink' => $permalink,
                    'price'     => $price,
                    'score'     => $itemScore['total'],
                ];

                // Classificar problemas
                if (!($item['shipping']['free_shipping'] ?? false)) {
                    $noFreeShipping[] = $itemSummary;
                }
                $listingType = $item['listing_type_id'] ?? '';
                if ($listingType !== 'gold_pro') {
                    $noGoldPro[] = array_merge($itemSummary, ['listing_type' => $listingType]);
                }
                $health = is_numeric($item['health'] ?? null) ? (float)$item['health'] : null;
                if ($health !== null && $health < 0.7) {
                    $lowHealth[] = array_merge($itemSummary, ['health' => $health]);
                }
                $logistic = $item['shipping']['logistic_type'] ?? '';
                if (!in_array($logistic, ['fulfillment', 'cross_docking'])) {
                    $noFullfillment[] = $itemSummary;
                }
                $soldQty = $item['sold_quantity'] ?? 0;
                if ($soldQty === 0) {
                    $lowSales[] = $itemSummary;
                }
                if ($itemScore['total'] >= 75) {
                    $goodItems[] = $itemSummary;
                }

                // Sinais ML nativos (0 requests extras)
                if (!empty($item['catalog_product_id'])) {
                    $catalogCount++;
                }
                $itemTags = $item['tags'] ?? [];
                if (in_array('dragged_bids_and_visits', $itemTags)) {
                    $draggedVisits[] = $itemSummary;
                }
                if (in_array('best_seller_candidate', $itemTags)) {
                    $bestSellerCandidates[] = $itemSummary;
                }
            }

            $analyzed = count($allItems);
            $details['analyzed'] = $analyzed;

            // Calcular score geral (média dos scores dos itens)
            if ($analyzed > 0) {
                $totalScores = array_column($itemScores, 'total');
                $score = (int) round(array_sum($totalScores) / count($totalScores));
                $score = min(100, max(0, $score));
            }

            // Detalhes por sub-pontuação
            $details['avg_listing_type_score'] = $this->avgSubScore($itemScores, 'listing_type');
            $details['avg_shipping_score'] = $this->avgSubScore($itemScores, 'shipping');
            $details['avg_logistics_score'] = $this->avgSubScore($itemScores, 'logistics');
            $details['avg_health_score'] = $this->avgSubScore($itemScores, 'health');
            $details['avg_quality_score'] = $this->avgSubScore($itemScores, 'quality');
            $details['avg_catalog_score'] = $this->avgSubScore($itemScores, 'catalog');
            $details['avg_sales_score'] = $this->avgSubScore($itemScores, 'sales');
            $details['avg_relevance_score'] = $this->avgSubScore($itemScores, 'relevance');

            $details['good_items'] = count($goodItems);
            $details['no_free_shipping'] = count($noFreeShipping);
            $details['no_gold_pro'] = count($noGoldPro);
            $details['low_health'] = count($lowHealth);
            $details['no_fulfillment'] = count($noFullfillment);
            $details['zero_sales'] = count($lowSales);
            $details['trend_keywords'] = array_slice($trendKeywords, 0, 10);

            // Sinais ML nativos (0 requests extras)
            $details['catalog_items'] = $catalogCount;
            $details['catalog_pct'] = $analyzed > 0
                ? round($catalogCount / $analyzed * 100, 1)
                : 0;
            $details['dragged_visits_items'] = count($draggedVisits);
            $details['best_seller_candidates'] = count($bestSellerCandidates);

            // Issues
            if (count($noFreeShipping) > 0) {
                $pct = round(count($noFreeShipping) / $analyzed * 100);
                $issues[] = [
                    'type'     => 'no_free_shipping',
                    'severity' => $pct > 30 ? 'critical' : 'warning',
                    'message'  => count($noFreeShipping) . " anúncios sem frete grátis ({$pct}%)",
                    'impact'   => 'Frete grátis é decisivo no ranking do ML',
                    'action'   => 'Ative frete grátis (pode embutir no preço)',
                    'count'    => count($noFreeShipping),
                    'items'    => array_slice($noFreeShipping, 0, 5),
                ];
            }

            if (count($noGoldPro) > 0) {
                $pct = round(count($noGoldPro) / $analyzed * 100);
                $issues[] = [
                    'type'     => 'not_gold_pro',
                    'severity' => $pct > 50 ? 'warning' : 'info',
                    'message'  => count($noGoldPro) . " anúncios não são Premium (gold_pro)",
                    'impact'   => 'Anúncios Premium têm mais exposição e conversão',
                    'action'   => 'Migre anúncios para tipo Premium',
                    'count'    => count($noGoldPro),
                ];
            }

            if (count($lowHealth) > 0) {
                $issues[] = [
                    'type'     => 'low_health',
                    'severity' => count($lowHealth) > 5 ? 'critical' : 'warning',
                    'message'  => count($lowHealth) . " anúncios com saúde baixa no ML (< 70%)",
                    'impact'   => 'Anúncios com saúde baixa perdem posição nos resultados',
                    'action'   => 'Melhore título, fotos e atributos desses anúncios',
                    'count'    => count($lowHealth),
                    'items'    => array_slice($lowHealth, 0, 5),
                ];
            }

            if (count($lowSales) > 0 && count($lowSales) > $analyzed * 0.3) {
                $pct = round(count($lowSales) / $analyzed * 100);
                $issues[] = [
                    'type'     => 'zero_sales',
                    'severity' => 'warning',
                    'message'  => count($lowSales) . " anúncios sem nenhuma venda ({$pct}%)",
                    'impact'   => 'Itens sem vendas têm menor visibilidade',
                    'action'   => 'Revise preço, título e fotos desses anúncios',
                    'count'    => count($lowSales),
                ];
            }
        } catch (\Exception $e) {
            log_error('Erro no pilar Competitividade', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyPillar('competitiveness', 'Erro ao analisar competitividade');
        }

        return [
            'name'    => 'Competitividade',
            'icon'    => 'bi-graph-up-arrow',
            'score'   => $score,
            'level'   => $this->getScoreLevel($score),
            'details' => $details,
            'issues'  => $issues,
        ];
    }

    /**
     * Pontua a competitividade de um item individual (0-100)
     */
    private function scoreItemCompetitiveness(array $item, array $trendKeywords = []): array
    {
        $scores = [
            'listing_type' => 0, // max 15
            'shipping'     => 0, // max 15
            'logistics'    => 0, // max 15
            'health'       => 0, // max 15
            'quality'      => 0, // max 15
            'catalog'      => 0, // max 10
            'sales'        => 0, // max 10
            'relevance'    => 0, // max 5
        ];

        // Listing Type (15pts)
        $listingType = $item['listing_type_id'] ?? '';
        $scores['listing_type'] = match ($listingType) {
            'gold_pro'     => 15,
            'gold_special' => 9,
            'gold'         => 6,
            default        => 0,
        };

        // Free Shipping (15pts)
        if ($item['shipping']['free_shipping'] ?? false) {
            $scores['shipping'] = 15;
        }

        // Logistics (15pts)
        $logistic = $item['shipping']['logistic_type'] ?? '';
        $scores['logistics'] = match (true) {
            $logistic === 'fulfillment'                         => 15,
            $logistic === 'cross_docking'                       => 12,
            in_array($logistic, ['xd_drop_off', 'drop_off'])    => 8,
            $logistic !== ''                                    => 4,
            default                                             => 0,
        };

        // ML Health (15pts)
        $health = is_numeric($item['health'] ?? null) ? (float)$item['health'] : null;
        if ($health !== null) {
            $scores['health'] = (int) round($health * 15);
        } else {
            $scores['health'] = 7; // default se não disponível
        }

        // Catalog (10pts) — item vinculado ao catálogo oficial ML
        if (!empty($item['catalog_product_id']) || in_array('catalog_listing', $item['tags'] ?? [])) {
            $scores['catalog'] = 10;
        }

        // Quality Tags (15pts) - sinais ML nativos expandidos
        $tags = $item['tags'] ?? [];
        $qualityPts = 0;
        // Imagens de qualidade verificadas pelo ML
        if (in_array('good_quality_picture', $tags)) {
            $qualityPts += 3;
        }
        if (in_array('good_quality_thumbnail', $tags)) {
            $qualityPts += 2;
        }
        // Elegível ao carrinho (forte sinal de confiança)
        if (in_array('cart_eligible', $tags)) {
            $qualityPts += 3;
        }
        // Item no catálogo oficial
        if (in_array('catalog_listing', $tags) || !empty($item['catalog_product_id'])) {
            $qualityPts += 3;
        }
        // ML detecta specs incompletas (penalidade)
        if (in_array('incomplete_technical_specs', $tags)) {
            $qualityPts -= 2;
        }
        // Itens arrastando visitas (tração positiva)
        if (in_array('dragged_bids_and_visits', $tags)) {
            $qualityPts += 2;
        }
        // Elegível a desconto por fidelidade
        if (in_array('loyalty_discount_eligible', $tags)) {
            $qualityPts += 1;
        }
        // Candidato a best seller
        if (in_array('best_seller_candidate', $tags)) {
            $qualityPts += 2;
        }
        // Frete garantido pelo ML
        if (in_array('shipping_guaranteed', $tags)) {
            $qualityPts += 1;
        }
        $scores['quality'] = max(0, min(15, $qualityPts));

        // Sales (10pts)
        $soldQty = $item['sold_quantity'] ?? 0;
        $scores['sales'] = match (true) {
            $soldQty >= 100 => 10,
            $soldQty >= 50  => 8,
            $soldQty >= 20  => 6,
            $soldQty >= 5   => 4,
            $soldQty > 0    => 2,
            default         => 0,
        };

        // Trend Relevance (5pts)
        if (!empty($trendKeywords)) {
            $titleLower = mb_strtolower($item['title'] ?? '');
            $matchCount = 0;
            foreach ($trendKeywords as $kw) {
                if (mb_strpos($titleLower, $kw) !== false) {
                    $matchCount++;
                }
            }
            $scores['relevance'] = min(5, $matchCount * 2);
        }

        $scores['total'] = array_sum($scores);

        return $scores;
    }

    /**
     * Calcula média de uma sub-pontuação
     */
    private function avgSubScore(array $itemScores, string $key): float
    {
        if (empty($itemScores)) {
            return 0;
        }
        $values = array_column($itemScores, $key);
        return round(array_sum($values) / count($values), 1);
    }

    // =========================================================================
    // PILAR 4: OPERAÇÃO (Envios, Atendimento, Pós-venda)
    // =========================================================================

    public function getOperationPillar(): array
    {
        $score = 0;
        $details = [];
        $issues = [];

        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptyPillar('operation', 'Conta não conectada');
            }

            // Dados de reputação (métricas operacionais) - cache compartilhado
            $userData = $this->getCachedUserData();
            $metrics = $userData['seller_reputation']['metrics'] ?? [];

            // ── Delayed handling (0-20 pts) ──
            $delayRate = $metrics['delayed_handling_time']['rate'] ?? 0;
            $details['delay_rate'] = round($delayRate * 100, 2);
            $delayScore = match (true) {
                $delayRate <= 0.02 => 20,
                $delayRate <= 0.05 => 16,
                $delayRate <= 0.10 => 10,
                $delayRate <= 0.20 => 4,
                default            => 0,
            };

            // ── Perguntas sem resposta + tempo de resposta (0-15 pts) ──
            $questionsScore = 0;
            try {
                $questions = $this->client->get("/questions/search", [
                    'seller_id' => $sellerId,
                    'status'    => 'unanswered',
                    'limit'     => 1,
                ], 300);
                $unanswered = $questions['total'] ?? 0;

                // Tempo médio de resposta (baseado nas últimas 20 perguntas respondidas)
                $avgResponseMinutes = null;
                try {
                    $answered = $this->client->get("/questions/search", [
                        'seller_id' => $sellerId,
                        'status'    => 'answered',
                        'sort_fields' => 'date_created',
                        'sort_types'  => 'DESC',
                        'limit'       => 20,
                    ], 600);
                    $responseTimes = [];
                    foreach (($answered['questions'] ?? []) as $q) {
                        $created = strtotime($q['date_created'] ?? '');
                        $answeredAt = strtotime($q['answer']['date_created'] ?? '');
                        if ($created && $answeredAt && $answeredAt > $created) {
                            $responseTimes[] = ($answeredAt - $created) / 60; // minutos
                        }
                    }
                    $details['questions_answered'] = $answered['total'] ?? count($answered['questions'] ?? []);
                    if (!empty($responseTimes)) {
                        $avgResponseMinutes = round(array_sum($responseTimes) / count($responseTimes));
                        $details['avg_response_time_min'] = $avgResponseMinutes;
                    }
                } catch (\Exception $e) {
                    log_warning('Falha ao calcular tempo de resposta de perguntas', [
                        'account_id' => $this->accountId,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Exception $e) {
                $unanswered = 0;
            }

            $details['unanswered_questions'] = $unanswered;
            // Score combinado: sem-resposta + tempo
            $unansweredPts = $unanswered === 0 ? 10 : ($unanswered <= 3 ? 7 : ($unanswered <= 10 ? 3 : 0));
            $responseTimePts = 0;
            if ($avgResponseMinutes !== null) {
                $responseTimePts = match (true) {
                    $avgResponseMinutes <= 30  => 5,
                    $avgResponseMinutes <= 60  => 4,
                    $avgResponseMinutes <= 180 => 2,
                    default                    => 0,
                };
            } else {
                $responseTimePts = $unanswered === 0 ? 3 : 0; // crédito parcial se tudo respondido
            }
            $questionsScore = $unansweredPts + $responseTimePts;

            if ($unanswered > 0) {
                $issues[] = [
                    'type'     => 'unanswered_questions',
                    'severity' => $unanswered > 5 ? 'critical' : 'warning',
                    'message'  => "{$unanswered} perguntas sem resposta",
                    'impact'   => 'Cada pergunta sem resposta é uma venda perdida',
                    'action'   => 'Responda todas as perguntas rapidamente (ideal < 1h)',
                    'count'    => $unanswered,
                ];
            }
            if ($avgResponseMinutes !== null && $avgResponseMinutes > 120) {
                $hours = round($avgResponseMinutes / 60, 1);
                $issues[] = [
                    'type'     => 'slow_response_time',
                    'severity' => $avgResponseMinutes > 360 ? 'warning' : 'info',
                    'message'  => "Tempo médio de resposta: {$hours}h (ideal < 1h)",
                    'impact'   => 'Respostas lentas fazem o comprador ir para o concorrente',
                    'action'   => 'Ative notificações e responda em até 30 minutos',
                ];
            }

            // ── Shipping preferences / Fulfillment (0-20 pts) ──
            try {
                $shippingPrefs = $this->client->get("/users/{$sellerId}/shipping_preferences");
                $fullEnabled = false;

                $modes = $shippingPrefs['modes'] ?? [];
                if (in_array('me2', $modes) || in_array('me1', $modes)) {
                    $fullEnabled = true;
                }

                $listingModes = $shippingPrefs['listing_modes'] ?? [];
                foreach ($listingModes as $mode) {
                    if (($mode['logistic_type'] ?? '') === 'fulfillment') {
                        $fullEnabled = true;
                    }
                }

                $details['fulfillment_enabled'] = $fullEnabled;
                $details['shipping_modes'] = $modes;
            } catch (\Exception $e) {
                $fullEnabled = false;
                $details['fulfillment_enabled'] = false;
            }

            $shippingScore = 8; // base
            if ($fullEnabled) {
                $shippingScore += 12;
            } else {
                $issues[] = [
                    'type'     => 'no_fulfillment',
                    'severity' => 'warning',
                    'message'  => 'Mercado Envios Full não está ativo',
                    'impact'   => 'Full = entregas mais rápidas = melhor ranking',
                    'action'   => 'Ative Fulfillment para seus produtos mais vendidos',
                ];
            }

            // ── Cancelamentos recentes (0-15 pts) ──
            $ordersScore = 0;
            try {
                $since30d = date('Y-m-d\TH:i:s', strtotime('-30 days')) . '.000-00:00';
                $recentOrders = $this->client->get('/orders/search', [
                    'seller' => $sellerId,
                    'order.date_created.from' => $since30d,
                    'limit' => 1,
                ]);

                if (
                    isset($recentOrders['error'])
                    && $recentOrders['error'] === 'orders_access_unavailable'
                    && ($recentOrders['feature'] ?? null) === 'orders'
                    && ($recentOrders['optional_feature'] ?? false) === true
                ) {
                    log_info('AccountHealthService: orders capability unavailable — skipping order metrics', [
                        'account_id' => $this->accountId,
                        'seller_id' => $sellerId,
                    ]);
                    $totalOrders = 0;
                    $cancelledOrders = 0;
                } else {
                    $totalOrders = (int)($recentOrders['paging']['total'] ?? 0);

                    $cancelledOrders = 0;
                    try {
                        $cancelledResult = $this->client->get('/orders/search', [
                            'seller' => $sellerId,
                            'order.status' => 'cancelled',
                            'order.date_created.from' => $since30d,
                            'limit' => 1,
                        ]);
                        $cancelledOrders = (int)($cancelledResult['paging']['total'] ?? 0);
                    } catch (\Exception $e) {
                        log_warning('Falha ao buscar pedidos cancelados', [
                            'account_id' => $this->accountId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $details['orders_30d'] = $totalOrders;
                $details['cancelled_30d'] = $cancelledOrders;

                $cancelPct = $totalOrders > 0 ? $cancelledOrders / $totalOrders : 0;
                $ordersScore = match (true) {
                    $cancelPct <= 0.02 => 15,
                    $cancelPct <= 0.05 => 11,
                    $cancelPct <= 0.10 => 6,
                    default            => 0,
                };
            } catch (\Exception $e) {
                $ordersScore = 8;
                $details['orders_30d'] = 0;
            }

            // ── Mediações / Claims abertas (0-15 pts) ──
            $claimsScore = 15; // assume perfeito
            try {
                // Endpoint correto: /post-purchase/v1/claims/search (sem /search → 404)
                $claimsParams = [
                    'status' => 'opened',
                    'limit'  => 50,
                    'offset' => 0,
                ];
                try {
                    $me = $this->client->get('/users/me', [], 600);
                    if (isset($me['id']) && $me['id'] !== '' && $me['id'] !== null) {
                        $claimsParams['players.user_id'] = (string) $me['id'];
                        $claimsParams['players.role'] = 'respondent';
                    }
                } catch (\Throwable) {
                    // segue sem filtro de player
                }
                $claimsResult = $this->client->get('/post-purchase/v1/claims/search', $claimsParams, 600);
                $allClaims = $claimsResult['data'] ?? $claimsResult['results'] ?? [];
                $totalClaims = (int)($claimsResult['paging']['total'] ?? count($allClaims));

                $openClaims = 0;
                $closedFavorSeller = 0;
                $closedAgainstSeller = 0;
                $recentClaimReasons = [];

                foreach ($allClaims as $claim) {
                    $status = $claim['status'] ?? '';
                    $resolution = $claim['resolution'] ?? $claim['close_resolution'] ?? '';

                    if (in_array($status, ['opened', 'in_process', 'waiting'])) {
                        $openClaims++;
                    }
                    if ($resolution === 'favour_seller' || $resolution === 'not_applicable') {
                        $closedFavorSeller++;
                    } elseif (in_array($resolution, ['favour_buyer', 'favour_complainant'])) {
                        $closedAgainstSeller++;
                    }

                    $reason = $claim['reason_id'] ?? $claim['reason'] ?? 'unknown';
                    $recentClaimReasons[$reason] = ($recentClaimReasons[$reason] ?? 0) + 1;
                }

                $details['open_claims'] = $openClaims;
                $details['total_claims'] = $totalClaims;
                $details['claims_favor_seller'] = $closedFavorSeller;
                $details['claims_against_seller'] = $closedAgainstSeller;
                $details['claim_reasons'] = $recentClaimReasons;

                $claimsScore = match (true) {
                    $openClaims === 0 && $totalClaims <= 2 => 15,
                    $openClaims === 0                      => 12,
                    $openClaims <= 2                        => 8,
                    $openClaims <= 5                        => 4,
                    default                                 => 0,
                };

                if ($openClaims > 0) {
                    $issues[] = [
                        'type'     => 'open_claims',
                        'severity' => $openClaims > 3 ? 'critical' : 'warning',
                        'message'  => "{$openClaims} mediações/reclamações abertas",
                        'impact'   => 'Mediações abertas podem congelar valores e prejudicar a reputação',
                        'action'   => 'Resolva mediações rapidamente — ofereça reembolso ou reenvio quando cabível',
                        'count'    => $openClaims,
                    ];
                }

                if ($closedAgainstSeller > 3) {
                    $issues[] = [
                        'type'     => 'claims_lost',
                        'severity' => $closedAgainstSeller > 10 ? 'critical' : 'warning',
                        'message'  => "{$closedAgainstSeller} mediações decididas contra você",
                        'impact'   => 'Perder mediações afeta diretamente a reputação e pode causar restrições',
                        'action'   => 'Analise os motivos recorrentes e corrija a causa raiz',
                    ];
                }

                // Motivo mais frequente como insight
                if (!empty($recentClaimReasons)) {
                    arsort($recentClaimReasons);
                    $topReason = array_key_first($recentClaimReasons);
                    $details['top_claim_reason'] = $topReason;
                    $details['top_claim_reason_count'] = $recentClaimReasons[$topReason];
                }
            } catch (\Exception $e) {
                log_warning('Falha ao buscar reclamações', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
                // Manter score parcial — não penalizar se API indisponível
                $claimsScore = 10;
            }

            // ── Itens com infrações (0-15 pts) ──
            $complianceScore = 15; // assume perfeito
            try {
                // Itens inativos (pode incluir moderação, deleteção, etc.)
                $inactiveResult = $this->client->get("/users/{$sellerId}/items/search", [
                    'status' => 'inactive',
                    'limit'  => 1,
                ], 600);
                $inactiveTotal = (int)($inactiveResult['paging']['total'] ?? 0);
                $details['inactive_items'] = $inactiveTotal;

                // Itens em revisão (under_review = infrações pendentes)
                $reviewResult = $this->client->get("/users/{$sellerId}/items/search", [
                    'status' => 'under_review',
                    'limit'  => 1,
                ], 600);
                $underReviewTotal = (int)($reviewResult['paging']['total'] ?? 0);
                $details['under_review_items'] = $underReviewTotal;

                $problemItems = $underReviewTotal + min($inactiveTotal, 50); // cap para não penalizar demais
                $complianceScore = match (true) {
                    $problemItems === 0           => 15,
                    $underReviewTotal === 0 && $inactiveTotal <= 5 => 12,
                    $underReviewTotal <= 2        => 8,
                    $underReviewTotal <= 5        => 4,
                    default                       => 0,
                };

                if ($underReviewTotal > 0) {
                    $issues[] = [
                        'type'     => 'under_review_items',
                        'severity' => $underReviewTotal > 3 ? 'critical' : 'warning',
                        'message'  => "{$underReviewTotal} anúncios em revisão por possíveis infrações",
                        'impact'   => 'Infrações recorrentes podem levar a restrições na conta',
                        'action'   => 'Verifique a central do vendedor e corrija os anúncios sinalizados',
                        'count'    => $underReviewTotal,
                    ];
                }

                if ($inactiveTotal > 10) {
                    $issues[] = [
                        'type'     => 'many_inactive',
                        'severity' => 'info',
                        'message'  => "{$inactiveTotal} anúncios inativos no catálogo",
                        'impact'   => 'Muitos inativos podem indicar problemas recorrentes de compliance',
                        'action'   => 'Revise os anúncios inativos — republicar ou excluir os irrelevantes',
                        'count'    => $inactiveTotal,
                    ];
                }
            } catch (\Exception $e) {
                log_warning('Falha ao verificar itens de compliance', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
                $complianceScore = 10;
            }

            $score = min(100, $delayScore + $questionsScore + $shippingScore + $ordersScore + $claimsScore + $complianceScore);

            // Score breakdown (transparência)
            $details['score_breakdown'] = [
                'delay'      => ['score' => $delayScore, 'max' => 20, 'label' => 'Prazo de Envio'],
                'questions'  => ['score' => $questionsScore, 'max' => 15, 'label' => 'Atendimento'],
                'shipping'   => ['score' => $shippingScore, 'max' => 20, 'label' => 'Logística/Full'],
                'orders'     => ['score' => $ordersScore, 'max' => 15, 'label' => 'Taxa Cancelamento'],
                'claims'     => ['score' => $claimsScore, 'max' => 15, 'label' => 'Mediações'],
                'compliance' => ['score' => $complianceScore, 'max' => 15, 'label' => 'Conformidade'],
            ];

            // Cancel rate for frontend
            $details['cancel_rate'] = isset($details['orders_30d']) && $details['orders_30d'] > 0
                ? round(($details['cancelled_30d'] ?? 0) / $details['orders_30d'] * 100, 1)
                : 0;
        } catch (\Exception $e) {
            log_error('Erro no pilar Operação', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyPillar('operation', 'Erro ao analisar operação');
        }

        return [
            'name'    => 'Operação',
            'icon'    => 'bi-truck',
            'score'   => $score,
            'level'   => $this->getScoreLevel($score),
            'details' => $details,
            'issues'  => $issues,
        ];
    }

    // =========================================================================
    // PILAR 5: VENDAS & VISITAS
    // =========================================================================

    public function getSalesPillar(): array
    {
        $score = 0;
        $details = [];
        $issues = [];

        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptyPillar('sales', 'Conta não conectada');
            }

            // Vendas do DB local (30 dias vs 30 dias anteriores)
            $salesData = $this->getSalesComparison();
            $details = array_merge($details, $salesData);

            // Visitas (últimos 30 dias) - resposta é objeto com total_visits e results[]
            try {
                $visitsData = $this->client->get("/users/{$sellerId}/items_visits/time_window", [
                    'last' => 30,
                    'unit' => 'day',
                ]);
                $totalVisits = $visitsData['total_visits'] ?? 0;
                if ($totalVisits === 0 && !empty($visitsData['results'])) {
                    foreach ($visitsData['results'] as $dayData) {
                        $totalVisits += $dayData['total'] ?? 0;
                    }
                }
                $details['visits_30d'] = $totalVisits;
            } catch (\Exception $e) {
                $details['visits_30d'] = 0;
            }

            // Conversão
            $sales30d = $details['sales_30d'] ?? 0;
            $visits30d = $details['visits_30d'] ?? 1;
            $conversionRate = $visits30d > 0 ? ($sales30d / $visits30d) * 100 : 0;
            $details['conversion_rate'] = round($conversionRate, 2);

            // Score baseado na tendência
            $salesGrowth = $details['sales_growth'] ?? 0;
            $revenue30d = $details['revenue_30d'] ?? 0;

            // Growth score (0-40 pts)
            $growthScore = match (true) {
                $salesGrowth > 20  => 40,
                $salesGrowth > 5   => 30,
                $salesGrowth > -5  => 20,
                $salesGrowth > -20 => 10,
                default            => 0,
            };

            // Volume score (0-30 pts) - baseado no volume absoluto
            $volumeScore = match (true) {
                $sales30d >= 100 => 30,
                $sales30d >= 50  => 25,
                $sales30d >= 20  => 20,
                $sales30d >= 10  => 15,
                $sales30d >= 5   => 10,
                $sales30d > 0    => 5,
                default          => 0,
            };

            // Conversion score (0-30 pts)
            $conversionScore = match (true) {
                $conversionRate >= 5  => 30,
                $conversionRate >= 3  => 25,
                $conversionRate >= 2  => 20,
                $conversionRate >= 1  => 15,
                $conversionRate >= 0.5 => 8,
                default               => 0,
            };

            $score = min(100, $growthScore + $volumeScore + $conversionScore);

            // Issues
            if ($salesGrowth < -10) {
                $issues[] = [
                    'type'     => 'sales_declining',
                    'severity' => $salesGrowth < -30 ? 'critical' : 'warning',
                    'message'  => "Vendas caíram {$details['sales_growth']}% nos últimos 30 dias",
                    'impact'   => 'Queda sustentada reduz relevância e exposição no ML',
                    'action'   => 'Revise preços, ative promoções e melhore títulos',
                ];
            }

            if ($conversionRate < 1 && $visits30d > 100) {
                $issues[] = [
                    'type'     => 'low_conversion',
                    'severity' => 'warning',
                    'message'  => "Taxa de conversão baixa: {$details['conversion_rate']}%",
                    'impact'   => 'Visitantes chegam mas não compram',
                    'action'   => 'Melhore fotos, descrição, preço e ofereça frete grátis',
                ];
            }

            // Ticket médio vs período anterior
            $avgTicket = $details['avg_ticket'] ?? 0;
            $prevRevenue = $details['revenue_prev_30d'] ?? 0;
            $prevCount = $details['sales_prev_30d'] ?? 0;
            $prevTicket = $prevCount > 0 ? $prevRevenue / $prevCount : 0;
            if ($prevTicket > 0 && $avgTicket < $prevTicket * 0.8) {
                $ticketDrop = round((1 - $avgTicket / $prevTicket) * 100, 1);
                $issues[] = [
                    'type'     => 'ticket_declining',
                    'severity' => $ticketDrop > 30 ? 'warning' : 'info',
                    'message'  => "Ticket médio caiu {$ticketDrop}% (R$ " . number_format($avgTicket, 2, ',', '.') . " vs R$ " . number_format($prevTicket, 2, ',', '.') . ")",
                    'impact'   => 'Clientes estão comprando itens mais baratos — pode indicar perda de competitividade nos produtos premium',
                    'action'   => 'Verifique se concorrentes estão oferecendo preços melhores nos seus itens de maior valor',
                ];
            }

            // Revenue per active item (produtividade do catálogo)
            $activeItems = $details['total_active_items'] ?? 0;
            if ($activeItems > 0) {
                $revenuePerItem = $revenue30d / $activeItems;
                $details['revenue_per_item'] = round($revenuePerItem, 2);
            }

            if ($visits30d < 50 && $details['total_active_items'] > 0) {
                $avgVisitsPerItem = $visits30d / max(1, $details['total_active_items']);
                if ($avgVisitsPerItem < 2) {
                    $issues[] = [
                        'type'     => 'low_visibility',
                        'severity' => 'critical',
                        'message'  => "Média de apenas " . round($avgVisitsPerItem, 1) . " visitas/anúncio em 30 dias",
                        'impact'   => 'Seus anúncios não estão aparecendo nas buscas',
                        'action'   => 'Otimize títulos com palavras-chave e ative Ads do ML',
                    ];
                }
            }

            // Penalidade por anúncios parados (afeta score de vendas)
            try {
                $stale = $this->cachedStaleListings ?? $this->getStaleListings();
                $stalePct = $stale['summary']['stale_percent'] ?? 0;
                $staleCount = $stale['summary']['total_stale'] ?? 0;

                if ($staleCount > 0) {
                    // Penalizar score: -5 a -25 pts dependendo da %
                    $penalty = match (true) {
                        $stalePct >= 50 => 25,
                        $stalePct >= 30 => 15,
                        $stalePct >= 15 => 10,
                        default         => 5,
                    };
                    $score = max(0, $score - $penalty);
                    $details['stale_count'] = $staleCount;
                    $details['stale_percent'] = $stalePct;

                    $issues[] = [
                        'type'     => 'stale_listings',
                        'severity' => $stalePct >= 30 ? 'critical' : 'warning',
                        'message'  => "{$staleCount} anúncios parados ({$stalePct}% do catálogo)",
                        'impact'   => 'Anúncios sem vendas reduzem a taxa de conversão geral e prejudicam o ranking de todos os seus anúncios',
                        'action'   => 'Pause anúncios sem vendas há 90+ dias e otimize os de 60-90 dias',
                        'count'    => $staleCount,
                    ];
                }
            } catch (\Exception $e) {
                log_warning('Falha ao calcular penalidade de anúncios parados', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            log_error('Erro no pilar Vendas', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyPillar('sales', 'Erro ao analisar vendas');
        }

        return [
            'name'    => 'Vendas',
            'icon'    => 'bi-cash-stack',
            'score'   => $score,
            'level'   => $this->getScoreLevel($score),
            'details' => $details,
            'issues'  => $issues,
        ];
    }

    /**
     * Comparação de vendas via ML Orders API: últimos 30 dias vs 30 dias anteriores
     */
    private function getSalesComparison(): array
    {
        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptySalesData();
            }

            $now = time();
            $since30d = date('Y-m-d\TH:i:s', $now - 30 * 86400) . '.000-00:00';
            $since60d = date('Y-m-d\TH:i:s', $now - 60 * 86400) . '.000-00:00';
            $until30d = date('Y-m-d\TH:i:s', $now - 30 * 86400) . '.000-00:00';

            // Últimos 30 dias - pedidos pagos
            $current = $this->fetchOrderStats($sellerId, $since30d, null);

            // 30 dias anteriores (60d - 30d atrás)
            $previous = $this->fetchOrderStats($sellerId, $since60d, $until30d);

            // Total de itens ativos (cache compartilhado)
            $activeItems = $this->cachedTotalActive ?? 0;
            if ($activeItems === 0) {
                $activeData = $this->getCachedActiveItems();
                $activeItems = $activeData['total'];
            }

            $currentCount = $current['count'];
            $previousCount = $previous['count'];
            $currentRevenue = $current['revenue'];
            $previousRevenue = $previous['revenue'];

            $salesGrowth = $previousCount > 0
                ? round((($currentCount - $previousCount) / $previousCount) * 100, 1)
                : ($currentCount > 0 ? 100 : 0);

            $revenueGrowth = $previousRevenue > 0
                ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1)
                : ($currentRevenue > 0 ? 100 : 0);

            return [
                'sales_30d'          => $currentCount,
                'sales_prev_30d'     => $previousCount,
                'revenue_30d'        => round($currentRevenue, 2),
                'revenue_prev_30d'   => round($previousRevenue, 2),
                'avg_ticket'         => $currentCount > 0 ? round($currentRevenue / $currentCount, 2) : 0,
                'sales_growth'       => $salesGrowth,
                'revenue_growth'     => $revenueGrowth,
                'total_active_items' => $activeItems,
            ];
        } catch (\Exception $e) {
            log_error('Erro na comparação de vendas', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptySalesData();
        }
    }

    /**
     * Buscar estatísticas de pedidos via ML Orders API
     */
    private function fetchOrderStats(string $sellerId, string $dateFrom, ?string $dateTo): array
    {
        try {
            $params = [
                'seller' => $sellerId,
                'order.status' => 'paid',
                'order.date_created.from' => $dateFrom,
                'limit' => 50,
                'offset' => 0,
            ];
            if ($dateTo) {
                $params['order.date_created.to'] = $dateTo;
            }

            // Primeiro request para pegar o total
            $response = $this->client->get('/orders/search', $params);

            if (
                isset($response['error'])
                && $response['error'] === 'orders_access_unavailable'
                && ($response['feature'] ?? null) === 'orders'
                && ($response['optional_feature'] ?? false) === true
            ) {
                log_info('AccountHealthService: orders capability unavailable — returning zero stats', [
                    'seller_id' => $sellerId,
                ]);
                return ['count' => 0, 'revenue' => 0];
            }

            $totalOrders = (int)($response['paging']['total'] ?? 0);

            // Calcular receita dos primeiros resultados
            $revenue = 0;
            $fetched = 0;
            $results = $response['results'] ?? [];

            foreach ($results as $order) {
                $revenue += (float)($order['total_amount'] ?? 0);
                $fetched++;
            }

            // Se tem mais pedidos, buscar em lotes (max 200 para não sobrecarregar)
            $maxFetch = min($totalOrders, 200);
            while ($fetched < $maxFetch) {
                $params['offset'] = $fetched;
                $response = $this->client->get('/orders/search', $params);
                $results = $response['results'] ?? [];
                if (empty($results)) {
                    break;
                }
                foreach ($results as $order) {
                    $revenue += (float)($order['total_amount'] ?? 0);
                    $fetched++;
                }
            }

            // Se total > 200, estimar receita proporcionalmente
            if ($totalOrders > $maxFetch && $fetched > 0) {
                $avgPerOrder = $revenue / $fetched;
                $revenue = $avgPerOrder * $totalOrders;
            }

            return ['count' => $totalOrders, 'revenue' => $revenue];
        } catch (\Exception $e) {
            return ['count' => 0, 'revenue' => 0];
        }
    }

    private function emptySalesData(): array
    {
        return [
            'sales_30d'          => 0,
            'sales_prev_30d'     => 0,
            'revenue_30d'        => 0,
            'revenue_prev_30d'   => 0,
            'avg_ticket'         => 0,
            'sales_growth'       => 0,
            'revenue_growth'     => 0,
            'total_active_items' => 0,
        ];
    }

    // =========================================================================
    // ANÚNCIOS PARADOS (STALE LISTINGS)
    // =========================================================================

    /**
     * Detecta anúncios ativos sem vendas há muito tempo.
     *
     * Anúncios parados prejudicam a conta inteira:
     * - Reduzem taxa de conversão geral → pior posicionamento
     * - Diluem métricas de saúde da conta
     * - ML interpreta como catálogo de baixa qualidade
     * - Afetam negativamente o ranking de TODOS os anúncios
     *
     * @return array{summary: array, items: array, impact: array}
     */
    public function getStaleListings(): array
    {
        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptyStaleListings();
            }

            // Buscar IDs dos itens ativos (até 200 para análise)
            // Reutilizar cache dos primeiros 50 itens
            $cachedActive = $this->getCachedActiveItems();
            $activeItemIds = $cachedActive['ids'];
            $totalActive = $cachedActive['total'];
            $maxFetch = 200;

            // Se há mais de 50 itens, buscar os restantes
            $offset = count($activeItemIds);
            while ($offset < $maxFetch && $offset < $totalActive) {
                $response = $this->client->get("/users/{$sellerId}/items/search", [
                    'status' => 'active',
                    'limit'  => 50,
                    'offset' => $offset,
                ]);

                $ids = $response['results'] ?? [];
                if (empty($ids)) {
                    break;
                }

                $activeItemIds = array_merge($activeItemIds, $ids);
                $offset += 50;
            }

            if (empty($activeItemIds)) {
                return $this->emptyStaleListings();
            }

            // Buscar detalhes dos itens em lotes de 20 (multiget)
            $staleItems = [];
            $warningItems = [];
            $now = time();
            $staleThreshold = 90 * 86400;    // 90 dias = crítico
            $warningThreshold = 60 * 86400;  // 60 dias = alerta

            $batches = array_chunk($activeItemIds, 20);
            foreach ($batches as $batch) {
                $idsParam = implode(',', $batch);
                try {
                    $itemsData = $this->client->get('/items', [
                        'ids'        => $idsParam,
                        'attributes' => 'id,title,price,thumbnail,date_created,sold_quantity,available_quantity,permalink,listing_type_id,category_id,health,shipping,catalog_product_id',
                    ]);

                    foreach ($itemsData as $wrapper) {
                        $item = $wrapper['body'] ?? $wrapper;
                        if (empty($item['id'])) {
                            continue;
                        }

                        $dateCreated = strtotime($item['date_created'] ?? 'now');
                        $daysActive = (int)(($now - $dateCreated) / 86400);
                        $soldQty = (int)($item['sold_quantity'] ?? 0);

                        // Item é "parado" se ativo há mais de X dias com 0 vendas
                        if ($soldQty === 0 && ($now - $dateCreated) >= $warningThreshold) {
                            $itemData = [
                                'id'            => $item['id'],
                                'title'         => $item['title'] ?? '',
                                'price'         => (float)($item['price'] ?? 0),
                                'thumbnail'     => $item['thumbnail'] ?? '',
                                'days_active'   => $daysActive,
                                'sold_quantity'  => $soldQty,
                                'available_qty' => (int)($item['available_quantity'] ?? 0),
                                'permalink'     => $item['permalink'] ?? '',
                                'listing_type'  => $item['listing_type_id'] ?? '',
                                'category_id'   => $item['category_id'] ?? '',
                                'health'        => $item['health'] ?? null,
                            ];

                            // Diagnóstico de causa raiz
                            $causes = [];
                            $lt = $item['listing_type_id'] ?? '';
                            if (!in_array($lt, ['gold_pro', 'gold_special'])) {
                                $causes[] = 'tipo_anuncio';
                            }
                            if (!($item['shipping']['free_shipping'] ?? false)) {
                                $causes[] = 'sem_frete_gratis';
                            }
                            $h = $item['health'] ?? null;
                            if ($h !== null && (float)$h < 0.5) {
                                $causes[] = 'saude_baixa';
                            }
                            if (empty($item['catalog_product_id'])) {
                                $causes[] = 'fora_catalogo';
                            }
                            $itemData['causes'] = $causes;

                            if (($now - $dateCreated) >= $staleThreshold) {
                                $itemData['severity'] = 'critical';
                                $staleItems[] = $itemData;
                            } else {
                                $itemData['severity'] = 'warning';
                                $warningItems[] = $itemData;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    log_warning('Falha no multiget de anúncios parados', [
                        'account_id' => $this->accountId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Ordenar por dias ativos (mais antigos primeiro)
            usort($staleItems, fn($a, $b) => $b['days_active'] <=> $a['days_active']);
            usort($warningItems, fn($a, $b) => $b['days_active'] <=> $a['days_active']);

            // Combinar: críticos primeiro, depois alertas
            $allStale = array_merge($staleItems, $warningItems);

            // Calcular impacto na conta
            $staleCount = count($staleItems);
            $warningCount = count($warningItems);
            $totalStale = $staleCount + $warningCount;
            $stalePercent = $totalActive > 0
                ? round(($totalStale / $totalActive) * 100, 1)
                : 0;

            // Valor parado (soma dos preços × estoque)
            $frozenValue = 0;
            $causeCounts = [];
            foreach ($allStale as $item) {
                $frozenValue += $item['price'] * max(1, $item['available_qty']);
                foreach ($item['causes'] ?? [] as $cause) {
                    $causeCounts[$cause] = ($causeCounts[$cause] ?? 0) + 1;
                }
            }
            arsort($causeCounts);

            // Nível de impacto na conta
            $impactLevel = match (true) {
                $stalePercent >= 50 => 'critical',
                $stalePercent >= 30 => 'warning',
                $stalePercent >= 15 => 'moderate',
                default             => 'low',
            };

            return [
                'summary' => [
                    'total_active'     => $totalActive,
                    'total_stale'      => $totalStale,
                    'critical_count'   => $staleCount,
                    'warning_count'    => $warningCount,
                    'stale_percent'    => $stalePercent,
                    'frozen_value'     => round($frozenValue, 2),
                    'impact_level'     => $impactLevel,
                    'cause_counts'     => $causeCounts,
                ],
                'items'  => array_slice($allStale, 0, 50), // Top 50 para exibição
                'impact' => [
                    'conversion_dilution' => $stalePercent >= 15,
                    'ranking_penalty'     => $stalePercent >= 30,
                    'account_health_risk' => $stalePercent >= 50,
                    'recommendation'      => $this->getStaleRecommendation($stalePercent, $totalStale),
                ],
            ];
        } catch (\Exception $e) {
            log_error('Erro ao analisar anúncios parados', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyStaleListings();
        }
    }

    /**
     * Retorna recomendação baseada na % de anúncios parados
     */
    private function getStaleRecommendation(float $percent, int $total): string
    {
        if ($total === 0) {
            return 'Nenhum anúncio parado detectado. Excelente!';
        }

        return match (true) {
            $percent >= 50 => "URGENTE: {$percent}% dos seus anúncios estão parados! Pause ou republique agora para salvar a conta.",
            $percent >= 30 => "ATENÇÃO: {$percent}% de anúncios parados. Isso está prejudicando seriamente seu posicionamento.",
            $percent >= 15 => "Recomendado: Revise os {$total} anúncios parados. Considere pausar ou otimizar.",
            default        => "{$total} anúncios sem vendas há mais de 60 dias. Monitore e otimize.",
        };
    }

    private function emptyStaleListings(): array
    {
        return [
            'summary' => [
                'total_active'   => 0,
                'total_stale'    => 0,
                'critical_count' => 0,
                'warning_count'  => 0,
                'stale_percent'  => 0,
                'frozen_value'   => 0,
                'impact_level'   => 'low',
            ],
            'items'  => [],
            'impact' => [
                'conversion_dilution' => false,
                'ranking_penalty'     => false,
                'account_health_risk' => false,
                'recommendation'      => 'Sem dados disponíveis',
            ],
        ];
    }

    // =========================================================================
    // ITENS QUE PRECISAM DE ATENÇÃO
    // =========================================================================

    public function getItemsNeedingAttention(): array
    {
        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return [];
            }

            $items = [];

            // Itens pausados
            try {
                $paused = $this->client->get("/users/{$sellerId}/items/search", [
                    'status' => 'paused',
                    'limit'  => 10,
                ]);
                $pausedTotal = $paused['paging']['total'] ?? 0;
                if ($pausedTotal > 0) {
                    $items[] = [
                        'type'    => 'paused_items',
                        'count'   => $pausedTotal,
                        'message' => "{$pausedTotal} anúncios pausados",
                        'action'  => 'Reative anúncios pausados para não perder vendas',
                        'icon'    => 'bi-pause-circle',
                        'severity' => 'warning',
                    ];
                }
            } catch (\Exception $e) {
                log_warning('Falha ao verificar itens pausados', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Itens sem estoque
            try {
                $noStock = $this->client->get("/users/{$sellerId}/items/search", [
                    'status'    => 'active',
                    'available_quantity' => '0',
                    'limit'     => 1,
                ]);
                $noStockTotal = $noStock['paging']['total'] ?? 0;
                if ($noStockTotal > 0) {
                    $items[] = [
                        'type'    => 'no_stock',
                        'count'   => $noStockTotal,
                        'message' => "{$noStockTotal} anúncios ativos sem estoque",
                        'action'  => 'Atualize o estoque ou pause os anúncios',
                        'icon'    => 'bi-box-seam',
                        'severity' => 'critical',
                    ];
                }
            } catch (\Exception $e) {
                log_warning('Falha ao verificar itens sem estoque', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Itens under_review
            try {
                $underReview = $this->client->get("/users/{$sellerId}/items/search", [
                    'status' => 'under_review',
                    'limit'  => 1,
                ]);
                $reviewTotal = $underReview['paging']['total'] ?? 0;
                if ($reviewTotal > 0) {
                    $items[] = [
                        'type'    => 'under_review',
                        'count'   => $reviewTotal,
                        'message' => "{$reviewTotal} anúncios em revisão pelo ML",
                        'action'  => 'Verifique se há infrações ou informações pendentes',
                        'icon'    => 'bi-exclamation-triangle',
                        'severity' => 'warning',
                    ];
                }
            } catch (\Exception $e) {
                log_warning('Falha ao verificar itens em revisão', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Anúncios parados (sem vendas há 60+ dias)
            try {
                $stale = $this->cachedStaleListings ?? $this->getStaleListings();
                $staleTotal = $stale['summary']['total_stale'] ?? 0;
                if ($staleTotal > 0) {
                    $criticalStale = $stale['summary']['critical_count'] ?? 0;
                    $severity = $criticalStale > 0 ? 'critical' : 'warning';
                    $pct = $stale['summary']['stale_percent'] ?? 0;
                    $items[] = [
                        'type'    => 'stale_listings',
                        'count'   => $staleTotal,
                        'message' => "{$staleTotal} anúncios sem vendas há 60+ dias ({$pct}% do catálogo)",
                        'action'  => 'Pause, republique ou otimize — anúncios parados prejudicam toda a conta',
                        'icon'    => 'bi-hourglass-bottom',
                        'severity' => $severity,
                    ];
                }
            } catch (\Exception $e) {
                log_warning('Falha ao verificar anúncios parados para atenção', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Itens com estoque baixo (≤ 3 unidades)
            try {
                $activeData = $this->getCachedActiveItems();
                $activeIds = $activeData['ids'] ?? [];
                if (!empty($activeIds)) {
                    $lowStockCount = 0;
                    $sampleBatch = array_slice($activeIds, 0, 20);
                    $idsParam = implode(',', $sampleBatch);
                    $batchItems = $this->client->get('/items', [
                        'ids'        => $idsParam,
                        'attributes' => 'id,available_quantity',
                    ]);
                    foreach ($batchItems as $wrapper) {
                        $body = $wrapper['body'] ?? $wrapper;
                        $qty = (int)($body['available_quantity'] ?? 0);
                        if ($qty > 0 && $qty <= 3) {
                            $lowStockCount++;
                        }
                    }
                    // Extrapolar para o total
                    $totalActive = $activeData['total'] ?? count($activeIds);
                    if (count($sampleBatch) > 0 && $totalActive > count($sampleBatch)) {
                        $lowStockCount = (int) round($lowStockCount * $totalActive / count($sampleBatch));
                    }
                    if ($lowStockCount > 0) {
                        $items[] = [
                            'type'     => 'low_stock',
                            'count'    => $lowStockCount,
                            'message'  => "~{$lowStockCount} anúncios com estoque baixo (≤ 3 unidades)",
                            'action'   => 'Reponha estoque para não perder vendas quando acabar',
                            'icon'     => 'bi-exclamation-diamond',
                            'severity' => $lowStockCount > 10 ? 'warning' : 'info',
                        ];
                    }
                }
            } catch (\Exception $e) {
                log_warning('Falha ao verificar estoque baixo', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }

            return $items;
        } catch (\Exception $e) {
            log_error('Erro ao buscar itens que precisam de atenção', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // =========================================================================
    // GERADOR DE AÇÕES PRIORITÁRIAS
    // =========================================================================

    private function generateActionItems(array $pillars): array
    {
        $actions = [];
        $seenTypes = [];

        foreach ($pillars as $pillarKey => $pillar) {
            $pillarWeight = self::PILLAR_WEIGHTS[$pillarKey] ?? 10;
            $pillarScore = $pillar['score'] ?? 0;
            // Potencial de ganho: quanto menor o score do pilar, mais impactante resolver
            $pillarGap = 100 - $pillarScore;

            foreach ($pillar['issues'] ?? [] as $issue) {
                $type = $issue['type'] ?? '';

                // Deduplicar issues do mesmo tipo entre pilares
                if ($type && isset($seenTypes[$type])) {
                    continue;
                }
                if ($type) {
                    $seenTypes[$type] = true;
                }

                $severityMultiplier = match ($issue['severity']) {
                    'critical' => 3,
                    'warning'  => 2,
                    'info'     => 1,
                    default    => 1,
                };

                // Impact score: combina peso do pilar, gap e severidade
                // Range: 0-100 (maior = mais impactante)
                $impactScore = (int) min(100, round(
                    ($pillarWeight / 25) * ($pillarGap / 100) * $severityMultiplier * 33
                ));

                $actions[] = [
                    'pillar'       => $pillarKey,
                    'pillar_name'  => $pillar['name'] ?? $pillarKey,
                    'type'         => $type,
                    'severity'     => $issue['severity'],
                    'message'      => $issue['message'],
                    'impact'       => $issue['impact'],
                    'action'       => $issue['action'],
                    'count'        => $issue['count'] ?? 0,
                    'priority'     => $this->getActionPriority($issue['severity']),
                    'impact_score' => $impactScore,
                ];
            }
        }

        // Ordenar por impact_score descendente (maior impacto primeiro)
        usort($actions, fn($a, $b) => $b['impact_score'] <=> $a['impact_score']);

        return $actions;
    }

    private function getActionPriority(string $severity): int
    {
        return match ($severity) {
            'critical' => 1,
            'warning'  => 2,
            'info'     => 3,
            default    => 4,
        };
    }

    /**
     * Gera insights cruzando dados de múltiplos pilares.
     * Identifica correlações e oportunidades que não aparecem em pilares isolados.
     */
    private function generateCrossPillarInsights(array $pillars): array
    {
        $insights = [];

        $seoDetails = $pillars['seo_quality']['details'] ?? [];
        $compDetails = $pillars['competitiveness']['details'] ?? [];
        $salesDetails = $pillars['sales']['details'] ?? [];
        $opsDetails = $pillars['operation']['details'] ?? [];

        // SEO ruim + muitas visitas = oportunidade de conversão
        $seoScore = $pillars['seo_quality']['score'] ?? 0;
        $visits = $salesDetails['visits_30d'] ?? 0;
        if ($seoScore < 60 && $visits > 200) {
            $insights[] = [
                'type'    => 'seo_conversion_opportunity',
                'icon'    => 'bi-lightbulb',
                'color'   => 'warning',
                'title'   => 'Oportunidade: visitantes chegam mas SEO está fraco',
                'message' => "Você tem {$visits} visitas/mês mas SEO score é {$seoScore}/100. Melhorar títulos e fotos pode converter essas visitas em vendas.",
                'pillars' => ['seo_quality', 'sales'],
            ];
        }

        // Muitos itens fora do catálogo + competitividade baixa
        $nonCatalog = $seoDetails['non_catalog_items'] ?? 0;
        $compScore = $pillars['competitiveness']['score'] ?? 0;
        if ($nonCatalog > 5 && $compScore < 60) {
            $insights[] = [
                'type'    => 'catalog_competitiveness',
                'icon'    => 'bi-book-half',
                'color'   => 'info',
                'title'   => 'Catálogo ML pode impulsionar competitividade',
                'message' => "{$nonCatalog} anúncios fora do catálogo oficial ML. Vincular ao catálogo aumenta exposição e elegibilidade para compra conjunta.",
                'pillars' => ['seo_quality', 'competitiveness'],
            ];
        }

        // Vendas caindo + operação com problemas
        $salesGrowth = $salesDetails['sales_growth'] ?? 0;
        $opsScore = $pillars['operation']['score'] ?? 0;
        if ($salesGrowth < -10 && $opsScore < 70) {
            $insights[] = [
                'type'    => 'sales_ops_correlation',
                'icon'    => 'bi-arrow-down-circle',
                'color'   => 'danger',
                'title'   => 'Queda de vendas + problemas operacionais',
                'message' => "Vendas caíram {$salesGrowth}% e score operacional é {$opsScore}/100. Problemas como atrasos e perguntas sem resposta podem estar afetando diretamente as vendas.",
                'pillars' => ['sales', 'operation'],
            ];
        }

        // Alta conversão mas poucas visitas = precisa de mais tráfego
        $convRate = $salesDetails['conversion_rate'] ?? 0;
        if ($convRate >= 3 && $visits < 100) {
            $insights[] = [
                'type'    => 'traffic_opportunity',
                'icon'    => 'bi-megaphone',
                'color'   => 'success',
                'title'   => 'Boa conversão — precisa de mais tráfego!',
                'message' => "Taxa de conversão de {$convRate}% é excelente, mas apenas {$visits} visitas/mês. Invista em Ads do ML e otimize títulos para termos de busca populares.",
                'pillars' => ['sales', 'competitiveness'],
            ];
        }

        // Muitos anúncios parados + sem fulfillment
        $staleCount = $salesDetails['stale_count'] ?? 0;
        $hasFull = $opsDetails['fulfillment_enabled'] ?? false;
        if ($staleCount > 10 && !$hasFull) {
            $insights[] = [
                'type'    => 'stale_fulfillment',
                'icon'    => 'bi-box-seam',
                'color'   => 'warning',
                'title'   => 'Anúncios parados podem precisar de Full',
                'message' => "{$staleCount} anúncios sem vendas e Fulfillment não está ativo. Produtos no Full ganham selo de entrega rápida e melhor ranking.",
                'pillars' => ['sales', 'operation'],
            ];
        }

        // Mediações abertas + vendas caindo = risco de restrição
        $openClaims = $opsDetails['open_claims'] ?? 0;
        $repScore = $pillars['reputation']['score'] ?? 0;
        if ($openClaims > 2 && $repScore < 70) {
            $insights[] = [
                'type'    => 'claims_reputation_risk',
                'icon'    => 'bi-shield-exclamation',
                'color'   => 'danger',
                'title'   => 'Risco: mediações + reputação baixa',
                'message' => "{$openClaims} mediações abertas com reputação em {$repScore}/100. Resolva as mediações urgentemente para evitar restrições na conta.",
                'pillars' => ['operation', 'reputation'],
            ];
        }

        // Resposta lenta a perguntas + baixa conversão
        $avgResponse = $opsDetails['avg_response_time_min'] ?? 0;
        if ($avgResponse > 120 && $convRate < 2 && $visits > 50) {
            $hours = round($avgResponse / 60, 1);
            $insights[] = [
                'type'    => 'slow_response_conversion',
                'icon'    => 'bi-chat-dots',
                'color'   => 'warning',
                'title'   => 'Tempo de resposta pode estar custando vendas',
                'message' => "Tempo médio de {$hours}h para responder + conversão de apenas {$convRate}%. Compradores compram de quem responde primeiro.",
                'pillars' => ['operation', 'sales'],
            ];
        }

        // Itens em revisão + SEO fraco = problemas de compliance + qualidade
        $underReview = $opsDetails['under_review_items'] ?? 0;
        if ($underReview > 0 && $seoScore < 60) {
            $insights[] = [
                'type'    => 'review_seo_quality',
                'icon'    => 'bi-exclamation-octagon',
                'color'   => 'danger',
                'title'   => 'Anúncios sinalizados + SEO fraco',
                'message' => "{$underReview} anúncios em revisão e SEO score é {$seoScore}/100. Melhore a qualidade dos anúncios para evitar novas infrações.",
                'pillars' => ['operation', 'seo_quality'],
            ];
        }

        // Claims perdidas + avaliações negativas = problema sistêmico
        $claimsAgainst = $opsDetails['claims_against_seller'] ?? 0;
        $repDetails = $pillars['reputation']['details'] ?? [];
        $negativeRatings = $repDetails['negative_ratings'] ?? 0;
        if ($claimsAgainst > 3 && $negativeRatings > 5) {
            $insights[] = [
                'type'    => 'systemic_quality_issue',
                'icon'    => 'bi-bug',
                'color'   => 'danger',
                'title'   => 'Problema sistêmico: reclamações + avaliações negativas',
                'message' => "{$claimsAgainst} mediações perdidas e {$negativeRatings} avaliações negativas indicam problemas recorrentes com produtos ou atendimento.",
                'pillars' => ['operation', 'reputation'],
            ];
        }

        return $insights;
    }

    // =========================================================================
    // SCORE DELTAS (comparação com snapshot anterior)
    // =========================================================================

    /**
     * Busca o snapshot mais recente do histórico para calcular deltas.
     * Retorna null se não houver histórico suficiente.
     */
    private function getPreviousScores(): ?array
    {
        if (!$this->accountId) {
            return null;
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT overall_score, reputation_score, seo_score,
                        competitiveness_score, operation_score, sales_score,
                        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as date
                 FROM account_health_history
                 WHERE account_id = :aid
                   AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $stmt->execute(['aid' => $this->accountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            return [
                'overall'         => (int) $row['overall_score'],
                'reputation'      => (int) $row['reputation_score'],
                'seo_quality'     => (int) $row['seo_score'],
                'competitiveness' => (int) $row['competitiveness_score'],
                'operation'       => (int) $row['operation_score'],
                'sales'           => (int) $row['sales_score'],
                'date'            => $row['date'],
            ];
        } catch (\Exception $e) {
            log_warning('Falha ao buscar scores anteriores', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // ANÁLISE POR CATEGORIA (agrupa problemas por categoria ML)
    // =========================================================================

    /**
     * Agrupa dados de itens por categoria para identificar padrões sistêmicos.
     * Usa dados já disponíveis nos pilares (0 requests extras).
     */
    private function getCategoryBreakdown(array $pillars): array
    {
        try {
            $seoDetails = $pillars['seo_quality']['details'] ?? [];
            $worstItems = $seoDetails['worst_items'] ?? [];
            $compDetails = $pillars['competitiveness']['details'] ?? [];

            // Agrupar itens com problemas por categoria
            $categories = [];
            $sellerId = $this->getCachedSellerId();
            $activeData = $this->getCachedActiveItems();
            $itemIds = $activeData['ids'] ?? [];

            if (empty($itemIds)) {
                return [];
            }

            // Buscar dados mínimos dos itens já em cache (multiget)
            $batches = array_chunk($itemIds, 20);
            foreach (array_slice($batches, 0, 3) as $batch) {
                $idsParam = implode(',', $batch);
                try {
                    $itemsData = $this->client->get('/items', [
                        'ids'        => $idsParam,
                        'attributes' => 'id,category_id,sold_quantity,health,listing_type_id,shipping',
                    ]);

                    foreach ($itemsData as $wrapper) {
                        $item = $wrapper['body'] ?? $wrapper;
                        $catId = $item['category_id'] ?? '';
                        if (!$catId) {
                            continue;
                        }

                        if (!isset($categories[$catId])) {
                            $categories[$catId] = [
                                'category_id' => $catId,
                                'total_items' => 0,
                                'zero_sales'  => 0,
                                'low_health'  => 0,
                                'no_free_ship' => 0,
                                'not_premium' => 0,
                                'total_sales' => 0,
                            ];
                        }

                        $categories[$catId]['total_items']++;
                        $soldQty = (int) ($item['sold_quantity'] ?? 0);
                        $categories[$catId]['total_sales'] += $soldQty;
                        if ($soldQty === 0) {
                            $categories[$catId]['zero_sales']++;
                        }
                        $health = is_numeric($item['health'] ?? null) ? (float) $item['health'] : null;
                        if ($health !== null && $health < 0.7) {
                            $categories[$catId]['low_health']++;
                        }
                        if (!($item['shipping']['free_shipping'] ?? false)) {
                            $categories[$catId]['no_free_ship']++;
                        }
                        if (($item['listing_type_id'] ?? '') !== 'gold_pro') {
                            $categories[$catId]['not_premium']++;
                        }
                    }
                } catch (\Exception $e) {
                    // Não é crítico — continuar com dados parciais
                }
            }

            if (empty($categories)) {
                return [];
            }

            // Calcular issue_score por categoria (quanto maior, mais problemática)
            foreach ($categories as &$cat) {
                $total = max(1, $cat['total_items']);
                $cat['zero_sales_pct'] = round($cat['zero_sales'] / $total * 100, 1);
                $cat['issue_score'] = round(
                    ($cat['zero_sales'] / $total * 30) +
                        ($cat['low_health'] / $total * 25) +
                        ($cat['no_free_ship'] / $total * 25) +
                        ($cat['not_premium'] / $total * 20),
                    1
                );
                $cat['avg_sales'] = round($cat['total_sales'] / $total, 1);
            }
            unset($cat);

            // Buscar nomes das categorias (top 8)
            uasort($categories, fn($a, $b) => $b['issue_score'] <=> $a['issue_score']);
            $topCategories = array_slice($categories, 0, 8, true);

            foreach ($topCategories as $catId => &$cat) {
                try {
                    $catData = $this->client->get("/categories/{$catId}", [], 3600, true);
                    $cat['name'] = $catData['name'] ?? $catId;
                } catch (\Exception $e) {
                    $cat['name'] = $catId;
                }
            }
            unset($cat);

            return array_values($topCategories);
        } catch (\Exception $e) {
            log_error('Erro na análise por categoria', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // =========================================================================
    // RECUPERAÇÃO DE ITENS PAUSADOS
    // =========================================================================

    /**
     * Analisa itens pausados para estimar valor recuperável.
     * Itens pausados representam receita potencial perdida.
     */
    private function getPausedItemsRecovery(): array
    {
        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return ['total_paused' => 0, 'items' => [], 'recovery_value' => 0];
            }

            // Contar e buscar itens pausados
            $pausedResult = $this->client->get("/users/{$sellerId}/items/search", [
                'status' => 'paused',
                'limit'  => 50,
                'offset' => 0,
            ], 600);

            $totalPaused = (int) ($pausedResult['paging']['total'] ?? 0);
            $pausedIds = $pausedResult['results'] ?? [];

            if ($totalPaused === 0 || empty($pausedIds)) {
                return ['total_paused' => 0, 'items' => [], 'recovery_value' => 0];
            }

            // Buscar detalhes dos itens pausados (máx 20)
            $idsParam = implode(',', array_slice($pausedIds, 0, 20));
            $itemsData = $this->client->get('/items', [
                'ids'        => $idsParam,
                'attributes' => 'id,title,price,thumbnail,sold_quantity,available_quantity,permalink,listing_type_id,date_created,health,shipping',
            ]);

            $items = [];
            $totalRecoveryValue = 0;

            foreach ($itemsData as $wrapper) {
                $item = $wrapper['body'] ?? $wrapper;
                if (empty($item['id'])) {
                    continue;
                }

                $price = (float) ($item['price'] ?? 0);
                $soldQty = (int) ($item['sold_quantity'] ?? 0);
                $availQty = (int) ($item['available_quantity'] ?? 0);
                $dateCreated = strtotime($item['date_created'] ?? 'now');
                $daysExist = max(1, (int) ((time() - $dateCreated) / 86400));

                // Estimar vendas potenciais por mês baseado no histórico
                $salesPerMonth = $daysExist > 0 ? round($soldQty / ($daysExist / 30), 1) : 0;
                $monthlyRevenue = round($salesPerMonth * $price, 2);
                $totalRecoveryValue += $monthlyRevenue;

                // Avaliar se vale reativar
                $reactivateScore = 0;
                if ($soldQty > 10) {
                    $reactivateScore += 40;
                } elseif ($soldQty > 0) {
                    $reactivateScore += 20;
                }
                if ($availQty > 0) {
                    $reactivateScore += 30;
                }
                $health = is_numeric($item['health'] ?? null) ? (float) $item['health'] : null;
                if ($health !== null && $health >= 0.7) {
                    $reactivateScore += 30;
                } elseif ($health === null) {
                    $reactivateScore += 15;
                }

                $recommendation = match (true) {
                    $reactivateScore >= 70 => 'reactivate',
                    $reactivateScore >= 40 => 'optimize_then_reactivate',
                    $availQty === 0        => 'restock_needed',
                    default                => 'review',
                };

                $items[] = [
                    'id'                => $item['id'],
                    'title'             => $item['title'] ?? '',
                    'price'             => $price,
                    'thumbnail'         => $item['thumbnail'] ?? '',
                    'permalink'         => $item['permalink'] ?? '',
                    'sold_quantity'     => $soldQty,
                    'available_qty'     => $availQty,
                    'sales_per_month'   => $salesPerMonth,
                    'monthly_revenue'   => $monthlyRevenue,
                    'reactivate_score'  => $reactivateScore,
                    'recommendation'    => $recommendation,
                    'has_free_shipping' => $item['shipping']['free_shipping'] ?? false,
                    'listing_type'      => $item['listing_type_id'] ?? '',
                ];
            }

            // Ordenar por potencial de receita
            usort($items, fn($a, $b) => $b['monthly_revenue'] <=> $a['monthly_revenue']);

            // Extrapolar valor recuperável se tem mais de 20 pausados
            if ($totalPaused > count($items) && count($items) > 0) {
                $avgRecovery = $totalRecoveryValue / count($items);
                $totalRecoveryValue = round($avgRecovery * $totalPaused, 2);
            }

            return [
                'total_paused'   => $totalPaused,
                'items'          => array_slice($items, 0, 10),
                'recovery_value' => round($totalRecoveryValue, 2),
                'reactivatable'  => count(array_filter($items, fn(array $i): bool => $i['reactivate_score'] >= 70)),
                'needs_restock'  => count(array_filter($items, fn(array $i): bool => $i['recommendation'] === 'restock_needed')),
            ];
        } catch (\Exception $e) {
            log_error('Erro na análise de recuperação de pausados', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return ['total_paused' => 0, 'items' => [], 'recovery_value' => 0];
        }
    }

    // =========================================================================
    // PLANO DE AÇÃO SEMANAL (top 3 ações de maior impacto)
    // =========================================================================

    /**
     * Gera um plano focado com as 3 ações de maior impacto para a semana.
     * Inclui estimativa de ganho no score geral se implementadas.
     */
    private function generateWeeklyPlan(array $actionItems, array $pillars): array
    {
        if (empty($actionItems)) {
            return [
                'actions' => [],
                'estimated_gain' => 0,
                'focus_pillar' => null,
            ];
        }

        // Pegar top 3 ações por impact_score (já ordenadas)
        $top3 = array_slice($actionItems, 0, 3);

        // Estimar ganho potencial se as 3 ações forem implementadas
        $estimatedGain = 0;
        $affectedPillars = [];
        foreach ($top3 as $action) {
            $pillarKey = $action['pillar'];
            $pillarWeight = self::PILLAR_WEIGHTS[$pillarKey] ?? 10;
            $totalWeight = array_sum(self::PILLAR_WEIGHTS);

            // Estimar ganho por pilar: cada ação corrigida pode subir 5-15 pontos no pilar
            $pillarGain = match ($action['severity']) {
                'critical' => 15,
                'warning'  => 8,
                default    => 4,
            };

            // Ganho ponderado no score geral
            $estimatedGain += (int) round($pillarGain * $pillarWeight / $totalWeight);
            $affectedPillars[$pillarKey] = ($affectedPillars[$pillarKey] ?? 0) + $pillarGain;
        }

        // Pilar mais afetado (foco da semana)
        $focusPillar = null;
        if (!empty($affectedPillars)) {
            arsort($affectedPillars);
            $focusKey = array_key_first($affectedPillars);
            $focusPillar = [
                'key'   => $focusKey,
                'name'  => $pillars[$focusKey]['name'] ?? $focusKey,
                'score' => $pillars[$focusKey]['score'] ?? 0,
                'potential_gain' => $affectedPillars[$focusKey],
            ];
        }

        return [
            'actions'        => $top3,
            'estimated_gain' => min(30, $estimatedGain),
            'focus_pillar'   => $focusPillar,
        ];
    }

    // =========================================================================
    // CÁLCULOS E HELPERS
    // =========================================================================

    private function calculateOverallScore(array $pillars): int
    {
        $weightedSum = 0;
        $totalWeight = 0;

        foreach (self::PILLAR_WEIGHTS as $key => $weight) {
            if (isset($pillars[$key])) {
                $weightedSum += ($pillars[$key]['score'] ?? 0) * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? (int) round($weightedSum / $totalWeight) : 0;
    }

    private function getScoreLevel(int $score): string
    {
        return match (true) {
            $score >= self::SCORE_THRESHOLDS['great']   => 'great',
            $score >= self::SCORE_THRESHOLDS['good']    => 'good',
            $score >= self::SCORE_THRESHOLDS['warning'] => 'warning',
            default                                     => 'critical',
        };
    }

    private function getScoreLabel(int $score): string
    {
        return match (true) {
            $score >= self::SCORE_THRESHOLDS['great']   => 'Excelente',
            $score >= self::SCORE_THRESHOLDS['good']    => 'Boa',
            $score >= self::SCORE_THRESHOLDS['warning'] => 'Atenção',
            default                                     => 'Crítica',
        };
    }

    private function emptyPillar(string $name, string $reason): array
    {
        $names = [
            'reputation'      => 'Reputação',
            'seo_quality'     => 'SEO & Qualidade',
            'competitiveness' => 'Competitividade',
            'operation'       => 'Operação',
            'sales'           => 'Vendas',
        ];

        $icons = [
            'reputation'      => 'bi-star-fill',
            'seo_quality'     => 'bi-search',
            'competitiveness' => 'bi-graph-up-arrow',
            'operation'       => 'bi-truck',
            'sales'           => 'bi-cash-stack',
        ];

        log_info('Pilar vazio retornado', [
            'pillar' => $name,
            'reason' => $reason,
            'account_id' => $this->accountId,
        ]);

        return [
            'name'    => $names[$name] ?? $name,
            'icon'    => $icons[$name] ?? 'bi-question-circle',
            'score'   => 0,
            'level'   => 'critical',
            'details' => ['error' => $reason, 'is_mock' => true],
            'issues'  => [[
                'type'     => 'no_data',
                'severity' => 'critical',
                'message'  => $reason,
                'impact'   => $name === 'reputation' && str_contains($reason, 'conectada')
                    ? 'Sem conexão, não é possível obter dados reais do Mercado Livre'
                    : 'Dados indisponíveis para análise',
                'action'   => $name === 'reputation' && str_contains($reason, 'conectada')
                    ? 'Conecte sua conta no menu de Configurações > Contas ML'
                    : 'Verifique a conexão com o Mercado Livre',
            ]],
        ];
    }

    /**
     * Avalia a qualidade dos dados retornados (real vs mock/empty)
     */
    private function assessDataQuality(array $pillars): array
    {
        $totalPillars = count($pillars);
        $emptyPillars = 0;
        $realDataPillars = 0;
        $errors = [];

        foreach ($pillars as $key => $pillar) {
            $score = $pillar['score'] ?? 0;
            $details = $pillar['details'] ?? [];

            // Verificar se é empty/mock
            if (isset($details['error']) || isset($details['is_mock'])) {
                $emptyPillars++;
                $errors[$key] = $details['error'] ?? 'Dados indisponíveis';
            } elseif ($score > 0 || !empty($details)) {
                // Tem dados reais
                $realDataPillars++;
            }
        }

        $quality = match (true) {
            $emptyPillars === 0 => 'real',
            $emptyPillars >= 3 => 'mostly_mock',
            $realDataPillars > $emptyPillars => 'partial',
            default => 'mostly_mock'
        };

        return [
            'quality'          => $quality,
            'total_pillars'    => $totalPillars,
            'real_data'        => $realDataPillars,
            'mock_data'        => $emptyPillars,
            'percentage_real'  => $totalPillars > 0 ? round(($realDataPillars / $totalPillars) * 100) : 0,
            'errors'           => $errors,
            'is_fully_real'    => $emptyPillars === 0,
            'needs_connection' => in_array('Conta não conectada', $errors),
        ];
    }

    private function generateSummary(int $score, array $pillars, array $actions): array
    {
        $criticalCount = count(array_filter($actions, fn(array $a): bool => $a['severity'] === 'critical'));
        $warningCount = count(array_filter($actions, fn(array $a): bool => $a['severity'] === 'warning'));

        // Pilar mais fraco e mais forte
        $worstPillar = null;
        $worstScore = 101;
        $worstKey = null;
        $bestPillar = null;
        $bestScore = -1;
        foreach ($pillars as $key => $pillar) {
            $s = $pillar['score'] ?? 0;
            if ($s < $worstScore) {
                $worstScore = $s;
                $worstPillar = $pillar['name'];
                $worstKey = $key;
            }
            if ($s > $bestScore) {
                $bestScore = $s;
                $bestPillar = $pillar['name'];
            }
        }

        // Ganho potencial: se o pilar mais fraco subisse para 70, quanto o score geral subiria?
        $potentialGain = 0;
        if ($worstKey && $worstScore < 70) {
            $weight = self::PILLAR_WEIGHTS[$worstKey] ?? 0;
            $totalWeight = array_sum(self::PILLAR_WEIGHTS);
            $potentialGain = (int) round((70 - $worstScore) * $weight / $totalWeight);
        }

        // Recomendação específica por pilar mais fraco
        $pillarTip = '';
        if ($worstKey && $worstScore < 70) {
            $pillarTip = match ($worstKey) {
                'reputation'      => 'Reduza reclamações e atrasos para subir de nível no ML.',
                'seo_quality'     => 'Otimize títulos, complete fichas técnicas e melhore fotos.',
                'competitiveness' => 'Ative frete grátis, upgrade para Premium e vincule ao catálogo.',
                'operation'       => 'Responda perguntas rapidamente e ative Fulfillment.',
                'sales'           => 'Invista em Ads do ML e otimize anúncios de baixa conversão.',
                default           => '',
            };
        }

        // Recomendação principal: combina severidade com dica específica
        $mainRecommendation = match (true) {
            $criticalCount > 3 => 'Sua conta precisa de atenção urgente. Foque nos itens críticos primeiro.',
            $criticalCount > 0 => "Resolva os {$criticalCount} problemas críticos para melhorar rapidamente.",
            $warningCount > 3  => 'Sua conta está razoável. Corrija os alertas para subir de nível.',
            $warningCount > 0  => 'Conta em bom estado! Pequenos ajustes podem trazer resultados.',
            default            => 'Parabéns! Sua conta está excelente. Continue monitorando.',
        };

        if ($pillarTip) {
            $mainRecommendation .= ' ' . $pillarTip;
        }

        return [
            'critical_count'      => $criticalCount,
            'warning_count'       => $warningCount,
            'total_actions'       => count($actions),
            'worst_pillar'        => $worstPillar,
            'worst_pillar_score'  => $worstScore,
            'worst_pillar_key'    => $worstKey,
            'best_pillar'         => $bestPillar,
            'best_pillar_score'   => $bestScore,
            'potential_gain'      => $potentialGain,
            'recommendation'      => $mainRecommendation,
        ];
    }

    /**
     * Gera informações sobre as fontes de dados utilizadas no diagnóstico.
     * Transparência: mostra ao usuário o que é sinal real do ML vs análise local.
     */
    private function getDataSourcesInfo(array $pillars): array
    {
        $seoDetails = $pillars['seo_quality']['details'] ?? [];
        $hasMLQuality = !empty($seoDetails['ml_quality']);
        $scoreSource = $seoDetails['score_source'] ?? 'local_only';

        return [
            'ml_signals' => [
                [
                    'name'   => 'Reputação do Vendedor',
                    'source' => 'API ML /users/{id}',
                    'type'   => 'real',
                    'pillar' => 'reputation',
                ],
                [
                    'name'   => 'Qualidade dos Anúncios',
                    'source' => $hasMLQuality
                        ? 'API ML /users/{id}/listings_quality'
                        : 'Análise local (ML indisponível)',
                    'type'   => $hasMLQuality ? 'real' : 'estimated',
                    'pillar' => 'seo_quality',
                ],
                [
                    'name'   => 'Catálogo ML (catalog_product_id)',
                    'source' => 'API ML /items multiget',
                    'type'   => 'real',
                    'pillar' => 'seo_quality',
                ],
                [
                    'name'   => 'Tags de Qualidade (10+ tags)',
                    'source' => 'API ML /items multiget',
                    'type'   => 'real',
                    'pillar' => 'competitiveness',
                ],
                [
                    'name'   => 'Qualidade de Imagens (ML tags)',
                    'source' => 'API ML /items tags',
                    'type'   => 'real',
                    'pillar' => 'seo_quality',
                ],
                [
                    'name'   => 'Atributos Obrigatórios por Categoria',
                    'source' => 'API ML /categories/{id}/attributes',
                    'type'   => 'real',
                    'pillar' => 'seo_quality',
                ],
                [
                    'name'   => 'Métricas de Envio e Atrasos',
                    'source' => 'API ML /users/{id} seller_reputation',
                    'type'   => 'real',
                    'pillar' => 'operation',
                ],
                [
                    'name'   => 'Vendas e Faturamento',
                    'source' => 'API ML /orders/search',
                    'type'   => 'real',
                    'pillar' => 'sales',
                ],
                [
                    'name'   => 'Tendências de Busca',
                    'source' => 'API ML /trends/{site}/{category}',
                    'type'   => 'real',
                    'pillar' => 'competitiveness',
                ],
                [
                    'name'   => 'Visitas por Período',
                    'source' => 'API ML /users/{id}/items_visits',
                    'type'   => 'real',
                    'pillar' => 'sales',
                ],
                [
                    'name'   => 'Mediações e Reclamações',
                    'source' => 'API ML /v1/claims/search',
                    'type'   => 'real',
                    'pillar' => 'operation',
                ],
                [
                    'name'   => 'Tempo de Resposta a Perguntas',
                    'source' => 'API ML /questions/search (answered)',
                    'type'   => 'real',
                    'pillar' => 'operation',
                ],
                [
                    'name'   => 'Itens em Revisão (infrações)',
                    'source' => 'API ML /users/{id}/items/search?status=under_review',
                    'type'   => 'real',
                    'pillar' => 'operation',
                ],
                [
                    'name'   => 'Itens Inativos',
                    'source' => 'API ML /users/{id}/items/search?status=inactive',
                    'type'   => 'real',
                    'pillar' => 'operation',
                ],
            ],
            'local_analysis' => [
                [
                    'name'   => 'Score de Título (tamanho, formato)',
                    'type'   => 'heuristic',
                    'pillar' => 'seo_quality',
                ],
                [
                    'name'   => 'Score de Descrição (tamanho)',
                    'type'   => 'heuristic',
                    'pillar' => 'seo_quality',
                ],
            ],
            'score_composition' => [
                'seo_quality_source' => $scoreSource,
                'ml_weight'          => $scoreSource === 'ml_quality_60_local_40' ? 60 : 0,
                'local_weight'       => $scoreSource === 'ml_quality_60_local_40' ? 40 : 100,
                'item_score_breakdown' => 'Título 20 + Imagens(ML) 20 + Atributos(categoria) 20 + Descrição 10 + Envio 20 + Bônus ML 10 = 100',
                'competitiveness_breakdown' => 'Tipo Anúncio 15 + Frete 15 + Logística 15 + Saúde ML 15 + Catálogo 10 + Qualidade(tags) 15 + Vendas 10 + Relevância 5 = 100',
                'operation_breakdown' => 'Prazo Envio 20 + Atendimento 15 + Logística/Full 20 + Cancelamento 15 + Mediações 15 + Conformidade 15 = 100',
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // ADVANCED DIAGNOSTICS - NEW IMPLEMENTATIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * 🆕 Account Status & Verification Diagnostic
     * Checks account verification, official store status, available features
     */
    public function getAccountStatusDiagnostic(): array
    {
        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptyAccountStatus();
            }

            // Get user detailed info
            $user = $this->client->get("/users/{$sellerId}");

            // Get shipping preferences
            $shippingPrefs = null;
            try {
                $shippingPrefs = $this->client->get("/users/{$sellerId}/shipping_preferences");
                if (is_array($shippingPrefs) && isset($shippingPrefs['error'])) {
                    $shippingPrefs = null;
                }
            } catch (\Exception $e) {
                // Optional endpoint
            }

            // Check official store
            $isOfficialStore = false;
            try {
                $officialStore = $this->client->get("/users/{$sellerId}/brands_official_store");
                $isOfficialStore = is_array($officialStore)
                    && !isset($officialStore['error'])
                    && !empty($officialStore);
            } catch (\Exception $e) {
                // Not an official store
            }

            // Build diagnostic
            $verificationStatus = $this->assessVerificationStatus($user);
            $featureAvailability = $this->assessFeatureAvailability($user, $shippingPrefs);

            return [
                'score' => $this->calculateAccountStatusScore($verificationStatus, $featureAvailability, $isOfficialStore),
                'verification' => $verificationStatus,
                'features' => $featureAvailability,
                'official_store' => $isOfficialStore,
                'account_type' => $user['user_type'] ?? 'unknown',
                'site' => $user['site_id'] ?? 'MLB',
                'registration_date' => $user['registration_date'] ?? null,
                'country' => $user['country_id'] ?? null,
                'recommendations' => $this->generateAccountStatusRecommendations($verificationStatus, $featureAvailability),
            ];
        } catch (\Exception $e) {
            log_error('Falha no diagnóstico de status da conta', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyAccountStatus();
        }
    }

    private function assessVerificationStatus(array $user): array
    {
        $status = $user['status'] ?? [];

        return [
            'site_status' => $status['site_status'] ?? 'unknown',
            'confirmed_email' => ($status['confirmed_email'] ?? false),
            'user_type' => $user['user_type'] ?? 'unknown',
            'power_seller_status' => $user['seller_reputation']['power_seller_status'] ?? null,
            'mercadopago_account_type' => $user['status']['mercadopago_account_type'] ?? 'unknown',
            'required_action' => $status['required_action'] ?? null,
            'is_verified' => (($status['site_status'] ?? '') === 'active') && ($status['confirmed_email'] ?? false),
        ];
    }

    private function assessFeatureAvailability(array $user, ?array $shippingPrefs): array
    {
        $tags = $user['tags'] ?? [];

        return [
            'mercado_envios' => in_array('normal', $tags) || in_array('mshops', $tags),
            'mercado_pago' => in_array('mercadopago_account', $tags) || in_array('eshop', $tags),
            'catalog_enabled' => in_array('catalog_product', $tags) || in_array('catalog_seller', $tags),
            'full_eligible' => in_array('logistics', $tags) || in_array('fulfillment', $tags),
            'credits_enabled' => in_array('credits_priority', $tags) || in_array('credits_active_borrower', $tags),
            'can_list' => $user['seller_reputation']['metrics']['cancellations']['rate'] < 0.10, // <10% cancellation
            'shipping_configured' => !empty($shippingPrefs),
            'available_tags' => $tags,
        ];
    }

    private function calculateAccountStatusScore(array $verification, array $features, bool $officialStore): int
    {
        $score = 0;

        // Verification (40 points)
        if ($verification['is_verified']) $score += 30;
        if ($verification['confirmed_email']) $score += 10;

        // Features (40 points)
        if ($features['mercado_envios']) $score += 10;
        if ($features['mercado_pago']) $score += 10;
        if ($features['catalog_enabled']) $score += 10;
        if ($features['full_eligible']) $score += 10;

        // Official Store (20 points bonus)
        if ($officialStore) $score += 20;

        return min(100, $score);
    }

    private function generateAccountStatusRecommendations(array $verification, array $features): array
    {
        $recommendations = [];

        if (!$verification['confirmed_email']) {
            $recommendations[] = [
                'priority' => 'critical',
                'action' => 'Confirme seu e-mail',
                'impact' => 'Necessário para vender',
                'link' => '/settings/profile',
            ];
        }

        if (!$features['mercado_pago']) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'Ative sua conta Mercado Pago',
                'impact' => 'Receba pagamentos mais rápido',
                'link' => 'https://www.mercadopago.com.br',
            ];
        }

        if (!$features['mercado_envios']) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'Configure Mercado Envios',
                'impact' => '+30% visibilidade nos resultados',
                'link' => '/settings/shipping',
            ];
        }

        if ($features['full_eligible'] && !in_array('logistics', $features['available_tags'] ?? [])) {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => 'Considere ativar Mercado Envios Full',
                'impact' => '+50% visibilidade + Frete Grátis',
                'link' => 'https://vendedores.mercadolivre.com.br/nota/envio-full-o-que-e',
            ];
        }

        return $recommendations;
    }

    private function emptyAccountStatus(): array
    {
        return [
            'score' => 0,
            'verification' => ['is_verified' => false],
            'features' => [],
            'official_store' => false,
            'account_type' => 'unknown',
            'recommendations' => [],
        ];
    }

    /**
     * 🆕 Questions & Customer Service Diagnostic
     * Analyzes unanswered questions, response time, customer service impact
     */
    public function getCustomerServiceDiagnostic(): array
    {
        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptyCustomerService();
            }

            // Get unanswered questions
            $unanswered = $this->client->get('/questions/search', [
                'seller_id' => $sellerId,
                'status' => 'UNANSWERED',
                'limit' => 50,
            ]);

            // Get all recent questions for metrics
            $recent = $this->client->get('/questions/search', [
                'seller_id' => $sellerId,
                'limit' => 100,
            ]);

            $questions = $recent['questions'] ?? [];
            $metrics = $this->calculateQuestionMetrics($questions);
            $unansweredList = $unanswered['questions'] ?? [];

            $score = $this->calculateCustomerServiceScore($metrics, count($unansweredList));

            return [
                'score' => $score,
                'unanswered_count' => count($unansweredList),
                'unanswered_urgent' => $this->getUrgentQuestions($unansweredList),
                'average_response_time_hours' => $metrics['avg_response_hours'],
                'response_rate_24h' => $metrics['response_rate_24h'],
                'total_questions_30d' => $metrics['total_30d'],
                'questions_by_status' => $metrics['by_status'],
                'impact_on_reputation' => $this->assessQuestionImpact($metrics, count($unansweredList)),
                'recommendations' => $this->generateCustomerServiceRecommendations($metrics, count($unansweredList)),
            ];
        } catch (\Exception $e) {
            log_error('Falha no diagnóstico de atendimento ao cliente', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyCustomerService();
        }
    }

    private function calculateQuestionMetrics(array $questions): array
    {
        $total = count($questions);
        $answered = 0;
        $responseTimes = [];
        $last30Days = strtotime('-30 days');
        $total30d = 0;
        $byStatus = ['ANSWERED' => 0, 'UNANSWERED' => 0, 'CLOSED' => 0, 'DELETED' => 0];

        foreach ($questions as $q) {
            $status = $q['status'] ?? 'UNKNOWN';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            if ($status === 'ANSWERED') {
                $answered++;

                // Calculate response time
                if (!empty($q['date_created']) && !empty($q['answer']['date_created'])) {
                    $created = strtotime($q['date_created']);
                    $responded = strtotime($q['answer']['date_created']);
                    $hours = ($responded - $created) / 3600;
                    if ($hours >= 0) {
                        $responseTimes[] = $hours;
                    }
                }
            }

            // Count last 30 days
            if (!empty($q['date_created']) && strtotime($q['date_created']) >= $last30Days) {
                $total30d++;
            }
        }

        $avgResponseHours = !empty($responseTimes) ? array_sum($responseTimes) / count($responseTimes) : 0;
        $responseRate24h = 0;
        if ($total > 0) {
            $answeredIn24h = array_filter($responseTimes, fn(float|int $h): bool => $h <= 24);
            $responseRate24h = (count($answeredIn24h) / $total) * 100;
        }

        return [
            'total' => $total,
            'answered' => $answered,
            'avg_response_hours' => round($avgResponseHours, 1),
            'response_rate_24h' => round($responseRate24h, 1),
            'total_30d' => $total30d,
            'by_status' => $byStatus,
        ];
    }

    private function getUrgentQuestions(array $unanswered): array
    {
        $urgent = [];
        $now = time();

        foreach ($unanswered as $q) {
            if (empty($q['date_created'])) continue;

            $created = strtotime($q['date_created']);
            $ageHours = ($now - $created) / 3600;

            if ($ageHours > 6) { // Urgent after 6 hours
                $urgent[] = [
                    'id' => $q['id'] ?? null,
                    'text' => $q['text'] ?? '',
                    'age_hours' => round($ageHours, 1),
                    'item_id' => $q['item_id'] ?? null,
                ];
            }
        }

        return $urgent;
    }

    private function calculateCustomerServiceScore(array $metrics, int $unansweredCount): int
    {
        $score = 100;

        // Penalty for unanswered questions
        if ($unansweredCount > 10) $score -= 30;
        elseif ($unansweredCount > 5) $score -= 20;
        elseif ($unansweredCount > 2) $score -= 10;

        // Penalty for slow response time
        $avgHours = $metrics['avg_response_hours'];
        if ($avgHours > 48) $score -= 20;
        elseif ($avgHours > 24) $score -= 10;
        elseif ($avgHours > 12) $score -= 5;

        // Bonus for fast response rate
        if ($metrics['response_rate_24h'] >= 90) $score += 10;
        elseif ($metrics['response_rate_24h'] >= 70) $score += 5;

        return max(0, min(100, $score));
    }

    private function assessQuestionImpact(array $metrics, int $unanswered): string
    {
        if ($unanswered > 10) {
            return 'CRÍTICO - Perguntas sem resposta afetam negativamente sua reputação e conversão';
        } elseif ($unanswered > 5) {
            return 'ALTO - Responda rapidamente para melhorar a experiência do comprador';
        } elseif ($metrics['avg_response_hours'] > 24) {
            return 'MÉDIO - Tempo de resposta acima da média afeta a conversão';
        } else {
            return 'BAIXO - Continue mantendo um bom atendimento';
        }
    }

    private function generateCustomerServiceRecommendations(array $metrics, int $unanswered): array
    {
        $recommendations = [];

        if ($unanswered > 0) {
            $recommendations[] = [
                'priority' => $unanswered > 5 ? 'critical' : 'high',
                'action' => "Responder {$unanswered} pergunta(s) pendente(s)",
                'impact' => 'Melhora conversão e reputação',
                'link' => '/dashboard/questions',
            ];
        }

        if ($metrics['avg_response_hours'] > 12) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'Melhorar tempo de resposta (meta: <6h)',
                'impact' => '+15% conversão com respostas rápidas',
                'link' => '/dashboard/questions',
            ];
        }

        if ($metrics['response_rate_24h'] < 70) {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => 'Configurar notificações de perguntas',
                'impact' => 'Responda mais rápido e não perca vendas',
                'link' => '/settings/notifications',
            ];
        }

        return $recommendations;
    }

    private function emptyCustomerService(): array
    {
        return [
            'score' => 0,
            'unanswered_count' => 0,
            'unanswered_urgent' => [],
            'average_response_time_hours' => 0,
            'response_rate_24h' => 0,
            'total_questions_30d' => 0,
            'questions_by_status' => [],
            'impact_on_reputation' => 'UNKNOWN',
            'recommendations' => [],
        ];
    }

    /**
     * 🆕 Catalog Health Diagnostic
     * Analyzes catalog participation, products created, matching quality
     */
    public function getCatalogHealthDiagnostic(): array
    {
        try {
            $sellerId = $this->getCachedSellerId();
            if (!$sellerId) {
                return $this->emptyCatalogHealth();
            }

            $activeData = $this->getCachedActiveItems();
            $itemIds = $activeData['ids'] ?? [];
            if (empty($itemIds)) {
                return $this->emptyCatalogHealth();
            }

            $items = [];
            $itemBatches = array_chunk(array_slice($itemIds, 0, 200), 20);
            foreach ($itemBatches as $batch) {
                $idsParam = implode(',', $batch);
                $batchItems = $this->client->get('/items', [
                    'ids'        => $idsParam,
                    'attributes' => 'id,title,catalog_product_id',
                ]);

                foreach ($batchItems as $wrapper) {
                    $item = $wrapper['body'] ?? $wrapper;
                    if (!is_array($item) || empty($item['id'])) {
                        continue;
                    }

                    $items[] = $item;
                }
            }

            if (empty($items)) {
                return $this->emptyCatalogHealth();
            }

            // Analyze catalog participation
            $catalogItems = array_filter($items, fn(array $item): bool => !empty($item['catalog_product_id']));
            $nonCatalogItems = array_filter($items, fn(array $item): bool => empty($item['catalog_product_id']));

            $catalogRatio = count($items) > 0 ? (count($catalogItems) / count($items)) * 100 : 0;

            // Check for duplicates (simplified)
            $duplicates = $this->detectDuplicateListings($items);

            // Calculate score
            $score = $this->calculateCatalogHealthScore($catalogRatio, count($duplicates), count($items));

            return [
                'score' => $score,
                'total_items' => count($items),
                'catalog_items' => count($catalogItems),
                'non_catalog_items' => count($nonCatalogItems),
                'catalog_ratio' => round($catalogRatio, 1),
                'duplicates_detected' => count($duplicates),
                'duplicate_examples' => array_slice($duplicates, 0, 5),
                'catalog_benefits' => $this->getCatalogBenefits(),
                'recommendations' => $this->generateCatalogRecommendations($catalogRatio, count($duplicates)),
            ];
        } catch (\Exception $e) {
            log_error('Falha no diagnóstico de saúde do catálogo', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return $this->emptyCatalogHealth();
        }
    }

    private function detectDuplicateListings(array $items): array
    {
        $duplicates = [];
        $titles = [];

        foreach ($items as $item) {
            $title = mb_strtolower(trim($item['title'] ?? ''));
            if (empty($title)) continue;

            // Simple similarity: remove numbers and special chars
            $normalized = preg_replace('/[0-9\W]+/', ' ', $title);
            $normalized = preg_replace('/\s+/', ' ', trim($normalized));

            if (isset($titles[$normalized])) {
                $duplicates[] = [
                    'item1' => $titles[$normalized],
                    'item2' => $item['id'],
                    'title' => $item['title'],
                ];
            } else {
                $titles[$normalized] = $item['id'];
            }
        }

        return $duplicates;
    }

    private function calculateCatalogHealthScore(float $catalogRatio, int $duplicates, int $totalItems): int
    {
        $score = 0;

        // Catalog ratio (60 points)
        if ($catalogRatio >= 80) $score += 60;
        elseif ($catalogRatio >= 60) $score += 45;
        elseif ($catalogRatio >= 40) $score += 30;
        elseif ($catalogRatio >= 20) $score += 15;

        // No duplicates (30 points)
        if ($duplicates === 0) $score += 30;
        elseif ($duplicates <= 2) $score += 20;
        elseif ($duplicates <= 5) $score += 10;

        // Volume bonus (10 points)
        if ($totalItems >= 50) $score += 10;
        elseif ($totalItems >= 20) $score += 5;

        return min(100, $score);
    }

    private function getCatalogBenefits(): array
    {
        return [
            '+40% visibilidade nos resultados de busca',
            'Maior confiança do comprador',
            'Processo de listagem mais rápido',
            'Dados de produto padronizados',
            'Elegível para Buy Box',
        ];
    }

    private function generateCatalogRecommendations(float $catalogRatio, int $duplicates): array
    {
        $recommendations = [];

        if ($catalogRatio < 50) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'Migrar anúncios para catálogo',
                'impact' => '+40% visibilidade',
                'link' => '/dashboard/catalog',
            ];
        }

        if ($duplicates > 0) {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => "Revisar {$duplicates} possíveis anúncio(s) duplicado(s)",
                'impact' => 'Evita penalização e melhora visibilidade',
                'link' => '/dashboard/items',
            ];
        }

        return $recommendations;
    }

    private function emptyCatalogHealth(): array
    {
        return [
            'score' => 0,
            'total_items' => 0,
            'catalog_items' => 0,
            'non_catalog_items' => 0,
            'catalog_ratio' => 0,
            'duplicates_detected' => 0,
            'duplicate_examples' => [],
            'catalog_benefits' => [],
            'recommendations' => [],
        ];
    }
}
