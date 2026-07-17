<?php

declare(strict_types=1);

namespace App\Helpers;

final class DebugAgentLog
{
    private const SESSION_ID = 'd0ed8f';

    public static function write(string $hypothesisId, string $location, string $message, array $data = []): void
    {
        $root = dirname(__DIR__, 2);
        $payload = [
            'sessionId' => self::SESSION_ID,
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
        ];
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($root . '/.cursor/debug-d0ed8f.log', $line, FILE_APPEND);
        @file_put_contents($root . '/storage/logs/debug-d0ed8f.log', $line, FILE_APPEND);
    }
}
