<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\AccountAccessDeniedException;
use App\Security\AccountAccessPolicy;
use App\Security\AccountContextResolver;
use App\Security\AuthenticatedActor;
use App\Security\AuthorizedAccountContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Security\AccountContextResolver
 */
final class AccountContextResolverTest extends TestCase
{
    private function makeActor(int $userId = 10): AuthenticatedActor
    {
        return AuthenticatedActor::fromHumanSession($userId, ['items:read']);
    }

    private function makeContext(): AuthorizedAccountContext
    {
        return AuthorizedAccountContext::create(
            accountId:      5,
            ownerUserId:    10,
            organizationId: 10,
            marketplace:    'mercadolivre',
            siteId:         'MLB',
            status:         'active',
            capabilities:   ['items:read']
        );
    }

    // Teste 23 — resolve contexto autorizado
    public function testResolvesAuthorizedContext(): void
    {
        $ctx = $this->makeContext();

        $policy = $this->createMock(AccountAccessPolicy::class);
        $policy->expects($this->once())
               ->method('authorize')
               ->willReturn($ctx);

        $resolver = new AccountContextResolver($policy);
        $result   = $resolver->resolve($this->makeActor(), 5, 'items:read');

        $this->assertSame($ctx, $result);
    }

    // Teste 24 — propaga negação com segurança
    public function testPropagesDenialSafely(): void
    {
        $policy = $this->createMock(AccountAccessPolicy::class);
        $policy->method('authorize')
               ->willThrowException(AccountAccessDeniedException::withAuditCode('TEST_DENY'));

        $resolver = new AccountContextResolver($policy);

        $this->expectException(AccountAccessDeniedException::class);
        $resolver->resolve($this->makeActor(), 5, 'items:read');
    }

    // Teste 25 — requer accountId explícito (positivo)
    public function testRequiresExplicitPositiveAccountId(): void
    {
        $policy   = $this->createMock(AccountAccessPolicy::class);
        $resolver = new AccountContextResolver($policy);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolve($this->makeActor(), 0, 'items:read');
    }

    // Teste 26 — requer capability não-vazia
    public function testRequiresCapability(): void
    {
        $policy   = $this->createMock(AccountAccessPolicy::class);
        $resolver = new AccountContextResolver($policy);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolve($this->makeActor(), 5, '');
    }

    // Teste 27 — não acessa superglobais de entrada no código real (fora de comentários)
    public function testDoesNotReadGlobalInputsInCode(): void
    {
        $ref = new \ReflectionClass(AccountContextResolver::class);
        $src = file_get_contents($ref->getFileName());

        // Verificar ausência de acesso real às superglobais (sintaxe de acesso: $_XXX[)
        // Comentários doc podem mencionar esses nomes, mas nunca devem acessá-los
        $this->assertStringNotContainsString('$_GET[', $src);
        $this->assertStringNotContainsString('$_POST[', $src);
        $this->assertStringNotContainsString('$_SESSION[', $src);
        $this->assertStringNotContainsString('$_COOKIE[', $src);
        $this->assertStringNotContainsString('$_SERVER[\'HTTP_', $src);
        $this->assertStringNotContainsString('getallheaders()', $src);
    }
}

/**
 * @covers \App\Security\AccountAccessDeniedException
 */
final class AccountAccessDeniedExceptionTest extends TestCase
{
    // Teste 28 — não revela existência da conta
    public function testDoesNotRevealAccountExistence(): void
    {
        $e = AccountAccessDeniedException::withAuditCode('ACCOUNT_NOT_FOUND_OR_NOT_OWNED');

        $this->assertSame('Acesso não autorizado.', $e->getMessage());
        $this->assertStringNotContainsString('not found', strtolower($e->getMessage()));
        $this->assertStringNotContainsString('exists', strtolower($e->getMessage()));
    }

    // Teste 29 — não revela proprietário
    public function testDoesNotRevealOwner(): void
    {
        $e = AccountAccessDeniedException::withAuditCode('ORGANIZATION_MISMATCH');

        $msg = $e->getMessage();
        $this->assertStringNotContainsString('owner', strtolower($msg));
        $this->assertStringNotContainsString('user_id', strtolower($msg));
        $this->assertStringNotContainsString('organizat', strtolower($msg));
    }

    // Teste 30 — não contém token ou credencial
    public function testDoesNotContainTokenOrCredential(): void
    {
        $e = AccountAccessDeniedException::withAuditCode('ACCESS_DENIED');

        $msg = strtolower($e->getMessage());
        $this->assertStringNotContainsString('access_token', $msg);
        $this->assertStringNotContainsString('refresh_token', $msg);
        $this->assertStringNotContainsString('secret', $msg);
        $this->assertStringNotContainsString('password', $msg);
    }

    // Teste 31 — código interno não é exposto externamente
    public function testInternalCodeNotExposedExternally(): void
    {
        $internalCode = 'ACCOUNT_NOT_FOUND_OR_NOT_OWNED';
        $e = AccountAccessDeniedException::withAuditCode($internalCode);

        // getMessage() é a mensagem pública — não deve conter o código interno
        $this->assertStringNotContainsString($internalCode, $e->getMessage());

        // getPublicMessage() deve ser genérico
        $this->assertSame('Acesso não autorizado.', $e->getPublicMessage());

        // getAuditCode() devolve o código interno (uso interno/auditoria)
        $this->assertSame($internalCode, $e->getAuditCode());
    }
}
