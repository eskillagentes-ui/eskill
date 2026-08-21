<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\BaseController;
use Tests\TestCase;

final class TestBaseControllerForActiveAccountId extends BaseController
{
    public function exposeGetActiveAccountId(): ?int
    {
        return $this->getActiveAccountId();
    }
}

final class BaseControllerActiveAccountIdTest extends TestCase
{
    private function makeController(): TestBaseControllerForActiveAccountId
    {
        return new TestBaseControllerForActiveAccountId();
    }

    public function testGetActiveAccountIdUsesSessionActiveMlAccountIdFirst(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        $_SESSION = ['active_ml_account_id' => 42];

        $controller = $this->makeController();

        $this->assertSame(42, $controller->exposeGetActiveAccountId());
    }

    public function testGetActiveAccountIdFallsBackToHeader(): void
    {
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        $_SERVER = ['HTTP_X_ML_ACCOUNT_ID' => '77'];

        $controller = $this->makeController();

        $this->assertSame(77, $controller->exposeGetActiveAccountId());
    }

    public function testGetActiveAccountIdFallsBackToQueryParam(): void
    {
        $_SERVER = [];
        $_POST = [];
        $_SESSION = [];
        $_GET = ['ml_account_id' => '99'];

        $controller = $this->makeController();

        $this->assertSame(99, $controller->exposeGetActiveAccountId());
    }

    public function testGetActiveAccountIdIgnoresLegacyCurrentAccountId(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
        $_SESSION = ['current_account_id' => 1336, 'account_id' => 1336];

        $controller = $this->makeController();

        $this->assertNull($controller->exposeGetActiveAccountId());
    }
}
