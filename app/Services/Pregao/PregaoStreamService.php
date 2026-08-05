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
    /** Lua fixo executado no Redis; nenhuma entrada do usuário é interpolada no script. */
    private const CONSUME_TICKET_LUA = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if value then
    redis.call('DEL', KEYS[1])
end
return value
LUA;

    /** @var list<string> */
    private const EVENT_KEYS = ['account_id', 'payload', 'source', 'ts', 'type', 'v'];
    /** @var list<string> */
    private const EVENT_TYPES = [
        'account.semaforo', 'agent.status', 'index.candle', 'index.tick', 'keyword.rank',
        'metric.update', 'op', 'qa.status', 'sale',
    ];

    private ?PregaoQaProof $qaProof;
    private ?PregaoQaRunService $qaRuns;

    public function __construct(?PregaoQaProof $qaProof = null, ?PregaoQaRunService $qaRuns = null)
    {
        $this->qaProof = $qaProof ?? PregaoQaProof::fromEnvironment();
        $this->qaRuns = $qaRuns;
        if ($this->qaRuns === null && $this->qaProof !== null) {
            try {
                $this->qaRuns = new PregaoQaRunService(PregaoQaRunService::connectRedis(), $this->qaProof);
            } catch (Throwable) {
                $this->qaRuns = null;
            }
        }
    }

    /** @param array<string, mixed> $event */
    public static function isEventAllowedForAccount(
        array $event,
        int $accountId,
        ?PregaoQaProof $qaProof = null,
        ?PregaoQaRunService $qaRuns = null
    ): bool
    {
        if ($accountId <= 0) {
            return false;
        }

        $keys = array_keys($event);
        sort($keys, SORT_STRING);
        $eventAccount = $event['account_id'] ?? null;
        $type = $event['type'] ?? null;
        $validEnvelope = $keys === self::EVENT_KEYS
            && ($event['v'] ?? null) === PregaoEmitService::VERSION
            && is_string($type)
            && in_array($type, self::EVENT_TYPES, true)
            && is_int($eventAccount)
            && $eventAccount === $accountId
            && is_string($event['source'])
            && in_array($event['source'], ['live', 'seed'], true)
            && is_string($event['ts'])
            && self::isCanonicalTimestamp($event['ts'])
            && is_array($event['payload']);
        if (!$validEnvelope) {
            return false;
        }
        if ($type === 'agent.status') {
            return PregaoAgentStatusService::validatePayload($event['payload'], $accountId) !== null;
        }
        if ($type === 'keyword.rank') {
            return PregaoEmitService::isKeywordRankPayloadValid($event['payload']);
        }
        if ($type === 'qa.status') {
            $qaProof = $qaProof ?? PregaoQaProof::fromEnvironment();
            return $qaProof !== null
                && $qaRuns !== null
                && $qaRuns->isStatusAuthoritative($event['payload'], $accountId);
        }
        return true;
    }

    public static function eventVersion(): int
    {
        return PregaoEmitService::VERSION;
    }

    private static function isCanonicalTimestamp(string $timestamp): bool
    {
        $matches = [];
        if (preg_match(
            '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})'
                . '(?:\.[0-9]{1,6})?(?:Z|([+-])([0-9]{2}):([0-9]{2}))$/D',
            $timestamp,
            $matches
        ) !== 1) {
            return false;
        }
        $offsetHour = isset($matches[8]) && $matches[8] !== '' ? (int) $matches[8] : 0;
        $offsetMinute = isset($matches[9]) && $matches[9] !== '' ? (int) $matches[9] : 0;
        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            || (int) $matches[4] > 23
            || (int) $matches[5] > 59
            || (int) $matches[6] > 59
            || $offsetHour > 14
            || $offsetMinute > 59
            || ($offsetHour === 14 && $offsetMinute !== 0)
        ) {
            return false;
        }
        $epoch = strtotime($timestamp);
        return $epoch !== false && $epoch <= time() + 60;
    }

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
                        $redis->unsubscribe([PregaoEmitService::CHANNEL]);
                        return;
                    }
                    $event = json_decode($message, true);
                    if (!is_array($event)) {
                        return;
                    }
                    if ($accountId === null || !self::isEventAllowedForAccount(
                        $event,
                        $accountId,
                        $this->qaProof,
                        $this->qaRuns
                    )) {
                        return;
                    }
                    echo 'id: ' . md5($message) . "\n";
                    echo 'event: ' . ($event['type'] ?? 'message') . "\n";
                    echo 'data: ' . $message . "\n\n";
                    $this->flush();
                });
                if (connection_aborted()) {
                    return;
                }
            } catch (Throwable) {
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
        if ($userId <= 0 || $accountId <= 0 || $ttlSeconds <= 0) {
            throw new \InvalidArgumentException('Parâmetros inválidos para ticket WS');
        }
        $ticket = bin2hex(random_bytes(24));
        $redis = $this->connectRedis();
        if ($redis === null) {
            throw new \RuntimeException('Redis indisponível para ticket WS');
        }
        $key = 'pregao:ws:ticket:' . $ticket;
        $stored = $redis->setex($key, $ttlSeconds, json_encode([
            'user_id' => $userId,
            'account_id' => $accountId,
            'exp' => time() + $ttlSeconds,
        ], JSON_THROW_ON_ERROR));
        if ($stored !== true) {
            throw new \RuntimeException('Falha ao emitir ticket WS');
        }
        return $ticket;
    }

    /**
     * @return array{user_id: int, account_id: int}|null
     */
    public function consumeTicket(string $ticket): ?array
    {
        if (preg_match('/\A[a-f0-9]{48}\z/D', $ticket) !== 1) {
            return null;
        }
        $redis = $this->connectRedis();
        if ($redis === null) {
            return null;
        }
        $key = 'pregao:ws:ticket:' . $ticket;
        $raw = $redis->eval(self::CONSUME_TICKET_LUA, [$key], 1);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['account_id', 'exp', 'user_id']
            || !is_int($data['user_id'])
            || !is_int($data['account_id'])
            || !is_int($data['exp'])
            || $data['user_id'] <= 0
            || $data['account_id'] <= 0
            || $data['exp'] < time()
        ) {
            return null;
        }
        return [
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
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
        } catch (Throwable) {
            log_warning('PregaoStreamService: Redis fail', ['reason' => 'redis_connection_failed']);
            return null;
        }
    }
}
