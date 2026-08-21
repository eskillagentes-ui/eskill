<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Tests\TestCase;

/**
 * Testes de segurança dos Middlewares
 *
 * Verifica: CSRF, Auth, RateLimit, ApiAuth, Security Headers
 */
class SecurityMiddlewaresTest extends TestCase
{
    // ===========================
    // CSRF MIDDLEWARE
    // ===========================

    public function test_csrf_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Middleware\CsrfMiddleware::class));
    }

    public function test_oauth_callback_is_csrf_exempt_in_bootstrap(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
        $this->assertStringContainsString('$path === \'/auth/callback\'', $source);
        $this->assertStringContainsString('$isOAuthCallback', $source);
        $this->assertStringContainsString('$path === \'/auth/login\'', $source);
        $this->assertStringContainsString("isset(\$_GET['code'])", $source);
        $this->assertStringContainsString("isset(\$_POST['code'])", $source);
        $this->assertStringContainsString("isset(\$_GET['error'])", $source);
        $this->assertStringContainsString("isset(\$_POST['error'])", $source);
        $this->assertStringNotContainsString('strpos($path, \'/auth/\') === 0', $source);
        $this->assertStringNotContainsString('$isAuthRoute || $isWebhookRoute', $source);
    }

    public function test_csrf_validates_post_methods(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/CsrfMiddleware.php'
        );

        $this->assertStringContainsString('POST', $source);
        $this->assertStringContainsString('_token', $source);
    }

    // ===========================
    // AUTH MIDDLEWARE
    // ===========================

    public function test_auth_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Middleware\AuthMiddleware::class));
    }

    public function test_auth_middleware_has_handle_and_check(): void
    {
        $this->assertTrue(method_exists(\App\Middleware\AuthMiddleware::class, 'handle'));
        $this->assertTrue(method_exists(\App\Middleware\AuthMiddleware::class, 'check'));
    }

    public function test_auth_middleware_handles_ajax_requests(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/AuthMiddleware.php'
        );

        $this->assertStringContainsString('xmlhttprequest', strtolower($source));
        $this->assertStringContainsString('401', $source);
    }

    // ===========================
    // API AUTH MIDDLEWARE
    // ===========================

    public function test_api_auth_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Middleware\ApiAuthMiddleware::class));
    }

    public function test_api_auth_no_test_bypass(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/ApiAuthMiddleware.php'
        );

        $this->assertStringNotContainsString(
            'test-token',
            $source,
            'API Auth Middleware NÃO deve conter test-token bypass'
        );

        $this->assertStringNotContainsString(
            'E2E_TEST_MODE',
            $source,
            'API Auth Middleware NÃO deve conter E2E_TEST_MODE bypass'
        );
    }

    public function test_api_auth_validates_bearer_token(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/ApiAuthMiddleware.php'
        );

        $this->assertStringContainsString('Bearer', $source);
        $this->assertStringContainsString('Authorization', $source);
    }

    public function test_api_auth_checks_scopes(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/ApiAuthMiddleware.php'
        );

        $this->assertStringContainsString('scope', strtolower($source));
    }

    // ===========================
    // SECURITY MIDDLEWARE
    // ===========================

    public function test_security_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Middleware\SecurityMiddleware::class));
    }

    public function test_security_middleware_detects_sql_injection(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/SecurityMiddleware.php'
        );

        $this->assertMatchesRegularExpression(
            '/sql.?injection|UNION|SELECT|DROP/i',
            $source,
            'Deve detectar padrões de SQL injection'
        );
    }

    public function test_security_middleware_detects_xss(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/SecurityMiddleware.php'
        );

        $this->assertMatchesRegularExpression(
            '/xss|<script|javascript:/i',
            $source,
            'Deve detectar padrões de XSS'
        );
    }

    public function test_security_middleware_supports_env_whitelist(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/SecurityMiddleware.php'
        );

        $this->assertStringContainsString(
            'AUTH_IP_WHITELIST',
            $source,
            'SecurityMiddleware deve respeitar AUTH_IP_WHITELIST do ambiente'
        );
    }

    public function test_security_middleware_supports_legacy_and_auth_block_tables(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/SecurityMiddleware.php'
        );

        $this->assertStringContainsString('blocked_ips', $source);
        $this->assertStringContainsString('auth_blocked_ips', $source);
    }

    public function test_manage_ips_script_handles_both_block_tables(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/bin/manage-ips.php'
        );

        $this->assertStringContainsString('blocked_ips', $source);
        $this->assertStringContainsString('auth_blocked_ips', $source);
    }

    // ===========================
    // SECURITY HEADERS MIDDLEWARE
    // ===========================

    public function test_security_headers_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Middleware\SecurityHeadersMiddleware::class));
    }

    // ===========================
    // RATE LIMIT MIDDLEWARE
    // ===========================

    public function test_rate_limit_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Middleware\RateLimitMiddleware::class));
    }

    public function test_rate_limit_constructor_accepts_params(): void
    {
        $reflection = new \ReflectionClass(\App\Middleware\RateLimitMiddleware::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'Deve ter construtor');
        $this->assertGreaterThanOrEqual(1, $constructor->getNumberOfParameters());
    }

    // ===========================
    // INDEX.PHP SECURITY CONFIG
    // ===========================

    public function test_index_has_session_cookie_secure(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/public/index.php'
        );

        // Deve ter cookie_secure habilitado (não comentado)
        $this->assertMatchesRegularExpression(
            '/^\s*ini_set\s*\(\s*[\'"]session\.cookie_secure[\'"]/m',
            $source,
            'session.cookie_secure deve estar habilitado (não comentado)'
        );
    }

    public function test_index_has_csp_enabled(): void
    {
        // CSP é definido pelo SecurityMiddleware.php e SecurityHeadersMiddleware.php
        // que são invocados pelo index.php via (new SecurityMiddleware())->setSecurityHeaders()
        $middlewareSource = file_get_contents(
            dirname(__DIR__, 3) . '/app/Middleware/SecurityMiddleware.php'
        );

        // CSP deve estar ativo no middleware (não comentado)
        $this->assertMatchesRegularExpression(
            '/^\s*header\s*\(\s*[\'"]Content-Security-Policy/m',
            $middlewareSource,
            'Content-Security-Policy deve estar habilitado no SecurityMiddleware'
        );

        // Verificar que index.php instancia o SecurityMiddleware
        $indexSource = file_get_contents(
            dirname(__DIR__, 3) . '/public/index.php'
        );
        $this->assertMatchesRegularExpression(
            '/SecurityMiddleware/m',
            $indexSource,
            'index.php deve usar SecurityMiddleware'
        );
    }

    public function test_index_csrf_covers_auth_routes(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/public/index.php'
        );

        // Não deve excluir rotas de auth do CSRF
        $this->assertStringNotContainsString(
            '$isAuthRoute || $isWebhookRoute',
            $source,
            'Auth routes NÃO devem ser excluídas do CSRF'
        );
    }

    public function test_index_uses_dashboard_burst_for_session_authenticated_apis(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/public/index.php'
        );

        $this->assertStringContainsString(
            "\$hasSessionAuth = !empty(\$_SESSION['account_id']) || !empty(\$_SESSION['user_id']);",
            $source,
            'index.php deve detectar autenticação por sessão antes do rate limit'
        );

        $this->assertMatchesRegularExpression(
            '/\$isDashboardApi\s*=\s*\$hasSessionAuth\s*\|\|/m',
            $source,
            'APIs autenticadas por sessão devem receber o burst limit do dashboard'
        );
    }
}
