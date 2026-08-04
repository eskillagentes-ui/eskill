<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Política pura de coerência mínima entre status e percentual do Sentinela. */
final class SentinelaRiskStatusPolicy
{
    /** @var array<string, array{limit: float, yellow: float, red: float}> */
    private const VALUE_THRESHOLDS = [
        'reclamacoes' => ['limit' => 2.0, 'yellow' => 1.0, 'red' => 1.6],
        'atrasos' => ['limit' => 15.0, 'yellow' => 7.0, 'red' => 12.0],
        'cancelamentos' => ['limit' => 2.5, 'yellow' => 1.0, 'red' => 2.0],
    ];

    public static function isConsistent(
        string $riskKey,
        string $status,
        mixed $value,
        mixed $limit,
        mixed $pct
    ): bool {
        if ($pct === null) {
            return $status === 'nd';
        }
        if ((!is_int($pct) && !is_float($pct)) || !is_finite((float) $pct) || (float) $pct < 0) {
            return false;
        }
        if ($status === 'nd') {
            return false;
        }

        $metric = (float) $pct;
        $thresholds = ['yellow' => 50.0, 'red' => 80.0];
        if (isset(self::VALUE_THRESHOLDS[$riskKey])) {
            $configured = self::VALUE_THRESHOLDS[$riskKey];
            if (!self::isFiniteNumber($value) || !self::isFiniteNumber($limit)
                || !self::approximatelyEqual((float) $limit, $configured['limit'])
                || !self::approximatelyEqual(
                    (float) $pct,
                    round(((float) $value / (float) $limit) * 100.0, 2)
                )
            ) {
                return false;
            }
            $metric = (float) $value;
            $thresholds = $configured;
        }

        $minimumSeverity = $metric >= $thresholds['red']
            ? 2
            : ($metric >= $thresholds['yellow'] ? 1 : 0);
        $actualSeverity = match ($status) {
            'verde' => 0,
            'amarelo' => 1,
            'vermelho' => 2,
            default => -1,
        };

        return $actualSeverity >= $minimumSeverity;
    }

    private static function isFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function approximatelyEqual(float $left, float $right): bool
    {
        return abs($left - $right) <= 0.000001 * max(1.0, abs($left), abs($right));
    }
}
