<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Services\AI\SEO\AttributeKiller;
use App\Services\TitleAttributeExtractorService;
use App\Services\TechSheetBenchmarkService;
use App\Services\KeywordMinerService;
use PDO;

class TechSheetService
{
    private PDO $db;
    private int $accountId;
    private AttributeKiller $attributeKiller;
    private MercadoLivreClient $mlClient;
    private TitleAttributeExtractorService $titleExtractor;
    private ?TechSheetBenchmarkService $benchmarkService = null;
    private ?KeywordMinerService $keywordMiner = null;

    // Opções de contexto para geração de sugestões (temporárias por chamada)
    private int $currentMinConfidence = 60;
    private bool $currentUseAi = false;
    private bool $currentUseTitle = true;
    private bool $currentUseBenchmark = true;

    /**
     * Fontes canônicas de sugestões (usadas para gravação e queries)
     *
     * Mapeamento:
     * - title: Extração regex/NLP do título do anúncio
     * - benchmark: Análise de concorrentes da mesma categoria
     * - ai: Gerado por IA (LLM)
     * - inference: Inferência por regras de negócio
     * - default: Valor padrão/fallback da categoria
     * - manual: Inserção manual pelo usuário
     */
    public const SOURCE_TITLE = 'title';
    public const SOURCE_BENCHMARK = 'benchmark';
    public const SOURCE_AI = 'ai';
    public const SOURCE_INFERENCE = 'inference';
    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_EAN_POOL = 'ean_pool';
    public const SOURCE_EMPTY_GTIN = 'empty_gtin_policy';
    public const SOURCE_HIDDEN_SEO = 'hidden_seo';

    /**
     * Fontes consideradas seguras para auto-approve
     */
    public const SAFE_SOURCES = [self::SOURCE_TITLE, self::SOURCE_BENCHMARK, self::SOURCE_INFERENCE];

    /**
     * Todas as fontes válidas
     */
    public const ALL_SOURCES = [
        self::SOURCE_TITLE,
        self::SOURCE_BENCHMARK,
        self::SOURCE_AI,
        self::SOURCE_INFERENCE,
        self::SOURCE_DEFAULT,
        self::SOURCE_MANUAL,
        self::SOURCE_EAN_POOL,
        self::SOURCE_EMPTY_GTIN,
        self::SOURCE_HIDDEN_SEO,
    ];

    /**
     * TTL para reaproveitar resumo (em segundos).
     * Como o cálculo usa cache de atributos de categoria, dá para manter bem alto.
     */
    private int $summaryTtlSeconds = 12 * 3600;

    /**
     * Feature flag para habilitar benchmark de concorrentes
     */
    private bool $benchmarkEnabled = true;

    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->attributeKiller = new AttributeKiller($accountId);
        $this->mlClient = new MercadoLivreClient($accountId);
        $this->titleExtractor = new TitleAttributeExtractorService();

        // Inicializar benchmark service (lazy load se necessário)
        $config = \App\Core\Config::getInstance()->all();
        $this->benchmarkEnabled = ($config['tech_sheet']['benchmark_enabled'] ?? true) === true;
    }

    /**
     * Obtém instância do benchmark service (lazy load)
     */
    private function getBenchmarkService(): TechSheetBenchmarkService
    {
        if ($this->benchmarkService === null) {
            $this->benchmarkService = new TechSheetBenchmarkService($this->accountId);
        }
        return $this->benchmarkService;
    }

    /**
     * Obtém instância do keyword miner service (lazy load)
     */
    private function getKeywordMiner(): KeywordMinerService
    {
        if ($this->keywordMiner === null) {
            $this->keywordMiner = new KeywordMinerService();
        }
        return $this->keywordMiner;
    }

    public function listItems(array $filters = []): array
    {
        $perPage = max(1, min(50, (int)($filters['per_page'] ?? 20)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $where = ['i.account_id = :account_id'];
        $params = [':account_id' => $this->accountId];

        if (!empty($filters['q'])) {
            $where[] = 'i.title LIKE :q';
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'i.category_id = :category_id';
            $params[':category_id'] = $filters['category_id'];
        }

        $this->applyListingStatusFilter($where, $params, $filters);
        $this->applyListingTabFilter($where, $filters);

        if (array_key_exists('has_pending_suggestions', $filters) && $filters['has_pending_suggestions'] !== null && $filters['has_pending_suggestions'] !== '') {
            $has = (int)$filters['has_pending_suggestions'];
            $where[] = $has ? 'COALESCE(sc.pending_count, 0) > 0' : 'COALESCE(sc.pending_count, 0) = 0';
        }

        if (isset($filters['min_completeness']) && $filters['min_completeness'] !== '' && $filters['min_completeness'] !== null) {
            $where[] = 'COALESCE(s.completeness_percent, 0) >= :min_completeness';
            $params[':min_completeness'] = (float)$filters['min_completeness'];
        }

        if (isset($filters['max_completeness']) && $filters['max_completeness'] !== '' && $filters['max_completeness'] !== null) {
            $where[] = 'COALESCE(s.completeness_percent, 0) <= :max_completeness';
            $params[':max_completeness'] = (float)$filters['max_completeness'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Adicionar parâmetro separado para a subquery (PDO não permite mesmo nome 2x)
        $params[':account_id_sub'] = $this->accountId;

        $fromSql = "FROM items i\n"
            . "LEFT JOIN tech_sheet_item_summary s\n"
            . "  ON s.account_id = i.account_id AND s.item_id = i.ml_item_id\n"
            . "LEFT JOIN (\n"
            . "    SELECT item_id,\n"
            . "           SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,\n"
            . "           SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,\n"
            . "           SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,\n"
            . "           SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END) AS applied_count\n"
            . "    FROM tech_sheet_suggestions\n"
            . "    WHERE account_id = :account_id_sub\n"
            . "    GROUP BY item_id\n"
            . ") sc ON sc.item_id = i.ml_item_id\n"
            . $whereSql;

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$fromSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sort = strtolower((string)($filters['sort'] ?? ''));
        $orderSql = 'i.updated_at DESC';
        if ($sort === 'completeness') {
            $orderSql = 'COALESCE(s.completeness_percent, -1) ASC, i.updated_at DESC';
        } elseif ($sort === 'missing_required') {
            $orderSql = 'COALESCE(s.missing_required, 0) DESC, COALESCE(s.missing_filter, 0) DESC, i.updated_at DESC';
        } elseif ($sort === 'missing_hidden') {
            $orderSql = 'COALESCE(s.missing_hidden, 0) DESC, i.updated_at DESC';
        } elseif ($sort === 'pending_suggestions') {
            $orderSql = 'COALESCE(sc.pending_count, 0) DESC, i.updated_at DESC';
        } elseif ($sort === 'total_gaps') {
            // Ordenar pelo total de lacunas (missing_required + missing_filter + missing_hidden)
            $orderSql = '(COALESCE(s.missing_required, 0) + COALESCE(s.missing_filter, 0) + COALESCE(s.missing_hidden, 0)) DESC, i.updated_at DESC';
        }

        $limitSql = max(1, min((int)$perPage, 200));
        $offsetSql = max(0, (int)$offset);

        $stmt = $this->db->prepare(
            "SELECT\n"
                . "  i.ml_item_id, i.title, i.category_id, i.status, i.data, i.updated_at,\n"
                . "  s.completeness_percent, s.missing_required, s.missing_filter, s.missing_hidden, s.missing_recommended,\n"
                . "  s.updated_at AS summary_updated_at,\n"
                . "  COALESCE(sc.pending_count, 0) AS pending_suggestions_count,\n"
                . "  COALESCE(sc.approved_count, 0) AS approved_suggestions_count,\n"
                . "  COALESCE(sc.rejected_count, 0) AS rejected_suggestions_count,\n"
                . "  COALESCE(sc.applied_count, 0) AS applied_suggestions_count\n"
                . "{$fromSql}\n"
                . "ORDER BY {$orderSql} LIMIT {$limitSql} OFFSET {$offsetSql}"
        );

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $itemRow = [
                'ml_item_id' => $row['ml_item_id'],
                'title' => $row['title'],
                'category_id' => $row['category_id'],
                'status' => $row['status'],
                'data' => $row['data'],
                'updated_at' => $row['updated_at'],
            ];

            $summaryUpdatedAt = $row['summary_updated_at'] ?? null;
            $summaryIsFresh = $summaryUpdatedAt ? $this->isSummaryFresh(['updated_at' => $summaryUpdatedAt]) : false;

            if ($summaryIsFresh && $row['completeness_percent'] !== null) {
                $summary = [
                    'completeness_percent' => (float)$row['completeness_percent'],
                    'missing_required' => (int)($row['missing_required'] ?? 0),
                    'missing_filter' => (int)($row['missing_filter'] ?? 0),
                    'missing_hidden' => (int)($row['missing_hidden'] ?? 0),
                    'missing_recommended' => (int)($row['missing_recommended'] ?? 0),
                ];
            } else {
                $summary = $this->getOrComputeSummary($itemRow);
            }

            $items[] = [
                'item_id' => $row['ml_item_id'],
                'title' => $row['title'],
                'category_id' => $row['category_id'],
                'status' => $row['status'],
                'updated_at' => $row['updated_at'],

                'completeness_percent' => $summary['completeness_percent'] ?? null,
                'missing_required' => $summary['missing_required'] ?? null,
                'missing_filter' => $summary['missing_filter'] ?? null,
                'missing_hidden' => $summary['missing_hidden'] ?? null,
                'missing_recommended' => $summary['missing_recommended'] ?? null,

                'pending_suggestions_count' => (int)($row['pending_suggestions_count'] ?? 0),
                'approved_suggestions_count' => (int)($row['approved_suggestions_count'] ?? 0),
                'rejected_suggestions_count' => (int)($row['rejected_suggestions_count'] ?? 0),
                'applied_suggestions_count' => (int)($row['applied_suggestions_count'] ?? 0),
            ];
        }

        $payload = [
            'success' => true,
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
            ],
        ];

        return $payload;
    }

    /**
     * KPIs/Stats para a tela de listagem (rápido e sem N+1).
     * Retorna números agregados com base nos mesmos filtros da listagem.
     */
    public function stats(array $filters = []): array
    {
        $where = ['i.account_id = :account_id'];
        $params = [':account_id' => $this->accountId];

        if (!empty($filters['q'])) {
            $where[] = 'i.title LIKE :q';
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'i.category_id = :category_id';
            $params[':category_id'] = $filters['category_id'];
        }

        $this->applyListingStatusFilter($where, $params, $filters);
        $this->applyListingTabFilter($where, $filters);

        if (array_key_exists('has_pending_suggestions', $filters) && $filters['has_pending_suggestions'] !== null && $filters['has_pending_suggestions'] !== '') {
            $has = (int)$filters['has_pending_suggestions'];
            $where[] = $has ? 'COALESCE(sc.pending_count, 0) > 0' : 'COALESCE(sc.pending_count, 0) = 0';
        }

        if (isset($filters['min_completeness']) && $filters['min_completeness'] !== '' && $filters['min_completeness'] !== null) {
            $where[] = 'COALESCE(s.completeness_percent, 0) >= :min_completeness';
            $params[':min_completeness'] = (float)$filters['min_completeness'];
        }

        if (isset($filters['max_completeness']) && $filters['max_completeness'] !== '' && $filters['max_completeness'] !== null) {
            $where[] = 'COALESCE(s.completeness_percent, 0) <= :max_completeness';
            $params[':max_completeness'] = (float)$filters['max_completeness'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Adicionar parâmetro separado para a subquery (PDO não permite mesmo nome 2x)
        $params[':account_id_sub'] = $this->accountId;

        $fromSql = "FROM items i\n"
            . "LEFT JOIN tech_sheet_item_summary s\n"
            . "  ON s.account_id = i.account_id AND s.item_id = i.ml_item_id\n"
            . "LEFT JOIN (\n"
            . "    SELECT item_id,\n"
            . "           SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count\n"
            . "    FROM tech_sheet_suggestions\n"
            . "    WHERE account_id = :account_id_sub\n"
            . "    GROUP BY item_id\n"
            . ") sc ON sc.item_id = i.ml_item_id\n"
            . $whereSql;

        $sql = "SELECT\n"
            . "  COUNT(*) AS total_items,\n"
            . "  SUM(CASE WHEN s.item_id IS NULL THEN 1 ELSE 0 END) AS unanalyzed_items,\n"
            . "  SUM(CASE WHEN COALESCE(s.missing_required,0) > 0 THEN 1 ELSE 0 END) AS critical_gap_items,\n"
            . "  SUM(CASE WHEN COALESCE(s.missing_filter,0) > 0 THEN 1 ELSE 0 END) AS filter_gap_items,\n"
            . "  SUM(CASE WHEN COALESCE(s.missing_hidden,0) > 0 THEN 1 ELSE 0 END) AS hidden_gap_items,\n"
            . "  SUM(CASE WHEN (s.item_id IS NULL OR COALESCE(s.missing_required,0) > 0 OR COALESCE(s.missing_filter,0) > 0 OR COALESCE(s.missing_hidden,0) > 0) THEN 1 ELSE 0 END) AS pending_gap_items,\n"
            . "  SUM(COALESCE(s.missing_hidden, 0)) AS total_missing_hidden,\n"
            . "  SUM(COALESCE(sc.pending_count, 0)) AS pending_suggestions_total,\n"
            . "  SUM(CASE WHEN COALESCE(sc.pending_count, 0) > 0 THEN 1 ELSE 0 END) AS items_with_pending_suggestions,\n"
            . "  AVG(CASE WHEN s.item_id IS NOT NULL THEN s.completeness_percent END) AS avg_completeness_analyzed\n"
            . $fromSql;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Buscar estatísticas de MODEL separadamente
        $modelStats = $this->getModelSuggestionStats();

        $payload = [
            'success' => true,
            'total_items' => (int)($r['total_items'] ?? 0),
            'unanalyzed_items' => (int)($r['unanalyzed_items'] ?? 0),
            'critical_gap_items' => (int)($r['critical_gap_items'] ?? 0),
            'filter_gap_items' => (int)($r['filter_gap_items'] ?? 0),
            'hidden_gap_items' => (int)($r['hidden_gap_items'] ?? 0),
            'pending_gap_items' => (int)($r['pending_gap_items'] ?? 0),
            'total_missing_hidden' => (int)($r['total_missing_hidden'] ?? 0),
            'pending_suggestions_total' => (int)($r['pending_suggestions_total'] ?? 0),
            'items_with_pending_suggestions' => (int)($r['items_with_pending_suggestions'] ?? 0),
            'avg_completeness_analyzed' => $r['avg_completeness_analyzed'] !== null ? (float)$r['avg_completeness_analyzed'] : null,
            // MODEL suggestion stats
            'model_suggestions' => $modelStats,
            'ean_balance' => (new EanService())->getBalance($this->accountId),
        ];

        return $payload;
    }

    /**
     * Filtro de status da listagem/stats.
     * Default: apenas anúncios active (alinhado ao Analytics).
     * Para incluir todos: status=all
     *
     * @param list<string> $where
     * @param array<string, mixed> $params
     * @param array<string, mixed> $filters
     */
    private function applyListingStatusFilter(array &$where, array &$params, array $filters): void
    {
        $status = $filters['status'] ?? null;
        if ($status === null || $status === '') {
            $status = 'active';
        }
        $status = strtolower(trim((string)$status));
        if ($status === 'all' || $status === '*') {
            return;
        }
        $where[] = 'i.status = :status';
        $params[':status'] = $status;
    }

    /**
     * Filtros de aba da listagem/KPIs.
     * - pending (Lacunas): qualquer lacuna (required/filter/hidden) ou sem análise
     * - critical (Críticas): só required/filter ou sem análise — NÃO inclui hidden-only
     * - hidden: missing_hidden > 0
     * - review (Sugestões): tem sugestões pending
     * - done: analisado, sem lacunas e sem sugestões pending
     */
    private function applyListingTabFilter(array &$where, array $filters): void
    {
        $tab = strtolower(trim((string)($filters['tab'] ?? '')));
        if ($tab === '' || $tab === 'all') {
            return;
        }

        if ($tab === 'review') {
            $where[] = 'COALESCE(sc.pending_count, 0) > 0';
            return;
        }

        if ($tab === 'done') {
            $where[] = 's.item_id IS NOT NULL';
            $where[] = 'COALESCE(sc.pending_count, 0) = 0';
            $where[] = 'COALESCE(s.missing_required, 0) = 0';
            $where[] = 'COALESCE(s.missing_filter, 0) = 0';
            $where[] = 'COALESCE(s.missing_hidden, 0) = 0';
            return;
        }

        if ($tab === 'critical') {
            $where[] = '(s.item_id IS NULL OR COALESCE(s.missing_required, 0) > 0 OR COALESCE(s.missing_filter, 0) > 0)';
            return;
        }

        if ($tab === 'hidden') {
            $where[] = 'COALESCE(s.missing_hidden, 0) > 0';
            return;
        }

        if ($tab === 'pending') {
            $where[] = '(s.item_id IS NULL OR COALESCE(s.missing_required, 0) > 0 OR COALESCE(s.missing_filter, 0) > 0 OR COALESCE(s.missing_hidden, 0) > 0)';
        }
    }

    /**
     * Retorna estatísticas de sugestões de MODEL (somente anúncios active)
     */
    private function getModelSuggestionStats(): array
    {
        // Itens active que realmente precisam de MODEL (flag gravada no meta pelo analyzer v2+)
        $needsModel = $this->db->prepare("
            SELECT COUNT(DISTINCT s.item_id) as count
            FROM tech_sheet_item_summary s
            INNER JOIN items i
              ON i.account_id = s.account_id AND i.ml_item_id = s.item_id
            WHERE s.account_id = :account_id
              AND i.status = 'active'
              AND (
                JSON_EXTRACT(s.meta, '$.missing_model') = true
                OR JSON_UNQUOTE(JSON_EXTRACT(s.meta, '$.missing_model')) = 'true'
                OR JSON_EXTRACT(s.meta, '$.missing_model') = 1
              )
        ");
        $needsModel->execute([':account_id' => $this->accountId]);
        $itemsNeedingModel = (int)($needsModel->fetchColumn() ?: 0);

        // Itens active com sugestões de MODEL pendentes
        $hasModelSuggestion = $this->db->prepare("
            SELECT COUNT(DISTINCT t.item_id) as count
            FROM tech_sheet_suggestions t
            INNER JOIN items i
              ON i.account_id = t.account_id AND i.ml_item_id = t.item_id
            WHERE t.account_id = :account_id
              AND i.status = 'active'
              AND t.attribute_id IN ('MODEL','COMPATIBLE_VEHICLE_MODELS','VEHICLE_MODEL','MOTO_MODEL','ALPHANUMERIC_MODEL')
              AND t.status = 'pending'
        ");
        $hasModelSuggestion->execute([':account_id' => $this->accountId]);
        $itemsWithModelSuggestions = (int)($hasModelSuggestion->fetchColumn() ?: 0);

        // Total de sugestões de MODEL pendentes em anúncios active
        $totalModelSuggestions = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM tech_sheet_suggestions t
            INNER JOIN items i
              ON i.account_id = t.account_id AND i.ml_item_id = t.item_id
            WHERE t.account_id = :account_id
              AND i.status = 'active'
              AND t.attribute_id IN ('MODEL','COMPATIBLE_VEHICLE_MODELS','VEHICLE_MODEL','MOTO_MODEL','ALPHANUMERIC_MODEL')
              AND t.status = 'pending'
        ");
        $totalModelSuggestions->execute([':account_id' => $this->accountId]);
        $totalSuggestions = (int)($totalModelSuggestions->fetchColumn() ?: 0);

        $coverage = $itemsNeedingModel > 0
            ? round(($itemsWithModelSuggestions / $itemsNeedingModel) * 100, 1)
            : ($itemsWithModelSuggestions > 0 ? 100.0 : 0.0);

        return [
            'items_needing_model' => $itemsNeedingModel,
            'items_with_suggestions' => $itemsWithModelSuggestions,
            'total_suggestions' => $totalSuggestions,
            'coverage_percent' => $coverage,
        ];
    }

    /**
     * Busca dados frescos da API do ML e re-analisa o item
     * Atualiza a tabela items e tech_sheet_item_summary
     */
    public function refreshItemFromApi(string $itemId): array
    {
        try {
            // 1. Buscar dados frescos da API do ML
            $mlData = $this->mlClient->get("/items/{$itemId}");

            if (isset($mlData['error'])) {
                return ['success' => false, 'error' => 'Erro ao buscar item na API: ' . ($mlData['message'] ?? 'desconhecido')];
            }

            // 2. Atualizar a tabela items com dados frescos
            $stmt = $this->db->prepare("
                UPDATE items SET
                    title = :title,
                    category_id = :category_id,
                    price = :price,
                    currency_id = :currency_id,
                    available_quantity = :available_quantity,
                    status = :status,
                    condition_type = :condition_type,
                    catalog_product_id = :catalog_product_id,
                    data = :data,
                    updated_at = NOW()
                WHERE account_id = :account_id AND ml_item_id = :ml_item_id
            ");

            $stmt->execute([
                ':title' => $mlData['title'] ?? '',
                ':category_id' => $mlData['category_id'] ?? null,
                ':price' => $mlData['price'] ?? 0,
                ':currency_id' => $mlData['currency_id'] ?? 'BRL',
                ':available_quantity' => $mlData['available_quantity'] ?? 0,
                ':status' => $mlData['status'] ?? 'unknown',
                ':condition_type' => $mlData['condition'] ?? null,
                ':catalog_product_id' => $mlData['catalog_product_id'] ?? null,
                ':data' => json_encode($mlData),
                ':account_id' => $this->accountId,
                ':ml_item_id' => $itemId,
            ]);

            // 3. Re-analisar gaps com dados frescos
            $categoryId = $mlData['category_id'] ?? null;
            if (!$categoryId) {
                return ['success' => false, 'error' => 'Item sem categoria definida'];
            }

            $gaps = $this->attributeKiller->analyzeGaps($itemId, (string)$categoryId, $mlData);

            // 4. Atualizar summary
            $row = $this->getLocalItemRow($itemId);
            if ($row) {
                $summary = $this->getOrComputeSummary($row, $gaps, true);
            }

            return [
                'success' => true,
                'item_id' => $itemId,
                'title' => $mlData['title'] ?? '',
                'category_id' => $categoryId,
                'attributes_count' => count($mlData['attributes'] ?? []),
                'gaps' => $gaps,
                'summary' => $summary ?? null,
                'refreshed_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Exceção: ' . $e->getMessage()];
        }
    }

    public function getItem(string $itemId): array
    {
        $row = $this->getLocalItemRow($itemId);
        if (!$row) {
            return ['success' => false, 'error' => 'Item não encontrado no cache local. Sincronize os anúncios primeiro.'];
        }

        $itemData = $this->decodeItemData($row);
        $categoryId = $row['category_id'] ?: ($itemData['category_id'] ?? null);

        if (!$categoryId) {
            // Fallback: tenta buscar no ML
            $ml = $this->mlClient->get("/items/{$itemId}");
            $categoryId = $ml['category_id'] ?? null;
            $itemData = array_merge($itemData, $ml);
        }

        $gaps = $this->attributeKiller->analyzeGaps($itemId, (string)$categoryId, $itemData);
        $summary = $this->getOrComputeSummary($row, $gaps);

        $suggestions = $this->listSuggestionsForItem($itemId);

        return [
            'success' => true,
            'item' => [
                'item_id' => $itemId,
                'title' => $row['title'] ?? ($itemData['title'] ?? null),
                'category_id' => $categoryId,
                'status' => $row['status'] ?? null,
            ],
            'summary' => $summary,
            'gaps' => $gaps,
            'suggestions' => $suggestions,
        ];
    }

    public function generateSuggestions(string $itemId, array $options = []): array
    {
        // Opções configuráveis (valores default mantêm comportamento original)
        $useTitle = $options['use_title'] ?? true;
        $useBenchmark = $options['use_benchmark'] ?? $this->benchmarkEnabled;
        $useAi = $options['use_ai'] ?? false;
        $minConfidence = $options['min_confidence'] ?? 60;

        // Guardar min_confidence para uso interno
        $this->currentMinConfidence = $minConfidence;
        $this->currentUseAi = $useAi;
        $this->currentUseTitle = $useTitle;
        $this->currentUseBenchmark = $useBenchmark;

        $row = $this->getLocalItemRow($itemId);
        if (!$row) {
            return ['success' => false, 'error' => 'Item não encontrado no cache local.'];
        }

        $itemData = $this->decodeItemData($row);
        $categoryId = $row['category_id'] ?: ($itemData['category_id'] ?? null);
        if (!$categoryId) {
            return ['success' => false, 'error' => 'category_id ausente (item não sincronizado corretamente).'];
        }

        // 1. Obter atributos permitidos da categoria
        $categoryAttributes = $this->attributeKiller->getCategoryAttributes((string)$categoryId);

        // 2. Extrair atributos do título primeiro
        $title = $row['title'] ?? ($itemData['title'] ?? '');
        $categoryType = $this->titleExtractor->detectCategoryType($title);
        $titleExtracted = $this->titleExtractor->extractFromTitle(
            $title,
            $categoryAttributes,
            $categoryType
        );

        // 3. Gerar plano de atributos faltantes via AttributeKiller
        $plan = $this->attributeKiller->planMissingAttributes($itemId, (string)$categoryId, $itemData);
        if (!($plan['success'] ?? false)) {
            return ['success' => false, 'error' => $plan['error'] ?? 'Falha ao gerar plano'];
        }

        $created = 0;
        $processedAttributes = [];

        // 4. Primeiro, inserir sugestões do título (maior prioridade para regex do título)
        // Respeitar opção use_title
        if (!$useTitle) {
            $titleExtracted = []; // Skip title extraction se desabilitado
        }

        foreach ($titleExtracted as $extracted) {
            $attributeId = (string)($extracted['attribute_id'] ?? '');
            $value = (string)($extracted['value'] ?? '');
            $extractedConfidence = (int)($extracted['confidence'] ?? 88);

            if ($attributeId === '' || $value === '') {
                continue;
            }

            $modelPolicy = new TechSheetModelSuggestionPolicy();
            if ($modelPolicy->isModelAttribute($attributeId)) {
                $cleanModel = $modelPolicy->cleanCandidate($value, (string)$title);
                if ($cleanModel === null) {
                    continue;
                }
                $value = $cleanModel;
            }

            // Respeitar min_confidence
            if ($extractedConfidence < $minConfidence) {
                continue;
            }

            // Verificar se este atributo está na lista de gaps
            $isGap = false;
            foreach (($plan['gaps'] ?? []) as $gap) {
                if (($gap['id'] ?? '') === $attributeId) {
                    $isGap = true;
                    break;
                }
            }

            // Só criar sugestão se for um gap
            if (!$isGap) {
                continue;
            }

            $ok = $this->upsertSuggestion([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'category_id' => $categoryId,
                'attribute_id' => $attributeId,
                'attribute_name' => $this->getAttributeName($categoryAttributes, $attributeId),
                'suggested_value' => $value,
                'source' => self::SOURCE_TITLE,
                'confidence' => (int)($extracted['confidence'] ?? 88),
                'status' => 'pending',
                'meta' => [
                    'method' => $extracted['method'] ?? 'regex_extraction',
                    'priority' => 'high',
                ],
            ]);

            if ($ok) {
                $created++;
                $processedAttributes[$attributeId] = true;
            }
        }

        // 5. Em seguida, processar sugestões do AttributeKiller (evitar duplicatas)
        foreach (($plan['filled'] ?? []) as $sugg) {
            $attributeId = (string)($sugg['id'] ?? '');
            $attributeName = $sugg['name'] ?? null;
            $value = (string)($sugg['value'] ?? '');
            $method = (string)($sugg['method'] ?? 'inference');

            if ($attributeId === '' || $value === '') {
                continue;
            }

            // Pular se já processado pela extração do título
            if (isset($processedAttributes[$attributeId])) {
                continue;
            }

            // Normalizar source: 'ai' -> SOURCE_AI, default -> SOURCE_INFERENCE
            $source = match ($method) {
                'ai' => self::SOURCE_AI,
                'default', 'category_default' => self::SOURCE_DEFAULT,
                default => self::SOURCE_INFERENCE,
            };
            $confidence = $method === 'ai' ? 75 : 85;

            // Respeitar use_ai: pular sugestões de IA se desabilitado
            if ($source === self::SOURCE_AI && !$useAi) {
                continue;
            }

            // Respeitar min_confidence
            if ($confidence < $minConfidence) {
                continue;
            }

            $ok = $this->upsertSuggestion([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'category_id' => $categoryId,
                'attribute_id' => $attributeId,
                'attribute_name' => $attributeName,
                'suggested_value' => $value,
                'source' => $source,
                'confidence' => $confidence,
                'status' => 'pending',
                'meta' => [
                    'priority' => $sugg['priority'] ?? null,
                ],
            ]);

            if ($ok) {
                $created++;
                $processedAttributes[$attributeId] = true;
            }
        }

        // 6. Sugestões de benchmark de concorrentes (se habilitado E use_benchmark permitido)
        $benchmarkCreated = 0;
        if ($this->benchmarkEnabled && $useBenchmark && !empty($plan['gaps'])) {
            try {
                $benchmarkResult = $this->getBenchmarkService()->generateBenchmarkSuggestions(
                    $itemId,
                    $categoryId,
                    $plan['gaps']
                );

                if (($benchmarkResult['success'] ?? false) && !empty($benchmarkResult['suggestions'])) {
                    foreach ($benchmarkResult['suggestions'] as $benchSugg) {
                        $attrId = $benchSugg['attribute_id'] ?? '';

                        if (!is_string($attrId) || $attrId === '') {
                            continue;
                        }

                        // Respeitar min_confidence também no benchmark
                        $benchConfidence = (int)($benchSugg['confidence'] ?? 0);
                        if ($benchConfidence < $minConfidence) {
                            continue;
                        }

                        // Pular se já temos sugestão para este atributo
                        if (isset($processedAttributes[$attrId])) {
                            continue;
                        }

                        $ok = $this->upsertSuggestion($benchSugg);
                        if ($ok) {
                            $benchmarkCreated++;
                            $processedAttributes[$attrId] = true;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Não bloquear se benchmark falhar
                log_warning('Erro no benchmark de ficha técnica', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Atualiza resumo com os gaps mais recentes
        $gaps = $plan['gaps'] ?? null;
        if (is_array($gaps)) {
            $this->getOrComputeSummary($row, $gaps);
        }

        $titleForGtin = (string)($row['title'] ?? ($itemData['title'] ?? ''));
        $gtinResult = $this->appendGtinSuggestion(
            $itemId,
            (string)$categoryId,
            $categoryAttributes,
            $itemData,
            $titleForGtin
        );
        $gtinCreated = !empty($gtinResult['created']) ? 1 : 0;
        if ($gtinCreated > 0 && !empty($gtinResult['result']['suggestion']['attribute_id'])) {
            $processedAttributes[(string)$gtinResult['result']['suggestion']['attribute_id']] = true;
        }

        $hiddenResult = $this->appendHiddenSuggestions(
            $itemId,
            (string)$categoryId,
            $categoryAttributes,
            $itemData,
            $titleForGtin,
            array_keys($processedAttributes)
        );
        $hiddenCreated = (int)($hiddenResult['created'] ?? 0);

        return [
            'success' => true,
            'item_id' => $itemId,
            'created' => $created + $benchmarkCreated + $gtinCreated + $hiddenCreated,
            'title_extracted' => count($titleExtracted),
            'benchmark_created' => $benchmarkCreated,
            'gtin' => $gtinResult,
            'hidden' => $hiddenResult,
            'plan' => $plan,
        ];
    }

    /**
     * Gera sugestões rápidas usando APENAS extração por regex (sem AI)
     * Mais rápido e não depende de API externa
     */
    public function generateQuickSuggestions(string $itemId): array
    {
        $row = $this->getLocalItemRow($itemId);
        if (!$row) {
            return ['success' => false, 'error' => 'Item não encontrado no cache local.'];
        }

        $itemData = $this->decodeItemData($row);
        $categoryId = $row['category_id'] ?: ($itemData['category_id'] ?? null);
        if (!$categoryId) {
            return ['success' => false, 'error' => 'category_id ausente.'];
        }

        // 1. Obter atributos permitidos da categoria
        $categoryAttributes = $this->attributeKiller->getCategoryAttributes((string)$categoryId);

        // 2. Analisar gaps atuais
        $gapsResult = $this->attributeKiller->analyzeGaps($itemId, (string)$categoryId, $itemData);
        $allGaps = array_merge(
            $gapsResult['gaps']['required'] ?? [],
            $gapsResult['gaps']['filter'] ?? [],
            $gapsResult['gaps']['hidden'] ?? [],
            $gapsResult['gaps']['recommended'] ?? []
        );

        // Criar mapa de gaps para lookup rápido - por ID e por nome normalizado
        $gapIds = array_column($allGaps, 'id');
        $gapMap = []; // id => gap data
        $gapNameMap = []; // normalized_name => gap data
        foreach ($allGaps as $gap) {
            $gapMap[$gap['id']] = $gap;
            $normalizedName = $this->normalizeAttributeName($gap['name'] ?? '');
            $gapNameMap[$normalizedName] = $gap;
        }

        // 3. Extrair atributos do título (sem filtrar por categoria - o mapeamento de aliases fará isso)
        $title = $row['title'] ?? ($itemData['title'] ?? '');
        $categoryType = $this->titleExtractor->detectCategoryType($title);
        $titleExtracted = $this->titleExtractor->extractFromTitle(
            $title,
            [], // Não passar atributos da categoria - deixar mapeamento de aliases funcionar
            $categoryType
        );

        $created = 0;
        $skipped = 0;
        $matchedByAlias = 0;
        $matchedByValue = 0;
        $suggestions = [];

        // 4. Criar sugestões - usando mapeamento inteligente
        foreach ($titleExtracted as $extracted) {
            $attributeId = (string)($extracted['attribute_id'] ?? '');
            $value = (string)($extracted['value'] ?? '');

            if ($attributeId === '' || $value === '') {
                continue;
            }

            // ESTRATÉGIA 1: Match direto por ID
            $matchedGap = null;
            $matchMethod = null;

            if (in_array($attributeId, $gapIds)) {
                $matchedGap = $gapMap[$attributeId];
                $matchMethod = 'direct_id';
            }

            // ESTRATÉGIA 2: Match por aliases de atributo
            if (!$matchedGap) {
                $aliasMatch = $this->findGapByAlias($attributeId, $allGaps);
                if ($aliasMatch) {
                    $matchedGap = $aliasMatch;
                    $matchMethod = 'alias_mapping';
                    $matchedByAlias++;
                }
            }

            // ESTRATÉGIA 3: Match por valor - verificar se o valor extraído bate com allowed_values de algum gap
            if (!$matchedGap) {
                $valueMatch = $this->findGapByValue($value, $allGaps);
                if ($valueMatch) {
                    $matchedGap = $valueMatch;
                    $matchMethod = 'value_matching';
                    $matchedByValue++;
                }
            }

            if (!$matchedGap) {
                $skipped++;
                continue;
            }

            $finalAttributeId = $matchedGap['id'];
            $finalValue = $value;

            // Se tem allowed_values, tentar normalizar o valor para o ID correto
            if (!empty($matchedGap['allowed_values'])) {
                $normalizedValue = $this->normalizeToAllowedValue($value, $matchedGap['allowed_values']);
                if ($normalizedValue !== null) {
                    $finalValue = $normalizedValue;
                }
            }

            $ok = $this->upsertSuggestion([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'category_id' => $categoryId,
                'attribute_id' => $finalAttributeId,
                'attribute_name' => $matchedGap['name'] ?? $this->getAttributeName($categoryAttributes, $finalAttributeId),
                'suggested_value' => $finalValue,
                'source' => self::SOURCE_TITLE,  // Normalizado: era 'title_extraction'
                'confidence' => (int)($extracted['confidence'] ?? 85),
                'status' => 'pending',
                'meta' => [
                    'method' => $extracted['method'] ?? 'regex_extraction',
                    'match_method' => $matchMethod,
                    'original_attribute' => $attributeId,
                    'quick_mode' => true,
                    'extraction_type' => 'title_extraction',  // Guardar tipo original no meta
                ],
            ]);

            if ($ok) {
                $created++;
                $suggestions[] = [
                    'attribute_id' => $finalAttributeId,
                    'value' => $finalValue,
                    'confidence' => $extracted['confidence'] ?? 85,
                    'match_method' => $matchMethod,
                ];
            }
        }

        // 5. Atualizar summary
        $this->getOrComputeSummary($row, $gapsResult);

        return [
            'success' => true,
            'item_id' => $itemId,
            'created' => $created,
            'skipped' => $skipped,
            'matched_by_alias' => $matchedByAlias,
            'matched_by_value' => $matchedByValue,
            'total_gaps' => count($allGaps),
            'extracted' => count($titleExtracted),
            'suggestions' => $suggestions,
            'message' => $created > 0
                ? "{$created} sugestão(ões) criada(s) por extração do título"
                : "Nenhum atributo pôde ser extraído do título para preencher gaps",
        ];
    }

    /**
     * Gera sugestões avançadas para o atributo MODEL usando múltiplas estratégias de busca
     *
     * Estratégias:
     * 1. Autocomplete Mining - Usa título parcial para buscar sugestões
     * 2. Category Trends - Busca trends da categoria e extrai modelos
     * 3. Competitor Analysis - Extrai modelos dos top sellers
     * 4. Search Volume Scoring - Prioriza modelos com maior volume de busca
     *
     * @param string $itemId ID do item
     * @return array Resultado com sugestões ranqueadas por relevância
     */
    public function generateModelSuggestions(string $itemId, bool $dryRun = false): array
    {
        $row = $this->getLocalItemRow($itemId);
        if (!$row) {
            return ['success' => false, 'error' => 'Item não encontrado'];
        }

        $itemData = $this->decodeItemData($row);
        $categoryId = $row['category_id'] ?: ($itemData['category_id'] ?? null);
        $title = $row['title'] ?? ($itemData['title'] ?? '');

        if (!$categoryId) {
            return ['success' => false, 'error' => 'Categoria não identificada'];
        }

        $policy = new TechSheetModelSuggestionPolicy();
        if ($policy->isAmbiguousMultiModel((string)$title)) {
            return $this->suggestUmbrellaModelForAmbiguousTitle(
                $itemId,
                (string)$categoryId,
                (string)$title,
                $itemData,
                $policy,
                $dryRun
            );
        }

        // Verificar se MODEL é um gap
        $gapsResult = $this->attributeKiller->analyzeGaps($itemId, (string)$categoryId, $itemData);
        $allGaps = array_merge(
            $gapsResult['gaps']['required'] ?? [],
            $gapsResult['gaps']['filter'] ?? [],
            $gapsResult['gaps']['hidden'] ?? [],
            $gapsResult['gaps']['recommended'] ?? []
        );

        $modelGap = null;
        $modelAliases = TechSheetModelSuggestionPolicy::MODEL_ATTRIBUTE_IDS;
        foreach ($allGaps as $gap) {
            if (in_array($gap['id'], $modelAliases, true)) {
                $modelGap = $gap;
                break;
            }
        }

        if (!$modelGap) {
            return [
                'success' => true,
                'item_id' => $itemId,
                'message' => 'MODEL não é um gap - atributo já preenchido ou não disponível na categoria',
                'suggestions' => [],
            ];
        }

        $suggestions = [];
        $strategies = [];

        // ===== ESTRATÉGIA 1: Autocomplete Mining =====
        $autocompleteModels = $this->mineModelsFromAutocomplete($title, $categoryId);
        if (!empty($autocompleteModels)) {
            $strategies['autocomplete'] = count($autocompleteModels);
            foreach ($autocompleteModels as $model) {
                $key = mb_strtolower($model['value']);
                if (!isset($suggestions[$key])) {
                    $suggestions[$key] = [
                        'value' => $model['value'],
                        'score' => 0,
                        'sources' => [],
                        'inferred_signal_score' => $model['position_relevance_score'] ?? 0,
                    ];
                }
                $suggestions[$key]['score'] += 30; // Base score for autocomplete
                $suggestions[$key]['sources'][] = 'autocomplete';
            }
        }

        // ===== ESTRATÉGIA 2: Category Trends =====
        $trendModels = $this->mineModelsFromTrends($categoryId, $title);
        if (!empty($trendModels)) {
            $strategies['trends'] = count($trendModels);
            foreach ($trendModels as $model) {
                $key = mb_strtolower($model['value']);
                if (!isset($suggestions[$key])) {
                    $suggestions[$key] = [
                        'value' => $model['value'],
                        'score' => 0,
                        'sources' => [],
                        'inferred_signal_score' => 0,
                    ];
                }
                $suggestions[$key]['score'] += 25 + ($model['trend_score'] ?? 0);
                $suggestions[$key]['sources'][] = 'trends';
                $suggestions[$key]['inferred_signal_score'] = max(
                    $suggestions[$key]['inferred_signal_score'],
                    $model['position_relevance_score'] ?? 0
                );
            }
        }

        // ===== ESTRATÉGIA 3: Competitor Analysis =====
        $competitorModels = $this->mineModelsFromCompetitors($title, $categoryId);
        if (!empty($competitorModels)) {
            $strategies['competitors'] = count($competitorModels);
            foreach ($competitorModels as $model) {
                $key = mb_strtolower($model['value']);
                if (!isset($suggestions[$key])) {
                    $suggestions[$key] = [
                        'value' => $model['value'],
                        'score' => 0,
                        'sources' => [],
                        'inferred_signal_score' => 0,
                    ];
                }
                $suggestions[$key]['score'] += 20 + ($model['frequency'] ?? 1) * 5;
                $suggestions[$key]['sources'][] = 'competitors';
            }
        }

        // ===== ESTRATÉGIA 4: Title Extraction (já existente) =====
        $titleModels = $this->titleExtractor->extractFromTitle($title);
        foreach ($titleModels as $extracted) {
            if (!in_array($extracted['attribute_id'], ['COMPATIBLE_VEHICLE_MODELS', 'VEHICLE_MODEL', 'MOTO_MODEL', 'MODEL'])) {
                continue;
            }
                $key = mb_strtolower($extracted['value']);
            if (!isset($suggestions[$key])) {
                $suggestions[$key] = [
                    'value' => $extracted['value'],
                    'score' => 0,
                    'sources' => [],
                    'inferred_signal_score' => 0,
                ];
            }
            $suggestions[$key]['score'] += 40; // High score for being in title
            $suggestions[$key]['sources'][] = 'title';
        }

        // ===== ESTRATÉGIA 5: Itens da mesma categoria na conta =====
        $sameCategModels = $this->mineModelsFromSameCategory($categoryId, $itemId, $title);
        if (!empty($sameCategModels)) {
            $strategies['same_category'] = count($sameCategModels);
            foreach ($sameCategModels as $model) {
                $key = mb_strtolower($model['value']);
                if (!isset($suggestions[$key])) {
                    $suggestions[$key] = [
                        'value' => $model['value'],
                        'score' => 0,
                        'sources' => [],
                        'inferred_signal_score' => 0,
                    ];
                }
                $suggestions[$key]['score'] += 15 + ($model['frequency'] ?? 1) * 3;
                $suggestions[$key]['sources'][] = 'same_category';
            }
        }

        // ===== ESTRATÉGIA 6: Catálogo Local de Modelos =====
        $catalogModels = $this->mineModelsFromLocalCatalog($title);
        if (!empty($catalogModels)) {
            $strategies['local_catalog'] = count($catalogModels);
            foreach ($catalogModels as $model) {
                $key = mb_strtolower($model['value']);
                if (!isset($suggestions[$key])) {
                    $suggestions[$key] = [
                        'value' => $model['value'],
                        'score' => 0,
                        'sources' => [],
                        'inferred_signal_score' => 0,
                    ];
                }
                $suggestions[$key]['score'] += 35 + ($model['match_score'] ?? 0);
                $suggestions[$key]['sources'][] = 'local_catalog';
                if (!empty($model['brand'])) {
                    $suggestions[$key]['brand'] = $model['brand'];
                }
            }
        }

        // ===== ESTRATÉGIA 7: Inferência por Tipo de Produto =====
        // Só usar se poucas sugestões foram encontradas
        if (count($suggestions) < 3) {
            $inferredModels = $this->inferModelsFromProductType($title, $categoryId);
            if (!empty($inferredModels)) {
                $strategies['inferred_type'] = count($inferredModels);
                foreach ($inferredModels as $model) {
                    $key = mb_strtolower($model['value']);
                    if (!isset($suggestions[$key])) {
                        $suggestions[$key] = [
                            'value' => $model['value'],
                            'score' => 0,
                            'sources' => [],
                            'inferred_signal_score' => 0,
                        ];
                    }
                    // Score menor pois é inferência
                    $score = $model['confidence'] === 'high' ? 20 : 10;
                    $suggestions[$key]['score'] += $score;
                    $suggestions[$key]['sources'][] = 'inferred_type';
                }
            }
        }

        // ===== ESTRATÉGIA 8: Keyword Mining da API ML =====
        $minedModels = $this->mineModelsFromKeywordAPI($title, $categoryId);
        if (!empty($minedModels)) {
            $strategies['ml_keyword_api'] = count($minedModels);
            foreach ($minedModels as $model) {
                $key = mb_strtolower($model['value']);
                if (!isset($suggestions[$key])) {
                    $suggestions[$key] = [
                        'value' => $model['value'],
                        'score' => 0,
                        'sources' => [],
                        'inferred_signal_score' => 0,
                    ];
                }
                $suggestions[$key]['score'] += $model['score'] ?? 15;
                $suggestions[$key]['sources'][] = 'ml_keyword_api';
            }
        }

        // ===== Política: MODEL = identificador limpo (não keyword stuffing) =====
        $cleanedList = $policy->selectBest(array_values($suggestions), $title);
        $suggestions = [];
        foreach ($cleanedList as $cleanRow) {
            $key = mb_strtolower($cleanRow['value']);
            $suggestions[$key] = $cleanRow;
        }

        // Ordenar por score
        uasort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);

        // Formatar resultado
        $rankedSuggestions = [];
        $rank = 1;
        foreach ($suggestions as $suggestion) {
            $rankedSuggestions[] = [
                'rank' => $rank++,
                'value' => $suggestion['value'],
                'score' => $suggestion['score'],
                'sources' => array_unique($suggestion['sources']),
                'inferred_signal_score' => $suggestion['inferred_signal_score'],
                'recommendation' => $this->getRecommendationLevel($suggestion['score']),
                'policy' => $suggestion['policy'] ?? 'semantic_clean',
            ];
        }

        // Criar sugestões no banco para os top 3, MAS APENAS SE RELEVANTES
        $created = 0;
        $titleLower = mb_strtolower($title);

        foreach (array_slice($rankedSuggestions, 0, 3) as $suggestion) {
            $valueLower = mb_strtolower($suggestion['value']);

            // VALIDAÇÃO CRÍTICA: O modelo sugerido deve estar presente no título
            // ou ter vindo da fonte 'title' diretamente
            $isFromTitle = in_array('title', $suggestion['sources']) || in_array('local_catalog', $suggestion['sources']);
            $isInTitle = stripos($titleLower, $valueLower) !== false;

            // Também aceitar se o modelo é uma parte significativa do título
            // Ex: "CG 160" pode aparecer como "cg160" ou "cg-160"
            $normalizedValue = preg_replace('/[\s\-]+/', '', $valueLower);
            $normalizedTitle = preg_replace('/[\s\-]+/', '', $titleLower);
            $isInTitleNormalized = stripos($normalizedTitle, $normalizedValue) !== false;

            if (!$isFromTitle && !$isInTitle && !$isInTitleNormalized) {
                // Modelo não é relevante para este item - PULAR
                continue;
            }

            $cleanValue = $policy->cleanCandidate((string)$suggestion['value'], $title);
            if ($cleanValue === null) {
                continue;
            }

            // Ajustar confidence baseado na relevância
            $confidence = $suggestion['score'];
            if ($isFromTitle) {
                $confidence = min(95, $confidence + 20); // Boost se veio do título
            } elseif ($isInTitle || $isInTitleNormalized) {
                $confidence = min(90, $confidence + 10); // Boost se está no título
            }

            $ok = true;
            if (!$dryRun) {
                $ok = $this->upsertSuggestion([
                    'account_id' => $this->accountId,
                    'item_id' => $itemId,
                    'category_id' => $categoryId,
                    'attribute_id' => $modelGap['id'],
                    'attribute_name' => $modelGap['name'] ?? 'Modelo',
                    'suggested_value' => $cleanValue,
                    'source' => 'search_strategy',
                    'confidence' => $confidence,
                    'status' => 'pending',
                    'meta' => [
                        'strategy_sources' => $suggestion['sources'],
                        // Sinal derivado (posição em autocomplete/trends), não é volume de busca medido.
                        'inferred_signal_score' => $suggestion['inferred_signal_score'],
                        'rank' => $suggestion['rank'],
                        'validation' => $isFromTitle ? 'from_title' : ($isInTitle ? 'in_title' : 'in_title_normalized'),
                        'policy' => 'semantic_field_mapping',
                    ],
                ]);
            }
            if ($ok) {
                $created++;
            }
        }

        return [
            'success' => true,
            'item_id' => $itemId,
            'title' => $title,
            'category_id' => $categoryId,
            'gap_attribute' => $modelGap['id'],
            'strategies_used' => $strategies,
            'suggestions_found' => count($suggestions),
            'suggestions_created' => $created,
            'dry_run' => $dryRun,
            'ranked_suggestions' => array_slice($rankedSuggestions, 0, 10),
            'message' => count($rankedSuggestions) > 0
                ? "Encontradas " . count($rankedSuggestions) . " sugestões de modelo via estratégias de busca"
                : "Nenhum modelo encontrado via estratégias de busca",
        ];
    }

    /**
     * Título multi-compatível: tenta MODEL guarda-chuva (ex. CG 160) em vez de skip cego.
     *
     * @return array<string,mixed>
     */
    private function suggestUmbrellaModelForAmbiguousTitle(
        string $itemId,
        string $categoryId,
        string $title,
        array $itemData,
        TechSheetModelSuggestionPolicy $policy,
        bool $dryRun = false
    ): array {
        $signals = $policy->listModelLineSignals($title);
        $umbrella = $policy->resolveUmbrellaModel($title);

        if ($umbrella === null) {
            return [
                'success' => true,
                'item_id' => $itemId,
                'skipped' => true,
                'reason' => 'ambiguous_multi_model_title',
                'signals' => $signals,
                'message' => 'Título lista múltiplos modelos sem família/cilindrada clara — MODEL único não sugerido',
                'suggestions' => [],
                'dry_run' => $dryRun,
            ];
        }

        $gapsResult = $this->attributeKiller->analyzeGaps($itemId, $categoryId, $itemData);
        $allGaps = array_merge(
            $gapsResult['gaps']['required'] ?? [],
            $gapsResult['gaps']['filter'] ?? [],
            $gapsResult['gaps']['hidden'] ?? [],
            $gapsResult['gaps']['recommended'] ?? []
        );

        $modelGap = null;
        foreach ($allGaps as $gap) {
            if (($gap['id'] ?? '') === 'MODEL') {
                $modelGap = $gap;
                break;
            }
        }

        if ($modelGap === null) {
            return [
                'success' => true,
                'item_id' => $itemId,
                'skipped' => true,
                'reason' => 'model_not_a_gap',
                'signals' => $signals,
                'umbrella' => $umbrella,
                'message' => 'MODEL não é gap — guarda-chuva não gravado',
                'suggestions' => [],
                'dry_run' => $dryRun,
            ];
        }

        $ok = true;
        if (!$dryRun) {
            $ok = $this->upsertSuggestion([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'category_id' => $categoryId,
                'attribute_id' => 'MODEL',
                'attribute_name' => $modelGap['name'] ?? 'Modelo',
                'suggested_value' => $umbrella['value'],
                'source' => 'search_strategy',
                'confidence' => 88,
                'status' => 'pending',
                'meta' => [
                    'policy' => 'umbrella_multi_compatible',
                    'family' => $umbrella['family'],
                    'signals' => $umbrella['signals'],
                    'displacement' => $umbrella['displacement'],
                    'validation' => 'umbrella_from_title',
                ],
            ]);
        }

        return [
            'success' => true,
            'item_id' => $itemId,
            'title' => $title,
            'category_id' => $categoryId,
            'gap_attribute' => 'MODEL',
            'suggestions_created' => ($ok && !$dryRun) ? 1 : ($dryRun ? 1 : 0),
            'umbrella' => $umbrella,
            'signals' => $signals,
            'dry_run' => $dryRun,
            'ranked_suggestions' => [[
                'rank' => 1,
                'value' => $umbrella['value'],
                'score' => 88,
                'sources' => ['title', 'umbrella_family'],
                'policy' => 'umbrella_multi_compatible',
            ]],
            'message' => 'MULTI-compatível → MODEL guarda-chuva ' . $umbrella['value']
                . ($dryRun ? ' (dry-run)' : ''),
        ];
    }

    /**
     * Minera modelos do autocomplete do ML
     */
    private function mineModelsFromAutocomplete(string $title, string $categoryId): array
    {
        $models = [];

        // Extrair palavras-chave do título para usar como query
        $words = preg_split('/\s+/', $title);
        $queries = [];

        // Query 1: Primeiras 3 palavras
        if (count($words) >= 2) {
            $queries[] = implode(' ', array_slice($words, 0, 3));
        }

        // Query 2: Se tiver marca conhecida, usar marca + tipo
        $knownBrands = ['honda', 'yamaha', 'suzuki', 'kawasaki', 'bmw', 'ducati', 'triumph', 'harley', 'ktm'];
        foreach ($words as $i => $word) {
            if (in_array(mb_strtolower($word), $knownBrands)) {
                // Marca + próxima palavra
                $nextWord = $words[$i + 1] ?? '';
                if ($nextWord) {
                    $queries[] = "{$word} {$nextWord}";
                }
            }
        }

        foreach (array_unique($queries) as $query) {
            $suggestions = $this->mlClient->getAutocompleteSuggestions($query, $categoryId);

            foreach ($suggestions as $suggestion) {
                // Extrair modelos das sugestões
                $extractedModels = $this->extractModelsFromText($suggestion);
                foreach ($extractedModels as $model) {
                    $models[] = [
                        'value' => $model,
                        'source_query' => $query,
                        // Sinal derivado da posição no autocomplete, NÃO é volume de busca real.
                        'position_relevance_score' => $this->estimatePositionRelevanceScore(count($models)),
                    ];
                }
            }
        }

        return $models;
    }

    /**
     * Minera modelos das trends da categoria
     */
    private function mineModelsFromTrends(string $categoryId, string $title): array
    {
        $models = [];
        $titleLower = mb_strtolower($title);

        $trends = $this->mlClient->getTrends($categoryId);

        foreach ($trends as $i => $trend) {
            // Verificar se o trend tem overlap com o título
            $trendLower = mb_strtolower($trend);
            $trendWords = preg_split('/\s+/', $trendLower);

            $hasOverlap = false;
            foreach ($trendWords as $word) {
                if (mb_strlen($word) > 2 && str_contains($titleLower, $word)) {
                    $hasOverlap = true;
                    break;
                }
            }

            if (!$hasOverlap) {
                continue;
            }

            // Extrair modelos do trend
            $extractedModels = $this->extractModelsFromText($trend);
            foreach ($extractedModels as $model) {
                $models[] = [
                    'value' => $model,
                    'trend_keyword' => $trend,
                    'trend_score' => max(0, 20 - $i), // Maior score para trends no topo
                    // Sinal derivado da posição no trend, NÃO é volume de busca real.
                    'position_relevance_score' => $this->estimatePositionRelevanceScore($i),
                ];
            }
        }

        return $models;
    }

    /**
     * Minera modelos dos concorrentes top sellers
     */
    private function mineModelsFromCompetitors(string $title, string $categoryId): array
    {
        $models = [];
        $modelCounts = [];

        // Extrair keywords principais do título
        $words = preg_split('/\s+/', $title);
        $searchQuery = implode(' ', array_slice($words, 0, 4));

        // Buscar top 20 concorrentes
        $competitors = $this->mlClient->searchByKeyword($searchQuery, $categoryId, 20);

        foreach ($competitors['results'] ?? [] as $item) {
            $competitorTitle = $item['title'] ?? '';
            $extractedModels = $this->extractModelsFromText($competitorTitle);

            foreach ($extractedModels as $model) {
                $key = mb_strtolower($model);
                if (!isset($modelCounts[$key])) {
                    $modelCounts[$key] = ['value' => $model, 'count' => 0];
                }
                $modelCounts[$key]['count']++;
            }
        }

        // Ordenar por frequência
        uasort($modelCounts, fn($a, $b) => $b['count'] <=> $a['count']);

        foreach ($modelCounts as $data) {
            $models[] = [
                'value' => $data['value'],
                'frequency' => $data['count'],
            ];
        }

        return array_slice($models, 0, 15);
    }

    /**
     * Minera modelos de outros itens da mesma categoria na conta
     * Esta estratégia não depende de APIs externas
     */
    private function mineModelsFromSameCategory(string $categoryId, string $excludeItemId, string $currentTitle): array
    {
        $models = [];
        $modelCounts = [];
        $currentTitleLower = mb_strtolower($currentTitle);

        // Buscar outros itens da mesma categoria
        $stmt = $this->db->prepare("
            SELECT i.ml_item_id, i.title
            FROM items i
            WHERE i.account_id = :account_id
            AND i.category_id = :category_id
            AND i.ml_item_id != :exclude_id
            AND i.status = 'active'
            LIMIT 50
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'category_id' => $categoryId,
            'exclude_id' => $excludeItemId,
        ]);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $itemTitle = $row['title'] ?? '';
            $itemTitleLower = mb_strtolower($itemTitle);

            // Verificar se tem palavras em comum (produtos relacionados)
            $currentWords = array_filter(preg_split('/\s+/', $currentTitleLower), fn($w) => mb_strlen($w) > 2);
            $itemWords = array_filter(preg_split('/\s+/', $itemTitleLower), fn($w) => mb_strlen($w) > 2);
            $commonWords = array_intersect($currentWords, $itemWords);

            // Se tiver pelo menos 2 palavras em comum, extrair modelos
            if (count($commonWords) >= 2) {
                $extractedModels = $this->extractModelsFromText($itemTitle);

                foreach ($extractedModels as $model) {
                    $key = mb_strtolower($model);
                    if (!isset($modelCounts[$key])) {
                        $modelCounts[$key] = ['value' => $model, 'count' => 0];
                    }
                    $modelCounts[$key]['count']++;
                }
            }
        }

        // Ordenar por frequência
        uasort($modelCounts, fn($a, $b) => $b['count'] <=> $a['count']);

        foreach ($modelCounts as $data) {
            $models[] = [
                'value' => $data['value'],
                'frequency' => $data['count'],
            ];
        }

        return array_slice($models, 0, 20);
    }

    /**
     * Extrai modelos de um texto usando padrões conhecidos
     */
    private function extractModelsFromText(string $text): array
    {
        $models = [];

        // Padrões de modelos de moto/veículo
        $patterns = [
            // BMW: GS650, F800, R1200, S1000RR
            '/\b([FGRSK]\s*\d{3,4}(?:\s*[A-Z]{1,2})?)\b/i',
            // Honda: CG125, CB500, XRE300, NXR160
            '/\b((?:CG|CB|XR|NX|PCX|BIZ|POP|BROS|FAN|TITAN|START)\s*\d{2,4})\b/i',
            // Yamaha: MT-03, YZF-R1, XTZ250
            '/\b((?:MT|YZF|XTZ|FZ|FAZER|YBR|CROSSER|LANDER|NMAX)\s*[-]?\s*\d{2,4})\b/i',
            // Kawasaki: Z400, Ninja 400, ZX-10R
            '/\b((?:Z|ZX|NINJA|VULCAN|VERSYS)\s*[-]?\s*\d{2,4}(?:\s*[A-Z]{1,2})?)\b/i',
            // Genéricos: 125cc, 150cc, 250cc
            '/\b(\d{2,4}\s*(?:cc|CC|cilindradas?))\b/i',
            // Modelos com ano: 2020, 2021, 2022, etc
            '/\b(20[12]\d)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $match) {
                    $model = trim(preg_replace('/\s+/', ' ', $match));
                    if (mb_strlen($model) >= 2) {
                        $models[] = $model;
                    }
                }
            }
        }

        return array_unique($models);
    }

    /**
     * CATÁLOGO LOCAL de modelos de motos populares no Brasil
     * Alternativa às APIs bloqueadas do ML
     */
    private function getLocalModelsCatalog(): array
    {
        return [
            // HONDA - Mais vendidas Brasil
            'honda' => [
                'CG 125',
                'CG 150',
                'CG 160',
                'CG 160 Titan',
                'CG 160 Start',
                'CG 160 Fan',
                'CG Titan',
                'CG Fan',
                'CG Start',
                'CG Cargo',
                'CB 250F Twister',
                'CB 300R',
                'CB 300F',
                'CB 500F',
                'CB 500X',
                'CB 650R',
                'CB 1000R',
                'Bros 125',
                'Bros 150',
                'Bros 160',
                'NXR 125 Bros',
                'NXR 150 Bros',
                'NXR 160 Bros',
                'XRE 190',
                'XRE 300',
                'XRE 300 Rally',
                'Biz 100',
                'Biz 110',
                'Biz 125',
                'Pop 100',
                'Pop 110',
                'PCX 150',
                'PCX 160',
                'Elite 125',
                'Sahara 350',
                'NC 750X',
                'Africa Twin 1100',
            ],
            // YAMAHA
            'yamaha' => [
                'Factor 125',
                'Factor 150',
                'Fazer 150',
                'Fazer 250',
                'Fazer FZ25',
                'YBR 125',
                'YBR 150',
                'Lander 250',
                'XTZ 125',
                'XTZ 150',
                'XTZ 250',
                'XTZ 250 Lander',
                'XTZ 250 Ténéré',
                'Crosser 150',
                'Crosser S',
                'MT-03',
                'MT-07',
                'MT-09',
                'YZF-R3',
                'YZF-R6',
                'YZF-R1',
                'NMAX 160',
                'NEO 125',
                'Fluo 125',
                'XJ6 N',
                'XJ6 F',
                'XT 660R',
                'XT 660Z Ténéré',
                'Tracer 900 GT',
            ],
            // SUZUKI
            'suzuki' => [
                'Intruder 125',
                'Intruder 250',
                'Yes 125',
                'GSR 125',
                'GSR 150',
                'Burgman 125',
                'Burgman 400',
                'V-Strom 650',
                'V-Strom 1000',
                'V-Strom 1050',
                'GSX-S750',
                'GSX-S1000',
                'GSX-R750',
                'GSX-R1000',
                'GSX-R1000R',
                'Hayabusa 1300',
                'DR 650',
                'DR-Z400',
            ],
            // KAWASAKI
            'kawasaki' => [
                'Z400',
                'Z650',
                'Z900',
                'Z900RS',
                'Z1000',
                'Ninja 250',
                'Ninja 300',
                'Ninja 400',
                'Ninja 650',
                'Ninja 1000',
                'Ninja ZX-6R',
                'Ninja ZX-10R',
                'Versys 650',
                'Versys 1000',
                'Vulcan 650 S',
                'Vulcan 900',
                'KLX 110',
                'KLX 140',
                'KLX 300',
                'KX 250',
                'KX 450',
            ],
            // BMW
            'bmw' => [
                'G 310 R',
                'G 310 GS',
                'F 750 GS',
                'F 800 GS',
                'F 850 GS',
                'F 850 GS Adventure',
                'R 1200 GS',
                'R 1250 GS',
                'R 1250 GS Adventure',
                'S 1000 RR',
                'S 1000 R',
                'S 1000 XR',
                'R 18',
                'R nineT',
                'C 400 X',
                'C 400 GT',
            ],
            // DAFRA / SHINERAY / HAOJUE
            'dafra' => [
                'Apache 150',
                'Apache RTR 200',
                'Citycom 300',
                'Horizon 150',
                'Horizon 250',
                'Riva 150',
                'Speed 150',
            ],
            'shineray' => [
                'Jet 50',
                'Jet 125',
                'Phoenix 50',
                'Phoenix Gold',
                'XY 50',
                'XY 150',
            ],
            'haojue' => [
                'DK 150',
                'DK 160',
                'DR 160',
                'NK 150',
                'VR 150',
            ],
            // TRIUMPH
            'triumph' => [
                'Street Triple',
                'Speed Triple',
                'Tiger 800',
                'Tiger 900',
                'Tiger 1200',
                'Bonneville T100',
                'Bonneville T120',
                'Rocket 3',
                'Trident 660',
            ],
            // DUCATI
            'ducati' => [
                'Monster 821',
                'Monster 1200',
                'Panigale V2',
                'Panigale V4',
                'Multistrada 950',
                'Multistrada V4',
                'Scrambler',
                'Diavel',
            ],
            // HARLEY-DAVIDSON
            'harley-davidson' => [
                'Sportster 883',
                'Sportster 1200',
                'Sportster S',
                'Iron 883',
                'Iron 1200',
                'Fat Boy',
                'Fat Bob',
                'Street Glide',
                'Road Glide',
                'Road King',
                'Breakout',
                'Softail',
            ],
            // ROYAL ENFIELD
            'royal-enfield' => [
                'Classic 350',
                'Classic 500',
                'Meteor 350',
                'Himalayan 411',
                'Continental GT 650',
                'Interceptor 650',
            ],
            // KTM
            'ktm' => [
                'Duke 200',
                'Duke 390',
                'Duke 690',
                'Duke 790',
                'Duke 890',
                'Duke 1290',
                'Adventure 390',
                'Adventure 790',
                'Adventure 890',
                'Adventure 1290',
                'RC 390',
                'EXC 250',
                'EXC 300',
                'EXC 450',
            ],
            // GENÉRICOS/UNIVERSAIS
            'universal' => [
                '50cc',
                '100cc',
                '110cc',
                '125cc',
                '150cc',
                '160cc',
                '200cc',
                '250cc',
                '300cc',
                '400cc',
                '500cc',
                '600cc',
                '650cc',
                '750cc',
                '800cc',
                '900cc',
                '1000cc',
                '1100cc',
                '1200cc',
                '1300cc',
            ],
        ];
    }

    /**
     * NOVA ESTRATÉGIA: Busca no catálogo local de modelos
     * Verifica se palavras do título correspondem a modelos conhecidos
     */
    private function mineModelsFromLocalCatalog(string $title): array
    {
        $models = [];
        $titleLower = mb_strtolower($title);
        $catalog = $this->getLocalModelsCatalog();

        foreach ($catalog as $brand => $brandModels) {
            // Verificar se a marca está no título
            $brandLower = mb_strtolower($brand);
            $brandInTitle = str_contains($titleLower, $brandLower);

            foreach ($brandModels as $model) {
                $modelLower = mb_strtolower($model);

                // Verificar se o modelo está no título
                if (str_contains($titleLower, $modelLower)) {
                    $score = $brandInTitle ? 10 : 5; // Maior score se marca também está
                    $models[] = [
                        'value' => $model,
                        'brand' => $brand,
                        'match_score' => $score,
                    ];
                    continue;
                }

                // Verificar variações (sem espaços, com hífen, etc)
                $modelVariations = [
                    str_replace(' ', '', $modelLower),      // CG160
                    str_replace(' ', '-', $modelLower),     // CG-160
                    preg_replace('/\s+/', '', $modelLower), // Remove todos espaços
                ];

                foreach ($modelVariations as $variation) {
                    if (str_contains($titleLower, $variation)) {
                        $models[] = [
                            'value' => $model,
                            'brand' => $brand,
                            'match_score' => $brandInTitle ? 8 : 4,
                        ];
                        break;
                    }
                }
            }
        }

        // Ordenar por score e remover duplicatas
        usort($models, fn($a, $b) => $b['match_score'] <=> $a['match_score']);

        $seen = [];
        $unique = [];
        foreach ($models as $model) {
            $key = mb_strtolower($model['value']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $model;
            }
        }

        return array_slice($unique, 0, 10);
    }

    /**
     * NOVA ESTRATÉGIA: Inferir modelos compatíveis por tipo de produto
     * Ex: "Retrovisor Universal" → sugere modelos populares da categoria
     */
    private function inferModelsFromProductType(string $title, string $categoryId): array
    {
        $models = [];
        $titleLower = mb_strtolower($title);

        // Detectar se é produto universal
        $isUniversal = str_contains($titleLower, 'universal')
            || str_contains($titleLower, 'todas as motos')
            || str_contains($titleLower, 'várias motos')
            || str_contains($titleLower, 'diversas motos');

        // Detectar tipo de moto pelo título
        $motoTypes = [];

        if (preg_match('/\b(scooter|pcx|nmax|burgman)\b/i', $title)) {
            $motoTypes[] = 'scooter';
        }
        if (preg_match('/\b(trail|xre|lander|tenere|adventure|xtz)\b/i', $title)) {
            $motoTypes[] = 'trail';
        }
        if (preg_match('/\b(esportiva|ninja|r1|r6|cbr|gsxr|panigale)\b/i', $title)) {
            $motoTypes[] = 'esportiva';
        }
        if (preg_match('/\b(custom|cruiser|intruder|shadow|dragstar|vulcan)\b/i', $title)) {
            $motoTypes[] = 'custom';
        }
        if (preg_match('/\b(cg|titan|fan|ybr|factor|start)\b/i', $title)) {
            $motoTypes[] = 'street';
        }

        // Se não detectou tipo, inferir da categoria ou marcar como street (mais comum)
        if (empty($motoTypes)) {
            $motoTypes[] = 'street';
        }

        // Modelos mais populares por tipo
        $modelsByType = [
            'scooter' => ['PCX 150', 'NMAX 160', 'Burgman 125', 'NEO 125', 'Elite 125'],
            'trail' => ['XRE 300', 'Lander 250', 'Bros 160', 'XTZ 250', 'Crosser 150', 'F 850 GS'],
            'esportiva' => ['Ninja 400', 'CB 500F', 'MT-03', 'YZF-R3', 'GSX-R750'],
            'custom' => ['Intruder 125', 'Shadow 750', 'Vulcan 650', 'Sportster 883'],
            'street' => ['CG 160', 'Factor 150', 'Fazer 250', 'YBR 150', 'CB 300R', 'Titan 160'],
        ];

        foreach ($motoTypes as $type) {
            if (isset($modelsByType[$type])) {
                foreach ($modelsByType[$type] as $model) {
                    $models[] = [
                        'value' => $model,
                        'inferred_from' => $type,
                        'confidence' => $isUniversal ? 'medium' : 'high',
                    ];
                }
            }
        }

        return $models;
    }

    /**
     * Minera modelos usando KeywordMinerService
     * Busca marcas e modelos da API de atributos do ML
     */
    private function mineModelsFromKeywordAPI(string $title, string $categoryId): array
    {
        $models = [];
        $titleLower = mb_strtolower($title);

        try {
            $miner = $this->getKeywordMiner();

            // Buscar keywords de atributos da categoria
            $attrKeywords = $miner->getAttributeKeywords($categoryId);

            // Filtrar valores relevantes para MODEL (marcas, modelos)
            foreach ($attrKeywords as $kw) {
                // Ignorar nomes de atributos, queremos apenas valores
                if (($kw['type'] ?? '') !== 'attribute_value') {
                    continue;
                }

                // Focar em atributos relacionados a modelo/marca/compatibilidade
                $attrId = $kw['attribute_id'] ?? '';
                if (!in_array($attrId, ['BRAND', 'MODEL', 'COMPATIBLE_VEHICLE_MODELS', 'VEHICLE_MODEL', 'MOTO_MODEL'])) {
                    continue;
                }

                $value = $kw['keyword'] ?? '';
                if (empty($value) || mb_strlen($value) < 2) {
                    continue;
                }

                // Verificar se o valor está relacionado ao título
                $valueLower = mb_strtolower($value);
                $score = 15; // Score base

                // Se aparecer no título, maior score
                if (str_contains($titleLower, $valueLower)) {
                    $score += 25;
                }

                // Se for um modelo de moto conhecido, maior score
                if (preg_match('/\b(cg|titan|fan|factor|fazer|xre|bros|lander|pcx|nmax|cb|xtz|ybr|intruder)\b/i', $value)) {
                    $score += 10;
                }

                $models[] = [
                    'value' => $value,
                    'score' => $score,
                    'attribute_id' => $attrId,
                    'source' => 'ml_api_attributes',
                ];
            }

            // Também usar domain discovery para encontrar contexto
            $productWords = array_slice(explode(' ', $title), 0, 3);
            $searchTerm = implode(' ', $productWords);

            $domains = $miner->getDomainDiscovery($searchTerm);
            foreach ($domains as $domain) {
                $domainKw = $domain['keyword'] ?? '';
                if (empty($domainKw)) continue;

                // Extrair possíveis modelos do nome do domínio
                if (preg_match('/para\s+(\w+\s+\d+|\w+)\s*$/i', $domainKw, $m)) {
                    $models[] = [
                        'value' => trim($m[1]),
                        'score' => 12,
                        'source' => 'domain_discovery',
                    ];
                }
            }
        } catch (\Exception $e) {
            // Silently fail - a estratégia é opcional
            log_warning('Erro no KeywordMiner da ficha técnica', [
                'error' => $e->getMessage(),
            ]);
        }

        return $models;
    }

    /**
     * Score de relevância posicional (0-100), NÃO é volume de busca.
     *
     * Correção de metodologia: a versão anterior deste método ("estimateVolumeFromPosition")
     * fabricava um número absoluto (base 10000, decaindo 10% por posição) e o
     * gravava como "search_volume" — dava a impressão de medição real de busca,
     * quando na verdade é só a posição relativa em uma lista (autocomplete/trends).
     * Autocomplete e trends não expõem contagem de busca; isso é apenas um
     * sinal derivado de relevância posicional, nunca deve ser lido como volume.
     */
    private function estimatePositionRelevanceScore(int $position): int
    {
        $decayFactor = 0.9;
        return (int)round(100 * pow($decayFactor, $position));
    }

    /**
     * Retorna nível de recomendação baseado no score
     */
    private function getRecommendationLevel(int $score): string
    {
        if ($score >= 70) return 'highly_recommended';
        if ($score >= 50) return 'recommended';
        if ($score >= 30) return 'consider';
        return 'low_confidence';
    }

    /**
     * Mapeamento de aliases: atributos genéricos extraídos → IDs específicos do ML
     */
    private const ATTRIBUTE_ALIASES = [
        // Cores
        'COLOR' => ['MAIN_COLOR', 'PRIMARY_COLOR', 'SECONDARY_COLOR', 'REFLECTOR_COLOR', 'COLOR', 'ITEM_COLOR', 'EXTERNAL_COLOR'],
        'MAIN_COLOR' => ['COLOR', 'PRIMARY_COLOR', 'ITEM_COLOR'],

        // Capacidade
        'CAPACITY' => ['CAPACITY_LITERS', 'VOLUME', 'MAX_CAPACITY', 'CAPACITY_L', 'STORAGE_CAPACITY', 'VOLUME_CAPACITY'],
        'CAPACITY_LITERS' => ['CAPACITY', 'VOLUME', 'MAX_CAPACITY', 'VOLUME_CAPACITY'],

        // Tamanho
        'SIZE' => ['HELMET_SIZE', 'HEAD_SIZE', 'CLOTHING_SIZE', 'SHOE_SIZE', 'PRODUCT_SIZE'],
        'HELMET_SIZE' => ['SIZE', 'HEAD_SIZE'],

        // Veículos compatíveis - IMPORTANTE para peças de moto
        'COMPATIBLE_VEHICLE_MODELS' => ['VEHICLE_MODEL', 'MODEL', 'COMPATIBLE_MODELS', 'VEHICLE_MODELS', 'MOTO_MODEL'],
        'COMPATIBLE_VEHICLE_BRANDS' => ['VEHICLE_BRAND', 'BRAND', 'COMPATIBLE_BRANDS', 'VEHICLE_BRANDS', 'MOTO_BRAND'],
        'MODEL' => ['COMPATIBLE_VEHICLE_MODELS', 'VEHICLE_MODEL', 'MOTO_MODEL'],
        'BRAND' => ['COMPATIBLE_VEHICLE_BRANDS', 'VEHICLE_BRAND', 'MOTO_BRAND'],

        // Material
        'MATERIAL' => ['MAIN_MATERIAL', 'BODY_MATERIAL', 'COMPOSITION', 'MATERIAL_TYPE'],

        // Acabamento
        'FINISH' => ['SURFACE_FINISH', 'COLOR_TYPE', 'FINISH_TYPE'],

        // Compatibilidade
        'COMPATIBILITY' => ['FIT_TYPE', 'APPLICATION', 'UNIVERSAL_FIT', 'COMPATIBLE_VEHICLES', 'IS_UNIVERSAL'],

        // Ano
        'YEAR' => ['MODEL_YEAR', 'RELEASE_YEAR', 'FABRICATION_YEAR', 'VEHICLE_YEAR'],

        // Peso
        'WEIGHT' => ['PRODUCT_WEIGHT', 'NET_WEIGHT', 'GROSS_WEIGHT', 'ITEM_WEIGHT'],

        // Dimensões (para dados de embalagem se disponíveis no título)
        'LENGTH' => ['PACKAGE_LENGTH', 'PRODUCT_LENGTH', 'TOTAL_LENGTH'],
        'WIDTH' => ['PACKAGE_WIDTH', 'PRODUCT_WIDTH', 'TOTAL_WIDTH'],
        'HEIGHT' => ['PACKAGE_HEIGHT', 'PRODUCT_HEIGHT', 'TOTAL_HEIGHT'],
    ];

    /**
     * Encontra um gap que corresponde ao atributo extraído via aliases
     */
    private function findGapByAlias(string $extractedAttributeId, array $gaps): ?array
    {
        // Obter possíveis aliases para o atributo extraído
        $aliases = self::ATTRIBUTE_ALIASES[$extractedAttributeId] ?? [];

        // Também verificar se o atributo extraído é um alias de outro
        foreach (self::ATTRIBUTE_ALIASES as $canonical => $aliasGroup) {
            if (in_array($extractedAttributeId, $aliasGroup)) {
                $aliases = array_merge($aliases, [$canonical], $aliasGroup);
            }
        }

        $aliases = array_unique($aliases);

        foreach ($gaps as $gap) {
            if (in_array($gap['id'], $aliases)) {
                return $gap;
            }
        }

        return null;
    }

    /**
     * Encontra um gap cujo allowed_values contém o valor extraído
     */
    private function findGapByValue(string $value, array $gaps): ?array
    {
        $normalizedValue = mb_strtolower(trim($value));

        foreach ($gaps as $gap) {
            if (empty($gap['allowed_values'])) {
                continue;
            }

            foreach ($gap['allowed_values'] as $allowed) {
                $allowedName = mb_strtolower($allowed['name'] ?? '');
                $allowedId = mb_strtolower($allowed['id'] ?? '');

                if ($normalizedValue === $allowedName || $normalizedValue === $allowedId) {
                    return $gap;
                }

                // Verificar se é uma correspondência parcial (ex: "Preto" em "Preto Fosco")
                if (
                    mb_strlen($normalizedValue) >= 4 &&
                    (str_contains($allowedName, $normalizedValue) || str_contains($normalizedValue, $allowedName))
                ) {
                    return $gap;
                }
            }
        }

        return null;
    }

    /**
     * Normaliza um nome de atributo para comparação
     */
    private function normalizeAttributeName(string $name): string
    {
        // Remove acentos, converte para minúsculas, substitui espaços por underscores
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/\s+/', '_', $normalized);
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        return $normalized;
    }

    /**
     * Normaliza um valor para corresponder a um dos allowed_values
     * Retorna o ID ou nome correto do valor, ou null se não encontrar
     */
    private function normalizeToAllowedValue(string $value, array $allowedValues): ?string
    {
        $normalizedValue = mb_strtolower(trim($value));

        foreach ($allowedValues as $allowed) {
            $allowedName = mb_strtolower($allowed['name'] ?? '');
            $allowedId = $allowed['id'] ?? null;

            // Match exato
            if ($normalizedValue === $allowedName) {
                return $allowedId ?? $allowed['name'];
            }

            // Match parcial
            if (mb_strlen($normalizedValue) >= 3) {
                if (str_contains($allowedName, $normalizedValue) || str_contains($normalizedValue, $allowedName)) {
                    return $allowedId ?? $allowed['name'];
                }
            }
        }

        return null;
    }

    /**
     * Obtém o nome do atributo a partir da lista de atributos da categoria
     */
    private function getAttributeName(array $categoryAttributes, string $attributeId): ?string
    {
        foreach ($categoryAttributes as $attr) {
            if (($attr['id'] ?? '') === $attributeId) {
                return $attr['name'] ?? null;
            }
        }
        return null;
    }

    /**
     * Salva decisões de aprovação/rejeição de sugestões
     *
     * @param string $itemId
     * @param array $decisions Array de decisões [['attribute_id' => '...', 'status' => 'approved|rejected', 'value' => ...], ...]
     * @param int|null $userId ID do usuário que fez a decisão. NULL = sistema (auto-optimize)
     * @return array
     */
    public function saveDecisions(string $itemId, array $decisions, ?int $userId): array
    {
        // 1. Validar se o item existe e pegar categoria para validação
        $row = $this->getLocalItemRow($itemId);
        $categoryId = null;
        $categoryAttributes = [];

        if ($row) {
            $itemData = $this->decodeItemData($row);
            $categoryId = $row['category_id'] ?: ($itemData['category_id'] ?? null);
            if ($categoryId) {
                $categoryAttributes = $this->attributeKiller->getCategoryAttributes((string)$categoryId);
            }
        }

        $updated = 0;
        $isSystemAction = ($userId === null || $userId === 0);

        foreach ($decisions as $decision) {
            $attributeId = $decision['attribute_id'] ?? null;
            $status = $decision['status'] ?? null;
            $value = $decision['value'] ?? null;

            if (!is_string($attributeId) || $attributeId === '') {
                continue;
            }
            if (!in_array($status, ['approved', 'rejected'], true)) {
                continue;
            }

            // Validação de valor (apenas se estiver aprovando e tiver valor)
            if ($status === 'approved' && $value !== null && !empty($categoryAttributes)) {
                $isValid = $this->validateAttributeValue($attributeId, $value, $categoryAttributes);
                if (!$isValid) {
                    continue; // Pula valores inválidos
                }
            }

            $sql = "UPDATE tech_sheet_suggestions
                    SET status = :status,
                        decided_by_user_id = :user_id,
                        decided_at = NOW(),
                        suggested_value = COALESCE(:value, suggested_value),
                        meta = JSON_SET(COALESCE(meta, '{}'), '$.auto_approved', :auto_flag)
                    WHERE account_id = :account_id
                      AND item_id = :item_id
                      AND attribute_id = :attribute_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':status' => $status,
                ':user_id' => $isSystemAction ? null : $userId,
                ':value' => $value,
                ':auto_flag' => $isSystemAction ? 'true' : 'false',
                ':account_id' => $this->accountId,
                ':item_id' => $itemId,
                ':attribute_id' => $attributeId,
            ]);

            if ($stmt->rowCount() > 0 && $status === 'rejected') {
                $metaStmt = $this->db->prepare(
                    "SELECT meta FROM tech_sheet_suggestions
                     WHERE account_id = :account_id AND item_id = :item_id AND attribute_id = :attribute_id
                     ORDER BY id DESC LIMIT 1"
                );
                $metaStmt->execute([
                    ':account_id' => $this->accountId,
                    ':item_id' => $itemId,
                    ':attribute_id' => $attributeId,
                ]);
                $metaRaw = $metaStmt->fetchColumn();
                $meta = is_string($metaRaw) ? (json_decode($metaRaw, true) ?: []) : [];
                try {
                    (new TechSheetGtinSuggestionService($this->accountId, $this))
                        ->releaseReservationForRejectedSuggestion($itemId, $attributeId, $meta);
                } catch (\Throwable $e) {
                    log_warning('TechSheet: falha ao liberar reserva EAN no reject', [
                        'item_id' => $itemId,
                        'attribute_id' => $attributeId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $updated += $stmt->rowCount();
        }

        return [
            'success' => true,
            'item_id' => $itemId,
            'updated' => $updated,
        ];
    }

    /**
     * Valida se o valor confere com as regras do atributo
     */
    private function validateAttributeValue(string $attributeId, string $value, array $categoryAttributes): bool
    {
        foreach ($categoryAttributes as $attr) {
            if (($attr['id'] ?? '') === $attributeId) {
                // Se tiver valores permitidos, o valor deve estar na lista (por nome ou ID)
                if (!empty($attr['values'])) {
                    $normalizedValue = mb_strtolower(trim($value));
                    foreach ($attr['values'] as $allowed) {
                        if (mb_strtolower($allowed['name'] ?? '') === $normalizedValue || ($allowed['id'] ?? '') === $value) {
                            return true;
                        }
                    }
                    return false; // Valor não permitido
                }

                // Validação de tipo básico (numérico)
                if (($attr['value_type'] ?? '') === 'number_unit' || ($attr['value_type'] ?? '') === 'number') {
                    // Remove unidades comuns para validar o número
                    $cleanValue = preg_replace('/[^\d.,-]/', '', $value);
                    if (!is_numeric(str_replace(',', '.', $cleanValue))) {
                        return false;
                    }
                }

                break;
            }
        }
        return true;
    }

    /**
     * Aprova automaticamente sugestões pendentes por confiança mínima.
     * Não aplica no Mercado Livre; apenas muda status para "approved".
     */
    public function approvePendingByConfidence(string $itemId, int $userId, int $minConfidence = 85): array
    {
        $itemId = (string)$itemId;
        if ($itemId === '') {
            return ['success' => false, 'error' => 'item_id inválido'];
        }

        if ($userId <= 0) {
            return ['success' => false, 'error' => 'user_id inválido'];
        }

        if ($minConfidence < 0) {
            $minConfidence = 0;
        }
        if ($minConfidence > 100) {
            $minConfidence = 100;
        }

        $sql = "UPDATE tech_sheet_suggestions
                SET status = 'approved',
                    decided_by_user_id = :user_id,
                    decided_at = NOW()
                WHERE account_id = :account_id
                  AND item_id = :item_id
                  AND status = 'pending'
                  AND confidence IS NOT NULL
                  AND confidence >= :min_confidence";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':account_id' => $this->accountId,
            ':item_id' => $itemId,
            ':min_confidence' => $minConfidence,
        ]);

        $approved = (int)$stmt->rowCount();

        return [
            'success' => true,
            'item_id' => $itemId,
            'approved' => $approved,
            'min_confidence' => $minConfidence,
        ];
    }

    /**
     * IDs de anúncios active com pelo menos uma sugestão pending ≥ minConfidence.
     *
     * @return array{item_ids: list<string>, count: int, suggestions: int}
     */
    public function listActiveItemIdsWithPendingMinConfidence(int $minConfidence = 85, int $limit = 200): array
    {
        if ($minConfidence < 0) {
            $minConfidence = 0;
        }
        if ($minConfidence > 100) {
            $minConfidence = 100;
        }
        $limit = max(1, min(200, $limit));

        $sql = "SELECT t.item_id, COUNT(*) AS pending_eligible
                FROM tech_sheet_suggestions t
                INNER JOIN items i
                  ON i.account_id = t.account_id AND i.ml_item_id = t.item_id
                WHERE t.account_id = :account_id
                  AND i.status = 'active'
                  AND t.status = 'pending'
                  AND t.confidence IS NOT NULL
                  AND t.confidence >= :min_confidence
                GROUP BY t.item_id
                ORDER BY pending_eligible DESC, t.item_id ASC
                LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':min_confidence' => $minConfidence,
        ]);

        $itemIds = [];
        $suggestions = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (string)($row['item_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $itemIds[] = $id;
            $suggestions += (int)($row['pending_eligible'] ?? 0);
        }

        return [
            'item_ids' => $itemIds,
            'count' => count($itemIds),
            'suggestions' => $suggestions,
        ];
    }

    /**
     * IDs de anúncios active com pelo menos uma sugestão approved (pronta para aplicar no ML).
     *
     * @return array{item_ids: list<string>, count: int, suggestions: int}
     */
    public function listActiveItemIdsWithApprovedSuggestions(int $limit = 200): array
    {
        $limit = max(1, min(200, $limit));

        $sql = "SELECT t.item_id, COUNT(*) AS approved_count
                FROM tech_sheet_suggestions t
                INNER JOIN items i
                  ON i.account_id = t.account_id AND i.ml_item_id = t.item_id
                WHERE t.account_id = :account_id
                  AND i.status = 'active'
                  AND t.status = 'approved'
                GROUP BY t.item_id
                ORDER BY approved_count DESC, t.item_id ASC
                LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':account_id' => $this->accountId]);

        $itemIds = [];
        $suggestions = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (string)($row['item_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $itemIds[] = $id;
            $suggestions += (int)($row['approved_count'] ?? 0);
        }

        return [
            'item_ids' => $itemIds,
            'count' => count($itemIds),
            'suggestions' => $suggestions,
        ];
    }

    public function applyApproved(string $itemId, ?int $userId = null): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM tech_sheet_suggestions
             WHERE account_id = :account_id AND item_id = :item_id AND status = 'approved'"
        );
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':item_id' => $itemId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return ['success' => true, 'item_id' => $itemId, 'applied' => 0, 'message' => 'Nenhuma sugestão aprovada para aplicar'];
        }

        // 1. Obter atributos da categoria para resolver value_id
        $categoryAttributes = [];
        try {
            $itemData = $this->mlClient->get("/items/{$itemId}");
            $categoryId = $itemData['category_id'] ?? null;
            if ($categoryId) {
                $categoryAttributes = $this->attributeKiller->getCategoryAttributes((string)$categoryId);
            }
        } catch (\Exception $e) {
            // Continua sem validação avançada se falhar
        }

        // 2. Monta mapa com atributos atuais
        $currentMap = [];
        foreach (($itemData['attributes'] ?? []) as $attr) {
            if (!is_array($attr)) continue;
            $id = $attr['id'] ?? null;
            if (!is_string($id) || $id === '') continue;

            $entry = ['id' => $id];
            if (!empty($attr['value_id'])) {
                $entry['value_id'] = $attr['value_id'];
            }
            if (array_key_exists('value_name', $attr) && $attr['value_name'] !== null) {
                $entry['value_name'] = $attr['value_name'];
            }
            $currentMap[$id] = $entry;
        }

        // 3. Aplica sugestões aprovadas
        $appliedAttributes = [];
        foreach ($rows as $r) {
            $attrId = (string)($r['attribute_id'] ?? '');
            $value = (string)($r['suggested_value'] ?? '');
            if ($attrId === '' || $value === '') {
                continue;
            }

            $payloadAttr = [
                'id' => $attrId,
                'value_name' => $value,
            ];

            $meta = [];
            if (!empty($r['meta'])) {
                $decoded = is_string($r['meta']) ? json_decode($r['meta'], true) : $r['meta'];
                $meta = is_array($decoded) ? $decoded : [];
            }
            if (!empty($meta['reason_value_id']) && is_scalar($meta['reason_value_id'])) {
                $payloadAttr['value_id'] = (string)$meta['reason_value_id'];
            }
            if (empty($payloadAttr['value_id']) && !empty($meta['value_id']) && is_scalar($meta['value_id'])) {
                $payloadAttr['value_id'] = (string)$meta['value_id'];
            }

            // Tenta resolver value_id
            if (empty($payloadAttr['value_id']) && !empty($categoryAttributes)) {
                $resolvedId = $this->resolveValueId($attrId, $value, $categoryAttributes);
                if ($resolvedId) {
                    $payloadAttr['value_id'] = $resolvedId;
                }
            }

            // Doc ML: boolean exige value_id — não enviar só value_name
            $valueType = (string)($meta['value_type'] ?? '');
            if ($valueType === '' && !empty($categoryAttributes)) {
                foreach ($categoryAttributes as $ca) {
                    if (($ca['id'] ?? '') === $attrId) {
                        $valueType = (string)($ca['value_type'] ?? '');
                        break;
                    }
                }
            }
            if ($valueType === 'boolean' && empty($payloadAttr['value_id'])) {
                log_warning('TechSheet apply: boolean sem value_id — skip', [
                    'item_id' => $itemId,
                    'attribute_id' => $attrId,
                ]);
                continue;
            }

            $currentMap[$attrId] = $payloadAttr;
            $appliedAttributes[] = $payloadAttr;
        }

        if (!$appliedAttributes) {
            return ['success' => true, 'item_id' => $itemId, 'applied' => 0, 'message' => 'Nenhuma sugestão aprovada válida para aplicar'];
        }

        $payloadAttributes = array_values($currentMap ?: $appliedAttributes);

        // Governança: aplicar sugestão aprovada é escrita real na API ML — respeita
        // SAFE_MODE/FORBIDDEN_ACCOUNTS como os demais pontos de apply do Hidden SEO
        // (HiddenSeoSuggester::applyPending, CLI tech-sheet apply). Sem isso, a UI e o
        // auto-optimizer podiam escrever na conta 1335 (produção FACILYTY) sem passar
        // pelo mesmo guard. allowApply=true porque chegar aqui já significa que o
        // usuário aprovou a sugestão e pediu explicitamente para aplicar; a blacklist
        // de contas (FORBIDDEN_ACCOUNTS) continua bloqueada de forma incondicional.
        (new \App\Services\HiddenSeo\SafetyGuard())->assertCanApply($this->accountId, false, true);

        $mlResponse = $this->mlClient->put("/items/{$itemId}", ['attributes' => $payloadAttributes]);
        if (isset($mlResponse['error']) || ($mlResponse['success'] ?? true) === false) {
            return [
                'success' => false,
                'error' => $mlResponse['message'] ?? 'Falha ao aplicar no Mercado Livre',
                'ml' => $mlResponse,
            ];
        }

        // Marca como applied
        $upd = $this->db->prepare(
            "UPDATE tech_sheet_suggestions
             SET status = 'applied', applied_at = NOW()
             WHERE account_id = :account_id AND item_id = :item_id AND status = 'approved'"
        );
        $upd->execute([
            ':account_id' => $this->accountId,
            ':item_id' => $itemId,
        ]);

        try {
            (new TechSheetGtinSuggestionService($this->accountId, $this))
                ->confirmPoolAfterApply($itemId, $appliedAttributes);
        } catch (\Throwable $e) {
            log_warning('TechSheet: falha ao confirmar consumo EAN pós-apply', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
        }

        // Auditoria básica
        $this->writeAuditLog($userId, $itemId, [
            'attributes_applied' => $appliedAttributes,
            'attributes_payload_count' => count($payloadAttributes),
            'ml_response' => $mlResponse,
        ]);

        // Atualiza resumo
        try {
            $itemData = $this->mlClient->get("/items/{$itemId}");
            $row = $this->getLocalItemRow($itemId);
            if ($row) {
                $categoryId = $row['category_id'] ?: ($itemData['category_id'] ?? null);
                if ($categoryId) {
                    $gaps = $this->attributeKiller->analyzeGaps($itemId, (string)$categoryId, $itemData);
                    $this->getOrComputeSummary($row, $gaps, true);
                }
            }
        } catch (\Exception $e) {
            log_debug('TechSheet: post-apply summary refresh failed', ['item_id' => $itemId, 'error' => $e->getMessage()]);
        }

        return [
            'success' => true,
            'item_id' => $itemId,
            'applied' => count($appliedAttributes),
            'applied_count' => count($appliedAttributes),
            'ml' => $mlResponse,
        ];
    }

    /**
     * Resolve o ID do valor para um determinado atributo, se disponível
     */
    private function resolveValueId(string $attributeId, string $value, array $categoryAttributes): ?string
    {
        foreach ($categoryAttributes as $attr) {
            if (($attr['id'] ?? '') === $attributeId) {
                if (empty($attr['values'])) {
                    return null;
                }
                $normalizedValue = mb_strtolower(trim($value));
                foreach ($attr['values'] as $allowed) {
                    if (mb_strtolower($allowed['name'] ?? '') === $normalizedValue) {
                        return $allowed['id'] ?? null;
                    }
                }
                break;
            }
        }
        return null;
    }

    private function getSuggestionCountsForItems(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        if (!$itemIds) {
            return [];
        }

        // Monta placeholders para IN
        $placeholders = [];
        $params = [':account_id' => $this->accountId];
        foreach ($itemIds as $i => $id) {
            $ph = ':item' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $id;
        }

        try {
            $sql = "
                SELECT item_id,
                       SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                       SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                       SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
                       SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END) AS applied_count
                FROM tech_sheet_suggestions
                WHERE account_id = :account_id
                  AND item_id IN (" . implode(',', $placeholders) . ")
                GROUP BY item_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            foreach ($rows as $r) {
                $id = $r['item_id'] ?? null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                $out[$id] = [
                    'pending' => (int)($r['pending_count'] ?? 0),
                    'approved' => (int)($r['approved_count'] ?? 0),
                    'rejected' => (int)($r['rejected_count'] ?? 0),
                    'applied' => (int)($r['applied_count'] ?? 0),
                ];
            }

            return $out;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getLocalItemRow(string $itemId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ml_item_id, title, category_id, status, data, updated_at
             FROM items
             WHERE account_id = :account_id AND ml_item_id = :item_id
             LIMIT 1"
        );
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':item_id' => $itemId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function decodeItemData(array $row): array
    {
        $raw = json_decode($row['data'] ?? '{}', true);
        if (!is_array($raw)) {
            $raw = [];
        }

        // Garante campos essenciais
        $raw['id'] = $raw['id'] ?? ($row['ml_item_id'] ?? null);
        $raw['title'] = $raw['title'] ?? ($row['title'] ?? null);
        $raw['category_id'] = $raw['category_id'] ?? ($row['category_id'] ?? null);

        return $raw;
    }

    private function getOrComputeSummary(array $itemRow, ?array $gaps = null, bool $force = false): array
    {
        $itemId = $itemRow['ml_item_id'];

        $existing = $this->getSummaryRow($itemId);
        if (!$force && $existing && $this->isSummaryFresh($existing)) {
            return $existing;
        }

        $itemData = $this->decodeItemData($itemRow);
        $categoryId = $itemRow['category_id'] ?: ($itemData['category_id'] ?? null);

        if (!$categoryId) {
            return $existing ?: [];
        }

        if (!$gaps) {
            $gaps = $this->attributeKiller->analyzeGaps($itemId, (string)$categoryId, $itemData);
        }

        $required = $gaps['gaps']['required'] ?? [];
        $filter = $gaps['gaps']['filter'] ?? [];
        $hidden = $gaps['gaps']['hidden'] ?? [];
        $recommended = $gaps['gaps']['recommended'] ?? [];

        $summary = [
            'account_id' => $this->accountId,
            'item_id' => $itemId,
            'category_id' => $categoryId,
            'total_available' => (int)($gaps['total_available'] ?? 0),
            'filled' => (int)($gaps['filled'] ?? 0),
            'missing' => (int)($gaps['missing'] ?? 0),
            'completeness_percent' => (float)($gaps['completeness'] ?? 0),
            'missing_required' => is_array($required) ? count($required) : 0,
            'missing_filter' => is_array($filter) ? count($filter) : 0,
            'missing_hidden' => is_array($hidden) ? count($hidden) : 0,
            'missing_recommended' => is_array($recommended) ? count($recommended) : 0,
            'last_analyzed_at' => date('Y-m-d H:i:s'),
            'meta' => [
                'priority_actions' => $gaps['priority_actions'] ?? null,
                'computed_from' => 'local',
                'analyzer_version' => (int)($gaps['analyzer_version'] ?? AttributeKiller::ANALYZER_VERSION),
                'missing_model' => (bool)($gaps['missing_model'] ?? false),
                'missing_hidden_ids' => array_values(array_map(
                    static fn(array $g): string => (string)($g['id'] ?? ''),
                    is_array($hidden) ? $hidden : []
                )),
            ],
        ];

        $this->upsertSummary($summary);
        return $this->getSummaryRow($itemId) ?: $summary;
    }

    private function getSummaryRow(string $itemId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT account_id, item_id, category_id, total_available, filled, missing,
                    completeness_percent, missing_required, missing_filter, missing_hidden, missing_recommended,
                    last_analyzed_at, meta, updated_at
             FROM tech_sheet_item_summary
             WHERE account_id = :account_id AND item_id = :item_id
             LIMIT 1"
        );
        $stmt->execute([
            ':account_id' => $this->accountId,
            ':item_id' => $itemId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        if (!empty($row['meta'])) {
            $meta = json_decode($row['meta'], true);
            $row['meta'] = is_array($meta) ? $meta : null;
        }

        return $row;
    }

    private function isSummaryFresh(array $summaryRow): bool
    {
        $meta = $summaryRow['meta'] ?? [];
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($meta)) {
            $meta = [];
        }

        // Invalida cache após mudança na classificação de gaps (ex.: ignorar read_only)
        if ((int)($meta['analyzer_version'] ?? 0) < AttributeKiller::ANALYZER_VERSION) {
            return false;
        }

        $updatedAt = $summaryRow['updated_at'] ?? null;
        if (!$updatedAt) {
            return false;
        }

        $ts = strtotime((string)$updatedAt);
        if (!$ts) {
            return false;
        }

        return (time() - $ts) <= $this->summaryTtlSeconds;
    }

    private function upsertSummary(array $summary): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO tech_sheet_item_summary
                (account_id, item_id, category_id, total_available, filled, missing,
                 completeness_percent, missing_required, missing_filter, missing_hidden, missing_recommended,
                 last_analyzed_at, meta)
             VALUES
                (:account_id, :item_id, :category_id, :total_available, :filled, :missing,
                 :completeness_percent, :missing_required, :missing_filter, :missing_hidden, :missing_recommended,
                 :last_analyzed_at, :meta)
             ON DUPLICATE KEY UPDATE
                category_id = VALUES(category_id),
                total_available = VALUES(total_available),
                filled = VALUES(filled),
                missing = VALUES(missing),
                completeness_percent = VALUES(completeness_percent),
                missing_required = VALUES(missing_required),
                missing_filter = VALUES(missing_filter),
                missing_hidden = VALUES(missing_hidden),
                missing_recommended = VALUES(missing_recommended),
                last_analyzed_at = VALUES(last_analyzed_at),
                meta = VALUES(meta),
                updated_at = NOW()"
        );

        $stmt->execute([
            ':account_id' => $summary['account_id'],
            ':item_id' => $summary['item_id'],
            ':category_id' => $summary['category_id'],
            ':total_available' => $summary['total_available'],
            ':filled' => $summary['filled'],
            ':missing' => $summary['missing'],
            ':completeness_percent' => $summary['completeness_percent'],
            ':missing_required' => $summary['missing_required'],
            ':missing_filter' => $summary['missing_filter'],
            ':missing_hidden' => $summary['missing_hidden'],
            ':missing_recommended' => $summary['missing_recommended'],
            ':last_analyzed_at' => $summary['last_analyzed_at'],
            ':meta' => json_encode($summary['meta'] ?? null),
        ]);
    }

    private const TECH_SHEET_ALLOWED_SOURCES = [
        'title_extraction',
        'inference',
        'competitor',
        'ai',
        'default',
        'manual',
        'ean_pool',
        'empty_gtin_policy',
        'hidden_seo',
        'title',
        'benchmark',
    ];

    private function normalizeSuggestionSource(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return 'inference';
        }

        if (in_array($source, self::TECH_SHEET_ALLOWED_SOURCES, true)) {
            return $source;
        }

        $s = mb_strtolower($source);

        if (str_contains($s, 'title')) {
            return 'title_extraction';
        }
        if (str_contains($s, 'competitor')) {
            return 'competitor';
        }
        if (str_contains($s, 'ai')) {
            return 'ai';
        }
        if ($s === 'manual') {
            return 'manual';
        }

        return 'inference';
    }

    /**
     * Persiste sugestão (API pública para TechSheetGtinSuggestionService).
     */
    /**
     * Sugere GTIN (pool) ou EMPTY_GTIN_REASON para um item.
     *
     * @return array{success:bool,created?:bool,result?:array,error?:string}
     */
    public function suggestGtinForItem(string $itemId, bool $dryRun = false): array
    {
        $row = $this->getLocalItemRow($itemId);
        if (!$row) {
            return ['success' => false, 'error' => 'Item não encontrado no cache local.'];
        }

        $itemData = $this->decodeItemData($row);
        $categoryId = $row['category_id'] ?: ($itemData['category_id'] ?? null);
        if (!$categoryId) {
            return ['success' => false, 'error' => 'category_id ausente.'];
        }

        $categoryAttributes = $this->attributeKiller->getCategoryAttributes((string)$categoryId);
        $gtinService = new TechSheetGtinSuggestionService($this->accountId, $this);
        $result = $gtinService->suggestForItem(
            $itemId,
            $categoryAttributes,
            $itemData,
            (string)$categoryId,
            (string)($row['title'] ?? ($itemData['title'] ?? '')),
            $dryRun
        );

        $created = ($result['success'] ?? false)
            && empty($result['skipped'])
            && !empty($result['suggestion']);

        return [
            'success' => (bool)($result['success'] ?? false),
            'created' => $created,
            'result' => $result,
            'error' => $result['error'] ?? null,
        ];
    }

    public function persistSuggestion(array $s): bool
    {
        return $this->upsertSuggestion($s);
    }

    /**
     * Sugere atributos Hidden SEO (LINE/MPN/…) com evidência — sem inventar.
     *
     * @return array{success:bool,created?:int,result?:array,error?:string}
     */
    public function suggestHiddenForItem(string $itemId, bool $dryRun = false): array
    {
        $row = $this->getLocalItemRow($itemId);
        if (!$row) {
            return ['success' => false, 'error' => 'Item não encontrado no cache local.'];
        }

        $itemData = $this->decodeItemData($row);
        $categoryId = $row['category_id'] ?: ($itemData['category_id'] ?? null);
        if (!$categoryId) {
            return ['success' => false, 'error' => 'category_id ausente.'];
        }

        $categoryAttributes = $this->attributeKiller->getCategoryAttributes((string)$categoryId);
        $service = new TechSheetHiddenSuggestionService($this->accountId, $this, $this->attributeKiller);
        $result = $service->suggestForItem(
            $itemId,
            $categoryAttributes,
            $itemData,
            (string)$categoryId,
            (string)($row['title'] ?? ($itemData['title'] ?? '')),
            [],
            $dryRun
        );

        return [
            'success' => (bool)($result['success'] ?? false),
            'created' => (int)($result['created'] ?? 0),
            'result' => $result,
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * @return array{created:bool,result:array}
     */
    private function appendGtinSuggestion(
        string $itemId,
        string $categoryId,
        array $categoryAttributes,
        array $itemData,
        string $title
    ): array {
        try {
            $gtinService = new TechSheetGtinSuggestionService($this->accountId, $this);
            $result = $gtinService->suggestForItem($itemId, $categoryAttributes, $itemData, $categoryId, $title);
            $created = ($result['success'] ?? false)
                && empty($result['skipped'])
                && !empty($result['suggestion']);
            return ['created' => $created, 'result' => $result];
        } catch (\Throwable $e) {
            log_warning('TechSheet: falha ao sugerir GTIN', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return [
                'created' => false,
                'result' => ['success' => false, 'error' => $e->getMessage()],
            ];
        }
    }

    /**
     * @param list<string> $skipAttributeIds
     * @return array{created:int,result:array}
     */
    private function appendHiddenSuggestions(
        string $itemId,
        string $categoryId,
        array $categoryAttributes,
        array $itemData,
        string $title,
        array $skipAttributeIds
    ): array {
        try {
            $service = new TechSheetHiddenSuggestionService($this->accountId, $this, $this->attributeKiller);
            $result = $service->suggestForItem(
                $itemId,
                $categoryAttributes,
                $itemData,
                $categoryId,
                $title,
                $skipAttributeIds,
                false
            );
            return [
                'created' => (int)($result['created'] ?? 0),
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            log_warning('TechSheet: falha ao sugerir Hidden SEO', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return [
                'created' => 0,
                'result' => ['success' => false, 'error' => $e->getMessage()],
            ];
        }
    }

    private function upsertSuggestion(array $s): bool
    {
        $rawSource = (string)($s['source'] ?? '');
        $normalizedSource = $this->normalizeSuggestionSource($rawSource);

        $meta = $s['meta'] ?? null;
        if (!is_array($meta)) {
            $meta = $meta ? (array)$meta : [];
        }
        if ($normalizedSource !== $rawSource && $rawSource !== '') {
            $meta['source_raw'] = $rawSource;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO tech_sheet_suggestions
                (account_id, item_id, category_id, attribute_id, attribute_name,
                 suggested_value, source, confidence, status, meta)
             VALUES
                (:account_id, :item_id, :category_id, :attribute_id, :attribute_name,
                 :suggested_value, :source, :confidence, :status, :meta)
             ON DUPLICATE KEY UPDATE
                category_id = VALUES(category_id),
                attribute_name = VALUES(attribute_name),
                suggested_value = VALUES(suggested_value),
                source = VALUES(source),
                confidence = VALUES(confidence),
                status = VALUES(status),
                decided_by_user_id = NULL,
                decided_at = NULL,
                meta = VALUES(meta),
                updated_at = NOW()"
        );

        $stmt->execute([
            ':account_id' => $s['account_id'],
            ':item_id' => $s['item_id'],
            ':category_id' => $s['category_id'],
            ':attribute_id' => $s['attribute_id'],
            ':attribute_name' => $s['attribute_name'],
            ':suggested_value' => $s['suggested_value'],
            ':source' => $normalizedSource,
            ':confidence' => $s['confidence'],
            ':status' => $s['status'],
            ':meta' => json_encode($meta ?: null),
        ]);

        return true;
    }

    private function listSuggestionsForItem(string $itemId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, attribute_id, attribute_name, suggested_value, source, confidence, status, decided_at, applied_at, meta
                 FROM tech_sheet_suggestions
                 WHERE account_id = :account_id AND item_id = :item_id
                 ORDER BY status ASC, id DESC"
            );
            $stmt->execute([
                ':account_id' => $this->accountId,
                ':item_id' => $itemId,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                if (!empty($row['meta'])) {
                    $meta = json_decode($row['meta'], true);
                    $row['meta'] = is_array($meta) ? $meta : null;
                }
            }

            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function writeAuditLog(?int $userId, string $itemId, array $data): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO audit_logs (user_id, ml_account_id, action, ip_address, user_agent, data)
                 VALUES (:user_id, :ml_account_id, :action, :ip_address, :user_agent, :data)"
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':ml_account_id' => $this->accountId,
                ':action' => 'tech_sheet_apply',
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ':data' => json_encode(array_merge(['item_id' => $itemId], $data)),
            ]);
        } catch (\Exception $e) {
            // silent
        }
    }

    /**
     * Adiciona sugestões manualmente (de extração de título ou análise de concorrentes)
     *
     * @param string $itemId ID do item
     * @param array $suggestions Array de sugestões [{attribute_id, suggested_value, confidence, source}]
     * @return array Resultado da operação
     */
    public function addSuggestions(string $itemId, array $suggestions): array
    {
        try {
            // Buscar dados do item
            $itemRow = $this->getLocalItemRow($itemId);
            if (!$itemRow) {
                return ['success' => false, 'error' => 'Item não encontrado'];
            }

            $categoryId = $itemRow['category_id'] ?? '';
            $added = 0;
            $errors = [];

            foreach ($suggestions as $s) {
                if (empty($s['attribute_id']) || empty($s['suggested_value'])) {
                    $errors[] = "Sugestão inválida: faltando attribute_id ou suggested_value";
                    continue;
                }

                try {
                    $this->upsertSuggestion([
                        'account_id' => $this->accountId,
                        'item_id' => $itemId,
                        'category_id' => $categoryId,
                        'attribute_id' => $s['attribute_id'],
                        'attribute_name' => $s['attribute_name'] ?? $s['attribute_id'],
                        'suggested_value' => $s['suggested_value'],
                        'source' => $s['source'] ?? 'manual',
                        'confidence' => min(100, max(0, (int)($s['confidence'] ?? 70))),
                        'status' => 'pending',
                        'meta' => $s['meta'] ?? null,
                    ]);
                    $added++;
                } catch (\Exception $e) {
                    $errors[] = "Erro ao adicionar {$s['attribute_id']}: " . $e->getMessage();
                }
            }

            return [
                'success' => $added > 0,
                'added' => $added,
                'total' => count($suggestions),
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
