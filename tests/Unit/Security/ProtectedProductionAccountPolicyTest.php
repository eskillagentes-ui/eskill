<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Exception\UnsafeOperationException;
use App\Security\ProtectedProductionAccountPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Security\ProtectedProductionAccountPolicy
 */
final class ProtectedProductionAccountPolicyTest extends TestCase
{
    public function testStagingCannotLinkFacilytyIdentity(): void
    {
        $policy = new ProtectedProductionAccountPolicy();
        $this->assertFalse(
            $policy->canLinkIdentity('3058804121', 'FACILYTY', null, true)
        );
        $this->assertFalse(
            $policy->canLinkIdentity('1', 'other', 1335, true)
        );
        $this->expectException(UnsafeOperationException::class);
        $policy->assertCanLinkIdentity('3058804121', 'FACILYTY', null, true);
    }

    public function testProductionCanLinkFacilytyIdentity(): void
    {
        $policy = new ProtectedProductionAccountPolicy();
        $this->assertTrue(
            $policy->canLinkIdentity('3058804121', 'FACILYTY', 1335, false)
        );
        $policy->assertCanLinkIdentity('3058804121', 'FACILYTY', 1335, false);
        $this->assertTrue(
            $policy->canLinkIdentity('999', 'TEST_SELLER', 1336, true)
        );
    }

    public function testIsStagingDetectsEnvAndPath(): void
    {
        $this->assertTrue(ProtectedProductionAccountPolicy::isStaging('staging', '/tmp', ''));
        $this->assertTrue(ProtectedProductionAccountPolicy::isStaging('production', '/home/eskill/htdocs/staging.eskill.com.br', ''));
        $this->assertTrue(ProtectedProductionAccountPolicy::isStaging('production', '/tmp', 'https://staging.eskill.com.br'));
        $this->assertFalse(ProtectedProductionAccountPolicy::isStaging('production', '/home/eskill/htdocs/eskill.com.br', 'https://eskill.com.br'));
    }

    public function testAuthCallbackWiresPolicy(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');
        $this->assertStringContainsString('ProtectedProductionAccountPolicy', $src);
        $this->assertStringContainsString('assertCanLinkIdentity', $src);
    }
}
