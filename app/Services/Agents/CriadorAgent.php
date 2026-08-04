<?php

declare(strict_types=1);

namespace App\Services\Agents;

/** Gera rascunhos determinísticos somente de snapshots do contexto. */
final class CriadorAgent implements AgentInterface
{
    public const NAME = 'criador';

    public function __construct()
    {
        if (func_num_args() !== 0) {
            throw new \InvalidArgumentException('snapshot agents do not accept dependencies');
        }
    }

    public function name(): string { return self::NAME; }

    public function run(AgentContext $context): AgentResult
    {
        $metadata = $context->metadata();
        $requested = $metadata['creator_request'] ?? null;
        if (!$this->validRequest($requested)) {
            return AgentResult::blocked(self::NAME, 'creator_request_blocked');
        }

        $source = $metadata['creator_source_snapshot'] ?? null;
        if (!$this->validSource($source, $requested['source_mlb_id'])) {
            return AgentResult::failed(self::NAME, 'invalid_creator_source_snapshot');
        }
        if ($source['valid'] !== true
            || $source['duplicate'] !== false
            || $source['item']['id'] !== $requested['source_mlb_id']
        ) {
            return AgentResult::blocked(self::NAME, 'creator_request_blocked');
        }

        $idempotencyKey = hash('sha256', $context->accountId() . ':' . $requested['source_mlb_id']);
        $draft = [
            'id' => 'draft-' . substr($idempotencyKey, 0, 24),
            'source_mlb_id' => $requested['source_mlb_id'],
            'status' => 'draft',
            'start_paused' => true,
            'include_description' => false,
            'include_pictures' => false,
        ];
        $title = $source['item']['title'] ?? null;
        if (is_string($title) && trim($title) !== '') {
            $draft['title'] = trim($title);
        }

        return AgentResult::success(self::NAME, 'draft_ready', [
            'draft' => $draft,
            'idempotency_key' => $idempotencyKey,
            'read_only' => true,
            'human_gate' => ['required' => true, 'status' => 'pending'],
            'publish_allowed' => false,
        ]);
    }

    private function validRequest(mixed $request): bool
    {
        return is_array($request)
            && array_keys($request) === ['source_mlb_id']
            && is_string($request['source_mlb_id'])
            && preg_match('/^MLB[0-9]+$/', $request['source_mlb_id']) === 1;
    }

    private function validSource(mixed $source, string $mlbId): bool
    {
        if (!is_array($source) || !$this->hasExactKeys($source, ['valid', 'duplicate', 'item'])) {
            return false;
        }
        if (!is_bool($source['valid']) || !is_bool($source['duplicate']) || !is_array($source['item'])) {
            return false;
        }
        $item = $source['item'];
        $allowed = ['id', 'title', 'published', 'permalink'];
        foreach (array_keys($item) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return false;
            }
        }
        if (!array_key_exists('id', $item) || !is_string($item['id'])) {
            return false;
        }
        if (array_key_exists('title', $item) && !is_string($item['title'])) {
            return false;
        }
        if (array_key_exists('published', $item) && !is_bool($item['published'])) {
            return false;
        }
        if (array_key_exists('permalink', $item) && !is_string($item['permalink'])) {
            return false;
        }

        return $item['id'] === $mlbId || preg_match('/^MLB[0-9]+$/', $item['id']) === 1;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }
}
