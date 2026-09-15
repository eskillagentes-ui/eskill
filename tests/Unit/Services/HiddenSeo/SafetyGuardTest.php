<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HiddenSeo;

use App\Exception\UnsafeOperationException;
use App\Services\HiddenSeo\ItemGoGrantLookup;
use App\Services\HiddenSeo\SafetyGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Services\HiddenSeo\SafetyGuard
 */
class SafetyGuardTest extends TestCase
{
    /** @var string|false */
    private $prevForbiddenGetenv;
    private ?string $prevForbiddenEnv = null;
    /** @var string|false */
    private $prevAutomationGetenv;
    private ?string $prevAutomationEnv = null;

    protected function setUp(): void
    {
        $this->prevForbiddenGetenv = getenv('FORBIDDEN_ACCOUNTS');
        $this->prevForbiddenEnv = isset($_ENV['FORBIDDEN_ACCOUNTS']) ? (string) $_ENV['FORBIDDEN_ACCOUNTS'] : null;
        $this->prevAutomationGetenv = getenv('ML_WRITE_AUTOMATION');
        $this->prevAutomationEnv = isset($_ENV['ML_WRITE_AUTOMATION']) ? (string) $_ENV['ML_WRITE_AUTOMATION'] : null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('FORBIDDEN_ACCOUNTS', $this->prevForbiddenGetenv, $this->prevForbiddenEnv);
        $this->restoreEnv('ML_WRITE_AUTOMATION', $this->prevAutomationGetenv, $this->prevAutomationEnv);
    }

    public function testDryRunAlwaysSafe(): void
    {
        $g = new SafetyGuard(true, [1335], 500);
        $g->assertCanApply(1335, true, false);
        $this->assertTrue(true);
    }

    public function testForbiddenBlocksApply(): void
    {
        $g = new SafetyGuard(true, [1335], 500);
        $this->expectException(UnsafeOperationException::class);
        $g->assertCanApply(1335, false, true);
    }

    public function testAllowedPassesWithAllowApply(): void
    {
        $g = new SafetyGuard(true, [1335], 500);
        $g->assertCanApply(1336, false, true);
        $this->assertFalse($g->isForbidden(1336));
        $this->assertTrue($g->isForbidden(1335));
    }

    public function testSafeModeOffAllowsWithoutFlagWhenNotForbidden(): void
    {
        $g = new SafetyGuard(false, [1335], 500);
        $g->assertCanApply(1336, false, false);
        $this->assertTrue(true);
    }

    public function testClampLimitRespectsMax(): void
    {
        $g = new SafetyGuard(true, [1335], 100);
        $this->assertSame(100, $g->clampLimit(999));
        $this->assertSame(50, $g->clampLimit(50));
    }

    public function testFacilytyWithoutGrantBlockedEvenIfNotForbidden(): void
    {
        $g = new SafetyGuard(true, [], 500, $this->emptyGrants());
        $this->assertFalse($g->isForbidden(1335));
        $this->assertTrue($g->isFacilyty(1335));
        $this->expectException(UnsafeOperationException::class);
        $this->expectExceptionMessage('ItemGoGrant');
        $g->assertCanApply(1335, false, true, 'MLB1234567890');
    }

    public function testFacilytyWithActiveGrantAndAllowApplyIsAllowed(): void
    {
        $grants = $this->grantFor('MLB1234567890');
        $g = new SafetyGuard(true, [], 500, $grants);
        $g->assertCanApply(1335, false, true, 'MLB1234567890');
        $this->assertTrue($grants->hasActive(1335, 'MLB1234567890'));
        $g->consumeOnApply(1335, 'MLB1234567890');
        $this->assertFalse($grants->hasActive(1335, 'MLB1234567890'));
    }

    public function testForbiddenAccountsNoneParsesToEmptyList(): void
    {
        putenv('FORBIDDEN_ACCOUNTS=none');
        $_ENV['FORBIDDEN_ACCOUNTS'] = 'none';
        $g = new SafetyGuard();
        $this->assertSame([], $g->forbiddenAccounts());
        $this->assertFalse($g->isForbidden(1335));

        $this->assertSame([], SafetyGuard::parseForbiddenAccounts('-'));
        $this->assertSame([], SafetyGuard::parseForbiddenAccounts('empty'));
        $this->assertSame([], SafetyGuard::parseForbiddenAccounts(''));
        $this->assertSame([], SafetyGuard::parseForbiddenAccounts('0'));
        $this->assertSame([1336], SafetyGuard::parseForbiddenAccounts('0,1336'));
        $this->assertSame([], SafetyGuard::DEFAULT_FORBIDDEN);

        putenv('FORBIDDEN_ACCOUNTS');
        unset($_ENV['FORBIDDEN_ACCOUNTS']);
        $empty = new SafetyGuard();
        $this->assertSame([], $empty->forbiddenAccounts());
    }

    public function testMlWriteAutomationUntouched(): void
    {
        putenv('ML_WRITE_AUTOMATION=false');
        $_ENV['ML_WRITE_AUTOMATION'] = 'false';
        $beforeG = getenv('ML_WRITE_AUTOMATION');
        $beforeE = $_ENV['ML_WRITE_AUTOMATION'] ?? null;

        $g = new SafetyGuard(true, [], 500);
        $g->assertCanApply(1336, false, true);

        $this->assertSame($beforeG, getenv('ML_WRITE_AUTOMATION'));
        $this->assertSame($beforeE, $_ENV['ML_WRITE_AUTOMATION'] ?? null);
        $this->assertSame('false', (string) getenv('ML_WRITE_AUTOMATION'));
        $this->assertSame('false', (string) ($_ENV['ML_WRITE_AUTOMATION'] ?? ''));

        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/HiddenSeo/SafetyGuard.php');
        $this->assertDoesNotMatchRegularExpression(
            '/getenv\s*\(\s*[\'"]ML_WRITE_AUTOMATION[\'"]/',
            $src
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$_ENV\s*\[\s*[\'"]ML_WRITE_AUTOMATION[\'"]/',
            $src
        );
    }

    public function testFacilytyGrantDoesNotBypassSafeModeWithoutAllowApply(): void
    {
        $g = new SafetyGuard(true, [], 500, $this->grantFor('MLB1234567890'));
        $this->expectException(UnsafeOperationException::class);
        $this->expectExceptionMessage('SAFE_MODE');
        $g->assertCanApply(1335, false, false, 'MLB1234567890');
    }

    public function testOtherAccountStillBlockedByForbiddenList(): void
    {
        $g = new SafetyGuard(true, [1336], 500, $this->grantFor('MLB1234567890'));
        $this->expectException(UnsafeOperationException::class);
        $this->expectExceptionMessage('FORBIDDEN_ACCOUNTS');
        $g->assertCanApply(1336, false, true, 'MLB1234567890');
    }

    private function emptyGrants(): ItemGoGrantLookup
    {
        return new class implements ItemGoGrantLookup {
            public function hasActive(int $accountId, string $mlbId): bool
            {
                return false;
            }

            public function consume(int $accountId, string $mlbId): bool
            {
                return false;
            }
        };
    }

    private function grantFor(string $mlb): ItemGoGrantLookup
    {
        return new class($mlb) implements ItemGoGrantLookup {
            private string $mlb;
            private bool $alive = true;

            public function __construct(string $mlb)
            {
                $this->mlb = $mlb;
            }

            public function hasActive(int $accountId, string $mlbId): bool
            {
                return $accountId === 1335
                    && strtoupper($mlbId) === strtoupper($this->mlb)
                    && $this->alive;
            }

            public function consume(int $accountId, string $mlbId): bool
            {
                if (!$this->hasActive($accountId, $mlbId)) {
                    return false;
                }
                $this->alive = false;

                return true;
            }
        };
    }

    private function restoreEnv(string $key, string|false $getenvValue, ?string $envValue): void
    {
        if ($getenvValue === false) {
            putenv($key);
        } else {
            putenv($key . '=' . (string) $getenvValue);
        }
        if ($envValue === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $envValue;
        }
    }
}
