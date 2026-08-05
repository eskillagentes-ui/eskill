<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use Redis;

final class PregaoQaRunService
{
    public const QUEUE_KEY = 'pregao:qa:queue';
    public const PENDING_KEY = 'pregao:qa:pending';
    public const RUN_ID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
    public const RUN_TTL_SECONDS = 900;
    public const LOCK_TTL_SECONDS = 180;
    public const EVIDENCE_TTL_SECONDS = 86400;
    private const ACTIVE_PREFIX = 'pregao:qa:active:';

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
        if ($this->redis->set(self::activeKey($accountId), $runId, ['nx', 'ex' => self::RUN_TTL_SECONDS]) !== true) {
            throw new \DomainException('Já existe QA ativo para esta conta');
        }
        $createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
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
                'manifest_hash' => $manifest['manifest_hash'],
                'status' => 'queued',
                'sequence' => 0,
                'updated_at' => $createdAt->format(DATE_ATOM),
            ];
            if ($this->redis->setex(
                self::stateKey($runId),
                self::RUN_TTL_SECONDS,
                json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            ) !== true) {
                throw new \RuntimeException('Falha ao armazenar estado QA');
            }
            $queued = $this->redis->lPush(self::QUEUE_KEY, json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
            if (!is_int($queued) || $queued < 1) {
                throw new \RuntimeException('Falha ao enfileirar QA');
            }
        } catch (\Throwable $exception) {
            $this->redis->del(self::manifestKey($runId), self::stateKey($runId));
            $this->releaseActive($accountId, $runId);
            throw $exception;
        }
        return [
            'run_id' => $runId,
            'account_id' => $accountId,
            'status' => 'queued',
            'manifest_hash' => $manifest['manifest_hash'],
            'expires_at' => $manifest['expires_at'],
        ];
    }

    /** @return array{manifest:array<string,mixed>,lock_token:string,pending_job:string}|null */
    public function claimNext(): ?array
    {
        $rawJob = $this->redis->rPopLPush(self::QUEUE_KEY, self::PENDING_KEY);
        if (!is_string($rawJob) || $rawJob === '') {
            return null;
        }
        $runId = self::decodeJob($rawJob);
        if ($runId === null) {
            $this->redis->lRem(self::PENDING_KEY, $rawJob, 1);
            return null;
        }
        $token = bin2hex(random_bytes(24));
        if ($this->redis->set(self::lockKey($runId), $token, ['nx', 'ex' => self::LOCK_TTL_SECONDS]) !== true) {
            return null;
        }
        $manifest = $this->loadManifest($runId);
        if ($manifest === null) {
            $this->ackPending($runId, $rawJob);
            $this->release($runId, $token);
            return null;
        }
        $expires = strtotime((string) $manifest['expires_at']);
        if ($expires === false || $expires < time()) {
            $this->failPendingClosed($manifest);
            $this->release($runId, $token);
            return null;
        }
        return ['manifest' => $manifest, 'lock_token' => $token, 'pending_job' => $rawJob];
    }

    public function ackPending(string $runId, string $rawJob): bool
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1 || self::decodeJob($rawJob) !== $runId) {
            return false;
        }
        return $this->redis->lRem(self::PENDING_KEY, $rawJob, 1) === 1;
    }

    /** @param null|callable(array<string,mixed>,array<string,mixed>):bool $ensureTerminalEvidence */
    public function recoverPending(?callable $ensureTerminalEvidence = null): int
    {
        $jobs = $this->redis->lRange(self::PENDING_KEY, 0, -1);
        if (!is_array($jobs)) {
            return 0;
        }
        $recovered = 0;
        foreach ($jobs as $rawJob) {
            if (!is_string($rawJob) || $rawJob === '') {
                continue;
            }
            $runId = self::decodeJob($rawJob);
            if ($runId === null) {
                $this->redis->lRem(self::PENDING_KEY, $rawJob, 0);
                continue;
            }
            $lock = $this->redis->get(self::lockKey($runId));
            if (is_string($lock) && $lock !== '') {
                continue;
            }
            $stateRaw = $this->redis->get(self::stateKey($runId));
            $state = is_string($stateRaw) ? json_decode($stateRaw, true) : null;
            if (is_array($state) && in_array(($state['status'] ?? null), ['passed', 'failed', 'blocked'], true)) {
                $terminalManifest = is_array($state['manifest'] ?? null) ? $state['manifest'] : null;
                if ($ensureTerminalEvidence === null
                    || !is_array($terminalManifest)
                    || !$this->proof->verifyManifest($terminalManifest)
                    || $terminalManifest['run_id'] !== $runId
                    || $ensureTerminalEvidence($terminalManifest, $state) !== true
                ) {
                    continue;
                }
                $this->releaseActive((int) $terminalManifest['account_id'], $runId);
                $this->redis->lRem(self::PENDING_KEY, $rawJob, 0);
                continue;
            }
            $manifest = $this->loadManifest($runId);
            if ($manifest === null) {
                $this->redis->lRem(self::PENDING_KEY, $rawJob, 0);
                continue;
            }
            $expires = strtotime((string) $manifest['expires_at']);
            if ($expires === false || $expires < time()) {
                $this->failPendingClosed($manifest);
                $terminalRaw = $this->redis->get(self::stateKey($runId));
                $terminal = is_string($terminalRaw) ? json_decode($terminalRaw, true) : null;
                if ($ensureTerminalEvidence !== null
                    && is_array($terminal)
                    && $ensureTerminalEvidence($manifest, $terminal) === true
                ) {
                    $this->releaseActive((int) $manifest['account_id'], $runId);
                    $this->redis->lRem(self::PENDING_KEY, $rawJob, 0);
                }
                continue;
            }
            $lua = "if redis.call('EXISTS', KEYS[3]) == 0 and redis.call('LREM', KEYS[1], 1, ARGV[1]) == 1 then return redis.call('LPUSH', KEYS[2], ARGV[1]) else return 0 end";
            $moved = $this->redis->eval(
                $lua,
                [self::PENDING_KEY, self::QUEUE_KEY, self::lockKey($runId), $rawJob],
                3
            );
            if (is_int($moved) && $moved > 0) {
                $recovered++;
            }
        }
        return $recovered;
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
        $sequence = $protocol['sequence'] ?? null;
        if (!is_int($sequence) || $sequence < 1) {
            throw new \InvalidArgumentException('Progressão QA regressiva ou terminal');
        }
        $previousSequence = $sequence - 1;
        $previousResult = $previousSequence === 0 ? null : 'running';
        $validated = PregaoQaWorkerProtocol::decode(
            json_encode($protocol, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $manifest['run_id'],
            $previousSequence,
            $previousResult
        );
        if ($validated === null) {
            throw new \InvalidArgumentException('Progressão QA regressiva ou terminal');
        }
        $protocol = $validated;
        $observedAt = strtotime($protocol['observed_at']);
        $expiresAt = strtotime((string) $manifest['expires_at']);
        if ($observedAt === false || $expiresAt === false || $observedAt > $expiresAt) {
            throw new \InvalidArgumentException('Evidência QA posterior ao manifesto');
        }
        $state = [
            'run_id' => $manifest['run_id'],
            'account_id' => $manifest['account_id'],
            'manifest_hash' => $manifest['manifest_hash'],
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
        $terminal = in_array($protocol['result'], ['passed', 'failed', 'blocked'], true);
        if ($terminal) {
            $state['manifest'] = $manifest;
        }
        $ttl = $terminal ? self::EVIDENCE_TTL_SECONDS : self::RUN_TTL_SECONDS;
        $expectedStatus = $previousSequence === 0 ? 'queued' : 'running';
        $lua = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return -1 end
local ok, current = pcall(cjson.decode, raw)
if not ok or type(current) ~= 'table' then return -1 end
if current.run_id ~= ARGV[1]
    or tonumber(current.account_id) ~= tonumber(ARGV[2])
    or current.manifest_hash ~= ARGV[3]
    or tonumber(current.sequence) ~= tonumber(ARGV[4])
    or current.status ~= ARGV[5] then
    return 0
end
redis.call('SETEX', KEYS[1], tonumber(ARGV[6]), ARGV[7])
return 1
LUA;
        // Lua constante: dados entram somente por KEYS/ARGV; nenhuma entrada é interpolada no script.
        $updated = $this->redis->eval($lua, [
            self::stateKey($manifest['run_id']),
            $manifest['run_id'],
            (string) $manifest['account_id'],
            $manifest['manifest_hash'],
            (string) $previousSequence,
            $expectedStatus,
            (string) $ttl,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ], 1);
        if ($updated !== 1) {
            throw new \InvalidArgumentException('Progressão QA atual inválida ou concorrente');
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

    public function releaseActive(int $accountId, string $runId): void
    {
        if ($accountId <= 0 || preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            return;
        }
        $lua = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end";
        $this->redis->eval($lua, [self::activeKey($accountId), $runId], 1);
    }

    public static function activeKey(int $accountId): string
    {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('Conta QA inválida');
        }
        return self::ACTIVE_PREFIX . $accountId;
    }

    public static function manifestKey(string $runId): string
    {
        return 'pregao:qa:manifest:' . $runId;
    }

    public static function stateKey(string $runId): string
    {
        return 'pregao:qa:state:' . $runId;
    }

    public static function receiptKey(string $runId): string
    {
        return 'pregao:qa:receipt:' . $runId;
    }

    public static function latestReceiptKey(int $accountId): string
    {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('Conta QA inválida');
        }
        return 'pregao:qa:receipt:latest:' . $accountId;
    }

    /** @param array<string,mixed> $status */
    public static function statusHash(array $status): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            $keys = array_keys($value);
            if ($keys !== range(0, count($value) - 1)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                $value[$key] = $normalize($child);
            }
            return $value;
        };
        return hash('sha256', json_encode(
            $normalize($status),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $status */
    public function confirmEvidence(array $manifest, array $status, int $eventId, string $eventTs): bool
    {
        $observedAt = strtotime((string) ($status['observed_at'] ?? ''));
        $expiresAt = strtotime((string) ($manifest['expires_at'] ?? ''));
        if ($eventId <= 0
            || !$this->proof->verifyManifest($manifest)
            || !$this->proof->verifyStatus($status, (int) ($manifest['account_id'] ?? 0))
            || ($status['run_id'] ?? null) !== $manifest['run_id']
            || ($status['manifest_hash'] ?? null) !== $manifest['manifest_hash']
            || $observedAt === false
            || $expiresAt === false
            || $observedAt > $expiresAt
            || strtotime($eventTs) === false
        ) {
            return false;
        }
        $payloadHash = self::statusHash($status);
        $terminal = in_array($status['result'], ['passed', 'failed', 'blocked'], true);
        $ttl = $terminal ? self::EVIDENCE_TTL_SECONDS : self::RUN_TTL_SECONDS;
        $receipt = [
            'run_id' => $manifest['run_id'],
            'account_id' => $manifest['account_id'],
            'manifest_hash' => $manifest['manifest_hash'],
            'manifest_expires_at' => $manifest['expires_at'],
            'sequence' => $status['sequence'],
            'step' => $status['step'],
            'result' => $status['result'],
            'observed_at' => $status['observed_at'],
            'payload_hash' => $payloadHash,
            'event_id' => $eventId,
            'event_ts' => $eventTs,
            'status' => $status,
        ];
        $lua = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local ok, state = pcall(cjson.decode, raw)
if not ok or type(state) ~= 'table'
    or state.run_id ~= ARGV[1]
    or tonumber(state.account_id) ~= tonumber(ARGV[2])
    or state.manifest_hash ~= ARGV[3]
    or tonumber(state.sequence) ~= tonumber(ARGV[4])
    or state.step ~= ARGV[5]
    or state.status ~= ARGV[6]
    or state.observed_at ~= ARGV[7] then
    return 0
end
local existing = redis.call('GET', KEYS[2])
if existing then
    local receiptOk, receipt = pcall(cjson.decode, existing)
    if receiptOk and receipt.payload_hash == ARGV[8] and tonumber(receipt.event_id) == tonumber(ARGV[9]) then
        return 2
    end
    return 0
end
state.receipt_hash = ARGV[8]
state.receipt_event_id = tonumber(ARGV[9])
redis.call('SETEX', KEYS[1], tonumber(ARGV[10]), cjson.encode(state))
redis.call('SETEX', KEYS[2], tonumber(ARGV[10]), ARGV[11])
redis.call('SETEX', KEYS[3], tonumber(ARGV[10]), ARGV[1])
return 1
LUA;
        // Lua constante: dados entram somente por KEYS/ARGV; nenhuma entrada é interpolada no script.
        $confirmed = $this->redis->eval($lua, [
            self::stateKey($manifest['run_id']),
            self::receiptKey($manifest['run_id']),
            self::latestReceiptKey((int) $manifest['account_id']),
            $manifest['run_id'],
            (string) $manifest['account_id'],
            $manifest['manifest_hash'],
            (string) $status['sequence'],
            $status['step'],
            $status['result'],
            $status['observed_at'],
            $payloadHash,
            (string) $eventId,
            (string) $ttl,
            json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ], 3);
        return $confirmed === 1 || $confirmed === 2;
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $protocol */
    public function protocolMatchesPersistedState(array $manifest, array $protocol): bool
    {
        if (!$this->proof->verifyManifest($manifest)) {
            return false;
        }
        $state = $this->decodeStoredArray(self::stateKey((string) $manifest['run_id']));
        $expectedScreenshot = ($protocol['screenshot'] ?? null) === 'latest.png'
            ? '/qa/frame/' . $manifest['run_id']
            : null;
        return $state !== null
            && array_keys($protocol) === ['run_id', 'sequence', 'step', 'result', 'screenshot', 'cursor', 'observed_at']
            && ($state['run_id'] ?? null) === $manifest['run_id']
            && ($state['account_id'] ?? null) === $manifest['account_id']
            && ($state['manifest_hash'] ?? null) === $manifest['manifest_hash']
            && ($state['sequence'] ?? null) === ($protocol['sequence'] ?? null)
            && ($state['step'] ?? null) === ($protocol['step'] ?? null)
            && ($state['status'] ?? null) === ($protocol['result'] ?? null)
            && ($state['observed_at'] ?? null) === ($protocol['observed_at'] ?? null)
            && ($state['screenshot_url'] ?? null) === $expectedScreenshot
            && ($state['cursor'] ?? null) === ($protocol['cursor'] ?? null);
    }

    /** @return array<string,mixed>|null */
    public function receiptForStatus(array $status, int $accountId): ?array
    {
        if (!$this->isStatusAuthoritative($status, $accountId)) {
            return null;
        }
        return $this->decodeStoredArray(self::receiptKey((string) $status['run_id']));
    }

    /** @param array<string,mixed> $status */
    public function isStatusAuthoritative(array $status, int $accountId): bool
    {
        if ($accountId <= 0 || !$this->proof->verifyStatus($status, $accountId)) {
            return false;
        }
        $runId = $status['run_id'] ?? null;
        if (!is_string($runId) || preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            return false;
        }
        $receipt = $this->decodeStoredArray(self::receiptKey($runId));
        return $receipt !== null && $this->receiptMatchesState($receipt, $status, $accountId);
    }

    /** @return array<string,mixed>|null */
    public function loadLatestReceipt(int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }
        $runId = $this->redis->get(self::latestReceiptKey($accountId));
        if (!is_string($runId) || preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            return null;
        }
        $receipt = $this->decodeStoredArray(self::receiptKey($runId));
        $status = is_array($receipt['status'] ?? null) ? $receipt['status'] : null;
        return $receipt !== null && $status !== null && $this->receiptMatchesState($receipt, $status, $accountId)
            ? $receipt
            : null;
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

    /** @return list<string> UUIDs removidos */
    public static function purgeExpiredFrames(
        string $privateRoot,
        ?int $now = null,
        int $retentionSeconds = 86400
    ): array {
        if ($privateRoot === '' || str_contains($privateRoot, "\0") || $retentionSeconds < 1 || is_link($privateRoot)) {
            throw new \InvalidArgumentException('Raiz de retenção QA inválida');
        }
        if (!is_dir($privateRoot)) {
            return [];
        }
        $root = realpath($privateRoot);
        if ($root === false) {
            throw new \RuntimeException('Raiz de retenção QA indisponível');
        }
        $cutoff = ($now ?? time()) - $retentionSeconds;
        $deleted = [];
        $entries = scandir($root);
        if (!is_array($entries)) {
            throw new \RuntimeException('Falha ao listar retenção QA');
        }
        foreach ($entries as $runId) {
            if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
                continue;
            }
            $directory = $root . DIRECTORY_SEPARATOR . $runId;
            $directoryStat = lstat($directory);
            if ($directoryStat === false || is_link($directory) || !is_dir($directory)) {
                continue;
            }
            $resolved = realpath($directory);
            if ($resolved === false || dirname($resolved) !== $root) {
                continue;
            }
            $children = scandir($directory);
            if (!is_array($children)) {
                continue;
            }
            $children = array_values(array_diff($children, ['.', '..']));
            if ($children !== [] && $children !== ['latest.png']) {
                continue;
            }
            $latest = $directory . DIRECTORY_SEPARATOR . 'latest.png';
            $latestMtime = 0;
            if ($children === ['latest.png']) {
                $latestStat = lstat($latest);
                if ($latestStat === false || is_link($latest) || !is_file($latest)) {
                    continue;
                }
                $latestMtime = (int) ($latestStat['mtime'] ?? 0);
            }
            $lastChanged = max((int) ($directoryStat['mtime'] ?? 0), $latestMtime);
            if ($lastChanged > $cutoff) {
                continue;
            }
            if ($children === ['latest.png'] && !unlink($latest)) {
                continue;
            }
            if (!rmdir($directory)) {
                continue;
            }
            $deleted[] = $runId;
        }
        sort($deleted, SORT_STRING);
        return $deleted;
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

    /** @return array<string,mixed>|null */
    private function decodeStoredArray(string $key): ?array
    {
        $raw = $this->redis->get($key);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $status */
    private function receiptMatchesState(array $receipt, array $status, int $accountId): bool
    {
        $runId = $status['run_id'];
        $payloadHash = self::statusHash($status);
        $expiresAt = strtotime((string) ($receipt['manifest_expires_at'] ?? ''));
        $observedAt = strtotime((string) ($status['observed_at'] ?? ''));
        if (($receipt['run_id'] ?? null) !== $runId
            || ($receipt['account_id'] ?? null) !== $accountId
            || ($receipt['manifest_hash'] ?? null) !== ($status['manifest_hash'] ?? null)
            || ($receipt['sequence'] ?? null) !== ($status['sequence'] ?? null)
            || ($receipt['step'] ?? null) !== ($status['step'] ?? null)
            || ($receipt['result'] ?? null) !== ($status['result'] ?? null)
            || ($receipt['observed_at'] ?? null) !== ($status['observed_at'] ?? null)
            || ($receipt['payload_hash'] ?? null) !== $payloadHash
            || !is_int($receipt['event_id'] ?? null) || $receipt['event_id'] <= 0
            || $expiresAt === false || $observedAt === false || $observedAt > $expiresAt
        ) {
            return false;
        }
        $state = $this->decodeStoredArray(self::stateKey($runId));
        if ($state === null
            || ($state['run_id'] ?? null) !== $runId
            || ($state['account_id'] ?? null) !== $accountId
            || ($state['manifest_hash'] ?? null) !== $status['manifest_hash']
            || ($state['sequence'] ?? null) !== $status['sequence']
            || ($state['step'] ?? null) !== $status['step']
            || ($state['status'] ?? null) !== $status['result']
            || ($state['observed_at'] ?? null) !== $status['observed_at']
            || ($state['receipt_hash'] ?? null) !== $payloadHash
            || ($state['receipt_event_id'] ?? null) !== $receipt['event_id']
        ) {
            return false;
        }
        if ($status['result'] !== 'running') {
            return in_array($status['result'], ['passed', 'failed', 'blocked'], true);
        }
        $manifest = $this->loadManifest($runId);
        return $manifest !== null
            && $manifest['account_id'] === $accountId
            && $manifest['manifest_hash'] === $status['manifest_hash'];
    }

    /** @param array<string,mixed> $manifest */
    private function failPendingClosed(array $manifest): void
    {
        $currentRaw = $this->redis->get(self::stateKey($manifest['run_id']));
        $current = is_string($currentRaw) ? json_decode($currentRaw, true) : null;
        $sequence = is_array($current) && is_int($current['sequence'] ?? null) ? $current['sequence'] : 0;
        if ($sequence >= count(PregaoQaWorkerProtocol::STEPS)) {
            return;
        }
        $this->updateState($manifest, [
            'run_id' => $manifest['run_id'],
            'sequence' => $sequence + 1,
            'step' => PregaoQaWorkerProtocol::STEPS[$sequence],
            'result' => 'blocked',
            'screenshot' => null,
            'cursor' => null,
            'observed_at' => (string) $manifest['expires_at'],
        ]);
    }

    private static function decodeJob(string $rawJob): ?string
    {
        $job = json_decode($rawJob, true);
        if (!is_array($job) || array_keys($job) !== ['run_id'] || !is_string($job['run_id'])
            || preg_match(self::RUN_ID_PATTERN, $job['run_id']) !== 1
        ) {
            return null;
        }
        return $job['run_id'];
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
