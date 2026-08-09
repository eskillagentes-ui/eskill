<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * TrendsService - Análise de Tendências de Mercado
 *
 * Analisa tendências, sazonalidade e produtos em alta
 * - Produtos mais buscados
 * - Análise de sazonalidade
 * - Oportunidades de mercado
 * - Previsão de demanda
 *
 * @link https://developers.mercadolivre.com.br/pt_br/trends
 */
class TrendsService extends MercadoLivreClient
{
    private PDO $db;

    public function __construct(?int $accountId = null)
    {
        parent::__construct($accountId);
        $this->db = Database::getInstance();
    }

    /**
     * Obtém tendências por categoria
     *
     * @param string $categoryId ID da categoria
     * @param array $filters Filtros
     * @return array Tendências
     */
    public function getCategoryTrends(string $categoryId, array $filters = []): array
    {
        try {
            $siteId = $filters['site_id'] ?? 'MLB';
            $response = $this->get("/trends/{$siteId}/{$categoryId}");

            if (isset($response['error'])) {
                return $this->getEmptyTrends();
            }

            return [
                'category_id' => $categoryId,
                'keywords' => $this->formatKeywords($response['keywords'] ?? []),
                'top_products' => $this->formatTopProducts($response['top_products'] ?? []),
                'trend_score' => $response['trend_score'] ?? 0,
                'growth_rate' => $response['growth_rate'] ?? 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            log_error('Erro ao obter tendências de categoria', [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return $this->getEmptyTrends();
        }
    }

    /**
     * Retorna as keywords mais buscadas (quando houver base local em `market_keywords`).
     *
     * Observação: o ML não expõe um endpoint oficial simples de "top searches" para todos
     * os casos; por isso usamos a base interna do projeto quando disponível.
     */
    public function getTopSearches(array $filters = []): array
    {
        $limit = (int)($filters['limit'] ?? 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $categoryId = $filters['category_id'] ?? null;
        if ($categoryId !== null && !is_string($categoryId)) {
            $categoryId = null;
        }

        try {
            $sql = "SELECT keyword, search_volume"
                . " FROM market_keywords"
                . " WHERE search_volume IS NOT NULL";

            $params = [];
            if (is_string($categoryId) && $categoryId !== '') {
                $sql .= " AND category_id = :category_id";
                $params['category_id'] = $categoryId;
            }

            $sql .= " ORDER BY search_volume DESC";
            $sql .= " LIMIT :limit";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'total' => count($rows),
                'keywords' => $this->formatKeywords($rows),
                'category_id' => $categoryId,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            log_error('Erro ao obter top searches', [
                'category_id' => $categoryId,
                'limit' => $limit,
                'error' => $e->getMessage(),
            ]);

            return [
                'total' => 0,
                'keywords' => [],
                'category_id' => $categoryId,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    /**
     * Alias semântico para análise de sazonalidade.
     */
    public function getSeasonalTrends(string $query, array $options = []): array
    {
        return $this->analyzeSeasonality($query, $options);
    }

    /**
     * Obtém produtos em alta
     *
     * @param array $filters Filtros
     * @return array Produtos populares
     */
    public function getHotProducts(array $filters = []): array
    {
        try {
            $siteId = $filters['site_id'] ?? 'MLB';
            $categoryId = $filters['category_id'] ?? null;
            $limit = $filters['limit'] ?? 20;

            $endpoint = $categoryId
                ? "/sites/{$siteId}/hot_items/{$categoryId}"
                : "/sites/{$siteId}/hot_items";

            $response = $this->get($endpoint, ['limit' => $limit]);

            if (isset($response['error'])) {
                return ['total' => 0, 'products' => []];
            }

            return [
                'total' => count($response['results'] ?? []),
                'products' => $this->formatHotProducts($response['results'] ?? []),
                'category_id' => $categoryId,
                'timestamp' => time(),
            ];
        } catch (\Exception $e) {
            log_warning('Erro ao buscar produtos em alta', [
                'service' => 'TrendsService',
                'filters' => $filters,
                'error' => $e->getMessage(),
            ]);
            return ['total' => 0, 'products' => []];
        }
    }

    /**
     * Analisa sazonalidade de produto/categoria
     * Usa dados históricos reais de vendas quando disponíveis
     *
     * @param string $query Busca ou categoria
     * @param array $options Opções
     * @return array Análise de sazonalidade
     */
    public function analyzeSeasonality(string $query, array $options = []): array
    {
        try {
            $months = [];
            $useRealData = false;

            // 1. Primeiro tentar obter dados históricos reais de vendas
            $historicalData = $this->getRealSeasonalityData($query);

            if (!empty($historicalData)) {
                $useRealData = true;
                $months = $historicalData;
            } else {
                // 2. Fallback: usar volume de keywords com fatores sazonais conhecidos
                $stmt = $this->db->prepare("
                    SELECT search_volume
                    FROM market_keywords
                    WHERE keyword LIKE :query
                    ORDER BY search_volume DESC
                    LIMIT 1
                ");
                $stmt->execute(['query' => "%{$query}%"]);
                $realVolume = $stmt->fetchColumn();

                $baseVolume = $realVolume ? (int)$realVolume : 5000;

                for ($i = 11; $i >= 0; $i--) {
                    $month = date('Y-m', strtotime("-{$i} months"));
                    $monthNum = (int)date('m', strtotime("-{$i} months"));

                    $seasonalFactor = $this->getSeasonalFactor($monthNum, $query);
                    $volume = round($baseVolume * $seasonalFactor);

                    $months[] = [
                        'month' => $month,
                        // Sinal de magnitude do mês; ver 'data_source' no retorno de
                        // analyzeSeasonality() para saber se veio de vendas reais ou estimativa.
                        'volume_signal' => $volume,
                        'seasonal_factor' => round($seasonalFactor, 2),
                    ];
                }
            }

            // Detectar padrão baseado nos dados
            $pattern = $this->detectSeasonalPattern($months);

            return [
                'query' => $query,
                'data_source' => $useRealData ? 'historical_sales' : 'estimated',
                'months' => $months,
                'pattern' => $pattern,
                'peak_months' => $this->findPeakMonths($months),
                'low_months' => $this->findLowMonths($months),
                'recommendation' => $this->getSeasonalRecommendation($pattern),
                'year_over_year' => $useRealData ? $this->calculateYoYGrowth($months) : null,
            ];
        } catch (\Exception $e) {
            log_error('Erro ao analisar sazonalidade', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [
                'query' => $query,
                'months' => [],
                'pattern' => 'unknown',
            ];
        }
    }

    /**
     * Estima volume de busca para uma keyword com base em dados disponíveis.
     *
     * Observação: não existe um endpoint oficial de "search volume" no ML.
     * Aqui usamos a análise de sazonalidade (dados históricos quando houver, caso
     * contrário estimativa baseada em market_keywords) e derivamos um volume e trend.
     */
    public function estimateVolume(string $keyword, string $categoryId): array
    {
        $seasonality = $this->analyzeSeasonality($keyword, ['category_id' => $categoryId]);
        $months = $seasonality['months'] ?? [];

        if (!is_array($months) || empty($months)) {
            return [
                'volume' => 0,
                'confidence' => 'none',
                'trend' => 'unknown',
            ];
        }

        $last = $months[count($months) - 1] ?? [];
        $volume = (int)($last['volume_signal'] ?? 0);

        // Trend simples: comparar média dos últimos 3 meses vs 3 anteriores
        $trend = 'stable';
        if (count($months) >= 6) {
            $recent = array_slice($months, -3);
            $prev = array_slice($months, -6, 3);

            $recentAvg = array_sum(array_column($recent, 'volume_signal')) / max(1, count($recent));
            $prevAvg = array_sum(array_column($prev, 'volume_signal')) / max(1, count($prev));

            if ($prevAvg > 0) {
                $delta = (($recentAvg - $prevAvg) / $prevAvg) * 100;
                if ($delta > 10) {
                    $trend = 'growing';
                } elseif ($delta < -10) {
                    $trend = 'declining';
                }
            }
        }

        $confidence = ($seasonality['data_source'] ?? 'estimated') === 'historical_sales' ? 'medium' : 'low';

        return [
            'volume' => $volume,
            'confidence' => $confidence,
            'trend' => $trend,
        ];
    }

    /**
     * Obtém dados de sazonalidade reais do histórico de vendas
     */
    private function getRealSeasonalityData(string $query): array
    {
        try {
            // Buscar vendas agregadas por mês para itens que correspondem à query
            $stmt = $this->db->prepare("
                SELECT
                    DATE_FORMAT(o.date_created, '%Y-%m') as month,
                    SUM(oi.quantity) as total_sales,
                    COUNT(DISTINCT o.id) as order_count,
                    AVG(oi.unit_price) as avg_price
                FROM order_items oi
                JOIN ml_orders o ON (oi.order_id = o.id OR oi.order_id = o.ml_order_id)
                JOIN items i ON oi.item_id = i.ml_item_id
                WHERE o.ml_account_id = :account_id
                AND o.status NOT IN ('cancelled', 'refunded')
                AND o.date_created >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                AND (i.title LIKE :query OR i.category_id LIKE :query2)
                GROUP BY DATE_FORMAT(o.date_created, '%Y-%m')
                ORDER BY month ASC
            ");

            $stmt->execute([
                'account_id' => $this->accountId,
                'query' => "%{$query}%",
                'query2' => "%{$query}%"
            ]);

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($results) < 3) {
                // Dados insuficientes, tentar busca mais ampla
                return $this->getAggregatedSeasonalityData();
            }

            // Calcular fator sazonal baseado na média
            $totalSales = array_sum(array_column($results, 'total_sales'));
            $avgMonthlySales = $totalSales / count($results);

            return array_map(function (array $row) use ($avgMonthlySales): array {
                $factor = $avgMonthlySales > 0
                    ? round($row['total_sales'] / $avgMonthlySales, 2)
                    : 1.0;

                return [
                    'month' => $row['month'],
                    // Correção de metodologia: era chamado de "search_volume" mas o dado real
                    // é histórico de vendas (total_sales), não busca. Renomeado para não sugerir
                    // medição de demanda de pesquisa que o ML não expõe.
                    'volume_signal' => (int)$row['total_sales'],
                    'order_count' => (int)$row['order_count'],
                    'avg_price' => round((float)$row['avg_price'], 2),
                    'seasonal_factor' => $factor,
                ];
            }, $results);
        } catch (\Exception $e) {
            log_warning('Erro ao buscar sazonalidade real do histórico', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Obtém dados agregados de sazonalidade de todas as vendas
     */
    private function getAggregatedSeasonalityData(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    DATE_FORMAT(date_created, '%Y-%m') as month,
                    SUM(total_amount) as total_revenue,
                    COUNT(*) as order_count
                FROM ml_orders
                WHERE ml_account_id = :account_id
                AND status NOT IN ('cancelled', 'refunded')
                AND date_created >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(date_created, '%Y-%m')
                ORDER BY month ASC
            ");

            $stmt->execute(['account_id' => $this->accountId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($results) < 3) {
                return [];
            }

            $totalRevenue = array_sum(array_column($results, 'total_revenue'));
            $avgMonthlyRevenue = $totalRevenue / count($results);

            return array_map(function (array $row) use ($avgMonthlyRevenue): array {
                $factor = $avgMonthlyRevenue > 0
                    ? round($row['total_revenue'] / $avgMonthlyRevenue, 2)
                    : 1.0;

                return [
                    'month' => $row['month'],
                    // Correção de metodologia: dado real é contagem de pedidos, não busca.
                    'volume_signal' => (int)$row['order_count'],
                    'revenue' => round((float)$row['total_revenue'], 2),
                    'seasonal_factor' => $factor,
                ];
            }, $results);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Calcula crescimento ano sobre ano
     */
    private function calculateYoYGrowth(array $months): ?array
    {
        if (count($months) < 12) {
            return null;
        }

        // Comparar últimos 6 meses com mesmos meses do ano anterior
        $recentMonths = array_slice($months, -6);
        $recentTotal = array_sum(array_column($recentMonths, 'volume_signal'));

        // Buscar dados do mesmo período do ano anterior
        try {
            $stmt = $this->db->prepare("
                SELECT SUM(total_amount) as total
                FROM ml_orders
                WHERE ml_account_id = :account_id
                AND status NOT IN ('cancelled', 'refunded')
                AND date_created >= DATE_SUB(NOW(), INTERVAL 18 MONTH)
                AND date_created < DATE_SUB(NOW(), INTERVAL 12 MONTH)
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $previousTotal = (float)$stmt->fetchColumn();

            if ($previousTotal > 0) {
                $growthRate = (($recentTotal - $previousTotal) / $previousTotal) * 100;
                return [
                    'growth_rate' => round($growthRate, 2),
                    'trend' => $growthRate > 0 ? 'growing' : ($growthRate < -5 ? 'declining' : 'stable'),
                    'recent_period_total' => $recentTotal,
                    'previous_period_total' => $previousTotal,
                ];
            }
        } catch (\Exception $e) {
            log_warning('Erro ao calcular crescimento YoY', [
                'service' => 'TrendsService',
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Identifica oportunidades de mercado
     *
     * @param array $criteria Critérios
     * @return array Oportunidades
     */
    public function findMarketOpportunities(array $criteria = []): array
    {
        try {
            $minDemand = $criteria['min_demand'] ?? 1000;
            $maxCompetition = $criteria['max_competition'] ?? 50;
            $categoryId = $criteria['category_id'] ?? null;

            // Query produtos com alta demanda e baixa concorrência
            $stmt = $this->db->prepare("
                SELECT
                    keyword,
                    search_volume,
                    competition_level,
                    avg_price,
                    (search_volume / NULLIF(competition_level, 0)) as opportunity_score
                FROM market_keywords
                WHERE search_volume >= :min_demand
                AND competition_level <= :max_competition
                " . ($categoryId ? "AND category_id = :category_id" : "") . "
                ORDER BY opportunity_score DESC
                LIMIT 20
            ");

            $params = [
                'min_demand' => $minDemand,
                'max_competition' => $maxCompetition,
            ];

            if ($categoryId) {
                $params['category_id'] = $categoryId;
            }

            $stmt->execute($params);
            $opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'total' => count($opportunities),
                'opportunities' => array_map(function (array $opp): array {
                    return [
                        'keyword' => $opp['keyword'],
                        'search_volume' => $opp['search_volume'],
                        'competition_level' => $opp['competition_level'],
                        'avg_price' => round($opp['avg_price'] ?? 0, 2),
                        'opportunity_score' => round($opp['opportunity_score'] ?? 0, 2),
                        'recommendation' => $this->getOpportunityRecommendation($opp),
                    ];
                }, $opportunities),
                'criteria' => $criteria,
            ];
        } catch (\Exception $e) {
            log_error('Erro ao buscar oportunidades de mercado', [
                'error' => $e->getMessage(),
            ]);
            return ['total' => 0, 'opportunities' => []];
        }
    }

    /**
     * Previsão de demanda
     *
     * @param string $itemId ID do item ou keyword
     * @param int $daysAhead Dias à frente
     * @return array Previsão
     */
    public function forecastDemand(string $itemId, int $daysAhead = 30): array
    {
        try {
            // Buscar histórico
            $stmt = $this->db->prepare("
                SELECT
                    date,
                    visits,
                    sales
                FROM item_metrics_history
                WHERE item_id = :item_id
                AND account_id = :account_id
                ORDER BY date DESC
                LIMIT 90
            ");

            $stmt->execute([
                'item_id' => $itemId,
                'account_id' => $this->accountId,
            ]);

            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($history)) {
                return [
                    'item_id' => $itemId,
                    'forecast' => [],
                    'confidence' => 'low',
                ];
            }

            // Calcular média móvel simples
            $avgVisits = array_sum(array_column($history, 'visits')) / count($history);
            $avgSales = array_sum(array_column($history, 'sales')) / count($history);

            // Gerar previsão
            $forecast = [];
            for ($i = 1; $i <= $daysAhead; $i++) {
                $date = date('Y-m-d', strtotime("+{$i} days"));
                $dayOfWeek = date('N', strtotime($date));
                $weekendFactor = in_array($dayOfWeek, [6, 7]) ? 1.2 : 1.0;

                $forecast[] = [
                    'date' => $date,
                    'predicted_visits' => round($avgVisits * $weekendFactor),
                    'predicted_sales' => round($avgSales * $weekendFactor, 1),
                    'confidence' => $this->calculateConfidence(count($history)),
                ];
            }

            return [
                'item_id' => $itemId,
                'forecast' => $forecast,
                'historical_avg_visits' => round($avgVisits),
                'historical_avg_sales' => round($avgSales, 1),
                'confidence' => $this->calculateConfidence(count($history)),
            ];
        } catch (\Exception $e) {
            log_error('Erro ao prever demanda', [
                'item_id' => $itemId,
                'days_ahead' => $daysAhead,
                'error' => $e->getMessage(),
            ]);
            return [
                'item_id' => $itemId,
                'forecast' => [],
                'confidence' => 'low',
            ];
        }
    }

    // ==================== MÉTODOS PRIVADOS ====================

    private function getSeasonalFactor(int $month, string $query): float
    {
        // Padrões sazonais comuns (simplificado)
        $patterns = [
            'natal|christmas' => [11 => 2.5, 12 => 3.0, 1 => 1.2],
            'verão|praia|biquini' => [11 => 1.5, 12 => 2.5, 1 => 3.0, 2 => 2.0],
            'inverno|jaqueta|casaco' => [5 => 2.0, 6 => 2.5, 7 => 2.8, 8 => 2.0],
            'volta às aulas|mochila' => [1 => 2.5, 2 => 3.0, 7 => 1.5],
            'dia das mães' => [4 => 2.0, 5 => 3.5],
            'dia dos pais' => [7 => 2.0, 8 => 3.0],
            'black friday' => [11 => 3.5],
        ];

        foreach ($patterns as $pattern => $months) {
            if (preg_match("/{$pattern}/i", $query)) {
                return $months[$month] ?? 1.0;
            }
        }

        return 1.0;
    }

    private function detectSeasonalPattern(array $months): string
    {
        $volumes = array_column($months, 'volume_signal');
        $maxVolume = max($volumes);
        $minVolume = min($volumes);
        $variance = $maxVolume / ($minVolume ?: 1);

        if ($variance > 2.0) return 'high_seasonality';
        if ($variance > 1.5) return 'moderate_seasonality';
        return 'low_seasonality';
    }

    private function findPeakMonths(array $months): array
    {
        $sorted = $months;
        usort($sorted, fn($a, $b) => $b['volume_signal'] <=> $a['volume_signal']);

        return array_slice(array_map(fn(array $m): array => [
            'month' => date('F', strtotime($m['month'])),
            'volume' => $m['volume_signal'],
        ], $sorted), 0, 3);
    }

    private function findLowMonths(array $months): array
    {
        $sorted = $months;
        usort($sorted, fn($a, $b) => $a['volume_signal'] <=> $b['volume_signal']);

        return array_slice(array_map(fn(array $m): array => [
            'month' => date('F', strtotime($m['month'])),
            'volume' => $m['volume_signal'],
        ], $sorted), 0, 3);
    }

    private function getSeasonalRecommendation(string $pattern): string
    {
        $recommendations = [
            'high_seasonality' => 'Estoque alto nos picos, reduza fora de temporada',
            'moderate_seasonality' => 'Ajuste estoque moderadamente conforme sazonalidade',
            'low_seasonality' => 'Mantenha estoque constante ao longo do ano',
        ];

        return $recommendations[$pattern] ?? 'Análise detalhada necessária';
    }

    private function getOpportunityRecommendation(array $opp): string
    {
        $score = $opp['opportunity_score'] ?? 0;

        if ($score > 100) return 'Excelente oportunidade - Alta demanda, baixa concorrência';
        if ($score > 50) return 'Boa oportunidade - Considere investir';
        if ($score > 20) return 'Oportunidade moderada - Avalie cuidadosamente';

        return 'Oportunidade limitada';
    }

    private function calculateConfidence(int $dataPoints): string
    {
        if ($dataPoints >= 60) return 'high';
        if ($dataPoints >= 30) return 'medium';
        return 'low';
    }

    private function formatKeywords(array $keywords): array
    {
        return array_map(function (array $kw): array {
            return [
                'keyword' => $kw['keyword'] ?? $kw,
                'search_volume' => $kw['search_volume'] ?? 0,
                'trend' => $kw['trend'] ?? 'stable',
            ];
        }, $keywords);
    }

    private function formatTopProducts(array $products): array
    {
        return array_map(function (array $p): array {
            return [
                'id' => $p['id'],
                'title' => $p['title'],
                'price' => $p['price'],
                'sold_quantity' => $p['sold_quantity'] ?? 0,
            ];
        }, $products);
    }

    private function formatHotProducts(array $products): array
    {
        return array_map(function (array $p): array {
            return [
                'id' => $p['id'],
                'title' => $p['title'],
                'price' => $p['price'],
                'thumbnail' => $p['thumbnail'] ?? null,
                'permalink' => $p['permalink'] ?? null,
                'trend_score' => $p['trend_score'] ?? 0,
            ];
        }, $products);
    }

    private function getEmptyTrends(): array
    {
        return [
            'keywords' => [],
            'top_products' => [],
            'trend_score' => 0,
            'growth_rate' => 0,
        ];
    }
}
