<?php

declare(strict_types=1);

namespace App\Services\Pregao;

final class PregaoQaWorkerProtocol
{
    /** @var list<string> */
    public const STEPS = ['dashboard', 'snapshot', 'realtime', 'event_explorer', 'console_http'];
    /** @var list<string> */
    public const RESULTS = ['running', 'passed', 'failed', 'blocked'];
    /** @var list<string> */
    private const KEYS = ['cursor', 'observed_at', 'result', 'run_id', 'screenshot', 'sequence', 'step'];

    /** @return array<string,mixed>|null */
    public static function decode(string $line, string $expectedRunId, int $previousSequence): ?array
    {
        if (strlen($line) > 16384 || preg_match(PregaoQaRunService::RUN_ID_PATTERN, $expectedRunId) !== 1) {
            return null;
        }
        $data = json_decode(trim($line), true);
        if (!is_array($data)) {
            return null;
        }
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== self::KEYS
            || ($data['run_id'] ?? null) !== $expectedRunId
            || !is_int($data['sequence'])
            || $data['sequence'] !== $previousSequence + 1
            || !is_string($data['step'])
            || !in_array($data['step'], self::STEPS, true)
            || !is_string($data['result'])
            || !in_array($data['result'], self::RESULTS, true)
            || !in_array($data['screenshot'], [null, 'latest.png'], true)
            || !self::validCursor($data['cursor'])
            || !self::validTimestamp($data['observed_at'])
        ) {
            return null;
        }
        return $data;
    }

    private static function validCursor(mixed $cursor): bool
    {
        if ($cursor === null) {
            return true;
        }
        if (!is_array($cursor)) {
            return false;
        }
        $keys = array_keys($cursor);
        sort($keys, SORT_STRING);
        return $keys === ['x', 'y']
            && is_int($cursor['x']) && $cursor['x'] >= 0 && $cursor['x'] <= 100000
            && is_int($cursor['y']) && $cursor['y'] >= 0 && $cursor['y'] <= 100000;
    }

    private static function validTimestamp(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
        ) {
            return false;
        }
        $epoch = strtotime($value);
        return $epoch !== false && $epoch <= time() + 60;
    }
}
