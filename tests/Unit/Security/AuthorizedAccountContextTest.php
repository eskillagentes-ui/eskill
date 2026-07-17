<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\AuthorizedAccountContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Security\AuthorizedAccountContext
 */
final class AuthorizedAccountContextTest extends TestCase
{
    private function makeContext(array $overrides = []): AuthorizedAccountContext
    {
        return AuthorizedAccountContext::create(
            accountId:      $overrides['accountId']      ?? 10,
            ownerUserId:    $overrides['ownerUserId']    ?? 42,
            organizationId: $overrides['organizationId'] ?? 42,
            marketplace:    $overrides['marketplace']    ?? 'mercadolivre',
            siteId:         $overrides['siteId']         ?? 'MLB',
            status:         $overrides['status']         ?? 'active',
            capabilities:   $overrides['capabilities']   ?? ['items:read', 'orders:read'],
        );
    }

    // Teste 7
    public function testCreatesValidContext(): void
    {
        $ctx = $this->makeContext();

        $this->assertSame(10, $ctx->getAccountId());
        $this->assertSame(42, $ctx->getOwnerUserId());
        $this->assertSame(42, $ctx->getOrganizationId());
        $this->assertSame('mercadolivre', $ctx->getMarketplace());
        $this->assertSame('MLB', $ctx->getSiteId());
        $this->assertSame('active', $ctx->getStatus());
    }

    // Teste 8 — imutabilidade: sem setters
    public function testContextIsImmutable(): void
    {
        $ctx = $this->makeContext();
        $ref = new \ReflectionClass($ctx);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            $this->assertFalse(
                str_starts_with($name, 'set'),
                "Setter encontrado: {$name} — AuthorizedAccountContext deve ser imutável."
            );
        }
    }

    // Teste 9 — apenas metadados autorizados
    public function testContainsOnlyAuthorizedMetadata(): void
    {
        $ctx = $this->makeContext();
        $ref = new \ReflectionClass($ctx);

        $forbidden = ['accesstoken', 'refreshtoken', 'clientsecret', 'password'];

        foreach ($ref->getProperties() as $prop) {
            $name = strtolower(str_replace('_', '', $prop->getName()));
            $this->assertNotContains(
                $name,
                $forbidden,
                "Propriedade com nome de credencial encontrada: {$prop->getName()}"
            );
        }
    }

    // Teste 10 — sem access_token
    public function testNoAccessTokenProperty(): void
    {
        $ctx = $this->makeContext();
        $ref = new \ReflectionClass($ctx);

        $propNames = array_map(fn($p) => strtolower(str_replace('_', '', $p->getName())), $ref->getProperties());
        $this->assertNotContains('accesstoken', $propNames);
    }

    // Teste 11 — sem refresh_token
    public function testNoRefreshTokenProperty(): void
    {
        $ctx = $this->makeContext();
        $ref = new \ReflectionClass($ctx);

        $propNames = array_map(fn($p) => strtolower(str_replace('_', '', $p->getName())), $ref->getProperties());
        $this->assertNotContains('refreshtoken', $propNames);
    }

    // Teste 12 — hasCapability funciona corretamente
    public function testCapabilitiesVerifiedCorrectly(): void
    {
        $ctx = $this->makeContext(['capabilities' => ['items:read', 'catalog:read']]);

        $this->assertTrue($ctx->hasCapability('items:read'));
        $this->assertTrue($ctx->hasCapability('catalog:read'));
        $this->assertFalse($ctx->hasCapability('items:write'));
        $this->assertFalse($ctx->hasCapability('orders:read'));
    }
}
