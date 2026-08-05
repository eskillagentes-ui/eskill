<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use App\Services\Agents\AgentContext;
use App\Services\Agents\AgentPolicy;
use App\Services\Agents\AgentResult;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura do gate de escrita ML no framework Agents.
 *
 * Invariantes sob teste:
 *  - production e fail-closed (allowsMlWrite sempre false)
 *  - capability fora da allowlist nunca libera
 *  - capabilities allowlistadas exigem mlWriteAutomation=true e !production
 *  - leitura e sempre permitida (conta valida, capability nao-vazia)
 *  - emissao de op requer status=success + stateChanged + ops allowlistadas
 */
final class AgentPolicyTest extends TestCase
{
    private AgentPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AgentPolicy();
    }

    private function ctx(string $env, bool $mlWrite): AgentContext
    {
        return new AgentContext(1335, $env, 'corr-test-0001', $mlWrite);
    }

    public function testAllowsMlWriteCapabilityListIsExact(): void
    {
        $allowlisted = ['ml.price.patch', 'ml.ads.update', 'ml.item.publish'];

        foreach ($allowlisted as $cap) {
            $this->assertTrue(
                $this->policy->isMlWriteCapability($cap),
                "expected {$cap} to be allowlisted"
            );
        }

        foreach (['ml.user.delete', 'account.export', '*'] as $cap) {
            $this->assertFalse(
                $this->policy->isMlWriteCapability($cap),
                "expected {$cap} to be denied"
            );
        }
    }

    public function testProductionIsAlwaysFailClosed(): void
    {
        $ctx = $this->ctx('production', true);
        foreach (['ml.price.patch', 'ml.ads.update', 'ml.item.publish'] as $cap) {
            $this->assertFalse(
                $this->policy->allowsMlWrite($ctx, $cap),
                "production must deny {$cap} even with mlWriteAutomation=true"
            );
        }
    }

    public function testStagingWithMlWriteFalseIsBlocked(): void
    {
        $ctx = $this->ctx('staging', false);
        $this->assertFalse($this->policy->allowsMlWrite($ctx, 'ml.price.patch'));
    }

    public function testStagingWithMlWriteTrueAndAllowlistedCapabilityIsAllowed(): void
    {
        $ctx = $this->ctx('staging', true);
        $this->assertTrue($this->policy->allowsMlWrite($ctx, 'ml.item.publish'));
    }

    public function testLocalWithMlWriteFalseStillBlocked(): void
    {
        $ctx = $this->ctx('local', false);
        $this->assertFalse($this->policy->allowsMlWrite($ctx, 'ml.ads.update'));
    }

    public function testAllowsMlReadRequiresPositiveAccountIdAndNonEmptyCapability(): void
    {
        $ctx = $this->ctx('local', false);
        $this->assertTrue($this->policy->allowsMlRead($ctx, 'ml.items.search'));
        $this->assertFalse($this->policy->allowsMlRead($ctx, ''), 'empty capability must be denied');
    }

    public function testAllowsMlReadRejectsInvalidAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $bad = new AgentContext(0, 'local', 'corr-test', false);
        $this->policy->allowsMlRead($bad, 'ml.items.search');
    }

    public function testOpEmissionRequiresSuccess(): void
    {
        $ctx = $this->ctx('staging', true);
        $blocked = AgentResult::blocked('financeiro', 'no_op');
        $failed  = AgentResult::failed('financeiro', 'boom');
        $this->assertFalse($this->policy->allowsOpEmission($ctx, $blocked));
        $this->assertFalse($this->policy->allowsOpEmission($ctx, $failed));
    }

    public function testOpEmissionRequiresStateChanged(): void
    {
        $ctx = $this->ctx('staging', true);
        $r = AgentResult::success('financeiro', 'ok', [], false, ['ml.price.patch']);
        $this->assertFalse($this->policy->allowsOpEmission($ctx, $r));
    }

    public function testOpEmissionHonorsCapabilityAllowlist(): void
    {
        $ctx = $this->ctx('staging', true);
        $r = AgentResult::success('financeiro', 'ok', [], true, ['ml.account.wipe']);
        $this->assertFalse(
            $this->policy->allowsOpEmission($ctx, $r),
            'non-allowlisted op must be denied even when other gates pass'
        );
    }

    public function testOpEmissionSucceedsForValidScenario(): void
    {
        $ctx = $this->ctx('staging', true);
        $r = AgentResult::success('financeiro', 'price_updated', ['x' => 1], true, ['ml.price.patch']);
        $this->assertTrue($this->policy->allowsOpEmission($ctx, $r));
    }

    /**
     * Validacao de baixo nivel: AgentResult::success() rejeita emittedOps com
     * string vazia/whitespace. Isso elimina essa categoria antes de chegar no
     * AgentPolicy::allowsOpEmission(), entao o policy assume o contrato.
     */
    public function testAgentResultConstructorRejectsBlankEmittedOpStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AgentResult::success('financeiro', 'ok', [], true, ['   ']);
    }

    public function testAgentResultConstructorRejectsNonStringEmittedOp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AgentResult::success('financeiro', 'ok', [], true, ['ml.price.patch', 123]);
    }
}
