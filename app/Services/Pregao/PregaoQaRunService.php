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
    public const LOCK_TTL_SECONDS = 240;
    public const EVIDENCE_TTL_SECONDS = 86400;
    public const COOLDOWN_TTL_SECONDS = 60;
    public const MAX_QUEUE_DEPTH = 8;
    public const MAX_FRAME_BYTES = 4 * 1024 * 1024;
    private const ACTIVE_PREFIX = 'pregao:qa:active:';
    private const COOLDOWN_ACCOUNT_PREFIX = 'pregao:qa:cooldown:account:';
    private const COOLDOWN_USER_PREFIX = 'pregao:qa:cooldown:user:';

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
        // Lua constante: apenas chaves, UUID e TTL validados entram via KEYS/ARGV.
        $admissionLua = "if redis.call('EXISTS', KEYS[2]) == 1 or redis.call('EXISTS', KEYS[3]) == 1 then return 0 end local acquired = redis.call('SET', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2]) if acquired then return 1 end return 0";
        $admitted = $this->redis->eval($admissionLua, [
            self::activeKey($accountId),
            self::cooldownAccountKey($accountId),
            self::cooldownUserKey($userId),
            $runId,
            self::RUN_TTL_SECONDS,
        ], 3);
        if ($admitted === 0) {
            throw new \DomainException('Já existe QA ativo para esta conta');
        }
        if ($admitted !== 1) {
            throw new \RuntimeException('Falha ao reservar QA');
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
            $rawJob = json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR);
            // LLEN + LPUSH precisam compartilhar a mesma operação para nunca ultrapassar o limite.
            $enqueueLua = "local depth = redis.call('LLEN', KEYS[1]) + redis.call('LLEN', KEYS[2]) if depth >= tonumber(ARGV[2]) then return 0 end return redis.call('LPUSH', KEYS[1], ARGV[1])";
            $queued = $this->redis->eval(
                $enqueueLua,
                [self::QUEUE_KEY, self::PENDING_KEY, $rawJob, self::MAX_QUEUE_DEPTH],
                2
            );
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

    /** @param null|callable(array<string,mixed>,array<string,mixed>):bool $ensureEvidence */
    public function recoverPending(?callable $ensureEvidence = null): int
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
                if ($ensureEvidence === null
                    || !is_array($terminalManifest)
                    || !$this->proof->verifyManifest($terminalManifest)
                    || $terminalManifest['run_id'] !== $runId
                    || $ensureEvidence($terminalManifest, $state) !== true
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
            if (is_array($state)
                && ($state['status'] ?? null) === 'running'
                && is_int($state['sequence'] ?? null)
                && $state['sequence'] > 0
                && $ensureEvidence !== null
                && $ensureEvidence($manifest, $state) !== true
            ) {
                continue;
            }
            if ($expires === false || $expires < time()) {
                $this->failPendingClosed($manifest);
                $terminalRaw = $this->redis->get(self::stateKey($runId));
                $terminal = is_string($terminalRaw) ? json_decode($terminalRaw, true) : null;
                if ($ensureEvidence !== null
                    && is_array($terminal)
                    && $ensureEvidence($manifest, $terminal) === true
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
if ARGV[8] == '1' then
    redis.call('SETEX', KEYS[2], tonumber(ARGV[9]), '1')
    redis.call('SETEX', KEYS[3], tonumber(ARGV[9]), '1')
end
return 1
LUA;
        // Lua constante: dados entram somente por KEYS/ARGV; nenhuma entrada é interpolada no script.
        $updated = $this->redis->eval($lua, [
            self::stateKey($manifest['run_id']),
            self::cooldownAccountKey((int) $manifest['account_id']),
            self::cooldownUserKey((int) $manifest['user_id']),
            $manifest['run_id'],
            (string) $manifest['account_id'],
            $manifest['manifest_hash'],
            (string) $previousSequence,
            $expectedStatus,
            (string) $ttl,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $terminal ? '1' : '0',
            (string) self::COOLDOWN_TTL_SECONDS,
        ], 3);
        if ($updated !== 1) {
            throw new \InvalidArgumentException('Progressão QA atual inválida ou concorrente');
        }
    }

    public function renew(string $runId, string $token): bool
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1 || preg_match('/\A[a-f0-9]{48}\z/D', $token) !== 1) {
            return false;
        }
        // Lua constante: chave, token e TTL passam somente por KEYS/ARGV.
        $lua = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('EXPIRE', KEYS[1], ARGV[2]) else return 0 end";
        return $this->redis->eval(
            $lua,
            [self::lockKey($runId), $token, self::LOCK_TTL_SECONDS],
            1
        ) === 1;
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

    public static function cooldownAccountKey(int $accountId): string
    {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException('Conta QA inválida');
        }
        return self::COOLDOWN_ACCOUNT_PREFIX . $accountId;
    }

    public static function cooldownUserKey(int $userId): string
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Usuário QA inválido');
        }
        return self::COOLDOWN_USER_PREFIX . $userId;
    }

    public static function manifestKey(string $runId): string
    {
        return 'pregao:qa:manifest:' . $runId;
    }

    public static function stateKey(string $runId): string
    {
        return 'pregao:qa:state:' . $runId;
    }

    public static function receiptKey(string $runId, int $sequence): string
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1
            || $sequence < 1
            || $sequence > count(PregaoQaWorkerProtocol::STEPS)
        ) {
            throw new \InvalidArgumentException('Receipt QA inválido');
        }
        return 'pregao:qa:receipt:' . $runId . ':' . $sequence;
    }

    public static function receiptPublishedKey(string $runId, int $sequence): string
    {
        return self::receiptKey($runId, $sequence) . ':published';
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
        return hash('sha256', json_encode(
            self::canonicalize($status),
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
        $latest = [
            'run_id' => $manifest['run_id'],
            'sequence' => $status['sequence'],
            'event_id' => $eventId,
            'payload_hash' => $payloadHash,
            'status_signature' => $status['signature'],
        ];
        $lua = <<<'LUA'
local existing = redis.call('GET', KEYS[2])
if existing then
    local receiptOk, receipt = pcall(cjson.decode, existing)
    if receiptOk and receipt.payload_hash == ARGV[8] and tonumber(receipt.event_id) == tonumber(ARGV[9]) then
        return 2
    end
    return 0
end
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
local latestRaw = redis.call('GET', KEYS[3])
if latestRaw then
    local latestOk, latest = pcall(cjson.decode, latestRaw)
    if not latestOk or type(latest) ~= 'table' or tonumber(latest.event_id) == nil then
        return 0
    end
    if tonumber(latest.event_id) >= tonumber(ARGV[9]) then
        return 0
    end
end
state.receipt_hash = ARGV[8]
state.receipt_event_id = tonumber(ARGV[9])
redis.call('SETEX', KEYS[1], tonumber(ARGV[10]), cjson.encode(state))
redis.call('SETEX', KEYS[2], tonumber(ARGV[10]), ARGV[11])
redis.call('SETEX', KEYS[3], tonumber(ARGV[10]), ARGV[12])
return 1
LUA;
        // Lua constante: dados entram somente por KEYS/ARGV; nenhuma entrada é interpolada no script.
        $confirmed = $this->redis->eval($lua, [
            self::stateKey($manifest['run_id']),
            self::receiptKey($manifest['run_id'], (int) $status['sequence']),
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
            json_encode($latest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
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
            && self::canonicalValuesEqual($state['cursor'] ?? null, $protocol['cursor'] ?? null);
    }

    /** @return array<string,mixed>|null */
    public function receiptForStatus(array $status, int $accountId): ?array
    {
        if ($accountId <= 0 || !$this->proof->verifyStatus($status, $accountId)) {
            return null;
        }
        $runId = $status['run_id'] ?? null;
        $sequence = $status['sequence'] ?? null;
        if (!is_string($runId) || preg_match(self::RUN_ID_PATTERN, $runId) !== 1 || !is_int($sequence)) {
            return null;
        }
        $receipt = $this->decodeStoredArray(self::receiptKey($runId, $sequence));
        return $receipt !== null && $this->receiptMatchesEvidence($receipt, $status, $accountId)
            ? $receipt
            : null;
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
        $receipt = $this->receiptForStatus($status, $accountId);
        $latest = $this->decodeStoredArray(self::latestReceiptKey($accountId));
        return $receipt !== null
            && $latest !== null
            && ($latest['run_id'] ?? null) === $runId
            && ($latest['sequence'] ?? null) === ($status['sequence'] ?? null)
            && ($latest['event_id'] ?? null) === ($receipt['event_id'] ?? null)
            && ($latest['payload_hash'] ?? null) === ($receipt['payload_hash'] ?? null);
    }

    /** @return array<string,mixed>|null */
    public function loadLatestReceipt(int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }
        $latest = $this->decodeStoredArray(self::latestReceiptKey($accountId));
        $runId = $latest['run_id'] ?? null;
        $sequence = $latest['sequence'] ?? null;
        if (!is_string($runId) || preg_match(self::RUN_ID_PATTERN, $runId) !== 1 || !is_int($sequence)) {
            return null;
        }
        $receipt = $this->decodeStoredArray(self::receiptKey($runId, $sequence));
        $status = is_array($receipt['status'] ?? null) ? $receipt['status'] : null;
        return $receipt !== null && $status !== null && $this->isStatusAuthoritative($status, $accountId)
            ? $receipt
            : null;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $event */
    public function publishEvidenceOnce(array $receipt, array $event): bool
    {
        $status = is_array($receipt['status'] ?? null) ? $receipt['status'] : null;
        $accountId = $receipt['account_id'] ?? null;
        $runId = $receipt['run_id'] ?? null;
        $sequence = $receipt['sequence'] ?? null;
        if (!is_array($status)
            || !is_int($accountId)
            || !is_string($runId)
            || !is_int($sequence)
            || !$this->receiptMatchesEvidence($receipt, $status, $accountId)
            || ($event['type'] ?? null) !== 'qa.status'
            || ($event['account_id'] ?? null) !== $accountId
            || ($event['payload'] ?? null) !== $status
            || ($event['ts'] ?? null) !== ($receipt['event_ts'] ?? null)
        ) {
            return false;
        }
        $ttl = in_array($status['result'], ['passed', 'failed', 'blocked'], true)
            ? self::EVIDENCE_TTL_SECONDS
            : self::RUN_TTL_SECONDS;
        $lua = <<<'LUA'
local receiptRaw = redis.call('GET', KEYS[1])
local latestRaw = redis.call('GET', KEYS[2])
if not receiptRaw or not latestRaw then return 0 end
local receiptOk, stored = pcall(cjson.decode, receiptRaw)
local latestOk, latest = pcall(cjson.decode, latestRaw)
if not receiptOk or not latestOk
    or stored.payload_hash ~= ARGV[1]
    or tonumber(stored.event_id) ~= tonumber(ARGV[2])
    or latest.run_id ~= ARGV[3]
    or tonumber(latest.sequence) ~= tonumber(ARGV[4])
    or tonumber(latest.event_id) ~= tonumber(ARGV[2])
    or latest.payload_hash ~= ARGV[1] then
    return 0
end
if redis.call('EXISTS', KEYS[3]) == 1 then return 2 end
redis.call('SETEX', KEYS[3], tonumber(ARGV[5]), '1')
redis.call('PUBLISH', ARGV[6], ARGV[7])
redis.call('LPUSH', ARGV[8], ARGV[7])
redis.call('LTRIM', ARGV[8], 0, 999)
return 1
LUA;
        // Script constante: somente chaves e valores validados entram via KEYS/ARGV.
        $published = $this->redis->eval($lua, [
            self::receiptKey($runId, $sequence),
            self::latestReceiptKey($accountId),
            self::receiptPublishedKey($runId, $sequence),
            $receipt['payload_hash'],
            (string) $receipt['event_id'],
            $runId,
            (string) $sequence,
            (string) $ttl,
            PregaoEmitService::CHANNEL,
            json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'pregao:fanout',
        ], 3);
        return $published === 1 || $published === 2;
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
        if (basename($source) !== 'latest.png' || preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            throw new \InvalidArgumentException('Frame QA inválido');
        }
        $sourceFile = self::openVerifiedRegularFile($source);
        if ($sourceFile === null) {
            throw new \InvalidArgumentException('Frame QA inválido');
        }
        [$sourceHandle, $sourceStat] = $sourceFile;
        $temporary = null;
        try {
            $root = self::ensurePrivateRoot($privateRoot);
            $directory = self::resolveRunDirectory($root, $runId, true);
            if ($directory === null) {
                throw new \InvalidArgumentException('Diretório de retenção QA inválido');
            }
            $destination = $directory . DIRECTORY_SEPARATOR . 'latest.png';
            $destinationStat = @lstat($destination);
            if (is_array($destinationStat) && !self::isVerifiedFrameStat($destinationStat)) {
                throw new \InvalidArgumentException('Frame QA inválido');
            }

            $temporary = $directory . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $temporaryHandle = @fopen($temporary, 'x+b');
            if ($temporaryHandle === false) {
                throw new \RuntimeException('Falha ao reter frame QA');
            }
            try {
                $copied = stream_copy_to_stream($sourceHandle, $temporaryHandle, self::MAX_FRAME_BYTES + 1);
                if ($copied === false || $copied > self::MAX_FRAME_BYTES
                    || !self::isUnchangedVerifiedHandle($sourceHandle, $sourceStat, $copied)
                ) {
                    throw new \InvalidArgumentException('Frame QA inválido');
                }
                if (!fflush($temporaryHandle)) {
                    throw new \RuntimeException('Falha ao reter frame QA');
                }
            } finally {
                fclose($temporaryHandle);
            }
            if (!chmod($temporary, 0600)) {
                throw new \RuntimeException('Falha ao proteger frame QA');
            }

            // Revalida root/run/destino imediatamente antes da troca atômica.
            if (self::resolveExistingPrivateRoot($root) !== $root
                || self::resolveRunDirectory($root, $runId, false) !== $directory
            ) {
                throw new \InvalidArgumentException('Diretório de retenção QA inválido');
            }
            $destinationStat = @lstat($destination);
            if (is_array($destinationStat) && !self::isVerifiedFrameStat($destinationStat)) {
                throw new \InvalidArgumentException('Frame QA inválido');
            }
            if (!rename($temporary, $destination)) {
                throw new \RuntimeException('Falha ao reter frame QA');
            }
            $temporary = null;

            $resolved = self::resolveRegularFramePath($root, $runId);
            if ($resolved !== $destination) {
                throw new \RuntimeException('Falha ao validar frame QA retido');
            }
            return $destination;
        } finally {
            fclose($sourceHandle);
            if (is_string($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public static function readLatestFrame(string $privateRoot, string $runId): ?string
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            return null;
        }
        try {
            $root = self::resolveExistingPrivateRoot($privateRoot);
            if ($root === null) {
                return null;
            }
            $frame = self::resolveRegularFramePath($root, $runId);
            if ($frame === null) {
                return null;
            }
            $opened = self::openVerifiedRegularFile($frame);
            if ($opened === null) {
                return null;
            }
            [$handle, $openedStat] = $opened;
            try {
                return self::readBoundedVerifiedHandle($handle, $openedStat);
            } finally {
                fclose($handle);
            }
        } catch (\Throwable) {
            return null;
        }
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

    private static function ensurePrivateRoot(string $privateRoot): string
    {
        $root = self::normalizeAbsolutePath($privateRoot);
        $current = '';
        foreach (explode('/', ltrim($root, '/')) as $component) {
            $current .= '/' . $component;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if ($stat === false) {
                if (!mkdir($current, 0700)) {
                    throw new \RuntimeException('Falha ao criar retenção QA');
                }
                clearstatcache(true, $current);
                $stat = @lstat($current);
            }
            if (!is_array($stat) || !self::isDirectoryStat($stat)) {
                throw new \InvalidArgumentException('Raiz de retenção QA inválida');
            }
        }
        $resolved = realpath($root);
        if ($resolved === false || $resolved !== $root) {
            throw new \InvalidArgumentException('Raiz de retenção QA inválida');
        }
        return $resolved;
    }

    private static function resolveExistingPrivateRoot(string $privateRoot): ?string
    {
        $root = self::normalizeAbsolutePath($privateRoot);
        if (!self::hasOnlyExistingNonLinkComponents($root)) {
            return null;
        }
        $stat = @lstat($root);
        if (!is_array($stat) || !self::isDirectoryStat($stat)) {
            return null;
        }
        $resolved = realpath($root);
        return is_string($resolved) && $resolved === $root ? $resolved : null;
    }

    private static function resolveRunDirectory(string $root, string $runId, bool $create): ?string
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            return null;
        }
        $directory = $root . DIRECTORY_SEPARATOR . $runId;
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        if ($stat === false && $create) {
            if (!mkdir($directory, 0700)) {
                throw new \RuntimeException('Falha ao criar retenção QA');
            }
            clearstatcache(true, $directory);
            $stat = @lstat($directory);
        }
        if (!is_array($stat) || !self::isDirectoryStat($stat)
            || !self::hasOnlyExistingNonLinkComponents($directory)
        ) {
            return null;
        }
        $resolved = realpath($directory);
        if (!is_string($resolved) || dirname($resolved) !== $root || basename($resolved) !== $runId) {
            return null;
        }
        return $resolved;
    }

    private static function resolveRegularFramePath(string $root, string $runId): ?string
    {
        $directory = self::resolveRunDirectory($root, $runId, false);
        if ($directory === null) {
            return null;
        }
        $frame = $directory . DIRECTORY_SEPARATOR . 'latest.png';
        clearstatcache(true, $frame);
        $stat = @lstat($frame);
        if (!is_array($stat) || !self::isVerifiedFrameStat($stat)
            || !self::hasOnlyExistingNonLinkComponents($frame)
        ) {
            return null;
        }
        $resolved = realpath($frame);
        if (!is_string($resolved) || $resolved !== $frame || dirname($resolved) !== $directory) {
            return null;
        }
        return $resolved;
    }

    /** @return array{0:resource,1:array<int|string,int>}|null */
    private static function openVerifiedRegularFile(string $path): ?array
    {
        try {
            $normalized = self::normalizeAbsolutePath($path);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (!self::hasOnlyExistingNonLinkComponents($normalized)) {
            return null;
        }
        clearstatcache(true, $normalized);
        $before = @lstat($normalized);
        $resolved = realpath($normalized);
        if (!is_array($before) || !self::isVerifiedFrameStat($before)
            || !is_string($resolved) || $resolved !== $normalized
        ) {
            return null;
        }
        $handle = @fopen($normalized, 'rb');
        if ($handle === false) {
            return null;
        }
        $opened = fstat($handle);
        if (!is_array($opened) || !self::isVerifiedFrameStat($opened)
            || !self::hasSameFileIdentity($before, $opened)
            || (int) $before['size'] !== (int) $opened['size']
        ) {
            fclose($handle);
            return null;
        }
        return [$handle, $opened];
    }

    /**
     * @param resource $handle
     * @param array<int|string,int> $validatedStat
     */
    private static function readBoundedVerifiedHandle($handle, array $validatedStat): ?string
    {
        if (!is_resource($handle)) {
            return null;
        }
        $beforeRead = fstat($handle);
        if (!is_array($beforeRead) || !self::isVerifiedFrameStat($beforeRead)
            || !self::hasSameFileIdentity($validatedStat, $beforeRead)
            || (int) ($validatedStat['size'] ?? -1) !== (int) $beforeRead['size']
        ) {
            return null;
        }
        $contents = stream_get_contents($handle, self::MAX_FRAME_BYTES + 1);
        if (!is_string($contents) || strlen($contents) > self::MAX_FRAME_BYTES) {
            return null;
        }
        return self::isUnchangedVerifiedHandle($handle, $beforeRead, strlen($contents))
            ? $contents
            : null;
    }

    /**
     * @param resource $handle
     * @param array<int|string,int> $validatedStat
     */
    private static function isUnchangedVerifiedHandle($handle, array $validatedStat, int $bytesRead): bool
    {
        if (!is_resource($handle) || $bytesRead < 0 || $bytesRead > self::MAX_FRAME_BYTES) {
            return false;
        }
        $afterRead = fstat($handle);
        return is_array($afterRead)
            && self::isVerifiedFrameStat($afterRead)
            && self::hasSameFileIdentity($validatedStat, $afterRead)
            && (int) ($validatedStat['size'] ?? -1) === $bytesRead
            && (int) $afterRead['size'] === $bytesRead;
    }

    /** @param array<int|string,int> $stat */
    private static function isVerifiedFrameStat(array $stat): bool
    {
        return self::isRegularStat($stat)
            && isset($stat['nlink'], $stat['size'])
            && (int) $stat['nlink'] === 1
            && (int) $stat['size'] >= 0
            && (int) $stat['size'] <= self::MAX_FRAME_BYTES;
    }

    /**
     * @param array<int|string,int> $left
     * @param array<int|string,int> $right
     */
    private static function hasSameFileIdentity(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino'];
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Caminho de retenção QA inválido');
        }
        $normalized = preg_replace('#/+#', '/', str_replace('\\', '/', $path));
        if (!is_string($normalized) || !str_starts_with($normalized, '/')) {
            throw new \InvalidArgumentException('Caminho de retenção QA inválido');
        }
        $normalized = rtrim($normalized, '/');
        $components = explode('/', ltrim($normalized, '/'));
        if ($normalized === '' || in_array('.', $components, true) || in_array('..', $components, true)) {
            throw new \InvalidArgumentException('Caminho de retenção QA inválido');
        }
        return $normalized;
    }

    private static function hasOnlyExistingNonLinkComponents(string $path): bool
    {
        $current = '';
        foreach (explode('/', ltrim($path, '/')) as $component) {
            $current .= '/' . $component;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if (!is_array($stat) || self::isLinkStat($stat)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<int|string,int> $stat */
    private static function isRegularStat(array $stat): bool
    {
        return (((int) ($stat['mode'] ?? 0)) & 0170000) === 0100000;
    }

    /** @param array<int|string,int> $stat */
    private static function isDirectoryStat(array $stat): bool
    {
        return (((int) ($stat['mode'] ?? 0)) & 0170000) === 0040000;
    }

    /** @param array<int|string,int> $stat */
    private static function isLinkStat(array $stat): bool
    {
        return (((int) ($stat['mode'] ?? 0)) & 0170000) === 0120000;
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
    private function receiptMatchesEvidence(array $receipt, array $status, int $accountId): bool
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
        if ($status['result'] !== 'running') {
            return in_array($status['result'], ['passed', 'failed', 'blocked'], true);
        }
        $manifest = $this->loadManifest($runId);
        return $manifest !== null
            && $manifest['account_id'] === $accountId
            && $manifest['manifest_hash'] === $status['manifest_hash'];
    }

    private static function canonicalValuesEqual(mixed $left, mixed $right): bool
    {
        return json_encode(self::canonicalize($left), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode(self::canonicalize($right), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        if ($keys !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }
        return $value;
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
