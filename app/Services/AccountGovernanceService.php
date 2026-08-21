<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

/**
 * AccountGovernanceService - Motor de Governança e Recuperação de Conta ML
 *
 * Pure computation engine:
 * - Recebe accountData + items
 * - Produz: item scoring, classification, account status, recovery plan, week plan, actions
 * - Não faz chamadas de API nem acessa banco de dados
 *
 * @version 1.0.0
 */
class AccountGovernanceService
{
    // ═══════════════════════════════════════════════════════════════════════
    // CONSTANTS: Item Classifications
    // ═══════════════════════════════════════════════════════════════════════

    public const CLASS_SEM_ESTOQUE = 'SEM_ESTOQUE';
    public const CLASS_TOXICO = 'TOXICO';
    public const CLASS_POLUIDOR = 'POLUIDOR';
    public const CLASS_MORTO = 'MORTO';
    public const CLASS_FRACO = 'FRACO';
    public const CLASS_EM_RISCO = 'EM_RISCO';
    public const CLASS_SAUDAVEL = 'SAUDAVEL';
    public const CLASS_ANCHOR = 'ANCHOR';

    // ═══════════════════════════════════════════════════════════════════════
    // CONSTANTS: Account Status
    // ═══════════════════════════════════════════════════════════════════════

    public const STATUS_TRAVADA = 'TRAVADA';
    public const STATUS_PENALIZADA = 'PENALIZADA';
    public const STATUS_EM_RECUPERACAO = 'EM_RECUPERACAO';
    public const STATUS_ESTAVEL = 'ESTAVEL';
    public const STATUS_FORTE = 'FORTE';

    // ═══════════════════════════════════════════════════════════════════════
    // CONSTANTS: Action Types
    // ═══════════════════════════════════════════════════════════════════════

    public const ACTION_PAUSAR = 'PAUSAR';
    public const ACTION_REATIVAR = 'REATIVAR';
    public const ACTION_REPOR_ESTOQUE = 'REPOR_ESTOQUE';
    public const ACTION_OTIMIZAR_TITULO = 'OTIMIZAR_TITULO';
    public const ACTION_OTIMIZAR_PRECO = 'OTIMIZAR_PRECO';
    public const ACTION_MELHORAR_FOTOS = 'MELHORAR_FOTOS';
    public const ACTION_MONITORAR = 'MONITORAR';
    public const ACTION_PROTEGER = 'PROTEGER';

    // ═══════════════════════════════════════════════════════════════════════
    // CONSTANTS: Action Priorities
    // ═══════════════════════════════════════════════════════════════════════

    public const PRIORITY_CRITICA = 'CRITICA';
    public const PRIORITY_ALTA = 'ALTA';
    public const PRIORITY_MEDIA = 'MEDIA';
    public const PRIORITY_BAIXA = 'BAIXA';

    // ═══════════════════════════════════════════════════════════════════════
    // CONSTANTS: Recovery Phases
    // ═══════════════════════════════════════════════════════════════════════

    public const PHASE_ESTANCAR = 'ESTANCAR';
    public const PHASE_ESTABILIZAR = 'ESTABILIZAR';
    public const PHASE_CRESCER = 'CRESCER';

    // ═══════════════════════════════════════════════════════════════════════
    // THRESHOLDS
    // ═══════════════════════════════════════════════════════════════════════

    private const TRAFFIC_HIGH_THRESHOLD = 0.15;
    private const TRAFFIC_LOW_THRESHOLD = 0.03;
    private const LOW_STOCK_THRESHOLD = 3;
    private const CONV_VERY_BAD_THRESHOLD = 0.005;
    private const CONV_BAD_THRESHOLD = 0.015;
    private const FALLING_TREND_THRESHOLD = -0.30;
    private const STALE_VISITS_THRESHOLD = 10;
    private const ANCHOR_SCORE_THRESHOLD = 80;
    private const HEALTHY_SCORE_THRESHOLD = 55;
    private const GUARDRAIL_MAX_PAUSE_PCT = 0.30;

    // ═══════════════════════════════════════════════════════════════════════
    // PROPERTIES
    // ═══════════════════════════════════════════════════════════════════════

    private float $defaultMinMarginPct;
    private float $maxPriceDropPct;
    private ?Logger $logger;

    // ═══════════════════════════════════════════════════════════════════════
    // CONSTRUCTOR
    // ═══════════════════════════════════════════════════════════════════════

    public function __construct(
        float $defaultMinMarginPct = 0.05,
        float $maxPriceDropPct = 0.15,
        ?Logger $logger = null
    ) {
        $this->defaultMinMarginPct = $defaultMinMarginPct;
        $this->maxPriceDropPct = $maxPriceDropPct;
        $this->logger = $logger;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MAIN PIPELINE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Run the full diagnostic pipeline
     *
     * @param array $accountData Account data with seller_id, reputation_level, etc.
     * @param array $items Array of item data
     * @param array $sellerContext Optional seller context
     * @return array Complete diagnostic result
     */
    public function runFullDiagnostic(array $accountData, array $items, array $sellerContext = []): array
    {
        $startTime = hrtime(true);

        // Step 1: Validate input
        $this->validateInput($accountData, $items);

        // Step 2: Calculate account-level metrics
        $metrics = $this->calculateAccountMetrics($accountData, $items);

        // Step 3: Process each item (flags, score, classification)
        $processedItems = [];
        foreach ($items as $item) {
            $processedItems[] = $this->processItem($item, $metrics);
        }

        // Step 4: Classify account status
        $accountStatus = $this->classifyAccount($metrics, $processedItems);

        // Step 5: Generate actions for each item
        foreach ($processedItems as &$item) {
            $item['actions'] = $this->generateActions($item, $sellerContext);
        }
        unset($item);

        // Step 6: Apply guardrails
        $processedItems = $this->applyGuardrails($processedItems, $metrics);

        // Step 7: Detect density risks
        $densityRisks = $this->detectDensityRisks($processedItems, $sellerContext);

        // Step 8: Generate recovery plan
        $recoveryPlan = $this->generateRecoveryPlan($accountStatus, $processedItems, $sellerContext);

        // Step 9: Identify top causes
        $topCauses = $this->identifyTopCauses($processedItems, $metrics);

        // Step 10: Generate week plan
        $weekPlan = $this->generateWeekPlan($processedItems, $recoveryPlan);

        // Step 11: Generate executive summary
        $executiveSummary = $this->generateExecutiveSummary($accountStatus, $metrics, $processedItems, $topCauses);

        // Step 12: Build success/rollback criteria
        $successCriteria = $this->buildSuccessCriteria($accountStatus, $metrics);

        $elapsedMs = round((hrtime(true) - $startTime) / 1_000_000, 2);

        return [
            'executive_summary' => $executiveSummary,
            'account_status' => $accountStatus,
            'account_metrics' => $metrics,
            'top_causes' => $topCauses,
            'week_plan' => $weekPlan,
            'recovery_plan' => $recoveryPlan,
            'density_risks' => $densityRisks,
            'items' => $processedItems,
            'success_criteria' => $successCriteria['success'],
            'rollback_criteria' => $successCriteria['rollback'],
            'guardrails_applied' => true,
            'meta' => [
                'total_items' => count($items),
                'processed_items' => count($processedItems),
                'elapsed_ms' => $elapsedMs,
                'engine_version' => '1.0.0',
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // VALIDATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Validate input data
     *
     * @throws \InvalidArgumentException if validation fails
     */
    public function validateInput(array $accountData, array $items): void
    {
        if (empty($accountData['seller_id'])) {
            throw new \InvalidArgumentException('Campo obrigatório ausente: seller_id');
        }

        if (!isset($accountData['reputation_level'])) {
            throw new \InvalidArgumentException('Campo obrigatório ausente: reputation_level');
        }

        if (empty($items)) {
            throw new \InvalidArgumentException('Lista de itens vazia');
        }

        $requiredItemFields = ['id', 'title', 'price', 'status', 'available_quantity'];
        foreach ($items as $index => $item) {
            foreach ($requiredItemFields as $field) {
                if (!isset($item[$field])) {
                    throw new \InvalidArgumentException("Campo obrigatório ausente no item [{$index}]: {$field}");
                }
            }
        }
    }


    /**
     * Copy AccountXRayService enrich keys onto the unprefixed names that
     * calculateFlags / calculateAccountMetrics read.
     *
     * Enrich writes _visits_30d / _sales_30d (and optionally 14d). Flags
     * read visits_30d / sales_30d. Without this copy every item looks like
     * 0 visits/sales → MORTO → account TRAVADA.
     *
     * Prefixed values win when present so freshly enriched metrics override
     * a missing or stale unprefixed key. Unprefixed-only payloads are left
     * unchanged (existing tests / non-XRay callers).
     */
    public static function normalizeItemMetricKeys(array $item): array
    {
        $pairs = [
            'visits_30d' => '_visits_30d',
            'sales_30d'  => '_sales_30d',
            'visits_14d' => '_visits_14d',
            'sales_14d'  => '_sales_14d',
        ];

        foreach ($pairs as $plain => $prefixed) {
            if (array_key_exists($prefixed, $item)) {
                $item[$plain] = $item[$prefixed];
            }
        }

        return $item;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ACCOUNT METRICS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Calculate account-level metrics from items
     */
    public function calculateAccountMetrics(array $accountData, array $items): array
    {
        $totalItems = count($items);
        $activeItems = 0;
        $pausedItems = 0;
        $totalVisits30d = 0;
        $totalSales30d = 0;
        $oosCount = 0;

        foreach ($items as $item) {
            $status = $item['status'] ?? 'unknown';
            if ($status === 'active') {
                $activeItems++;
                if (($item['available_quantity'] ?? 0) <= 0) {
                    $oosCount++;
                }
            } elseif ($status === 'paused') {
                $pausedItems++;
            }
            $item = self::normalizeItemMetricKeys($item);
            $totalVisits30d += (int)($item['visits_30d'] ?? 0);
            $totalSales30d += (int)($item['sales_30d'] ?? 0);
        }

        $accountConv30d = $totalVisits30d > 0 ? $totalSales30d / $totalVisits30d : 0;

        return [
            'total_items' => $totalItems,
            'active_items' => $activeItems,
            'paused_items' => $pausedItems,
            'total_visits_30d' => $totalVisits30d,
            'total_sales_30d' => $totalSales30d,
            'oos_count' => $oosCount,
            'account_conv_30d' => $accountConv30d,
            'reputation_level' => $accountData['reputation_level'] ?? 'unknown',
            'claims_rate' => $accountData['claims_rate'] ?? 0,
            'late_shipment_rate' => $accountData['late_shipment_rate'] ?? 0,
            'cancellation_rate' => $accountData['cancellation_rate'] ?? 0,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ITEM PROCESSING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process a single item: calculate flags, score, and classification
     */
    private function processItem(array $item, array $metrics): array
    {
        $item = self::normalizeItemMetricKeys($item);
        $flags = $this->calculateFlags($item, $metrics);
        $score = $this->calculateItemScore($item, $flags, $metrics);
        $classification = $this->classifyItem($item, $flags, $score);

        return array_merge($item, [
            'flags' => $flags,
            'score' => $score,
            'classification' => $classification,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FLAGS CALCULATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Calculate flags for an item
     */
    public function calculateFlags(array $item, array $metrics): array
    {
        $item = self::normalizeItemMetricKeys($item);
        $visits30d = (int)($item['visits_30d'] ?? 0);
        $visits14d = (int)($item['visits_14d'] ?? 0);
        $sales30d = (int)($item['sales_30d'] ?? 0);
        $sales14d = (int)($item['sales_14d'] ?? 0);
        $stock = (int)($item['available_quantity'] ?? 0);
        $totalVisits = max(1, $metrics['total_visits_30d'] ?? 1);

        // Traffic flags
        $trafficShare = $visits30d / $totalVisits;
        $highTraffic = $trafficShare >= self::TRAFFIC_HIGH_THRESHOLD;
        $lowTraffic = $trafficShare < self::TRAFFIC_LOW_THRESHOLD && $visits30d < 50;
        $medTraffic = !$highTraffic && !$lowTraffic;

        // Stock flags
        $oos = $stock <= 0;
        $lowStock = !$oos && $stock <= self::LOW_STOCK_THRESHOLD;

        // Sales flags
        $noSales30 = $sales30d === 0;
        $noSales14 = $sales14d === 0;

        // Conversion flags (only meaningful with minimum traffic)
        $conv = $visits30d > 0 ? $sales30d / $visits30d : 0;
        $veryBadConv = $visits30d >= 100 && $conv < self::CONV_VERY_BAD_THRESHOLD;
        $badConv = $visits30d >= 50 && $conv < self::CONV_BAD_THRESHOLD && !$veryBadConv;

        // Trend flags
        $projected14d = $visits14d * (30 / 14);
        $trend = $visits30d > 0 ? ($projected14d - $visits30d) / $visits30d : 0;
        $falling = $trend < self::FALLING_TREND_THRESHOLD;

        // Stale flag
        $stale = $visits30d < self::STALE_VISITS_THRESHOLD && $noSales30;

        return [
            'HIGH_TRAFFIC' => $highTraffic,
            'MED_TRAFFIC' => $medTraffic,
            'LOW_TRAFFIC' => $lowTraffic,
            'LOW_STOCK' => $lowStock,
            'OOS' => $oos,
            'NO_SALES_30' => $noSales30,
            'NO_SALES_14' => $noSales14,
            'BAD_CONV' => $badConv,
            'VERY_BAD_CONV' => $veryBadConv,
            'FALLING' => $falling,
            'STALE' => $stale,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ITEM SCORING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Calculate item score (0-100)
     */
    public function calculateItemScore(array $item, array $flags, array $metrics): int
    {
        $score = 100;

        // Major penalties
        if ($flags['NO_SALES_30'] ?? false) {
            $score -= 30;
        }
        if ($flags['VERY_BAD_CONV'] ?? false) {
            $score -= 25;
        }
        if ($flags['OOS'] ?? false) {
            $score -= 20;
        }

        // Medium penalties
        if ($flags['BAD_CONV'] ?? false) {
            $score -= 15;
        }
        if ($flags['LOW_STOCK'] ?? false) {
            $score -= 10;
        }
        if ($flags['FALLING'] ?? false) {
            $score -= 12;
        }

        // Minor penalties
        if ($flags['LOW_TRAFFIC'] ?? false) {
            $score -= 7;
        }
        if ($flags['STALE'] ?? false) {
            $score -= 4;
        }
        if ($flags['NO_SALES_14'] ?? false) {
            $score -= 5;
        }

        // Margin penalty
        $margin = $item['margin_pct'] ?? $this->defaultMinMarginPct;
        if ($margin < $this->defaultMinMarginPct) {
            $score -= 8;
        }

        return max(0, min(100, $score));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ITEM CLASSIFICATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Classify item based on flags and score
     */
    public function classifyItem(array $item, array $flags, int $score): string
    {
        // SEM_ESTOQUE: active item with no stock
        if (($flags['OOS'] ?? false) && ($item['status'] ?? '') === 'active') {
            return self::CLASS_SEM_ESTOQUE;
        }

        // TOXICO: high traffic but very bad conversion
        if (($flags['HIGH_TRAFFIC'] ?? false) && ($flags['VERY_BAD_CONV'] ?? false)) {
            return self::CLASS_TOXICO;
        }

        // POLUIDOR: medium traffic with bad conversion
        if (($flags['MED_TRAFFIC'] ?? false) && ($flags['BAD_CONV'] ?? false)) {
            return self::CLASS_POLUIDOR;
        }

        // MORTO: low traffic, no sales, stale
        if (($flags['LOW_TRAFFIC'] ?? false) && ($flags['NO_SALES_30'] ?? false) && ($flags['STALE'] ?? false)) {
            return self::CLASS_MORTO;
        }

        // FRACO: no sales but not stale (some traffic)
        if (($flags['NO_SALES_30'] ?? false) && !($flags['STALE'] ?? false)) {
            return self::CLASS_FRACO;
        }

        // EM_RISCO: falling trend
        if ($flags['FALLING'] ?? false) {
            return self::CLASS_EM_RISCO;
        }

        // ANCHOR: high score with good traffic
        if ($score >= self::ANCHOR_SCORE_THRESHOLD && ($flags['HIGH_TRAFFIC'] ?? false)) {
            return self::CLASS_ANCHOR;
        }

        // SAUDAVEL: decent score and no major flags
        if ($score >= self::HEALTHY_SCORE_THRESHOLD) {
            return self::CLASS_SAUDAVEL;
        }

        // Default to EM_RISCO for anything else
        return self::CLASS_EM_RISCO;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ACCOUNT CLASSIFICATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Classify account status based on metrics and item classifications
     */
    public function classifyAccount(array $metrics, array $items): string
    {
        $totalItems = count($items);
        if ($totalItems === 0) {
            return self::STATUS_EM_RECUPERACAO;
        }

        // Count problem items
        $problemClasses = [
            self::CLASS_TOXICO,
            self::CLASS_POLUIDOR,
            self::CLASS_MORTO,
            self::CLASS_SEM_ESTOQUE,
        ];

        $healthyClasses = [
            self::CLASS_ANCHOR,
            self::CLASS_SAUDAVEL,
        ];

        $problemCount = 0;
        $healthyCount = 0;

        foreach ($items as $item) {
            $class = $item['classification'] ?? '';
            if (in_array($class, $problemClasses, true)) {
                $problemCount++;
            }
            if (in_array($class, $healthyClasses, true)) {
                $healthyCount++;
            }
        }

        $problemRatio = $problemCount / $totalItems;
        $healthyRatio = $healthyCount / $totalItems;
        $accountConv = $metrics['account_conv_30d'] ?? 0;
        $reputation = $metrics['reputation_level'] ?? 'unknown';

        // TRAVADA: very low conversion OR problem ratio > 50%
        if ($accountConv < 0.003 || $problemRatio > 0.5) {
            return self::STATUS_TRAVADA;
        }

        // PENALIZADA: bad reputation + high problem ratio
        $badRep = in_array($reputation, ['red', 'orange', 'yellow'], true);
        if ($badRep && ($problemRatio > 0.3 || ($metrics['claims_rate'] ?? 0) > 0.03 || ($metrics['late_shipment_rate'] ?? 0) > 0.05)) {
            return self::STATUS_PENALIZADA;
        }

        // FORTE: high healthy ratio with good reputation
        if ($healthyRatio > 0.7 && in_array($reputation, ['green', 'light_green'], true) && $accountConv >= 0.02) {
            return self::STATUS_FORTE;
        }

        // ESTAVEL: healthy ratio > 50%, no critical issues
        if ($healthyRatio > 0.5 && $problemRatio < 0.2) {
            return self::STATUS_ESTAVEL;
        }

        // Default
        return self::STATUS_EM_RECUPERACAO;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ACTION GENERATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate actions for an item based on classification and flags
     */
    public function generateActions(array $item, array $context): array
    {
        $actions = [];
        $classification = $item['classification'] ?? '';
        $flags = $item['flags'] ?? [];

        switch ($classification) {
            case self::CLASS_TOXICO:
                $actions[] = $this->buildAction(
                    self::ACTION_PAUSAR,
                    self::PRIORITY_CRITICA,
                    'Anúncio tóxico - alto tráfego com conversão muito baixa, prejudicando a conta',
                    'Parar hemorragia de conversão da conta',
                    'Perda de exposição temporária',
                    '24h',
                    'Reativar após 7 dias com otimização completa'
                );
                break;

            case self::CLASS_SEM_ESTOQUE:
                $actions[] = $this->buildAction(
                    self::ACTION_REPOR_ESTOQUE,
                    self::PRIORITY_CRITICA,
                    'Item ativo sem estoque - frustração do cliente e perda de vendas',
                    'Evitar cancelamentos e reclamações',
                    'Custo de reposição',
                    '48h',
                    'Pausar se não houver previsão de estoque'
                );
                break;

            case self::CLASS_POLUIDOR:
                $actions[] = $this->buildAction(
                    self::ACTION_OTIMIZAR_TITULO,
                    self::PRIORITY_ALTA,
                    'Conversão baixa - título pode não estar atraindo público certo',
                    'Melhorar relevância e CTR',
                    'Baixo - alteração de texto',
                    '72h',
                    'Reverter título anterior se piorar'
                );
                $actions[] = $this->buildAction(
                    self::ACTION_OTIMIZAR_PRECO,
                    self::PRIORITY_ALTA,
                    'Preço pode estar fora do mercado',
                    'Aumentar competitividade',
                    'Margem reduzida',
                    '72h',
                    'Restaurar preço anterior'
                );
                break;

            case self::CLASS_MORTO:
                $actions[] = $this->buildAction(
                    self::ACTION_PAUSAR,
                    self::PRIORITY_MEDIA,
                    'Item sem tráfego e sem vendas - poluindo catálogo',
                    'Limpar catálogo de itens inativos',
                    'Baixo',
                    '7d',
                    'Reativar com nova estratégia SEO'
                );
                break;

            case self::CLASS_FRACO:
                $actions[] = $this->buildAction(
                    self::ACTION_OTIMIZAR_TITULO,
                    self::PRIORITY_MEDIA,
                    'Item com tráfego mas sem conversão',
                    'Melhorar taxa de conversão',
                    'Baixo',
                    '5d',
                    'Testar variações de título'
                );
                break;

            case self::CLASS_EM_RISCO:
                $actions[] = $this->buildAction(
                    self::ACTION_MONITORAR,
                    self::PRIORITY_MEDIA,
                    'Tendência de queda detectada',
                    'Identificar causa da queda',
                    'Baixo',
                    '7d',
                    'Intervir se continuar caindo'
                );
                break;

            case self::CLASS_ANCHOR:
                // Anchor items need protection
                if ($flags['LOW_STOCK'] ?? false) {
                    $actions[] = $this->buildAction(
                        self::ACTION_REPOR_ESTOQUE,
                        self::PRIORITY_CRITICA,
                        'Anúncio âncora com estoque baixo - risco de perder vendas',
                        'Proteger faturamento principal',
                        'Custo de reposição prioritária',
                        '24h',
                        'N/A - ação preventiva'
                    );
                }
                $actions[] = $this->buildAction(
                    self::ACTION_PROTEGER,
                    self::PRIORITY_BAIXA,
                    'Anúncio âncora - manter e proteger',
                    'Preservar performance',
                    'Nenhum',
                    'Contínuo',
                    'N/A'
                );
                break;

            case self::CLASS_SAUDAVEL:
                $actions[] = $this->buildAction(
                    self::ACTION_MONITORAR,
                    self::PRIORITY_BAIXA,
                    'Item saudável - monitoramento padrão',
                    'Manter performance',
                    'Nenhum',
                    'Semanal',
                    'N/A'
                );
                break;
        }

        // Additional actions based on flags
        if (($flags['LOW_STOCK'] ?? false) && $classification !== self::CLASS_ANCHOR) {
            $actions[] = $this->buildAction(
                self::ACTION_REPOR_ESTOQUE,
                self::PRIORITY_ALTA,
                'Estoque baixo detectado',
                'Evitar ruptura',
                'Custo de reposição',
                '48h',
                'Pausar se necessário'
            );
        }

        // Sort by priority
        usort($actions, function ($a, $b) {
            $priorities = [
                self::PRIORITY_CRITICA => 1,
                self::PRIORITY_ALTA => 2,
                self::PRIORITY_MEDIA => 3,
                self::PRIORITY_BAIXA => 4,
            ];
            return ($priorities[$a['prioridade']] ?? 5) <=> ($priorities[$b['prioridade']] ?? 5);
        });

        return $actions;
    }

    /**
     * Build a standardized action array
     */
    private function buildAction(
        string $tipo,
        string $prioridade,
        string $motivo,
        string $impacto,
        string $risco,
        string $janela,
        string $rollback
    ): array {
        return [
            'tipo' => $tipo,
            'prioridade' => $prioridade,
            'motivo' => $motivo,
            'impacto' => $impacto,
            'risco' => $risco,
            'janela' => $janela,
            'rollback' => $rollback,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GUARDRAILS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Apply guardrails to prevent excessive pausing
     */
    public function applyGuardrails(array $items, array $metrics): array
    {
        $totalItems = count($items);
        $currentPaused = $metrics['paused_items'] ?? 0;
        $maxPauses = (int)floor($totalItems * self::GUARDRAIL_MAX_PAUSE_PCT);
        $pausesAllowed = max(0, $maxPauses - $currentPaused);
        $pauseCount = 0;

        foreach ($items as &$item) {
            foreach ($item['actions'] as &$action) {
                if ($action['tipo'] === self::ACTION_PAUSAR) {
                    if ($pauseCount >= $pausesAllowed) {
                        $action['guardrail_blocked'] = true;
                        $action['guardrail_reason'] = "Limite de pausas atingido ({$maxPauses} max, {$currentPaused} já pausados)";
                    } else {
                        $pauseCount++;
                        $action['guardrail_blocked'] = false;
                    }
                }
            }
            unset($action);
        }
        unset($item);

        return $items;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DENSITY RISK DETECTION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Detect density risks (concentration of problem items)
     */
    public function detectDensityRisks(array $items, array $context): array
    {
        $risks = [];
        $totalItems = count($items);
        if ($totalItems === 0) {
            return $risks;
        }

        // Count by classification
        $classificationCounts = [];
        $problemClasses = [
            self::CLASS_TOXICO,
            self::CLASS_POLUIDOR,
            self::CLASS_MORTO,
            self::CLASS_SEM_ESTOQUE,
        ];

        $totalProblems = 0;
        foreach ($items as $item) {
            $class = $item['classification'] ?? '';
            $classificationCounts[$class] = ($classificationCounts[$class] ?? 0) + 1;
            if (in_array($class, $problemClasses, true)) {
                $totalProblems++;
            }
        }

        // HIGH_DENSITY: any problem class > 15%
        foreach ($problemClasses as $class) {
            $count = $classificationCounts[$class] ?? 0;
            $ratio = $count / $totalItems;
            if ($ratio >= 0.15) {
                $risks[] = [
                    'type' => 'HIGH_DENSITY',
                    'category' => $class,
                    'count' => $count,
                    'ratio' => round($ratio, 3),
                    'severity' => $ratio >= 0.25 ? 'critical' : 'warning',
                    'recommendation' => "Concentração alta de itens {$class} ({$count} itens). Ação imediata recomendada.",
                ];
            }
        }

        // GLOBAL_CONTAMINATION: total problems > 40%
        $problemRatio = $totalProblems / $totalItems;
        if ($problemRatio >= 0.40) {
            $risks[] = [
                'type' => 'GLOBAL_CONTAMINATION',
                'category' => 'ALL_PROBLEMS',
                'count' => $totalProblems,
                'ratio' => round($problemRatio, 3),
                'severity' => 'critical',
                'recommendation' => 'Mais de 40% do catálogo está problemático. Plano de recuperação urgente.',
            ];
        }

        return $risks;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // RECOVERY PLAN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate 3-phase recovery plan
     */
    public function generateRecoveryPlan(string $accountStatus, array $items, array $context): array
    {
        $phases = [
            [
                'name' => self::PHASE_ESTANCAR,
                'duration' => '1-3 dias',
                'objective' => 'Parar a hemorragia - eliminar itens tóxicos e críticos',
                'items' => [],
                'kpis' => ['Redução de itens tóxicos para 0', 'Estoque crítico reposto'],
            ],
            [
                'name' => self::PHASE_ESTABILIZAR,
                'duration' => '4-14 dias',
                'objective' => 'Estabilizar a conta - otimizar poluidores e fracos',
                'items' => [],
                'kpis' => ['Conversão geral > 1%', 'Redução de reclamações'],
            ],
            [
                'name' => self::PHASE_CRESCER,
                'duration' => '15-30 dias',
                'objective' => 'Crescimento sustentável - fortalecer âncoras',
                'items' => [],
                'kpis' => ['Aumento de 10% em vendas', 'Manutenção de reputação'],
            ],
        ];

        // Assign items to phases based on priority
        foreach ($items as $item) {
            $actions = $item['actions'] ?? [];
            if (empty($actions)) {
                continue;
            }

            $topPriority = $actions[0]['prioridade'] ?? self::PRIORITY_BAIXA;

            if ($topPriority === self::PRIORITY_CRITICA) {
                $phases[0]['items'][] = [
                    'id' => $item['id'] ?? '',
                    'title' => $item['title'] ?? '',
                    'action' => $actions[0]['tipo'] ?? '',
                ];
            } elseif ($topPriority === self::PRIORITY_ALTA) {
                $phases[1]['items'][] = [
                    'id' => $item['id'] ?? '',
                    'title' => $item['title'] ?? '',
                    'action' => $actions[0]['tipo'] ?? '',
                ];
            } else {
                $phases[2]['items'][] = [
                    'id' => $item['id'] ?? '',
                    'title' => $item['title'] ?? '',
                    'action' => $actions[0]['tipo'] ?? '',
                ];
            }
        }

        return [
            'status' => $accountStatus,
            'phases' => $phases,
            'estimated_recovery' => $this->estimateRecoveryTime($accountStatus),
        ];
    }

    /**
     * Estimate recovery time based on account status
     */
    private function estimateRecoveryTime(string $status): string
    {
        return match ($status) {
            self::STATUS_TRAVADA => '30-45 dias',
            self::STATUS_PENALIZADA => '21-30 dias',
            self::STATUS_EM_RECUPERACAO => '14-21 dias',
            self::STATUS_ESTAVEL => '7-14 dias',
            self::STATUS_FORTE => 'Manutenção contínua',
            default => '21-30 dias',
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TOP CAUSES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Identify top 5 causes of account problems
     */
    public function identifyTopCauses(array $items, array $metrics): array
    {
        $causes = [];

        // Count problem types
        $toxicCount = 0;
        $oosCount = 0;
        $pollutorCount = 0;
        $deadCount = 0;
        $fallingCount = 0;

        foreach ($items as $item) {
            $class = $item['classification'] ?? '';
            $flags = $item['flags'] ?? [];

            match ($class) {
                self::CLASS_TOXICO => $toxicCount++,
                self::CLASS_SEM_ESTOQUE => $oosCount++,
                self::CLASS_POLUIDOR => $pollutorCount++,
                self::CLASS_MORTO => $deadCount++,
                default => null,
            };

            if ($flags['FALLING'] ?? false) {
                $fallingCount++;
            }
        }

        // Build cause list with impact scores
        if ($toxicCount > 0) {
            $causes[] = [
                'cause' => 'Itens tóxicos',
                'description' => "Há {$toxicCount} itens com alto tráfego e conversão muito baixa",
                'impact_score' => $toxicCount * 10,
                'fix' => 'Pausar imediatamente e revisar antes de reativar',
            ];
        }

        if ($oosCount > 0) {
            $causes[] = [
                'cause' => 'Ruptura de estoque',
                'description' => "Há {$oosCount} itens ativos sem estoque",
                'impact_score' => $oosCount * 8,
                'fix' => 'Repor estoque ou pausar itens',
            ];
        }

        if ($pollutorCount > 0) {
            $causes[] = [
                'cause' => 'Conversão baixa',
                'description' => "Há {$pollutorCount} itens poluidores com conversão ruim",
                'impact_score' => $pollutorCount * 5,
                'fix' => 'Otimizar títulos, fotos e preços',
            ];
        }

        if ($deadCount > 0) {
            $causes[] = [
                'cause' => 'Itens mortos',
                'description' => "Há {$deadCount} itens sem tráfego nem vendas",
                'impact_score' => $deadCount * 3,
                'fix' => 'Pausar ou renovar estratégia SEO',
            ];
        }

        if (($metrics['claims_rate'] ?? 0) > 0.03) {
            $causes[] = [
                'cause' => 'Alta taxa de reclamações',
                'description' => 'Taxa de reclamações acima do aceitável',
                'impact_score' => 50,
                'fix' => 'Revisar descrições e fotos para evitar frustração',
            ];
        }

        if (($metrics['late_shipment_rate'] ?? 0) > 0.05) {
            $causes[] = [
                'cause' => 'Atrasos de envio',
                'description' => 'Taxa de atrasos prejudicando reputação',
                'impact_score' => 40,
                'fix' => 'Melhorar processo logístico',
            ];
        }

        if ($fallingCount > 0) {
            $causes[] = [
                'cause' => 'Tendência de queda',
                'description' => "Há {$fallingCount} itens com tráfego em queda",
                'impact_score' => $fallingCount * 4,
                'fix' => 'Investigar causas e otimizar',
            ];
        }

        // Sort by impact and return top 5
        usort($causes, fn($a, $b) => $b['impact_score'] <=> $a['impact_score']);

        return array_slice($causes, 0, 5);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WEEK PLAN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate 7-day action plan
     */
    public function generateWeekPlan(array $items, array $recoveryPlan): array
    {
        $weekPlan = [];

        $themes = [
            1 => ['theme' => 'Estancar - Tóxicos', 'focus' => 'Pausar itens tóxicos'],
            2 => ['theme' => 'Estancar - Estoque', 'focus' => 'Repor estoque crítico'],
            3 => ['theme' => 'Estabilizar - Títulos', 'focus' => 'Otimizar títulos de poluidores'],
            4 => ['theme' => 'Estabilizar - Preços', 'focus' => 'Ajustar preços'],
            5 => ['theme' => 'Estabilizar - Fotos', 'focus' => 'Melhorar fotos'],
            6 => ['theme' => 'Crescer - Âncoras', 'focus' => 'Proteger e impulsionar âncoras'],
            7 => ['theme' => 'Revisão e Métricas', 'focus' => 'Avaliar progresso'],
        ];

        // Collect actions by priority
        $criticalActions = [];
        $highActions = [];
        $otherActions = [];

        foreach ($items as $item) {
            foreach ($item['actions'] ?? [] as $action) {
                if ($action['guardrail_blocked'] ?? false) {
                    continue;
                }

                $actionItem = [
                    'item_id' => $item['id'] ?? '',
                    'item_title' => $item['title'] ?? '',
                    'action' => $action['tipo'] ?? '',
                    'motivo' => $action['motivo'] ?? '',
                ];

                match ($action['prioridade'] ?? '') {
                    self::PRIORITY_CRITICA => $criticalActions[] = $actionItem,
                    self::PRIORITY_ALTA => $highActions[] = $actionItem,
                    default => $otherActions[] = $actionItem,
                };
            }
        }

        for ($day = 1; $day <= 7; $day++) {
            $dayActions = [];

            if ($day <= 2) {
                // Days 1-2: Critical actions
                $dayActions = array_splice($criticalActions, 0, 5);
            } elseif ($day <= 5) {
                // Days 3-5: High priority actions
                $dayActions = array_splice($highActions, 0, 5);
            } else {
                // Days 6-7: Remaining actions
                $dayActions = array_splice($otherActions, 0, 5);
            }

            $weekPlan[] = [
                'day' => $day,
                'theme' => $themes[$day]['theme'],
                'focus' => $themes[$day]['focus'],
                'actions' => $dayActions,
                'kpi_check' => $this->getKpiCheck($day),
            ];
        }

        return $weekPlan;
    }

    /**
     * Get KPI check for a specific day
     */
    private function getKpiCheck(int $day): string
    {
        return match ($day) {
            1, 2 => 'Verificar: itens tóxicos pausados, estoque crítico reposto',
            3, 4 => 'Verificar: conversão melhorando, títulos otimizados',
            5 => 'Verificar: catálogo limpo, fotos atualizadas',
            6 => 'Verificar: âncoras protegidos, sem novas quedas',
            7 => 'Revisão completa: comparar métricas dia 1 vs dia 7',
            default => 'Monitoramento padrão',
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    // EXECUTIVE SUMMARY
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate executive summary
     */
    public function generateExecutiveSummary(
        string $accountStatus,
        array $metrics,
        array $items,
        array $causes
    ): array {
        $totalItems = count($items);
        $healthyCount = 0;
        $problemCount = 0;
        $criticalActions = 0;

        $classificationBreakdown = [];

        foreach ($items as $item) {
            $class = $item['classification'] ?? '';
            $classificationBreakdown[$class] = ($classificationBreakdown[$class] ?? 0) + 1;

            if (in_array($class, [self::CLASS_ANCHOR, self::CLASS_SAUDAVEL], true)) {
                $healthyCount++;
            } elseif (in_array($class, [self::CLASS_TOXICO, self::CLASS_POLUIDOR, self::CLASS_MORTO, self::CLASS_SEM_ESTOQUE], true)) {
                $problemCount++;
            }

            foreach ($item['actions'] ?? [] as $action) {
                if (($action['prioridade'] ?? '') === self::PRIORITY_CRITICA && !($action['guardrail_blocked'] ?? false)) {
                    $criticalActions++;
                }
            }
        }

        $headline = $this->generateHeadline($accountStatus, $problemCount, $totalItems);
        $topCause = $causes[0]['cause'] ?? 'Nenhuma causa crítica identificada';

        return [
            'status' => $accountStatus,
            'headline' => $headline,
            'total_items' => $totalItems,
            'healthy_items' => $healthyCount,
            'problem_items' => $problemCount,
            'critical_actions' => $criticalActions,
            'top_cause' => $topCause,
            'account_conv' => round(($metrics['account_conv_30d'] ?? 0) * 100, 2) . '%',
            'classification_breakdown' => $classificationBreakdown,
        ];
    }

    /**
     * Generate headline based on status
     */
    private function generateHeadline(string $status, int $problemCount, int $totalItems): string
    {
        $problemPct = $totalItems > 0 ? round(($problemCount / $totalItems) * 100) : 0;

        return match ($status) {
            self::STATUS_TRAVADA => "Conta TRAVADA: {$problemPct}% do catálogo problemático. Ação urgente necessária.",
            self::STATUS_PENALIZADA => "Conta PENALIZADA: {$problemCount} itens afetando reputação. Plano de recuperação ativado.",
            self::STATUS_EM_RECUPERACAO => "Conta em RECUPERAÇÃO: Progresso detectado. Manter foco nas ações prioritárias.",
            self::STATUS_ESTAVEL => "Conta ESTÁVEL: Catálogo saudável. Foco em manutenção e crescimento.",
            self::STATUS_FORTE => "Conta FORTE: Performance excelente. Proteger âncoras e expandir.",
            default => "Status da conta: {$status}. {$problemCount} itens requerem atenção.",
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SUCCESS CRITERIA
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Build success and rollback criteria
     */
    public function buildSuccessCriteria(string $status, array $metrics): array
    {
        $currentConv = ($metrics['account_conv_30d'] ?? 0) * 100;
        $currentSales = $metrics['total_sales_30d'] ?? 0;

        $successCriteria = match ($status) {
            self::STATUS_TRAVADA => [
                'Reduzir itens tóxicos para 0',
                'Atingir conversão > 0.5%',
                'Zerar estoque crítico em itens ativos',
                'Reduzir reclamações em 50%',
            ],
            self::STATUS_PENALIZADA => [
                'Atingir conversão > 1%',
                'Reduzir itens problemáticos em 50%',
                'Melhorar reputação para light_green ou green',
                'Manter vendas atuais enquanto limpa catálogo',
            ],
            self::STATUS_EM_RECUPERACAO => [
                'Atingir conversão > 1.5%',
                'Aumentar vendas em 10%',
                'Reduzir itens em risco',
                'Fortalecer 3+ âncoras',
            ],
            self::STATUS_ESTAVEL => [
                'Manter conversão > 1.5%',
                'Crescimento de 15% em vendas',
                'Zero itens tóxicos',
                'Expandir catálogo saudável',
            ],
            self::STATUS_FORTE => [
                'Manter reputação green',
                'Crescimento sustentável de 20%+',
                'Proteção dos âncoras atuais',
                'Expansão para novas categorias',
            ],
            default => [
                'Estabilizar métricas atuais',
                'Reduzir problemas identificados',
            ],
        };

        $rollbackCriteria = [
            'Queda de conversão > 20% em 7 dias',
            'Aumento de reclamações',
            'Perda de reputação',
            "Vendas caírem abaixo de " . round($currentSales * 0.8) . " unidades",
        ];

        return [
            'success' => $successCriteria,
            'rollback' => $rollbackCriteria,
        ];
    }
}
