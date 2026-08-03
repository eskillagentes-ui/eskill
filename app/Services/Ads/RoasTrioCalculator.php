<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Calculadora pura do trio de ROAS a partir da margem líquida (%).
 *
 * ROAS_breakeven = 100 / margem_liquida_pct
 * ROAS_objetivo  = breakeven × 1,5
 * ROAS_escala    = breakeven × 2,0
 *
 * Margem ≤ 0 → n/d (sem divisão por zero).
 */
final class RoasTrioCalculator
{
    public const OBJETIVO_MULT = 1.5;
    public const ESCALA_MULT = 2.0;

    /**
     * @return array{
     *   roas_breakeven: float|null,
     *   roas_objetivo: float|null,
     *   roas_escala: float|null,
     *   available: bool,
     *   reason: string|null
     * }
     */
    public function fromMargemLiquida(?float $margemLiquidaPct): array
    {
        if ($margemLiquidaPct === null) {
            return $this->nd('margem_ausente');
        }
        if ($margemLiquidaPct <= 0.0) {
            return $this->nd('margem_nao_positiva');
        }

        $breakeven = 100.0 / $margemLiquidaPct;
        return [
            'roas_breakeven' => round($breakeven, 4),
            'roas_objetivo' => round($breakeven * self::OBJETIVO_MULT, 4),
            'roas_escala' => round($breakeven * self::ESCALA_MULT, 4),
            'available' => true,
            'reason' => null,
        ];
    }

    /**
     * Margens a partir dos custos cadastrados.
     * margem_bruta = (preço − custo − frete) / preço × 100
     * margem_líquida = margem_bruta − comissão% − custos_operacionais%
     *
     * @return array{margem_bruta_pct: float|null, margem_liquida_pct: float|null, available: bool, reason: string|null}
     */
    public function marginsFromCustos(
        float $custoProduto,
        float $comissaoPct,
        float $freteMedio,
        float $custosOperacionaisPct,
        float $precoMinimo
    ): array {
        if ($precoMinimo <= 0.0) {
            return [
                'margem_bruta_pct' => null,
                'margem_liquida_pct' => null,
                'available' => false,
                'reason' => 'preco_invalido',
            ];
        }

        $bruta = (($precoMinimo - $custoProduto - $freteMedio) / $precoMinimo) * 100.0;
        $liquida = $bruta - $comissaoPct - $custosOperacionaisPct;

        return [
            'margem_bruta_pct' => round($bruta, 4),
            'margem_liquida_pct' => round($liquida, 4),
            'available' => true,
            'reason' => null,
        ];
    }

    /**
     * @return array{
     *   roas_breakeven: float|null,
     *   roas_objetivo: float|null,
     *   roas_escala: float|null,
     *   available: bool,
     *   reason: string|null
     * }
     */
    private function nd(string $reason): array
    {
        return [
            'roas_breakeven' => null,
            'roas_objetivo' => null,
            'roas_escala' => null,
            'available' => false,
            'reason' => $reason,
        ];
    }
}
