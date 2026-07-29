<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Services\Financial\HasFinancialDependencies;

/**
 * Serviço de reputação, visitas, conversão e performance do vendedor.
 * Extraído de FinancialService.
 */
class SellerReputationService
{
    use HasFinancialDependencies;

    /**
     * Obtém reputação do vendedor
     * Endpoint: GET /users/{user_id}
     *
     * @return array Dados de reputação
     */
    public function getSellerReputation(): array
    {
        $client = $this->getClient();

        $sellerId = $client->getSellerId();
        $response = $client->get("/users/{$sellerId}");

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar reputação',
                'data' => null,
            ];
        }

        $reputation = $response['seller_reputation'] ?? [];
        $transactions = $reputation['transactions'] ?? [];
        $metrics = $reputation['metrics'] ?? [];

        return [
            'seller_id' => $sellerId,
            'nickname' => $response['nickname'] ?? null,
            'level' => [
                'id' => $reputation['level_id'] ?? null,
                'power_seller_status' => $reputation['power_seller_status'] ?? null,
                'real_level' => $reputation['real_level'] ?? null,
                'protection_end_date' => $reputation['protection_end_date'] ?? null,
            ],
            'transactions' => [
                'total' => $transactions['total'] ?? 0,
                'completed' => $transactions['completed'] ?? 0,
                'canceled' => $transactions['canceled'] ?? 0,
                'period' => $transactions['period'] ?? null,
                'ratings' => [
                    'positive' => ($transactions['ratings']['positive'] ?? 0) * 100,
                    'neutral' => ($transactions['ratings']['neutral'] ?? 0) * 100,
                    'negative' => ($transactions['ratings']['negative'] ?? 0) * 100,
                ],
            ],
            'metrics' => [
                'sales' => [
                    'period' => $metrics['sales']['period'] ?? null,
                    'completed' => $metrics['sales']['completed'] ?? 0,
                ],
                'claims' => [
                    'period' => $metrics['claims']['period'] ?? null,
                    'rate' => ($metrics['claims']['rate'] ?? 0) * 100,
                    'value' => $metrics['claims']['value'] ?? 0,
                    'excluded' => $metrics['claims']['excluded'] ?? null,
                ],
                'delayed_handling_time' => [
                    'period' => $metrics['delayed_handling_time']['period'] ?? null,
                    'rate' => ($metrics['delayed_handling_time']['rate'] ?? 0) * 100,
                    'value' => $metrics['delayed_handling_time']['value'] ?? 0,
                    'excluded' => $metrics['delayed_handling_time']['excluded'] ?? null,
                ],
                'cancellations' => [
                    'period' => $metrics['cancellations']['period'] ?? null,
                    'rate' => ($metrics['cancellations']['rate'] ?? 0) * 100,
                    'value' => $metrics['cancellations']['value'] ?? 0,
                    'excluded' => $metrics['cancellations']['excluded'] ?? null,
                ],
            ],
            'interpretation' => $this->interpretReputationLevel($reputation['level_id'] ?? null),
        ];
    }

    /**
     * Interpreta o nível de reputação
     */
    private function interpretReputationLevel(?string $levelId): array
    {
        $levels = [
            '5_green' => ['color' => 'verde', 'status' => 'Excelente', 'description' => 'Reputação máxima'],
            '4_light_green' => ['color' => 'verde claro', 'status' => 'Muito Bom', 'description' => 'Acima da média'],
            '3_yellow' => ['color' => 'amarelo', 'status' => 'Bom', 'description' => 'Na média'],
            '2_orange' => ['color' => 'laranja', 'status' => 'Regular', 'description' => 'Abaixo da média'],
            '1_red' => ['color' => 'vermelho', 'status' => 'Ruim', 'description' => 'Precisa melhorar'],
        ];

        return $levels[$levelId] ?? ['color' => 'desconhecido', 'status' => 'N/A', 'description' => 'Nível não identificado'];
    }

    /**
     * Obtém total de visitas de todos os anúncios do vendedor
     * Endpoint: GET /users/{user_id}/items_visits
     *
     * @param string $startDate Data inicial (ISO format)
     * @param string $endDate Data final (ISO format)
     * @return array Total de visitas
     */
    public function getSellerTotalVisits(string $startDate, string $endDate): array
    {
        $client = $this->getClient();

        $sellerId = $client->getSellerId();
        $response = $client->get("/users/{$sellerId}/items_visits", [
            'date_from' => $startDate,
            'date_to' => $endDate,
        ]);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar visitas',
                'data' => null,
            ];
        }

        $visitsDetail = [];
        foreach ($response['visits_detail'] ?? [] as $detail) {
            $visitsDetail[] = [
                'company' => $detail['company'] ?? 'mercadolibre',
                'quantity' => $detail['quantity'] ?? 0,
            ];
        }

        return [
            'seller_id' => $sellerId,
            'period' => [
                'from' => $response['date_from'] ?? $startDate,
                'to' => $response['date_to'] ?? $endDate,
            ],
            'total_visits' => $response['total_visits'] ?? 0,
            'visits_detail' => $visitsDetail,
        ];
    }

    /**
     * Obtém visitas por janela de tempo (detalhamento diário)
     * Endpoint: GET /users/{user_id}/items_visits/time_window
     *
     * @param int $lastDays Últimos N dias
     * @return array Visitas por dia
     */
    public function getSellerVisitsByTimeWindow(int $lastDays = 30): array
    {
        $client = $this->getClient();

        $sellerId = $client->getSellerId();
        $response = $client->get("/users/{$sellerId}/items_visits/time_window", [
            'last' => $lastDays,
            'unit' => 'day',
        ]);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar visitas por período',
                'data' => null,
            ];
        }

        $dailyVisits = [];
        foreach ($response['results'] ?? [] as $result) {
            $dailyVisits[] = [
                'date' => $result['date'] ?? null,
                'total' => $result['total'] ?? 0,
            ];
        }

        return [
            'seller_id' => $sellerId,
            'period' => [
                'from' => $response['date_from'] ?? null,
                'to' => $response['date_to'] ?? null,
            ],
            'total_visits' => $response['total_visits'] ?? 0,
            'last_days' => $lastDays,
            'daily_visits' => $dailyVisits,
            'average_daily' => count($dailyVisits) > 0
                ? round(($response['total_visits'] ?? 0) / count($dailyVisits), 2)
                : 0,
        ];
    }

    /**
     * Obtém visitas de um item específico
     * Endpoint: GET /visits/items?ids={item_id}
     *
     * @param string $itemId ID do item
     * @return array Total de visitas do item
     */
    public function getItemVisitsTotal(string $itemId): array
    {
        $client = $this->getClient();

        $response = $client->get('/visits/items', [
            'ids' => $itemId,
        ]);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar visitas do item',
                'data' => null,
            ];
        }

        return [
            'item_id' => $itemId,
            'total_visits' => $response[$itemId] ?? 0,
        ];
    }

    /**
     * Obtém visitas de um item por período
     * Endpoint: GET /items/visits?ids={item_id}&date_from=&date_to=
     *
     * @param string $itemId ID do item
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Visitas do item no período
     */
    public function getItemVisitsByPeriod(string $itemId, string $startDate, string $endDate): array
    {
        $client = $this->getClient();

        $response = $client->get('/items/visits', [
            'ids' => $itemId,
            'date_from' => $startDate,
            'date_to' => $endDate,
        ]);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar visitas do item',
                'data' => null,
            ];
        }

        // A resposta é um array de objetos
        $itemData = is_array($response) && isset($response[0]) ? $response[0] : $response;

        return [
            'item_id' => $itemData['item_id'] ?? $itemId,
            'period' => [
                'from' => $itemData['date_from'] ?? $startDate,
                'to' => $itemData['date_to'] ?? $endDate,
            ],
            'total_visits' => $itemData['total_visits'] ?? 0,
            'visits_detail' => $itemData['visits_detail'] ?? [],
        ];
    }

    /**
     * Obtém visitas de um item por janela de tempo (detalhado)
     * Endpoint: GET /items/{item_id}/visits/time_window
     *
     * @param string $itemId ID do item
     * @param int $lastDays Últimos N dias
     * @return array Visitas diárias do item
     */
    public function getItemVisitsByTimeWindow(string $itemId, int $lastDays = 30): array
    {
        $client = $this->getClient();

        $response = $client->get("/items/{$itemId}/visits/time_window", [
            'last' => $lastDays,
            'unit' => 'day',
        ]);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar visitas do item',
                'data' => null,
            ];
        }

        $dailyVisits = [];
        foreach ($response['results'] ?? [] as $result) {
            $dailyVisits[] = [
                'date' => $result['date'] ?? null,
                'total' => $result['total'] ?? 0,
            ];
        }

        return [
            'item_id' => $itemId,
            'period' => [
                'from' => $response['date_from'] ?? null,
                'to' => $response['date_to'] ?? null,
            ],
            'total_visits' => $response['total_visits'] ?? 0,
            'last_days' => $lastDays,
            'daily_visits' => $dailyVisits,
        ];
    }

    /**
     * Obtém total de perguntas por período
     * Endpoint: GET /users/{user_id}/contacts/questions
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Total de perguntas
     */
    public function getSellerQuestionsMetrics(string $startDate, string $endDate): array
    {
        $client = $this->getClient();

        $sellerId = $client->getSellerId();
        $response = $client->get("/users/{$sellerId}/contacts/questions", [
            'date_from' => $startDate,
            'date_to' => $endDate,
        ]);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar métricas de perguntas',
                'data' => null,
            ];
        }

        return [
            'seller_id' => $sellerId,
            'period' => [
                'from' => $response['date_from'] ?? $startDate,
                'to' => $response['date_to'] ?? $endDate,
            ],
            'total_questions' => $response['total'] ?? 0,
        ];
    }

    /**
     * Obtém métricas de "ver telefone" do vendedor
     * Endpoint: GET /users/{user_id}/contacts/phone_views
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Total de cliques em ver telefone
     */
    public function getSellerPhoneViewsMetrics(string $startDate, string $endDate): array
    {
        $client = $this->getClient();

        $sellerId = $client->getSellerId();
        $response = $client->get("/users/{$sellerId}/contacts/phone_views", [
            'date_from' => $startDate,
            'date_to' => $endDate,
        ]);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar métricas de telefone',
                'data' => null,
            ];
        }

        return [
            'seller_id' => $sellerId,
            'period' => [
                'from' => $response['date_from'] ?? $startDate,
                'to' => $response['date_to'] ?? $endDate,
            ],
            'total_phone_views' => $response['total'] ?? 0,
        ];
    }

    /**
     * Calcula taxa de conversão (vendas/visitas)
     * Combina dados de visitas com dados de vendas
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Taxa de conversão e métricas
     */
    public function calculateConversionRate(string $startDate, string $endDate): array
    {
        // Buscar visitas
        $visits = $this->getSellerTotalVisits($startDate, $endDate);
        $totalVisits = $visits['total_visits'] ?? 0;

        // Buscar vendas do período
        $client = $this->getClient();
        $sellerId = $client->getSellerId();

        $ordersResponse = $client->get('/orders/search', [
            'seller' => $sellerId,
            'order.date_created.from' => $startDate . 'T00:00:00.000-00:00',
            'order.date_created.to' => $endDate . 'T23:59:59.999-00:00',
            'order.status' => 'paid',
        ]);

        if ($this->isOrdersCapabilityUnavailable($ordersResponse)) {
            return [
                'period' => ['from' => $startDate, 'to' => $endDate],
                'feature_unavailable' => true,
                'error' => 'orders_access_unavailable',
                'total_visits' => $totalVisits,
                'total_sales' => 0,
                'conversion_rate' => 0,
                'conversion_rate_formatted' => '0%',
                'visits_per_sale' => 0,
                'benchmark' => null,
            ];
        }

        $totalSales = $ordersResponse['paging']['total'] ?? 0;

        // Calcular conversão
        $conversionRate = $totalVisits > 0 ? ($totalSales / $totalVisits) * 100 : 0;

        return [
            'period' => ['from' => $startDate, 'to' => $endDate],
            'total_visits' => $totalVisits,
            'total_sales' => $totalSales,
            'conversion_rate' => round($conversionRate, 4),
            'conversion_rate_formatted' => round($conversionRate, 2) . '%',
            'visits_per_sale' => $totalSales > 0 ? round($totalVisits / $totalSales) : 0,
            'benchmark' => $this->getConversionBenchmark($conversionRate),
        ];
    }

    /**
     * Retorna benchmark de conversão
     */
    private function getConversionBenchmark(float $rate): array
    {
        if ($rate >= 5) {
            return ['status' => 'excellent', 'message' => 'Excelente! Acima de 5% é muito bom'];
        }
        if ($rate >= 3) {
            return ['status' => 'good', 'message' => 'Bom! Entre 3-5% é a média do mercado'];
        }
        if ($rate >= 1) {
            return ['status' => 'average', 'message' => 'Na média. Há espaço para melhoria'];
        }
        return ['status' => 'low', 'message' => 'Abaixo da média. Considere otimizar anúncios'];
    }

    /**
     * Gera relatório consolidado de performance do vendedor
     * Combina reputação, visitas, vendas e métricas
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Relatório completo de performance
     */
    public function generateSellerPerformanceReport(string $startDate, string $endDate): array
    {
        // Coletar todas as métricas
        $reputation = $this->getSellerReputation();
        $visits = $this->getSellerVisitsByTimeWindow(30);
        $conversion = $this->calculateConversionRate($startDate, $endDate);

        // Buscar dados financeiros
        $client = $this->getClient();
        $sellerId = $client->getSellerId();

        // Vendas do período
        $ordersResponse = $client->get('/orders/search', [
            'seller' => $sellerId,
            'order.date_created.from' => $startDate . 'T00:00:00.000-00:00',
            'order.date_created.to' => $endDate . 'T23:59:59.999-00:00',
        ]);

        if ($this->isOrdersCapabilityUnavailable($ordersResponse)) {
            return [
                'period' => ['from' => $startDate, 'to' => $endDate],
                'generated_at' => date('Y-m-d H:i:s'),
                'feature_unavailable' => true,
                'error' => 'orders_access_unavailable',
                'reputation' => [
                    'level' => $reputation['level'] ?? null,
                    'power_seller' => $reputation['level']['power_seller_status'] ?? null,
                    'interpretation' => $reputation['interpretation'] ?? null,
                ],
                'sales' => null,
                'conversion' => $conversion,
                'scores' => null,
            ];
        }

        $orders = $ordersResponse['results'] ?? [];
        $totalRevenue = 0;
        $paidOrders = 0;
        $cancelledOrders = 0;

        foreach ($orders as $order) {
            $status = $order['status'] ?? '';
            if ($status === 'paid') {
                $paidOrders++;
                $totalRevenue += (float)($order['total_amount'] ?? 0);
            } elseif ($status === 'cancelled') {
                $cancelledOrders++;
            }
        }

        $totalOrders = count($orders);
        $fulfillmentRate = $totalOrders > 0 ? ($paidOrders / $totalOrders) * 100 : 0;

        return [
            'period' => ['from' => $startDate, 'to' => $endDate],
            'generated_at' => date('Y-m-d H:i:s'),
            'reputation' => [
                'level' => $reputation['level'] ?? null,
                'power_seller' => $reputation['level']['power_seller_status'] ?? null,
                'interpretation' => $reputation['interpretation'] ?? null,
            ],
            'metrics' => [
                'claims_rate' => $reputation['metrics']['claims']['rate'] ?? 0,
                'cancellations_rate' => $reputation['metrics']['cancellations']['rate'] ?? 0,
                'delayed_handling_rate' => $reputation['metrics']['delayed_handling_time']['rate'] ?? 0,
            ],
            'traffic' => [
                'total_visits' => $visits['total_visits'] ?? 0,
                'average_daily_visits' => $visits['average_daily'] ?? 0,
                'visits_trend' => $this->calculateVisitsTrend($visits['daily_visits'] ?? []),
            ],
            'sales' => [
                'total_orders' => $totalOrders,
                'paid_orders' => $paidOrders,
                'cancelled_orders' => $cancelledOrders,
                'total_revenue' => round($totalRevenue, 2),
                'fulfillment_rate' => round($fulfillmentRate, 2),
            ],
            'conversion' => [
                'rate' => $conversion['conversion_rate'] ?? 0,
                'formatted' => $conversion['conversion_rate_formatted'] ?? '0%',
                'benchmark' => $conversion['benchmark'] ?? null,
            ],
            'scores' => $this->calculatePerformanceScores(
                $reputation,
                $conversion,
                $fulfillmentRate
            ),
        ];
    }

    /**
     * Calcula tendência de visitas
     */
    private function calculateVisitsTrend(array $dailyVisits): array
    {
        if (count($dailyVisits) < 2) {
            return ['trend' => 'stable', 'change' => 0];
        }

        $halfCount = (int)(count($dailyVisits) / 2);
        $firstHalf = array_slice($dailyVisits, 0, $halfCount);
        $secondHalf = array_slice($dailyVisits, $halfCount);

        $firstAvg = array_sum(array_column($firstHalf, 'total')) / count($firstHalf);
        $secondAvg = array_sum(array_column($secondHalf, 'total')) / count($secondHalf);

        $change = $firstAvg > 0 ? (($secondAvg - $firstAvg) / $firstAvg) * 100 : 0;

        $trend = 'stable';
        if ($change > 10) {
            $trend = 'increasing';
        } elseif ($change < -10) {
            $trend = 'decreasing';
        }

        return [
            'trend' => $trend,
            'change' => round($change, 2),
        ];
    }

    /**
     * Calcula scores de performance
     */
    private function calculatePerformanceScores(
        array $reputation,
        array $conversion,
        float $fulfillmentRate
    ): array {
        // Score de reputação (0-100)
        $reputationScore = $this->calculateReputationScore($reputation);

        // Score de conversão (0-100)
        $conversionRate = $conversion['conversion_rate'] ?? 0;
        $conversionScore = min(100, ($conversionRate / 5) * 100);

        // Score de fulfillment (0-100)
        $fulfillmentScore = $fulfillmentRate;

        // Score geral (média ponderada)
        $overallScore = ($reputationScore * 0.4) + ($conversionScore * 0.3) + ($fulfillmentScore * 0.3);

        return [
            'reputation_score' => round($reputationScore),
            'conversion_score' => round($conversionScore),
            'fulfillment_score' => round($fulfillmentScore),
            'overall_score' => round($overallScore),
            'grade' => $this->getPerformanceGrade($overallScore),
        ];
    }

    /**
     * Calcula score de reputação
     */
    private function calculateReputationScore(array $reputation): float
    {
        $levelScores = [
            '5_green' => 100,
            '4_light_green' => 80,
            '3_yellow' => 60,
            '2_orange' => 40,
            '1_red' => 20,
        ];

        $level = $reputation['level']['id'] ?? null;
        $baseScore = $levelScores[$level] ?? 50;

        // Penalidades
        $claimsRate = $reputation['metrics']['claims']['rate'] ?? 0;
        $cancellationsRate = $reputation['metrics']['cancellations']['rate'] ?? 0;
        $delayedRate = $reputation['metrics']['delayed_handling_time']['rate'] ?? 0;

        $penalty = ($claimsRate * 2) + ($cancellationsRate * 1.5) + ($delayedRate * 1);

        return max(0, $baseScore - $penalty);
    }

    /**
     * Retorna nota de performance
     */
    private function getPerformanceGrade(float $score): string
    {
        if ($score >= 90) {
            return 'A+';
        }
        if ($score >= 80) {
            return 'A';
        }
        if ($score >= 70) {
            return 'B';
        }
        if ($score >= 60) {
            return 'C';
        }
        if ($score >= 50) {
            return 'D';
        }
        return 'F';
    }

    /**
     * Obtém feedback de vendas
     * Endpoint: GET /orders/{order_id}/feedback
     *
     * @param string $orderId ID da ordem
     * @return array Feedback da venda
     */
    public function getOrderFeedback(string $orderId): array
    {
        $client = $this->getClient();

        $response = $client->get("/orders/{$orderId}/feedback");

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Feedback não encontrado',
                'data' => null,
            ];
        }

        $sale = $response['sale'] ?? null;
        $purchase = $response['purchase'] ?? null;

        return [
            'order_id' => $orderId,
            'sale' => $sale ? [
                'fulfilled' => $sale['fulfilled'] ?? null,
                'rating' => $sale['rating'] ?? null,
                'date_created' => $sale['date_created'] ?? null,
                'message' => $sale['message'] ?? null,
            ] : null,
            'purchase' => $purchase ? [
                'fulfilled' => $purchase['fulfilled'] ?? null,
                'rating' => $purchase['rating'] ?? null,
                'date_created' => $purchase['date_created'] ?? null,
                'message' => $purchase['message'] ?? null,
            ] : null,
        ];
    }

    /**
     * Obtém opiniões de um produto
     * Endpoint: GET /reviews/item/{item_id}
     *
     * @param string $itemId ID do item
     * @param int $limit Limite de resultados
     * @return array Opiniões do produto
     */
    public function getProductReviews(string $itemId, int $limit = 50): array
    {
        $client = $this->getClient();

        $response = $client->get("/reviews/item/{$itemId}", [
            'limit' => $limit,
        ]);

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Opiniões não encontradas',
                'data' => null,
            ];
        }

        $reviews = [];
        foreach ($response['reviews'] ?? [] as $review) {
            $reviews[] = [
                'id' => $review['id'] ?? null,
                'rate' => $review['rate'] ?? null,
                'title' => $review['title'] ?? null,
                'content' => $review['content'] ?? null,
                'date_created' => $review['date_created'] ?? null,
                'likes' => $review['likes'] ?? 0,
                'dislikes' => $review['dislikes'] ?? 0,
                'relevance' => $review['relevance'] ?? null,
            ];
        }

        $rating = $response['rating_average'] ?? 0;
        $ratingLevels = $response['rating_levels'] ?? [];

        return [
            'item_id' => $itemId,
            'summary' => [
                'total_reviews' => $response['paging']['total'] ?? count($reviews),
                'rating_average' => round($rating, 2),
                'rating_levels' => [
                    '5_stars' => $ratingLevels['five_star'] ?? 0,
                    '4_stars' => $ratingLevels['four_star'] ?? 0,
                    '3_stars' => $ratingLevels['three_star'] ?? 0,
                    '2_stars' => $ratingLevels['two_star'] ?? 0,
                    '1_star' => $ratingLevels['one_star'] ?? 0,
                ],
            ],
            'reviews' => $reviews,
        ];
    }

    /**
     * Calcula LTV (Lifetime Value) estimado por cliente
     * Baseado no histórico de vendas
     *
     * @param int $months Meses para análise
     * @return array Métricas de LTV
     */
    public function calculateCustomerLTV(int $months = 12): array
    {
        $client = $this->getClient();
        $sellerId = $client->getSellerId();

        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$months} months"));

        // Buscar todas as vendas do período
        $ordersResponse = $client->get('/orders/search', [
            'seller' => $sellerId,
            'order.date_created.from' => $startDate . 'T00:00:00.000-00:00',
            'order.date_created.to' => $endDate . 'T23:59:59.999-00:00',
            'order.status' => 'paid',
            'limit' => 50,
        ]);

        if ($this->isOrdersCapabilityUnavailable($ordersResponse)) {
            return [
                'period_months' => $months,
                'period' => ['from' => $startDate, 'to' => $endDate],
                'feature_unavailable' => true,
                'error' => 'orders_access_unavailable',
                'metrics' => null,
                'averages' => null,
                'ltv' => null,
                'insights' => [],
            ];
        }

        $orders = $ordersResponse['results'] ?? [];
        $totalOrders = $ordersResponse['paging']['total'] ?? count($orders);

        // Agrupar por comprador
        $buyerPurchases = [];
        $totalRevenue = 0;

        foreach ($orders as $order) {
            $buyerId = $order['buyer']['id'] ?? 'unknown';
            $amount = (float)($order['total_amount'] ?? 0);

            if (!isset($buyerPurchases[$buyerId])) {
                $buyerPurchases[$buyerId] = ['count' => 0, 'total' => 0];
            }

            $buyerPurchases[$buyerId]['count']++;
            $buyerPurchases[$buyerId]['total'] += $amount;
            $totalRevenue += $amount;
        }

        $uniqueCustomers = count($buyerPurchases);
        $repeatCustomers = count(array_filter($buyerPurchases, fn(array $b): bool => $b['count'] > 1));

        // Calcular métricas
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        $avgPurchaseFrequency = $uniqueCustomers > 0 ? $totalOrders / $uniqueCustomers : 0;
        $customerValue = $avgOrderValue * $avgPurchaseFrequency;

        // LTV estimado (12 meses)
        $estimatedLTV = $customerValue * ($months / 12);

        return [
            'period_months' => $months,
            'period' => ['from' => $startDate, 'to' => $endDate],
            'metrics' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_orders' => $totalOrders,
                'unique_customers' => $uniqueCustomers,
                'repeat_customers' => $repeatCustomers,
                'repeat_rate' => $uniqueCustomers > 0
                    ? round(($repeatCustomers / $uniqueCustomers) * 100, 2)
                    : 0,
            ],
            'averages' => [
                'order_value' => round($avgOrderValue, 2),
                'purchase_frequency' => round($avgPurchaseFrequency, 2),
                'customer_value' => round($customerValue, 2),
            ],
            'ltv' => [
                'estimated_12_months' => round($estimatedLTV, 2),
                'currency' => 'BRL',
            ],
            'insights' => $this->getLTVInsights($avgOrderValue, $avgPurchaseFrequency, $repeatCustomers / max(1, $uniqueCustomers)),
        ];
    }

    /**
     * Gera insights de LTV
     */
    private function getLTVInsights(float $aov, float $frequency, float $repeatRate): array
    {
        $insights = [];

        if ($aov < 50) {
            $insights[] = 'Ticket médio baixo. Considere upselling ou bundles';
        } elseif ($aov > 200) {
            $insights[] = 'Ticket médio alto. Foque em fidelização';
        }

        if ($frequency < 1.2) {
            $insights[] = 'Frequência de compra baixa. Implemente programas de recompra';
        }

        if ($repeatRate < 0.1) {
            $insights[] = 'Taxa de recompra muito baixa. Foque em pós-venda e follow-up';
        } elseif ($repeatRate > 0.3) {
            $insights[] = 'Ótima taxa de recompra! Mantenha a qualidade';
        }

        return $insights;
    }

    /**
     * Verifica se a resposta da API indica que a capability de orders não está disponível
     */
    private function isOrdersCapabilityUnavailable(array $response): bool
    {
        return isset($response['error'])
            && $response['error'] === 'orders_access_unavailable'
            && ($response['feature'] ?? null) === 'orders'
            && ($response['optional_feature'] ?? false) === true;
    }
}
