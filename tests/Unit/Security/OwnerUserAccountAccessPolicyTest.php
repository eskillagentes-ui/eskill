<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\AccountAccessException;
use App\Security\AccountContextResolver;
use App\Security\OwnerUserAccountAccessPolicy;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * SEC-001 — 10 cenários obrigatórios (ownership via owner_user_id / ADR-001).
 *
 * @covers \App\Security\OwnerUserAccountAccessPolicy
 * @covers \App\Security\AccountContextResolver
 */
final class OwnerUserAccountAccessPolicyTest extends TestCase
{
    /**
     * @param array<string, mixed>|false $row
     */
    private function policyWithRow(array|false $row): OwnerUserAccountAccessPolicy
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($row);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return new OwnerUserAccountAccessPolicy($pdo);
    }

    public function testUserAAccessesOwnAccountAAllowed(): void
    {
        $policy = $this->policyWithRow([
            'id' => 10,
            'user_id' => 1,
            'ml_user_id' => '123',
            'nickname' => 'loja_a',
            'status' => 'active',
        ]);

        $ctx = $policy->authorize(1, 10, 'read');
        $this->assertSame(10, $ctx->accountId());
        $this->assertSame(1, $ctx->ownerUserId());
        $this->assertSame(1, $ctx->organizationId());
        $this->assertSame(1, $ctx->actorUserId());
    }

    public function testUserAAccessesAccountBDenied(): void
    {
        $policy = $this->policyWithRow([
            'id' => 20,
            'user_id' => 2,
            'ml_user_id' => '999',
            'nickname' => 'loja_b',
            'status' => 'active',
        ]);

        $this->expectException(AccountAccessException::class);
        try {
            $policy->authorize(1, 20, 'read');
        } catch (AccountAccessException $e) {
            $this->assertSame(403, $e->httpStatus());
            $this->assertSame('account_access_denied', $e->errorCode());
            throw $e;
        }
    }

    public function testApiTokenActorAAccessesAccountBDenied(): void
    {
        $_SERVER['API_USER_ID'] = 1;
        $_SESSION = [];
        $_GET = ['account_id' => '20'];
        $_POST = [];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 20,
            'user_id' => 2,
            'ml_user_id' => '999',
            'nickname' => 'loja_b',
            'status' => 'active',
        ]);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $resolver = new AccountContextResolver(new OwnerUserAccountAccessPolicy($pdo), $pdo);

        $this->expectException(AccountAccessException::class);
        try {
            $resolver->authorizeForCurrentActor('read', 20);
        } finally {
            unset($_SERVER['API_USER_ID']);
            $_GET = [];
        }
    }

    public function testActorWithoutOrganizationEquivalentDeniedWhenActorInvalid(): void
    {
        $policy = $this->policyWithRow([
            'id' => 10,
            'user_id' => 1,
            'status' => 'active',
            'ml_user_id' => '1',
            'nickname' => 'x',
        ]);

        $this->expectException(AccountAccessException::class);
        $policy->authorize(0, 10, 'read');
    }

    public function testWorkerWithoutExplicitAccountIdFails(): void
    {
        $resolver = new AccountContextResolver($this->policyWithRow(false));
        $this->expectException(AccountAccessException::class);
        $resolver->authorizeForWorker(1, 0, 'sync');
    }

    public function testNonexistentAccountReturnsGeneric404(): void
    {
        $policy = $this->policyWithRow(false);
        try {
            $policy->authorize(1, 99999, 'read');
            $this->fail('Expected exception');
        } catch (AccountAccessException $e) {
            $this->assertSame(404, $e->httpStatus());
            $this->assertSame('account_not_found', $e->errorCode());
            $this->assertStringNotContainsString('99999', $e->getMessage());
        }
    }

    public function testInactiveAccountDenied(): void
    {
        $policy = $this->policyWithRow([
            'id' => 10,
            'user_id' => 1,
            'ml_user_id' => '1',
            'nickname' => 'x',
            'status' => 'disconnected',
        ]);

        $this->expectException(AccountAccessException::class);
        try {
            $policy->authorize(1, 10, 'read');
        } catch (AccountAccessException $e) {
            $this->assertSame('account_inactive', $e->errorCode());
            throw $e;
        }
    }

    public function testAccountSwitchWritesAudit(): void
    {
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn([
            'id' => 10,
            'user_id' => 1,
            'ml_user_id' => '1',
            'nickname' => 'loja',
            'status' => 'active',
        ]);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->expects($this->once())->method('execute')->with($this->callback(
            static function (array $params): bool {
                return ($params['action'] ?? '') === 'account_switch'
                    && (int) ($params['ml_account_id'] ?? 0) === 10
                    && (int) ($params['user_id'] ?? 0) === 1;
            }
        ))->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static function (string $sql) use ($selectStmt, $insertStmt): PDOStatement {
                if (stripos($sql, 'INSERT INTO audit_logs') !== false) {
                    return $insertStmt;
                }
                return $selectStmt;
            }
        );

        $_SESSION = ['active_ml_account_id' => 5];
        $resolver = new AccountContextResolver(new OwnerUserAccountAccessPolicy($pdo), $pdo);
        $ctx = $resolver->switchActiveAccount(1, 10);

        $this->assertSame(10, $ctx->accountId());
        $this->assertSame(10, (int) $_SESSION['active_ml_account_id']);
    }

    public function testHeaderGetPostDoNotBypassPolicy(): void
    {
        $_SESSION = ['user_id' => 1];
        $_SERVER['HTTP_X_ML_ACCOUNT_ID'] = '20';
        $_GET = ['account_id' => '20'];
        $_POST = ['ml_account_id' => '20'];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 20,
            'user_id' => 2,
            'ml_user_id' => '2',
            'nickname' => 'other',
            'status' => 'active',
        ]);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $resolver = new AccountContextResolver(new OwnerUserAccountAccessPolicy($pdo), $pdo);

        $this->expectException(AccountAccessException::class);
        try {
            $resolver->authorizeForCurrentActor('read');
        } finally {
            unset($_SERVER['HTTP_X_ML_ACCOUNT_ID']);
            $_GET = [];
            $_POST = [];
            $_SESSION = [];
        }
    }

    public function testDenialLogsNeverContainTokens(): void
    {
        $policy = $this->policyWithRow([
            'id' => 20,
            'user_id' => 2,
            'ml_user_id' => '2',
            'nickname' => 'other',
            'status' => 'active',
            'access_token' => 'SECRET_TOKEN_SHOULD_NOT_LEAK',
            'refresh_token' => 'SECRET_REFRESH',
        ]);

        try {
            $policy->authorize(1, 20, 'read');
            $this->fail('Expected denial');
        } catch (AccountAccessException $e) {
            $this->assertStringNotContainsString('SECRET_TOKEN', $e->getMessage());
            $this->assertStringNotContainsString('SECRET_REFRESH', $e->getMessage());
            $this->assertStringNotContainsString('access_token', $e->getMessage());
        }
    }
}
