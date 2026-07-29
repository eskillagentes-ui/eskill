<?php

declare(strict_types=1);

namespace App\Services\AI\SEO\Strategies;

use App\Services\AI\SEO\CompetitorSpy;

/**
 * 🕵️ E13: Competitor Benchmark Strategy
 * 
 * Estratégia que utiliza o CompetitorSpy para comparar o item
 * com os top sellers e gerar recomendações baseadas no mercado.
 */
class CompetitorBenchmarkStrategy
{
    private ?int $accountId;
    private CompetitorSpy $spy;

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->spy = new CompetitorSpy($accountId);
    }

    /**
     * Executa análise comparativa
     */
    public function analyze(string $itemId): array
    {
        // Usa o CompetitorSpy para obter dados brutos
        $comparison = $this->spy->compareWithCompetitors($itemId);

        if (isset($comparison['error'])) {
            return [
                'error' => $comparison['error'],
                'score' => 0
            ];
        }

        // Calcula score relativo (0-100)
        // Baseado em quão bem posicionado está vs concorrentes
        $score = $this->calculateRelativeScore($comparison);

        // Formata recomendações para o padrão da Engine
        $recommendations = $this->formatRecommendations($comparison['recommendations'] ?? []);

        return [
            'strategy_name' => 'Competitor Benchmark',
            'score' => $score,
            'details' => $comparison,
            'recommendations' => $recommendations
        ];
    }

    private function calculateRelativeScore(array $comparison): float
    {
        // Se não houver dados de comparação, retorna neutro
        if (empty($comparison['comparison'])) {
            return 50;
        }

        $comp = $comparison['comparison'];
        $score = 50; // Base start

        // Price impact (max +/- 20)
        $priceStatus = $comp['price']['status'] ?? 'competitive';
        if ($priceStatus === 'competitive') $score += 10;
        elseif ($priceStatus === 'underpriced') $score += 20; // Aggressive is good for sales velocity
        elseif ($priceStatus === 'overpriced') $score -= 10;

        // Title impact (max +/- 10)
        if (($comp['title_length']['status'] ?? '') === 'good') $score += 10;
        else $score -= 5;

        // Attributes impact (max +/- 10)
        if (($comp['attributes']['status'] ?? '') === 'good') $score += 10;
        
        // Images impact (max +/- 10)
        if (($comp['images']['status'] ?? '') === 'good') $score += 10;

        return min(100, max(0, $score));
    }

    private function formatRecommendations(array $spyRecommendations): array
    {
        $formatted = [];
        foreach ($spyRecommendations as $rec) {
            $formatted[] = [
                'strategy' => 'E13_COMPETITOR',
                'priority' => $rec['priority'] === 'high' ? 1 : 2,
                'message' => $rec['issue'] ?? 'Melhoria baseada em concorrentes',
                'action' => $rec['action']
            ];
        }
        return $formatted;
    }
}
