<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\SessionHelper;
use PHPUnit\Framework\TestCase;

final class SessionHelperActiveAccountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
    }

    public function testGetActiveAccountIdUsesOnlyActiveMlAccountIdSessionKey(): void
    {
        $_SESSION = [
            'current_account_id' => 1336,
            'account_id' => 1336,
        ];

        $this->assertNull(
            SessionHelper::getActiveAccountId(),
            'Getter must ignore legacy current_account_id / account_id session keys'
        );

        $_SESSION['active_ml_account_id'] = 1335;
        $this->assertSame(1335, SessionHelper::getActiveAccountId());
    }

    public function testGetterSourceDoesNotReadLegacySessionKeys(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Helpers/SessionHelper.php');
        $this->assertIsString($source);

        $start = strpos($source, 'public static function getActiveAccountId()');
        $end = strpos($source, 'public static function setActiveAccountId(', $start);
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $getter = substr($source, $start, $end - $start);

        $this->assertStringContainsString("\$_SESSION['active_ml_account_id']", $getter);
        $this->assertStringNotContainsString('current_account_id', $getter);
        $this->assertStringNotContainsString("\$_SESSION['account_id']", $getter);
    }
}
