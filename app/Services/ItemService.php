<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\MercadoLivreClient;
use App\Helpers\MercadoLivreHelper;
use App\Database;
use PDO;

class ItemService
{
    private MercadoLivreClient $client;
    private ?\PDO $db;
    private ?int $accountId;

    /** @var list<string> */
    private const LIST_MULTI_GET_ATTRIBUTES = [
        'id',
        'title',
        'price',
        'status',
        'available_quantity',
        'sold_quantity',
        'permalink',
        'thumbnail',
        'listing_type_id',
        'category_id',
        'currency_id',
    ];

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->client = new MercadoLivreClient($accountId);
        try {
            $this->db = Database::getInstance();
        } catch (\Exception $e) {
            log_warning('ItemService: DB indisponível, operando em modo API-only', [
                'service' => 'ItemService',
                'error' => $e->getMessage(),
            ]);
            $this->db = null;
        }
    }

    /**
     * Lista anúncios do usuário autenticado
     */
    public function listItems(array $filters = []): array
    {
        $limit = max(1, min(50, (int)($filters['limit'] ?? 50)));
        $page = max(1, (int)($filters['page'] ?? 1));

        if (isset($filters['offset'])) {
            $offset = max(0, (int)$filters['offset']);
            $page = (int)floor($offset / $limit) + 1;
        } else {
            $offset = ($page - 1) * $limit;
        }

        // Opt-in explícito: widgets de dashboard (ex.: SEO Killer Top Performers)
        // devem ler o cache local sem 50x GET /items na ML.
        if (!empty($filters['source']) && $filters['source'] === 'local') {
            return $this->listItemsFromLocalCache($filters, $limit, $page, $offset, [
                'reason' => 'source_local',
            ]);
        }

        $sellerId = $this->getSellerIdFromAccount();
        if (!$sellerId) {
            // Como fallback, tenta /users/me (exige token). Se não houver token, continuará null.
            $sellerId = $this->client->getSellerId();
        }

        if (!$sellerId) {
            if ($this->shouldAllowLocalFallback($filters)) {
                return $this->listItemsFromLocalCache($filters, $limit, $page, $offset, [
                    'reason' => 'missing_seller_id'
                ]);
            }

            return [
                'success' => false,
                'error' => 'missing_seller_id',
                'message' => 'Conta Mercado Livre não vinculada (ml_user_id ausente). Conecte a conta para puxar anúncios reais.',
                'items' => [],
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
                'total' => 0,
                'has_more' => false,
            ];
        }

        $params = [];

        // Status do anúncio
        if (isset($filters['status'])) {
            $params['status'] = $filters['status']; // active, paused, closed
        }

        // Busca por texto
        if (isset($filters['q'])) {
            $params['q'] = $filters['q'];
        }

        if (isset($filters['search'])) {
            $params['q'] = $filters['search'];
        }

        // Categoria específica
        if (isset($filters['category'])) {
            $params['category'] = $filters['category'];
        }

        // Ordenação
        if (isset($filters['order'])) {
            $params['order'] = $filters['order']; // price_asc, price_desc, date_created_desc, etc
        }

        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $siteId = $this->getAccountSiteId() ?? ($_ENV['ML_SITE_ID'] ?? 'MLB');
        $hasToken = $this->client->getAccessToken() !== '';

        // low_stock/high_sales dependem do cache local; em modo real estrito retornamos erro claro.
        $hasCustomFilters = !empty($filters['low_stock']) || !empty($filters['high_sales']);
        if ($hasCustomFilters) {
            if (!$this->shouldAllowLocalFallback($filters)) {
                return [
                    'success' => false,
                    'error' => 'local_cache_required',
                    'message' => 'Filtros low_stock/high_sales exigem cache local. Ative allow_local_cache=true para usar dados sincronizados.',
                    'items' => [],
                    'page' => $page,
                    'pages' => 1,
                    'limit' => $limit,
                    'total' => 0,
                    'has_more' => false,
                ];
            }

            return $this->listItemsFromLocalCache($filters, $limit, $page, $offset, [
                'reason' => 'custom_filters_required'
            ]);
        }

        // 1) Preferir endpoint autenticado quando há token (permite filtros como status de forma mais fiel)
        if ($hasToken) {
            // Endpoint de usuário exige autenticação.
            $response = $this->client->get("/users/{$sellerId}/items/search", $params, false);

            if (isset($response['error'])) {
                if ($this->shouldAllowLocalFallback($filters)) {
                    return $this->listItemsFromLocalCache($filters, $limit, $page, $offset, $response);
                }

                // Se o endpoint autenticado falhou, ainda dá para listar itens reais via busca pública por seller_id.
                // Isso resolve cenários comuns: token inválido/expirado, refresh falhando, ou permissões insuficientes.
                $publicParams = [
                    'seller_id' => $sellerId,
                    'limit' => $limit,
                    'offset' => $offset,
                ];
                if (isset($params['q'])) {
                    $publicParams['q'] = $params['q'];
                }
                if (isset($params['category'])) {
                    $publicParams['category'] = $params['category'];
                }

                $statusRequested = $filters['status'] ?? null;
                $publicResponse = $this->client->get("/sites/{$siteId}/search", $publicParams, 60, true);

                if (!isset($publicResponse['error'])) {
                    $payload = $this->buildPublicSearchListPayload($publicResponse, $limit, $page, $offset);
                    $payload['source'] = 'ml_public';
                    $payload['fallback_from'] = 'ml_api';
                    $payload['warning'] = 'Endpoint autenticado falhou; usando busca pública por seller_id. Motivo: '
                        . $this->formatMlApiErrorMessage($response, 'Falha ao buscar anúncios na API do Mercado Livre');

                    if ($statusRequested) {
                        $payload['warning'] .= ' | Filtro "status" ignorado na busca pública.';
                    }

                    return $payload;
                }

                return [
                    'success' => false,
                    'error' => 'ml_api_error',
                    'message' => $this->formatMlApiErrorMessage($response, 'Falha ao buscar anúncios na API do Mercado Livre'),
                    'api_error' => $response,
                    'api_error_public' => $publicResponse,
                    'items' => [],
                    'page' => $page,
                    'pages' => 1,
                    'limit' => $limit,
                    'total' => 0,
                    'has_more' => false,
                ];
            }

            // Se retornou IDs, hidratar via multi-get oficial (GET /items?ids=, máx. 20).
            // Não chama getItem() — esse caminho também busca /description por ID.
            $items = [];
            $itemIds = [];
            if (isset($response['results']) && is_array($response['results'])) {
                foreach ($response['results'] as $itemId) {
                    if (!is_string($itemId) || $itemId === '') {
                        continue;
                    }
                    $itemIds[] = $itemId;
                }

                $items = $this->hydrateItemsForList($itemIds, $filters);
            }

            if (!$this->shouldSkipVisits($filters)) {
                $items = $this->attachVisits($items);
            }

            $total = $response['paging']['total'] ?? count($items);
            $pages = max(1, (int)ceil($total / $limit));

            $response['items'] = $items;
            $response['results'] = $itemIds;
            $response['page'] = $page;
            $response['pages'] = $pages;
            $response['limit'] = $limit;
            $response['total'] = $total;
            $response['has_more'] = ($offset + $limit) < $total;
            $response['success'] = true;
            $response['source'] = 'ml_api';

            return $response;
        }

        // 2) Sem token: usar endpoint público de busca por seller_id (dados reais, sem autenticação)
        $publicParams = [
            'seller_id' => $sellerId,
            'limit' => $limit,
            'offset' => $offset,
        ];

        if (isset($params['q'])) {
            $publicParams['q'] = $params['q'];
        }
        if (isset($params['category'])) {
            $publicParams['category'] = $params['category'];
        }

        // Observação: filtro de status não é suportado consistentemente em busca pública.
        $statusRequested = $filters['status'] ?? null;

        $response = $this->client->get("/sites/{$siteId}/search", $publicParams, 60, true);

        if (isset($response['error'])) {
            if ($this->shouldAllowLocalFallback($filters)) {
                return $this->listItemsFromLocalCache($filters, $limit, $page, $offset, $response);
            }

            return [
                'success' => false,
                'error' => 'ml_api_error',
                'message' => $this->formatMlApiErrorMessage($response, 'Falha ao buscar anúncios (endpoint público) na API do Mercado Livre'),
                'api_error' => $response,
                'items' => [],
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
                'total' => 0,
                'has_more' => false,
            ];
        }

        $payload = $this->buildPublicSearchListPayload($response, $limit, $page, $offset, $filters);
        $payload['source'] = 'ml_public';

        if ($statusRequested) {
            $payload['warning'] = 'Filtro "status" ignorado: busca pública por seller_id não suporta status de forma consistente sem autenticação.';
        }

        return $payload;
    }

    private function buildPublicSearchListPayload(array $response, int $limit, int $page, int $offset, array $filters = []): array
    {
        $items = [];
        $itemIds = [];
        foreach (($response['results'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $itemIds[] = $id;
            }
            $items[] = $this->formatItemForList($row);
        }

        // Apply custom filters (low_stock, high_sales)
        $items = $this->filterItemsByCustomCriteria($items, $filters);
        if (!$this->shouldSkipVisits($filters)) {
            $items = $this->attachVisits($items);
        }

        $total = $response['paging']['total'] ?? count($items);
        $pages = max(1, (int)ceil($total / $limit));

        $payload = $response;
        $payload['items'] = $items;
        $payload['results'] = $itemIds;
        $payload['page'] = $page;
        $payload['pages'] = $pages;
        $payload['limit'] = $limit;
        $payload['total'] = $total;
        $payload['has_more'] = ($offset + $limit) < $total;
        $payload['success'] = true;

        return $payload;
    }

    private function formatMlApiErrorMessage(array $error, string $prefix): string
    {
        $message = $prefix;

        $detail = $error['message'] ?? ($error['error'] ?? null);
        if (is_string($detail) && $detail !== '') {
            $message .= ': ' . $detail;
        }

        $status = $error['status'] ?? null;
        if (is_int($status) && $status > 0) {
            $message .= ' (HTTP ' . $status . ')';
        }

        $endpoint = $error['endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            $message .= ' [' . $endpoint . ']';
        }

        return $message;
    }

    private function shouldAllowLocalFallback(array $filters): bool
    {
        if (!empty($filters['allow_local_cache']) && filter_var($filters['allow_local_cache'], FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        if (!empty($filters['source']) && $filters['source'] === 'local') {
            return true;
        }

        $envAllow = $_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK'] ?? getenv('ML_ALLOW_LOCAL_CACHE_FALLBACK') ?? null;
        if (!filter_var($envAllow, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // Em produção/staging, fallback local só com opt-in explícito via query
        // ou flag dedicada de emergência.
        $appEnv = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production'));
        if (in_array($appEnv, ['production', 'prod', 'staging'], true)) {
            $prodAllow = $_ENV['ML_ALLOW_LOCAL_CACHE_FALLBACK_PRODUCTION']
                ?? getenv('ML_ALLOW_LOCAL_CACHE_FALLBACK_PRODUCTION')
                ?? null;

            return filter_var($prodAllow, FILTER_VALIDATE_BOOLEAN);
        }

        return true;
    }

    private function filterItemsByCustomCriteria(array $items, array $filters): array
    {
        if (!empty($filters['low_stock']) && $filters['low_stock'] === true) {
            $items = array_filter($items, function (array $item): bool {
                $stock = $item['available_quantity'] ?? 0;
                return $stock < 5 && $stock >= 0;
            });
        }

        if (!empty($filters['high_sales']) && $filters['high_sales'] === true) {
            $items = array_filter($items, function (array $item): bool {
                // sold_quantity can come from direct field or from data JSON
                $sold = $item['sold_quantity'] ??
                    ($item['data']['sold_quantity'] ??
                        ($item['metrics']['sold_quantity'] ?? 0));
                return $sold > 0;
            });
        }

        return array_values($items); // Re-index array
    }

    private function listItemsFromLocalCache(array $filters, int $limit, int $page, int $offset, array $context = []): array
    {
        if ($this->db === null) {
            return [
                'success' => false,
                'error' => 'db_unavailable',
                'message' => 'Banco de dados indisponível e cache local não acessível. Tente sincronizar quando o banco estiver online.',
                'items' => [],
                'page' => $page,
                'pages' => 1,
                'limit' => $limit,
                'total' => 0,
                'has_more' => false,
                'source' => 'none',
            ];
        }

        $where = [];
        $params = [];

        if ($this->accountId) {
            $where[] = 'account_id = :account_id';
            $params['account_id'] = $this->accountId;
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['category'])) {
            $where[] = 'category_id = :category';
            $params['category'] = $filters['category'];
        }

        $searchTerm = $filters['search'] ?? ($filters['q'] ?? null);
        if ($searchTerm) {
            $where[] = '(title LIKE :search OR JSON_UNQUOTE(JSON_EXTRACT(data, "$.seller_custom_field")) LIKE :search_sku)';
            $params['search'] = '%' . $searchTerm . '%';
            $params['search_sku'] = '%' . $searchTerm . '%';
        }

        if (!empty($filters['low_stock']) && $filters['low_stock'] === true) {
            $where[] = 'available_quantity < 5';
        }

        if (!empty($filters['high_sales']) && $filters['high_sales'] === true) {
            $where[] = 'sold_quantity > 0';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM ml_items {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $orderSql = $this->resolveLocalItemsOrder($filters['order'] ?? null);

        $selectSql = "SELECT id, ml_item_id, title, price, available_quantity, status, category_id, currency_id, data, updated_at "
            . "FROM ml_items {$whereSql} {$orderSql} LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($selectSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $rawData = json_decode($row['data'] ?? '[]', true);
            if (!is_array($rawData)) {
                $rawData = [];
            }

            $itemData = array_merge($rawData, [
                'id' => $row['ml_item_id'] ?: ($rawData['id'] ?? (string) $row['id']),
                'ml_item_id' => $row['ml_item_id'] ?? null,
                'title' => $row['title'],
                'price' => isset($row['price']) ? (float)$row['price'] : null,
                'available_quantity' => isset($row['available_quantity']) ? (int)$row['available_quantity'] : 0,
                'status' => $row['status'],
                'category_id' => $row['category_id'],
                'currency_id' => $row['currency_id'],
                'seller_custom_field' => $rawData['seller_custom_field'] ?? null,
                'sku' => $rawData['seller_custom_field'] ?? null,
            ]);

            if (!isset($itemData['pictures']) && isset($rawData['thumbnail'])) {
                $itemData['pictures'] = [['url' => $rawData['thumbnail']]];
            }

            $items[] = $this->formatItemForList($itemData);
        }

        if (!$this->shouldSkipVisits($filters)) {
            $items = $this->attachVisits($items);
        }

        $pages = $total > 0 ? (int)ceil($total / $limit) : 1;

        $payload = [
            'success' => true,
            'source' => 'local',
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];

        if (!empty($context)) {
            $payload['warning'] = $this->buildLocalFallbackWarning($context);
        }

        return $payload;
    }

    private function resolveLocalItemsOrder(?string $order): string
    {
        return match ($order) {
            'price_asc' => 'ORDER BY price ASC',
            'price_desc' => 'ORDER BY price DESC',
            'date_created_asc' => 'ORDER BY created_at ASC',
            'date_created_desc' => 'ORDER BY created_at DESC',
            default => 'ORDER BY updated_at DESC',
        };
    }

    private function buildLocalFallbackWarning(array $context): string
    {
        if (($context['reason'] ?? null) === 'missing_seller_id') {
            return 'Conta Mercado Livre não configurada; exibindo dados sincronizados localmente.';
        }

        $message = $context['message'] ?? $context['error'] ?? null;
        if ($message) {
            return 'Dados retornados do cache local (fallback da API Mercado Livre: ' . $message . ').';
        }

        return 'Dados retornados do cache local por indisponibilidade da API Mercado Livre.';
    }

    /**
     * Obtém detalhes de um anúncio específico
     */
    public function getItem(string $itemId, array $options = []): array
    {
        $item = [];
        try {
            $response = $this->client->get("/items/{$itemId}");
            if (isset($response['error'])) {
                $item = ['error' => $response['error'] ?? 'API Error'];
            } else {
                $item = $this->unwrapMlResponse($response);
            }
        } catch (\Exception $e) {
            // API failed/Item not found in ML
            $item = ['error' => $e->getMessage()];
        }

        // Try fallback to local DB if API failed or returned error
        if (isset($item['error'])) {
            if (!$this->shouldAllowLocalFallback($options) || $this->db === null) {
                // Real-only: sem fallback silencioso para cache local
                return $item;
            }

            try {
                $stmt = $this->db->prepare("SELECT * FROM ml_items WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $itemId]);
                $local = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($local) {
                    $data = json_decode($local['data'] ?? $local['raw_data'] ?? '{}', true);
                    // Usar permalink salvo ou construir URL real do ML
                    $permalink = $local['permalink'] ?? $data['permalink'] ?? null;
                    if (!$permalink) {
                        $permalink = MercadoLivreHelper::itemUrl($local['id']);
                    }
                    return array_merge($data, [
                        'id' => $local['id'],
                        'title' => $local['title'],
                        'price' => (float)$local['price'],
                        'available_quantity' => (int)$local['available_quantity'],
                        'status' => $local['status'],
                        'permalink' => $permalink,
                        'attributes' => $data['attributes'] ?? [],
                        'pictures' => $data['pictures'] ?? [],
                        '_source' => 'local_cache', // Indicar que dados vieram do cache
                    ]);
                }
                // If not in DB either, return the API error
                return $item;
            } catch (\Exception $e) {
                log_warning('ItemService: falha no fallback local para getItem', [
                    'item_id' => $itemId,
                    'error' => $e->getMessage(),
                ]);
                return $item;
            }
        }

        $skipDescription = array_key_exists('skip_description', $options)
            ? filter_var($options['skip_description'], FILTER_VALIDATE_BOOLEAN)
            : false;

        if (!isset($item['error']) && !$skipDescription) {
            try {
                $description = $this->client->get("/items/{$itemId}/description");
                if (is_array($description) && !isset($description['error'])) {
                    $item['description'] = $description['plain_text'] ?? $description['text'] ?? ($item['description'] ?? '');
                }
            } catch (\Exception $e) {
                // Description fetch is non-critical — item data is still valid without it
                log_warning('ItemService: falha ao buscar descrição do item', [
                    'item_id' => $itemId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Merge com dados locais (Custo, Taxa, Health) se API retornou ok e DB disponível
        if ($this->db !== null) {
            try {
                // Buscar dados de custo/taxa na tabela items (que possui essas colunas)
                $stmt = $this->db->prepare("SELECT cost_price, tax_rate FROM items WHERE ml_item_id = :ml_item_id AND account_id = :account_id LIMIT 1");
                $stmt->execute([':ml_item_id' => $itemId, ':account_id' => $this->accountId]);
                $costData = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($costData) {
                    $item['cost_price'] = $costData['cost_price'];
                    $item['tax_rate'] = $costData['tax_rate'];
                }

                // Buscar dados de health/description do JSON em ml_items
                $stmt2 = $this->db->prepare("SELECT data FROM ml_items WHERE ml_item_id = :ml_item_id AND account_id = :account_id LIMIT 1");
                $stmt2->execute([':ml_item_id' => $itemId, ':account_id' => $this->accountId]);
                $localData = $stmt2->fetch(PDO::FETCH_ASSOC);

                if ($localData) {
                    // Merge health se não vier na API (as vezes vem em metrics)
                    if (!isset($item['health'])) {
                        $jsonData = json_decode($localData['data'] ?? '{}', true);
                        $item['health'] = $jsonData['health'] ?? null;
                    }
                    if (!isset($item['description'])) {
                        $jsonData = $jsonData ?? json_decode($localData['data'] ?? '{}', true);
                        $item['description'] = $jsonData['description'] ?? $jsonData['plain_text'] ?? $jsonData['text'] ?? null;
                    }
                }
            } catch (\Exception $e) {
                log_warning('ItemService: falha ao enriquecer item com dados locais', [
                    'item_id' => $itemId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $item;
    }

    /**
     * Atualiza dados financeiros do item (Local apenas)
     */
    /**
     * Atualiza configurações de precificação do item
     */
    public function updateItemPricing(string $itemId, array $data): bool
    {
        try {
            $fields = [];
            $params = [];

            if (array_key_exists('pricing_strategy', $data)) {
                $fields[] = "pricing_strategy = ?";
                $params[] = $data['pricing_strategy'];
            }
            if (array_key_exists('min_price', $data)) {
                $fields[] = "min_price = ?";
                $params[] = $data['min_price'];
            }
            if (array_key_exists('max_price', $data)) {
                $fields[] = "max_price = ?";
                $params[] = $data['max_price'];
            }
            if (array_key_exists('auto_reprice', $data)) {
                $fields[] = "auto_reprice = ?";
                $params[] = $data['auto_reprice'];
            }
            if (array_key_exists('auto_negotiate', $data)) {
                $fields[] = "auto_negotiate = ?";
                $params[] = $data['auto_negotiate'];
            }

            if (empty($fields)) {
                return false;
            }

            // Append ID for WHERE clause
            $params[] = $itemId; // ml_item_id or internal id? Usually ml_id for consistency in routes

            // Check if item exists by ml_id first
            $check = $this->db->prepare("SELECT id FROM ml_items WHERE id = ?");
            $check->execute([$itemId]);
            if (!$check->fetch()) {
                // Try by internal id if passed
                $params[count($params) - 1] = $itemId;
                $where = "id = ?";
            } else {
                $where = "ml_item_id = ?";
            }

            // Re-bind correctly implies strict logic. Let's assume we pass what we have.
            // If the route passes ml_id (e.g. MTB...), we use that.

            $sql = "UPDATE items SET " . implode(', ', $fields) . " WHERE " . $where;
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            log_error('Erro ao atualizar precificação do item', [
                'item_id' => $itemId ?? null,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function updateItemCost(string $itemId, ?float $cost, ?float $tax): bool
    {
        try {
            if ($this->accountId) {
                $stmt = $this->db->prepare("
                    UPDATE items
                    SET cost_price = :cost, tax_rate = :tax, updated_at = NOW()
                    WHERE ml_item_id = :id AND account_id = :account_id
                ");
                $ok = $stmt->execute([
                    ':cost' => $cost,
                    ':tax' => $tax,
                    ':id' => $itemId,
                    ':account_id' => $this->accountId,
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE items
                    SET cost_price = :cost, tax_rate = :tax, updated_at = NOW()
                    WHERE ml_item_id = :id
                ");
                $ok = $stmt->execute([
                    ':cost' => $cost,
                    ':tax' => $tax,
                    ':id' => $itemId,
                ]);
            }

            // Espelha na fonte canônica do P&L (sku_custos) quando há conta + custo
            if ($ok && $this->accountId && $cost !== null && $cost >= 0) {
                try {
                    (new \App\Services\Financial\CogsService($this->db))
                        ->upsertUnitCost($this->accountId, $itemId, $cost, 'items.cost_price_sync');
                } catch (\Throwable $e) {
                    log_warning('ItemService: sync CMV→sku_custos falhou', [
                        'item_id' => $itemId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $ok;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Cria um novo anúncio
     */
    public function createItem(array $data): array
    {
        // Validar dados obrigatórios básicos
        $required = ['title', 'category_id', 'price', 'currency_id', 'available_quantity', 'buying_mode', 'listing_type_id', 'condition', 'description'];

        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return [
                    'error' => true,
                    'message' => "Campo obrigatório ausente: {$field}",
                ];
            }
        }

        // Validar atributos obrigatórios da categoria
        if (isset($data['category_id'])) {
            $categoryService = new CategoryService($this->accountId);
            $attributes = $data['attributes'] ?? [];

            // Converter formato se necessário
            $formattedAttributes = [];
            foreach ($attributes as $key => $value) {
                if (is_array($value)) {
                    $formattedAttributes[] = $value;
                } else {
                    $formattedAttributes[] = [
                        'id' => is_numeric($key) ? $value : $key,
                        'value' => is_numeric($key) ? null : $value,
                        'value_name' => is_numeric($key) ? null : $value,
                    ];
                }
            }

            $validation = $categoryService->validateRequiredAttributes($data['category_id'], $formattedAttributes);

            if (!$validation['valid']) {
                return [
                    'error' => true,
                    'message' => 'Erros de validação nos atributos obrigatórios',
                    'validation_errors' => $validation['errors'],
                ];
            }
        }

        // Preparar dados do anúncio
        $itemData = [
            'title' => $data['title'],
            'category_id' => $data['category_id'],
            'price' => (float)$data['price'],
            'currency_id' => $data['currency_id'] ?? 'BRL',
            'available_quantity' => (int)$data['available_quantity'],
            'buying_mode' => $data['buying_mode'] ?? 'buy_it_now',
            'listing_type_id' => $data['listing_type_id'],
            'condition' => $data['condition'],
            'description' => $data['description'],
        ];

        // Atributos específicos da categoria
        if (isset($data['attributes']) && is_array($data['attributes'])) {
            $itemData['attributes'] = $data['attributes'];
        }

        // Imagens
        if (isset($data['pictures']) && is_array($data['pictures'])) {
            $itemData['pictures'] = array_map(function (string $url): array {
                return ['source' => $url];
            }, $data['pictures']);
        }

        // Variações (para produtos com variações)
        if (isset($data['variations']) && is_array($data['variations'])) {
            $itemData['variations'] = $data['variations'];
        }

        // Produto do catálogo
        if (isset($data['catalog_product_id'])) {
            $itemData['catalog_product_id'] = $data['catalog_product_id'];
        }

        // Frete
        if (isset($data['shipping'])) {
            $itemData['shipping'] = $data['shipping'];
        } else {
            // Frete grátis padrão
            $itemData['shipping'] = [
                'mode' => 'me2',
                'free_shipping' => true,
            ];
        }

        $response = $this->client->post('/items', $itemData);
        $payload = $this->unwrapMlResponse($response);

        // Registrar no banco de dados se criado com sucesso
        if (!isset($response['error']) && isset($payload['id'])) {
            $this->saveItemToDatabase($payload);
        }

        return $response;
    }

    /**
     * Atualiza um anúncio existente
     */
    public function updateItem(string $itemId, array $data): array
    {
        // Obter anúncio atual primeiro
        $currentItem = $this->getItem($itemId);

        if (isset($currentItem['error'])) {
            return $currentItem;
        }

        // Preparar dados para atualização (apenas campos permitidos)
        $updateData = [];

        $allowedFields = [
            'title',
            'price',
            'available_quantity',
            'description',
            'pictures',
            'attributes',
            'variations',
            'shipping',
            'seller_custom_field'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        // Formatar imagens se necessário
        if (isset($updateData['pictures']) && is_array($updateData['pictures'])) {
            $updateData['pictures'] = array_map(function (string|array $url): array {
                return is_array($url) ? $url : ['source' => $url];
            }, $updateData['pictures']);
        }

        $response = $this->client->put("/items/{$itemId}", $updateData);
        $payload = $this->unwrapMlResponse($response);

        // Atualizar no banco de dados se atualizado com sucesso
        if (!isset($response['error'])) {
            $this->saveItemToDatabase($payload);
        }

        return $response;
    }

    /**
     * Pausa um anúncio
     */
    public function pauseItem(string $itemId): array
    {
        return $this->client->put("/items/{$itemId}", ['status' => 'paused']);
    }

    /**
     * Reativa um anúncio pausado
     */
    public function activateItem(string $itemId): array
    {
        return $this->client->put("/items/{$itemId}", ['status' => 'active']);
    }

    /**
     * Fecha um anúncio
     */
    public function closeItem(string $itemId): array
    {
        return $this->client->put("/items/{$itemId}", ['status' => 'closed']);
    }

    /**
     * Deleta um anúncio (apenas se estiver fechado)
     */
    public function deleteItem(string $itemId): array
    {
        // Primeiro fechar o anúncio se necessário
        $item = $this->getItem($itemId);

        if (isset($item['error'])) {
            return $item;
        }

        if ($item['status'] !== 'closed') {
            $closeResponse = $this->closeItem($itemId);
            if (isset($closeResponse['error'])) {
                return [
                    'error' => true,
                    'message' => 'Não foi possível fechar o anúncio antes de deletar',
                ];
            }
        }

        return $this->client->delete("/items/{$itemId}");
    }

    /**
     * Atualiza preço de um anúncio
     */
    public function updatePrice(string $itemId, float $newPrice): array
    {
        return $this->updateItem($itemId, ['price' => $newPrice]);
    }

    /**
     * Atualiza estoque de um anúncio
     */
    public function updateStock(string $itemId, int $newQuantity): array
    {
        return $this->updateItem($itemId, ['available_quantity' => $newQuantity]);
    }

    /**
     * Lista anúncios por status
     */
    public function getItemsByStatus(string $status): array
    {
        return $this->listItems(['status' => $status]);
    }

    /**
     * Busca anúncios por categoria
     */
    public function getItemsByCategory(string $categoryId): array
    {
        $items = $this->listItems();

        if (isset($items['error'])) {
            return $items;
        }

        // Filtrar por categoria
        if (isset($items['items'])) {
            $items['items'] = array_filter($items['items'], function (array $item) use ($categoryId): bool {
                return isset($item['category_id']) && $item['category_id'] === $categoryId;
            });
            $items['items'] = array_values($items['items']); // Reindexar
        }

        return $items;
    }

    /**
     * Obtém estatísticas dos anúncios
     */
    public function getItemsStats(): array
    {
        $sellerId = $this->getSellerIdFromAccount();
        if (!$sellerId) {
            $sellerId = $this->client->getSellerId();
        }

        if (!$sellerId) {
            if ($this->shouldAllowLocalFallback([])) {
                return $this->getLocalItemsStats();
            }

            return [
                'success' => false,
                'error' => 'missing_seller_id',
                'message' => 'Conta Mercado Livre não vinculada (ml_user_id ausente). Conecte a conta para puxar estatísticas reais.',
            ];
        }

        $allItems = $this->listItems(['limit' => 1000]);
        if (isset($allItems['error'])) {
            if ($this->shouldAllowLocalFallback([])) {
                return $this->getLocalItemsStats();
            }
            return $allItems;
        }

        $stats = [
            'total' => 0,
            'active' => 0,
            'paused' => 0,
            'closed' => 0,
            'catalog' => 0,
            'common' => 0,
            'total_revenue' => 0,
            'total_quantity' => 0,
            'total_views' => 0,
            'total_sold' => 0,
            'low_stock' => 0,
        ];

        if (isset($allItems['items'])) {
            foreach ($allItems['items'] as $item) {
                $stats['total']++;

                // Status
                $status = $item['status'] ?? 'unknown';
                if (isset($stats[$status])) {
                    $stats[$status]++;
                }

                // Tipo (catálogo vs comum)
                if (!empty($item['catalog_product_id'])) {
                    $stats['catalog']++;
                } else {
                    $stats['common']++;
                }

                // Receita e quantidade
                if (isset($item['price'])) {
                    $quantity = $item['available_quantity'] ?? 0;
                    $stats['total_revenue'] += $item['price'] * $quantity;
                    $stats['total_quantity'] += $quantity;
                }

                $stats['total_views'] += (int)($item['visits'] ?? $item['metrics']['visits'] ?? 0);
                $stats['total_sold'] += (int)($item['sold_quantity'] ?? $item['metrics']['sold_quantity'] ?? 0);

                if (($item['available_quantity'] ?? 0) < 5) {
                    $stats['low_stock']++;
                }
            }
        }

        return array_merge(['success' => true], $stats);
    }

    private function getLocalItemsStats(): array
    {
        if ($this->db === null) {
            return [
                'success' => false,
                'error' => 'db_unavailable',
                'message' => 'Banco de dados indisponível para estatísticas locais.',
            ];
        }

        $where = [];
        $params = [];

        if ($this->accountId) {
            $where[] = 'account_id = :account_id';
            $params['account_id'] = $this->accountId;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare("SELECT status, COUNT(*) AS count FROM ml_items {$whereSql} GROUP BY status");
        $stmt->execute($params);
        $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN catalog_product_id IS NOT NULL THEN 1 ELSE 0 END) AS catalog,
                SUM(CASE WHEN catalog_product_id IS NULL THEN 1 ELSE 0 END) AS common,
                SUM(price * available_quantity) AS total_revenue,
                SUM(available_quantity) AS total_quantity,
                SUM(CASE WHEN available_quantity < 5 THEN 1 ELSE 0 END) AS low_stock
            FROM ml_items {$whereSql}
        ");
        $stmt->execute($params);
        $aggregates = $stmt->fetch(PDO::FETCH_ASSOC);

        $stats = [
            'success' => true,
            'source' => 'local',
            'total' => (int)($aggregates['total'] ?? 0),
            'active' => (int)($statusCounts['active'] ?? 0),
            'paused' => (int)($statusCounts['paused'] ?? 0),
            'closed' => (int)($statusCounts['closed'] ?? 0),
            'catalog' => (int)($aggregates['catalog'] ?? 0),
            'common' => (int)($aggregates['common'] ?? 0),
            'total_revenue' => (float)($aggregates['total_revenue'] ?? 0),
            'total_quantity' => (int)($aggregates['total_quantity'] ?? 0),
            'total_views' => 0,
            'total_sold' => 0,
            'low_stock' => (int)($aggregates['low_stock'] ?? 0),
        ];

        return $stats;
    }

    /**
     * Sincroniza um único item do ML para o banco de dados
     */
    public function syncItem(string $itemId): array
    {
        // 1. Fetch from API (bypass cache)
        $response = $this->client->get("/items/{$itemId}");
        $payload = $this->unwrapMlResponse($response);

        if (isset($response['error'])) {
            return ['error' => $response['message'] ?? 'API Error'];
        }

        // 2. Save to DB
        $this->saveItemToDatabase($payload);

        return $payload;
    }

    /**
     * Sincroniza todos os anúncios da conta com o banco de dados local
     *
     * @param int $limit Máximo de itens por página
     * @return array Resultado da sincronização
     */
    public function syncItems(int $limit = 50): array
    {
        $synced = 0;
        $errors = 0;
        $allItemIds = [];

        // Obter ml_user_id da conta (necessário para CLI sem sessão)
        $mlUserId = $this->getMlUserId();
        if (!$mlUserId) {
            return [
                'success' => false,
                'error' => 'Conta não encontrada ou sem ml_user_id',
                'synced' => 0,
                'errors' => 1,
            ];
        }

        // Buscar todos os IDs de itens do usuário.
        // Preferir scan+scroll_id (completo); offset trunca ~1000 na API ML.
        $scrollId = null;
        $usedScan = false;
        $maxPages = 500;

        for ($page = 0; $page < $maxPages; $page++) {
            $params = [
                'limit' => min($limit, 50),
            ];

            if ($page === 0) {
                $params['search_type'] = 'scan';
            } elseif (is_string($scrollId) && $scrollId !== '') {
                $params['search_type'] = 'scan';
                $params['scroll_id'] = $scrollId;
                $usedScan = true;
            } else {
                // Scan encerrado ou indisponível → fallback offset
                break;
            }

            $response = $this->client->get("/users/{$mlUserId}/items/search", $params);

            if (isset($response['error'])) {
                if ($page === 0) {
                    // Scan falhou na 1ª página: fallback offset completo abaixo
                    break;
                }

                return [
                    'success' => false,
                    'error' => $response['message'] ?? 'Erro ao buscar itens (scan)',
                    'synced' => $synced,
                    'errors' => $errors + 1,
                ];
            }

            $itemIds = $response['results'] ?? [];
            if (!is_array($itemIds)) {
                $itemIds = [];
            }
            $allItemIds = array_merge($allItemIds, $itemIds);
            $usedScan = true;

            $scrollId = $response['scroll_id'] ?? null;
            if (!is_string($scrollId) || $scrollId === '' || $itemIds === []) {
                break;
            }

            usleep(100_000);
        }

        if (!$usedScan) {
            $offset = 0;
            $maxOffset = 1000;
            do {
                $response = $this->client->get("/users/{$mlUserId}/items/search", [
                    'limit' => min($limit, 50),
                    'offset' => $offset,
                ]);

                if (isset($response['error'])) {
                    return [
                        'success' => false,
                        'error' => $response['message'] ?? 'Erro ao buscar itens',
                        'synced' => $synced,
                        'errors' => $errors + 1,
                    ];
                }

                $itemIds = $response['results'] ?? [];
                if (!is_array($itemIds) || $itemIds === []) {
                    break;
                }
                $allItemIds = array_merge($allItemIds, $itemIds);

                $offset += count($itemIds);
                $total = (int)($response['paging']['total'] ?? 0);

                if ($total > $maxOffset && $offset >= $maxOffset) {
                    break;
                }
            } while ($offset < $total && count($itemIds) > 0);
        }

        $allItemIds = array_values(array_unique(array_map('strval', $allItemIds)));

        // Buscar detalhes e salvar cada item
        // Processar em lotes de 20 para evitar sobrecarga
        $chunks = array_chunk($allItemIds, 20);

        foreach ($chunks as $chunk) {
            // Usar multiget para buscar múltiplos itens de uma vez
            $ids = implode(',', $chunk);
            $items = $this->client->get("/items?ids={$ids}");

            if (!is_array($items)) {
                $errors += count($chunk);
                continue;
            }

            foreach ($items as $itemData) {
                if (isset($itemData['body']) && !isset($itemData['body']['error'])) {
                    try {
                        $this->saveItemToDatabase($itemData['body']);
                        $synced++;
                    } catch (\Exception $e) {
                        log_error('Erro ao sincronizar item', [
                            'item_id' => $itemData['body']['id'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                        $errors++;
                    }
                } else {
                    $errors++;
                }
            }

            // Pequena pausa para não sobrecarregar a API
            usleep(100000); // 100ms
        }

        // Closed/inactive saem do search; refresca itens locais "vivos" ausentes do catálogo remoto.
        $staleSynced = $this->refreshItemsMissingFromRemoteCatalog($allItemIds);
        $synced += $staleSynced;

        // Badge do dashboard depende de ml_accounts.last_synced_at.
        $this->touchAccountLastSyncedAt();

        return [
            'success' => true,
            'synced' => $synced,
            'errors' => $errors,
            'total_found' => count($allItemIds),
            'stale_refreshed' => $staleSynced,
        ];
    }


    /**
     * Atualiza itens locais ainda "vivos" ausentes do catálogo retornado pelo search/scan.
     * Apenas GET na API ML (sem mutação remota).
     *
     * @param list<string> $remoteIds
     */

    private function touchAccountLastSyncedAt(): void
    {
        if ($this->db === null || $this->accountId === null || $this->accountId <= 0) {
            return;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE ml_accounts SET last_synced_at = NOW(), updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([':id' => $this->accountId]);
        } catch (\Throwable $e) {
            log_warning('ItemService: falha ao atualizar last_synced_at', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function refreshItemsMissingFromRemoteCatalog(array $remoteIds): int
    {
        if ($this->db === null || $this->accountId === null || $this->accountId <= 0) {
            return 0;
        }

        $remoteSet = [];
        foreach ($remoteIds as $id) {
            $id = (string) $id;
            if ($id !== '') {
                $remoteSet[$id] = true;
            }
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT ml_item_id FROM items
                 WHERE account_id = :account_id
                   AND status IN ('active', 'paused', 'under_review', 'inactive')"
            );
            $stmt->execute([':account_id' => $this->accountId]);
            $localIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            log_warning('ItemService: falha ao listar itens locais para reconcile stale', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $missing = [];
        foreach ($localIds as $id) {
            $id = (string) $id;
            if ($id !== '' && !isset($remoteSet[$id])) {
                $missing[] = $id;
            }
        }

        if ($missing === []) {
            return 0;
        }

        $maxPerRun = max(20, min(200, (int)($_ENV['ITEM_SYNC_STALE_REFRESH_LIMIT'] ?? 100)));
        $missing = array_slice($missing, 0, $maxPerRun);

        log_info('ItemService: refreshing stale local items missing from remote catalog', [
            'account_id' => $this->accountId,
            'missing' => count($missing),
        ]);

        $synced = 0;
        $errors = 0;
        foreach (array_chunk($missing, 20) as $chunk) {
            $ids = implode(',', $chunk);
            $items = $this->client->get("/items?ids={$ids}");
            if (!is_array($items)) {
                $errors += count($chunk);
                continue;
            }

            foreach ($items as $itemData) {
                if (isset($itemData['body']) && !isset($itemData['body']['error'])) {
                    try {
                        $this->saveItemToDatabase($itemData['body']);
                        $synced++;
                    } catch (\Exception $e) {
                        log_error('Erro ao sincronizar item stale', [
                            'item_id' => $itemData['body']['id'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                        $errors++;
                    }
                } else {
                    $errors++;
                }
            }
            usleep(100000);
        }

        if ($errors > 0) {
            log_warning('ItemService: erros ao refrescar itens stale', [
                'account_id' => $this->accountId,
                'errors' => $errors,
                'synced' => $synced,
            ]);
        }

        return $synced;
    }

    /**
     * Obtém o ml_user_id da conta do banco de dados
     */
    private function getMlUserId(): ?string
    {
        if (!$this->accountId) {
            return null;
        }

        // Tentar buscar do banco de dados
        if ($this->db !== null) {
            try {
                $stmt = $this->db->prepare("SELECT ml_user_id FROM ml_accounts WHERE id = :id");
                $stmt->execute([':id' => $this->accountId]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);

                $mlUserId = $result['ml_user_id'] ?? null;
                if ($mlUserId) {
                    return $mlUserId;
                }
            } catch (\Throwable $e) {
                log_warning('getMlUserId: falha ao consultar DB, tentando API', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: tentar obter via API com token e persistir
        $sellerId = $this->client->getSellerId();
        if (!$sellerId) {
            return null;
        }

        if ($this->db !== null) {
            try {
                $update = $this->db->prepare("UPDATE ml_accounts SET ml_user_id = :ml_user_id WHERE id = :id");
                $update->execute([':ml_user_id' => $sellerId, ':id' => $this->accountId]);
            } catch (\Throwable $e) {
                log_warning('Falha ao persistir ml_user_id', [
                    'account_id' => $this->accountId,
                    'seller_id' => $sellerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sellerId;
    }

    /**
     * Salva anúncio no banco de dados para cache/local
     */
    private function saveItemToDatabase(array $item): void
    {
        if ($this->db === null) {
            log_warning('saveItemToDatabase: DB indisponível, item não persistido', [
                'item_id' => $item['id'] ?? 'unknown',
            ]);
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO items (
                    ml_item_id, account_id, title, category_id, price,
                    currency_id, available_quantity, sold_quantity, status, condition_type,
                    catalog_product_id, sku, thumbnail, permalink, data, created_at, updated_at
                ) VALUES (
                    :ml_item_id, :account_id, :title, :category_id, :price,
                    :currency_id, :available_quantity, :sold_quantity, :status, :condition_type,
                    :catalog_product_id, :sku, :thumbnail, :permalink, :data, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    category_id = VALUES(category_id),
                    price = VALUES(price),
                    available_quantity = VALUES(available_quantity),
                    sold_quantity = VALUES(sold_quantity),
                    status = VALUES(status),
                    condition_type = VALUES(condition_type),
                    catalog_product_id = VALUES(catalog_product_id),
                    sku = VALUES(sku),
                    thumbnail = VALUES(thumbnail),
                    permalink = VALUES(permalink),
                    data = VALUES(data),
                    updated_at = NOW()
            ");

            $stmt->execute([
                ':ml_item_id' => $item['id'] ?? null,
                ':account_id' => $this->accountId,
                ':title' => $item['title'] ?? null,
                ':category_id' => $item['category_id'] ?? null,
                ':price' => $item['price'] ?? null,
                ':currency_id' => $item['currency_id'] ?? 'BRL',
                ':available_quantity' => $item['available_quantity'] ?? 0,
                ':sold_quantity' => $item['sold_quantity'] ?? 0,
                ':status' => $item['status'] ?? 'unknown',
                ':condition_type' => $item['condition'] ?? null,
                ':catalog_product_id' => $item['catalog_product_id'] ?? null,
                ':sku' => $item['seller_custom_field'] ?? ($this->extractSku($item) ?? null),
                ':thumbnail' => $item['thumbnail'] ?? null,
                ':permalink' => $item['permalink'] ?? null,
                ':data' => json_encode($this->ensureHealthScore($item)), // Ensure health
            ]);
        } catch (\Exception $e) {
            log_error('Erro ao salvar item no banco', [
                'item_id' => $item['id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Helper to ensure item has health score
     */
    private function ensureHealthScore(array $item): array
    {
        if (!isset($item['health']) || $item['health'] === null) {
            $seoService = new \App\Services\AISEOOptimizerService();
            $item['health'] = $seoService->calculateLegacyHealth($item);
        }
        return $item;
    }

    /**
     * Extrai SKU do item (atributo SELLER_SKU ou campo seller_custom_field)
     */
    private function extractSku(array $item): ?string
    {
        if (!empty($item['seller_custom_field'])) {
            return (string)$item['seller_custom_field'];
        }

        if (isset($item['attributes']) && is_array($item['attributes'])) {
            foreach ($item['attributes'] as $attr) {
                if (($attr['id'] ?? '') === 'SELLER_SKU' && !empty($attr['value_name'])) {
                    return (string)$attr['value_name'];
                }
            }
        }

        return null;
    }

    /**
     * Categorias usadas pelos anúncios da conta (filtro de Meus Anúncios).
     *
     * Fonte: catálogo local `items` (mesmo agrupamento de AnalyticsService).
     * Não usa GET /sites/{site}/search — nesse host o endpoint público
     * responde 403 (PolicyAgent/datacenter) e o dropdown ficava 400.
     */
    public function getSellerCategories(): array
    {
        if ($this->accountId === null || $this->accountId <= 0) {
            return [
                'success' => false,
                'error' => 'missing_seller_id',
                'message' => 'Conta Mercado Livre não vinculada. Conecte a conta para listar categorias do catálogo.',
                'categories' => [],
            ];
        }

        return $this->getLocalCategoriesFallback(['reason' => 'seller_catalog']);
    }

    private function getLocalCategoriesFallback(array $context = []): array
    {
        if ($this->db === null) {
            return [
                'success' => false,
                'error' => 'db_unavailable',
                'message' => 'Banco de dados indisponível para fallback local.',
                'categories' => [],
            ];
        }

        if ($this->accountId === null || $this->accountId <= 0) {
            return [
                'success' => false,
                'error' => 'missing_seller_id',
                'message' => 'Conta Mercado Livre não vinculada. Conecte a conta para listar categorias do catálogo.',
                'categories' => [],
            ];
        }

        try {
            $sql = 'SELECT category_id, '
                . 'MAX(COALESCE(NULLIF(category_name, \'\'), category_id)) AS category_name, '
                . 'COUNT(*) AS total '
                . 'FROM items '
                . 'WHERE account_id = :account_id '
                . 'AND category_id IS NOT NULL '
                . 'AND category_id <> \'\' '
                . 'GROUP BY category_id '
                . 'ORDER BY total DESC';

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['account_id' => $this->accountId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'error' => 'db_unavailable',
                'message' => 'Falha ao ler categorias do catálogo local.',
                'categories' => [],
            ];
        }

        $categories = [];
        foreach ($rows as $row) {
            $categoryId = trim((string)($row['category_id'] ?? ''));
            if ($categoryId === '') {
                continue;
            }
            $name = trim((string)($row['category_name'] ?? ''));
            $categories[] = [
                'id' => $categoryId,
                'name' => $name !== '' ? $name : $categoryId,
                'results' => (int)($row['total'] ?? 0),
            ];
        }

        $appEnv = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? ''));
        if ($appEnv !== 'testing') {
            $categories = $this->attachOfficialCategoryNames($categories);
        }

        $payload = [
            'success' => true,
            'source' => 'local_catalog',
            'categories' => $categories,
        ];
        if (($context['reason'] ?? '') !== 'seller_catalog') {
            $payload['warning'] = $this->buildLocalFallbackWarning($context);
        }

        return $payload;
    }

    /**
     * @param list<array{id: string, name: string, results: int}> $categories
     * @return list<array{id: string, name: string, results: int}>
     */
    private function attachOfficialCategoryNames(array $categories): array
    {
        foreach ($categories as &$category) {
            $categoryId = (string)($category['id'] ?? '');
            $name = (string)($category['name'] ?? '');
            if ($categoryId === '' || ($name !== '' && $name !== $categoryId)) {
                continue;
            }

            $info = $this->client->getCategory($categoryId);
            $officialName = $info['name'] ?? null;
            if (is_string($officialName) && $officialName !== '') {
                $category['name'] = $officialName;
            }
        }
        unset($category);

        return $categories;
    }

    private function getAccountSiteId(): ?string
    {
        if (!$this->accountId) {
            return null;
        }

        if ($this->db === null) {
            return null;
        }

        try {
            $stmt = $this->db->prepare("SELECT site_id FROM ml_accounts WHERE id = :id");
            $stmt->execute([':id' => $this->accountId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result['site_id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getSellerIdFromAccount(): ?string
    {
        // seller_id no contexto de anúncios equivale ao ml_user_id da conta
        return $this->getMlUserId();
    }

    /**
     * Normaliza resposta do client para payload puro da API.
     */
    private function unwrapMlResponse(array $response): array
    {
        if (isset($response['body']) && is_array($response['body']) && !isset($response['body']['error'])) {
            return $response['body'];
        }

        return $response;
    }

    /**
     * Popula item.visits com visitas reais dos últimos N dias (janela
     * consistente, padrão 7d — mesma métrica exibida no Pregão), buscando em
     * batch via MercadoLivreClient::getMultiItemVisits() (Onda 2 / T2).
     * Sem isso, a coluna Visitas fica sempre em 0 (nenhum campo do item bruto
     * da API de listagem/cache local traz visitas).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */

    private function shouldSkipVisits(array $filters): bool
    {
        if (!array_key_exists('skip_visits', $filters) || $filters['skip_visits'] === null || $filters['skip_visits'] === '') {
            return false;
        }

        return filter_var($filters['skip_visits'], FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * Hidrata linhas da listagem via GET /items?ids= (chunk 20 no client).
     * Não busca /items/{id}/description.
     *
     * Se o multi-get 403/4xx/5xx (ou falhar), preenche a página com a tabela
     * local `items` em vez de devolver a listagem vazia. Não chama visitas.
     *
     * @param list<string> $itemIds
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function hydrateItemsForList(array $itemIds, array $filters = []): array
    {
        $itemIds = array_values(array_unique(array_filter(
            $itemIds,
            static fn($id): bool => is_string($id) && $id !== ''
        )));

        if ($itemIds === []) {
            return [];
        }

        $details = [];
        $multiGetFailed = false;

        try {
            $details = $this->client->getMultiItemDetails($itemIds, self::LIST_MULTI_GET_ATTRIBUTES);
            if (!is_array($details) || $this->isMlHttpFailurePayload($details)) {
                $multiGetFailed = true;
                $status = is_array($details)
                    ? ($details['status'] ?? $details['error'] ?? 'unknown')
                    : 'invalid';
                log_warning('ItemService: multi-get falhou; hidratando listagem pelo cache local', [
                    'service' => 'ItemService',
                    'ids_count' => count($itemIds),
                    'status' => $status,
                ]);
                $details = [];
            } elseif ($details === []) {
                // getMultiItemDetails engole 403/4xx/5xx e devolve []. Não zerar a página.
                $multiGetFailed = true;
                log_warning('ItemService: multi-get vazio; hidratando listagem pelo cache local', [
                    'service' => 'ItemService',
                    'ids_count' => count($itemIds),
                ]);
            }
        } catch (\Throwable $e) {
            $multiGetFailed = true;
            log_warning('ItemService: exceção no multi-get; hidratando listagem pelo cache local', [
                'service' => 'ItemService',
                'ids_count' => count($itemIds),
                'error' => $e->getMessage(),
            ]);
            $details = [];
        }

        $itemsById = [];
        foreach ($itemIds as $itemId) {
            $item = $details[$itemId] ?? null;
            if ($this->isUsableMlListItem($item)) {
                $itemsById[$itemId] = $this->formatItemForList($item);
            }
        }

        if ($multiGetFailed) {
            $missingIds = [];
            foreach ($itemIds as $itemId) {
                if (!isset($itemsById[$itemId])) {
                    $missingIds[] = $itemId;
                }
            }

            $localById = $this->loadLocalItemsForList($missingIds);
            foreach ($missingIds as $itemId) {
                if (!isset($localById[$itemId])) {
                    continue;
                }
                $itemsById[$itemId] = $this->formatItemForList($localById[$itemId]);
            }

            log_warning('ItemService: listagem hidratada do cache local items', [
                'service' => 'ItemService',
                'requested' => count($itemIds),
                'from_ml' => count($itemIds) - count($missingIds),
                'from_local' => count($localById),
                'still_missing' => count($missingIds) - count($localById),
            ]);
        }

        $items = [];
        foreach ($itemIds as $itemId) {
            if (isset($itemsById[$itemId])) {
                $items[] = $itemsById[$itemId];
            }
        }

        return $this->filterItemsByCustomCriteria($items, $filters);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isMlHttpFailurePayload(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/^ML[A-Z]*\d+/i', $key) === 1 && is_array($value)) {
                return false;
            }
        }

        $status = $payload['status'] ?? $payload['http_status'] ?? $payload['code'] ?? null;
        if (is_numeric($status) && (int) $status >= 400) {
            return true;
        }

        $error = $payload['error'] ?? null;

        return is_string($error) && $error !== '';
    }

    private function isUsableMlListItem(mixed $item): bool
    {
        if (!is_array($item) || isset($item['error'])) {
            return false;
        }

        $httpStatus = $item['http_status'] ?? $item['code'] ?? null;
        if (is_numeric($httpStatus) && (int) $httpStatus >= 400) {
            return false;
        }

        $status = $item['status'] ?? null;
        if (is_numeric($status) && (int) $status >= 400) {
            return false;
        }
        if (is_string($status) && in_array(strtolower($status), ['401', '403', '404', '500'], true)) {
            return false;
        }

        $id = $item['id'] ?? null;

        return is_string($id) && $id !== '';
    }

    /**
     * @param list<string> $itemIds
     * @return array<string, array<string, mixed>>
     */
    private function loadLocalItemsForList(array $itemIds): array
    {
        if ($this->db === null || $itemIds === []) {
            return [];
        }

        $itemIds = array_values(array_unique($itemIds));
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $sql = 'SELECT ml_item_id, title, price, status, available_quantity, sold_quantity, thumbnail, permalink, data'
            . " FROM items WHERE ml_item_id IN ({$placeholders})";
        $params = $itemIds;

        if ($this->accountId !== null) {
            $sql .= ' AND account_id = ?';
            $params[] = $this->accountId;
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            log_warning('ItemService: falha ao ler items locais para listagem', [
                'service' => 'ItemService',
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['ml_item_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $byId[$id] = $this->mapLocalItemRowForList($row);
        }

        return $byId;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapLocalItemRowForList(array $row): array
    {
        $data = $row['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($data)) {
            $data = [];
        }

        $pick = static function (string $column, string ...$jsonKeys) use ($row, $data): mixed {
            $value = $row[$column] ?? null;
            if ($value !== null && $value !== '') {
                return $value;
            }
            foreach ($jsonKeys as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                    return $data[$key];
                }
            }

            return $value;
        };

        $id = (string) $row['ml_item_id'];
        $price = $pick('price', 'price');
        $stock = $pick('available_quantity', 'available_quantity', 'stock');
        $sold = $pick('sold_quantity', 'sold_quantity');

        return [
            'id' => $id,
            'ml_item_id' => $id,
            'title' => $pick('title', 'title'),
            'price' => is_numeric($price) ? (float) $price : $price,
            'status' => $pick('status', 'status'),
            'available_quantity' => is_numeric($stock) ? (int) $stock : $stock,
            'sold_quantity' => is_numeric($sold) ? (int) $sold : $sold,
            'thumbnail' => $pick('thumbnail', 'thumbnail', 'secure_thumbnail'),
            'permalink' => $pick('permalink', 'permalink'),
        ];
    }

    private function attachVisits(array $items, int $days = 7): array
    {
        if ($items === []) {
            return $items;
        }

        $ids = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return $items;
        }

        try {
            $visitMap = $this->client->getMultiItemVisits($ids, $days);
        } catch (\Throwable $e) {
            log_warning('ItemService: falha ao buscar visitas em batch', [
                'service' => 'ItemService',
                'error' => $e->getMessage(),
            ]);
            return $items;
        }

        foreach ($items as &$item) {
            $id = $item['id'] ?? '';
            $visitData = $visitMap[$id] ?? null;
            $item['visits'] = (int) ($visitData['total'] ?? $visitData['visits'] ?? 0);
        }
        unset($item);

        return $items;
    }

    private function formatItemForList(array $item): array
    {
        $item['ml_id'] = $item['id'] ?? null;
        $item['thumbnail'] = $item['thumbnail']
            ?? ($item['pictures'][0]['url'] ?? $item['pictures'][0]['secure_url'] ?? null);
        $item['permalink'] = $item['permalink'] ?? ($item['link'] ?? null);
        $item['visits'] = $item['visits'] ?? ($item['metrics']['visits'] ?? 0);
        $item['sold_quantity'] = $item['sold_quantity'] ?? ($item['metrics']['sold_quantity'] ?? 0);

        return $item;
    }
    public function getCatalogDetails(string $catalogId): array
    {
        // Real API Implementation
        try {
            // GET /products/{id}
            $product = $this->client->get("/products/{$catalogId}");

            // Should contain buy_box_winner
            $winner = $product['buy_box_winner'] ?? null;

            $sellerId = $this->getSellerIdFromAccount();
            if (!$sellerId) {
                $sellerId = $this->client->getSellerId();
            }

            // Correção de bug: quando a API não retorna buy_box_winner (produto sem
            // disputa de catálogo ativa, ou sem outros vendedores), o código anterior
            // sempre fabricava um objeto {price: 0, seller_id: 0}. Isso fazia a UI
            // reportar "Perdendo" com preço-para-vencer R$0,00 mesmo sem nenhuma
            // disputa real. Agora, sem winner real, retornamos null/desconhecido
            // explicitamente em vez de inventar um concorrente com preço zero.
            if (!is_array($winner) || !isset($winner['price'])) {
                return [
                    'id' => $catalogId,
                    'title' => $product['name'] ?? 'Unknown',
                    'buy_box_winner' => null,
                    'is_winner' => null,
                    'price_to_win' => null,
                    'status' => 'no_active_competition',
                ];
            }

            return [
                'id' => $catalogId,
                'title' => $product['name'] ?? 'Unknown',
                'buy_box_winner' => [
                    'price' => $winner['price'],
                    'seller_id' => $winner['seller_id'] ?? 0
                ],
                'is_winner' => $sellerId ? ((string)($winner['seller_id'] ?? '') === (string)$sellerId) : null,
                'price_to_win' => max(0, round($winner['price'] - 0.01, 2)),
            ];
        } catch (\Exception $e) {
            // Fallback for demo or if scope missing
            if (strpos($e->getMessage(), '403') !== false) {
                return ['error' => 'missing_scope', 'message' => 'Permissão catalog.read necessária'];
            }
            return [
                'id' => $catalogId,
                'error' => 'ml_api_error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
