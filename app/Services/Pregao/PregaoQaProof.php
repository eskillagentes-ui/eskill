<?php

declare(strict_types=1);

namespace App\Services\Pregao;

final class PregaoQaProof
{
    private const STATUS_MAX_AGE_SECONDS = 86400;
    private const STATUS_FUTURE_SKEW_SECONDS = 60;

    public const STATUS_PROJECTION_KEYS = [
        'elapsed_ms', 'executed', 'log', 'observed_at', 'result', 'run_id', 'running', 'sequence',
        'status', 'step', 'stream_url', 'suite', 'test', 'trusted', 'video_url',
    ];

    private const MANIFEST_KEYS = [
        'account_id', 'created_at', 'expires_at', 'manifest_hash', 'run_id', 'signature', 'user_id',
    ];

    private const STATUS_KEYS = [
        'cursor', 'manifest_hash', 'observed_at', 'result', 'run_id', 'running', 'screenshot_url', 'sequence',
        'signature', 'started_at', 'step', 'stream_url', 'suite', 'test', 'video_url',
    ];

    private string $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Chave de assinatura QA inválida');
        }
        $this->secret = $secret;
    }

    public static function fromEnvironment(): ?self
    {
        $secret = (string) ($_ENV['PREGAO_QA_SIGNING_KEY'] ?? getenv('PREGAO_QA_SIGNING_KEY') ?: '');
        if (strlen($secret) < 32) {
            $appKey = (string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
            if (strlen($appKey) < 32) {
                return null;
            }
            $secret = hash_hmac('sha256', 'pregao-qa-live-v1', $appKey);
        }
        return new self($secret);
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function signManifest(array $manifest): array
    {
        $baseKeys = array_keys($manifest);
        sort($baseKeys, SORT_STRING);
        if ($baseKeys !== ['account_id', 'created_at', 'expires_at', 'run_id', 'user_id']) {
            throw new \InvalidArgumentException('Manifesto QA inválido');
        }
        $this->assertManifestBase($manifest);
        $hash = hash('sha256', self::canonicalJson($manifest));
        $signed = $manifest + ['manifest_hash' => $hash];
        $signed['signature'] = hash_hmac('sha256', self::canonicalJson($signed), $this->secret);
        return $signed;
    }

    /** @param array<string, mixed> $manifest */
    public function verifyManifest(array $manifest): bool
    {
        try {
            $keys = array_keys($manifest);
            sort($keys, SORT_STRING);
            if ($keys !== self::MANIFEST_KEYS) {
                return false;
            }
            $this->assertManifestBase($manifest);
            if (!is_string($manifest['manifest_hash']) || preg_match('/\A[a-f0-9]{64}\z/D', $manifest['manifest_hash']) !== 1
                || !is_string($manifest['signature']) || preg_match('/\A[a-f0-9]{64}\z/D', $manifest['signature']) !== 1
            ) {
                return false;
            }
            $base = $manifest;
            unset($base['manifest_hash'], $base['signature']);
            if (!hash_equals(hash('sha256', self::canonicalJson($base)), $manifest['manifest_hash'])) {
                return false;
            }
            $signed = $manifest;
            $signature = $signed['signature'];
            unset($signed['signature']);
            return hash_equals(hash_hmac('sha256', self::canonicalJson($signed), $this->secret), $signature);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $status @return array<string, mixed> */
    public function signStatus(array $status, int $accountId): array
    {
        if (!$this->isStatusBaseValid($status, $accountId)) {
            throw new \InvalidArgumentException('Evidência QA inválida');
        }
        $status['signature'] = hash_hmac(
            'sha256',
            self::canonicalJson(['account_id' => $accountId, 'status' => $status]),
            $this->secret
        );
        return $status;
    }

    /** @param array<string, mixed> $status */
    public function verifyStatus(array $status, int $accountId): bool
    {
        $keys = array_keys($status);
        sort($keys, SORT_STRING);
        if ($keys !== self::STATUS_KEYS || !is_string($status['signature'])) {
            return false;
        }
        $unsigned = $status;
        $signature = $unsigned['signature'];
        unset($unsigned['signature']);
        if (!$this->isStatusBaseValid($unsigned, $accountId)
            || !$this->isStatusFresh($unsigned['observed_at'])
        ) {
            return false;
        }
        $expected = hash_hmac(
            'sha256',
            self::canonicalJson(['account_id' => $accountId, 'status' => $unsigned]),
            $this->secret
        );
        return preg_match('/\A[a-f0-9]{64}\z/D', $signature) === 1 && hash_equals($expected, $signature);
    }

    /** @param array<string, mixed> $status @return array<string, mixed>|null */
    public function projectStatus(array $status, int $accountId): ?array
    {
        if (!$this->verifyStatus($status, $accountId)) {
            return null;
        }
        $started = strtotime($status['started_at']);
        $observed = strtotime($status['observed_at']);
        if ($started === false || $observed === false || $observed < $started) {
            return null;
        }
        $running = $status['result'] === 'running';
        return [
            'elapsed_ms' => ($observed - $started) * 1000,
            'executed' => true,
            'log' => [],
            'observed_at' => $status['observed_at'],
            'result' => $running ? null : $status['result'],
            'run_id' => $status['run_id'],
            'running' => $running,
            'sequence' => $status['sequence'],
            'status' => $status['result'],
            'step' => $status['step'],
            'stream_url' => $status['stream_url'],
            'suite' => $status['suite'],
            'test' => $status['test'],
            'trusted' => true,
            'video_url' => $status['video_url'],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifestBase(array $manifest): void
    {
        if (!is_string($manifest['run_id'] ?? null)
            || preg_match(PregaoQaRunService::RUN_ID_PATTERN, $manifest['run_id']) !== 1
            || !is_int($manifest['account_id'] ?? null) || $manifest['account_id'] <= 0
            || !is_int($manifest['user_id'] ?? null) || $manifest['user_id'] <= 0
            || !self::isIsoTimestamp($manifest['created_at'] ?? null)
            || !self::isIsoTimestamp($manifest['expires_at'] ?? null)
        ) {
            throw new \InvalidArgumentException('Manifesto QA inválido');
        }
    }

    /** @param array<string, mixed> $status */
    private function isStatusBaseValid(array $status, int $accountId): bool
    {
        $keys = array_keys($status);
        sort($keys, SORT_STRING);
        $expected = self::STATUS_KEYS;
        $expected = array_values(array_filter($expected, static fn (string $key): bool => $key !== 'signature'));
        sort($expected, SORT_STRING);
        return $keys === $expected
            && $accountId > 0
            && is_string($status['run_id'])
            && preg_match(PregaoQaRunService::RUN_ID_PATTERN, $status['run_id']) === 1
            && is_int($status['sequence']) && $status['sequence'] >= 0
            && is_string($status['step']) && in_array($status['step'], PregaoQaWorkerProtocol::STEPS, true)
            && is_string($status['result']) && in_array($status['result'], PregaoQaWorkerProtocol::RESULTS, true)
            && is_bool($status['running'])
            && $status['running'] === ($status['result'] === 'running')
            && is_string($status['suite']) && $status['suite'] === 'pregao-live'
            && is_string($status['test']) && $status['test'] === $status['step']
            && self::isCursor($status['cursor'])
            && self::isIsoTimestamp($status['started_at'])
            && array_key_exists('video_url', $status) && $status['video_url'] === null
            && (($status['stream_url'] === null) || $status['stream_url'] === '/qa/live/' . $status['run_id'])
            && (($status['screenshot_url'] === null) || $status['screenshot_url'] === '/qa/frame/' . $status['run_id'])
            && is_string($status['manifest_hash'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $status['manifest_hash']) === 1
            && self::isIsoTimestamp($status['observed_at']);
    }

    private static function isCursor(mixed $cursor): bool
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

    private function isStatusFresh(string $observedAt): bool
    {
        $observedEpoch = strtotime($observedAt);
        if ($observedEpoch === false) {
            return false;
        }
        $now = time();
        return $observedEpoch >= $now - self::STATUS_MAX_AGE_SECONDS
            && $observedEpoch <= $now + self::STATUS_FUTURE_SKEW_SECONDS;
    }

    private static function isIsoTimestamp(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return false;
        }
        return strtotime($value) !== false;
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    /** @param array<string, mixed> $value */
    private static function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!self::isList($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
