<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Política pura de coerência mínima entre status e percentual do Sentinela. */
final class SentinelaRiskStatusPolicy
{
    /** @var array<string, array{yellow: float, red: float}> */
    private const PCT_THRESHOLDS = [
        'reclamacoes' => ['yellow' => 50.0, 'red' => 80.0],
        'atrasos' => ['yellow' => 46.6666666667, 'red' => 80.0],
        'cancelamentos' => ['yellow' => 40.0, 'red' => 80.0],
    ];

    public static function isConsistent(string $riskKey, string $status, mixed $pct): bool
    {
        if ($pct === null) {
            return true;
        }
        if ((!is_int($pct) && !is_float($pct)) || !is_finite((float) $pct) || (float) $pct < 0) {
            return false;
        }
        if ($status === 'nd') {
            return false;
        }

        $thresholds = self::PCT_THRESHOLDS[$riskKey] ?? ['yellow' => 50.0, 'red' => 80.0];
        $minimumSeverity = (float) $pct >= $thresholds['red']
            ? 2
            : ((float) $pct >= $thresholds['yellow'] ? 1 : 0);
        $actualSeverity = match ($status) {
            'verde' => 0,
            'amarelo' => 1,
            'vermelho' => 2,
            default => -1,
        };

        return $actualSeverity >= $minimumSeverity;
    }
}
