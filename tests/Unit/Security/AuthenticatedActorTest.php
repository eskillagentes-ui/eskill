<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\AuthenticatedActor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Security\AuthenticatedActor
 */
final class AuthenticatedActorTest extends TestCase
{
    // Teste 1
    public function testCreatesValidHumanActor(): void
    {
        $actor = AuthenticatedActor::fromHumanSession(42, ['items:read']);

        $this->assertSame(42, $actor->getUserId());
        $this->assertSame('human', $actor->getActorType());
        $this->assertTrue($actor->isHuman());
        $this->assertFalse($actor->isService());
        $this->assertNull($actor->getApiTokenId());
        $this->assertContains('items:read', $actor->getScopes());
    }

    // Teste 2
    public function testCreatesValidApiActor(): void
    {
        $actor = AuthenticatedActor::fromApiToken(99, 7, ['orders:read']);

        $this->assertSame(99, $actor->getUserId());
        $this->assertSame('api', $actor->getActorType());
        $this->assertSame(7, $actor->getApiTokenId());
        $this->assertFalse($actor->isHuman());
        $this->assertFalse($actor->isService());
        $this->assertContains('orders:read', $actor->getScopes());
    }

    // Teste 3
    public function testCreatesValidServiceActor(): void
    {
        $actor = AuthenticatedActor::fromService(1, ['catalog:read']);

        $this->assertSame(1, $actor->getUserId());
        $this->assertSame('service', $actor->getActorType());
        $this->assertTrue($actor->isService());
        $this->assertFalse($actor->isHuman());
        $this->assertNull($actor->getApiTokenId());
    }

    // Teste 4
    public function testRejectsInvalidUserIdZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AuthenticatedActor::fromHumanSession(0);
    }

    public function testRejectsNegativeUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AuthenticatedActor::fromHumanSession(-5);
    }

    // Teste 5 — sem credenciais reais (access_token, refresh_token, client_secret, password)
    public function testActorDoesNotContainCredentials(): void
    {
        $actor = AuthenticatedActor::fromHumanSession(10);
        $ref   = new \ReflectionClass($actor);

        // Nomes proibidos de propriedade — credenciais reais
        $forbidden = ['accesstoken', 'refreshtoken', 'clientsecret', 'password', 'accesskey'];

        foreach ($ref->getProperties() as $prop) {
            $name = strtolower(str_replace('_', '', $prop->getName()));
            $this->assertNotContains(
                $name,
                $forbidden,
                "Propriedade com nome de credencial encontrada: {$prop->getName()}"
            );
        }

        // O ator também não deve armazenar strings de token nos valores
        $serialized = serialize($actor);
        $this->assertStringNotContainsString('access_token', $serialized);
        $this->assertStringNotContainsString('refresh_token', $serialized);
        $this->assertStringNotContainsString('client_secret', $serialized);
    }

    // Teste 6 — ADR-001: organizationId == owner_user_id
    public function testOrganizationIdEqualsOwnerUserId(): void
    {
        $userId = 55;
        $humanActor   = AuthenticatedActor::fromHumanSession($userId);
        $apiActor     = AuthenticatedActor::fromApiToken($userId, 3);
        $serviceActor = AuthenticatedActor::fromService($userId);

        $this->assertSame($userId, $humanActor->getOrganizationId());
        $this->assertSame($userId, $apiActor->getOrganizationId());
        $this->assertSame($userId, $serviceActor->getOrganizationId());
    }
}
