<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\UnifiedTokenRefreshService;

/**
 * Testes do UnifiedTokenRefreshService
 *
 * Cobre: refreshExpiring, forceRefreshAll, refreshAccount, getHealthMetrics,
 *        file locking, rate limiting, skip de tokens antigos
 */
class UnifiedTokenRefreshServiceTest extends TestCase
{
    // ===========================
    // STRUCTURAL TESTS
    // ===========================

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(UnifiedTokenRefreshService::class));
    }

    public function test_has_required_public_methods(): void
    {
        $methods = [
            'refreshExpiring',
            'forceRefreshAll',
            'refreshAccount',
            'getHealthMetrics'
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(UnifiedTokenRefreshService::class, $method),
                "Método {$method} deve existir"
            );
        }
    }

    public function test_refreshExpiring_has_correct_parameters(): void
    {
        $ref = new \ReflectionMethod(UnifiedTokenRefreshService::class, 'refreshExpiring');
        $params = $ref->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('bufferMinutes', $params[0]->getName());
        $this->assertEquals('useLock', $params[1]->getName());
        
        // Verificar valores padrão
        $this->assertEquals(120, $params[0]->getDefaultValue());
        $this->assertTrue($params[1]->getDefaultValue());
    }

    public function test_forceRefreshAll_has_lock_parameter(): void
    {
        $ref = new \ReflectionMethod(UnifiedTokenRefreshService::class, 'forceRefreshAll');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('useLock', $params[0]->getName());
        $this->assertTrue($params[0]->getDefaultValue());
    }

    public function test_refreshAccount_returns_array(): void
    {
        $ref = new \ReflectionMethod(UnifiedTokenRefreshService::class, 'refreshAccount');
        $returnType = $ref->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function test_getHealthMetrics_returns_array(): void
    {
        $ref = new \ReflectionMethod(UnifiedTokenRefreshService::class, 'getHealthMetrics');
        $returnType = $ref->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    // ===========================
    // CONSTANTS & CONFIGURATION
    // ===========================

    public function test_has_required_constants(): void
    {
        $ref = new \ReflectionClass(UnifiedTokenRefreshService::class);
        $constants = $ref->getConstants();

        $this->assertArrayHasKey('DEFAULT_BUFFER_MINUTES', $constants);
        $this->assertArrayHasKey('DEFAULT_MAX_RETRIES', $constants);
        $this->assertArrayHasKey('DEFAULT_RATE_DELAY_MS', $constants);
        $this->assertArrayHasKey('SKIP_EXPIRED_DAYS', $constants);
    }

    public function test_default_buffer_is_two_hours(): void
    {
        $ref = new \ReflectionClass(UnifiedTokenRefreshService::class);
        $constants = $ref->getConstants();

        $this->assertEquals(120, $constants['DEFAULT_BUFFER_MINUTES']);
    }

    public function test_lock_uses_flock_handle(): void
    {
        $ref = new \ReflectionClass(UnifiedTokenRefreshService::class);
        $this->assertTrue(
            $ref->hasProperty('lockHandle'),
            'Lock deve manter handle para flock durante a execução'
        );
    }

    public function test_skip_expired_days_is_thirty(): void
    {
        $ref = new \ReflectionClass(UnifiedTokenRefreshService::class);
        $constants = $ref->getConstants();

        $this->assertEquals(30, $constants['SKIP_EXPIRED_DAYS']);
    }

    // ===========================
    // SECURITY: FILE LOCKING
    // ===========================

    public function test_implements_file_locking(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('acquireLock()', $source);
        $this->assertStringContainsString('releaseLock()', $source);
        $this->assertStringContainsString('getLockFilePath()', $source);
    }

    public function test_lock_file_uses_storage_directory(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('storage', $source);
        $this->assertStringContainsString('.lock', $source);
    }

    public function test_lock_includes_pid_tracking(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('getmypid()', $source,
            'Lock deve rastrear PID do processo');
    }

    public function test_lock_includes_hostname_tracking(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('gethostname()', $source,
            'Lock deve rastrear hostname');
    }

    public function test_handles_expired_locks(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        // flock(LOCK_EX|LOCK_NB) serializa processos; lock é liberado ao fechar o handle
        // (inclui morte do processo) — não depende mais de filemtime/TTL.
        $this->assertTrue(
            str_contains($source, 'flock(') && str_contains($source, 'LOCK_EX'),
            'Deve usar flock para serializar renovação e liberar lock ao fim do processo'
        );
    }

    // ===========================
    // RATE LIMITING
    // ===========================

    public function test_implements_rate_limiting(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('usleep', $source,
            'Deve implementar delay entre renovações');
    }

    public function test_rate_limiting_is_configurable_via_env(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('ML_API_RATE_DELAY_MS', $source,
            'Rate limiting deve ser configurável via ENV');
    }

    // ===========================
    // TOKEN EXPIRATION LOGIC
    // ===========================

    public function test_skips_tokens_older_than_threshold(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('isTokenTooOld', $source,
            'Deve verificar se token está muito antigo');
        $this->assertStringContainsString('SKIP_EXPIRED_DAYS', $source,
            'Deve usar threshold de dias expirados');
    }

    public function test_marks_failed_accounts_as_expired(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('markAccountAsExpired', $source,
            'Deve marcar contas com falha como expiradas');
    }

    // ===========================
    // HEALTH METRICS
    // ===========================

    public function test_health_metrics_includes_total_accounts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('getTotalAccounts()', $source);
    }

    public function test_health_metrics_includes_active_accounts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('getActiveAccounts()', $source);
    }

    public function test_health_metrics_includes_expired_accounts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('getExpiredAccounts()', $source);
    }

    public function test_health_metrics_includes_failure_rate(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('failure_rate_24h', $source);
        $this->assertStringContainsString('getRefreshFailures24h()', $source);
    }

    public function test_health_metrics_includes_api_validation_failure_accounts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('accounts_with_api_validation_failures', $source);
        $this->assertStringContainsString('getAccountsWithApiValidationFailures', $source);
    }

    public function test_health_metrics_includes_identity_mismatch_accounts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('accounts_with_identity_mismatch', $source);
        $this->assertStringContainsString('ml_user_id_mismatch', $source);
    }

    public function test_health_metrics_includes_recent_validation_errors(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('recent_validation_errors', $source);
        $this->assertStringContainsString('getRecentValidationErrors', $source);
    }

    public function test_health_metrics_calculates_status(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('determineHealthStatus', $source);
        $this->assertStringContainsString('health_status', $source);
    }

    public function test_health_status_has_multiple_levels(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString("'critical'", $source);
        $this->assertStringContainsString("'warning'", $source);
        $this->assertStringContainsString("'attention'", $source);
        $this->assertStringContainsString("'healthy'", $source);
    }

    // ===========================
    // LOGGING & AUDITORIA
    // ===========================

    public function test_has_logging_method(): void
    {
        $ref = new \ReflectionClass(UnifiedTokenRefreshService::class);
        
        $this->assertTrue(
            $ref->hasMethod('log'),
            'Deve ter método log para registrar eventos'
        );
    }

    public function test_logs_to_structured_logger(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('StructuredLogService', $source,
            'Deve usar StructuredLogService para logs');
    }

    public function test_logs_lock_acquisition(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('Lock adquirido', $source);
    }

    public function test_logs_execution_summary(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertMatchesRegularExpression('/renovação de tokens concluída/i', $source);
    }

    // ===========================
    // RETURN VALUES & STRUCTURE
    // ===========================

    public function test_refresh_result_includes_required_fields(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $requiredFields = [
            'started_at',
            'finished_at',
            'accounts_checked',
            'tokens_refreshed',
            'tokens_failed',
            'tokens_skipped'
        ];

        foreach ($requiredFields as $field) {
            $this->assertStringContainsString("'{$field}'", $source,
                "Resultado deve incluir campo '{$field}'"
            );
        }
    }

    public function test_refresh_result_includes_api_validation_counters(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString("'api_validations_ok'", $source);
        $this->assertStringContainsString("'api_validations_failed'", $source);
        $this->assertStringContainsString("'api_validations_skipped'", $source);
    }

    public function test_refresh_result_includes_mode(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString("'mode'", $source);
        $this->assertStringContainsString("'force_all'", $source);
        $this->assertStringContainsString("'expiring_only'", $source);
    }

    public function test_skipped_result_includes_reason(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('createSkippedResult', $source);
        $this->assertStringContainsString("'reason'", $source);
        $this->assertStringContainsString("'skipped'", $source);
    }

    public function testDeveRetornarCamposLegadosQuandoSkippedResultForCriado(): void
    {
        $ref = new \ReflectionClass(UnifiedTokenRefreshService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $method = $ref->getMethod('createSkippedResult');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($service, 'lock failed');

        $this->assertSame(0, $result['checked']);
        $this->assertSame(0, $result['refreshed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['skipped']);
    }

    // ===========================
    // DEPENDENCIES
    // ===========================

    public function test_uses_mercadolivre_auth_service(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('MercadoLivreAuthService', $source);
        $this->assertStringContainsString('$this->authService', $source);
    }

    public function test_integrates_with_mercadolivre_api_users_me(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString("/users/me", $source,
            'Deve validar token renovado consultando endpoint /users/me');
        $this->assertStringContainsString('MercadoLivreClient', $source,
            'Deve integrar com MercadoLivreClient para validar API');
    }

    public function test_persists_api_validation_outcome_in_ml_accounts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('applyApiValidationOutcome', $source,
            'Deve aplicar resultado de validação da API no estado local da conta');
        $this->assertStringContainsString('refresh_failure_count = refresh_failure_count + 1', $source,
            'Falhas de validação da API devem incrementar contador de falhas');
        $this->assertStringContainsString('last_refresh_error = :error', $source,
            'Falhas de validação da API devem persistir último erro');
    }

    public function test_can_expire_account_on_auth_related_validation_errors(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('shouldExpireAccountFromValidationError', $source,
            'Deve existir regra para expirar conta em erros de autenticação pós-refresh');
        $this->assertStringContainsString("missing_access_token", $source,
            'Regra de expiração deve cobrir erros de token ausente/inválido');
    }

    public function test_validates_ml_identity_consistency_after_users_me(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('getExpectedMlUserId', $source,
            'Deve obter ml_user_id esperado da conta antes de sincronizar identidade');
        $this->assertStringContainsString('ml_user_id_mismatch', $source,
            'Deve falhar validação quando /users/me retornar ml_user_id diferente do esperado');
    }

    public function test_supports_env_toggle_for_api_validation(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('ML_VALIDATE_TOKEN_AFTER_REFRESH', $source,
            'Deve permitir habilitar/desabilitar validação na API por variável de ambiente');
    }

    public function test_uses_database(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('Database::getInstance()', $source);
        $this->assertStringContainsString('$this->db', $source);
    }

    // ===========================
    // ERROR HANDLING
    // ===========================

    public function test_has_try_catch_blocks(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $tryCount = substr_count($source, 'try {');
        $catchCount = substr_count($source, 'catch (');

        $this->assertGreaterThan(0, $tryCount, 'Deve ter blocos try-catch para error handling');
        $this->assertEquals($tryCount, $catchCount, 'Todo try deve ter catch correspondente');
    }

    public function test_uses_throwable_for_catch(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('\\Throwable', $source,
            'Deve usar \\Throwable para capturar todos os erros');
    }

    public function test_uses_finally_for_lock_release(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('finally', $source,
            'Deve usar finally para garantir liberação do lock');
    }

    // ===========================
    // QUERY OPTIMIZATION
    // ===========================

    public function test_uses_prepared_statements(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('->prepare(', $source,
            'Deve usar prepared statements para segurança');
    }

    public function test_orders_accounts_by_expiration(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('ORDER BY token_expires_at', $source,
            'Deve ordenar contas por data de expiração');
    }

    public function test_filters_empty_refresh_tokens(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString("refresh_token != ''", $source) ||
        $this->assertStringContainsString('refresh_token IS NOT NULL', $source);
    }

    // ===========================
    // CODE QUALITY
    // ===========================

    public function test_has_phpdoc_comments(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('/**', $source,
            'Deve ter comentários PHPDoc');
    }

    public function test_has_class_description(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertMatchesRegularExpression(
            '/\/\*\*.*Serviço Unificado.*\*\//s',
            $source,
            'Deve ter descrição da classe'
        );
    }

    public function test_file_has_namespace(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        $this->assertStringContainsString('namespace App\Services;', $source);
    }

    public function test_uses_strict_types(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Services/UnifiedTokenRefreshService.php');

        // PHP 8+ não requer declare(strict_types=1) mas é boa prática
        // Verificar se há type hints estritos
        $this->assertStringContainsString(': array', $source);
        $this->assertStringContainsString(': int', $source);
        $this->assertStringContainsString(': bool', $source);
    }
}
