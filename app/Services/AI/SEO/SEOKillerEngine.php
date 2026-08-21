<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use App\Services\ItemService;
use App\Services\CategoryService;
use App\Services\AI\Core\AIProviderManager;
use App\Traits\SEOStrategiesIntegrationTrait;
use PDO;

/**
 * SEO Killer Engine - Diagnóstico completo de conta e otimização SEO para Mercado Livre
 */
class SEOKillerEngine
{
    use SEOStrategiesIntegrationTrait;

    private ?\PDO $db;
    private ?int $accountId;
    private ?MercadoLivreClient $mlClient = null;
    private ?ItemService $itemService = null;
    private ?CategoryService $categoryService = null;
    private ?AIProviderManager $aiProvider = null;

    // Weights for diagnosis
    private const DIAGNOSIS_WEIGHTS = [
        'title_quality' => 20,
        'description_quality' => 15,
        'attributes_completeness' => 20,
        'image_quality' => 15,
        'price_competitiveness' => 15,
        'visibility_factors' => 15,
    ];

    public function __construct(int $accountId)
    {
        try {
            $this->db = Database::getInstance();
        } catch (\Exception $e) {
            log_warning('SEOKillerEngine: DB indisponível, operando em modo API-only', [
                'service' => 'SEOKillerEngine',
                'error' => $e->getMessage(),
            ]);
            $this->db = null;
        }
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->itemService = new ItemService($accountId);
        $this->categoryService = new CategoryService($accountId);
        $this->aiProvider = new AIProviderManager();
    }

    /**
     * 🔍 DIAGNÓSTICO COMPLETO DA CONTA
     * Identifica TODOS os motivos de baixa performance
     */
    public function diagnoseAccount(): array
    {
        $diagnosis = [
            'account_id' => $this->accountId,
            'diagnosis_date' => date('Y-m-d H:i:s'),
            'health_score' => 0,
            'status' => 'critical',
            'problems' => [],
            'opportunities' => [],
            'priority_actions' => [],
            'summary' => '',
        ];

        $items = $this->getAllItems();

        if (empty($items)) {
            $diagnosis['problems'][] = [
                'severity' => 'critical',
                'category' => 'inventory',
                'issue' => 'Conta sem anúncios ativos',
                'impact' => -100,
                'solution' => 'Criar anúncios com estratégia SEO desde o início'
            ];
            return $diagnosis;
        }

        $diagnosis['total_items'] = count($items);

        [$problems, $opportunities] = $this->collectAnalysisResults($items);

        return $this->assembleDiagnosis($diagnosis, $problems, $opportunities, $items);
    }

    /**
     * Coleta resultados de todas as análises dimensionais
     * @return array{0: array, 1: array} [problems, opportunities]
     */
    private function collectAnalysisResults(array $items): array
    {
        $problems = [];
        $opportunities = [];

        $analyzers = [
            'analyzeOfficialListingGaps',
            'analyzeTitles',
            'analyzeDescriptions',
            'analyzeAttributes',
            'analyzeImages',
            'analyzePricing',
            'analyzeVisibility',
        ];

        foreach ($analyzers as $method) {
            $result = $this->{$method}($items);
            $problems = array_merge($problems, $result['problems']);
            $opportunities = array_merge($opportunities, $result['opportunities']);
        }

        return [$problems, $opportunities];
    }

    /**
     * Monta o diagnóstico final com scores e prioridades
     */
    private function assembleDiagnosis(array $diagnosis, array $problems, array $opportunities, array $items = []): array
    {
        usort($problems, fn($a, $b) => $b['impact'] <=> $a['impact']);
        usort($opportunities, fn($a, $b) => $b['potential'] <=> $a['potential']);

        $totalImpact = array_sum(array_column($problems, 'impact'));
        $customScore = max(0, 100 + $totalImpact);

        $scoreStats = $this->collectOfficialScoreStats($items);
        $officialScores = $scoreStats['scores'];
        $sample = count($officialScores);
        $totalItems = max(1, count($items));
        $minSample = max(10, (int) ceil($totalItems * 0.1));
        $diagnosis['official_score_sample'] = $sample;
        $diagnosis['performance_unknown'] = $scoreStats['unknown'];
        $diagnosis['performance_status'] = $sample === 0
            ? 'pending'
            : ($scoreStats['unknown'] > 0 ? 'partial' : 'ready');

        if ($sample >= $minSample) {
            $diagnosis['health_score'] = (int) round(array_sum($officialScores) / $sample);
            $diagnosis['official_score'] = true;
        } else {
            // Missing official scores are pending/unknown — never treat as 0.
            $diagnosis['health_score'] = $customScore;
            $diagnosis['official_score'] = false;
        }

        $diagnosis['status'] = $this->resolveHealthStatus($diagnosis['health_score']);
        $diagnosis['problems'] = array_slice($problems, 0, 20);
        $diagnosis['opportunities'] = array_slice($opportunities, 0, 10);
        $diagnosis['priority_actions'] = $this->generatePriorityActions($problems, $opportunities);
        $diagnosis['summary'] = $this->generateDiagnosisSummary($diagnosis);
        $diagnosis['ficha_gaps'] = $this->summarizeOfficialListingGaps(
            $this->collectOfficialListingGapStats($items)
        );

        return $diagnosis;
    }

    /**
     * Resolve status textual a partir do health score
     */
    private function resolveHealthStatus(int $score): string
    {
        return match (true) {
            $score < 30 => 'critical',
            $score < 60 => 'warning',
            default => 'healthy',
        };
    }

    /**
     * 📊 Analisar Títulos
     */
    private function analyzeTitles(array $items): array
    {
        $stats = $this->collectTitleStats($items);
        $total = count($items);

        return $this->evaluateTitleStats($stats, $total);
    }

    /**
     * Coleta estatísticas de títulos dos itens
     */
    private function collectTitleStats(array $items): array
    {
        $stats = ['shortTitles' => 0, 'longTitles' => 0, 'noNumbers' => 0, 'allCaps' => 0];

        foreach ($items as $item) {
            $title = $item['title'] ?? '';
            $this->classifyTitle($title, $stats);
        }

        return $stats;
    }

    /**
     * Classifica um título individual e incrementa contadores
     */
    private function classifyTitle(string $title, array &$stats): void
    {
        $len = mb_strlen($title);

        if ($len < 40) {
            $stats['shortTitles']++;
        } elseif ($len > 60) {
            $stats['longTitles']++;
        }

        if (!preg_match('/\d/', $title)) {
            $stats['noNumbers']++;
        }

        if ($this->isTitleAllCaps($title, $len)) {
            $stats['allCaps']++;
        }
    }

    /**
     * Verifica se um título está todo em maiúsculas
     */
    private function isTitleAllCaps(string $title, int $len): bool
    {
        return $len > 10 && $title === mb_strtoupper($title);
    }

    /**
     * Avalia estatísticas de títulos e gera problemas/oportunidades
     */
    private function evaluateTitleStats(array $stats, int $total): array
    {
        $problems = [];
        $opportunities = [];

        if ($stats['shortTitles'] > $total * 0.3) {
            $problems[] = [
                'severity' => 'high',
                'category' => 'title',
                'issue' => "Títulos curtos demais ({$stats['shortTitles']} de {$total})",
                'impact' => -15,
                'affected_items' => $stats['shortTitles'],
                'solution' => 'Expandir títulos para 50-60 caracteres com keywords de cauda longa'
            ];
        }

        if ($stats['noNumbers'] > $total * 0.5) {
            $opportunities[] = [
                'category' => 'title',
                'opportunity' => 'Adicionar especificações numéricas nos títulos',
                'potential' => 10,
                'affected_items' => $stats['noNumbers'],
                'strategy' => 'Incluir tamanho, quantidade, capacidade, voltagem, etc.'
            ];
        }

        return ['problems' => $problems, 'opportunities' => $opportunities];
    }

    /**
     * 📝 Analisar Descrições
     */
    private function analyzeDescriptions(array $items): array
    {
        $stats = $this->collectDescriptionStats($items);
        $total = count($items);

        return $this->evaluateDescriptionStats($stats, $total);
    }

    /**
     * Coleta estatísticas de descrições dos itens via API
     */
    private function collectDescriptionStats(array $items): array
    {
        $stats = ['noDescription' => 0, 'shortDescriptions' => 0, 'noStructure' => 0];

        foreach ($items as $item) {
            $this->classifyItemDescription($item, $stats);
        }

        return $stats;
    }

    /**
     * Classificar descrição de um item e incrementar contadores
     */
    private function classifyItemDescription(array $item, array &$stats): void
    {
        $mlItemId = $item['id'] ?? $item['ml_item_id'] ?? null;
        if ($mlItemId === null) {
            return;
        }

        $desc = null;
        foreach (['description', 'plain_text'] as $key) {
            $cached = $item[$key] ?? null;
            if (is_string($cached) && $cached !== '') {
                $desc = $cached;
                break;
            }
        }
        if ($desc === null) {
            // items.data does not store /description; missing local text is unknown, not empty.
            return;
        }
        $this->classifyDescriptionLength($desc, $stats);

        if (!$this->hasStructuredContent($desc)) {
            $stats['noStructure']++;
        }
    }

    /**
     * Classifica comprimento da descrição e atualiza contadores
     */
    private function classifyDescriptionLength(string $desc, array &$stats): void
    {
        $len = mb_strlen($desc);

        if ($len < 100) {
            $stats['noDescription']++;
        } elseif ($len < 500) {
            $stats['shortDescriptions']++;
        }
    }

    /**
     * Verifica se texto contém marcadores de estrutura (bullets, hífens)
     */
    private function hasStructuredContent(string $text): bool
    {
        return str_contains($text, '•') || str_contains($text, '-');
    }

    /**
     * Resolve o texto da descrição de um item, normalizando para string
     */
    private function resolveDescriptionText(string $itemId): string
    {
        $desc = $this->getItemDescription($itemId);

        if (is_array($desc)) {
            return $desc['plain_text'] ?? json_encode($desc);
        }

        return is_string($desc) ? $desc : (string) $desc;
    }

    /**
     * Avalia estatísticas de descrições e gera problemas/oportunidades
     */
    private function evaluateDescriptionStats(array $stats, int $total): array
    {
        $problems = [];
        $opportunities = [];

        if ($stats['noDescription'] > 0) {
            $problems[] = [
                'severity' => 'critical',
                'category' => 'description',
                'issue' => "{$stats['noDescription']} anúncios sem descrição adequada",
                'impact' => -20,
                'affected_items' => $stats['noDescription'],
                'solution' => 'Gerar descrições completas com IA usando template persuasivo'
            ];
        }

        if ($stats['noStructure'] > $total * 0.5) {
            $opportunities[] = [
                'category' => 'description',
                'opportunity' => 'Estruturar descrições com bullet points',
                'potential' => 15,
                'affected_items' => $stats['noStructure'],
                'strategy' => 'Usar emojis + bullets + seções claras'
            ];
        }

        return ['problems' => $problems, 'opportunities' => $opportunities];
    }

    /**
     * 🔧 Analisar Atributos (CRÍTICO - Visíveis + Ocultos)
     */
    private function analyzeAttributes(array $items): array
    {
        $stats = $this->collectAttributeStats($items);
        $total = count($items);

        return $this->evaluateAttributeStats($stats, $total);
    }

    /**
     * Coleta estatísticas de atributos faltantes por item
     */
    private function collectAttributeStats(array $items): array
    {
        $stats = ['incompleteItems' => 0, 'missingRequired' => 0, 'totalMissingOptional' => 0];

        foreach ($items as $item) {
            $categoryId = $item['category_id'] ?? '';
            if (!$categoryId) {
                continue;
            }

            try {
                $itemResult = $this->analyzeItemAttributes($item, $categoryId);
                $stats['missingRequired'] += $itemResult['missingRequired'];
                $stats['totalMissingOptional'] += $itemResult['missingOptional'];
                if ($itemResult['missingRequired'] > 0) {
                    $stats['incompleteItems']++;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $stats;
    }

    /**
     * Analisa atributos de um item específico contra sua categoria
     * @return array{missingRequired: int, missingOptional: int}
     */
    private function analyzeItemAttributes(array $item, string $categoryId): array
    {
        $categoryAttrs = $this->categoryService->getCategoryAttributes($categoryId);
        $itemAttrIds = array_column($item['attributes'] ?? [], 'id');
        $allAttrs = $categoryAttrs['attributes'] ?? [];

        return [
            'missingRequired' => $this->countMissingRequiredAttrs($allAttrs, $itemAttrIds),
            'missingOptional' => $this->countMissingOptionalAttrs($allAttrs, $itemAttrIds),
        ];
    }

    /**
     * Conta atributos OBRIGATÓRIOS faltantes
     */
    private function countMissingRequiredAttrs(array $allAttrs, array $itemAttrIds): int
    {
        $missing = 0;
        foreach ($allAttrs as $attr) {
            $isRequired = !empty($attr['tags']['required']) || !empty($attr['tags']['catalog_required']);
            if ($isRequired && !in_array($attr['id'], $itemAttrIds)) {
                $missing++;
            }
        }
        return $missing;
    }

    /**
     * Conta atributos opcionais visíveis faltantes
     */
    private function countMissingOptionalAttrs(array $allAttrs, array $itemAttrIds): int
    {
        $missing = 0;
        foreach ($allAttrs as $attr) {
            $isOptionalVisible = empty($attr['tags']['required']) && empty($attr['tags']['hidden']);
            if ($isOptionalVisible && !in_array($attr['id'], $itemAttrIds)) {
                $missing++;
            }
        }
        return $missing;
    }

    /**
     * Avalia estatísticas de atributos e gera problemas/oportunidades
     */
    private function evaluateAttributeStats(array $stats, int $total): array
    {
        $problems = [];
        $opportunities = [];

        if ($stats['missingRequired'] > 0) {
            $problems[] = [
                'severity' => 'critical',
                'category' => 'attributes',
                'issue' => "{$stats['incompleteItems']} anúncios com atributos OBRIGATÓRIOS faltando",
                'impact' => -25,
                'affected_items' => $stats['incompleteItems'],
                'solution' => 'Preencher TODOS os atributos obrigatórios imediatamente'
            ];
        }

        if ($stats['totalMissingOptional'] > $total * 5) {
            $opportunities[] = [
                'category' => 'attributes',
                'opportunity' => "~{$stats['totalMissingOptional']} atributos opcionais podem ser preenchidos",
                'potential' => 20,
                'strategy' => 'Preencher 100% dos atributos aumenta visibilidade em filtros'
            ];
        }

        return ['problems' => $problems, 'opportunities' => $opportunities];
    }

    /**
     * 🖼️ Analisar Imagens
     */
    private function analyzeImages(array $items): array
    {
        $problems = [];
        $opportunities = [];

        $fewImages = 0;
        $noImages = 0;

        foreach ($items as $item) {
            $imageCount = count($item['pictures'] ?? []);

            if ($imageCount === 0) {
                $noImages++;
            } elseif ($imageCount < 4) {
                $fewImages++;
            }
        }

        $total = count($items);

        if ($noImages > 0) {
            $problems[] = [
                'severity' => 'critical',
                'category' => 'images',
                'issue' => "{$noImages} anúncios sem imagens",
                'impact' => -30,
                'affected_items' => $noImages,
                'solution' => 'Adicionar mínimo 4-6 imagens de qualidade'
            ];
        }

        if ($fewImages > $total * 0.3) {
            $opportunities[] = [
                'category' => 'images',
                'opportunity' => 'Adicionar mais imagens em ' . $fewImages . ' anúncios',
                'potential' => 15,
                'strategy' => 'ML favorece anúncios com 6+ imagens de ângulos diferentes'
            ];
        }

        return ['problems' => $problems, 'opportunities' => $opportunities];
    }

    /**
     * 💰 Analisar Preços
     */
    private function analyzePricing(array $items): array
    {
        $problems = [];
        $opportunities = [];

        // Simplified - would need competitor data for full analysis
        $noFreeShipping = 0;

        foreach ($items as $item) {
            if (!($item['shipping']['free_shipping'] ?? false)) {
                $noFreeShipping++;
            }
        }

        $total = count($items);

        if ($noFreeShipping > $total * 0.5) {
            $problems[] = [
                'severity' => 'medium',
                'category' => 'shipping',
                'issue' => "{$noFreeShipping} anúncios sem frete grátis",
                'impact' => -10,
                'affected_items' => $noFreeShipping,
                'solution' => 'Ativar frete grátis - aumenta CTR em até 30%'
            ];
        }

        return ['problems' => $problems, 'opportunities' => $opportunities];
    }

    private const LOW_LISTING_TYPES = ['bronze', 'free'];
    private const LOW_PERFORMANCE_SCORE = 40;
    /** Cruzamento de “sem venda” com visitas: só account_index_metrics (conta), nunca getMultiItemVisits. */
    private const MIN_VISITS_FOR_CONVERSION = 20;
    private const PERFORMANCE_HREF = '/dashboard/seo-killer#performance-tracker';
    private const FICHA_SAMPLE_LIMIT = 8;

    /**
     * Analisar Visibilidade
     */
    private function analyzeVisibility(array $items): array
    {
        [$paused, $lowListing] = $this->countVisibilityIssues($items);

        $problems = [];
        $opportunities = [];

        if ($paused > 0) {
            $problems[] = [
                'severity' => 'high',
                'category' => 'visibility',
                'issue' => "{$paused} anúncios pausados",
                'impact' => -15,
                'affected_items' => $paused,
                'solution' => 'Reativar anúncios pausados após otimização'
            ];
        }

        if ($lowListing > 0) {
            $opportunities[] = [
                'category' => 'visibility',
                'opportunity' => "Upgrade de tipo de anúncio em {$lowListing} itens",
                'potential' => 20,
                'strategy' => 'Clássico/Premium têm muito mais exposição que Grátis'
            ];
        }

        $quality = $this->evaluateOfficialQualityStats($this->collectOfficialScoreStats($items));
        $traffic = $this->evaluateVisitConversionStats($this->collectVisitConversionStats($items));

        return [
            'problems' => array_merge($problems, $quality['problems'], $traffic['problems']),
            'opportunities' => array_merge($opportunities, $quality['opportunities'], $traffic['opportunities']),
        ];
    }

    /**
     * Conta itens pausados e com listing type baixo
     * @return array{0: int, 1: int} [paused, lowListing]
     */
    private function countVisibilityIssues(array $items): array
    {
        $paused = 0;
        $lowListing = 0;

        foreach ($items as $item) {
            $status = $item['status'] ?? '';
            $listingType = $item['listing_type_id'] ?? '';

            if ($status === 'paused') {
                $paused++;
            }
            if (in_array($listingType, self::LOW_LISTING_TYPES)) {
                $lowListing++;
            }
        }

        return [$paused, $lowListing];
    }

    /**
     * Gerar Ações Prioritárias
     */
    private function generatePriorityActions(array $problems, array $opportunities): array
    {
        $actions = [];
        $critical = array_filter($problems, fn(array $p): bool => $p['severity'] === 'critical');

        foreach (array_slice($critical, 0, 3) as $p) {
            $actions[] = [
                'priority' => 1,
                'type' => 'fix_problem',
                'action' => $p['solution'],
                'category' => $p['category'],
                'impact' => 'Alto',
                'affected' => $p['affected_items'] ?? 0
            ];
        }
        foreach (array_slice($opportunities, 0, 2) as $o) {
            $actions[] = [
                'priority' => 2,
                'type' => 'opportunity',
                'action' => $o['strategy'],
                'category' => $o['category'],
                'impact' => 'Médio-Alto',
                'potential' => '+' . $o['potential'] . '%'
            ];
        }

        return $actions;
    }

    /**
     * Gerar Resumo do Diagnóstico
     */
    private function generateDiagnosisSummary(array $diagnosis): string
    {
        $score = $diagnosis['health_score'];
        $n = count($diagnosis['problems']);

        $summary = match (true) {
            $score < 30 => "🔴 CONTA CRÍTICA: Score {$score}/100. {$n} problemas graves. Otimização urgente necessária.",
            $score < 60 => "🟡 CONTA COM PROBLEMAS: Score {$score}/100. {$n} issues. Potencial de melhoria com SEO.",
            default => "🟢 CONTA SAUDÁVEL: Score {$score}/100. Foco em otimizações finas.",
        };
        $pending = (int) ($diagnosis['performance_unknown'] ?? 0);
        if ($pending > 0) {
            $summary .= " {$pending} anúncios com performance oficial pendente (não é score 0).";
        }

        return $summary;
    }


    /**
     * Overlay performance_* + sold_quantity from items.data (item-performance-sync).
     * listItems / ml_items do not carry these keys. Never calls getMultiItemVisits.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function hydrateOfficialPerformanceFromLocalItems(array $items): array
    {
        if ($items === [] || !isset($this->db) || !$this->db instanceof \PDO || ($this->accountId ?? 0) <= 0) {
            return $items;
        }

        $indexById = [];
        foreach ($items as $i => $item) {
            $mlb = strtoupper(trim((string) ($item['id'] ?? $item['ml_item_id'] ?? '')));
            if ($mlb === '') {
                continue;
            }
            $indexById[$mlb][] = $i;
        }
        if ($indexById === []) {
            return $items;
        }

        foreach (array_chunk(array_keys($indexById), 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = null;
            try {
                $sql = "SELECT ml_item_id, sold_quantity, data FROM items WHERE account_id = ? AND ml_item_id IN ({$placeholders})";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(array_merge([$this->accountId], $chunk));
            } catch (\Throwable) {
                try {
                    $sql = "SELECT ml_item_id, data FROM items WHERE account_id = ? AND ml_item_id IN ({$placeholders})";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute(array_merge([$this->accountId], $chunk));
                } catch (\Throwable) {
                    return $items;
                }
            }

            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $mlb = strtoupper(trim((string) ($row['ml_item_id'] ?? '')));
                if ($mlb === '' || !isset($indexById[$mlb])) {
                    continue;
                }
                $data = json_decode((string) ($row['data'] ?? ''), true);
                if (!is_array($data)) {
                    $data = [];
                }
                $overlay = $this->extractOfficialPerformanceFields($data, $row);
                if ($overlay === []) {
                    continue;
                }
                foreach ($indexById[$mlb] as $idx) {
                    $items[$idx] = array_merge($items[$idx], $overlay);
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function extractOfficialPerformanceFields(array $data, array $row): array
    {
        $overlay = [];
        foreach (['performance_score', 'performance_level', 'performance_level_wording', 'performance_updated_at'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $overlay[$key] = $data[$key];
            }
        }
        if (isset($row['sold_quantity']) && is_numeric($row['sold_quantity'])) {
            $overlay['sold_quantity'] = (int) $row['sold_quantity'];
        } elseif (isset($data['sold_quantity']) && is_numeric($data['sold_quantity'])) {
            $overlay['sold_quantity'] = (int) $data['sold_quantity'];
        }

        return $overlay;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{
     *   scores: list<float>,
     *   unknown: int,
     *   low: int,
     *   low_ids: list<string>,
     *   unknown_ids: list<string>
     * }
     */
    private function collectOfficialScoreStats(array $items): array
    {
        $stats = [
            'scores' => [],
            'unknown' => 0,
            'low' => 0,
            'low_ids' => [],
            'unknown_ids' => [],
        ];

        foreach ($items as $item) {
            $mlb = strtoupper(trim((string) ($item['id'] ?? $item['ml_item_id'] ?? '')));
            if (array_key_exists('performance_score', $item) && is_numeric($item['performance_score'])) {
                $score = (float) $item['performance_score'];
                $stats['scores'][] = $score;
                if ($score < self::LOW_PERFORMANCE_SCORE) {
                    $stats['low']++;
                    $this->pushFichaSampleId($stats['low_ids'], $mlb);
                }
            } else {
                $stats['unknown']++;
                $this->pushFichaSampleId($stats['unknown_ids'], $mlb);
            }
        }

        return $stats;
    }

    /**
     * @param array{
     *   scores: list<float>,
     *   unknown: int,
     *   low: int,
     *   low_ids: list<string>,
     *   unknown_ids: list<string>
     * } $stats
     * @return array{problems: list<array<string, mixed>>, opportunities: list<array<string, mixed>>}
     */
    private function evaluateOfficialQualityStats(array $stats): array
    {
        $problems = [];
        $opportunities = [];

        if ($stats['low'] > 0) {
            $opportunities[] = [
                'category' => 'visibility',
                'opportunity' => "{$stats['low']} anúncios com performance_score oficial < " . self::LOW_PERFORMANCE_SCORE,
                'potential' => 18,
                'affected_items' => $stats['low'],
                'sample_item_ids' => $stats['low_ids'],
                'strategy' => 'Priorizar ficha/título desses MLBs — score vem de items.data (item-performance-sync), não do cache morto',
            ];
        }

        if ($stats['unknown'] > 0) {
            $opportunities[] = [
                'category' => 'visibility',
                'opportunity' => "{$stats['unknown']} anúncios com performance oficial pendente (unknown) — não tratar como score 0",
                'potential' => 5,
                'affected_items' => $stats['unknown'],
                'sample_item_ids' => $stats['unknown_ids'],
                'performance_pending' => true,
                'strategy' => 'Aguardar item-performance-sync gravar performance_score em items.data',
            ];
        }

        return ['problems' => $problems, 'opportunities' => $opportunities];
    }

    /**
     * Sem venda: ml_orders (30d) + items.sold_quantity. Visitas só em nível de conta
     * (account_index_metrics) quando não há visitas por anúncio. Sem GET /visits.
     *
     * @param list<array<string, mixed>> $items
     * @return array{
     *   known: bool,
     *   zero_visits: int,
     *   no_sales: int,
     *   zero_sample_ids: list<string>,
     *   no_sales_sample_ids: list<string>,
     *   visits_source: string,
     *   account_visits: ?float,
     *   account_zero_visits: bool
     * }
     */
    private function collectVisitConversionStats(array $items): array
    {
        $empty = [
            'known' => false,
            'zero_visits' => 0,
            'no_sales' => 0,
            'zero_sample_ids' => [],
            'no_sales_sample_ids' => [],
            'visits_source' => 'none',
            'account_visits' => null,
            'account_zero_visits' => false,
        ];
        if (!isset($this->db) || !$this->db instanceof \PDO || ($this->accountId ?? 0) <= 0) {
            return $empty;
        }

        $sales = $this->loadSalesByItem($items);
        $accountVisits = $this->loadAccountVisits7d();

        $stats = $empty;
        $stats['known'] = true;
        $stats['account_visits'] = $accountVisits;
        $stats['visits_source'] = $accountVisits === null ? 'sales_only' : 'account_index_metrics';
        $stats['account_zero_visits'] = $accountVisits === 0.0;

        foreach ($items as $item) {
            if ((string) ($item['status'] ?? '') !== 'active') {
                continue;
            }
            $mlb = strtoupper(trim((string) ($item['id'] ?? $item['ml_item_id'] ?? '')));
            if ($mlb === '') {
                continue;
            }
            $soldLifetime = (int) ($sales[$mlb]['sold_quantity'] ?? $item['sold_quantity'] ?? 0);
            $soldRecent = (int) ($sales[$mlb]['recent_qty'] ?? 0);
            $noSale = $soldRecent <= 0 && $soldLifetime <= 0;

            // Per-item visits are not loaded on page load (no getMultiItemVisits,
            // no seo_performance_metrics). Cross with account-level visits only.
            if ($noSale && $accountVisits !== 0.0) {
                $stats['no_sales']++;
                $this->pushFichaSampleId($stats['no_sales_sample_ids'], $mlb);
            }
        }

        return $stats;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, array{sold_quantity: int, recent_qty: int}>
     */
    private function loadSalesByItem(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $mlb = strtoupper(trim((string) ($item['id'] ?? $item['ml_item_id'] ?? '')));
            if ($mlb === '') {
                continue;
            }
            $map[$mlb] = [
                'sold_quantity' => (int) ($item['sold_quantity'] ?? 0),
                'recent_qty' => 0,
            ];
        }

        $cutoff = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
        $sqlVariants = [
            'SELECT order_data, status FROM ml_orders WHERE account_id = :account_id AND date_created >= :cutoff',
            'SELECT order_data, status FROM ml_orders WHERE ml_account_id = :account_id AND date_created >= :cutoff',
        ];
        $rows = null;
        foreach ($sqlVariants as $sql) {
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'account_id' => $this->accountId,
                    'cutoff' => $cutoff,
                ]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                break;
            } catch (\Throwable) {
                continue;
            }
        }
        if (!is_array($rows)) {
            return $map;
        }

        $paid = ['paid', 'delivered', 'confirmed', 'ready_to_ship', 'shipped', 'handling'];
        foreach ($rows as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            if ($status !== '' && !in_array($status, $paid, true)) {
                continue;
            }
            $data = json_decode((string) ($row['order_data'] ?? ''), true);
            if (!is_array($data)) {
                continue;
            }
            $orderItems = $data['order_items'] ?? [];
            if (!is_array($orderItems)) {
                continue;
            }
            foreach ($orderItems as $orderItem) {
                if (!is_array($orderItem)) {
                    continue;
                }
                $itemRef = $orderItem['item'] ?? [];
                $id = '';
                if (is_array($itemRef)) {
                    $id = strtoupper(trim((string) ($itemRef['id'] ?? '')));
                }
                if ($id === '') {
                    $id = strtoupper(trim((string) ($orderItem['item_id'] ?? '')));
                }
                if ($id === '') {
                    continue;
                }
                $qty = (int) ($orderItem['quantity'] ?? 0);
                if (!isset($map[$id])) {
                    $map[$id] = ['sold_quantity' => 0, 'recent_qty' => 0];
                }
                $map[$id]['recent_qty'] += $qty;
            }
        }

        return $map;
    }

    private function loadAccountVisits7d(): ?float
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT visitas_7d FROM account_index_metrics WHERE account_id = :account_id ORDER BY updated_at DESC LIMIT 1'
            );
            $stmt->execute(['account_id' => $this->accountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row) || !array_key_exists('visitas_7d', $row) || $row['visitas_7d'] === null || $row['visitas_7d'] === '') {
            return null;
        }

        return (float) $row['visitas_7d'];
    }

    /**
     * @param array{
     *   known: bool,
     *   zero_visits: int,
     *   no_sales: int,
     *   zero_sample_ids: list<string>,
     *   no_sales_sample_ids: list<string>,
     *   visits_source?: string,
     *   account_visits?: ?float,
     *   account_zero_visits?: bool
     * } $stats
     * @return array{problems: list<array<string, mixed>>, opportunities: list<array<string, mixed>>}
     */
    private function evaluateVisitConversionStats(array $stats): array
    {
        $problems = [];
        $opportunities = [];
        if (empty($stats['known'])) {
            return ['problems' => $problems, 'opportunities' => $opportunities];
        }

        if (!empty($stats['account_zero_visits'])) {
            $problems[] = [
                'severity' => 'high',
                'category' => 'visits',
                'issue' => 'Conta com 0 visitas (7d) em account_index_metrics — visitas por anúncio não são carregadas nesta tela',
                'impact' => -10,
                'affected_items' => 0,
                'visits_cta' => true,
                'href' => self::PERFORMANCE_HREF,
                'sample_item_ids' => [],
                'solution' => 'Revisar exposição da conta (Pregão/Analytics). Sem GET /visits em lote no diagnose.',
            ];
        } elseif ((int) ($stats['zero_visits'] ?? 0) > 0) {
            $problems[] = [
                'severity' => 'high',
                'category' => 'visits',
                'issue' => "{$stats['zero_visits']} anúncios ativos sem visitas (30d)",
                'impact' => -15,
                'affected_items' => $stats['zero_visits'],
                'visits_cta' => true,
                'href' => self::PERFORMANCE_HREF,
                'sample_item_ids' => $stats['zero_sample_ids'] ?? [],
                'solution' => 'Abrir Performance Tracker — zero visita = não ranqueia; revisar título/ficha (apply 1335 só com GO item a item)',
            ];
        }

        $noSales = (int) ($stats['no_sales'] ?? 0);
        if ($noSales > 0) {
            $accountVisits = $stats['account_visits'] ?? null;
            $src = (string) ($stats['visits_source'] ?? 'none');
            if ($src === 'account_index_metrics' && is_numeric($accountVisits) && (float) $accountVisits > 0) {
                $issue = "{$noSales} anúncios ativos sem venda (pedidos 30d + sold_quantity) — conta tem visitas 7d";
            } else {
                $issue = "{$noSales} anúncios ativos sem venda (pedidos 30d + sold_quantity)";
            }
            $problems[] = [
                'severity' => 'high',
                'category' => 'conversion',
                'issue' => $issue,
                'impact' => -18,
                'affected_items' => $noSales,
                'visits_cta' => true,
                'href' => self::PERFORMANCE_HREF,
                'sample_item_ids' => $stats['no_sales_sample_ids'] ?? [],
                'solution' => 'Sem venda: cruzar título/ficha/preço — apply 1335 só com GO item a item. Fonte: ml_orders + items.sold_quantity, não seo_performance_metrics.',
            ];
        }

        return ['problems' => $problems, 'opportunities' => $opportunities];
    }

    private function pushFichaSampleId(array &$ids, string $mlb): void
    {
        if ($mlb === '' || count($ids) >= self::FICHA_SAMPLE_LIMIT) {
            return;
        }
        if (!in_array($mlb, $ids, true)) {
            $ids[] = $mlb;
        }
    }

    public function officialListingCompletenessReport(): array
    {
        $items = $this->getAllItems();
        $stats = $this->collectOfficialListingGapStats($items);
        $summary = $this->summarizeOfficialListingGaps($stats);

        return [
            'success' => true,
            'source' => 'local_items',
            'account_id' => $this->accountId,
            'total_items' => $summary['universe_active'],
            'analyzed' => $summary['universe_active'],
            'pending' => $summary['pending_unique'],
            'optimized' => max(0, $summary['universe_active'] - $summary['pending_unique']),
            'ficha_gaps' => $summary,
            'items' => $stats['item_rows'],
        ];
    }

    /**
     * Official listing completeness (local items.data). Not title/MODEL/price apply.
     *
     * @param list<array<string, mixed>> $items
     * @return array{problems: list<array<string, mixed>>, opportunities: list<array<string, mixed>>}
     */
    private function analyzeOfficialListingGaps(array $items): array
    {
        $stats = $this->collectOfficialListingGapStats($items);
        $problems = [];
        $opportunities = [];

        if ($stats['photos_lt3'] > 0) {
            $problems[] = [
                'severity' => 'high',
                'category' => 'ficha',
                'issue' => "{$stats['photos_lt3']} anúncios ativos com menos de 3 fotos",
                'impact' => -15,
                'affected_items' => $stats['photos_lt3'],
                'sample_item_ids' => $stats['photos_lt3_ids'],
                'solution' => 'Completar ficha: mínimo 3 fotos (sinal oficial ML). Sem apply automático.',
            ];
        }
        if ($stats['stock_0'] > 0) {
            $problems[] = [
                'severity' => 'critical',
                'category' => 'ficha',
                'issue' => "{$stats['stock_0']} anúncios ativos com estoque 0",
                'impact' => -20,
                'affected_items' => $stats['stock_0'],
                'sample_item_ids' => $stats['stock_0_ids'],
                'solution' => 'Reposição de estoque — anúncio sem stock some da busca. Sem apply automático.',
            ];
        }
        if ($stats['catalog_not_listing'] > 0) {
            $problems[] = [
                'severity' => 'high',
                'category' => 'ficha',
                'issue' => "{$stats['catalog_not_listing']} anúncios com catalog_product_id local mas sem catalog_listing",
                'impact' => -12,
                'affected_items' => $stats['catalog_not_listing'],
                'sample_item_ids' => $stats['catalog_not_listing_ids'],
                'solution' => 'Migrar para catálogo/buy box só quando o id local existir. Sem scrape ML.',
            ];
        }
        if ($stats['no_free_shipping'] > 0) {
            $problems[] = [
                'severity' => 'medium',
                'category' => 'ficha',
                'issue' => "{$stats['no_free_shipping']} anúncios ativos sem frete grátis",
                'impact' => -10,
                'affected_items' => $stats['no_free_shipping'],
                'sample_item_ids' => $stats['no_free_shipping_ids'],
                'solution' => 'Frete grátis é sinal oficial de visibilidade. Sem apply automático.',
            ];
        }
        if ($stats['not_premium'] > 0) {
            $problems[] = [
                'severity' => 'medium',
                'category' => 'ficha',
                'issue' => "{$stats['not_premium']} anúncios ativos que não são Premium (gold_pro)",
                'impact' => -8,
                'affected_items' => $stats['not_premium'],
                'sample_item_ids' => $stats['not_premium_ids'],
                'solution' => 'Premium (gold_pro) ajuda exposição. Clássico não é gap inventado de TRAVADA.',
            ];
        }
        if ($stats['performance_pending'] > 0) {
            $problems[] = [
                'severity' => 'pending',
                'category' => 'ficha',
                'issue' => "{$stats['performance_pending']} anúncios ativos com performance oficial pendente (unknown — não tratar como 0)",
                'impact' => 0,
                'affected_items' => $stats['performance_pending'],
                'sample_item_ids' => $stats['performance_pending_ids'],
                'performance_pending' => true,
                'solution' => 'Aguardar item-performance-sync gravar performance_score em items.data.',
            ];
        }

        return ['problems' => $problems, 'opportunities' => $opportunities];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function collectOfficialListingGapStats(array $items): array
    {
        $stats = [
            'universe_active' => 0,
            'universe_paused' => 0,
            'photos_lt3' => 0,
            'stock_0' => 0,
            'catalog_not_listing' => 0,
            'no_free_shipping' => 0,
            'not_premium' => 0,
            'performance_pending' => 0,
            'pending_unique' => 0,
            'pending_ids' => [],
            'photos_lt3_ids' => [],
            'stock_0_ids' => [],
            'catalog_not_listing_ids' => [],
            'no_free_shipping_ids' => [],
            'not_premium_ids' => [],
            'performance_pending_ids' => [],
            'item_rows' => [],
        ];

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === 'paused') {
                $stats['universe_paused']++;
                continue;
            }
            if ($status !== '' && $status !== 'active') {
                continue;
            }
            $stats['universe_active']++;
            $mlb = strtoupper(trim((string) ($item['id'] ?? $item['ml_item_id'] ?? '')));
            $flags = $this->officialListingGapFlags($item);
            if ($flags['photos_lt3']) {
                $stats['photos_lt3']++;
                $this->pushFichaSampleId($stats['photos_lt3_ids'], $mlb);
            }
            if ($flags['stock_0']) {
                $stats['stock_0']++;
                $this->pushFichaSampleId($stats['stock_0_ids'], $mlb);
            }
            if ($flags['catalog_not_listing']) {
                $stats['catalog_not_listing']++;
                $this->pushFichaSampleId($stats['catalog_not_listing_ids'], $mlb);
            }
            if ($flags['no_free_shipping']) {
                $stats['no_free_shipping']++;
                $this->pushFichaSampleId($stats['no_free_shipping_ids'], $mlb);
            }
            if ($flags['not_premium']) {
                $stats['not_premium']++;
                $this->pushFichaSampleId($stats['not_premium_ids'], $mlb);
            }
            if ($flags['performance_pending']) {
                $stats['performance_pending']++;
                $this->pushFichaSampleId($stats['performance_pending_ids'], $mlb);
            }
            if ($flags['any']) {
                $stats['pending_unique']++;
                $this->pushFichaSampleId($stats['pending_ids'], $mlb);
                $stats['item_rows'][] = [
                    'id' => $mlb,
                    'title' => (string) ($item['title'] ?? ''),
                    'gaps' => array_keys(array_filter([
                        'photos_lt3' => $flags['photos_lt3'],
                        'stock_0' => $flags['stock_0'],
                        'catalog_not_listing' => $flags['catalog_not_listing'],
                        'no_free_shipping' => $flags['no_free_shipping'],
                        'not_premium' => $flags['not_premium'],
                        'performance_pending' => $flags['performance_pending'],
                    ])),
                ];
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, int>
     */
    private function summarizeOfficialListingGaps(array $stats): array
    {
        return [
            'universe_active' => (int) ($stats['universe_active'] ?? 0),
            'universe_paused' => (int) ($stats['universe_paused'] ?? 0),
            'photos_lt3' => (int) ($stats['photos_lt3'] ?? 0),
            'stock_0' => (int) ($stats['stock_0'] ?? 0),
            'catalog_not_listing' => (int) ($stats['catalog_not_listing'] ?? 0),
            'no_free_shipping' => (int) ($stats['no_free_shipping'] ?? 0),
            'not_premium' => (int) ($stats['not_premium'] ?? 0),
            'performance_pending' => (int) ($stats['performance_pending'] ?? 0),
            'pending_unique' => (int) ($stats['pending_unique'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{
     *   photos_lt3: bool,
     *   stock_0: bool,
     *   catalog_not_listing: bool,
     *   no_free_shipping: bool,
     *   not_premium: bool,
     *   performance_pending: bool,
     *   any: bool
     * }
     */
    private function officialListingGapFlags(array $item): array
    {
        $photos = $item['pictures'] ?? [];
        $photoCount = is_array($photos) ? count($photos) : 0;
        $stock = (int) ($item['available_quantity'] ?? 0);
        $catalogId = trim((string) ($item['catalog_product_id'] ?? ''));
        $catalogListing = $item['catalog_listing'] ?? false;
        $isCatalogListing = $catalogListing === true || $catalogListing === 1 || $catalogListing === 'true';
        $shipping = $item['shipping'] ?? [];
        $freeShipping = is_array($shipping) && !empty($shipping['free_shipping']);
        $listingType = (string) ($item['listing_type_id'] ?? '');
        $performancePending = !(array_key_exists('performance_score', $item) && is_numeric($item['performance_score']));

        $flags = [
            'photos_lt3' => $photoCount < 3,
            'stock_0' => $stock <= 0,
            'catalog_not_listing' => $catalogId !== '' && !$isCatalogListing,
            'no_free_shipping' => !$freeShipping,
            'not_premium' => $listingType !== 'gold_pro',
            'performance_pending' => $performancePending,
        ];
        $flags['any'] = in_array(true, $flags, true);

        return $flags;
    }

    /**
     * Full active+paused universe from local items for this account. No ML GET /items.
     *
     * @return list<array<string, mixed>>
     */
    private function loadLocalListingUniverse(): array
    {
        if (!isset($this->db) || !$this->db instanceof \PDO || ($this->accountId ?? 0) <= 0) {
            return [];
        }

        $sqlVariants = [
            "SELECT ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data
             FROM items
             WHERE account_id = ? AND status IN ('active', 'paused')
             ORDER BY ml_item_id ASC",
            "SELECT ml_item_id, title, status, available_quantity, sold_quantity, catalog_product_id, data
             FROM ml_items
             WHERE account_id = ? AND status IN ('active', 'paused')
             ORDER BY ml_item_id ASC",
        ];

        $stmt = null;
        foreach ($sqlVariants as $sql) {
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$this->accountId]);
                break;
            } catch (\Throwable) {
                $stmt = null;
            }
        }
        if ($stmt === null) {
            return [];
        }

        $items = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $decoded = json_decode((string) ($row['data'] ?? ''), true);
            $data = is_array($decoded) ? $decoded : [];
            $mlb = strtoupper(trim((string) ($row['ml_item_id'] ?? $data['id'] ?? '')));
            if ($mlb === '') {
                continue;
            }
            $item = $data;
            $item['id'] = $mlb;
            $item['ml_item_id'] = $mlb;
            $item['title'] = (string) ($row['title'] ?? $data['title'] ?? '');
            $item['status'] = (string) ($row['status'] ?? $data['status'] ?? '');
            if (isset($row['available_quantity']) && is_numeric($row['available_quantity'])) {
                $item['available_quantity'] = (int) $row['available_quantity'];
            }
            if (isset($row['sold_quantity']) && is_numeric($row['sold_quantity'])) {
                $item['sold_quantity'] = (int) $row['sold_quantity'];
            }
            $catalog = trim((string) ($row['catalog_product_id'] ?? ''));
            if ($catalog === '') {
                $catalog = trim((string) ($data['catalog_product_id'] ?? ''));
            }
            $item['catalog_product_id'] = $catalog !== '' ? $catalog : null;
            if (!array_key_exists('catalog_listing', $item)) {
                $item['catalog_listing'] = false;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Helper: Get all items with pagination
     * Prefers the local items universe (account-scoped). ML list is fallback only.
     */
    private function getAllItems(): array
    {
        $local = $this->loadLocalListingUniverse();
        if ($local !== []) {
            $local = $this->hydrateOfficialPerformanceFromLocalItems($local);
            log_info('SEOKillerEngine: itens carregados do cache local', [
                'service' => 'SEOKillerEngine',
                'count' => count($local),
                'account_id' => $this->accountId,
                'source' => 'local_items',
            ]);
            return $local;
        }

        $allItems = [];
        $offset = 0;
        $limit = 50;
        $maxItems = 1000; // Safety limit

        try {
            while ($offset < $maxItems) {
                // Buscar itens ativos e pausados (não apenas ativos)
                $result = $this->itemService->listItems([
                    'limit' => $limit,
                    'offset' => $offset,
                    'allow_local_cache' => true, // Permitir fallback para cache local
                    'skip_visits' => true, // Nunca getMultiItemVisits no diagnose
                ]);

                $items = $result['items'] ?? [];

                // Se não retornou itens, chegamos ao fim
                if (empty($items)) {
                    break;
                }

                $allItems = array_merge($allItems, $items);
                $offset += $limit;

                // Se retornou menos que o limit, não há mais páginas
                if (count($items) < $limit) {
                    break;
                }
            }

            $allItems = $this->hydrateOfficialPerformanceFromLocalItems($allItems);

            log_info('SEOKillerEngine: itens carregados', [
                'service' => 'SEOKillerEngine',
                'count' => count($allItems),
            ]);
            return $allItems;
        } catch (\Exception $e) {
            log_error('SEOKillerEngine: erro ao buscar todos os itens', [
                'service' => 'SEOKillerEngine',
                'error' => $e->getMessage(),
                'partial_count' => count($allItems),
            ]);
            // Retorna o que conseguiu buscar até o erro
            return $this->hydrateOfficialPerformanceFromLocalItems($allItems);
        }
    }

    /**
     * Helper: Get item description
     */
    private function getItemDescription(string $itemId): string
    {
        try {
            $desc = $this->mlClient->get("/items/{$itemId}/description");
            return $desc['plain_text'] ?? $desc['text'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }
}
