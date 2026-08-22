<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\MessageController;
use App\Core\Request;
use App\Helpers\SessionHelper;
use PHPUnit\Framework\TestCase;

final class MessageControllerScopedAccountProbe extends MessageController
{
    public function __construct()
    {
        $this->request = new Request();
    }

    public function exposeScopedAccountId(): ?int
    {
        return $this->scopedAccountId();
    }
}

/**
 * @covers \App\Controllers\MessageController
 */
final class MessageControllerIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    public function testRequestAccountId1336IsIgnoredWhenSessionIs1335(): void
    {
        $_SESSION = ['active_ml_account_id' => 1335];
        $_GET = ['account_id' => '1336'];
        $_POST = ['account_id' => '1336'];
        $_SERVER['HTTP_X_ML_ACCOUNT_ID'] = '1336';

        $probe = new MessageControllerScopedAccountProbe();
        $this->assertSame(1335, $probe->exposeScopedAccountId());
        $this->assertSame(1335, SessionHelper::getActiveAccountId());
    }

    public function testSourceDoesNotReadRequestAccountId(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Controllers/MessageController.php');
        $this->assertStringContainsString('SessionHelper::getActiveAccountId', $source);
        $this->assertStringNotContainsString("request->get('account_id')", $source);
        $this->assertStringNotContainsString("\$data['account_id']", $source);
        $this->assertStringContainsString('AND account_id = ?', $source);
    }
}
