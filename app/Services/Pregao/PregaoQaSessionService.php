<?php

declare(strict_types=1);

namespace App\Services\Pregao;

use Redis;

final class PregaoQaSessionService
{
    private Redis $redis;
    private string $prefix;

    public function __construct(Redis $redis, string $prefix = 'PHPREDIS_SESSION:')
    {
        if ($prefix === '' || str_contains($prefix, "\0")) {
            throw new \InvalidArgumentException('Prefixo de sessão QA inválido');
        }
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function create(int $userId, int $accountId, int $ttlSeconds, ?string $runId = null): string
    {
        if ($userId <= 0 || $accountId <= 0 || $ttlSeconds < 30 || $ttlSeconds > PregaoQaRunService::RUN_TTL_SECONDS
            || ($runId !== null && preg_match(PregaoQaRunService::RUN_ID_PATTERN, $runId) !== 1)
        ) {
            throw new \InvalidArgumentException('Sessão QA inválida');
        }
        $id = bin2hex(random_bytes(24));
        $values = [
            'user_id' => $userId,
            'account_id' => $accountId,
            'active_ml_account_id' => $accountId,
            'qa_read_only' => true,
            'qa_expires_at' => time() + $ttlSeconds,
        ];
        if ($runId !== null) {
            $values['qa_run_id'] = $runId;
        }
        $encoded = '';
        foreach ($values as $key => $value) {
            $encoded .= $key . '|' . serialize($value);
        }
        if ($this->redis->setex($this->prefix . $id, $ttlSeconds, $encoded) !== true) {
            throw new \RuntimeException('Falha ao criar sessão QA');
        }
        return $id;
    }

    public function destroy(string $sessionId): void
    {
        if (preg_match('/\A[a-f0-9]{48}\z/D', $sessionId) !== 1) {
            return;
        }
        $this->redis->del($this->prefix . $sessionId);
    }
}
