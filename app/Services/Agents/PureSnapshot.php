<?php

declare(strict_types=1);

namespace App\Services\Agents;

use InvalidArgumentException;

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
            if (self::isCallableStringRepresentation($value)) {
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
        if (self::isCallableArrayRepresentation($value)) {
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

    private static function isCallableStringRepresentation(string $value): bool
    {
        if (function_exists($value)) {
            return true;
        }

        $separator = strpos($value, '::');
        if ($separator === false || strpos($value, '::', $separator + 2) !== false) {
            return false;
        }

        return self::isClassName(substr($value, 0, $separator))
            && self::isIdentifier(substr($value, $separator + 2));
    }

    /** @param array<array-key, mixed> $value */
    private static function isCallableArrayRepresentation(array $value): bool
    {
        if (!array_is_list($value) || count($value) !== 2 || !is_string($value[1])) {
            return false;
        }
        if (!self::isIdentifier($value[1])) {
            return false;
        }

        return is_object($value[0])
            || (is_string($value[0]) && self::isClassReference($value[0]));
    }

    private static function isClassReference(string $value): bool
    {
        // Nomes de classe PHP são case-insensitive. Sem schema ou consulta de
        // símbolos, qualquer identificador simples neste par é ambíguo.
        return self::isClassName($value);
    }

    private static function isClassName(string $value): bool
    {
        $value = ltrim($value, '\\');
        if ($value === '') {
            return false;
        }
        foreach (explode('\\', $value) as $part) {
            if (!self::isIdentifier($part)) {
                return false;
            }
        }

        return true;
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $value) === 1;
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
