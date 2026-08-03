<?php

declare(strict_types=1);

namespace App\Services\Agents;

use Throwable;

/**
 * Produz exclusivamente recomendacoes a partir de observacoes e custos validados.
 *
 * As portas legadas retornam payloads heterogeneos. O uso de mixed nos
 * PHPDocs e restrito a esses payloads, validados antes de qualquer uso.
 */
final class OtimizadorAgent implements AgentInterface
{
    public const NAME = 'otimizador';

    /** @var callable(int): array */
    private $observePort;

    /** @var callable(int, list<string>): array */
    private $costValidationPort;

    /**
     * @param callable(int): array $observePort
     * @param callable(int, list<string>): array $costValidationPort
     */
    public function __construct(callable $observePort, callable $costValidationPort)
    {
        $this->observePort = $observePort;
        $this->costValidationPort = $costValidationPort;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(AgentContext $context): AgentResult
    {
        try {
            $observed = ($this->observePort)($context->accountId());
        } catch (Throwable) {
            return AgentResult::failed(self::NAME, 'optimizer_unavailable');
        }

        if (!is_array($observed)) {
            return AgentResult::failed(self::NAME, 'optimizer_unavailable');
        }

        if ($this->isRemoteFailure($observed)
            || !isset($observed['recommendations'])
            || !is_array($observed['recommendations'])
            || !$this->isSequentialList($observed['recommendations'])
            || $observed['recommendations'] === []
        ) {
            return AgentResult::failed(self::NAME, 'optimizer_unavailable');
        }

        $recommendations = $observed['recommendations'];
        $mlbIds = [];

        foreach ($recommendations as $recommendation) {
            if (!is_array($recommendation)
                || !isset($recommendation['mlb_id'])
                || !is_string($recommendation['mlb_id'])
                || trim($recommendation['mlb_id']) === ''
            ) {
                return AgentResult::failed(self::NAME, 'optimizer_unavailable');
            }

            $mlbIds[] = $recommendation['mlb_id'];
        }

        try {
            $costValidation = ($this->costValidationPort)($context->accountId(), $mlbIds);
        } catch (Throwable) {
            return AgentResult::failed(self::NAME, 'optimizer_unavailable');
        }

        if (!is_array($costValidation)) {
            return AgentResult::failed(self::NAME, 'optimizer_unavailable');
        }

        if ($this->isRemoteFailure($costValidation)
            || !isset($costValidation['items'])
            || !is_array($costValidation['items'])
        ) {
            return AgentResult::failed(self::NAME, 'optimizer_unavailable');
        }

        $allActionable = true;

        foreach ($recommendations as $index => $recommendation) {
            $mlbId = $recommendation['mlb_id'];
            $cost = $costValidation['items'][$mlbId] ?? null;
            $actionable = is_array($cost)
                && ($cost['validated'] ?? false) === true
                && ($cost['suspicious'] ?? true) === false
                && isset($cost['cost'])
                && is_numeric($cost['cost'])
                && (float) $cost['cost'] > 0.0;

            if (!$actionable) {
                $allActionable = false;
                $recommendations[$index] = [
                    'mlb_id' => $mlbId,
                    'actionable' => false,
                    'blocked' => true,
                    'blocked_reason' => 'cost_not_validated',
                ];
                continue;
            }

            $recommendation['actionable'] = true;
            $recommendation['blocked'] = false;
            $recommendations[$index] = $recommendation;
        }

        $data = [
            'recommendations' => $recommendations,
            'read_only' => true,
        ];

        if (!$allActionable) {
            return AgentResult::blocked(self::NAME, 'cost_validation_blocked', $data);
        }

        return AgentResult::success(self::NAME, 'recommendations_ready', $data);
    }

    /** @param array<int|string, mixed> $value */
    private function isSequentialList(array $value): bool
    {
        $expectedKey = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function isRemoteFailure(array $payload): bool
    {
        $status = $payload['http_status'] ?? $payload['status'] ?? null;
        if (is_string($status) && ctype_digit($status)) {
            $status = (int) $status;
        }

        return is_int($status) && ($status === 429 || $status >= 500);
    }
}
