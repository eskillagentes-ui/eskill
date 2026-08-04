<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;
use ReflectionReference;

/**
 * Normalizador recursivo de snapshots puros (sem referências PHP, callables ou I/O).
 *
 * Não usa serialize/unserialize (evita métodos mágicos).
 */
final class PureSnapshot
{
    public const MAX_DEPTH = 32;

    /**
     * Produz uma cópia profunda sem referências. Rejeita capabilities.
     *
     * @param bool $allowAgentResult somente para qa_results_snapshot.results
     */
    public static function normalize(mixed $value, bool $allowAgentResult = false, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('snapshot nesting is too deep');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('snapshot rejects non-finite floats');
            }

            return $value;
        }

        if (is_string($value)) {
            if (is_callable($value)) {
                throw new InvalidArgumentException('snapshot rejects callable strings');
            }

            return $value;
        }

        if ($allowAgentResult && $value instanceof AgentResult) {
            return self::canonicalizeAgentResult($value, $depth);
        }

        if ($value instanceof AgentResult) {
            throw new InvalidArgumentException('AgentResult is only allowed in qa_results_snapshot.results');
        }

        if (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('snapshot rejects objects and resources');
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('snapshot must contain only pure values');
        }

        // Callable arrays (ex.: [$obj, 'method'] ou ['Cls', 'method']) antes de percorrer.
        if (is_callable($value)) {
            throw new InvalidArgumentException('snapshot rejects callable arrays');
        }

        return self::copyArray($value, $allowAgentResult, $depth);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function normalizeArray(array $value, bool $allowAgentResult = false): array
    {
        $normalized = self::normalize($value, $allowAgentResult);
        if (!is_array($normalized)) {
            throw new InvalidArgumentException('expected array snapshot');
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function copyArray(array $value, bool $allowAgentResult, int $depth): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            // Quebra referências PHP: copia o valor atual para um slot novo.
            if (class_exists(ReflectionReference::class)) {
                ReflectionReference::fromArrayElement($value, $key);
            }
            $out[$key] = self::normalize($item, $allowAgentResult, $depth + 1);
        }

        return $out;
    }

    private static function canonicalizeAgentResult(AgentResult $result, int $depth): AgentResult
    {
        $data = self::normalizeArray($result->data(), false);
        $status = $result->status();
        $agent = $result->agent();
        $reason = $result->reason();
        $stateChanged = $result->stateChanged();
        $emittedOps = $result->emittedOps();

        return match ($status) {
            'success' => AgentResult::success($agent, $reason, $data, $stateChanged, $emittedOps),
            'skipped' => AgentResult::skipped($agent, $reason, $data, $stateChanged, $emittedOps),
            'blocked' => AgentResult::blocked($agent, $reason, $data, $stateChanged, $emittedOps),
            'failed' => AgentResult::failed($agent, $reason, $data, $stateChanged, $emittedOps),
            default => throw new InvalidArgumentException('invalid AgentResult status'),
        };
    }
}
