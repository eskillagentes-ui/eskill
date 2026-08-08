<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\MercadoLivreAuthService;

/**
 * Testes do MercadoLivreAuthService
 *
 * Cobre: getAuthUrl, exchangeCodeForTokens (validação de state),
 *        refreshToken, ensureValidToken, segurança (PKCE, prepared statements)
 */
class MercadoLivreAuthServiceTest extends TestCase
{
    // ===========================
    // STRUCTURAL TESTS
    // ===========================

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(MercadoLivreAuthService::class));
    }

    public function test_has_required_public_methods(): void
    {
        $methods = ['getAuthUrl', 'exchangeCodeForTokens', 'refreshToken', 'ensureValidToken'];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(MercadoLivreAuthService::class, $method),
                "Método {$method} deve existir"
            );
        }
    }

    public function test_getAuthUrl_requires_int_userId(): void
    {
        $ref = new \ReflectionMethod(MercadoLivreAuthService::class, 'getAuthUrl');
        $params = $ref->getParameters();

        $this->assertEquals('userId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());
    }

    public function test_refreshToken_has_retry_parameter(): void
    {
        $ref = new \ReflectionMethod(MercadoLivreAuthService::class, 'refreshToken');
        $params = $ref->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('accountId', $params[0]->getName());
        $this->assertEquals('maxRetries', $params[1]->getName());
        $this->assertEquals(3, $params[1]->getDefaultValue());
    }

    public function test_ensureValidToken_has_buffer_parameter(): void
    {
        $ref = new \ReflectionMethod(MercadoLivreAuthService::class, 'ensureValidToken');
        $params = $ref->getParameters();

        $this->assertEquals('bufferMinutes', $params[1]->getName());
        $this->assertEquals(60, $params[1]->getDefaultValue());
    }

    // ===========================
    // SECURITY: OAUTH/PKCE
    // ===========================

    public function test_uses_pkce_code_challenge(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        $this->assertStringContainsString('code_challenge', $source);
        $this->assertStringContainsString('code_verifier', $source);
        $this->assertStringContainsString('S256', $source);
    }

    public function test_validates_oauth_state(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        // Deve validar state recebido contra o legado em sessão e contra o mapa contextual.
        $this->assertStringContainsString('ml_oauth_state', $source);
        $this->assertStringContainsString('ml_oauth_states', $source);
        $this->assertTrue(
            str_contains($source, 'hash_equals($stored, $state)')
            || str_contains($source, 'hash_equals($state, $stored)')
            || str_contains($source, '!is_array($stateContext) && !$isLegacyMatch'),
            'Deve validar state OAuth contra sessão'
        );
    }

    public function test_generates_secure_random_state(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        $this->assertStringContainsString(
            'random_bytes(',
            $source,
            'Deve usar random_bytes para gerar state seguro'
        );
    }

    public function test_cleans_session_after_exchange(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        // Deve limpar session após troca de código
        $this->assertStringContainsString("unset(\$_SESSION['ml_oauth_state'])", $source);
        $this->assertStringContainsString("unset(\$_SESSION['ml_oauth_pkce']", $source);
    }

    // ===========================
    // SECURITY: TOKEN STORAGE
    // ===========================

    public function test_encrypts_tokens_before_storage(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        // Deve usar EncryptionService para proteger tokens no DB
        $this->assertStringContainsString('EncryptionService', $source);
        $this->assertTrue(
            str_contains($source, '->encrypt(') && str_contains($source, '->decrypt('),
            'Deve criptografar e descriptografar tokens'
        );
    }

    public function test_uses_prepared_statements(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        $prepareCount = substr_count($source, '->prepare(');
        $executeCount = substr_count($source, '->execute(');

        $this->assertGreaterThan(3, $prepareCount, 'Deve ter múltiplos prepared statements');
        $this->assertGreaterThan(3, $executeCount, 'Deve ter múltiplas execuções');
    }

    // ===========================
    // SECURITY: API CALLS
    // ===========================

    public function test_uses_timeout_on_api_calls(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        $this->assertStringContainsString(
            'CURLOPT_TIMEOUT',
            $source,
            'Deve ter timeout nas chamadas HTTP'
        );
    }

    public function test_uses_user_agent(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        $this->assertStringContainsString('CURLOPT_USERAGENT', $source);
    }

    public function test_validates_http_response_codes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        $this->assertStringContainsString('CURLINFO_HTTP_CODE', $source);
        $this->assertStringContainsString('$httpCode', $source);
    }

    // ===========================
    // RETRY / RESILIENCE
    // ===========================

    public function test_refreshToken_implements_exponential_backoff(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        // Deve ter backoff exponencial
        $this->assertTrue(
            str_contains($source, 'pow(2,') || str_contains($source, 'pow(2, '),
            'Deve implementar backoff exponencial'
        );
    }

    public function test_refreshToken_success_does_not_depend_on_rowCount(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        // Após persistir tokens, deve retornar true sem (bool)$upd->rowCount()
        // (falso negativo no MySQL/PDO após consumir refresh_token de uso único).
        $this->assertStringContainsString('Não depender de rowCount()', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/return \(bool\)\$upd->rowCount\(\);/',
            $source
        );
    }

    public function test_refreshToken_handles_invalid_grant(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        $this->assertStringContainsString(
            'invalid_grant',
            $source,
            'Deve tratar invalid_grant como falha irrecuperável'
        );
    }

    // ===========================
    // INTEGRATION TESTS (skip sem DB)
    // ===========================

    public function test_instantiation(): void
    {
        try {
            $service = new MercadoLivreAuthService();
            $this->assertInstanceOf(MercadoLivreAuthService::class, $service);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'SQLSTATE') || str_contains($e->getMessage(), 'Connection')) {
                $this->markTestSkipped('DB não disponível');
            } else {
                throw $e;
            }
        }
    }

    public function test_getAuthUrl_generates_valid_url(): void
    {
        try {
            $service = new MercadoLivreAuthService();
        } catch (\Exception $e) {
            $this->markTestSkipped('DB não disponível');
            return;
        }

        try {
            $url = $service->getAuthUrl(1);

            $this->assertStringContainsString('http', $url);
            $this->assertStringContainsString('response_type=code', $url);
            $this->assertStringContainsString('state=', $url);
            $this->assertStringContainsString('code_challenge=', $url);
            $this->assertStringContainsString('code_challenge_method=S256', $url);
        } catch (\Exception $e) {
            // sessão pode falhar em contexto PHPUnit
            if (str_contains($e->getMessage(), 'session') || str_contains($e->getMessage(), 'headers')) {
                $this->markTestSkipped('Session não disponível em contexto PHPUnit');
            } else {
                throw $e;
            }
        }
    }

    public function test_exchangeCodeForTokens_rejects_invalid_state(): void
    {
        try {
            $service = new MercadoLivreAuthService();
        } catch (\Exception $e) {
            $this->markTestSkipped('DB não disponível');
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Estado OAuth inválido');
        $service->exchangeCodeForTokens('fake-code', 'invalid-state');
    }

    public function test_refreshToken_returns_false_for_nonexistent_account(): void
    {
        try {
            $service = new MercadoLivreAuthService();
        } catch (\Exception $e) {
            $this->markTestSkipped('DB não disponível');
            return;
        }

        try {
            $result = $service->refreshToken(999999);
            $this->assertFalse($result);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'Table')) {
                $this->markTestSkipped('Tabela ml_accounts não existe');
            } else {
                throw $e;
            }
        }
    }

    public function test_ensureValidToken_returns_false_for_nonexistent_account(): void
    {
        try {
            $service = new MercadoLivreAuthService();
        } catch (\Exception $e) {
            $this->markTestSkipped('DB não disponível');
            return;
        }

        try {
            $result = $service->ensureValidToken(999999);
            $this->assertFalse($result);
        } catch (\Exception $e) {
            if (
                str_contains($e->getMessage(), "doesn't exist")
                || str_contains($e->getMessage(), 'Table')
                || str_contains($e->getMessage(), 'unavailable')
                || str_contains($e->getMessage(), 'connection')
            ) {
                $this->markTestSkipped('DB indisponível: ' . $e->getMessage());
            } else {
                throw $e;
            }
        }
    }

    // ===========================
    // PROXY SUPPORT
    // ===========================

    public function test_has_proxy_support(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');

        $this->assertStringContainsString('ML_PROXY_ENABLED', $source);
        $this->assertStringContainsString('CURLOPT_PROXY', $source);
    }
}
