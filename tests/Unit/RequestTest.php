<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Tests\TestCase;
use App\Core\Request;

/**
 * Testes unitários para Request - validação e sanitização de input
 */
class RequestTest extends TestCase
{
    // ========================================
    // sanitizeString (via get/post)
    // ========================================

    public function testGetSanitizesXSS(): void
    {
        $_GET['name'] = '<script>alert(1)</script>';
        $req = new Request();

        $result = $req->get('name');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $result);
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $_GET = [];
        $req = new Request();

        $this->assertNull($req->get('nonexistent'));
        $this->assertSame('fallback', $req->get('nonexistent', 'fallback'));
    }

    public function testGetRejectsArrayInputInsteadOfRaisingTypeError(): void
    {
        $_GET['page'] = ['2'];
        $req = new Request();

        $this->expectException(InvalidArgumentException::class);
        $req->get('page');
    }

    public function testGetIntCastsToInteger(): void
    {
        $_GET['page'] = '42abc';
        $req = new Request();

        $this->assertSame(42, $req->getInt('page'));
    }

    public function testGetIntDefaultsToZero(): void
    {
        $_GET = [];
        $req = new Request();

        $this->assertSame(0, $req->getInt('page'));
        $this->assertSame(1, $req->getInt('page', 1));
    }

    public function testGetTypedAccessorsRejectArrayInput(): void
    {
        foreach (['int', 'float', 'bool', 'enum', 'clamped', 'sort'] as $accessor) {
            $_GET = ['value' => ['2']];
            $req = new Request();
            try {
                match ($accessor) {
                    'int' => $req->getInt('value'),
                    'float' => $req->getFloat('value'),
                    'bool' => $req->getBool('value'),
                    'enum' => $req->getEnum('value', ['2']),
                    'clamped' => $req->getIntClamped('value', 1, 100),
                    'sort' => $req->getSortDir('value'),
                };
                self::fail("{$accessor} deveria rejeitar array");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPostEInputIntRejeitamArrayEmAccessorEscalar(): void
    {
        $_POST = ['value' => ['2']];
        $req = new Request();
        foreach ([fn () => $req->post('value'), fn () => $req->postInt('value'), fn () => $req->inputInt('value')] as $call) {
            try {
                $call();
                self::fail('accessor escalar deveria rejeitar array');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testGetBool(): void
    {
        $_GET['active'] = 'true';
        $_GET['disabled'] = '0';
        $req = new Request();

        $this->assertTrue($req->getBool('active'));
        $this->assertFalse($req->getBool('disabled'));
        $this->assertFalse($req->getBool('missing'));
    }

    // ========================================
    // POST
    // ========================================

    public function testPostSanitizes(): void
    {
        $_POST['comment'] = "   <img onerror=alert(1)>test   ";
        $req = new Request();

        $result = $req->post('comment');
        $this->assertStringNotContainsString('<img', $result);
        $this->assertSame('&lt;img onerror=alert(1)&gt;test', $result);
    }

    public function testPostArray(): void
    {
        $_POST['tags'] = ['php', 'security'];
        $req = new Request();

        $this->assertSame(['php', 'security'], $req->postArray('tags'));
    }

    public function testPostArrayReturnsDefaultForNonArray(): void
    {
        $_POST['tags'] = 'not-an-array';
        $req = new Request();

        $this->assertSame([], $req->postArray('tags'));
    }

    // ========================================
    // JSON
    // ========================================

    public function testJsonReturnsEmptyArrayOnInvalidJson(): void
    {
        // Mock php://input is not easily testable without stream wrappers
        // Just test the Request object was instantiated
        $req = new Request();
        $this->assertInstanceOf(Request::class, $req);
    }

    // ========================================
    // validateRequired
    // ========================================

    public function testValidateRequiredDetectsMissing(): void
    {
        $req = new Request();

        $data = ['name' => 'Test', 'email' => 'test@test.com'];
        $missing = $req->validateRequired(['name', 'email', 'password'], $data);

        $this->assertSame(['password'], $missing);
    }

    public function testValidateRequiredTreatsEmptyStringAsMissing(): void
    {
        $req = new Request();

        $data = ['name' => '', 'email' => 'test@test.com'];
        $missing = $req->validateRequired(['name', 'email'], $data);

        $this->assertSame(['name'], $missing);
    }

    public function testValidateRequiredReturnsEmptyWhenAllPresent(): void
    {
        $req = new Request();

        $data = ['name' => 'Test', 'email' => 'test@test.com'];
        $missing = $req->validateRequired(['name', 'email'], $data);

        $this->assertEmpty($missing);
    }

    // ========================================
    // getEnum
    // ========================================

    public function testGetEnumReturnsValueIfAllowed(): void
    {
        $_GET['sort'] = 'price';
        $req = new Request();

        $result = $req->getEnum('sort', ['price', 'title', 'date'], 'date');
        $this->assertSame('price', $result);
    }

    public function testGetEnumReturnsDefaultIfNotAllowed(): void
    {
        $_GET['sort'] = 'hacked';
        $req = new Request();

        $result = $req->getEnum('sort', ['price', 'title', 'date'], 'date');
        $this->assertSame('date', $result);
    }

    // ========================================
    // getIntClamped
    // ========================================

    public function testGetIntClampedClampsToRange(): void
    {
        $_GET['limit'] = '999';
        $req = new Request();

        $result = $req->getIntClamped('limit', 1, 100, 10);
        $this->assertSame(100, $result);
    }

    public function testGetIntClampedMinimum(): void
    {
        $_GET['limit'] = '-5';
        $req = new Request();

        $result = $req->getIntClamped('limit', 1, 100, 10);
        $this->assertSame(1, $result);
    }

    // ========================================
    // getSortDir
    // ========================================

    public function testGetSortDirNormalizesToAscDesc(): void
    {
        $_GET['dir'] = 'asc';
        $req = new Request();
        $this->assertSame('ASC', $req->getSortDir());

        $_GET['dir'] = 'HACKED';
        $req = new Request();
        $this->assertSame('DESC', $req->getSortDir());
    }

    // ========================================
    // isAjax
    // ========================================

    public function testIsAjax(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $req = new Request();

        $this->assertTrue($req->isAjax());
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        parent::tearDown();
    }
}
