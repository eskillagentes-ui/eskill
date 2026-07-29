<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use PDO;

/**
 * 📈 Advanced Market Analytics
 *
 * Análise preditiva e tendências de mercado:
 * - Previsão de demanda
 * - Detecção de sazonalidade
 * - Análise de sentimento
 * - Recomendações baseadas em IA
 *
 * @version 1.0.0
 */
class MarketAnalytics
{
    private PDO $db;
    private int $accountId;
    private ?MercadoLivreClient $mlClient = null;

    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->mlClient = new MercadoLivreClient($accountId);
    }

    /**
     * Prevê demanda para os próximos 30 dias
     *
     * Usa média móvel e tendência linear
     *
     * @param string $categoryId Categoria ML
     * @return array Previsões diárias
     */
    public function predictDemand(string $categoryId): array
    {
        // Buscar histórico de vendas dos últimos 90 dias
        $stmt = $this->db->prepare("
            SELECT
                DATE(pm.metric_date) as date,
                SUM(COALESCE(pm.sold_quantity, 0)) as sales
            FROM seo_performance_metrics pm
            JOIN items i ON i.ml_item_id = pm.item_id
            WHERE pm.account_id = :account_id
              AND i.category_id = :category_id
              AND pm.metric_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY DATE(pm.metric_date)
            ORDER BY date ASC
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'category_id' => $categoryId,
        ]);
        $historicalData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($historicalData)) {
            return [
                'predictions' => [],
                'confidence' => 0,
                'error' => 'Dados insuficientes para previsão',
            ];
        }

        // Calcular média móvel (7 dias)
        $movingAverage = $this->calculateMovingAverage($historicalData, 7);

        // Calcular tendência linear
        $trend = $this->calculateLinearTrend($historicalData);

        // Gerar previsões para próximos 30 dias
        $predictions = [];
        $lastValue = end($historicalData)['sales'];
        $baseDate = new \DateTime();

        for ($i = 1; $i <= 30; $i++) {
            $futureDate = clone $baseDate;
            $futureDate->modify("+{$i} days");

            // Previsão = última média móvel + (tendência * dias)
            $predicted = $movingAverage + ($trend * $i);

            // Adicionar fator de sazonalidade (se detectado)
            $seasonalityFactor = $this->getSeasonalityFactor($futureDate, $historicalData);
            $predicted *= $seasonalityFactor;

            $predictions[] = [
                'date' => $futureDate->format('Y-m-d'),
                'predicted_sales' => max(0, round($predicted, 2)),
                'confidence' => $this->calculateConfidence($i, count($historicalData)),
            ];
        }

        return [
            'predictions' => $predictions,
            'trend' => $trend > 0 ? 'growing' : ($trend < 0 ? 'declining' : 'stable'),
            'trend_value' => round($trend, 4),
            'confidence' => $this->calculateOverallConfidence($historicalData),
            'historical_data' => $historicalData,
        ];
    }

    /**
     * Detecta padrões de sazonalidade
     *
     * @param string $categoryId
     * @return array Análise de sazonalidade
     */
    public function detectSeasonality(string $categoryId): array
    {
        // Buscar dados de 12 meses para análise sazonal
        $stmt = $this->db->prepare("
            SELECT
                MONTH(pm.metric_date) as month,
                YEAR(pm.metric_date) as year,
                AVG(COALESCE(pm.sold_quantity, 0)) as avg_sales
            FROM seo_performance_metrics pm
            JOIN items i ON i.ml_item_id = pm.item_id
            WHERE pm.account_id = :account_id
              AND i.category_id = :category_id
              AND pm.metric_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY YEAR(pm.metric_date), MONTH(pm.metric_date)
            ORDER BY year, month
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'category_id' => $categoryId,
        ]);
        $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($monthlyData) < 6) {
            return [
                'has_seasonality' => false,
                'reason' => 'Dados insuficientes (mínimo 6 meses)',
            ];
        }

        // Calcular média geral
        $overallAvg = array_sum(array_column($monthlyData, 'avg_sales')) / count($monthlyData);

        // Calcular índice sazonal por mês
        $seasonalIndices = [];
        foreach ($monthlyData as $data) {
            $month = (int)$data['month'];
            if (!isset($seasonalIndices[$month])) {
                $seasonalIndices[$month] = [];
            }
            $seasonalIndices[$month][] = $data['avg_sales'] / $overallAvg;
        }

        // Calcular índice médio por mês
        $avgSeasonalIndices = [];
        $monthNames = [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Março',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro'
        ];

        foreach ($seasonalIndices as $month => $indices) {
            $avgIndex = array_sum($indices) / count($indices);
            $avgSeasonalIndices[] = [
                'month' => $month,
                'month_name' => $monthNames[$month],
                'index' => round($avgIndex, 3),
                'variation' => round(($avgIndex - 1) * 100, 1) . '%',
            ];
        }

        // Detectar se há sazonalidade significativa (variação > 15%)
        $maxIndex = max(array_column($avgSeasonalIndices, 'index'));
        $minIndex = min(array_column($avgSeasonalIndices, 'index'));
        $hasSignificantSeasonality = (($maxIndex - $minIndex) / $minIndex) > 0.15;

        // Identificar meses de pico e baixa
        usort($avgSeasonalIndices, fn($a, $b) => $b['index'] <=> $a['index']);

        return [
            'has_seasonality' => $hasSignificantSeasonality,
            'peak_months' => array_slice($avgSeasonalIndices, 0, 3),
            'low_months' => array_slice(array_reverse($avgSeasonalIndices), 0, 3),
            'all_months' => $avgSeasonalIndices,
            'variation_range' => round((($maxIndex - $minIndex) / $minIndex) * 100, 1) . '%',
        ];
    }

    /**
     * Analisa oportunidades de mercado emergentes
     *
     * @return array Lista de oportunidades detectadas
     */
    public function detectEmergingOpportunities(): array
    {
        $opportunities = [];

        // 1. Categorias com crescimento acelerado (>20% últimos 30 dias)
        $stmt = $this->db->prepare("
            SELECT
                i.category_id,
                COUNT(DISTINCT i.id) as products_count,
                SUM(COALESCE(pm.sold_quantity, 0)) as sales_growth,
                AVG(COALESCE(pm.views, 0)) as avg_views_growth
            FROM items i
            JOIN seo_performance_metrics pm ON pm.item_id = i.ml_item_id
            WHERE i.account_id = :account_id
              AND pm.metric_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY i.category_id
            HAVING sales_growth > 0
            ORDER BY sales_growth DESC
            LIMIT 5
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $growingCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($growingCategories as $cat) {
            if ($cat['sales_growth'] > 10) { // Crescimento significativo
                $opportunities[] = [
                    'type' => 'growing_category',
                    'priority' => 'high',
                    'category_id' => $cat['category_id'],
                    'title' => '📈 Categoria em Crescimento',
                    'description' => "Categoria com +{$cat['sales_growth']} vendas nos últimos 30 dias",
                    'action' => 'Adicionar mais produtos nesta categoria',
                    'potential_revenue' => $cat['sales_growth'] * 150, // Estimativa
                ];
            }
        }

        // 2. Gaps de preço (concorrentes muito mais caros)
        $stmt = $this->db->prepare("
            SELECT
                i.category_id,
                i.price as my_price,
                AVG(CAST(cw.current_price AS DECIMAL(10,2))) as competitor_avg_price,
                COUNT(cw.id) as competitor_count
            FROM items i
            JOIN competitor_watchlist cw ON cw.category_id = i.category_id
            WHERE i.account_id = :account_id
              AND cw.account_id = :account_id
              AND cw.status = 'active'
            GROUP BY i.category_id, i.price
            HAVING competitor_avg_price > (my_price * 1.3)
            LIMIT 5
        ");
        $stmt->execute(['account_id' => $this->accountId]);
        $priceGaps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($priceGaps as $gap) {
            $priceDiff = $gap['competitor_avg_price'] - $gap['my_price'];
            $opportunities[] = [
                'type' => 'price_gap',
                'priority' => 'medium',
                'category_id' => $gap['category_id'],
                'title' => '💰 Oportunidade de Repricing',
                'description' => "Concorrentes cobram em média R$ {$priceDiff} a mais",
                'action' => 'Considere aumentar preço ou destacar valor agregado',
                'potential_revenue' => $priceDiff * 10, // Estimativa
            ];
        }

        // 3. Keywords inexploradas - buscar via ML Trends API e análise de competidores
        $keywordOpportunities = $this->discoverKeywordOpportunities();
        if (!empty($keywordOpportunities)) {
            $opportunities[] = [
                'type' => 'keyword_opportunity',
                'priority' => 'medium',
                'title' => '🔑 Keywords de Alto Potencial',
                'description' => 'Identificamos keywords com alto volume de busca via Trends do Mercado Livre',
                'action' => 'Otimizar títulos com estas keywords',
                'keywords' => $keywordOpportunities,
            ];
        }

        return $opportunities;
    }

    /**
     * Análise de sentimento do mercado
     *
     * Baseado em mudanças de preço e atividade dos concorrentes
     *
     * @param string $categoryId
     * @return array Sentimento do mercado
     */
    public function analyzemarketSentiment(?string $categoryId = null): array
    {
        $whereClause = $categoryId ? "AND cw.category_id = :category_id" : "";
        $params = ['account_id' => $this->accountId];
        if ($categoryId) $params['category_id'] = $categoryId;

        // Analisar mudanças de preço nos últimos 7 dias
        $stmt = $this->db->prepare("
            SELECT
                COUNT(CASE WHEN CAST(h.new_value AS DECIMAL(10,2)) < CAST(h.old_value AS DECIMAL(10,2)) THEN 1 END) as price_decreases,
                COUNT(CASE WHEN CAST(h.new_value AS DECIMAL(10,2)) > CAST(h.old_value AS DECIMAL(10,2)) THEN 1 END) as price_increases,
                COUNT(*) as total_changes
            FROM competitor_history h
            JOIN competitor_watchlist cw ON cw.id = h.watchlist_id
            WHERE cw.account_id = :account_id
              AND h.field = 'price'
              AND h.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              {$whereClause}
        ");
        $stmt->execute($params);
        $priceChanges = $stmt->fetch(PDO::FETCH_ASSOC);

        // Determinar sentimento
        $sentiment = 'neutral';
        $confidenceScore = 50;

        if ($priceChanges['total_changes'] > 5) {
            $decreaseRatio = $priceChanges['price_decreases'] / $priceChanges['total_changes'];

            if ($decreaseRatio > 0.6) {
                $sentiment = 'bearish'; // Mercado em queda (guerra de preços)
                $confidenceScore = round($decreaseRatio * 100);
            } elseif ($decreaseRatio < 0.4) {
                $sentiment = 'bullish'; // Mercado em alta (preços subindo)
                $confidenceScore = round((1 - $decreaseRatio) * 100);
            }
        }

        return [
            'sentiment' => $sentiment,
            'confidence' => $confidenceScore,
            'description' => $this->getSentimentDescription($sentiment),
            'recommendation' => $this->getSentimentRecommendation($sentiment),
            'metrics' => [
                'price_decreases' => $priceChanges['price_decreases'],
                'price_increases' => $priceChanges['price_increases'],
                'total_changes' => $priceChanges['total_changes'],
            ],
        ];
    }

    // Helper Methods

    private function calculateMovingAverage(array $data, int $window): float
    {
        $recentData = array_slice($data, -$window);
        $sum = array_sum(array_column($recentData, 'sales'));
        return $sum / count($recentData);
    }

    private function calculateLinearTrend(array $data): float
    {
        $n = count($data);
        if ($n < 2) return 0;

        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;

        foreach ($data as $i => $point) {
            $x = $i;
            $y = $point['sales'];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        // Coeficiente angular (slope)
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);

        return $slope;
    }

    private function getSeasonalityFactor(\DateTime $date, array $historicalData): float
    {
        $month = (int)$date->format('n');
        $day = (int)$date->format('j');

        // Calendario comercial brasileiro — fatores de demanda
        $seasonalFactors = [
            // Mês => [fator base, [eventos especiais => [início, fim, fator]]]
            1  => 0.75,  // Janeiro — pós-festas, baixa
            2  => 0.80,  // Fevereiro — Carnaval (baixa em geral)
            3  => 0.90,  // Março — retorno às aulas
            4  => 0.95,  // Abril — Páscoa
            5  => 1.20,  // Maio — Dia das Mães (forte)
            6  => 1.15,  // Junho — Dia dos Namorados, Festa Junina
            7  => 0.90,  // Julho — férias escolares
            8  => 1.10,  // Agosto — Dia dos Pais
            9  => 0.95,  // Setembro — estável
            10 => 1.05,  // Outubro — Dia das Crianças
            11 => 1.35,  // Novembro — Black Friday (pico)
            12 => 1.40,  // Dezembro — Natal (pico máximo)
        ];

        $factor = $seasonalFactors[$month] ?? 1.0;

        // Ajustes pontuais para eventos específicos
        // Dia das Mães: segunda semana de maio
        if ($month === 5 && $day >= 5 && $day <= 15) {
            $factor += 0.15;
        }
        // Dia dos Namorados: 1-12 Junho
        if ($month === 6 && $day <= 12) {
            $factor += 0.10;
        }
        // Dia dos Pais: segunda semana de agosto
        if ($month === 8 && $day >= 5 && $day <= 15) {
            $factor += 0.10;
        }
        // Dia das Crianças: 1-12 Outubro
        if ($month === 10 && $day <= 12) {
            $factor += 0.10;
        }
        // Black Friday: última semana de novembro
        if ($month === 11 && $day >= 20) {
            $factor += 0.25;
        }
        // Natal: 1-25 Dezembro
        if ($month === 12 && $day <= 25) {
            $factor += 0.15;
        }

        // Se houver dados históricos, ajustar baseado na média do mês
        if (!empty($historicalData)) {
            $monthData = array_filter($historicalData, function (array $point) use ($month): bool {
                $pointDate = $point['date'] ?? null;
                if (!$pointDate) {
                    return false;
                }
                return (int)date('n', strtotime($pointDate)) === $month;
            });

            if (count($monthData) >= 3) {
                $monthSales = array_column($monthData, 'sales');
                $allSales = array_column($historicalData, 'sales');
                $monthAvg = array_sum($monthSales) / count($monthSales);
                $overallAvg = array_sum($allSales) / max(1, count($allSales));

                if ($overallAvg > 0) {
                    $historicalFactor = $monthAvg / $overallAvg;
                    // Média ponderada: 60% calendario, 40% histórico
                    $factor = ($factor * 0.6) + ($historicalFactor * 0.4);
                }
            }
        }

        return round($factor, 2);
    }

    private function calculateConfidence(int $daysAhead, int $historicalDays): float
    {
        // Confiança diminui quanto mais distante a previsão
        $baseConfidence = min(100, ($historicalDays / 90) * 100);
        $decayFactor = 1 - ($daysAhead / 60); // Decay até 60 dias

        return max(10, round($baseConfidence * $decayFactor, 1));
    }

    private function calculateOverallConfidence(array $data): float
    {
        // Confiança baseada na quantidade e qualidade dos dados
        $dataPoints = count($data);

        if ($dataPoints < 30) return 50;
        if ($dataPoints < 60) return 70;
        return 90;
    }

    private function getSentimentDescription(string $sentiment): string
    {
        return match ($sentiment) {
            'bullish' => 'Mercado em alta - Concorrentes aumentando preços',
            'bearish' => 'Mercado em queda - Guerra de preços ativa',
            default => 'Mercado estável - Sem movimentações significativas',
        };
    }

    private function getSentimentRecommendation(string $sentiment): string
    {
        return match ($sentiment) {
            'bullish' => 'Considere aumentar preços gradualmente para maximizar margem',
            'bearish' => 'Mantenha preços competitivos e foque em diferenciais (frete, atendimento)',
            default => 'Continue monitorando e mantenha estratégia atual',
        };
    }

    /**
     * Descobre keywords de alto potencial via ML Trends API e autocomplete
     */
    private function discoverKeywordOpportunities(): array
    {
        $keywords = [];

        try {
            // 1. Buscar categorias ativas do vendedor
            $stmt = $this->db->prepare("
                SELECT DISTINCT category_id
                FROM items
                WHERE account_id = :account_id AND status = 'active'
                LIMIT 5
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($categories) || !$this->mlClient) {
                return $keywords;
            }

            // 2. Para cada categoria, buscar trends reais
            foreach ($categories as $categoryId) {
                try {
                    $trends = $this->mlClient->getTrends($categoryId);
                    foreach (array_slice($trends, 0, 5) as $trend) {
                        if (is_string($trend) && $trend !== '' && !in_array($trend, $keywords, true)) {
                            $keywords[] = $trend;
                        }
                    }
                } catch (\Exception $e) {
                    // Continuar com próxima categoria
                }
            }

            // 3. Enriquecer com autocomplete de termos chave nos títulos
            if (!empty($keywords)) {
                $enriched = [];
                foreach (array_slice($keywords, 0, 3) as $kw) {
                    try {
                        $suggestions = $this->mlClient->getAutocompleteSuggestions($kw);
                        foreach (array_slice($suggestions, 0, 2) as $suggestion) {
                            if (!in_array($suggestion, $keywords, true) && !in_array($suggestion, $enriched, true)) {
                                $enriched[] = $suggestion;
                            }
                        }
                    } catch (\Exception $e) {
                        // Pular
                    }
                }
                $keywords = array_merge($keywords, $enriched);
            }
        } catch (\Exception $e) {
            // Retornar vazio se houver erro
        }

        return array_slice($keywords, 0, 10);
    }
}
