<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\AccountGovernanceService;
use App\Services\MercadoLivreClient;
use App\Services\MercadoPagoAccountService;
use App\Services\SEO\KeywordGapAnalyzerService;
use App\Services\SEO\LongTailGeneratorService;
use App\Services\SEO\SemanticAnalyzerService;
use Monolog\Logger;
use PDO;

/**
 * AccountXRayService — Diagnóstico sistemático completo de conta Mercado Livre
 *
 * RAIO X = análise profunda de:
 *  1. Saúde da conta (reputação, métricas, status)
 *  2. Qualidade SEO de cada anúncio (título, keywords, semântica)
 *  3. Cauda longa — keywords de baixa concorrência que geram vendas
 *  4. Lacunas ocultas — o que concorrentes ranqueiam e você não
 *  5. Saúde financeira via Mercado Pago
 *  6. Plano de recuperação priorizado
 *
 * @version 1.0.0
 */
class AccountXRayService
{
    // ─────────────────────────────────────────────────────────
    // CONSTANTS
    // ─────────────────────────────────────────────────────────

    private const ITEMS_PER_BATCH          = 20;
    private const MAX_ITEMS_FULL_ANALYSIS  = 100;  // analisar até 100 itens com SEO completo
    private const MAX_COMPETITORS_PER_CAT  = 10;
    private const LONG_TAIL_TOP_ITEMS      = 20;   // gerar long-tail para os 20 melhores/piores
    private const SEMANTIC_TOP_ITEMS       = 10;   // análise semântica profunda nos 10 críticos

    // Pesos do score final
    private const W_ACCOUNT_HEALTH  = 0.35;  // 35% — saúde da conta
    private const W_SEO_QUALITY     = 0.30;  // 30% — qualidade SEO dos anúncios
    private const W_FINANCIAL       = 0.20;  // 20% — saúde financeira
    private const W_COMPETITIVE     = 0.15;  // 15% — posição competitiva

    // SEO scoring thresholds
    private const TITLE_MIN_LENGTH  = 35;
    private const TITLE_OPT_MIN     = 40;
    private const TITLE_OPT_MAX     = 60;
    private const TITLE_MAX_LENGTH  = 60;

    // ─────────────────────────────────────────────────────────
    // PROPERTIES
    // ─────────────────────────────────────────────────────────

    private PDO $db;
    private MercadoLivreClient $mlClient;
    private AccountGovernanceService $governance;
    private MercadoPagoAccountService $mpService;
    private LongTailGeneratorService $longTail;
    private ?Logger $logger;
    private int $accountId;

    // ─────────────────────────────────────────────────────────
    // CONSTRUCTOR
    // ─────────────────────────────────────────────────────────

    public function __construct(
        int $accountId,
        ?MercadoLivreClient $mlClient = null,
        ?Logger $logger = null
    ) {
        $this->accountId  = $accountId;
        $this->db         = Database::getInstance();
        $this->mlClient   = $mlClient ?? new MercadoLivreClient($accountId);
        $this->governance = new AccountGovernanceService(
            defaultMinMarginPct: 0.05,
            maxPriceDropPct: 0.15,
            logger: $logger
        );
        $this->mpService  = new MercadoPagoAccountService(null, $logger);
        $this->longTail   = new LongTailGeneratorService($accountId);
        $this->logger     = $logger;
    }

    // ─────────────────────────────────────────────────────────
    // PUBLIC: run full Raio X
    // ─────────────────────────────────────────────────────────

    /**
     * Executa o Raio X completo e salva no banco de dados.
     *
     * @param array{
     *   max_items?: int,
     *   include_paused?: bool,
     *   deep_seo?: bool,
     *   include_financial?: bool
     * } $options
     */
    public function run(array $options = []): array
    {
        $reportId = $this->createReportRecord($options);

        try {
            $this->updateReportStatus($reportId, 'running');
            $startTime = hrtime(true);

            // ── FASE 1: dados do vendedor ────────────────────────────
            $this->log('info', 'RAIO X F1: Buscando dados do vendedor', ['account_id' => $this->accountId]);
            $sellerData = $this->fetchSellerData();
            if (isset($sellerData['error'])) {
                return $this->failReport($reportId, $sellerData['message'] ?? 'Erro ao buscar vendedor');
            }

            // ── FASE 2: buscar itens ─────────────────────────────────
            $maxItems = min((int) ($options['max_items'] ?? 200), 500);
            $includePaused = (bool) ($options['include_paused'] ?? true);
            $this->log('info', 'RAIO X F2: Buscando itens', ['max' => $maxItems]);
            $rawItems = $this->fetchAllItems($maxItems, $includePaused, $sellerData['seller_id']);
            $totalFetched = count($rawItems);

            // ── FASE 3: enriquecer com métricas ML ───────────────────
            $this->log('info', 'RAIO X F3: Métricas de visitas e vendas', ['items' => $totalFetched]);
            $enrichedItems = $this->enrichWithMetrics($rawItems, $sellerData['seller_id']);

            // ── FASE 4: GovernanceService — scoring + classificação ──
            $this->log('info', 'RAIO X F4: Governance scoring');
            $accountData  = $this->buildAccountData($sellerData);
            $govResult    = $this->governance->runFullDiagnostic($accountData, $enrichedItems, []);

            // ── FASE 5: SEO Audit completo ───────────────────────────
            $this->log('info', 'RAIO X F5: SEO audit por anúncio');
            $seoAudit = $this->runSEOAudit($enrichedItems, $options);

            // ── FASE 6: Análise de competidores + lacunas ocultas ────
            $this->log('info', 'RAIO X F6: Análise competitiva e lacunas');
            $competitiveAnalysis = $this->runCompetitiveAnalysis($enrichedItems, $seoAudit);

            // ── FASE 7: Saúde financeira MP ──────────────────────────
            $financialHealth = [];
            if ($options['include_financial'] ?? true) {
                $this->log('info', 'RAIO X F7: Saúde financeira Mercado Pago');
                $financialHealth = $this->mpService->getFinancialHealth();
            }

            // ── FASE 8: Score geral + diagnóstico ────────────────────
            $this->log('info', 'RAIO X F8: Calculando score geral');
            $overallScore = $this->calculateOverallScore($govResult, $seoAudit, $financialHealth, $competitiveAnalysis);
            $diagnosis    = $this->buildDiagnosis($sellerData, $govResult, $seoAudit, $competitiveAnalysis, $financialHealth);

            // ── FASE 9: Plano de ação priorizado ────────────────────
            $this->log('info', 'RAIO X F9: Gerando plano de recuperação');
            $recoveryPlan = $this->buildRecoveryPlan($govResult, $seoAudit, $competitiveAnalysis, $financialHealth, $overallScore);

            $elapsedMs = round((hrtime(true) - $startTime) / 1_000_000, 2);

            $report = [
                'meta' => [
                    'report_id'         => $reportId,
                    'account_id'        => $this->accountId,
                    'seller_id'         => $sellerData['seller_id'] ?? null,
                    'nickname'          => $sellerData['nickname'] ?? null,
                    'generated_at'      => date('c'),
                    'elapsed_ms'        => $elapsedMs,
                    'items_fetched'     => $totalFetched,
                    'items_analyzed'    => count($seoAudit['items'] ?? []),
                ],
                'score_overall'     => $overallScore,
                'account_status'    => $govResult['classification']['account_status'] ?? 'UNKNOWN',
                'seller'            => $sellerData,
                'governance'        => $govResult,
                'seo_audit'         => $seoAudit,
                'competitive'       => $competitiveAnalysis,
                'financial'         => $financialHealth,
                'diagnosis'         => $diagnosis,
                'recovery_plan'     => $recoveryPlan,
            ];

            // Salvar resultado
            $this->saveReport(
                $reportId,
                $report,
                $overallScore,
                $govResult['classification']['account_status'] ?? null,
                $totalFetched,
                count($seoAudit['items'] ?? []),
                count($recoveryPlan['critical'] ?? [])
            );

            // Salvar scores por item
            $this->saveItemScores($reportId, $seoAudit['items'] ?? [], $govResult['items'] ?? []);

            $this->log('info', 'RAIO X completo', [
                'score'       => $overallScore,
                'status'      => $report['account_status'],
                'elapsed_ms'  => $elapsedMs,
            ]);

            return ['success' => true, 'report_id' => $reportId, 'report' => $report];
        } catch (\Throwable $e) {
            $this->log('error', 'RAIO X falhou', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->failReport($reportId, $e->getMessage());
        }
    }

    /**
     * Carrega um relatório salvo do banco.
     */
    public function loadReport(int $reportId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM account_xray_reports WHERE id = :id AND account_id = :account_id LIMIT 1'
        );
        $stmt->execute(['id' => $reportId, 'account_id' => $this->accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['report'] = $row['report_json'] ? json_decode($row['report_json'], true) : null;
        unset($row['report_json']);

        return $row;
    }

    /**
     * Lista relatórios recentes da conta.
     *
     * @return array<int, array>
     */
    public function listReports(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, status, score_overall, account_status, items_total, items_analyzed,
                    critical_issues, started_at, completed_at, created_at
             FROM account_xray_reports
             WHERE account_id = :account_id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('account_id', $this->accountId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────
    // PHASE 1: dados do vendedor
    // ─────────────────────────────────────────────────────────

    private function fetchSellerData(): array
    {
        // GET /users/me
        $me = $this->mlClient->getMe();
        if (isset($me['error'])) {
            return ['error' => true, 'message' => $me['message'] ?? 'Falha ao buscar /users/me'];
        }

        $sellerId = (string) ($me['id'] ?? '');

        // GET /users/{id}/seller_reputation
        $rep = $this->mlClient->get("/users/{$sellerId}/seller_reputation");

        // GET /users/{id} — mais detalhes
        $userDetail = $this->mlClient->get("/users/{$sellerId}");

        // power_seller_status
        $powerSeller = $userDetail['power_seller_status'] ?? null;
        $level = $me['seller_reputation']['level_id'] ?? $rep['level_id'] ?? 'unknown';

        // Pontuação de reputação
        $repScore = $this->calculateReputationScore($rep, $level, $powerSeller);

        return [
            'seller_id'           => $sellerId,
            'nickname'            => $me['nickname'] ?? null,
            'email'               => $me['email'] ?? null,
            'site_id'             => $me['site_id'] ?? 'MLB',
            'points'              => (int) ($me['points'] ?? 0),
            'registration_date'   => $me['registration_date'] ?? null,
            'power_seller_status' => $powerSeller,
            'reputation_level'    => $level,
            'reputation_score'    => $repScore,
            'reputation_raw'      => $rep,
            'seller_type'         => $me['seller_reputation']['transactions']['period'] ?? null,
            'shipping_modes'      => $me['shipping_modes'] ?? [],
            'tags'                => $me['tags'] ?? [],
            'status_banned'       => in_array('mshops_banned', $me['tags'] ?? [], true),
            'account_age_days'    => $this->accountAgeDays($me['registration_date'] ?? null),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // PHASE 2: buscar itens
    // ─────────────────────────────────────────────────────────

    /** @return array<int, array> */
    private function fetchAllItems(int $max, bool $includePaused, string $sellerId): array
    {
        $items    = [];
        $offset   = 0;
        $pageSize = min(self::ITEMS_PER_BATCH, 50);

        $statuses = $includePaused ? ['active', 'paused'] : ['active'];

        foreach ($statuses as $status) {
            $offset = 0;
            while (count($items) < $max) {
                $response = $this->mlClient->get("/users/{$sellerId}/items/search", [
                    'status' => $status,
                    'limit'  => $pageSize,
                    'offset' => $offset,
                ]);

                $ids   = $response['results'] ?? [];
                $total = (int) ($response['paging']['total'] ?? 0);

                if (empty($ids)) {
                    break;
                }

                // Buscar detalhes em batch (até 20 por request)
                $batches = array_chunk($ids, 20);
                foreach ($batches as $batch) {
                    if (count($items) >= $max) {
                        break 2;
                    }
                    $details = $this->mlClient->getMultiItemDetails($batch);
                    foreach ($details as $item) {
                        $item['_fetched_status'] = $status;
                        $items[] = $item;
                    }
                    usleep(150_000); // 150ms entre batches
                }

                $offset += $pageSize;
                if ($offset >= $total || count($ids) < $pageSize) {
                    break;
                }
            }
        }

        return array_slice($items, 0, $max);
    }

    // ─────────────────────────────────────────────────────────
    // PHASE 3: enriquecer com métricas
    // ─────────────────────────────────────────────────────────

    /** @param array<int, array> $items */
    private function enrichWithMetrics(array $items, string $sellerId): array
    {
        if (empty($items)) {
            return [];
        }

        // Buscar visitas em batch (últimos 30 dias)
        $ids      = array_column($items, 'id');
        $visitMap = [];
        $batches  = array_chunk($ids, 20);

        foreach ($batches as $batch) {
            // getMultiItemVisits retorna array<itemId, ['total'=>int, 'visits'=>int, 'daily'=>array]>
            $visits = $this->mlClient->getMultiItemVisits($batch, 30);
            foreach ($visits as $itemId => $visitData) {
                $visitMap[(string) $itemId] = (int) ($visitData['total'] ?? $visitData['visits'] ?? 0);
            }
            usleep(200_000);
        }

        // Buscar health score por item (top 50 por visitas)
        usort(
            $items,
            fn(array $a, array $b): int => ($visitMap[$b['id'] ?? ''] ?? 0) <=> ($visitMap[$a['id'] ?? ''] ?? 0)
        );

        $healthMap = [];
        $topItems  = array_slice($items, 0, 50);
        foreach ($topItems as $item) {
            $id     = $item['id'] ?? '';
            $health = $this->mlClient->getItemHealth($id);
            if (!isset($health['error'])) {
                $healthMap[$id] = $health;
            }
            usleep(100_000);
        }

        // Buscar pedidos recentes para calcular conversão
        $salesMap = $this->fetchRecentSales($sellerId, $ids);

        return array_map(function (array $item) use ($visitMap, $healthMap, $salesMap): array {
            $id      = $item['id'] ?? '';
            $visits  = $visitMap[$id] ?? 0;
            $sales30 = $salesMap[$id] ?? 0;
            $conv    = $visits > 0 ? round($sales30 / $visits, 4) : 0.0;

            $item['_visits_30d']         = $visits;
            $item['_sales_30d']          = $sales30;
            $item['_conversion_rate']    = $conv;
            $item['_health']             = $healthMap[$id] ?? null;
            $item['_health_score']       = (int) ($healthMap[$id]['quality_score'] ?? 0);
            $item['_has_visits']         = $visits > 0;
            $item['_has_sales']          = $sales30 > 0;
            $item['_is_stale']           = $visits > 0 && $sales30 === 0;

            return $item;
        }, $items);
    }

    /**
     * Busca vendas dos últimos 30 dias para mapear por item_id.
     *
     * @param  array<int, string> $itemIds
     * @return array<string, int>
     */
    private function fetchRecentSales(string $sellerId, array $itemIds): array
    {
        $salesMap = [];
        try {
            // Timezone único da aplicação (America/Sao_Paulo) em vez de offset
            // fixo -03:00 (evita divergência caso o fuso configurado mude).
            $tzOffset = \App\Helpers\TimezoneHelper::mysqlOffsetLiteral();
            $from     = date('Y-m-d', strtotime('-30 days')) . 'T00:00:00.000' . $tzOffset;
            $response = $this->mlClient->get('/orders/search', [
                'seller'         => $sellerId,
                'order.status'   => 'paid',
                'order.date_created.from' => $from,
                'limit'          => 100,
            ]);

            foreach ($response['results'] ?? [] as $order) {
                foreach ($order['order_items'] ?? [] as $oi) {
                    $id = $oi['item']['id'] ?? '';
                    if (in_array($id, $itemIds, true)) {
                        $salesMap[$id] = ($salesMap[$id] ?? 0) + (int) ($oi['quantity'] ?? 1);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log('warning', 'fetchRecentSales falhou', ['error' => $e->getMessage()]);
        }

        return $salesMap;
    }

    // ─────────────────────────────────────────────────────────
    // PHASE 5: SEO Audit
    // ─────────────────────────────────────────────────────────

    private function runSEOAudit(array $items, array $options): array
    {
        $deepSeo = (bool) ($options['deep_seo'] ?? false);
        $limit   = self::MAX_ITEMS_FULL_ANALYSIS;

        $auditItems    = [];
        $totalSeoScore = 0;
        $scoreCount    = 0;

        // Ordenar por visitas (analisar os mais relevantes primeiro)
        usort(
            $items,
            fn(array $a, array $b): int => ($b['_visits_30d'] ?? 0) <=> ($a['_visits_30d'] ?? 0)
        );

        $semanticService = $deepSeo ? new SemanticAnalyzerService() : null;
        $gapService      = new KeywordGapAnalyzerService((string) $this->accountId);

        foreach (array_slice($items, 0, $limit) as $item) {
            $id       = $item['id'] ?? '';
            $title    = $item['title'] ?? '';
            $catId    = $item['category_id'] ?? '';

            $seoScore    = $this->scoreSEOTitle($title);
            $longTailKws = $this->longTail->generate($title, $catId);
            $missingKws  = $this->identifyMissingKeywords($item);

            // Gap analysis (só para os top LONG_TAIL_TOP_ITEMS)
            $gapResult = [];
            if ($scoreCount < self::LONG_TAIL_TOP_ITEMS) {
                try {
                    $gapResult = $gapService->analyzeKeywordGaps($id, ['title' => $title, 'category_id' => $catId]);
                } catch (\Throwable) {
                    // gap analysis é best-effort
                }
            }

            // Análise semântica profunda (top SEMANTIC_TOP_ITEMS críticos)
            $semanticResult = [];
            if ($deepSeo && $semanticService !== null && $scoreCount < self::SEMANTIC_TOP_ITEMS) {
                try {
                    $semanticResult = $semanticService->analyzeSemanticStructure([
                        'title'      => $title,
                        'description' => $item['descriptions'][0]['text'] ?? ($item['description'] ?? ''),
                        'category'   => $catId,
                        'attributes' => $item['attributes'] ?? [],
                    ]);
                } catch (\Throwable) {
                    // semântica é best-effort
                }
            }

            $auditItem = [
                'id'                => $id,
                'title'             => $title,
                'category_id'       => $catId,
                'status'            => $item['status'] ?? 'unknown',
                'price'             => (float) ($item['price'] ?? 0),
                'available_quantity' => (int) ($item['available_quantity'] ?? 0),
                'visits_30d'        => $item['_visits_30d'] ?? 0,
                'sales_30d'         => $item['_sales_30d'] ?? 0,
                'conversion_rate'   => $item['_conversion_rate'] ?? 0.0,
                'health_score'      => $item['_health_score'] ?? 0,
                'seo_score'         => $seoScore,
                'seo_breakdown'     => $this->seoBreakdown($title),
                'long_tail_keywords' => array_slice($longTailKws, 0, 10),
                'missing_keywords'  => array_slice($missingKws, 0, 8),
                'keyword_gaps'      => $gapResult['gap_analysis']['missing_keywords'] ?? [],
                'semantic_gaps'     => $gapResult['semantic_gaps'] ?? ($semanticResult['latent_semantic_analysis']['semantic_gaps'] ?? []),
                'semantic_clusters' => $semanticResult['semantic_core']['semantic_clusters'] ?? [],
                'actions'           => $this->generateItemActions($item, $seoScore, $missingKws),
            ];

            $auditItems[] = $auditItem;
            $totalSeoScore += $seoScore;
            $scoreCount++;
        }

        $avgSeo = $scoreCount > 0 ? (int) round($totalSeoScore / $scoreCount) : 0;

        // Classificar os piores anúncios SEO
        $worst = array_filter($auditItems, fn(array $i): bool => $i['seo_score'] < 50);
        usort($worst, fn(array $a, array $b): int => $a['seo_score'] <=> $b['seo_score']);

        return [
            'items'              => $auditItems,
            'avg_seo_score'      => $avgSeo,
            'items_below_50'     => count($worst),
            'total_analyzed'     => $scoreCount,
            'worst_items'        => array_values(array_slice($worst, 0, 10)),
            'top_missing_keywords' => $this->aggregateTopMissingKeywords($auditItems),
            'category_gaps'      => $this->aggregateCategoryGaps($auditItems),
            'long_tail_summary'  => $this->aggregateLongTailOpportunities($auditItems),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // PHASE 6: análise competitiva
    // ─────────────────────────────────────────────────────────

    private function runCompetitiveAnalysis(array $items, array $seoAudit): array
    {
        // Pegar categorias únicas dos top itens
        $categories = [];
        foreach (array_slice($items, 0, 30) as $item) {
            $catId = $item['category_id'] ?? '';
            if ($catId && !isset($categories[$catId])) {
                $categories[$catId] = 0;
            }
            if ($catId) {
                $categories[$catId]++;
            }
        }

        arsort($categories);
        $topCategories = array_keys(array_slice($categories, 0, 5, true));

        $competitiveInsights = [];
        foreach ($topCategories as $catId) {
            try {
                $insight = $this->analyzeCategoryCompetition($catId, $items, $seoAudit);
                if (!empty($insight)) {
                    $competitiveInsights[$catId] = $insight;
                }
            } catch (\Throwable $e) {
                $this->log('warning', 'Category competitive analysis failed', ['cat' => $catId, 'error' => $e->getMessage()]);
            }
            usleep(500_000); // 500ms entre categorias
        }

        // Calcular score competitivo geral
        $compScore = $this->calculateCompetitiveScore($competitiveInsights, $seoAudit);

        return [
            'score'              => $compScore,
            'categories_analyzed' => count($competitiveInsights),
            'insights'           => $competitiveInsights,
            'hidden_gaps'        => $this->extractHiddenGaps($competitiveInsights),
            'opportunity_keywords' => $this->extractOpportunityKeywords($competitiveInsights, $seoAudit),
        ];
    }

    private function analyzeCategoryCompetition(string $catId, array $myItems, array $seoAudit): array
    {
        // Buscar top vendedores da categoria
        $searchResult = $this->mlClient->searchItems([
            'category'    => $catId,
            'sort'        => 'sold_quantity_desc',
            'limit'       => self::MAX_COMPETITORS_PER_CAT,
        ], 600); // cache 10min

        $topCompetitors  = $searchResult['results'] ?? [];
        if (empty($topCompetitors)) {
            return [];
        }

        // Extrair keywords dos títulos dos concorrentes
        $competitorKeywords = [];
        foreach ($topCompetitors as $comp) {
            $kws = $this->extractKeywordsFromTitle($comp['title'] ?? '');
            foreach ($kws as $kw) {
                $competitorKeywords[$kw] = ($competitorKeywords[$kw] ?? 0) + 1;
            }
        }

        // Extrair keywords dos meus itens nessa categoria
        $myKeywords = [];
        foreach ($myItems as $item) {
            if (($item['category_id'] ?? '') !== $catId) {
                continue;
            }
            $kws = $this->extractKeywordsFromTitle($item['title'] ?? '');
            foreach ($kws as $kw) {
                $myKeywords[$kw] = true;
            }
        }

        // Calcular lacunas ocultas
        arsort($competitorKeywords);
        $hiddenGaps = [];
        foreach ($competitorKeywords as $kw => $freq) {
            if (!isset($myKeywords[$kw]) && $freq >= 2) {
                $hiddenGaps[] = ['keyword' => $kw, 'competitor_frequency' => $freq];
            }
        }

        // Calcular posição minha vs concorrentes
        $avgCompPrice = 0.0;
        $priceCount   = 0;
        foreach ($topCompetitors as $c) {
            if (isset($c['price']) && (float) $c['price'] > 0) {
                $avgCompPrice += (float) $c['price'];
                $priceCount++;
            }
        }
        $avgCompPrice = $priceCount > 0 ? round($avgCompPrice / $priceCount, 2) : 0.0;

        return [
            'category_id'     => $catId,
            'competitors'     => count($topCompetitors),
            'avg_comp_price'  => $avgCompPrice,
            'hidden_gaps'     => array_slice($hiddenGaps, 0, 15),
            'top_comp_keywords' => array_slice(array_keys($competitorKeywords), 0, 20),
            'my_keywords'     => array_keys($myKeywords),
            'keyword_overlap' => count(array_filter(
                array_keys($competitorKeywords),
                fn(string $kw): bool => isset($myKeywords[$kw])
            )),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // PHASE 8: score geral
    // ─────────────────────────────────────────────────────────

    private function calculateOverallScore(
        array $govResult,
        array $seoAudit,
        array $financialHealth,
        array $competitiveAnalysis
    ): int {
        // Score de saúde da conta (0-100) via governance
        $accountHealth = 100;
        $status = $govResult['classification']['account_status'] ?? '';
        $accountHealth = match ($status) {
            AccountGovernanceService::STATUS_TRAVADA     => 10,
            AccountGovernanceService::STATUS_PENALIZADA  => 25,
            AccountGovernanceService::STATUS_EM_RECUPERACAO => 45,
            AccountGovernanceService::STATUS_ESTAVEL     => 70,
            AccountGovernanceService::STATUS_FORTE       => 90,
            default => 50,
        };

        // Score SEO
        $seoScore = $seoAudit['avg_seo_score'] ?? 50;

        // Score financeiro
        $finScore = $financialHealth['financial_health']['score'] ?? 50;

        // Score competitivo
        $compScore = $competitiveAnalysis['score'] ?? 50;

        $overall = (int) round(
            $accountHealth  * self::W_ACCOUNT_HEALTH
                + $seoScore     * self::W_SEO_QUALITY
                + $finScore     * self::W_FINANCIAL
                + $compScore    * self::W_COMPETITIVE
        );

        return max(0, min(100, $overall));
    }

    // ─────────────────────────────────────────────────────────
    // PHASE 8b: diagnóstico
    // ─────────────────────────────────────────────────────────

    private function buildDiagnosis(
        array $sellerData,
        array $govResult,
        array $seoAudit,
        array $competitiveAnalysis,
        array $financialHealth
    ): array {
        $problems  = [];
        $strengths = [];

        $status = $govResult['classification']['account_status'] ?? '';

        // Problemas de conta
        if ($status === AccountGovernanceService::STATUS_TRAVADA) {
            $problems[] = [
                'severity' => 'CRITICO',
                'category' => 'CONTA',
                'message'  => 'Conta travada — algoritmo do ML penalizou por itens tóxicos ou métricas ruins',
                'action'   => 'Pausar imediatamente todos os itens TOXICO e POLUIDOR',
            ];
        }

        if ($sellerData['status_banned'] ?? false) {
            $problems[] = [
                'severity' => 'CRITICO',
                'category' => 'CONTA',
                'message'  => 'Conta banida no MShops — restrição de visibilidade ativa',
                'action'   => 'Contatar suporte ML e verificar reclamações pendentes',
            ];
        }

        // Reputação
        $repLevel = $sellerData['reputation_level'] ?? 'unknown';
        if (in_array($repLevel, ['1_red', '2_orange'], true)) {
            $problems[] = [
                'severity' => 'ALTO',
                'category' => 'REPUTACAO',
                'message'  => "Reputação baixa: nível {$repLevel} — impacto direto no posicionamento",
                'action'   => 'Resolver reclamações, melhorar prazo de envio, responder perguntas rapidamente',
            ];
        }

        // Problemas SEO
        if ($seoAudit['avg_seo_score'] < 50) {
            $problems[] = [
                'severity' => 'ALTO',
                'category' => 'SEO',
                'message'  => "Score SEO médio baixo: {$seoAudit['avg_seo_score']}/100 — anúncios mal posicionados",
                'action'   => 'Otimizar títulos com keywords de alta conversão e cauda longa',
            ];
        }

        // Problemas financeiros
        $mpRisk = $financialHealth['risk_level'] ?? 'UNKNOWN';
        if ($mpRisk === 'CRITICO' || $mpRisk === 'ALTO') {
            $problems[] = [
                'severity' => $mpRisk === 'CRITICO' ? 'CRITICO' : 'ALTO',
                'category' => 'FINANCEIRO',
                'message'  => "Risco financeiro {$mpRisk} — chargebacks e/ou saldo bloqueado elevado",
                'action'   => 'Resolver chargebacks pendentes e verificar disputes abertas no Mercado Pago',
            ];
        }

        // Lacunas competitivas
        $hiddenGaps = $competitiveAnalysis['hidden_gaps'] ?? [];
        if (count($hiddenGaps) > 10) {
            $problems[] = [
                'severity' => 'MEDIO',
                'category' => 'SEO_COMPETITIVO',
                'message'  => count($hiddenGaps) . ' keywords usadas por concorrentes que você não usa',
                'action'   => 'Incorporar keywords das lacunas ocultas nos títulos e fichas técnicas',
            ];
        }

        // Pontos fortes
        if ($status === AccountGovernanceService::STATUS_FORTE) {
            $strengths[] = 'Conta em excelente estado — aproveitar para expandir catálogo';
        }
        if ($seoAudit['avg_seo_score'] >= 75) {
            $strengths[] = 'Qualidade SEO acima da média — boa visibilidade orgânica';
        }
        $repRaw = $sellerData['reputation_raw'] ?? [];
        if (in_array($repLevel, ['5_green', '4_light_green'], true)) {
            $strengths[] = 'Reputação excelente — boost natural no algoritmo de busca';
        }
        if (($sellerData['power_seller_status'] ?? '') === 'gold' || ($sellerData['power_seller_status'] ?? '') === 'platinum') {
            $strengths[] = 'Power Seller — badge de confiança aumenta conversão';
        }

        // Contar itens por classificação
        $classifications = $govResult['items'] ?? [];
        $classCount = [];
        foreach ($classifications as $item) {
            $cls = $item['classification'] ?? 'unknown';
            $classCount[$cls] = ($classCount[$cls] ?? 0) + 1;
        }

        return [
            'problems'        => $problems,
            'strengths'       => $strengths,
            'critical_count'  => count(array_filter($problems, fn(array $p): bool => $p['severity'] === 'CRITICO')),
            'items_by_class'  => $classCount,
            'main_bottleneck' => $this->identifyMainBottleneck($problems, $status, $seoAudit),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // PHASE 9: plano de recuperação
    // ─────────────────────────────────────────────────────────

    private function buildRecoveryPlan(
        array $govResult,
        array $seoAudit,
        array $competitiveAnalysis,
        array $financialHealth,
        int $overallScore
    ): array {
        // Usar o plano do governance como base
        $govPlan  = $govResult['recovery_plan'] ?? [];
        $weekPlan = $govResult['week_plan'] ?? [];
        $actions  = $govResult['actions'] ?? [];

        // Enriquecer com ações SEO
        $seoActions = $this->buildSEOActions($seoAudit, $competitiveAnalysis);

        // Separar por prioridade
        $critical = array_filter(
            $actions,
            fn(array $a): bool => ($a['priority'] ?? '') === AccountGovernanceService::PRIORITY_CRITICA
        );
        $high = array_filter(
            $actions,
            fn(array $a): bool => ($a['priority'] ?? '') === AccountGovernanceService::PRIORITY_ALTA
        );

        return [
            'overall_score'    => $overallScore,
            'critical'         => array_values($critical),
            'high'             => array_values($high),
            'seo_actions'      => $seoActions,
            'week_plan'        => $weekPlan,
            'phases'           => $govPlan['phases'] ?? [],
            'success_criteria' => $govResult['success_criteria'] ?? [],
            'estimated_recovery_days' => $this->estimateRecoveryDays($overallScore),
        ];
    }

    /** @return array<int, array> */
    private function buildSEOActions(array $seoAudit, array $competitiveAnalysis): array
    {
        $actions = [];

        // Ação: otimizar piores títulos
        $worstItems = $seoAudit['worst_items'] ?? [];
        if (!empty($worstItems)) {
            $actions[] = [
                'type'        => 'OTIMIZAR_TITULOS',
                'priority'    => 'ALTA',
                'items_count' => count($worstItems),
                'description' => sprintf(
                    'Otimizar títulos de %d anúncios com score SEO abaixo de 50',
                    count($worstItems)
                ),
                'keywords_to_add' => array_slice($seoAudit['top_missing_keywords'] ?? [], 0, 10),
            ];
        }

        // Ação: incorporar lacunas ocultas
        $hiddenGaps = $competitiveAnalysis['hidden_gaps'] ?? [];
        if (!empty($hiddenGaps)) {
            $topGaps = array_slice($hiddenGaps, 0, 8);
            $actions[] = [
                'type'        => 'INCORPORAR_LACUNAS_OCULTAS',
                'priority'    => 'ALTA',
                'description' => 'Incorporar keywords das lacunas ocultas nos títulos — concorrentes usam e convertem',
                'keywords'    => array_column($topGaps, 'keyword'),
            ];
        }

        // Ação: keywords de cauda longa
        $ltOpps = $seoAudit['long_tail_summary'] ?? [];
        if (!empty($ltOpps)) {
            $actions[] = [
                'type'        => 'CAUDA_LONGA',
                'priority'    => 'MEDIA',
                'description' => 'Adicionar keywords de cauda longa nos títulos — menor concorrência, maior conversão',
                'keywords'    => array_slice($ltOpps, 0, 10),
            ];
        }

        return $actions;
    }

    // ─────────────────────────────────────────────────────────
    // SEO scoring
    // ─────────────────────────────────────────────────────────

    private function scoreSEOTitle(string $title): int
    {
        if (empty(trim($title))) {
            return 0;
        }

        $score = 0;
        $len   = mb_strlen($title);

        // Comprimento (30 pts)
        if ($len >= self::TITLE_OPT_MIN && $len <= self::TITLE_OPT_MAX) {
            $score += 30;
        } elseif ($len >= self::TITLE_MIN_LENGTH) {
            $score += 20;
        } elseif ($len > 15) {
            $score += 10;
        }

        // Uso de maiúsculas (não tudo maiúsculo, não tudo minúsculo) (15 pts)
        $isAllCaps   = mb_strtoupper($title) === $title;
        $isAllLower  = mb_strtolower($title) === $title;
        if (!$isAllCaps && !$isAllLower) {
            $score += 15;
        } elseif (!$isAllCaps) {
            $score += 5;
        }

        // Keyword numérica (compatibilidade/modelo) (10 pts)
        if (preg_match('/\d/', $title)) {
            $score += 10;
        }

        // Sem stop words no início (10 pts)
        $stopWords = ['o ', 'a ', 'os ', 'as ', 'um ', 'uma ', 'de ', 'para ', 'com ', 'em '];
        $titleLower = mb_strtolower(mb_substr($title, 0, 10));
        $hasStopStart = false;
        foreach ($stopWords as $sw) {
            if (str_starts_with($titleLower, $sw)) {
                $hasStopStart = true;
                break;
            }
        }
        if (!$hasStopStart) {
            $score += 10;
        }

        // Palavras potencialmente de conversão (15 pts)
        $conversionWords = ['original', 'universal', 'compatível', 'par', 'kit', 'novo', 'certificado', 'homologado'];
        $titleLowerFull  = mb_strtolower($title);
        foreach ($conversionWords as $w) {
            if (str_contains($titleLowerFull, $w)) {
                $score += 15;
                break;
            }
        }

        // Termos de compatibilidade (20 pts)
        $compTerms = ['cg', 'titan', 'fan', 'bros', 'xre', 'fazer', 'factor', 'honda', 'yamaha', 'suzuki', 'kawasaki'];
        foreach ($compTerms as $t) {
            if (str_contains($titleLowerFull, $t)) {
                $score += 20;
                break;
            }
        }

        return min(100, $score);
    }

    private function seoBreakdown(string $title): array
    {
        $len = mb_strlen($title);

        return [
            'length'        => $len,
            'length_status' => match (true) {
                $len >= self::TITLE_OPT_MIN && $len <= self::TITLE_OPT_MAX => 'ideal',
                $len >= self::TITLE_MIN_LENGTH                             => 'ok',
                default                                                     => 'curto',
            },
            'has_numbers'        => preg_match('/\d/', $title) === 1,
            'has_model_compat'   => (bool) preg_match('/\b(cg|titan|fan|bros|xre|fazer|factor|honda|yamaha)\b/i', $title),
            'has_conversion_word' => (bool) preg_match('/\b(original|universal|compatível|kit|par|novo)\b/i', $title),
        ];
    }

    /** @return array<string> */
    private function identifyMissingKeywords(array $item): array
    {
        $title    = mb_strtolower($item['title'] ?? '');
        $catId    = $item['category_id'] ?? '';
        $missing  = [];

        // Detectar tipo de produto e sugerir keywords faltantes
        if (str_contains($title, 'bagageiro') || str_contains($title, 'baú')) {
            if (!str_contains($title, 'moto')) {
                $missing[] = 'moto';
            }
            if (!str_contains($title, 'traseiro') && !str_contains($title, 'lateral')) {
                $missing[] = 'traseiro';
            }
            if (!str_contains($title, 'universal') && !str_contains($title, 'compatível')) {
                $missing[] = 'universal';
            }
        }

        if (str_contains($title, 'retrovisor')) {
            if (!preg_match('/\b(cg|titan|fan|bros|fazer|factor)\b/', $title)) {
                $missing[] = 'modelo moto';
            }
            if (!str_contains($title, 'par')) {
                $missing[] = 'par';
            }
        }

        if (str_contains($title, 'capacete')) {
            if (!preg_match('/\d{2,3}/', $title)) {
                $missing[] = 'tamanho';
            }
            if (!str_contains($title, 'homologado') && !str_contains($title, 'abnt')) {
                $missing[] = 'homologado';
            }
        }

        // Keywords genéricas que aumentam conversão
        if (!str_contains($title, 'original') && !str_contains($title, 'genuíno')) {
            $visits = $item['_visits_30d'] ?? 0;
            if ($visits > 5) {
                $missing[] = 'original';
            }
        }

        return array_values(array_unique($missing));
    }

    /** @return array<string> */
    private function extractKeywordsFromTitle(string $title): array
    {
        // Normalizar e extrair tokens relevantes
        $title = mb_strtolower($title);
        $title = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title) ?? $title;

        $stopWords = [
            'de',
            'para',
            'com',
            'em',
            'da',
            'do',
            'das',
            'dos',
            'e',
            'a',
            'o',
            'as',
            'os',
            'um',
            'uma',
            'no',
            'na',
            'nos',
            'nas',
            'ao',
            'aos',
            'pela',
            'pelo'
        ];

        $words = array_filter(
            explode(' ', $title),
            fn(string $w): bool => mb_strlen($w) >= 3 && !in_array($w, $stopWords, true)
        );

        return array_values(array_unique($words));
    }

    /** @return array<string> */
    private function aggregateTopMissingKeywords(array $auditItems): array
    {
        $counts = [];
        foreach ($auditItems as $item) {
            foreach ($item['missing_keywords'] ?? [] as $kw) {
                $counts[$kw] = ($counts[$kw] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_keys(array_slice($counts, 0, 15, true));
    }

    /** @return array<string, array> */
    private function aggregateCategoryGaps(array $auditItems): array
    {
        $catGaps = [];
        foreach ($auditItems as $item) {
            $cat = $item['category_id'] ?? 'unknown';
            if (!isset($catGaps[$cat])) {
                $catGaps[$cat] = [];
            }
            foreach ($item['keyword_gaps'] ?? [] as $kw) {
                if (is_string($kw)) {
                    $catGaps[$cat][] = $kw;
                }
            }
        }

        // Deduplicar
        foreach ($catGaps as $cat => $kws) {
            $catGaps[$cat] = array_values(array_unique($kws));
        }

        return $catGaps;
    }

    /** @return array<string> */
    private function aggregateLongTailOpportunities(array $auditItems): array
    {
        $all = [];
        foreach ($auditItems as $item) {
            foreach ($item['long_tail_keywords'] ?? [] as $kw) {
                if (is_string($kw)) {
                    $all[] = $kw;
                }
            }
        }
        return array_values(array_unique(array_slice($all, 0, 30)));
    }

    /** @return array<string> */
    private function extractHiddenGaps(array $competitiveInsights): array
    {
        $all = [];
        foreach ($competitiveInsights as $insight) {
            foreach ($insight['hidden_gaps'] ?? [] as $gap) {
                $kw = is_array($gap) ? ($gap['keyword'] ?? '') : (string) $gap;
                if ($kw !== '') {
                    $all[] = $kw;
                }
            }
        }
        return array_values(array_unique(array_slice($all, 0, 20)));
    }

    /** @return array<string> */
    private function extractOpportunityKeywords(array $competitiveInsights, array $seoAudit): array
    {
        $all = [];
        foreach ($competitiveInsights as $insight) {
            foreach ($insight['top_comp_keywords'] ?? [] as $kw) {
                if (!in_array($kw, $insight['my_keywords'] ?? [], true)) {
                    $all[] = $kw;
                }
            }
        }
        // Unir com missing keywords do SEO audit
        $all = array_merge($all, $seoAudit['top_missing_keywords'] ?? []);
        return array_values(array_unique(array_slice($all, 0, 25)));
    }

    private function calculateCompetitiveScore(array $insights, array $seoAudit): int
    {
        if (empty($insights)) {
            return 50;
        }

        $avgOverlap = 0;
        $count      = 0;

        foreach ($insights as $insight) {
            $myCount   = count($insight['my_keywords'] ?? []);
            $compCount = count($insight['top_comp_keywords'] ?? []);
            $overlap   = (int) ($insight['keyword_overlap'] ?? 0);

            if ($compCount > 0) {
                $avgOverlap += $overlap / $compCount;
                $count++;
            }
        }

        if ($count === 0) {
            return 50;
        }

        $overlapRatio = $avgOverlap / $count;
        $base = (int) round($overlapRatio * 100);

        // Ajustar pelo SEO score
        $seoBonus = (int) (($seoAudit['avg_seo_score'] ?? 50) * 0.2);

        return min(100, $base + $seoBonus);
    }

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────

    /** @return array<int, array> */
    private function generateItemActions(array $item, int $seoScore, array $missingKws): array
    {
        $actions = [];

        if ($seoScore < 50) {
            $actions[] = [
                'type'    => 'OTIMIZAR_TITULO',
                'urgency' => 'ALTA',
                'detail'  => 'Título com score SEO baixo (' . $seoScore . '). Adicionar: ' . implode(', ', array_slice($missingKws, 0, 3)),
            ];
        }

        if (($item['available_quantity'] ?? 0) === 0) {
            $actions[] = ['type' => 'REPOR_ESTOQUE', 'urgency' => 'CRITICA', 'detail' => 'Sem estoque — anúncio invisível'];
        }

        if (($item['_visits_30d'] ?? 0) === 0 && ($item['status'] ?? '') === 'active') {
            $actions[] = ['type' => 'REVISAR_ANUNCIO', 'urgency' => 'ALTA', 'detail' => 'Anúncio ativo mas com zero visitas em 30 dias'];
        }

        if (($item['_conversion_rate'] ?? 0) < 0.005 && ($item['_visits_30d'] ?? 0) > 20) {
            $actions[] = ['type' => 'OTIMIZAR_PRECO', 'urgency' => 'MEDIA', 'detail' => 'Muitas visitas mas conversão muito baixa — revisar preço e fotos'];
        }

        return $actions;
    }

    private function calculateReputationScore(array $rep, string $level, ?string $powerSeller): int
    {
        $base = match ($level) {
            '5_green'         => 90,
            '4_light_green'   => 70,
            '3_yellow'        => 50,
            '2_orange'        => 30,
            '1_red'           => 10,
            default           => 50,
        };

        if ($powerSeller === 'platinum') {
            $base = min(100, $base + 10);
        } elseif ($powerSeller === 'gold') {
            $base = min(100, $base + 5);
        }

        // Penalidade por cancellations
        $cancellationRate = (float) ($rep['transactions']['ratings']['negative'] ?? 0);
        if ($cancellationRate > 0.05) {
            $base = max(0, $base - 20);
        }

        return $base;
    }

    private function accountAgeDays(?string $registrationDate): int
    {
        if (empty($registrationDate)) {
            return 0;
        }
        try {
            $reg  = new \DateTimeImmutable($registrationDate);
            $now  = new \DateTimeImmutable();
            $diff = $now->diff($reg);
            return (int) $diff->days;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function buildAccountData(array $sellerData): array
    {
        $rep = $sellerData['reputation_raw'] ?? [];

        return [
            'seller_id'        => $sellerData['seller_id'] ?? '',
            'reputation_level' => $sellerData['reputation_level'] ?? 'unknown',
            'power_seller'     => $sellerData['power_seller_status'] ?? null,
            'reputation_score' => $sellerData['reputation_score'] ?? 50,
            'cancellations'    => (float) ($rep['transactions']['ratings']['negative'] ?? 0),
            'late_delivery'    => (float) ($rep['metrics']['late_delivery']['rate'] ?? 0),
            'claims'           => (float) ($rep['metrics']['claims']['rate'] ?? 0),
            'delayed_handling' => (float) ($rep['metrics']['delayed_handling_time']['rate'] ?? 0),
            'account_age_days' => $sellerData['account_age_days'] ?? 0,
        ];
    }

    private function identifyMainBottleneck(array $problems, string $status, array $seoAudit): string
    {
        // Verificar o problema mais crítico
        foreach ($problems as $p) {
            if ($p['severity'] === 'CRITICO') {
                return $p['category'] . ': ' . mb_substr($p['message'], 0, 100);
            }
        }

        if ($status === AccountGovernanceService::STATUS_TRAVADA) {
            return 'Conta travada pelo algoritmo ML';
        }

        if (($seoAudit['avg_seo_score'] ?? 100) < 50) {
            return 'SEO fraco — anúncios não aparecem nas buscas';
        }

        if (!empty($problems)) {
            return $problems[0]['category'] . ': ' . mb_substr($problems[0]['message'], 0, 80);
        }

        return 'Nenhum problema crítico identificado';
    }

    private function estimateRecoveryDays(int $overallScore): int
    {
        return match (true) {
            $overallScore < 20  => 90,
            $overallScore < 40  => 60,
            $overallScore < 60  => 30,
            $overallScore < 80  => 15,
            default             => 7,
        };
    }

    // ─────────────────────────────────────────────────────────
    // DB helpers
    // ─────────────────────────────────────────────────────────

    private function createReportRecord(array $options): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO account_xray_reports (account_id, status, options_json, started_at)
             VALUES (:account_id, :status, :options, NOW())'
        );
        $stmt->execute([
            'account_id' => $this->accountId,
            'status'     => 'pending',
            'options'    => json_encode($options),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function updateReportStatus(int $reportId, string $status): void
    {
        $this->db->prepare('UPDATE account_xray_reports SET status = :s WHERE id = :id')
            ->execute(['s' => $status, 'id' => $reportId]);
    }

    private function saveReport(
        int $reportId,
        array $report,
        int $overallScore,
        ?string $accountStatus,
        int $itemsTotal,
        int $itemsAnalyzed,
        int $criticalIssues
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE account_xray_reports SET
                status          = :status,
                score_overall   = :score,
                account_status  = :account_status,
                items_total     = :items_total,
                items_analyzed  = :items_analyzed,
                critical_issues = :critical_issues,
                report_json     = :report_json,
                seller_id       = :seller_id,
                nickname        = :nickname,
                completed_at    = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status'          => 'completed',
            'score'           => $overallScore,
            'account_status'  => $accountStatus,
            'items_total'     => $itemsTotal,
            'items_analyzed'  => $itemsAnalyzed,
            'critical_issues' => $criticalIssues,
            'report_json'     => json_encode($report, JSON_UNESCAPED_UNICODE),
            'seller_id'       => $report['meta']['seller_id'] ?? null,
            'nickname'        => $report['meta']['nickname'] ?? null,
            'id'              => $reportId,
        ]);
    }

    private function saveItemScores(int $reportId, array $auditItems, array $govItems): void
    {
        if (empty($auditItems)) {
            return;
        }

        // Mapear classificação do governance por item_id
        $classMap = [];
        foreach ($govItems as $gItem) {
            $id  = $gItem['id'] ?? '';
            $cls = $gItem['classification'] ?? null;
            if ($id !== '') {
                $classMap[$id] = $cls;
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO xray_item_scores
                (report_id, item_id, title, category_id, classification,
                 score_overall, score_seo, missing_keywords_json, gap_keywords_json, actions_json)
             VALUES
                (:report_id, :item_id, :title, :category_id, :classification,
                 :score_overall, :score_seo, :missing_json, :gap_json, :actions_json)'
        );

        foreach ($auditItems as $item) {
            $id  = $item['id'] ?? '';
            $seo = $item['seo_score'] ?? 0;

            $stmt->execute([
                'report_id'      => $reportId,
                'item_id'        => $id,
                'title'          => mb_substr($item['title'] ?? '', 0, 255),
                'category_id'    => $item['category_id'] ?? null,
                'classification' => $classMap[$id] ?? null,
                'score_overall'  => $seo,
                'score_seo'      => $seo,
                'missing_json'   => json_encode($item['missing_keywords'] ?? []),
                'gap_json'       => json_encode($item['keyword_gaps'] ?? []),
                'actions_json'   => json_encode($item['actions'] ?? []),
            ]);
        }
    }

    private function failReport(int $reportId, string $message): array
    {
        $this->db->prepare(
            'UPDATE account_xray_reports SET status = :s, error_message = :e, completed_at = NOW() WHERE id = :id'
        )->execute(['s' => 'failed', 'e' => $message, 'id' => $reportId]);

        return ['success' => false, 'error' => $message, 'report_id' => $reportId];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->{$level}($message, $context);
        }
    }
}
