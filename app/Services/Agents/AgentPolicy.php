<?php

declare(strict_types=1);

namespace App\Services\Agents;

/**
 * Gates transversais do runtime de agentes.
 *
 * - Escrita ML bloqueada quando mlWriteAutomation=false
 * - production sempre fail-closed (nunca libera escrita ML)
 * - emissão de op somente com stateChanged=true (P0)
 */
final class AgentPolicy
{
    /** @var list<string> */
    private const ML_WRITE_CAPABILITIES = [
        'ml.price.patch',
        'ml.ads.update',
        'ml.item.publish',
    ];

    public function isFailClosed(AgentContext $context): bool
    {
        return $context->environment() === 'production';
    }

    public function isMlWriteCapability(string $capability): bool
    {
        return in_array($capability, self::ML_WRITE_CAPABILITIES, true);
    }

    public function allowsMlWrite(AgentContext $context, string $capability): bool
    {
        if (!$this->isMlWriteCapability($capability)) {
            return false;
        }

        if ($this->isFailClosed($context)) {
            return false;
        }

        return $context->mlWriteAutomation();
    }

    public function allowsMlRead(AgentContext $context, string $capability): bool
    {
        // Leitura ML é sempre permitida neste runtime (somente API oficial).
        return $capability !== '' && $context->accountId() > 0;
    }

    public function allowsOpEmission(AgentResult $result): bool
    {
        return $result->stateChanged() === true;
    }
}
