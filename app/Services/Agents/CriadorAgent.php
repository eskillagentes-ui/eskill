<?php

declare(strict_types=1);

namespace App\Services\Agents;

use Throwable;

/** Gera exclusivamente rascunhos determinísticos em memória a partir de uma fonte read-only. */
final class CriadorAgent implements AgentInterface
{
    public const NAME = 'criador';

    /** @var callable(int, array<string, mixed>): array<string, mixed> */
    private $sourcePort;

    /** @var array<string, AgentResult> */
    private array $successfulResults = [];

    /** @param callable(int, array<string, mixed>): array<string, mixed> $sourcePort */
    public function __construct(callable $sourcePort)
    {
        $this->sourcePort = $sourcePort;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(AgentContext $context): AgentResult
    {
        $requested = $context->metadata()['creator_request'] ?? null;
        if (!is_array($requested)
            || !isset($requested['source_mlb_id'])
            || !is_string($requested['source_mlb_id'])
            || preg_match('/^MLB[0-9]+$/', $requested['source_mlb_id']) !== 1
        ) {
            return AgentResult::blocked(self::NAME, 'creator_request_blocked');
        }

        $idempotencyKey = hash(
            'sha256',
            $context->accountId() . ':' . $requested['source_mlb_id']
        );
        if (isset($this->successfulResults[$idempotencyKey])) {
            return $this->successfulResults[$idempotencyKey];
        }

        $request = [
            'source_mlb_id' => $requested['source_mlb_id'],
            'start_paused' => true,
            'include_description' => false,
            'include_pictures' => false,
            'idempotency_key' => $idempotencyKey,
        ];

        try {
            $source = ($this->sourcePort)($context->accountId(), $request);
        } catch (Throwable) {
            return AgentResult::failed(self::NAME, 'creator_unavailable');
        }

        if (!is_array($source) || $this->isRemoteFailure($source)) {
            return AgentResult::failed(self::NAME, 'creator_unavailable');
        }

        $sourceItem = $source['item'] ?? null;
        if (($source['valid'] ?? false) !== true
            || ($source['duplicate'] ?? true) !== false
            || !is_array($sourceItem)
            || ($sourceItem['id'] ?? null) !== $request['source_mlb_id']
        ) {
            return AgentResult::blocked(self::NAME, 'creator_request_blocked');
        }

        $draft = [
            'id' => 'draft-' . substr($idempotencyKey, 0, 24),
            'source_mlb_id' => $request['source_mlb_id'],
            'status' => 'draft',
            'start_paused' => true,
            'include_description' => false,
            'include_pictures' => false,
        ];
        if (isset($sourceItem['title'])
            && is_string($sourceItem['title'])
            && trim($sourceItem['title']) !== ''
        ) {
            $draft['title'] = trim($sourceItem['title']);
        }

        $result = AgentResult::success(
            self::NAME,
            'draft_ready',
            [
                'draft' => $draft,
                'idempotency_key' => $idempotencyKey,
                'read_only' => true,
                'human_gate' => [
                    'required' => true,
                    'status' => 'pending',
                ],
                'publish_allowed' => false,
            ]
        );
        $this->successfulResults[$idempotencyKey] = $result;

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function isRemoteFailure(array $payload): bool
    {
        $status = $payload['http_status'] ?? $payload['status'] ?? null;
        if (is_string($status) && ctype_digit($status)) {
            $status = (int) $status;
        }

        return is_int($status) && $status >= 400 && $status <= 599;
    }
}
