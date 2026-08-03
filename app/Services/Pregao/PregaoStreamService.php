<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use Redis;
use Throwable;

/**
 * Gateway SSE do Pregão — assina Redis Pub/Sub canal "pregao" e faz broadcast.
 *
 * Fallback oficial quando WebSocket (/ws/pregao) não está disponível na infra.
 */
final class PregaoStreamService
{
    private const HEARTBEAT_SECONDS = 15;
    private const MAX_SECONDS = 3600;

    /**
     * Stream SSE autenticado. Filtra por account_id quando informado no evento.
     */
    public function streamSse(?int $accountId = null): void
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        @ini_set('max_execution_time', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        echo "event: connected\n";
        echo 'data: ' . json_encode([
            'ok' => true,
            'channel' => PregaoEmitService::CHANNEL,
            'account_id' => $accountId,
            'transport' => 'sse',
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        $this->flush();

        $deadline = time() + self::MAX_SECONDS;

        while (time() < $deadline && !connection_aborted()) {
            $redis = $this->connectRedis();
            if ($redis === null) {
                echo "event: error\n";
                echo 'data: {"error":"redis_unavailable"}\n\n';
                $this->flush();
                sleep(2);
                continue;
            }

            try {
                $redis->setOption(Redis::OPT_READ_TIMEOUT, (float) self::HEARTBEAT_SECONDS);
                $redis->subscribe([PregaoEmitService::CHANNEL], function ($redis, string $channel, string $message) use ($accountId): void {
                    if (connection_aborted()) {
                        throw new \RuntimeException('client_aborted');
                    }
                    $event = json_decode($message, true);
                    if (!is_array($event)) {
                        return;
                    }
                    $eventAccount = isset($event['account_id']) ? (int) $event['account_id'] : null;
                    if ($accountId !== null && $eventAccount !== null && $eventAccount !== $accountId) {
                        return;
                    }
                    echo 'id: ' . md5($message) . "\n";
                    echo 'event: ' . ($event['type'] ?? 'message') . "\n";
                    echo 'data: ' . $message . "\n\n";
                    $this->flush();
                });
            } catch (Throwable $e) {
                if ($e->getMessage() === 'client_aborted') {
                    return;
                }
                // Timeout de leitura → heartbeat e re-subscribe
                echo ': heartbeat ' . time() . "\n\n";
                $this->flush();
            }
        }
    }

    /**
     * Emite ticket curto para autenticar o gateway WS.
     */
    public function issueTicket(int $userId, int $accountId, int $ttlSeconds = 120): string
    {
        $ticket = bin2hex(random_bytes(24));
        $redis = $this->connectRedis();
        if ($redis === null) {
            throw new \RuntimeException('Redis indisponível para ticket WS');
        }
        $key = 'pregao:ws:ticket:' . $ticket;
        $redis->setex($key, $ttlSeconds, json_encode([
            'user_id' => $userId,
            'account_id' => $accountId,
            'exp' => time() + $ttlSeconds,
        ]));
        return $ticket;
    }

    /**
     * @return array{user_id: int, account_id: int}|null
     */
    public function consumeTicket(string $ticket): ?array
    {
        $redis = $this->connectRedis();
        if ($redis === null) {
            return null;
        }
        $key = 'pregao:ws:ticket:' . $ticket;
        $raw = $redis->get($key);
        if (!$raw) {
            return null;
        }
        $redis->del($key);
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || empty($data['user_id']) || empty($data['account_id'])) {
            return null;
        }
        return [
            'user_id' => (int) $data['user_id'],
            'account_id' => (int) $data['account_id'],
        ];
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    private function connectRedis(): ?Redis
    {
        if (!class_exists('Redis')) {
            return null;
        }
        try {
            $redis = new Redis();
            $host = (string) ($_ENV['REDIS_HOST'] ?? '127.0.0.1');
            $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
            $redis->connect($host, $port, 1.5);
            $pass = $_ENV['REDIS_PASSWORD'] ?? '';
            if (!empty($pass) && $pass !== 'null') {
                $redis->auth($pass);
            }
            $redis->select((int) ($_ENV['REDIS_DB'] ?? 0));
            return $redis;
        } catch (Throwable $e) {
            log_warning('PregaoStreamService: Redis fail', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
