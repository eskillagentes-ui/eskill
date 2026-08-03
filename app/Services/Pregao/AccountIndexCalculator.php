<?php

declare(strict_types=1);

namespace App\Services\Pregao;

/**
 * Calculadora pura do índice ESKL11.
 *
 * indice = 1000 × (0.40·Fv + 0.20·Fp + 0.15·Fh + 0.15·Fr + 0.10·Ft)
 *
 * Sem I/O — testável unitariamente.
 */
final class AccountIndexCalculator
{
    public const WEIGHT_VENDAS = 0.40;
    public const WEIGHT_POSICAO = 0.20;
    public const WEIGHT_HEALTH = 0.15;
    public const WEIGHT_REPUTACAO = 0.15;
    public const WEIGHT_TACOS = 0.10;
    public const BASE = 1000.0;

    /** @var array<string, float> */
    private const REPUTACAO_FACTORS = [
        'verde-escuro' => 1.00,
        'verde_escuro' => 1.00,
        'dark_green' => 1.00,
        'verde' => 0.90,
        'green' => 0.90,
        'amarelo' => 0.70,
        'yellow' => 0.70,
        'laranja' => 0.40,
        'orange' => 0.40,
        'vermelho' => 0.20,
        'red' => 0.20,
    ];

    /**
     * @param array{
     *   vendas_7d?: float|int,
     *   vendas_7d_baseline?: float|int,
     *   pos_media_atual?: float|int,
     *   pos_baseline?: float|int,
     *   health_medio?: float|int,
     *   reputacao?: string,
     *   tacos_atual?: float|int,
     *   tacos_baseline?: float|int
     * } $input
     * @return array{
     *   indice: float,
     *   factors: array{Fv: float, Fp: float, Fh: float, Fr: float, Ft: float}
     * }
     */
    public function calculate(array $input): array
    {
        $fv = $this->factorVendas(
            (float) ($input['vendas_7d'] ?? 0),
            (float) ($input['vendas_7d_baseline'] ?? 1)
        );
        $fp = $this->factorPosicao(
            (float) ($input['pos_baseline'] ?? 10),
            (float) ($input['pos_media_atual'] ?? 10)
        );
        $fh = $this->factorHealth((float) ($input['health_medio'] ?? 0));
        $fr = $this->factorReputacao((string) ($input['reputacao'] ?? 'verde'));
        $ft = $this->factorTacos(
            (float) ($input['tacos_baseline'] ?? 10),
            (float) ($input['tacos_atual'] ?? 0.1)
        );

        $weighted = (self::WEIGHT_VENDAS * $fv)
            + (self::WEIGHT_POSICAO * $fp)
            + (self::WEIGHT_HEALTH * $fh)
            + (self::WEIGHT_REPUTACAO * $fr)
            + (self::WEIGHT_TACOS * $ft);

        return [
            'indice' => round(self::BASE * $weighted, 4),
            'factors' => [
                'Fv' => round($fv, 6),
                'Fp' => round($fp, 6),
                'Fh' => round($fh, 6),
                'Fr' => round($fr, 6),
                'Ft' => round($ft, 6),
            ],
        ];
    }

    public function factorVendas(float $vendas7d, float $baseline): float
    {
        $den = max($baseline, 1.0);
        return $vendas7d / $den;
    }

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
        // aliases com underscore já cobertos; fallback verde
        $alt = str_replace('-', '_', $key);
        return self::REPUTACAO_FACTORS[$alt] ?? self::REPUTACAO_FACTORS['verde'];
    }

    public function factorTacos(float $tacosBaseline, float $tacosAtual): float
    {
        $atual = max($tacosAtual, 0.1);
        return $this->clamp($tacosBaseline / $atual, 0.5, 2.0);
    }

    /**
     * Semáforo da conta a partir dos % vs limites.
     *
     * verde se todos <50% do limite; amarelo 50–80%; vermelho >80%.
     *
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
