<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\AccountAccessDeniedException;
use App\Security\AuthenticatedActor;
use App\Security\AuthorizedAccountContext;
use App\Security\DefaultAccountAccessPolicy;
use App\Security\SecurityAuditLogger;
use App\Services\AuditLogService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Security\DefaultAccountAccessPolicy
 */
final class DefaultAccountAccessPolicyTest extends TestCase
{
    /**
     * @param array<string,mixed>|false   $accountRow  Linha retornada pelo mock PDO.
     * @param list<array<string,mixed>>   $decisions   Referência para decisões capturadas.
     * @return array{pdo: PDO, stmt: PDOStatement, auditService: AuditLogService, policy: DefaultAccountAccessPolicy, decisions: list<array<string,mixed>>}
     */
    private function makePolicy(array|false $accountRow = false, array &$decisions = []): array
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($accountRow);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $auditService = $this->createMock(AuditLogService::class);
        $auditService->method('log')
            ->willReturnCallback(
                function (string $action, ?int $userId, ?int $accountId, array $data) use (&$decisions): void {
                    $decisions[] = ['action' => $action, 'data' => $data];
                }
            );

        $logger = new SecurityAuditLogger($auditService);
        $policy = new DefaultAccountAccessPolicy($pdo, $logger);

        return compact('pdo', 'stmt', 'auditService', 'logger', 'policy', 'decisions');
    }

    private function makeAccount(array $overrides = []): array
    {
        return array_merge([
            'id'         => 10,
            'user_id'    => 42,
            'site_id'    => 'MLB',
            'status'     => 'active',
            'nickname'   => 'AWA_MOTOS',
            'ml_user_id' => 'ML123',
        ], $overrides);
    }

    private function makeActor(int $userId = 42): AuthenticatedActor
    {
        return AuthenticatedActor::fromHumanSession($userId, ['items:read']);
    }

    // Teste 13 — usuário A acessa conta A → permitido
    public function testUserAAccessesAccountA_Allowed(): void
    {
        $decisions = [];
        ['stmt' => $stmt, 'policy' => $policy] =
            $this->makePolicy($this->makeAccount(['id' => 10, 'user_id' => 42]), $decisions);

        $stmt->method('fetch')->willReturn($this->makeAccount(['id' => 10, 'user_id' => 42]));

        $ctx = $policy->authorize($this->makeActor(42), 10, 'items:read');

        $this->assertInstanceOf(AuthorizedAccountContext::class, $ctx);
        $this->assertSame(10, $ctx->getAccountId());
        $this->assertSame(42, $ctx->getOwnerUserId());

        // Verifica que foi auditado como allow
        $this->assertCount(1, $decisions);
        $this->assertStringContainsString('allow', $decisions[0]['action']);
    }

    // Teste 14 — usuário A tenta conta B → negado
    public function testUserATriesAccountB_Denied(): void
    {
        $decisions = [];
        // Conta B não é encontrada para user 42 (query filtra user_id simultaneamente)
        ['policy' => $policy] = $this->makePolicy(false, $decisions);

        $this->expectException(AccountAccessDeniedException::class);
        $policy->authorize($this->makeActor(42), 99, 'items:read');

        $this->assertCount(1, $decisions);
        $this->assertStringContainsString('deny', $decisions[0]['action']);
    }

    // Teste 15 — conta inexistente → negado com mensagem genérica
    public function testNonexistentAccount_DeniedWithGenericMessage(): void
    {
        ['policy' => $policy] = $this->makePolicy(false);

        try {
            $policy->authorize($this->makeActor(42), 9999, 'items:read');
            $this->fail('Deve lançar AccountAccessDeniedException');
        } catch (AccountAccessDeniedException $e) {
            $this->assertSame('Acesso não autorizado.', $e->getMessage());
            $this->assertStringNotContainsString('9999', $e->getMessage());
            $this->assertStringNotContainsString('42', $e->getMessage());
        }
    }

    // Teste 16 — conta inativa → negado
    public function testInactiveAccount_Denied(): void
    {
        $decisions = [];
        ['policy' => $policy] = $this->makePolicy(
            $this->makeAccount(['status' => 'inactive']),
            $decisions
        );

        $this->expectException(AccountAccessDeniedException::class);
        $policy->authorize($this->makeActor(42), 10, 'items:read');
    }

    // Teste 17 — capability não autorizada → negado
    public function testUnauthorizedCapability_Denied(): void
    {
        $decisions = [];
        ['policy' => $policy] = $this->makePolicy($this->makeAccount(), $decisions);

        $this->expectException(AccountAccessDeniedException::class);
        $policy->authorize($this->makeActor(42), 10, 'admin:delete_everything');
    }

    // Teste 18 — organizationId divergente → negado (defesa em profundidade)
    public function testDivergentOrganizationId_Denied(): void
    {
        $decisions = [];
        // Retorna conta com user_id 99, mas actor tem org 42
        ['policy' => $policy] = $this->makePolicy(
            $this->makeAccount(['user_id' => 99]),
            $decisions
        );

        $this->expectException(AccountAccessDeniedException::class);
        $policy->authorize($this->makeActor(42), 10, 'items:read');
    }

    // Teste 19 — decisão allow gera auditoria
    public function testAllowDecisionGeneratesAudit(): void
    {
        $decisions = [];
        ['policy' => $policy] = $this->makePolicy($this->makeAccount(), $decisions);

        $policy->authorize($this->makeActor(42), 10, 'items:read');

        $this->assertCount(1, $decisions);
        $this->assertStringContainsString('allow', $decisions[0]['action']);
        $this->assertSame('allow', $decisions[0]['data']['decision']);
    }

    // Teste 20 — decisão deny gera auditoria
    public function testDenyDecisionGeneratesAudit(): void
    {
        $decisions = [];
        ['policy' => $policy] = $this->makePolicy(false, $decisions);

        try {
            $policy->authorize($this->makeActor(42), 100, 'items:read');
        } catch (AccountAccessDeniedException) {
            // esperado
        }

        $this->assertCount(1, $decisions);
        $this->assertStringContainsString('deny', $decisions[0]['action']);
        $this->assertSame('deny', $decisions[0]['data']['decision']);
    }

    // Teste 21 — nenhum segredo aparece na auditoria
    public function testNoSecretAppearsInAuditData(): void
    {
        $decisions = [];
        ['policy' => $policy] = $this->makePolicy($this->makeAccount(), $decisions);

        $policy->authorize($this->makeActor(42), 10, 'items:read');

        $serialized = json_encode($decisions);
        $this->assertStringNotContainsString('access_token', $serialized);
        $this->assertStringNotContainsString('refresh_token', $serialized);
        $this->assertStringNotContainsString('client_secret', $serialized);
    }

    // Teste 22 — query valida accountId E owner_user_id simultaneamente
    public function testQueryValidatesAccountIdAndUserIdSimultaneously(): void
    {
        $capturedSql = '';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql, $stmt) {
                $capturedSql = $sql;
                return $stmt;
            });

        $auditService = $this->createMock(AuditLogService::class);
        $logger = new SecurityAuditLogger($auditService);
        $policy = new DefaultAccountAccessPolicy($pdo, $logger);

        try {
            $policy->authorize($this->makeActor(42), 10, 'items:read');
        } catch (AccountAccessDeniedException) {
            // esperado
        }

        $this->assertStringContainsString(':id', $capturedSql);
        $this->assertStringContainsString(':userId', $capturedSql);
        // Garante que a query NUNCA filtra só por id — user_id DEVE estar no WHERE
        $this->assertMatchesRegularExpression('/WHERE\s.+\buser_id\b/si', $capturedSql);
    }
}
