<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\BrandSearchModel;
use App\Services\MercadoLivre\BrandSearchService;

/**
 * BrandSearchController — Módulo 20 BRAND-003
 *
 * Rotas registradas em app/Routes/api/items.php:
 *   GET  /brand-search                      → index()
 *   POST /api/brand-search/start            → start()    HTTP 202
 *   GET  /api/brand-search/{id}/progress    → progress()
 *   GET  /api/brand-search/{id}/sellers     → sellers()
 *   GET  /api/brand-search/{id}/items/{sid} → items()
 *   GET  /api/brand-search/{id}/export      → export()
 */
class BrandSearchController extends BaseController
{
    private BrandSearchService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new BrandSearchService($this->getActiveAccountId());
    }

    // =========================================================================
    // Web
    // =========================================================================

    /** GET /brand-search — renderiza dashboard */
    public function index(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        $pageTitle = 'Busca por Marca';
        ob_start();
        require __DIR__ . '/../Views/brand_analysis/brand_search.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/modern/app.php';
    }

    // =========================================================================
    // API — BRAND-003
    // =========================================================================

    /**
     * POST /api/brand-search/start
     *
     * Body JSON: { brand_id, brand_name, site_id?, category_id? }
     * Retorna HTTP 202 + { search_id: int, status: "pending" }
     */
    public function start(): void
    {
        $brandId    = trim((string) $this->request->input('brand_id', ''));
        $brandName  = trim((string) $this->request->input('brand_name', ''));
        $siteId     = strtoupper(trim((string) $this->request->input('site_id', 'MLB')));
        $raw        = $this->request->input('category_id');
        $categoryId = ($raw !== null && $raw !== '') ? (string) $raw : null;

        if ($brandId === '') {
            $this->jsonError('brand_id é obrigatório.', 422);
            return;
        }

        if ($brandName === '') {
            $this->jsonError('brand_name é obrigatório.', 422);
            return;
        }

        if (!preg_match('/^[A-Z0-9_-]{2,10}$/', $siteId)) {
            $this->jsonError('site_id inválido.', 422);
            return;
        }

        if (strlen($brandId) > 64) {
            $this->jsonError('brand_id excede o tamanho máximo permitido.', 422);
            return;
        }

        if (strlen($brandName) > 255) {
            $this->jsonError('brand_name excede o tamanho máximo permitido.', 422);
            return;
        }

        if ($categoryId !== null) {
            $categoryId = trim($categoryId);
            if ($categoryId === '' || strlen($categoryId) > 64) {
                $this->jsonError('category_id inválido.', 422);
                return;
            }
        }

        $accountId = $this->getActiveAccountId();
        if ($accountId === null || $accountId <= 0) {
            $this->jsonError('Conta Mercado Livre ativa é obrigatória.', 422);
            return;
        }

        try {
            $searchId = $this->service->initSearch(
                $accountId,
                $brandId,
                $brandName,
                $siteId !== '' ? $siteId : 'MLB',
                $categoryId
            );
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
            return;
        } catch (\Throwable $e) {
            $this->jsonError('Não foi possível iniciar a busca.', 500);
            return;
        }

        $this->dispatchBrandSearchWorker($searchId);

        http_response_code(202);
        $this->jsonSuccess(['search_id' => $searchId, 'status' => 'pending']);
    }

    /**
     * Dispara o worker em background para a busca recém-criada.
     * Fallback: cron a cada minuto processa a fila se o spawn falhar.
     */
    private function dispatchBrandSearchWorker(int $searchId): bool
    {
        if ($searchId <= 0) {
            return false;
        }

        $root = dirname(__DIR__, 2);
        $php = $this->resolvePhpCliBinary();
        $worker = $root . '/bin/brand-search-worker.php';
        $log = $root . '/storage/logs/brand-search-worker.log';

        if (!is_file($worker)) {
            return false;
        }

        $cmd = sprintf(
            'cd %s && nohup %s %s --search-id=%d >> %s 2>&1 & echo $!',
            escapeshellarg($root),
            escapeshellarg($php),
            escapeshellarg($worker),
            $searchId,
            escapeshellarg($log)
        );

        try {
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            $spawnPid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;

            return $spawnPid > 0 || $exitCode === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * PHP_BINARY sob php-fpm aponta para php-fpm (não executa scripts CLI).
     */
    private function resolvePhpCliBinary(): string
    {
        $candidates = [
            '/usr/bin/php8.4',
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/bin/php8.1',
            '/usr/bin/php8.0',
            '/usr/bin/php',
        ];

        if (PHP_BINARY !== '' && stripos(PHP_BINARY, 'fpm') === false && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        foreach ($candidates as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return 'php';
    }

    /**
     * GET /api/brand-search/{id}/progress
     *
     * Retorna: { search_id, status, progress, total_items, total_sellers, error_message? }
     * Cache-Control: max-age=1 (sugestão ao frontend para TTL 1 s)
     */
    public function progress(string $id): void
    {
        $searchId = (int) $id;

        if ($searchId <= 0) {
            $this->jsonError('search_id inválido.', 422);
            return;
        }

        $data = $this->service->getSearchProgress($searchId);

        if ($data === null) {
            $this->jsonError('Busca não encontrada.', 404);
            return;
        }

        header('Cache-Control: max-age=1, private');
        $this->jsonSuccess([
            'search_id'     => (int) $data['id'],
            'status'        => $data['status'],
            'progress'      => (int) $data['progress'],
            'total_items'   => (int) ($data['total_items']   ?? 0),
            'total_sellers' => (int) ($data['total_sellers'] ?? 0),
            'error_message' => $data['error_message'] ?? null,
            'started_at'    => $data['started_at']    ?? null,
            'completed_at'  => $data['completed_at']  ?? null,
        ]);
    }

    /**
     * GET /api/brand-search/{id}/sellers
     *
     * Query params: reputation?, min_items?, sort?, order?, page?, per_page?
     */
    public function sellers(string $id): void
    {
        $searchId = (int) $id;

        if ($searchId <= 0) {
            $this->jsonError('search_id inválido.', 422);
            return;
        }

        $filters = [
            'reputation' => (string) ($this->request->get('reputation') ?? ''),
            'min_items'  => $this->request->getInt('min_items', 0),
        ];

        $sort    = (string) ($this->request->get('sort') ?? 'total_items_brand');
        $order   = (string) ($this->request->get('order') ?? 'desc');
        $page    = max(1, $this->request->getInt('page', 1));
        $perPage = min(100, max(1, $this->request->getInt('per_page', 20)));

        $result = $this->service->getSearchSellers($searchId, $filters, $sort, $order, $page, $perPage);

        $this->jsonSuccess($result);
    }

    /**
     * GET /api/brand-search/{id}/items/{sellerId}
     *
     * Query params: limit? (max 100), offset?
     */
    public function items(string $id, string $sellerId): void
    {
        $searchId    = (int) $id;
        $sellerIdInt = (int) $sellerId;

        if ($searchId <= 0 || $sellerIdInt <= 0) {
            $this->jsonError('Parâmetros inválidos.', 422);
            return;
        }

        $limit  = min(100, max(1, $this->request->getInt('limit', 50)));
        $offset = max(0, $this->request->getInt('offset', 0));

        $model = new BrandSearchModel();
        $items = $model->getItemsBySeller($searchId, $sellerIdInt, $limit, $offset);

        $this->jsonSuccess(['items' => $items, 'count' => count($items)]);
    }

    /**
     * GET /api/brand-search/{id}/export — CSV download
     */
    public function export(string $id): void
    {
        $searchId = (int) $id;

        if ($searchId <= 0) {
            $this->jsonError('search_id inválido.', 422);
            return;
        }

        $model  = new BrandSearchModel();
        $search = $model->getSearch($searchId);

        if ($search === null) {
            $this->jsonError('Busca não encontrada.', 404);
            return;
        }

        $sellers  = $model->getSellersBySearchId($searchId, [], 'total_items_brand', 'desc', 10000, 0);
        $brand    = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $search['brand_name']);
        $filename = 'sellers_' . $brand . '_' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            $this->jsonError('Erro ao abrir output.', 500);
            return;
        }

        fputcsv($out, [
            'seller_id', 'nickname', 'reputation_level', 'reputation_score',
            'total_items_brand', 'avg_price', 'power_seller_status',
            'city', 'state', 'trend', 'site_status',
        ]);

        foreach ($sellers as $s) {
            fputcsv($out, [
                $s['seller_id'],
                $s['nickname'],
                $s['reputation_level']    ?? '',
                $s['reputation_score']    ?? 0,
                $s['total_items_brand'],
                $s['avg_price']           ?? '',
                $s['power_seller_status'] ?? '',
                $s['city']                ?? '',
                $s['state']               ?? '',
                $s['trend']               ?? 'stable',
                $s['site_status']         ?? '',
            ]);
        }

        fclose($out);
        exit;
    }
}
