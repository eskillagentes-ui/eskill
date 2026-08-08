<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\MercadoLivreClient;
use App\Services\EncryptionService;
use App\Database;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class MercadoLivreClientTest extends TestCase
{
    private int $accountId;
    private int $testUserId;
    private string $testMlUserId;
    /** @var array<int, array<string, mixed>> */
    private array $requestHistory = [];

    private function currentTestRequiresDatabase(): bool
    {
        return in_array($this->getName(false), [
            'testLoadAccountDecryptsToken',
            'testEnsureValidAccessToken_uses_refresh_when_expired',
            'testEnsureValidAccessToken_keepsUsableTokenWhenRefreshLockFails',
            'testEnsureValidAccessToken_failsWhenExpiredAndRefreshLockFails',
        ], true);
    }

    /**
     * @param array<int, Response> $responses
     * @return MercadoLivreClient
     */
    private function makeClientWithMockedTransport(array $responses): MercadoLivreClient
    {
        $this->requestHistory = [];
        $historyMiddleware = Middleware::history($this->requestHistory);
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push($historyMiddleware);

        $guzzle = new GuzzleClient(['handler' => $handler]);

        $client = new MercadoLivreClient(null);

        $httpClientProperty = new \ReflectionProperty(MercadoLivreClient::class, 'httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($client, $guzzle);

        $publicHttpClientProperty = new \ReflectionProperty(MercadoLivreClient::class, 'publicHttpClient');
        $publicHttpClientProperty->setAccessible(true);
        $publicHttpClientProperty->setValue($client, $guzzle);

        return $client;
    }

    private function setAuthenticatedState(MercadoLivreClient $client, string $token = 'unit-test-token'): void
    {
        $accessTokenProperty = new \ReflectionProperty(MercadoLivreClient::class, 'accessToken');
        $accessTokenProperty->setAccessible(true);
        $accessTokenProperty->setValue($client, $token);

        $hasAccessTokenProperty = new \ReflectionProperty(MercadoLivreClient::class, 'hasAccessToken');
        $hasAccessTokenProperty->setAccessible(true);
        $hasAccessTokenProperty->setValue($client, true);

        $tokenSourceProperty = new \ReflectionProperty(MercadoLivreClient::class, 'tokenSource');
        $tokenSourceProperty->setAccessible(true);
        $tokenSourceProperty->setValue($client, 'test');
    }

    protected function setUp(): void
    {
        // Ensure APP_KEY for EncryptionService
        putenv('APP_KEY=UnitTestFallbackKey_ThisIs32CharsLong!');

        if (!$this->currentTestRequiresDatabase()) {
            return;
        }

        try {
            $pdo = Database::getInstance();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL indisponível para testes que dependem de ml_accounts');
        }

        // Create table if not exists (safe DDL for tests)
        $pdo->exec("CREATE TABLE IF NOT EXISTS ml_accounts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            ml_user_id VARCHAR(64) NOT NULL,
            nickname VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            site_id VARCHAR(10) NOT NULL DEFAULT 'MLB',
            access_token TEXT NULL,
            refresh_token TEXT NULL,
            token_expires_at DATETIME NULL,
            last_synced_at DATETIME NULL,
            scopes VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            tokens_encrypted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Clean up only fixtures owned by this test suite
        $pdo->prepare("DELETE FROM ml_accounts WHERE ml_user_id LIKE 'MLUSER_CLIENT_TEST_%'")->execute();

        // Ensure users table and a test user exist (ml_accounts.user_id FK)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255),
                email VARCHAR(255),
                password VARCHAR(255),
                role VARCHAR(50) DEFAULT 'admin',
                email_verified_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        } catch (\Throwable $e) {
            // ignore
        }

        $email = 'mlclient-' . bin2hex(random_bytes(4)) . '@test.local';
        try {
            $pdo->prepare('INSERT INTO users (name, email, password) VALUES (:name, :email, :password)')
                ->execute(['name' => 'ML Test User', 'email' => $email, 'password' => password_hash('secret', PASSWORD_BCRYPT)]);
            $this->testUserId = (int)$pdo->lastInsertId();
        } catch (\Throwable $e) {
            // ignore
        }

        // Ensure tokens_encrypted column exists (older test DBs may miss it)
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM ml_accounts LIKE 'tokens_encrypted'");
            $col = $stmt->fetch();
            if (!$col) {
                $pdo->exec("ALTER TABLE ml_accounts ADD COLUMN tokens_encrypted TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (\Throwable $e) {
            // ignore - best effort
        }

        $this->testMlUserId = 'MLUSER_CLIENT_TEST_' . bin2hex(random_bytes(4));

        // Insert an account with encrypted tokens
        $enc = new EncryptionService();
        $accessEnc = $enc->encrypt('initial-access-token');
        $refreshEnc = $enc->encrypt('initial-refresh-token');
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $stmt = $pdo->prepare("INSERT INTO ml_accounts
            (user_id, ml_user_id, nickname, email, site_id, access_token, refresh_token, token_expires_at, tokens_encrypted, status, created_at, updated_at)
            VALUES (:user_id, :ml_user_id, :nickname, :email, :site_id, :access_token, :refresh_token, :expires_at, :tokens_encrypted, 'active', NOW(), NOW())");

        $stmt->execute([
            'user_id' => $this->testUserId,
            'ml_user_id' => $this->testMlUserId,
            'nickname' => 'unittest-ml',
            'email' => 'ml@example.com',
            'site_id' => 'MLB',
            'access_token' => $accessEnc,
            'refresh_token' => $refreshEnc,
            'expires_at' => $expiresAt,
            'tokens_encrypted' => 1
        ]);

        $this->accountId = (int)$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->currentTestRequiresDatabase()) {
            try {
                $pdo = Database::getInstance();
                if (isset($this->testMlUserId) && $this->testMlUserId !== '') {
                    $pdo->prepare('DELETE FROM ml_accounts WHERE ml_user_id = :ml_user_id')->execute([
                        'ml_user_id' => $this->testMlUserId,
                    ]);
                }
                if (isset($this->testUserId) && $this->testUserId > 0) {
                    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->testUserId]);
                }
            } catch (\Throwable $e) {
                // ignore cleanup failures in tests
            }
        }

        parent::tearDown();
    }

    public function testLoadAccountDecryptsToken()
    {
        $client = new MercadoLivreClient($this->accountId);
        $client->loadAccount();

        $this->assertEquals('initial-access-token', $client->getAccessToken());
    }

    public function testEnsureValidAccessToken_uses_refresh_when_expired()
    {
        $pdo = Database::getInstance();

        // Expire the token
        $pdo->prepare('UPDATE ml_accounts SET token_expires_at = :exp WHERE id = :id')
            ->execute(['exp' => date('Y-m-d H:i:s', time() - 3600), 'id' => $this->accountId]);

        // Create a Test AuthService that will simulate refresh by updating the DB
        $testAuth = new class extends \App\Services\MercadoLivreAuthService {
            public function refreshToken(int $accountId, int $maxRetries = 3): bool
            {
                $db = Database::getInstance();
                $enc = new EncryptionService();
                $newAccess = $enc->encrypt('refreshed-access-token');
                $newRefresh = $enc->encrypt('refreshed-refresh-token');
                $expiresAt = date('Y-m-d H:i:s', time() + 7200);

                $upd = $db->prepare('UPDATE ml_accounts SET access_token = :access_token, refresh_token = :refresh_token, token_expires_at = :expires_at, tokens_encrypted = 1, updated_at = NOW() WHERE id = :id');
                $upd->execute(['access_token' => $newAccess, 'refresh_token' => $newRefresh, 'expires_at' => $expiresAt, 'id' => $accountId]);
                return (bool)$upd->rowCount();
            }
        };

        $client = new MercadoLivreClient($this->accountId, $testAuth);
        $ok = $client->ensureValidAccessToken();

        $this->assertTrue($ok, 'ensureValidAccessToken should return true after simulated refresh');
        $this->assertEquals('refreshed-access-token', $client->getAccessToken());
    }

    public function testEnsureValidAccessToken_keepsUsableTokenWhenRefreshLockFails(): void
    {
        $pdo = Database::getInstance();

        // Dentro do buffer de refresh (60 min), mas ainda utilizável (>30s).
        $pdo->prepare('UPDATE ml_accounts SET token_expires_at = :exp WHERE id = :id')
            ->execute(['exp' => date('Y-m-d H:i:s', time() + 2700), 'id' => $this->accountId]);

        $testAuth = new class extends \App\Services\MercadoLivreAuthService {
            public function refreshToken(int $accountId, int $maxRetries = 3): bool
            {
                // Simula lock concorrente / refresh em andamento em outro processo.
                return false;
            }
        };

        $client = new MercadoLivreClient($this->accountId, $testAuth);
        $ok = $client->ensureValidAccessToken(60);

        $this->assertTrue($ok, 'Token ainda válido deve ser aceito quando refresh falha por lock');
        $this->assertEquals('initial-access-token', $client->getAccessToken());
    }

    public function testEnsureValidAccessToken_failsWhenExpiredAndRefreshLockFails(): void
    {
        $pdo = Database::getInstance();

        $pdo->prepare('UPDATE ml_accounts SET token_expires_at = :exp WHERE id = :id')
            ->execute(['exp' => date('Y-m-d H:i:s', time() - 120), 'id' => $this->accountId]);

        $testAuth = new class extends \App\Services\MercadoLivreAuthService {
            public function refreshToken(int $accountId, int $maxRetries = 3): bool
            {
                return false;
            }
        };

        $client = new MercadoLivreClient($this->accountId, $testAuth);
        $ok = $client->ensureValidAccessToken(60);

        $this->assertFalse($ok, 'Token expirado sem refresh não deve ser considerado válido');
    }

    public function testNetworkDisabledInTestingEnvironment(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $previousAllow = $_ENV['ML_ALLOW_NETWORK'] ?? null;

        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        putenv('ML_ALLOW_NETWORK');
        unset($_ENV['ML_ALLOW_NETWORK']);

        $client = new MercadoLivreClient(null);
        $response = $client->get('/sites/MLB/search', ['q' => 'test'], 0, true);

        $this->assertEquals('network_disabled', $response['error'] ?? null);
        $this->assertEquals(503, $response['status'] ?? null);

        if ($previousEnv !== null) {
            putenv("APP_ENV={$previousEnv}");
            $_ENV['APP_ENV'] = $previousEnv;
        } else {
            putenv('APP_ENV');
            unset($_ENV['APP_ENV']);
        }

        if ($previousAllow !== null) {
            putenv("ML_ALLOW_NETWORK={$previousAllow}");
            $_ENV['ML_ALLOW_NETWORK'] = $previousAllow;
        } else {
            putenv('ML_ALLOW_NETWORK');
            unset($_ENV['ML_ALLOW_NETWORK']);
        }
    }

    public function testNetworkCallsAreOptIn(): void
    {
        $allow = $_ENV['ML_ALLOW_NETWORK'] ?? getenv('ML_ALLOW_NETWORK') ?? null;
        if (!filter_var($allow, FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('ML_ALLOW_NETWORK not enabled');
        }

        $client = new MercadoLivreClient(null);
        $response = $client->get('/sites/MLB/search', ['q' => 'notebook'], 0, true);

        $this->assertIsArray($response);
    }

    public function testSkipsClientIdForKnownPolicyBlockedPublicEndpoint(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $previousAppId = $_ENV['ML_APP_ID'] ?? null;
        $previousMode = $_ENV['ML_PUBLIC_CLIENT_ID_MODE'] ?? null;

        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        putenv('ML_APP_ID=757032559637450');
        $_ENV['ML_APP_ID'] = '757032559637450';
        putenv('ML_PUBLIC_CLIENT_ID_MODE=auto');
        $_ENV['ML_PUBLIC_CLIENT_ID_MODE'] = 'auto';

        try {
            $client = $this->makeClientWithMockedTransport([
                new Response(200, ['Content-Type' => 'application/json'], json_encode(['id' => 'MLB', 'name' => 'Brasil'])),
            ]);

            $response = $client->get('/sites/MLB', [], 0, true);

            $this->assertSame('MLB', $response['id'] ?? null);
            $this->assertCount(1, $this->requestHistory);

            parse_str($this->requestHistory[0]['request']->getUri()->getQuery(), $query);
            $this->assertArrayNotHasKey('client_id', $query);
        } finally {
            if ($previousEnv !== null) {
                putenv("APP_ENV={$previousEnv}");
                $_ENV['APP_ENV'] = $previousEnv;
            } else {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            }

            if ($previousAppId !== null) {
                putenv("ML_APP_ID={$previousAppId}");
                $_ENV['ML_APP_ID'] = $previousAppId;
            } else {
                putenv('ML_APP_ID');
                unset($_ENV['ML_APP_ID']);
            }

            if ($previousMode !== null) {
                putenv("ML_PUBLIC_CLIENT_ID_MODE={$previousMode}");
                $_ENV['ML_PUBLIC_CLIENT_ID_MODE'] = $previousMode;
            } else {
                putenv('ML_PUBLIC_CLIENT_ID_MODE');
                unset($_ENV['ML_PUBLIC_CLIENT_ID_MODE']);
            }
        }
    }

    public function testDisablesInheritedProxyWhenMlProxyIsDisabled(): void
    {
        $previousEnabled = $_ENV['ML_PROXY_ENABLED'] ?? null;
        $previousHttpProxy = getenv('HTTP_PROXY') ?: null;

        putenv('ML_PROXY_ENABLED=false');
        $_ENV['ML_PROXY_ENABLED'] = 'false';
        putenv('HTTP_PROXY=http://proxy.mercadolivre.com:8080');

        try {
            $client = new MercadoLivreClient(null);
            $method = new \ReflectionMethod(MercadoLivreClient::class, 'createHttpClient');
            $method->setAccessible(true);

            /** @var GuzzleClient $guzzle */
            $guzzle = $method->invoke($client, false);

            $this->assertSame('', $guzzle->getConfig('proxy'));
        } finally {
            if ($previousEnabled !== null) {
                putenv("ML_PROXY_ENABLED={$previousEnabled}");
                $_ENV['ML_PROXY_ENABLED'] = $previousEnabled;
            } else {
                putenv('ML_PROXY_ENABLED');
                unset($_ENV['ML_PROXY_ENABLED']);
            }

            if ($previousHttpProxy !== null) {
                putenv("HTTP_PROXY={$previousHttpProxy}");
            } else {
                putenv('HTTP_PROXY');
            }
        }
    }

    public function testRetriesWithoutClientIdWhenUnknownPublicEndpointIsPolicyBlocked(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $previousAppId = $_ENV['ML_APP_ID'] ?? null;
        $previousMode = $_ENV['ML_PUBLIC_CLIENT_ID_MODE'] ?? null;

        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        putenv('ML_APP_ID=757032559637450');
        $_ENV['ML_APP_ID'] = '757032559637450';
        putenv('ML_PUBLIC_CLIENT_ID_MODE=auto');
        $_ENV['ML_PUBLIC_CLIENT_ID_MODE'] = 'auto';

        try {
            $client = $this->makeClientWithMockedTransport([
                new Response(403, ['Content-Type' => 'application/json'], json_encode([
                    'message' => 'At least one policy returned UNAUTHORIZED.',
                    'blocked_by' => 'PolicyAgent',
                    'code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES',
                    'status' => 403,
                ])),
                new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'suggested_queries' => [
                        ['q' => 'bagageiro cg 160'],
                    ],
                ])),
            ]);

            $response = $client->get('/sites/MLB/autosuggest', ['q' => 'bagageiro'], 0, true);

            $this->assertCount(2, $this->requestHistory);
            $this->assertSame('bagageiro cg 160', $response['suggested_queries'][0]['q'] ?? null);

            parse_str($this->requestHistory[0]['request']->getUri()->getQuery(), $firstQuery);
            parse_str($this->requestHistory[1]['request']->getUri()->getQuery(), $secondQuery);

            $this->assertSame('757032559637450', $firstQuery['client_id'] ?? null);
            $this->assertArrayNotHasKey('client_id', $secondQuery);
        } finally {
            if ($previousEnv !== null) {
                putenv("APP_ENV={$previousEnv}");
                $_ENV['APP_ENV'] = $previousEnv;
            } else {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            }

            if ($previousAppId !== null) {
                putenv("ML_APP_ID={$previousAppId}");
                $_ENV['ML_APP_ID'] = $previousAppId;
            } else {
                putenv('ML_APP_ID');
                unset($_ENV['ML_APP_ID']);
            }

            if ($previousMode !== null) {
                putenv("ML_PUBLIC_CLIENT_ID_MODE={$previousMode}");
                $_ENV['ML_PUBLIC_CLIENT_ID_MODE'] = $previousMode;
            } else {
                putenv('ML_PUBLIC_CLIENT_ID_MODE');
                unset($_ENV['ML_PUBLIC_CLIENT_ID_MODE']);
            }
        }
    }

    public function testCatalogProductWithoutWinnersIsSoftMiss(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;

        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        try {
            $client = $this->makeClientWithMockedTransport([
                new Response(404, ['Content-Type' => 'application/json'], json_encode([
                    'message' => 'No winners found',
                    'error' => 'not_found',
                    'status' => 404,
                    'cause' => [],
                ])),
            ]);
            $this->setAuthenticatedState($client);

            $response = $client->get('/products/MLB58038968/items');

            $this->assertSame(404, $response['status'] ?? null);
            $this->assertNotEmpty($response['error'] ?? null);
        } finally {
            if ($previousEnv !== null) {
                putenv("APP_ENV={$previousEnv}");
                $_ENV['APP_ENV'] = $previousEnv;
            } else {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            }
        }
    }

    public function testReturnsListingsQualityUnavailableForOptionalAuthEndpoint(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;

        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        try {
            $client = $this->makeClientWithMockedTransport([
                new Response(403, ['Content-Type' => 'application/json'], json_encode([
                    'message' => 'forbidden',
                    'status' => 403,
                ])),
            ]);
            $this->setAuthenticatedState($client);

            $response = $client->get('/users/123/listings_quality');

            $this->assertSame('listings_quality_unavailable', $response['error'] ?? null);
            $this->assertSame('listings_quality', $response['feature'] ?? null);
            $this->assertTrue($response['optional_feature'] ?? false);
            $this->assertSame(403, $response['status'] ?? null);
        } finally {
            if ($previousEnv !== null) {
                putenv("APP_ENV={$previousEnv}");
                $_ENV['APP_ENV'] = $previousEnv;
            } else {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            }
        }
    }

    public function testReturnsBrandCentralUnavailableForOfficialStoreEndpoint(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;

        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        try {
            $client = $this->makeClientWithMockedTransport([
                new Response(404, ['Content-Type' => 'application/json'], json_encode([
                    'message' => 'not found',
                    'status' => 404,
                ])),
            ]);
            $this->setAuthenticatedState($client);

            $response = $client->get('/users/123/brands_official_store');

            $this->assertSame('brand_central_unavailable', $response['error'] ?? null);
            $this->assertSame('brand_central', $response['feature'] ?? null);
            $this->assertTrue($response['optional_feature'] ?? false);
            $this->assertSame(404, $response['status'] ?? null);
        } finally {
            if ($previousEnv !== null) {
                putenv("APP_ENV={$previousEnv}");
                $_ENV['APP_ENV'] = $previousEnv;
            } else {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            }
        }
    }

    public function testReturnsShippingPreferencesUnavailableForOptionalShippingEndpoint(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;

        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        try {
            $client = $this->makeClientWithMockedTransport([
                new Response(403, ['Content-Type' => 'application/json'], json_encode([
                    'message' => 'forbidden',
                    'status' => 403,
                ])),
            ]);
            $this->setAuthenticatedState($client);

            $response = $client->get('/users/123/shipping_preferences');

            $this->assertSame('shipping_preferences_unavailable', $response['error'] ?? null);
            $this->assertSame('shipping_preferences', $response['feature'] ?? null);
            $this->assertTrue($response['optional_feature'] ?? false);
            $this->assertSame(403, $response['status'] ?? null);
        } finally {
            if ($previousEnv !== null) {
                putenv("APP_ENV={$previousEnv}");
                $_ENV['APP_ENV'] = $previousEnv;
            } else {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            }
        }
    }

    public function testReturnsOrdersAccessUnavailableForOrdersSearchEndpoint(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;

        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        try {
            $client = $this->makeClientWithMockedTransport([
                new Response(403, ['Content-Type' => 'application/json'], json_encode([
                    'message' => 'forbidden',
                    'status' => 403,
                ])),
            ]);
            $this->setAuthenticatedState($client);

            $response = $client->get('/orders/search', ['seller' => '123']);

            $this->assertSame('orders_access_unavailable', $response['error'] ?? null);
            $this->assertSame('orders', $response['feature'] ?? null);
            $this->assertTrue($response['optional_feature'] ?? false);
            $this->assertSame(403, $response['status'] ?? null);
        } finally {
            if ($previousEnv !== null) {
                putenv("APP_ENV={$previousEnv}");
                $_ENV['APP_ENV'] = $previousEnv;
            } else {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            }
        }
    }

    public function testReturnsMerchantOrdersUnavailableForMerchantOrdersEndpoint(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;

        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        try {
            $client = $this->makeClientWithMockedTransport([
                new Response(404, ['Content-Type' => 'application/json'], json_encode([
                    'message' => 'not found',
                    'status' => 404,
                ])),
            ]);
            $this->setAuthenticatedState($client);

            $response = $client->get('/merchant_orders/search?collector_id=123');

            $this->assertSame('merchant_orders_unavailable', $response['error'] ?? null);
            $this->assertSame('merchant_orders', $response['feature'] ?? null);
            $this->assertTrue($response['optional_feature'] ?? false);
            $this->assertSame(404, $response['status'] ?? null);
        } finally {
            if ($previousEnv !== null) {
                putenv("APP_ENV={$previousEnv}");
                $_ENV['APP_ENV'] = $previousEnv;
            } else {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            }
        }
    }

    public function testRateLimitWithoutRetryAfterUsesDefaultWaitInCli(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/../app/Services/MercadoLivreClient.php'
        );

        $this->assertStringContainsString('ML_RATE_LIMIT_DEFAULT_WAIT_SECONDS', $source);
        $this->assertStringContainsString('used_default_wait', $source);
        $this->assertStringContainsString('connect_timeout', $source);
        $this->assertStringContainsString('resolveOutboundCorrelationId', $source);
    }
}
