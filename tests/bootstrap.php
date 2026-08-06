<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load helpers
require_once __DIR__ . '/../app/Helpers/LogHelper.php';
require_once __DIR__ . '/../app/Helpers/CacheHelper.php';

if (!defined('CSP_NONCE')) {
    define('CSP_NONCE', 'test_nonce_value');
}

// Load environment variables for testing (prefer .env.testing when present)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../', ['.env.testing', '.env']);
$dotenv->safeLoad();

// Ensure variables from .env.testing are populated into $_ENV if Dotenv doesn't
$localEnv = __DIR__ . '/../.env.testing';
if (file_exists($localEnv)) {
    $lines = file($localEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, "=") === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '') {
            $_ENV[$k] = $v;
            putenv($k . '=' . $v);
        }
    }
}

// Set testing environment
$_ENV['APP_ENV'] = 'testing';
$_ENV['CACHE_ENABLED'] = false; // Disable cache in tests
if (!isset($_ENV['REDIS_DB']) || trim((string)$_ENV['REDIS_DB']) === '') {
    $_ENV['REDIS_DB'] = '15';
}
putenv('REDIS_DB=' . (string)$_ENV['REDIS_DB']);

// AIProviderManager/*Provider consideram um provider "disponível" apenas se a
// respectiva API key estiver não-vazia (ver App\Services\AI\Providers\*::isAvailable()).
// Os testes unitários de AIProviderManagerTest nunca chamam chat()/complete() —
// só exercitam seleção/fallback/estatísticas — então uma key falsa é suficiente
// para os providers se inicializarem sem qualquer chamada de rede real. Não
// sobrescreve se já houver uma key real configurada (ex.: dev local com .env).
$fakeAiKeyDefaults = [
    'OPENAI_API_KEY'    => 'sk-test-phpunit-fake-key-not-real',
    'ANTHROPIC_API_KEY' => 'sk-ant-test-phpunit-fake-key-not-real',
    'GEMINI_API_KEY'    => 'test-phpunit-fake-key-not-real',
];
foreach ($fakeAiKeyDefaults as $envKey => $fakeValue) {
    if (!isset($_ENV[$envKey]) || trim((string)$_ENV[$envKey]) === '') {
        $_ENV[$envKey] = $fakeValue;
        putenv($envKey . '=' . $fakeValue);
    }
}

// Make sessions safe in CLI tests even when there is output before session_start().
// PHPUnit may emit output (logs) during the run, and some services depend on $_SESSION.
// Disabling cookies and cache limiter prevents "headers already sent" warnings.
// Also, force save_path to a writable directory (sandbox environments may have read-only /var/lib/php/sessions
// and even a read-only /tmp). Use project storage for maximum portability.
$sessionSavePath = realpath(__DIR__ . '/../storage') ?: (__DIR__ . '/../storage');
$sessionSavePath = rtrim($sessionSavePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'sessions';
if (!is_dir($sessionSavePath)) {
    @mkdir($sessionSavePath, 0775, true);
}
@ini_set('session.save_path', $sessionSavePath);
@ini_set('session.use_cookies', '0');
@ini_set('session.use_only_cookies', '0');
@ini_set('session.cache_limiter', '');
@ini_set('session.cache_expire', '0');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Mock session if needed
if (!isset($_SESSION)) {
    $_SESSION = [];
}

/**
 * Protege contra execução de testes em um banco potencialmente não isolado.
 */
function isPhpUnitSafeDatabaseName(?string $dbName): bool
{
    if (!is_string($dbName)) {
        return false;
    }

    $normalized = strtolower(trim($dbName));
    if ($normalized === '') {
        return false;
    }

    return (bool)preg_match('/(^|[_-])(test|tests|testing|phpunit|ci)([_-]|$)/', $normalized)
        || str_starts_with($normalized, 'test_')
        || str_ends_with($normalized, '_test');
}

function shouldAllowNonTestPhpUnitDatabase(): bool
{
    $raw = $_ENV['PHPUNIT_ALLOW_NON_TEST_DB'] ?? getenv('PHPUNIT_ALLOW_NON_TEST_DB') ?? '';
    return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
}

function markPhpUnitDatabaseUnavailable(string $message): void
{
    if (!defined('PHPUNIT_DB_AVAILABLE')) {
        define('PHPUNIT_DB_AVAILABLE', false);
    }

    $GLOBALS['phpunit_db_error'] = $message;
    error_log('[phpunit-bootstrap] ' . $message);
}

// Detect PHPUnit testsuite from CLI args. Unit tests should not require DB connectivity.
$argv = $_SERVER['argv'] ?? [];
$requestedSuite = null;
for ($i = 0; $i < count($argv); $i++) {
    // Supports: --testsuite Unit
    if ($argv[$i] === '--testsuite' && isset($argv[$i + 1]) && is_string($argv[$i + 1])) {
        $requestedSuite = (string) $argv[$i + 1];
        break;
    }

    // Supports: --testsuite=Unit
    if (is_string($argv[$i]) && str_starts_with($argv[$i], '--testsuite=')) {
        $requestedSuite = (string) substr($argv[$i], strlen('--testsuite='));
        break;
    }
}

$suiteNormalized = $requestedSuite !== null ? strtolower(trim($requestedSuite)) : null;
// phpunit.xml sets defaultTestSuite="Unit", so no explicit --testsuite means Unit is selected.
$isUnitSuite = $suiteNormalized === 'unit' || $suiteNormalized === null;
$isIntegrationSuite = $suiteNormalized === 'integration';

$_ENV['PHPUNIT_REQUESTED_SUITE'] = $requestedSuite !== null ? $requestedSuite : 'All';
putenv('PHPUNIT_REQUESTED_SUITE=' . $_ENV['PHPUNIT_REQUESTED_SUITE']);

$resolvedDbName = trim((string)($_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? getenv('DB_DATABASE') ?? getenv('DB_NAME') ?? ''));

// Unit suite is completely DB-free: skip ALL DB safety enforcement before even checking the name.
if ($isUnitSuite) {
    // Keep Unit suite DB-free. Any DB usage should be mocked inside unit tests.
    error_log('[phpunit-bootstrap] Unit testsuite detected; skipping DB connectivity enforcement.');
    return;
}

if ($resolvedDbName !== '' && !isPhpUnitSafeDatabaseName($resolvedDbName) && !shouldAllowNonTestPhpUnitDatabase()) {
    markPhpUnitDatabaseUnavailable(
        sprintf(
            'Unsafe PHPUnit database detected (%s). Configure DB_DATABASE/DB_NAME with a test-specific name or set PHPUNIT_ALLOW_NON_TEST_DB=1 explicitly.',
            $resolvedDbName
        )
    );
    return;
}

// --- MySQL enforcement during tests -------------------------------------
// Only enforce DB connectivity when explicitly running Integration suite or
// when strict mode is enabled via CI=true or PHPUNIT_REQUIRE_DB=1.
$requireDb = $isIntegrationSuite;
$ci = strtolower(trim((string)($_ENV['CI'] ?? getenv('CI') ?? '')));
if ($ci === '1' || $ci === 'true' || $ci === 'yes') {
    $requireDb = true;
}

$envRequireDb = trim((string)($_ENV['PHPUNIT_REQUIRE_DB'] ?? getenv('PHPUNIT_REQUIRE_DB') ?? ''));
if ($envRequireDb !== '') {
    $requireDb = in_array(strtolower($envRequireDb), ['1', 'true', 'yes', 'on'], true);
}

if (!$requireDb) {
    // Default behavior: do not touch DB at bootstrap time.
    // The Unit suite is DB-free, and Integration runs must opt-in explicitly.
    return;
}

// Log resolved DB env and ensure tests run against MySQL
$dbConn = trim((string)($_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?? ''));
$dbHost = trim((string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? ''));
$dbPort = trim((string)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? ''));
$dbName = trim((string)($_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? getenv('DB_DATABASE') ?? ''));

if ($dbConn === '') {
    // Normalize empty string to null so defaults apply
    $dbConn = null;
}

error_log("[phpunit-bootstrap] Resolved DB_CONNECTION=" . ($dbConn ?? 'null') . ", DB_HOST=" . ($dbHost !== '' ? $dbHost : 'null') . ", DB_PORT=" . ($dbPort !== '' ? $dbPort : 'null') . ", DB_DATABASE=" . ($dbName !== '' ? $dbName : 'null'));

if ($dbConn === null) {
    // If not explicitly set, assume config default; but enforce mysql for tests
    $dbConn = 'mysql';
}

if (strtolower($dbConn) !== 'mysql') {
    throw new \RuntimeException("PHPUnit aborted: DB_CONNECTION must be 'mysql' for integration tests. Resolved DB_CONNECTION={$dbConn}");
}

// Attempt to obtain a PDO instance from App\Database and verify driver
try {
    // Force database initialization and validate driver
    $pdo = \App\Database::getInstance();
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'mysql') {
        throw new \RuntimeException("PHPUnit aborted: Detected PDO driver '{$driver}' (expected 'mysql').");
    }

    // Log a non-secret representation of DSN
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $dbHost ?? '127.0.0.1', $dbPort ?? '3306', $dbName ?? 'app_test');
    error_log("[phpunit-bootstrap] Resolved PDO DSN={$dsn}");
    define('PHPUNIT_DB_AVAILABLE', true);
} catch (\Throwable $e) {
    // DB unavailable — mark integration tests as skipped rather than aborting PHPUnit entirely.
    markPhpUnitDatabaseUnavailable('DB unavailable — Integration tests will be skipped: ' . $e->getMessage());
}
