<?php

declare(strict_types=1);

namespace App\Services\Agents;

use Throwable;

/**
 * Prepara rascunhos locais; publicacao permanece fora deste contrato.
 *
 * As portas legadas retornam payloads heterogeneos. O uso de mixed nos
 * PHPDocs e restrito a esses payloads, que sao validados antes de qualquer uso.
 */
final class CriadorAgent implements AgentInterface
{
    public const NAME = 'criador';

    /** @var callable(int, array<string, mixed>): array<string, mixed> */
    private $sourcePort;

    /** @var callable(int, array<string, mixed>): array<string, mixed> */
    private $draftPort;

    /** @var array<string, AgentResult> */
    private array $successfulResults = [];

    /**
     * @param callable(int, array<string, mixed>): array<string, mixed> $sourcePort
     * @param callable(int, array<string, mixed>): array<string, mixed> $draftPort
     */
    public function __construct(callable $sourcePort, callable $draftPort)
    {
        $this->sourcePort = $sourcePort;
        $this->draftPort = $draftPort;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(AgentContext $context): AgentResult
    {
        $metadata = $context->metadata();
        $requested = $metadata['creator_request'] ?? null;
        if (!is_array($requested)
            || !isset($requested['source_mlb_id'])
            || !is_string($requested['source_mlb_id'])
            || preg_match('/^MLB[0-9]+$/', $requested['source_mlb_id']) !== 1
        ) {
            return AgentResult::blocked(self::NAME, 'creator_request_blocked');
        }

        // Somente dados necessarios ao rascunho atravessam as portas. Campos de
        // aprovacao, publicacao ou acao vindos da metadata sao descartados.
        $request = [
            'source_mlb_id' => $requested['source_mlb_id'],
            'start_paused' => true,
            'include_description' => false,
            'include_pictures' => false,
        ];
        $idempotencyKey = hash(
            'sha256',
            $context->accountId() . ':' . $request['source_mlb_id']
        );
        $request['idempotency_key'] = $idempotencyKey;
        if (isset($this->successfulResults[$idempotencyKey])) {
            return $this->successfulResults[$idempotencyKey];
        }

        try {
            $source = ($this->sourcePort)($context->accountId(), $request);
        } catch (Throwable) {
            return AgentResult::failed(self::NAME, 'creator_unavailable');
        }

        if (!is_array($source)) {
            return AgentResult::failed(self::NAME, 'creator_unavailable');
        }

        if ($this->isRemoteFailure($source)) {
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

        try {
            $draftPayload = ($this->draftPort)($context->accountId(), $request);
        } catch (Throwable) {
            return AgentResult::failed(self::NAME, 'creator_unavailable');
        }

        if (!is_array($draftPayload)) {
            return AgentResult::failed(self::NAME, 'creator_unavailable');
        }

        if ($this->isRemoteFailure($draftPayload)) {
            return AgentResult::failed(self::NAME, 'creator_unavailable');
        }

        $draft = $draftPayload['draft'] ?? null;
        if (!is_array($draft)
            || !isset($draft['id'])
            || !is_string($draft['id'])
            || trim($draft['id']) === ''
            || preg_match('/^MLB[0-9]+$/', $draft['id']) === 1
        ) {
            return AgentResult::failed(self::NAME, 'creator_unavailable');
        }

        unset(
            $draft['published'],
            $draft['publish_allowed'],
            $draft['item_id'],
            $draft['permalink'],
            $draft['description'],
            $draft['pictures']
        );
        $draft['status'] = 'draft';
        $draft['start_paused'] = true;
        $draft['include_description'] = false;
        $draft['include_pictures'] = false;

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
