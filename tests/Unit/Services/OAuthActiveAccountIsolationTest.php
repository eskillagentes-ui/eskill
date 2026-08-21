<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MercadoLivreAuthService;
use PHPUnit\Framework\TestCase;

final class OAuthActiveAccountIsolationTest extends TestCase
{
    public function testNewSecondAccountDoesNotStealActiveSession(): void
    {
        $this->assertFalse(
            MercadoLivreAuthService::shouldSetActiveAccountAfterOAuth(1335, 1336, false),
            'OAuth of a brand-new second account must not change active_ml_account_id'
        );
    }

    public function testReconnectOfExistingAccountSetsItActive(): void
    {
        $this->assertTrue(
            MercadoLivreAuthService::shouldSetActiveAccountAfterOAuth(1335, 1335, true)
        );
        $this->assertTrue(
            MercadoLivreAuthService::shouldSetActiveAccountAfterOAuth(1335, 1336, true),
            'Reconnect of the same ml_user_id should set that account as active'
        );
    }

    public function testFirstAccountBecomesActive(): void
    {
        $this->assertTrue(
            MercadoLivreAuthService::shouldSetActiveAccountAfterOAuth(null, 1335, false)
        );
        $this->assertFalse(
            MercadoLivreAuthService::shouldSetActiveAccountAfterOAuth(1335, 0, false)
        );
    }

    public function testExchangeCodeForTokensNoLongerAlwaysSetsActiveAccount(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');
        $this->assertIsString($source);
        $start = strpos($source, 'public function exchangeCodeForTokens');
        $end = strpos($source, 'public static function shouldSetActiveAccountAfterOAuth', $start);
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $method = substr($source, $start, $end - $start);

        $this->assertStringContainsString('shouldSetActiveAccountAfterOAuth', $method);
        $this->assertStringContainsString('SessionHelper::setActiveAccountId($accountId)', $method);
        $this->assertStringNotContainsString(
            "        SessionHelper::setActiveAccountId(\$accountId);\n\n        return ['success' => true",
            $method,
            'callback path must not unconditionally setActiveAccountId'
        );
    }
}
