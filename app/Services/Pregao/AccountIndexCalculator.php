<?php

declare(strict_types=1);

namespace App\Services\Pregao;

/**
 * Calculadora pura do índice ESKL11 com renormalização sobre fatores ativos.
 *
 * Pesos nominais: Fv 0.40 · Fe 0.20 · Fh 0.15 · Fr 0.15 · Ft 0.10
 * Fe = exposição (visitas 7d / baseline 28d)
 * Ft = TACOS (baseline / atual); ativo quando o coletor Ads emite gasto/TACOS.
 *
 * indice = 1000 × Σ(w_i·F_i) / Σ(w_i)   apenas para i ativos
 */
final class AccountIndexCalculator
{
    public const WEIGHT_VENDAS = 0.40;
    public const WEIGHT_EXPOSICAO = 0.20;
    /** @deprecated use WEIGHT_EXPOSICAO */
    public const WEIGHT_POSICAO = self::WEIGHT_EXPOSICAO;
    public const WEIGHT_HEALTH = 0.15;
    public const WEIGHT_REPUTACAO = 0.15;
    public const WEIGHT_TACOS = 0.10;
    public const BASE = 1000.0;
    public const FACTORS_TOTAL = 5;

    /** @var array<string, float> */
    public const WEIGHTS = [
        'Fv' => self::WEIGHT_VENDAS,
        'Fe' => self::WEIGHT_EXPOSICAO,
        'Fh' => self::WEIGHT_HEALTH,
        'Fr' => self::WEIGHT_REPUTACAO,
        'Ft' => self::WEIGHT_TACOS,
    ];

    /** @var array<string, float> */
    private const REPUTACAO_FACTORS = [
        'verde-escuro' => 1.00,
        'verde_escuro' => 1.00,
        'dark_green' => 1.00,
        '5_green' => 1.00,
        'verde' => 0.90,
        'green' => 0.90,
        '4_light_green' => 0.90,
        'amarelo' => 0.70,
        'yellow' => 0.70,
        '3_yellow' => 0.70,
        'laranja' => 0.40,
        'orange' => 0.40,
        '2_orange' => 0.40,
        'vermelho' => 0.20,
        'red' => 0.20,
        '1_red' => 0.20,
    ];

    /**
     * @param array{
     *   vendas_7d?: float|int|null,
     *   vendas_7d_baseline?: float|int|null,
     *   visitas_7d?: float|int|null,
     *   visitas_baseline?: float|int|null,
     *   health_medio?: float|int|null,
     *   reputacao?: string|null,
     *   tacos_atual?: float|int|null,
     *   tacos_baseline?: float|int|null,
     *   available?: array{Fv?: bool, Fe?: bool, Fh?: bool, Fr?: bool, Ft?: bool}
     * } $input
     * @return array{
     *   indice: float|null,
     *   factors: array{Fv: float|null, Fe: float|null, Fh: float|null, Fr: float|null, Ft: float|null},
     *   active: array{Fv: bool, Fe: bool, Fh: bool, Fr: bool, Ft: bool},
     *   factors_active: int,
     *   factors_total: int,
     *   label: string,
     *   weight_sum: float
     * }
     */
    public function calculate(array $input): array
    {
        $available = $input['available'] ?? [];
        // Compat: meta antiga com Fp → Fe
        if (!isset($available['Fe']) && isset($available['Fp'])) {
            $available['Fe'] = (bool) $available['Fp'];
        }

        $active = [
            'Fv' => (bool) ($available['Fv'] ?? false),
            'Fe' => (bool) ($available['Fe'] ?? false),
            'Fh' => (bool) ($available['Fh'] ?? false),
            'Fr' => (bool) ($available['Fr'] ?? false),
            'Ft' => (bool) ($available['Ft'] ?? false),
        ];

        $factors = [
            'Fv' => null,
            'Fe' => null,
            'Fh' => null,
            'Fr' => null,
            'Ft' => null,
        ];

        if ($active['Fv']) {
            $factors['Fv'] = round($this->factorVendas(
                (float) ($input['vendas_7d'] ?? 0),
                (float) ($input['vendas_7d_baseline'] ?? 1)
            ), 6);
        }
        if ($active['Fe']) {
            $factors['Fe'] = round($this->factorExposicao(
                (float) ($input['visitas_7d'] ?? 0),
                (float) ($input['visitas_baseline'] ?? 1)
            ), 6);
        }
        if ($active['Fh']) {
            $factors['Fh'] = round($this->factorHealth((float) ($input['health_medio'] ?? 0)), 6);
        }
        if ($active['Fr']) {
            $factors['Fr'] = round($this->factorReputacao((string) ($input['reputacao'] ?? 'verde')), 6);
        }
        if ($active['Ft']) {
            $factors['Ft'] = round($this->factorTacos(
                (float) ($input['tacos_baseline'] ?? 10),
                (float) ($input['tacos_atual'] ?? 0.1)
            ), 6);
        }

        $weightSum = 0.0;
        $weighted = 0.0;
        $activeCount = 0;
        foreach (self::WEIGHTS as $key => $weight) {
            if (!$active[$key] || $factors[$key] === null) {
                continue;
            }
            $weightSum += $weight;
            $weighted += $weight * $factors[$key];
            $activeCount++;
        }

        $indice = null;
        if ($activeCount > 0 && $weightSum > 0.0) {
            $indice = round(self::BASE * ($weighted / $weightSum), 4);
        }

        return [
            'indice' => $indice,
            'factors' => $factors,
            'active' => $active,
            'factors_active' => $activeCount,
            'factors_total' => self::FACTORS_TOTAL,
            'label' => sprintf('%d de %d fatores ativos', $activeCount, self::FACTORS_TOTAL),
            'weight_sum' => round($weightSum, 6),
        ];
    }

    public function factorVendas(float $vendas7d, float $baseline): float
    {
        $den = max($baseline, 1.0);
        return $vendas7d / $den;
    }

    /**
     * Fe = clamp(visitas_7d / visitas_baseline, 0.5, 2.0)
     * Baseline tipicamente = média semanal dos 28d anteriores.
     */
    public function factorExposicao(float $visitas7d, float $visitasBaseline): float
    {
        $den = max($visitasBaseline, 1.0);
        return $this->clamp($visitas7d / $den, 0.5, 2.0);
    }

    /**
     * @deprecated use factorExposicao
     */
    public function factorPosicao(float $posBaseline, float $posMediaAtual): float
    {
        $atual = $posMediaAtual <= 0.0 ? 0.0001 : $posMediaAtual;
        return $this->clamp($posBaseline / $atual, 0.5, 2.0);
    }

    public function factorHealth(float $healthMedio): float
    {
        return $this->clamp($healthMedio, 0.0, 1.0);
    }

    public function factorReputacao(string $cor): float
    {
        $key = strtolower(trim($cor));
        $key = str_replace([' ', '_'], '-', $key);
        if (isset(self::REPUTACAO_FACTORS[$key])) {
            return self::REPUTACAO_FACTORS[$key];
        }
        $raw = strtolower(trim($cor));
        if (isset(self::REPUTACAO_FACTORS[$raw])) {
            return self::REPUTACAO_FACTORS[$raw];
        }
        foreach (self::REPUTACAO_FACTORS as $alias => $factor) {
            if (str_contains($raw, str_replace('_', '-', $alias)) || str_contains($raw, $alias)) {
                return $factor;
            }
        }
        $alt = str_replace('-', '_', $key);
        return self::REPUTACAO_FACTORS[$alt] ?? self::REPUTACAO_FACTORS['verde'];
    }

    public function factorTacos(float $tacosBaseline, float $tacosAtual): float
    {
        $atual = max($tacosAtual, 0.1);
        return $this->clamp($tacosBaseline / $atual, 0.5, 2.0);
    }

    public function mapLevelIdToCor(string $levelId): string
    {
        $id = strtolower($levelId);
        return match (true) {
            str_contains($id, '5_green') => 'verde-escuro',
            str_contains($id, '4_light') => 'verde',
            str_contains($id, '3_yellow') => 'amarelo',
            str_contains($id, '2_orange') => 'laranja',
            str_contains($id, '1_red') || str_contains($id, 'red') => 'vermelho',
            default => 'verde',
        };
    }

    /**
     * @param array{reclamacoes_pct?: float, atrasos_pct?: float, cancelamentos_pct?: float} $indicadores
     * @param array{reclamacoes_pct?: float, atrasos_pct?: float, cancelamentos_pct?: float} $limites
     */
    public function resolveSemaforo(array $indicadores, array $limites): string
    {
        $keys = ['reclamacoes_pct', 'atrasos_pct', 'cancelamentos_pct'];
        $worstRatio = 0.0;

        foreach ($keys as $key) {
            $limite = (float) ($limites[$key] ?? 0);
            if ($limite <= 0.0) {
                continue;
            }
            $valor = (float) ($indicadores[$key] ?? 0);
            $worstRatio = max($worstRatio, $valor / $limite);
        }

        if ($worstRatio > 0.80) {
            return 'vermelho';
        }
        if ($worstRatio >= 0.50) {
            return 'amarelo';
        }
        return 'verde';
    }

    public function clamp(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }
}
