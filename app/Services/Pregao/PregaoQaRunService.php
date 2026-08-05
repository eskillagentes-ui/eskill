<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use Redis;

final class PregaoQaRunService
{
    public const QUEUE_KEY = 'pregao:qa:queue';
    public const RUN_ID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
    public const RUN_TTL_SECONDS = 900;
    public const LOCK_TTL_SECONDS = 900;

    private Redis $redis;
    private PregaoQaProof $proof;

    public function __construct(Redis $redis, PregaoQaProof $proof)
    {
        $this->redis = $redis;
        $this->proof = $proof;
    }

    public static function connectRedis(): Redis
    {
        if (!class_exists(Redis::class)) {
            throw new \RuntimeException('Redis indisponível');
        }
        $redis = new Redis();
        $host = (string) ($_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379);
        if (!$redis->connect($host, $port, 1.5)) {
            throw new \RuntimeException('Redis indisponível');
        }
        $password = (string) ($_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '');
        if ($password !== '' && $password !== 'null' && !$redis->auth($password)) {
            throw new \RuntimeException('Redis indisponível');
        }
        $redis->select((int) ($_ENV['REDIS_DB'] ?? getenv('REDIS_DB') ?: 0));
        return $redis;
    }

    /** @return array{run_id:string,account_id:int,status:string,manifest_hash:string,expires_at:string} */
    public function startRun(int $accountId, int $userId): array
    {
        if ($accountId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Escopo QA inválido');
        }
        $runId = self::uuidV4();
        $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $manifest = $this->proof->signManifest([
            'run_id' => $runId,
            'account_id' => $accountId,
            'user_id' => $userId,
            'created_at' => $createdAt->format(DATE_ATOM),
            'expires_at' => $createdAt->modify('+' . self::RUN_TTL_SECONDS . ' seconds')->format(DATE_ATOM),
        ]);
        $encodedManifest = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($this->redis->setex(self::manifestKey($runId), self::RUN_TTL_SECONDS, $encodedManifest) !== true) {
            throw new \RuntimeException('Falha ao armazenar manifesto QA');
        }
        $state = [
            'run_id' => $runId,
            'account_id' => $accountId,
            'status' => 'queued',
            'sequence' => -1,
            'updated_at' => $createdAt->format(DATE_ATOM),
        ];
        if ($this->redis->setex(
            self::stateKey($runId),
            self::RUN_TTL_SECONDS,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        ) !== true) {
            $this->redis->del(self::manifestKey($runId));
            throw new \RuntimeException('Falha ao armazenar estado QA');
        }
        $queued = $this->redis->lPush(self::QUEUE_KEY, json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
        if (!is_int($queued) || $queued < 1) {
            $this->redis->del(self::manifestKey($runId), self::stateKey($runId));
            throw new \RuntimeException('Falha ao enfileirar QA');
        }
        return [
            'run_id' => $runId,
            'account_id' => $accountId,
            'status' => 'queued',
            'manifest_hash' => $manifest['manifest_hash'],
            'expires_at' => $manifest['expires_at'],
        ];
    }

    /** @return array{manifest:array<string,mixed>,lock_token:string}|null */
    public function claimNext(): ?array
    {
        $rawJob = $this->redis->rPop(self::QUEUE_KEY);
        if (!is_string($rawJob) || $rawJob === '') {
            return null;
        }
        $job = json_decode($rawJob, true);
        if (!is_array($job) || array_keys($job) !== ['run_id'] || !is_string($job['run_id'])
            || preg_match(self::RUN_ID_PATTERN, $job['run_id']) !== 1
        ) {
            return null;
        }
        $runId = $job['run_id'];
        $token = bin2hex(random_bytes(24));
        if ($this->redis->set(self::lockKey($runId), $token, ['nx', 'ex' => self::LOCK_TTL_SECONDS]) !== true) {
            return null;
        }
        $manifest = $this->loadManifest($runId);
        if ($manifest === null) {
            $this->release($runId, $token);
            return null;
        }
        $expires = strtotime((string) $manifest['expires_at']);
        if ($expires === false || $expires < time()) {
            $this->release($runId, $token);
            return null;
        }
        return ['manifest' => $manifest, 'lock_token' => $token];
    }

    /** @return array<string,mixed>|null */
    public function loadAuthorizedRun(string $runId, int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }
        $manifest = $this->loadManifest($runId);
        return $manifest !== null && $manifest['account_id'] === $accountId ? $manifest : null;
    }

    /** @return array<string,mixed>|null */
    public function loadState(string $runId, int $accountId): ?array
    {
        if ($this->loadAuthorizedRun($runId, $accountId) === null) {
            return null;
        }
        $raw = $this->redis->get(self::stateKey($runId));
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || ($state['run_id'] ?? null) !== $runId || ($state['account_id'] ?? null) !== $accountId) {
            return null;
        }
        return $state;
    }

    /** @param array<string,mixed> $protocol */
    public function updateState(array $manifest, array $protocol): void
    {
        if (!$this->proof->verifyManifest($manifest) || $manifest['run_id'] !== ($protocol['run_id'] ?? null)) {
            throw new \InvalidArgumentException('Estado QA sem manifesto válido');
        }
        $state = [
            'run_id' => $manifest['run_id'],
            'account_id' => $manifest['account_id'],
            'status' => $protocol['result'],
            'sequence' => $protocol['sequence'],
            'step' => $protocol['step'],
            'screenshot_url' => $protocol['screenshot'] === 'latest.png'
                ? '/qa/frame/' . $manifest['run_id']
                : null,
            'cursor' => $protocol['cursor'],
            'observed_at' => $protocol['observed_at'],
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
        if ($this->redis->setex(
            self::stateKey($manifest['run_id']),
            self::RUN_TTL_SECONDS,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        ) !== true) {
            throw new \RuntimeException('Falha ao atualizar estado QA');
        }
    }

    public function release(string $runId, string $token): void
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1 || preg_match('/\A[a-f0-9]{48}\z/D', $token) !== 1) {
            return;
        }
        // Lua constante: somente chave e token passam em KEYS/ARGV, nunca são interpolados no script.
        $lua = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end";
        $this->redis->eval($lua, [self::lockKey($runId), $token], 1);
    }

    public static function manifestKey(string $runId): string
    {
        return 'pregao:qa:manifest:' . $runId;
    }

    public static function stateKey(string $runId): string
    {
        return 'pregao:qa:state:' . $runId;
    }

    public static function lockKey(string $runId): string
    {
        return 'pregao:qa:lock:' . $runId;
    }

    public static function framePath(string $privateRoot, string $runId): string
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1 || $privateRoot === '' || str_contains($privateRoot, "\0")) {
            throw new \InvalidArgumentException('Caminho de frame QA inválido');
        }
        return rtrim($privateRoot, '/\\') . DIRECTORY_SEPARATOR . $runId . DIRECTORY_SEPARATOR . 'latest.png';
    }

    public static function retainLatestFrame(string $source, string $privateRoot, string $runId): string
    {
        if (basename($source) !== 'latest.png' || !is_file($source) || !is_readable($source)) {
            throw new \InvalidArgumentException('Frame QA inválido');
        }
        $destination = self::framePath($privateRoot, $runId);
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Falha ao criar retenção QA');
        }
        $temporary = $directory . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        if (!copy($source, $temporary) || !rename($temporary, $destination)) {
            @unlink($temporary);
            throw new \RuntimeException('Falha ao reter frame QA');
        }
        chmod($destination, 0600);
        return $destination;
    }

    /** @return array<string,mixed>|null */
    private function loadManifest(string $runId): ?array
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            return null;
        }
        $raw = $this->redis->get(self::manifestKey($runId));
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($manifest) && $this->proof->verifyManifest($manifest) ? $manifest : null;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
