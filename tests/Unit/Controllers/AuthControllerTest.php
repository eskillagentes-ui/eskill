<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Controllers\AuthController;

/**
 * Testes estruturais do AuthController
 *
 * Validam estrutura, métodos, dependências, segurança e padrões
 * do controller de autenticação sem precisar de banco de dados.
 */
class AuthControllerTest extends TestCase
{
    private static string $sourceCode = '';
    private static \ReflectionClass $reflection;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$reflection = new \ReflectionClass(AuthController::class);
        self::$sourceCode = (string) file_get_contents((string) self::$reflection->getFileName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    // =============================
    // STRICT TYPES
    // =============================

    public function testHasStrictTypesDeclaration(): void
    {
        $this->assertMatchesRegularExpression(
            '/declare\s*\(\s*strict_types\s*=\s*1\s*\)/',
            self::$sourceCode,
            'AuthController deve ter declare(strict_types=1)'
        );
    }

    // =============================
    // INSTANCIAÇÃO
    // =============================

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(AuthController::class));
    }

    public function testExtendsBaseController(): void
    {
        $this->assertTrue(self::$reflection->isSubclassOf('App\Controllers\BaseController'));
    }

    // =============================
    // CORE AUTH METHODS
    // =============================

    /**
     * @dataProvider coreMethodsProvider
     */
    public function testHasCoreMethod(string $method): void
    {
        $this->assertTrue(
            method_exists(AuthController::class, $method),
            "AuthController deve ter método {$method}()"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function coreMethodsProvider(): array
    {
        return [
            'login' => ['login'],
            'doLogin' => ['doLogin'],
            'register' => ['register'],
            'doRegister' => ['doRegister'],
            'logout' => ['logout'],
            'forgotPassword' => ['forgotPassword'],
            'doForgotPassword' => ['doForgotPassword'],
            'resetPassword' => ['resetPassword'],
            'doResetPassword' => ['doResetPassword'],
        ];
    }

    // =============================
    // 2FA METHODS
    // =============================

    /**
     * @dataProvider twoFactorMethodsProvider
     */
    public function testHas2FAMethod(string $method): void
    {
        $this->assertTrue(
            method_exists(AuthController::class, $method),
            "AuthController deve ter método 2FA: {$method}()"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function twoFactorMethodsProvider(): array
    {
        return [
            'verifyTwoFactor' => ['verifyTwoFactor'],
            'doVerifyTwoFactor' => ['doVerifyTwoFactor'],
            'setupTwoFactor' => ['setupTwoFactor'],
            'doSetupTwoFactor' => ['doSetupTwoFactor'],
        ];
    }

    // =============================
    // OAUTH / ML METHODS
    // =============================

    /**
     * @dataProvider oauthMethodsProvider
     */
    public function testHasOAuthMethod(string $method): void
    {
        $this->assertTrue(
            method_exists(AuthController::class, $method),
            "AuthController deve ter método OAuth: {$method}()"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function oauthMethodsProvider(): array
    {
        return [
            'authorize' => ['authorize'],
            'callback' => ['callback'],
            'accounts' => ['accounts'],
            'oauthConfigStatus' => ['oauthConfigStatus'],
            'status' => ['status'],
        ];
    }

    // =============================
    // ACCOUNT MANAGEMENT METHODS
    // =============================

    /**
     * @dataProvider accountMethodsProvider
     */
    public function testHasAccountMethod(string $method): void
    {
        $this->assertTrue(
            method_exists(AuthController::class, $method),
            "AuthController deve ter método de gestão de conta: {$method}()"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accountMethodsProvider(): array
    {
        return [
            'disconnect' => ['disconnect'],
            'syncAccount' => ['syncAccount'],
            'getSyncStatus' => ['getSyncStatus'],
            'syncAllAccounts' => ['syncAllAccounts'],
            'deleteAccount' => ['deleteAccount'],
        ];
    }

    // =============================
    // MOBILE AUTH
    // =============================

    public function testHasMobileLoginMethod(): void
    {
        $this->assertTrue(
            method_exists(AuthController::class, 'mobileLogin'),
            'AuthController deve ter método mobileLogin()'
        );
    }

    // =============================
    // EMAIL VERIFICATION
    // =============================

    public function testHasVerifyEmailMethod(): void
    {
        $this->assertTrue(
            method_exists(AuthController::class, 'verifyEmail'),
            'AuthController deve ter método verifyEmail()'
        );
    }

    public function testHasOAuthConfigStatusMethod(): void
    {
        $this->assertTrue(
            method_exists(AuthController::class, 'oauthConfigStatus'),
            'AuthController deve ter método oauthConfigStatus()'
        );
    }

    // =============================
    // METHOD SIGNATURES
    // =============================

    /**
     * @dataProvider intParameterMethodsProvider
     */
    public function testMethodAcceptsIntParameter(string $method, string $param): void
    {
        $ref = new \ReflectionMethod(AuthController::class, $method);
        $params = $ref->getParameters();

        $this->assertNotEmpty($params, "{$method}() deve ter parâmetros");
        $found = false;
        foreach ($params as $p) {
            if ($p->getName() === $param) {
                $type = $p->getType();
                $this->assertNotNull($type, "{$method}(\${$param}) deve ter type hint");
                $this->assertEquals('int', $type->getName(), "{$method}(\${$param}) deve ser int");
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "{$method}() deve ter parâmetro \${$param}");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function intParameterMethodsProvider(): array
    {
        return [
            'disconnect(accountId)' => ['disconnect', 'accountId'],
            'syncAccount(accountId)' => ['syncAccount', 'accountId'],
            'getSyncStatus(accountId)' => ['getSyncStatus', 'accountId'],
            'deleteAccount(accountId)' => ['deleteAccount', 'accountId'],
        ];
    }

    /**
     * @dataProvider voidReturnMethodsProvider
     */
    public function testMethodReturnsVoid(string $method): void
    {
        $ref = new \ReflectionMethod(AuthController::class, $method);
        $returnType = $ref->getReturnType();

        $this->assertNotNull($returnType, "{$method}() deve ter return type");
        $this->assertEquals('void', $returnType->getName(), "{$method}() deve retornar void");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function voidReturnMethodsProvider(): array
    {
        return [
            'login' => ['login'],
            'doLogin' => ['doLogin'],
            'logout' => ['logout'],
            'register' => ['register'],
            'doRegister' => ['doRegister'],
        ];
    }

    public function testOAuthConfigStatusUsesAuthServiceDiagnostics(): void
    {
        $this->assertStringContainsString("public function oauthConfigStatus(): void", self::$sourceCode);
        $this->assertStringContainsString("\$diagnostics = \$this->authService->getOAuthConfigDiagnostics();", self::$sourceCode);
        $this->assertStringContainsString("'data' => \$diagnostics", self::$sourceCode);
    }

    public function testAuthorizeHandlesConnectionFailuresGracefully(): void
    {
        $this->assertStringContainsString("\$errorType = \$this->classifyOAuthErrorType(\$e->getMessage());", self::$sourceCode);
        $this->assertStringContainsString("\$_SESSION['error'] = \$userMessage;", self::$sourceCode);
        $this->assertStringContainsString("header('Location: /dashboard/accounts');", self::$sourceCode);
    }

    public function testCallbackFailuresRedirectBackToAccountsWorkspace(): void
    {
        $this->assertStringContainsString('Erro na autorização:', self::$sourceCode);
        $this->assertStringContainsString('Código de autorização não recebido', self::$sourceCode);
        $this->assertStringContainsString("header('Location: /dashboard/accounts');", self::$sourceCode);
    }

    // =============================
    // DEPENDENCIES / PROPERTIES
    // =============================

    /**
     * @dataProvider dependenciesProvider
     */
    public function testHasDependency(string $property): void
    {
        $properties = self::$reflection->getProperties(\ReflectionProperty::IS_PRIVATE);
        $names = array_map(fn(\ReflectionProperty $p): string => $p->getName(), $properties);
        $this->assertContains($property, $names, "AuthController deve ter propriedade {$property}");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dependenciesProvider(): array
    {
        return [
            'authService' => ['authService'],
            'userService' => ['userService'],
            'security' => ['security'],
            'auditLog' => ['auditLog'],
            'twoFactorService' => ['twoFactorService'],
        ];
    }

    // =============================
    // IMPORTS / USE STATEMENTS
    // =============================

    /**
     * @dataProvider importsProvider
     */
    public function testUsesImport(string $import, string $context): void
    {
        $this->assertStringContainsString(
            $import,
            self::$sourceCode,
            "AuthController deve usar {$context}"
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function importsProvider(): array
    {
        return [
            'Log' => ['use App\Helpers\Log', 'App\Helpers\Log'],
            'SecurityService' => ['SecurityService', 'SecurityService'],
            'MercadoLivreAuthService' => ['MercadoLivreAuthService', 'MercadoLivreAuthService'],
            'UserService' => ['UserService', 'UserService'],
            'AuditLogService' => ['AuditLogService', 'AuditLogService'],
            'TwoFactorService' => ['TwoFactorService', 'TwoFactorService'],
            'Database' => ['Database', 'Database'],
        ];
    }

    // =============================
    // SECURITY PATTERNS
    // =============================

    public function testSessionStartInConstructor(): void
    {
        $this->assertStringContainsString(
            'session_start()',
            self::$sourceCode,
            'AuthController deve iniciar sessão no construtor'
        );
    }

    public function testUsesSessionRegeneration(): void
    {
        $this->assertStringContainsString(
            'session_regenerate_id',
            self::$sourceCode,
            'AuthController deve usar session_regenerate_id para segurança'
        );
    }

    public function testLogsSecurityEvents(): void
    {
        $this->assertStringContainsString(
            'Log::security',
            self::$sourceCode,
            'AuthController deve logar eventos de segurança'
        );
    }

    public function testUsesCSRFValidation(): void
    {
        $this->assertStringContainsString(
            'csrf',
            strtolower(self::$sourceCode),
            'AuthController deve implementar proteção CSRF'
        );
    }

    public function testUsesAuditLogging(): void
    {
        $this->assertStringContainsString(
            'auditLog->log',
            self::$sourceCode,
            'AuthController deve registrar ações no audit log'
        );
    }

    public function testUsesLogWarningForSilentErrors(): void
    {
        $this->assertStringContainsString(
            'log_warning',
            self::$sourceCode,
            'AuthController deve usar log_warning() para erros não-críticos'
        );
    }

    public function testNoErrorLogUsage(): void
    {
        $this->assertStringNotContainsString(
            'error_log(',
            self::$sourceCode,
            'AuthController não deve usar error_log() — use Log:: ou log_warning()'
        );
    }

    public function testNoVarDumpUsage(): void
    {
        $this->assertStringNotContainsString(
            'var_dump(',
            self::$sourceCode,
            'AuthController não deve ter var_dump() em produção'
        );
    }

    // =============================
    // OAUTH PATTERNS
    // =============================

    public function testCallbackHandlesOAuthCode(): void
    {
        $this->assertStringContainsString(
            'exchangeCodeForTokens',
            self::$sourceCode,
            'callback() deve trocar code por tokens via authService'
        );
    }

    public function testAuthorizeRedirectsToMl(): void
    {
        $this->assertStringContainsString(
            'getAuthUrl',
            self::$sourceCode,
            'authorize() deve obter URL de autenticação ML'
        );
    }

    // =============================
    // PASSWORD RESET PATTERNS
    // =============================

    public function testPasswordResetUsesTokenValidation(): void
    {
        $this->assertStringContainsString(
            'token',
            strtolower(self::$sourceCode),
            'Reset de senha deve usar tokens'
        );
    }

    // =============================
    // ACCOUNT MANAGEMENT PATTERNS
    // =============================

    public function testDisconnectRemovesSession(): void
    {
        $this->assertStringContainsString(
            'active_ml_account_id',
            self::$sourceCode,
            'disconnect() deve limpar a conta ativa da sessão'
        );
    }

    public function testDeleteAccountUsesTransaction(): void
    {
        $this->assertStringContainsString(
            'beginTransaction',
            self::$sourceCode,
            'deleteAccount() deve usar transação DB'
        );
        $this->assertStringContainsString(
            'commit',
            self::$sourceCode,
            'deleteAccount() deve commitar a transação'
        );
        $this->assertStringContainsString(
            'rollBack',
            self::$sourceCode,
            'deleteAccount() deve fazer rollback em caso de erro'
        );
    }

    public function testSyncUsesAccountSyncService(): void
    {
        $this->assertStringContainsString(
            'AccountSyncService',
            self::$sourceCode,
            'Sync methods devem usar AccountSyncService'
        );
    }

    // =============================
    // MOBILE AUTH PATTERNS
    // =============================

    public function testMobileLoginReturnsToken(): void
    {
        $this->assertStringContainsString(
            'token',
            strtolower(self::$sourceCode),
            'mobileLogin() deve retornar token de autenticação'
        );
    }

    // =============================
    // ERROR HANDLING
    // =============================

    public function testUsesLogErrorForExceptions(): void
    {
        $this->assertStringContainsString(
            'Log::error',
            self::$sourceCode,
            'AuthController deve usar Log::error para exceções críticas'
        );
    }

    public function testReturnsJsonErrorOnFailure(): void
    {
        $this->assertStringContainsString(
            'json_encode',
            self::$sourceCode,
            'AuthController deve retornar JSON em endpoints de API'
        );
    }

    public function testSetsHttpResponseCode(): void
    {
        $this->assertStringContainsString(
            'http_response_code(500)',
            self::$sourceCode,
            'AuthController deve definir código HTTP 500 em erros'
        );
    }

    // =============================
    // REMEMBER ME
    // =============================

    public function testImplementsRememberMe(): void
    {
        $this->assertStringContainsString(
            'remember_token',
            self::$sourceCode,
            'AuthController deve implementar "lembrar-me" com token'
        );
    }

    // =============================
    // DOCUMENTATION
    // =============================

    /**
     * @dataProvider documentedMethodsProvider
     */
    public function testMethodHasDocumentation(string $method): void
    {
        $ref = new \ReflectionMethod(AuthController::class, $method);
        $doc = $ref->getDocComment();
        $this->assertNotFalse($doc, "{$method}() deve ter documentação PHPDoc");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documentedMethodsProvider(): array
    {
        return [
            'login' => ['login'],
            'doLogin' => ['doLogin'],
            'logout' => ['logout'],
            'register' => ['register'],
            'doRegister' => ['doRegister'],
            'authorize' => ['authorize'],
            'callback' => ['callback'],
        ];
    }

    // =============================
    // METHOD COUNT
    // =============================

    public function testHasExpectedPublicMethodCount(): void
    {
        $methods = self::$reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $ownMethods = array_filter(
            $methods,
            fn(\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === AuthController::class
        );
        $this->assertGreaterThanOrEqual(
            20,
            count($ownMethods),
            'AuthController deve ter pelo menos 20 métodos públicos próprios'
        );
    }

    public function testCallbackDoesNotUnconditionallyStealActiveAccount(): void
    {
        $authService = file_get_contents(dirname(__DIR__, 3) . '/app/Services/MercadoLivreAuthService.php');
        $this->assertIsString($authService);
        $this->assertStringContainsString(
            'shouldSetActiveAccountAfterOAuth',
            $authService,
            'OAuth token exchange must gate setActiveAccountId for a new second account'
        );
    }
}
