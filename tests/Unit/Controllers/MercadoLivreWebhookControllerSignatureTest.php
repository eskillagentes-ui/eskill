<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\MercadoLivreWebhookController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \App\Controllers\MercadoLivreWebhookController
 */
class MercadoLivreWebhookControllerSignatureTest extends TestCase
{
    private function controller(): MercadoLivreWebhookController
    {
        return new MercadoLivreWebhookController();
    }

    private function invoke(object $object, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    public function testGenerateEventHashPrefersStableNotificationId(): void
    {
        $controller = $this->controller();

        $base = [
            'topic' => 'orders_v2',
            'resource' => '/orders/1',
            'user_id' => 123,
            'application_id' => 999,
            '_id' => 'f9f08571-1f65-4c46-9e0a-c0f43faa1557e',
            'sent' => '2026-08-06T10:00:00.000Z',
        ];

        $hash1 = $this->invoke($controller, 'generateEventHash', [$base]);
        $hash2 = $this->invoke($controller, 'generateEventHash', [
            array_merge($base, [
                'sent' => '2026-08-06T10:05:00.000Z',
                'attempts' => 3,
            ]),
        ]);

        $this->assertSame($hash1, $hash2, 'Retries com o mesmo _id devem colidir no hash');
    }

    public function testGenerateEventHashDiffersForDifferentStableIds(): void
    {
        $controller = $this->controller();

        $a = $this->invoke($controller, 'generateEventHash', [[
            'topic' => 'items',
            'user_id' => 1,
            'application_id' => 2,
            '_id' => 'id-a',
        ]]);
        $b = $this->invoke($controller, 'generateEventHash', [[
            'topic' => 'items',
            'user_id' => 1,
            'application_id' => 2,
            '_id' => 'id-b',
        ]]);

        $this->assertNotSame($a, $b);
    }

    public function testBuildWebhookSignatureManifestMatchesOfficialTemplate(): void
    {
        $controller = $this->controller();
        $payload = json_encode([
            'resource' => '/collections/ABC123',
            'topic' => 'payments',
            'user_id' => 1,
        ], JSON_THROW_ON_ERROR);

        $manifest = $this->invoke($controller, 'buildWebhookSignatureManifest', [
            $payload,
            'req-123',
            1704908010,
        ]);

        $this->assertSame('id:abc123;request-id:req-123;ts:1704908010;', $manifest);
    }

    public function testManifestHmacValidatesAgainstOfficialAlgorithm(): void
    {
        $controller = $this->controller();
        $secret = 'test-webhook-secret-value';
        $ts = time();
        $requestId = '4ed4fa2b-0b31-42ec-a62f-ad793c486c59';
        $dataId = 'abc123';
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, $secret);

        $_SERVER['HTTP_X_SIGNATURE'] = "ts={$ts},v1={$v1}";
        $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
        $_GET['data.id'] = $dataId;

        try {
            $ok = $this->invoke($controller, 'validateWebhookSignature', [
                '{"resource":"/collections/ABC123","topic":"payments"}',
                $secret,
            ]);
            $this->assertTrue($ok);
        } finally {
            unset($_SERVER['HTTP_X_SIGNATURE'], $_SERVER['HTTP_X_REQUEST_ID'], $_GET['data.id']);
        }
    }

    public function testControllerSourceSkipsMissingSignatureByDefault(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/app/Controllers/MercadoLivreWebhookController.php'
        );

        $this->assertStringContainsString('ML_WEBHOOK_SIGNATURE_SKIPPED', $source);
        $this->assertStringContainsString('ML_WEBHOOK_REQUIRE_SIGNATURE', $source);
        $this->assertStringContainsString("\$payload['_id']", $source);
        $this->assertStringContainsString("status IN ('active', 'expired')", $source);
        $this->assertStringContainsString('receive_exception:', $source);
        $this->assertStringContainsString('trim($sigHeader)', $source);
    }

    public function testEmptySignatureHeaderIsNotTreatedAsPresent(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/app/Controllers/MercadoLivreWebhookController.php'
        );

        // Regressão: `!== null` aceitava X-Signature vazio e forçava validate→401.
        $this->assertStringNotContainsString(
            "getRequestHeader('X-Signature') !== null",
            $source
        );
        $this->assertStringContainsString('trim($sigHeader) !== \'\'', $source);
    }

    public function testIsWebhookSignatureTimestampFreshAcceptsMilliseconds(): void
    {
        $controller = $this->controller();
        $nowMs = (int)(microtime(true) * 1000);

        $ok = $this->invoke($controller, 'isWebhookSignatureTimestampFresh', [$nowMs]);
        $this->assertTrue($ok);
    }
}
