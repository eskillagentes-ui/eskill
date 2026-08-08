<?php

declare(strict_types=1);

namespace App\Services\MercadoLivre;

use App\Models\BrandSearchModel;
use App\Services\MercadoLivreClient;
use App\Services\StructuredLogService;

/**
 * BrandSearchService — Módulo 20 BRAND-003
 *
 * Implementa busca assíncrona de vendedores de uma marca no Mercado Livre.
 * Fluxo: initSearch() cria registro pending; executeSearch() é chamado pelo worker.
 */
class BrandSearchService
{
    private const ML_API_BASE     = 'https://api.mercadolibre.com';
    private const PAGE_LIMIT      = 50;
    private const MAX_OFFSET      = 950;
    private const RATE_LIMIT_WAIT = 350;   // ms (usleep * 1000)
    private const USERS_BATCH     = 20;

    private MercadoLivreClient $ml;
    private StructuredLogService $log;

    public function __construct(?int $accountId = null)
    {
        $this->ml  = new MercadoLivreClient($accountId);
        $this->log = new StructuredLogService();
    }

    // =========================================================================
    // Métodos públicos
    // =========================================================================

    /**
     * Cria registro pending em brand_searches e retorna search_id.
     * Chamado pelo BrandSearchController::start().
     */
    public function initSearch(
        int $accountId,
        string $brandId,
        string $brandName,
        string $siteId = 'MLB',
        ?string $categoryId = null
    ): int {
        $brandId = trim($brandId);
        $brandName = trim($brandName);
        $siteId = strtoupper(trim($siteId));
        $categoryId = $categoryId !== null ? trim($categoryId) : null;

        if ($accountId <= 0) {
            throw new \InvalidArgumentException('accountId inválido.');
        }

        if ($brandId === '' || strlen($brandId) > 64) {
            throw new \InvalidArgumentException('brandId inválido.');
        }

        if ($brandName === '' || strlen($brandName) > 255) {
            throw new \InvalidArgumentException('brandName inválido.');
        }

        if (!preg_match('/^[A-Z0-9_-]{2,10}$/', $siteId)) {
            throw new \InvalidArgumentException('siteId inválido.');
        }

        if ($categoryId !== null && ($categoryId === '' || strlen($categoryId) > 64)) {
            throw new \InvalidArgumentException('categoryId inválido.');
        }

        return (new BrandSearchModel())->createSearch([
            'account_id'  => $accountId,
            'brand_id'    => $brandId,
            'brand_name'  => $brandName,
            'site_id'     => $siteId,
            'category_id' => $categoryId,
        ]);
    }

    /**
     * Orquestra coleta completa: categorias → itens → sellers.
     * Chamado pelo worker (brand-search-worker.php).
     *
     * @throws \Throwable em caso de falha — após persistir status=failed
     */
    public function executeSearch(int $searchId): void
    {
        $model  = new BrandSearchModel();
        $search = $model->getSearch($searchId);

        if ($search === null) {
            throw new \InvalidArgumentException("Search #{$searchId} não encontrada.");
        }

        $model->updateProgress($searchId, 0, 'running');
        $this->log->info('BrandSearch iniciada', ['search_id' => $searchId]);

        try {
            // Etapa 1: coletar itens por categoria (progress 0 → 70)
            $categories   = $this->fetchBrandCategories($search['brand_id'], $search['site_id']);
            $totalCats    = max(1, count($categories));
            $allSellerIds = [];

            foreach ($categories as $i => $cat) {
                $items = $this->fetchItemsByBrandAndCategory(
                    $search['brand_id'],
                    $cat['id'],
                    $search['site_id']
                );

                if (!empty($items)) {
                    $model->saveItems(
                        $searchId,
                        array_map(fn(array $item): array => $this->mapBrandItem($searchId, $item), $items)
                    );
                }

                foreach ($items as $item) {
                    $sid = $item['seller']['id'] ?? null;
                    if ($sid !== null) {
                        $allSellerIds[(int) $sid] = true;
                    }
                }

                $model->updateProgress($searchId, (int) (($i + 1) / $totalCats * 70), 'running');
            }

            // Etapa 2: buscar perfis de sellers (progress 70 → 100)
            $uniqueIds = array_keys($allSellerIds);
            $chunks    = array_chunk($uniqueIds, self::USERS_BATCH);
            $total     = max(1, count($chunks));

            foreach ($chunks as $j => $chunk) {
                foreach ($this->fetchSellersBatch($chunk) as $mlUser) {
                    $stats = $model->getSellerStats($searchId, (int) $mlUser['id']);
                    $model->saveSellers(
                        $searchId,
                        [$this->mapBrandSeller($searchId, $mlUser, $stats['total_items'], $stats['avg_price'])]
                    );
                    usleep(self::RATE_LIMIT_WAIT * 1000);
                }

                $model->updateProgress($searchId, 70 + (int) (($j + 1) / $total * 30), 'running');
            }

            $model->updateCompleted($searchId, $model->countItems($searchId), count($uniqueIds));
            $this->log->info('BrandSearch concluída', [
                'search_id' => $searchId,
                'sellers'   => count($uniqueIds),
                'items'     => $model->countItems($searchId),
            ]);
        } catch (\Throwable $e) {
            $model->updateFailed($searchId, $e->getMessage());
            $this->log->error('BrandSearch falhou', [
                'search_id' => $searchId,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Retorna dados de progresso para polling do frontend.
     */
    public function getSearchProgress(int $searchId): ?array
    {
        return (new BrandSearchModel())->getSearch($searchId);
    }

    /**
     * Retorna lista paginada de vendedores de uma busca.
     *
     * @param  array<string,mixed> $filters  ['reputation' => string, 'min_items' => int]
     * @return array<string,mixed>
     */
    public function getSearchSellers(
        int $searchId,
        array $filters,
        string $sort,
        string $order,
        int $page,
        int $perPage
    ): array {
        $model  = new BrandSearchModel();
        $offset = ($page - 1) * $perPage;
        $sort   = in_array($sort, ['total_items_brand', 'reputation_score', 'avg_price', 'nickname'], true)
            ? $sort : 'total_items_brand';
        $order  = strtolower($order) === 'asc' ? 'asc' : 'desc';
        $total  = $model->countSellersBySearchId($searchId, $filters);

        return [
            'data'      => $model->getSellersBySearchId($searchId, $filters, $sort, $order, $perPage, $offset),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
        ];
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    /**
     * Busca categorias disponíveis para a marca no site via available_filters.
     *
     * @return array<int,array{id:string,name:string}>
     */
    private function fetchBrandCategories(string $brandId, string $siteId): array
    {
        try {
            $resp = $this->ml->get(
                "/sites/{$siteId}/search",
                ['BRAND' => $brandId, 'limit' => 1],
                300,
                true
            );
        } catch (\Throwable $e) {
            $this->log->warning('BrandSearch: falha ao buscar categorias', ['error' => $e->getMessage()]);
            throw new \RuntimeException(
                'Falha ao consultar /sites/' . $siteId . '/search: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $this->assertMlSearchOk($resp, "/sites/{$siteId}/search");

        foreach ($resp['available_filters'] ?? [] as $filter) {
            if (($filter['id'] ?? '') === 'category') {
                return array_map(
                    fn(array $v): array => ['id' => (string) $v['id'], 'name' => (string) ($v['name'] ?? '')],
                    $filter['values'] ?? []
                );
            }
        }

        return [['id' => 'ALL', 'name' => 'Todas']];
    }

    /**
     * Pagina completamente uma categoria respeitando MAX_OFFSET=950.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchItemsByBrandAndCategory(
        string $brandId,
        string $categoryId,
        string $siteId
    ): array {
        $items  = [];
        $offset = 0;

        do {
            usleep(self::RATE_LIMIT_WAIT * 1000);

            $params = ['BRAND' => $brandId, 'limit' => self::PAGE_LIMIT, 'offset' => $offset];
            if ($categoryId !== 'ALL') {
                $params['category'] = $categoryId;
            }

            try {
                $resp = $this->ml->get("/sites/{$siteId}/search", $params, null, true);
            } catch (\Throwable $e) {
                $this->log->warning('BrandSearch: erro na página de busca', [
                    'offset' => $offset,
                    'error'  => $e->getMessage(),
                ]);
                throw new \RuntimeException(
                    'Falha ao paginar /sites/' . $siteId . '/search (offset=' . $offset . '): ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            $this->assertMlSearchOk($resp, "/sites/{$siteId}/search");

            $page  = $resp['results'] ?? [];
            $total = (int) ($resp['paging']['total'] ?? 0);
            $items = array_merge($items, $page);
            $offset += self::PAGE_LIMIT;
        } while (count($page) > 0 && $offset <= self::MAX_OFFSET && $offset < $total);

        return $items;
    }

    /**
     * Busca perfis de vendedores individualmente (até USERS_BATCH=20 por chamada).
     *
     * @param  int[] $ids
     * @return array<int,array<string,mixed>>
     */
    private function fetchSellersBatch(array $ids): array
    {
        $results = [];
        foreach ($ids as $id) {
            usleep(self::RATE_LIMIT_WAIT * 1000);
            try {
                $user = $this->ml->get("/users/{$id}", [], 900, false);
                if (!empty($user['id'])) {
                    $results[] = $user;
                }
            } catch (\Throwable $e) {
                $this->log->warning('BrandSearch: erro ao buscar seller', [
                    'seller_id' => $id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
        return $results;
    }

    /** @param array<string,mixed> $item */
    private function mapBrandItem(int $searchId, array $item): array
    {
        return [
            'item_id'       => (string) ($item['id'] ?? ''),
            'seller_id'     => (int) ($item['seller']['id'] ?? 0),
            'title'         => (string) ($item['title'] ?? ''),
            'category_id'   => $item['category_id'] ?? null,
            'price'         => isset($item['price']) ? (float) $item['price'] : null,
            'currency_id'   => $item['currency_id'] ?? 'BRL',
            'condition'     => $this->normalizeCondition((string) ($item['condition'] ?? '')),
            'listing_type'  => $item['listing_type_id'] ?? null,
            'available_qty' => $item['available_quantity'] ?? null,
            'status'        => $item['status'] ?? 'active',
        ];
    }

    /** @param array<string,mixed> $mlUser */
    private function mapBrandSeller(
        int $searchId,
        array $mlUser,
        int $totalItems,
        float $avgPrice
    ): array {
        $rep = $mlUser['seller_reputation'] ?? [];

        return [
            'seller_id'           => (int) ($mlUser['id'] ?? 0),
            'nickname'            => (string) ($mlUser['nickname'] ?? ''),
            'seller_type'         => $mlUser['seller_type'] ?? null,
            'permalink'           => $mlUser['permalink'] ?? null,
            'reputation_level'    => $rep['level_id'] ?? null,
            'reputation_score'    => $this->calcReputationScore($rep),
            'power_seller_status' => $rep['power_seller_status'] ?? null,
            'total_items_brand'   => $totalItems,
            'avg_price'           => $avgPrice > 0.0 ? $avgPrice : null,
            'site_status'         => $mlUser['status'] ?? null,
            'country_id'          => $mlUser['country_id'] ?? 'BR',
            'city'                => $mlUser['address']['city'] ?? null,
            'state'               => $mlUser['address']['state'] ?? null,
            'trend'               => 'stable',
        ];
    }

    /** @param array<string,mixed> $rep */
    private function calcReputationScore(array $rep): int
    {
        $levelMap = [
            '5_green'       => 100,
            '4_light_green' => 80,
            '3_yellow'      => 60,
            '2_orange'      => 40,
            '1_red'         => 20,
        ];

        $level = $rep['level_id'] ?? null;
        if ($level !== null && isset($levelMap[$level])) {
            return $levelMap[$level];
        }

        $positive = (float) ($rep['transactions']['ratings']['positive'] ?? 0);
        return (int) round($positive * 100);
    }

    private function normalizeCondition(string $c): string
    {
        return in_array($c, ['new', 'used', 'not_specified'], true) ? $c : 'not_specified';
    }

    /**
     * Garante que a resposta de /sites/{site}/search é utilizável.
     * PolicyAgent 403 não deve virar “busca concluída com 0 itens”.
     *
     * @param array<string,mixed> $resp
     */
    private function assertMlSearchOk(array $resp, string $endpoint): void
    {
        $status = isset($resp['status']) ? (int) $resp['status'] : null;
        $error = isset($resp['error']) ? (string) $resp['error'] : '';
        $message = isset($resp['message']) ? (string) $resp['message'] : '';

        $blocked = $status === 403
            || strcasecmp($error, 'forbidden') === 0
            || strcasecmp($message, 'forbidden') === 0
            || (isset($resp['success']) && $resp['success'] === false && $status !== null && $status >= 400);

        if (!$blocked) {
            return;
        }

        throw new \RuntimeException(
            'A API do Mercado Livre bloqueou a busca pública de anúncios (HTTP 403 / PolicyAgent). '
            . 'Isso ocorre com IP de datacenter — inclusive via proxy Cloudflare. '
            . 'Configure um proxy residencial em ML_PROXY_* (tabela ml_proxies está vazia) '
            . 'para liberar /sites/{site}/search.'
        );
    }
}
