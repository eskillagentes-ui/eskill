<?php

declare(strict_types=1);

namespace App\Services\Financial;

/**
 * Missing CMV is unknown, never zero.
 *
 * Pedidos / Analytics / P&amp;L must not present R$ 0,00 or a green profit %
 * when sku_custos.custo_produto (and items.cost_price) are absent.
 * Does not invent cost and does not write Mercado Livre.
 */
final class MissingCogsPolicy
{
    public static function isKnownUnitCost(float $custoProduto = 0.0, float $costPrice = 0.0): bool
    {
        return $custoProduto > 0.0 || $costPrice > 0.0;
    }

    /**
     * True only when every sold unit in the period has CMV.
     * Zero known units (and any missing) is NOT "CMV real".
     */
    public static function hasRealCogs(int $unitsWithCogs, int $unitsWithoutCogs): bool
    {
        return $unitsWithCogs > 0 && $unitsWithoutCogs === 0;
    }

    /**
     * @return array{
     *   product_cost: float|null,
     *   profit: float|null,
     *   margin_pct: float|null,
     *   has_cogs: bool
     * }
     */
    public static function lineProfit(
        bool $hasCogs,
        float $lineNet,
        float $productCost,
        float $lineTax,
        float $extraCost,
        float $lineShipping,
        float $lineTotal
    ): array {
        if (!$hasCogs) {
            return [
                'product_cost' => null,
                'profit' => null,
                'margin_pct' => null,
                'has_cogs' => false,
            ];
        }

        $profit = round($lineNet - $lineTax - $productCost - $extraCost - $lineShipping, 2);

        return [
            'product_cost' => round($productCost, 2),
            'profit' => $profit,
            'margin_pct' => $lineTotal > 0 ? round(($profit / $lineTotal) * 100, 2) : 0.0,
            'has_cogs' => true,
        ];
    }

    /**
     * @param array<string, mixed> $sale
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function saleProfitFromItems(array $sale, array $items): array
    {
        $knownCost = 0.0;
        $knownProfit = 0.0;
        $knownItems = 0;
        $missingItems = 0;
        $anyNumericProfit = false;

        foreach ($items as $item) {
            $has = !empty($item['has_cogs']) || !empty($item['linked_product']);
            if ($has && array_key_exists('profit', $item) && $item['profit'] !== null) {
                $knownItems++;
                $anyNumericProfit = true;
                $knownProfit += (float)$item['profit'];
                $knownCost += (float)($item['product_cost'] ?? 0);
            } elseif ($has && ($item['product_cost'] ?? null) !== null) {
                $knownItems++;
                $knownCost += (float)$item['product_cost'];
            } else {
                $missingItems++;
            }
        }

        $complete = self::hasRealCogs($knownItems, $missingItems);
        $sale['has_cogs'] = $complete;
        $sale['items_com_custo'] = $knownItems;
        $sale['items_sem_custo'] = $missingItems;

        if ($complete) {
            $sale['product_cost'] = round($knownCost, 2);
            if ($anyNumericProfit) {
                $sale['profit'] = round($knownProfit, 2);
            }
            $rev = (float)($sale['total_amount'] ?? 0);
            if (array_key_exists('profit', $sale) && $sale['profit'] !== null) {
                $sale['margin_pct'] = $rev > 0 ? round(((float)$sale['profit'] / $rev) * 100, 2) : 0.0;
            }
            return $sale;
        }

        $sale['product_cost'] = $knownItems > 0 ? round($knownCost, 2) : null;
        $sale['profit'] = null;
        $sale['margin_pct'] = null;
        $sale['lucro_conhecido'] = $anyNumericProfit ? round($knownProfit, 2) : null;

        return $sale;
    }

    /**
     * @param array<string, mixed> $pnl
     * @param array{cogs: float, items_com_custo: int, items_sem_custo: int} $coverage
     * @return array<string, mixed>
     */
    public static function presentPnL(array $pnl, array $coverage): array
    {
        $with = (int)($coverage['items_com_custo'] ?? 0);
        $without = (int)($coverage['items_sem_custo'] ?? 0);
        $hasReal = self::hasRealCogs($with, $without);

        $pnl['has_real_cogs'] = $hasReal;
        $pnl['items_com_custo'] = $with;
        $pnl['items_sem_custo'] = $without;

        if (isset($coverage['cogs'])) {
            $pnl['cogs'] = round((float)$coverage['cogs'], 2);
        }

        if ($hasReal) {
            $pnl['lucro_conhecido'] = $pnl['net_profit'] ?? null;
            return $pnl;
        }

        $pnl['lucro_conhecido'] = null;
        $pnl['net_profit'] = null;
        $pnl['avg_margin'] = null;

        $source = (string)($pnl['cogs_source'] ?? 'none');
        if ($with > 0 && in_array($source, ['sku_custos', 'ml_orders', 'none'], true)) {
            $pnl['cogs_source'] = 'parcial';
        } elseif ($with === 0) {
            $pnl['cogs_source'] = 'none';
        }

        return $pnl;
    }

    /**
     * Analytics: never label net_profit (which does not subtract CMV) as "margem líquida real".
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function presentProfitMargins(array $rows, bool $cogsComplete): array
    {
        foreach ($rows as &$row) {
            if ($cogsComplete) {
                $row['cogs_status'] = 'real';
                $row['profit_label'] = null;
                continue;
            }
            $row['profit'] = null;
            $row['avg_margin'] = null;
            $row['cogs_status'] = 'sem_cmv';
            $row['profit_label'] = 'sem CMV';
        }
        unset($row);

        return $rows;
    }

    /**
     * Dashboard daily P&amp;L: ml_orders.net_profit does not subtract CMV.
     *
     * @param list<array<string, mixed>> $salesOverTime
     * @return list<array<string, mixed>>
     */
    public static function presentSalesOverTimeProfit(array $salesOverTime, bool $cogsComplete): array
    {
        foreach ($salesOverTime as &$row) {
            if ($cogsComplete) {
                $row['cogs_status'] = 'real';
                continue;
            }
            $row['profit'] = null;
            $row['cogs_status'] = 'sem_cmv';
        }
        unset($row);

        return $salesOverTime;
    }

    public static function presentNetProfit(?float $profit, bool $cogsComplete): ?float
    {
        return $cogsComplete ? $profit : null;
    }
}
