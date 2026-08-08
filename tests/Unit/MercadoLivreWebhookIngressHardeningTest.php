<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MercadoLivreWebhookIngressHardeningTest extends TestCase
{
    public function testControllerAcknowledgesQueuedWebhookWithHttp200(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/MercadoLivreWebhookController.php');
        $this->assertIsString($source);
        // Doc ML: notificações exigem HTTP 200 (não 202) em ≤500ms.
        $this->assertStringNotContainsString('http_response_code(202)', $source);
        $this->assertStringContainsString('ML exige HTTP 200', $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'http_response_code(200)'));
    }

    public function testControllerUsesMarkQueuedForAsyncWebhookDispatch(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/MercadoLivreWebhookController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("markQueued('mercadolivre', \$eventHash, \$jobId", $source);
    }

    public function testControllerAllowsWebhookSecretOverrideViaGetenv(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/MercadoLivreWebhookController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("getenv('ML_WEBHOOK_SECRET')", $source);
    }

    public function testWebhookRoutesDoNotExposeDashboardAlias(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Routes/webhooks.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString("\$router->post('dashboard'", $source);
    }
}
