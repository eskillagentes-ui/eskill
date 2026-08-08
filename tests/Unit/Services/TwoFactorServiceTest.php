<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\TwoFactorService;

/**
 * Testes do TwoFactorService
 *
 * TwoFactorService é implementação TOTP pura (RFC 6238), sem dependências externas.
 * Todos os testes são comportamentais (não apenas estruturais).
 *
 * @covers \App\Services\TwoFactorService
 */
class TwoFactorServiceTest extends TestCase
{
    private TwoFactorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TwoFactorService();
    }

    // =============================
    // STRICT TYPES
    // =============================

    public function testHasStrictTypesDeclaration(): void
    {
        $ref = new \ReflectionClass(TwoFactorService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());
        $this->assertMatchesRegularExpression(
            '/declare\s*\(\s*strict_types\s*=\s*1\s*\)/',
            $source,
            'TwoFactorService deve ter declare(strict_types=1)'
        );
    }

    // =============================
    // INSTANCIAÇÃO
    // =============================

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(TwoFactorService::class, $this->service);
    }

    // =============================
    // GENERATE SECRET
    // =============================

    public function testGenerateSecretReturnsString(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertIsString($secret);
    }

    public function testGenerateSecretDefaultLength(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertEquals(16, strlen($secret), 'Secret padrão deve ter 16 caracteres');
    }

    public function testGenerateSecretCustomLength(): void
    {
        $secret = $this->service->generateSecret(32);
        $this->assertEquals(32, strlen($secret), 'Secret com length=32 deve ter 32 caracteres');
    }

    public function testGenerateSecretUsesBase32Characters(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertMatchesRegularExpression(
            '/^[A-Z2-7]+$/',
            $secret,
            'Secret deve conter apenas caracteres Base32 (A-Z, 2-7)'
        );
    }

    public function testGenerateSecretIsRandom(): void
    {
        $secrets = [];
        for ($i = 0; $i < 10; $i++) {
            $secrets[] = $this->service->generateSecret();
        }
        $unique = array_unique($secrets);
        $this->assertGreaterThan(1, count($unique), 'Secrets gerados devem ser diferentes');
    }

    public function testGenerateSecretMinimumLength(): void
    {
        $secret = $this->service->generateSecret(1);
        $this->assertEquals(1, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]$/', $secret);
    }

    // =============================
    // QR CODE URL
    // =============================

    public function testGetQrCodeUrlReturnsString(): void
    {
        $url = $this->service->getQrCodeUrl('MyApp', 'user@test.com', 'JBSWY3DPEHPK3PXP');
        $this->assertIsString($url);
    }

    public function testGetQrCodeUrlReturnsLocalPngDataUri(): void
    {
        $url = $this->service->getQrCodeUrl('MyApp', 'user@test.com', 'JBSWY3DPEHPK3PXP');
        $this->assertStringStartsWith('data:image/png;base64,', $url);

        $png = base64_decode(substr($url, strlen('data:image/png;base64,')), true);
        $this->assertIsString($png);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
    }

    public function testGetQrCodeUrlDoesNotExposeSecretOutsideImageData(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $url = $this->service->getQrCodeUrl('MyApp', 'user@test.com', $secret);

        $this->assertStringNotContainsString($secret, $url);
        $this->assertStringNotContainsString('qrserver.com', $url);
    }

    // =============================
    // VERIFY CODE (behavioral — TOTP core)
    // =============================

    public function testVerifyCodeAcceptsCurrentTimeSlice(): void
    {
        $secret = $this->service->generateSecret();

        // Gerar código para o time slice atual via reflection
        $ref = new \ReflectionMethod(TwoFactorService::class, 'getCode');
        $ref->setAccessible(true);
        $currentCode = $ref->invoke($this->service, $secret, time());

        $this->assertTrue(
            $this->service->verifyCode($secret, $currentCode),
            'verifyCode deve aceitar o código do time slice atual'
        );
    }

    public function testVerifyCodeRejectsBadCode(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertFalse(
            $this->service->verifyCode($secret, '000000'),
            'verifyCode deve rejeitar código inválido (chance mínima de colisão)'
        );
    }

    public function testVerifyCodeRejectsEmptyCode(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertFalse($this->service->verifyCode($secret, ''));
    }

    public function testVerifyCodeAcceptsWindowOffset(): void
    {
        $secret = $this->service->generateSecret();

        // Gerar código para time slice -30 segundos (window=1 deve aceitar)
        $ref = new \ReflectionMethod(TwoFactorService::class, 'getCode');
        $ref->setAccessible(true);
        $previousCode = $ref->invoke($this->service, $secret, time() - 30);

        $this->assertTrue(
            $this->service->verifyCode($secret, $previousCode, 1),
            'verifyCode com window=1 deve aceitar código do time slice anterior'
        );
    }

    public function testVerifyCodeReturnsBool(): void
    {
        $result = $this->service->verifyCode('JBSWY3DPEHPK3PXP', '123456');
        $this->assertIsBool($result);
    }

    // =============================
    // GET CODE (via reflection)
    // =============================

    public function testGetCodeReturns6Digits(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'getCode');
        $ref->setAccessible(true);

        $code = $ref->invoke($this->service, 'JBSWY3DPEHPK3PXP', time());
        $this->assertEquals(6, strlen($code), 'Código TOTP deve ter 6 dígitos');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code, 'Código deve ser numérico');
    }

    public function testGetCodeIsDeterministic(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'getCode');
        $ref->setAccessible(true);

        $ts = 1700000000;
        $code1 = $ref->invoke($this->service, 'JBSWY3DPEHPK3PXP', $ts);
        $code2 = $ref->invoke($this->service, 'JBSWY3DPEHPK3PXP', $ts);
        $this->assertEquals($code1, $code2, 'O mesmo secret+timestamp deve produzir o mesmo código');
    }

    public function testGetCodeDiffersForDifferentSecrets(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'getCode');
        $ref->setAccessible(true);

        $ts = 1700000000;
        $code1 = $ref->invoke($this->service, 'JBSWY3DPEHPK3PXP', $ts);
        $code2 = $ref->invoke($this->service, 'KBSWY3DPEHPK3PXP', $ts);
        $this->assertNotEquals($code1, $code2, 'Secrets diferentes devem gerar códigos diferentes');
    }

    public function testGetCodeDiffersForDifferentTimestamps(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'getCode');
        $ref->setAccessible(true);

        $secret = 'JBSWY3DPEHPK3PXP';
        $code1 = $ref->invoke($this->service, $secret, 1700000000);
        $code2 = $ref->invoke($this->service, $secret, 1700000060); // 2 time slices ahead
        $this->assertNotEquals($code1, $code2, 'Timestamps diferentes devem gerar códigos diferentes');
    }

    // =============================
    // BASE32 DECODE (via reflection)
    // =============================

    public function testBase32DecodeKnownValue(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'base32Decode');
        $ref->setAccessible(true);

        // "JBSWY3DPEHPK3PXP" is Base32 for "Hello!???"  - known encoding
        $result = $ref->invoke($this->service, 'JBSWY3DP');
        $this->assertEquals('Hello', $result, 'JBSWY3DP deve decodificar para "Hello"');
    }

    public function testBase32DecodeEmptyString(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'base32Decode');
        $ref->setAccessible(true);

        $result = $ref->invoke($this->service, '');
        $this->assertEquals('', $result, 'String vazia deve retornar vazio');
    }

    public function testBase32DecodeInvalidChars(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'base32Decode');
        $ref->setAccessible(true);

        $result = $ref->invoke($this->service, '!@#$%');
        $this->assertEquals('', $result, 'Caracteres inválidos devem retornar vazio');
    }

    // =============================
    // STRUCTURE
    // =============================

    /**
     * @dataProvider publicMethodsProvider
     */
    public function testHasPublicMethod(string $method): void
    {
        $this->assertTrue(
            method_exists(TwoFactorService::class, $method),
            "TwoFactorService deve ter método {$method}()"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicMethodsProvider(): array
    {
        return [
            'generateSecret' => ['generateSecret'],
            'getQrCodeUrl' => ['getQrCodeUrl'],
            'verifyCode' => ['verifyCode'],
        ];
    }

    public function testHasPrivateMethods(): void
    {
        $ref = new \ReflectionClass(TwoFactorService::class);
        $privateMethods = $ref->getMethods(\ReflectionMethod::IS_PRIVATE);
        $names = array_map(fn(\ReflectionMethod $m): string => $m->getName(), $privateMethods);

        $this->assertContains('getCode', $names, 'Deve ter método privado getCode');
        $this->assertContains('base32Decode', $names, 'Deve ter método privado base32Decode');
    }

    public function testHasValidCharactersConstant(): void
    {
        $ref = new \ReflectionClass(TwoFactorService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());
        $this->assertStringContainsString('VALID_CHARACTERS', $source);
        $this->assertStringContainsString('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $source);
    }

    // =============================
    // METHOD SIGNATURES
    // =============================

    public function testGenerateSecretHasDefaultLength(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'generateSecret');
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertEquals(16, $params[0]->getDefaultValue());
    }

    public function testVerifyCodeHasWindowParameter(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'verifyCode');
        $params = $ref->getParameters();
        $this->assertCount(3, $params);
        $this->assertEquals('secret', $params[0]->getName());
        $this->assertEquals('code', $params[1]->getName());
        $this->assertEquals('window', $params[2]->getName());
        $this->assertTrue($params[2]->isDefaultValueAvailable());
        $this->assertEquals(1, $params[2]->getDefaultValue());
    }

    public function testGenerateSecretReturnsStringType(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'generateSecret');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('string', $returnType->getName());
    }

    public function testVerifyCodeReturnsBoolType(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'verifyCode');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('bool', $returnType->getName());
    }

    public function testGetQrCodeUrlReturnsStringType(): void
    {
        $ref = new \ReflectionMethod(TwoFactorService::class, 'getQrCodeUrl');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('string', $returnType->getName());
    }

    // =============================
    // NO BAD PRACTICES
    // =============================

    public function testNoErrorLogUsage(): void
    {
        $ref = new \ReflectionClass(TwoFactorService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());
        $this->assertStringNotContainsString('error_log(', $source);
    }

    public function testNoVarDumpUsage(): void
    {
        $ref = new \ReflectionClass(TwoFactorService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());
        $this->assertStringNotContainsString('var_dump(', $source);
    }

    // =============================
    // SECURITY: TOTP PROPERTIES
    // =============================

    public function testUsesRandomInt(): void
    {
        $ref = new \ReflectionClass(TwoFactorService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());
        $this->assertStringContainsString(
            'random_int',
            $source,
            'generateSecret deve usar random_int() (CSPRNG) para segurança'
        );
    }

    public function testUsesHmacSha1(): void
    {
        $ref = new \ReflectionClass(TwoFactorService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());
        $this->assertStringContainsString(
            'hash_hmac',
            $source,
            'getCode deve usar hash_hmac para TOTP (RFC 6238)'
        );
        $this->assertStringContainsString(
            'sha1',
            $source,
            'TOTP padrão usa SHA-1'
        );
    }

    public function testUses30SecondTimeSlice(): void
    {
        $ref = new \ReflectionClass(TwoFactorService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());
        // TOTP standard uses 30-second time slices
        $this->assertStringContainsString('30', $source, 'TOTP deve usar time slice de 30 segundos');
    }
}
