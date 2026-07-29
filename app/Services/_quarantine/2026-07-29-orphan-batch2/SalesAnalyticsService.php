<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;
use Exception;

/**
 * Service para análise de vendas com dados reais do Mercado Livre
 * Substitui dados mockados por informações reais da API
 */
class SalesAnalyticsService
{
    private ?string $accountId;
    private MercadoLivreClient $mlClient;
    private CacheService $cache;
    private bool $ordersCapabilityUnavailable = false;
    private const CACHE_TTL = 3600; // 1 hora

    public function __construct(
        ?string $accountId = null,
        ?MercadoLivreClient $mlClient = null,
        ?CacheService $cache = null
    ) {
        $this->accountId = $accountId;
        $this->mlClient = $mlClient ?? new MercadoLivreClient($accountId);
        $this->cache = $cache ?? new CacheService();
    }

    /**
     * Obtém dados de vendas para um período
     * @param string $period Período (30d, 60d, 90d, etc)
     * @return array Dados de vendas com métricas calculadas
     */
    public function getSalesData(string $period = '30d'): array
    {
        $cacheKey = "sales_data_{$this->accountId}_{$period}";

        // Tentar cache primeiro
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $days = (int) filter_var($period, FILTER_SANITIZE_NUMBER_INT);
            $dateFrom = date('Y-m-d\T00:00:00.000-00:00', strtotime("-{$days} days"));
            $dateTo = date('Y-m-d\T23:59:59.999-00:00');

            // Buscar pedidos do ML
            $this->ordersCapabilityUnavailable = false;
            $orders = $this->fetchOrdersFromML($dateFrom, $dateTo);

            if ($this->ordersCapabilityUnavailable) {
                $result = $this->getEmptyStructure($period);
                $result['feature_unavailable'] = true;
                $result['error'] = 'orders_access_unavailable';
                return $result;
            }

            // Calcular métricas
            $metrics = $this->calculateSalesMetrics($orders);

            // Dados por período
            $salesByPeriod = $this->groupSalesByPeriod($orders, $days);

            // Top produtos
            $topProducts = $this->getTopProducts($orders);

            // Vendas por categoria
            $salesByCategory = $this->getSalesByCategory($orders);

            $result = [
                'total_sales' => $metrics['total_sales'],
                'total_orders' => $metrics['total_orders'],
                'average_ticket' => $metrics['average_ticket'],
                'conversion_rate' => $metrics['conversion_rate'],
                'sales_by_period' => $salesByPeriod,
                'top_products' => $topProducts,
                'sales_by_category' => $salesByCategory,
                'date_from' => date('Y-m-d', strtotime("-{$days} days")),
                'date_to' => date('Y-m-d'),
                'currency' => 'BRL',
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Cachear resultado
            $this->cache->set($cacheKey, $result, self::CACHE_TTL);

            return $result;
        } catch (Exception $e) {
            log_error('Erro no SalesAnalyticsService', [
                'service' => 'SalesAnalyticsService',
                'period' => $period,
                'error' => $e->getMessage(),
            ]);

            // Retornar estrutura vazia em caso de erro
            return $this->getEmptyStructure($period);
        }
    }

    /**
     * Busca pedidos do Mercado Livre via API
     * @param string $dateFrom Data inicial
     * @param string $dateTo Data final
     * @return array Lista de pedidos
     */
    private function fetchOrdersFromML(string $dateFrom, string $dateTo): array
    {
        $orders = [];
        $offset = 0;
        $limit = 50;
        $maxOrders = 1000; // Limite de segurança

        do {
            $params = [
                'seller' => $this->mlClient->getSellerId(),
                'order.date_created.from' => $dateFrom,
                'order.date_created.to' => $dateTo,
                'sort' => 'date_desc',
                'offset' => $offset,
                'limit' => $limit
            ];

            $response = $this->mlClient->get('/orders/search', $params);

            if ($this->isOrdersCapabilityUnavailable($response)) {
                $this->ordersCapabilityUnavailable = true;
                return [];
            }

            if (isset($response['results'])) {
                $orders = array_merge($orders, $response['results']);
                $offset += $limit;

                // Parar se não houver mais resultados ou atingir limite
                if (count($response['results']) < $limit || count($orders) >= $maxOrders) {
                    break;
                }
            } else {
                break;
            }

            usleep(200000); // 200ms entre requests para evitar rate limit

        } while (true);

        return $orders;
    }

    /**
     * Calcula métricas de vendas
     * @param array $orders Lista de pedidos
     * @return array Métricas calculadas
     */
    private function calculateSalesMetrics(array $orders): array
    {
        $totalSales = 0;
        $totalOrders = count($orders);
        $paidOrders = 0;

        foreach ($orders as $order) {
            // Apenas pedidos pagos
            if (isset($order['status']) && $order['status'] === 'paid') {
                $paidOrders++;
                $totalSales += $order['total_amount'] ?? 0;
            }
        }

        $averageTicket = $paidOrders > 0 ? $totalSales / $paidOrders : 0;

        // Conversão = pedidos pagos / total de pedidos
        $conversionRate = $totalOrders > 0 ? ($paidOrders / $totalOrders) * 100 : 0;

        return [
            'total_sales' => round($totalSales, 2),
            'total_orders' => $paidOrders,
            'average_ticket' => round($averageTicket, 2),
            'conversion_rate' => round($conversionRate, 2)
        ];
    }

    /**
     * Agrupa vendas por período (diário)
     * @param array $orders Lista de pedidos
     * @param int $days Número de dias
     * @return array Vendas agrupadas por data
     */
    private function groupSalesByPeriod(array $orders, int $days): array
    {
        $salesByDate = [];

        // Inicializar todos os dias com zero
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $salesByDate[$date] = [
                'date' => $date,
                'sales' => 0,
                'orders' => 0
            ];
        }

        // Agrupar pedidos por data
        foreach ($orders as $order) {
            if (!isset($order['date_created']) || $order['status'] !== 'paid') {
                continue;
            }

            $date = date('Y-m-d', strtotime($order['date_created']));

            if (isset($salesByDate[$date])) {
                $salesByDate[$date]['sales'] += $order['total_amount'] ?? 0;
                $salesByDate[$date]['orders']++;
            }
        }

        return array_values($salesByDate);
    }

    /**
     * Obtém top produtos vendidos
     * @param array $orders Lista de pedidos
     * @param int $limit Limite de produtos
     * @return array Top produtos
     */
    private function getTopProducts(array $orders, int $limit = 10): array
    {
        $products = [];

        foreach ($orders as $order) {
            if ($order['status'] !== 'paid' || !isset($order['order_items'])) {
                continue;
            }

            foreach ($order['order_items'] as $item) {
                $itemId = $item['item']['id'] ?? null;
                if (!$itemId) continue;

                if (!isset($products[$itemId])) {
                    $products[$itemId] = [
                        'item_id' => $itemId,
                        'title' => $item['item']['title'] ?? 'N/A',
                        'quantity' => 0,
                        'revenue' => 0
                    ];
                }

                $products[$itemId]['quantity'] += $item['quantity'] ?? 1;
                $products[$itemId]['revenue'] += $item['sale_price'] ?? 0;
            }
        }

        // Ordenar por receita
        usort($products, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        return array_slice($products, 0, $limit);
    }

    /**
     * Agrupa vendas por categoria
     * @param array $orders Lista de pedidos
     * @return array Vendas por categoria
     */
    private function getSalesByCategory(array $orders): array
    {
        $categories = [];

        foreach ($orders as $order) {
            if ($order['status'] !== 'paid' || !isset($order['order_items'])) {
                continue;
            }

            foreach ($order['order_items'] as $item) {
                $categoryId = $item['item']['category_id'] ?? 'unknown';

                if (!isset($categories[$categoryId])) {
                    $categories[$categoryId] = [
                        'category_id' => $categoryId,
                        'revenue' => 0,
                        'orders' => 0
                    ];
                }

                $categories[$categoryId]['revenue'] += $item['sale_price'] ?? 0;
                $categories[$categoryId]['orders']++;
            }
        }

        // Ordenar por receita
        usort($categories, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        return array_values($categories);
    }

    /**
     * Retorna estrutura vazia quando não há dados
     * @param string $period Período
     * @return array Estrutura vazia
     */
    private function getEmptyStructure(string $period): array
    {
        $days = (int) filter_var($period, FILTER_SANITIZE_NUMBER_INT);

        return [
            'total_sales' => 0.00,
            'total_orders' => 0,
            'average_ticket' => 0.00,
            'conversion_rate' => 0.0,
            'sales_by_period' => [],
            'top_products' => [],
            'sales_by_category' => [],
            'date_from' => date('Y-m-d', strtotime("-{$days} days")),
            'date_to' => date('Y-m-d'),
            'currency' => 'BRL',
            'error' => 'Não foi possível obter dados de vendas'
        ];
    }

    /**
     * Limpa cache de vendas
     * @return bool Sucesso
     */
    public function clearCache(): bool
    {
        $pattern = "sales_data_{$this->accountId}_*";
        return $this->cache->deletePattern($pattern);
    }

    /**
     * Obtém resumo rápido de vendas (versão lightweight)
     * @return array Resumo de vendas
     */
    public function getQuickSummary(): array
    {
        try {
            // Buscar apenas pedidos dos últimos 7 dias (mais rápido)
            $dateFrom = date('Y-m-d\T00:00:00.000-00:00', strtotime('-7 days'));
            $dateTo = date('Y-m-d\T23:59:59.999-00:00');

            $params = [
                'seller' => $this->mlClient->getSellerId(),
                'order.date_created.from' => $dateFrom,
                'order.date_created.to' => $dateTo,
                'limit' => 50
            ];

            $response = $this->mlClient->get('/orders/search', $params);

            if ($this->isOrdersCapabilityUnavailable($response)) {
                return [
                    'success' => false,
                    'error' => 'orders_access_unavailable',
                    'feature_unavailable' => true,
                ];
            }

            $orders = $response['results'] ?? [];

            $metrics = $this->calculateSalesMetrics($orders);

            return [
                'success' => true,
                'period' => '7d',
                'metrics' => $metrics
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica se a resposta da API indica que o endpoint de orders é uma capability indisponível
     */
    private function isOrdersCapabilityUnavailable(array $response): bool
    {
        return ($response['error'] ?? null) === 'orders_access_unavailable'
            && ($response['feature'] ?? null) === 'orders'
            && ($response['optional_feature'] ?? false) === true;
    }
}
