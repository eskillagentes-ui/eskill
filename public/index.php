<?php

/**
 * Arquivo principal de entrada da aplicação
 */

use App\Core\Container;
use App\Router;

// Definir status 200 como padrão
http_response_code(200);

// Constantes de diretório
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Carregar autoload do Composer
require_once ROOT_PATH . '/vendor/autoload.php';

// Carregar autoload customizado (para Services e classes específicas)
require_once ROOT_PATH . '/autoload.php';

// Carregar helpers globais
require_once APP_PATH . '/Helpers/LogHelper.php';
require_once APP_PATH . '/Helpers/CacheHelper.php';
require_once APP_PATH . '/Helpers/ViewHelper.php';

// Carregar variáveis de ambiente
// Priorizar .env.test se APP_ENV=testing estiver definido no ambiente
$envFile = '.env';
if (getenv('APP_ENV') === 'testing' && file_exists(ROOT_PATH . '/.env.test')) {
    $envFile = '.env.test';
}

if (file_exists(ROOT_PATH . '/' . $envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH, $envFile);
    $dotenv->load();
} else {
    // Fallback to .env if .env.test doesn't exist
    if (file_exists(ROOT_PATH . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
        $dotenv->load();
    } else {
        // Fallback ou erro
        if (!file_exists(ROOT_PATH . '/.env.example')) {
            die("Arquivo .env não encontrado.");
        }
        // Copiar example se for dev? Não, melhor pedir config.
    }
}

// Registrar handler global de exceções
\App\Core\ExceptionHandler::register();

// Startup validations (fail fast on fatal issues)
try {
    require_once APP_PATH . '/Services/StartupValidator.php';
    \App\Services\StartupValidator::validate();
} catch (Throwable $e) {
    // Try to write to structured log if available, otherwise plain error
    try {
        $logger = new \App\Services\StructuredLogService();
        $logger->critical('Startup validation failed', ['error' => $e->getMessage()]);
    } catch (Throwable $inner) {
        error_log('Startup validation failed: ' . $e->getMessage());
    }

    http_response_code(500);
    $isProductionEnv = ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development') === 'production';
    echo $isProductionEnv
        ? "Service temporarily unavailable. Please try again later."
        : "Startup validation failed: " . htmlentities($e->getMessage());
    exit(1);
}

// Configuração de erro baseada no ambiente
$isProduction = ($_ENV['APP_ENV'] ?? 'development') === 'production';

if ($isProduction) {
    // Produção: NUNCA exibir erros ao usuário, independente de APP_DEBUG
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('log_errors', 1);
    ini_set('error_log', STORAGE_PATH . '/logs/error.log');
} else {
    // Desenvolvimento: exibir erros para debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', STORAGE_PATH . '/logs/php_errors.log');
}

// Manutenção
if (file_exists(STORAGE_PATH . '/maintenance.lock')) {
    require APP_PATH . '/Views/maintenance.php';
    exit;
}

// Iniciar sessão com configurações seguras
if (session_status() === PHP_SESSION_NONE) {
    $isHttpsRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // Secure flag based ONLY on actual request protocol.
    // FORCE_HTTPS controls redirects (below), NOT the cookie flag.
    // Setting Secure on HTTP causes browsers to silently drop the session cookie,
    // breaking CSRF validation and all session-dependent features.
    $secureCookie = $isHttpsRequest;

    if ($isProduction && !$secureCookie) {
        error_log('[SECURITY WARNING] Production running without HTTPS. Session cookies are NOT Secure. Configure HTTPS or a reverse proxy with X-Forwarded-Proto.');
    }

    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.gc_maxlifetime', 7200); // 2 horas
    ini_set('session.cookie_lifetime', 0);   // Expira ao fechar browser

    // Use Redis for sessions when available (shared across containers, survives restarts)
    $redisHost = $_ENV['REDIS_HOST'] ?? '';
    $redisPort = $_ENV['REDIS_PORT'] ?? '6379';
    // Tratar REDIS_PASSWORD=null (string "null") como vazio — valor placeholders comuns de env
    $redisPassword = $_ENV['REDIS_PASSWORD'] ?? '';
    if ($redisPassword === 'null' || $redisPassword === 'false' || $redisPassword === '""' || $redisPassword === "''") {
        $redisPassword = '';
    }
    $redisConfigured = $redisHost !== '' && extension_loaded('redis');
    $redisAvailable = false;
    if ($redisConfigured) {
        // Testar conexão antes de configurar o handler para evitar sessões quebradas se Redis cair
        $redisTestSock = @fsockopen($redisHost, (int)$redisPort, $errno, $errstr, 0.5);
        if ($redisTestSock) {
            fclose($redisTestSock);
            $redisAvailable = true;
        }
    }
    if ($redisAvailable) {
        ini_set('session.save_handler', 'redis');
        $redisPath = 'tcp://' . $redisHost . ':' . $redisPort;
        if ($redisPassword !== '') {
            $redisPath .= '?auth=' . $redisPassword;
        }
        ini_set('session.save_path', $redisPath);
    } else {
        // Ensure the session save path is writable (fallback for read-only envs like CI/sandbox)
        $rawSavePath = session_save_path() ?: ini_get('session.save_path') ?: '';
        // The save_path can be 'N;/path' — strip the numeric prefix if present
        $activeSavePath = preg_replace('/^\d+;/', '', $rawSavePath);
        if ($activeSavePath === '' || !is_writable($activeSavePath)) {
            $candidates = [];

            // Prefer project-controlled writable dirs before system temp.
            $candidates[] = STORAGE_PATH . '/sessions';
            $candidates[] = ROOT_PATH . '/.tmp/sessions';
            $candidates[] = sys_get_temp_dir() . '/eskill_sessions';

            foreach ($candidates as $candidate) {
                if (!is_string($candidate) || $candidate === '') {
                    continue;
                }

                if (!is_dir($candidate)) {
                    @mkdir($candidate, 0700, true);
                }

                if (is_dir($candidate) && is_writable($candidate)) {
                    session_save_path($candidate);
                    break;
                }
            }
        }
    }

    session_start();
}

// Garantir que token CSRF existe na sessão e não está expirado (1 hora)
if (
    empty($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) ||
    (time() - $_SESSION['csrf_token_time']) > 3600
) {
    $_SESSION['csrf_token'] = bin2hex(\random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}

// Generate CSP nonce for inline scripts (used by SecurityMiddleware and views).
// Use standard base64 (RFC 4648) for broad CSP parser compatibility across browsers.
// A PHP constant is used so the nonce is accessible from any scope (class methods,
// included files, etc.) without $GLOBALS or session lookups.
$cspNonce = base64_encode(\random_bytes(16));
define('CSP_NONCE', $cspNonce);
$_SESSION['csp_nonce'] = $cspNonce;
$GLOBALS['cspNonce'] = $cspNonce;

// Container de injeção de dependência
$container = new Container();

// Registrar serviços básicos (Singletons)
$container->singleton(App\Database::class, function () {
    return App\Database::getInstance();
});

// Registrar todos os serviços da aplicação (singletons e factories)
$registerServices = require CONFIG_PATH . '/container.php';
$registerServices($container);

// Middleware de segurança
$security = new App\Middleware\SecurityMiddleware();
if (!$security->handle()) {
    exit;
}

// Forçar HTTPS em produção (redundante com SecurityMiddleware, mas mantém como fallback)
// Skip para localhost/dev server para permitir testes locais sem SSL
$forceHttps = filter_var($_ENV['FORCE_HTTPS'] ?? getenv('FORCE_HTTPS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
if ($forceHttps && empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostWithoutPort = explode(':', $host)[0];
    $isLocalhost = in_array($hostWithoutPort, ['localhost', '127.0.0.1', '::1'], true);
    if (!$isLocalhost) {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        header("Location: https://{$host}{$uri}", true, 301);
        exit;
    }
}

// Setup Router
$router = new Router($container);

// Obter caminho da requisição
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUrl = parse_url($requestUri);
$path = $parsedUrl['path'] ?? '/';

// Detectar base path
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$scriptDir = dirname($scriptName);

if ($scriptDir !== '/' && strpos($path, $scriptDir) === 0) {
    $path = substr($path, strlen($scriptDir));
}
$path = '/' . ltrim($path, '/');

// Validações e Middlewares globais
$isApi = strpos($path, '/api/') === 0;
$isWebhook = strpos($path, '/webhook/') === 0;
$hasSessionAuth = !empty($_SESSION['account_id']) || !empty($_SESSION['user_id']);

// CORS for external API integrations (OpenClaw, etc.)
$isOpenClawApi = strpos($path, '/api/openclaw/') === 0 || $path === '/api/openclaw';
if ($isOpenClawApi) {
    $requestOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $configuredOrigins = trim((string)($_ENV['OPENCLAW_CORS_ORIGIN'] ?? ''));
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $configuredOrigins))));
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $sameOrigin = $host !== '' ? ($scheme . '://' . $host) : '';
    $isAllowedOrigin = $requestOrigin !== ''
        && ($requestOrigin === $sameOrigin || in_array($requestOrigin, $allowedOrigins, true));

    if ($requestOrigin !== '' && !$isAllowedOrigin) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Forbidden',
            'message' => 'Origin not allowed for this resource.'
        ]);
        exit;
    }

    if ($isAllowedOrigin) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-ML-Account-Id');
    header('Access-Control-Max-Age: 86400');
    header('Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining');

    // Preflight request — respond immediately
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Rate Limiting (webhooks com limite mais alto)
if ($isWebhook) {
    // Webhooks get a higher limit but are still rate-limited to prevent abuse
    $rateLimit = new App\Middleware\RateLimitMiddleware(300, 60);
    $rateLimit->handle();
} else {
    // Session-authenticated API requests are typically dashboard traffic and can
    // legitimately burst during initial page loads, widget refreshes and polling.
    $isDashboardApi = $hasSessionAuth
        || strpos($path, '/api/items') === 0
        || strpos($path, '/api/dashboard') === 0
        || strpos($path, '/api/orders') === 0
        || strpos($path, '/api/multi-account') === 0
        || strpos($path, '/api/ai/') === 0
        || strpos($path, '/api/seo-killer/') === 0;
    if ($isApi) {
        $limit = $isDashboardApi ? 120 : 60; // Dashboard APIs get higher limit
    } else {
        $limit = 100; // View limits
    }

    // Dashboard APIs can legitimately burst (initial page load triggers many parallel calls)
    $burstLimit = $isDashboardApi ? 50 : null;
    $rateLimit = new App\Middleware\RateLimitMiddleware($limit, 60, $burstLimit);
    $rateLimit->handle();
}

// CSRF (métodos stateful: POST/PUT/DELETE/PATCH, exceto webhooks e APIs com Bearer token)
// APIs stateless autenticadas via Bearer token não são vulneráveis a CSRF (não usam cookies)
$isWebhookRoute = strpos($path, '/webhook/') === 0 || strpos($path, '/api/webhook') === 0;
$hasBearerToken = false;
if ($isApi) {
    $authHeaders = getallheaders();
    $authHeader = $authHeaders['Authorization'] ?? $authHeaders['authorization'] ?? null;
    $hasBearerToken = $authHeader && preg_match('/Bearer\s+.+/i', $authHeader);
}
// CSRF exempt se: (a) webhook, (b) API com Bearer token stateless,
// ou (c) API que lida com própria autenticação internamente (render harness).
// API routes com autenticação via session cookie continuam sujeitas a CSRF.
$isCsrfExempt = $isWebhookRoute || ($isApi && $hasBearerToken)
    || strpos($path, '/api/render') === 0;

if (!$isCsrfExempt && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    $csrf = new App\Middleware\CsrfMiddleware();
    $csrf->handle();
}

// Carregar rotas
require APP_PATH . '/routes.php';

// ============================================================
// Security: API Authentication Middleware for /api/* routes
// Protects all API endpoints requiring auth (session or token)
// ============================================================
$publicApiPaths = [
    '/api/webhook',
    '/api/auth/login',
    '/api/auth/register',
    '/api/auth/forgot-password',
    '/api/auth/reset-password',
    '/api/public/',
    '/api/health',
    '/api/status',
    // Render harness endpoints handle their own authentication internally
    '/api/render',
];

// Exact-match public paths (não usar strpos para evitar match parcial)
$exactPublicPaths = [
    '/api/openclaw',
];

if ($isApi) {
    $isPublicApi = false;
    foreach ($publicApiPaths as $publicPath) {
        if (strpos($path, $publicPath) === 0) {
            $isPublicApi = true;
            break;
        }
    }
    if (!$isPublicApi && in_array($path, $exactPublicPaths, true)) {
        $isPublicApi = true;
    }

    if (!$isPublicApi) {
        // Check session auth first, then API token auth
        if (!$hasSessionAuth) {
            // Try API token authentication
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
            $hasTokenAuth = false;

            if ($authHeader && preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
                require_once APP_PATH . '/Middleware/ApiAuthMiddleware.php';
                $requiredScopes = (new \App\Security\ApiRouteScopeResolver())
                    ->resolveRequiredScopes($_SERVER['REQUEST_METHOD'], $path);
                $apiAuth = new App\Middleware\ApiAuthMiddleware();
                // Validate token before dispatching protected API route.
                $apiAuth->handle(function () use (&$hasTokenAuth): void {
                    $hasTokenAuth = true;
                }, $requiredScopes);
            }

            if (!$hasTokenAuth) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Authentication required. Provide session cookie or Bearer token.'
                ]);
                exit;
            }
        }
    }
}

// Auth Middleware para views dashboard (simplificação da lógica anterior)
// Exempt API endpoints like /dashboard/metrics que têm própria autenticação
// Also covers top-level AI pages that require authentication
$isDashboardPath = strpos($path, '/dashboard') === 0
    || $path === '/ai-center'
    || $path === '/ai-optimization';
if ($isDashboardPath) {
    require_once APP_PATH . '/Middleware/AuthMiddleware.php';
    $auth = new App\Middleware\AuthMiddleware();
    $auth->handle();
}

// Redirecionamento da raiz
if ($path === '/' || $path === '') {
    require_once APP_PATH . '/Middleware/AuthMiddleware.php';
    $auth = new App\Middleware\AuthMiddleware();
    if ($auth->check()) {
        header('Location: /dashboard');
        exit;
    } else {
        // Redirect to login page
        header('Location: /login');
        exit;
    }
}

// Dispatch com Caching Layer para rotas públicas
$cachedProcessed = false;

// Tentar servir cache via Middleware apenas para GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cacheMiddleware = new App\Middleware\CacheMiddleware();

    // Encapsular o dispatch dentro do $next para que o cache middleware
    // possa retornar cache hit SEM executar o controller/dispatch
    $next = function () use ($router, $path) {
        ob_start();
        $router->dispatch('GET', $path);
        $content = ob_get_clean();
        return $content;
    };

    $finalContent = $cacheMiddleware->handle($path, 'GET', $next);

    echo $finalContent;
} else {
    // Métodos não cacheados (POST, PUT, etc)
    $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
}
