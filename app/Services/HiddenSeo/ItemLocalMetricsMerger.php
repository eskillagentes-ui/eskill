<?php

declare(strict_types=1);

namespace App\Services\HiddenSeo;

/**
 * Merge official read-only metrics into items.data.
 * Fail-soft: never persist visits=0 when the API call failed.
 */
final class ItemLocalMetricsMerger
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function applyPerformance(array $data, array $result): array
    {
        if (empty($result['success'])) {
            return $data;
        }
        if (array_key_exists('score', $result) && $result['score'] !== null && $result['score'] !== '') {
            $data['performance_score'] = $result['score'];
        }
        if (array_key_exists('level', $result) && $result['level'] !== null && $result['level'] !== '') {
            $data['performance_level'] = $result['level'];
        }
        if (array_key_exists('level_wording', $result) && $result['level_wording'] !== null && $result['level_wording'] !== '') {
            $data['performance_level_wording'] = $result['level_wording'];
        }
        $data['performance_updated_at'] = date('Y-m-d H:i:s');

        return $data;
    }

    /**
     * Persist visits_30d / _visits_30d only on a successful GET.
     * API total 0 is a known zero. HTTP 403 / error leaves keys untouched (pending).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function applyVisits30d(array $data, array $result): array
    {
        if (empty($result['success'])) {
            return $data;
        }
        if (!array_key_exists('visits', $result) || $result['visits'] === null || !is_numeric($result['visits'])) {
            return $data;
        }
        $visits = (int) $result['visits'];
        $data['visits_30d'] = $visits;
        $data['_visits_30d'] = $visits;
        $data['visits_updated_at'] = date('Y-m-d H:i:s');

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function needsVisits30d(array $data): bool
    {
        if (!array_key_exists('visits_30d', $data) && !array_key_exists('_visits_30d', $data)) {
            return true;
        }
        $updated = (string) ($data['visits_updated_at'] ?? '');
        if ($updated === '') {
            return true;
        }

        $ts = strtotime($updated);

        return $ts === false || $ts < (time() - 7 * 86400);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function needsPerformance(array $data): bool
    {
        if (!array_key_exists('performance_score', $data) || $data['performance_score'] === null || $data['performance_score'] === '') {
            return true;
        }
        $updated = (string) ($data['performance_updated_at'] ?? '');
        if ($updated === '') {
            return true;
        }
        $ts = strtotime($updated);

        return $ts === false || $ts < (time() - 7 * 86400);
    }
}
