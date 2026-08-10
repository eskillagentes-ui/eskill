<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Database;
use PDO;
use Throwable;

/**
 * Fila de recomendações de Ads (observação → recomendação).
 * Nunca executa escrita; botão de execução fica desabilitado na UI.
 */
final class AdsReadinessService
{
    private PDO $db;
    /** @var object objeto com dashboard(int): array */
    private object $observation;

    public function __construct(?PDO $db = null, ?object $observation = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->observation = $observation ?? new AdsObservationService($this->db);
    }

    /**
     * @return array{
     *   recommendations: list<array<string, mixed>>,
     *   summary: array<string, mixed>
     * }
     */
    public function recommendationQueue(int $accountId): array
    {
        if (!is_callable([$this->observation, 'dashboard'])) {
            return [
                'recommendations' => [],
                'summary' => [
                    'total' => 0,
                    'pausar' => 0,
                    'reduzir_lance' => 0,
                    'manter' => 0,
                    'waste_brl' => 0.0,
                    'read_only' => true,
                ],
            ];
        }
        $dash = $this->observation->dashboard($accountId);
        $skus = is_array($dash['skus'] ?? null) ? $dash['skus'] : [];
        $recs = [];

        foreach ($skus as $sku) {
            $mlb = (string) ($sku['mlb_id'] ?? '');
            if ($mlb === '') {
                continue;
            }
            $gasto = (float) ($sku['gasto'] ?? 0);
            $acos = $sku['acos'] !== null ? (float) $sku['acos'] : null;
            $margem = $sku['margem_liquida_pct'] !== null ? (float) $sku['margem_liquida_pct'] : null;
            $hasCusto = (bool) ($sku['has_custo'] ?? false);
            $vendas = (int) ($sku['vendas_atribuidas'] ?? 0);
            $waste = $vendas === 0 ? $gasto : 0.0;

            $action = 'manter';
            $reasons = [];

            if ($gasto <= 0) {
                continue;
            }

            if ($vendas === 0 && $gasto >= 1.0) {
                $action = 'pausar';
                $reasons[] = sprintf('R$ %s sem conversão atribuída', number_format($gasto, 2, ',', '.'));
            } elseif ($acos !== null && $margem !== null && $acos > $margem) {
                $action = $acos > ($margem * 2) ? 'pausar' : 'reduzir_lance';
                $reasons[] = sprintf(
                    'ACOS %.1f%% > margem bruta %.1f%%%s',
                    $acos,
                    $margem,
                    $hasCusto ? '' : ' (CMV estimado)'
                );
            } elseif ($acos !== null && $acos > 25) {
                $action = 'reduzir_lance';
                $reasons[] = sprintf('ACOS elevado (%.1f%%)', $acos);
            } else {
                $reasons[] = 'Dentro dos parâmetros observados';
            }

            if (!$hasCusto) {
                $reasons[] = 'confiança menor — cadastre o CMV';
            }

            $recs[] = [
                'mlb_id' => $mlb,
                'action' => $action,
                'waste_brl' => round($action === 'manter' ? 0.0 : max($waste, $gasto * (($acos ?? 0) > ($margem ?? 100) ? 0.5 : 0.2)), 2),
                'gasto' => $gasto,
                'acos' => $acos,
                'margem_bruta_pct' => $margem,
                'has_real_cogs' => $hasCusto,
                'cogs_confidence' => $hasCusto ? 'real' : 'estimado',
                'vendas_atribuidas' => $vendas,
                'reasons' => $reasons,
                'execute_enabled' => false,
                'execute_tooltip' => 'Escrita será habilitada após destravamento da conta — ver Governança de Escrita',
            ];
        }

        usort($recs, static fn (array $a, array $b): int => ($b['waste_brl'] <=> $a['waste_brl']));

        $wasteTotal = array_sum(array_column($recs, 'waste_brl'));
        $pause = count(array_filter($recs, static fn ($r) => $r['action'] === 'pausar'));
        $reduce = count(array_filter($recs, static fn ($r) => $r['action'] === 'reduzir_lance'));

        return [
            'recommendations' => $recs,
            'summary' => [
                'total' => count($recs),
                'pausar' => $pause,
                'reduzir_lance' => $reduce,
                'manter' => count($recs) - $pause - $reduce,
                'waste_brl' => round($wasteTotal, 2),
                'read_only' => true,
            ],
        ];
    }
}
