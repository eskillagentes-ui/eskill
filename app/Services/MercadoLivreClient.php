<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

/**
 * Client for Mercado Livre API integration
 * This service handles communication with Mercado Livre's API as mentioned in the architecture
 */
class MercadoLivreClient
{
    /**
     * Endpoints públicos conhecidos por bloquear client_id via PolicyAgent.
     *
     * @var array<int, string>
     */
    private const PUBLIC_CLIENT_ID_POLICY_BLOCKED_PATTERNS = [
        '#^/sites/[^/]+$#',
        '#^/sites/[^/]+/search$#',
    ];

    /**
     * Features opcionais/autorizadas por capability do seller/app.
     *
     * @var array<int, array{pattern: string, status_codes: array<int, int>, error: string, feature: string, message: string}>
     */
    private const OPTIONAL_FEATURE_ENDPOINTS = [
        [
            'pattern' => '#^/orders/search$#',
            'status_codes' => [403],
            'error' => 'orders_access_unavailable',
            'feature' => 'orders',
            'message' => 'A conta ou aplicação não possui permissão para consultar pedidos via API.',
        ],
        [
            'pattern' => '#^/merchant_orders/search(?:\?.*)?$#',
            'status_codes' => [403, 404],
            'error' => 'merchant_orders_unavailable',
            'feature' => 'merchant_orders',
            'message' => 'A conta ou aplicação não possui acesso ao endpoint de merchant orders via API.',
        ],
        [
            'pattern' => '#^/users/[^/]+/shipping_preferences$#',
            'status_codes' => [403, 404],
            'error' => 'shipping_preferences_unavailable',
            'feature' => 'shipping_preferences',
            'message' => 'A conta não possui configuração de preferências de envio disponível via API.',
        ],
        [
            'pattern' => '#^/users/[^/]+/listings_quality$#',
            'status_codes' => [403, 404],
            'error' => 'listings_quality_unavailable',
            'feature' => 'listings_quality',
            'message' => 'A conta não possui acesso ao score oficial de qualidade do Mercado Livre.',
        ],
        [
            'pattern' => '#^/users/[^/]+/brands_official_store$#',
            'status_codes' => [403, 404],
            'error' => 'brand_central_unavailable',
            'feature' => 'brand_central',
            'message' => 'A conta não possui loja oficial ou acesso ao Brand Central.',
        ],
        [
            'pattern' => '#^/brands_official_store(?:/|$)#',
            'status_codes' => [403, 404],
            'error' => 'brand_central_unavailable',
            'feature' => 'brand_central',
            'message' => 'A conta não possui loja oficial ou acesso ao Brand Central.',
        ],
    ];

    protected string $accessToken = '';
    protected string $refreshToken = '';
    protected ?string $tokenExpiresAt = null;
    protected string $baseUrl = 'https://api.mercadolibre.com';
    protected \GuzzleHttp\Client $httpClient;
    protected ?int $accountId = null;
    protected ?string $sellerId = null;
    protected bool $hasAccessToken = false;
    protected ?\GuzzleHttp\Client $publicHttpClient = null;
    protected static bool $missingTokenLogged = false;
    protected ?MercadoLivreAuthService $authService = null;
    private ?CacheService $cacheService = null;
    private ?CircuitBreakerService $circuitBreaker = null;
    private static bool $circuitBreakerEnabled = true;
    private bool $dbUnavailable = false;
    private string $tokenSource = 'none';

    private bool $accountDisconnected = false;
    private ?string $accountNickname = null;
    private ?string $accountStatus = null;
    private ?string $lastRefreshError = null;
    /**
     * Endpoints aprendidos dinamicamente como bloqueados para client_id em requests públicos.
     *
     * @var array<string, bool>
     */
    private static array $publicClientIdPolicyBlockedEndpoints = [];

    private function isHttpContext(): bool
    {
        return isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== '';
    }

    private function resolveDefaultActiveAccount(): ?int
    {
        try {
            $pdo = \App\Database::getInstance();
            $stmt = $pdo->query(
                "SELECT id FROM ml_accounts WHERE status = 'active' ORDER BY updated_at DESC LIMIT 1"
            );
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Factory SEC-001: só cria client a partir de contexto já autorizado.
     */
    public static function fromAuthorizedContext(
        \App\Security\AuthorizedAccountContext $context,
        ?MercadoLivreAuthService $authService = null
    ): self {
        return new self($context->accountId(), $authService);
    }

    public function __construct(?int $accountId = null, ?MercadoLivreAuthService $authService = null)
    {
        $this->accountId = $accountId;
        $this->authService = $authService;

        // SEC-001: NÃO resolver conta por header/GET/POST nem por "última conta ativa" do banco.
        // Controllers/workers devem autorizar via AccountAccessPolicy e passar accountId explícito.
        // Sessão: apenas hint best-effort quando o client é criado sem ID (legado web);
        // ownership deve ter sido validado pelo AccountContextResolver antes.
        if ($this->accountId === null) {
            try {
                if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['active_ml_account_id'])) {
                    $candidate = (int)$_SESSION['active_ml_account_id'];
                    if ($candidate > 0) {
                        $this->accountId = $candidate;
                    }
                }
            } catch (\Throwable $e) {
                // best-effort
            }
        }

        // Tokens
        // - Quando accountId está definido (multi-account), preferimos sempre os tokens vinculados no banco.
        // - ML_ACCESS_TOKEN (ambiente) fica como fallback para modo simples/single-account ou quando a conta não existe.
        $envAccessToken = (string)($_ENV['ML_ACCESS_TOKEN'] ?? getenv('ML_ACCESS_TOKEN') ?? '');

        // Token via header (opt-in): útil para integração server-to-server sem gravar token em DB/.env.
        // Para reduzir risco, exige ML_ALLOW_TOKEN_HEADER=true.
        $headerAccessToken = '';
        $allowTokenHeaderRaw = $_ENV['ML_ALLOW_TOKEN_HEADER'] ?? getenv('ML_ALLOW_TOKEN_HEADER') ?? null;
        $allowTokenHeader = filter_var($allowTokenHeaderRaw, FILTER_VALIDATE_BOOLEAN);
        if ($this->accountId === null && $envAccessToken === '' && $allowTokenHeader && $this->isHttpContext()) {
            $headerAccessToken = (string)($_SERVER['HTTP_X_ML_ACCESS_TOKEN'] ?? '');
            if (stripos($headerAccessToken, 'Bearer ') === 0) {
                $headerAccessToken = trim(substr($headerAccessToken, strlen('Bearer ')));
            }
        }

        $accountLoaded = false;
        $loadAccountError = null;
        if ($this->accountId !== null) {
            try {
                $accountLoaded = $this->loadAccount();
                if ($accountLoaded) {
                    $this->tokenSource = 'db';
                }
            } catch (\Throwable $e) {
                // best-effort; se falhar, podemos fazer fallback para token do ambiente SOMENTE
                // quando o erro for de infraestrutura (DB indisponível).
                $loadAccountError = $e;
            }
        }

        // Fallback: token do ambiente (somente quando NÃO há accountId explícito)
        // Se accountId foi informado mas loadAccount() falhou (tokens vazios/expirados),
        // NÃO devemos usar o token do ambiente — isso mascararia a conta desconectada.
        if (!$accountLoaded && $this->accountId === null) {
            // Prioridade: header token (opt-in) > env token
            $this->accessToken = $headerAccessToken !== '' ? $headerAccessToken : $envAccessToken;
            $this->hasAccessToken = $this->accessToken !== '';
            if ($this->hasAccessToken) {
                $this->tokenSource = $headerAccessToken !== '' ? 'header' : 'env';
            } else {
                $this->tokenSource = 'none';
            }
        }

        // Fallback controlado: quando accountId foi informado, mas o DB está indisponível,
        // permitimos usar ML_ACCESS_TOKEN para não derrubar toda a integração.
        if (!$accountLoaded && $this->accountId !== null && $envAccessToken !== '' && $this->isDbUnavailableError($loadAccountError)) {
            $this->dbUnavailable = true;
            $this->accessToken = $envAccessToken;
            $this->hasAccessToken = true;
            $this->tokenSource = 'env_fallback_db_error';

            // Evitar tentativas de refresh via DB durante requisições (ensureValidAccessToken)
            $this->accountId = null;
            $this->refreshHttpClient();

            log_warning('DB indisponível ao carregar conta ML — usando ML_ACCESS_TOKEN como fallback (modo degradado)', [
                'hint' => 'Suba/configure o MySQL para reativar multi-conta e refresh automático',
            ]);
        }

        // 3) Aviso único quando não há token disponível de nenhuma fonte
        if (!$this->hasAccessToken && !self::$missingTokenLogged) {
            $context = [
                'account_id' => $this->accountId,
                'hint' => 'ML_ACCESS_TOKEN no ambiente, X-ML-Access-Token (se ML_ALLOW_TOKEN_HEADER=true), ou conta vinculada em ml_accounts',
            ];

            // Sem accountId explícito em contexto não-HTTP costuma ser cenário esperado
            // para checks genéricos de health/workers. Evita flood de warning operacional.
            if ($this->accountId === null && !$this->isHttpContext()) {
                log_debug('MercadoLivreClient sem token e sem conta ativa (contexto não autenticado)', $context);
            } else {
                log_warning('Sem token configurado para ML API', $context);
            }

            self::$missingTokenLogged = true;
        }

        $this->httpClient = $this->createHttpClient();
    }

    private function createHttpClient(bool $includeAuth = true): \GuzzleHttp\Client
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'SEO-Optimizer/1.0'
        ];

        if ($includeAuth && $this->hasAccessToken) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        $options = [
            'timeout' => 30,
            'headers' => $headers,
        ];

        $options['proxy'] = $this->buildProxyOption() ?? false;

        return new \GuzzleHttp\Client($options);
    }

    private function getPublicHttpClient(): \GuzzleHttp\Client
    {
        if ($this->publicHttpClient) {
            return $this->publicHttpClient;
        }

        $this->publicHttpClient = $this->createHttpClient(false);
        return $this->publicHttpClient;
    }

    /**
     * Monta a opção de proxy do Guzzle a partir de variáveis de ambiente.
     *
     * Suporta:
     * - ML_PROXY_ENABLED=true
     * - ML_PROXY_TYPE=http|socks5
     * - ML_PROXY_HOST, ML_PROXY_PORT
     * - ML_PROXY_USER, ML_PROXY_PASS (opcionais)
     */
    /**
     * Cloudflare Worker proxy interception.
     *
     * Quando ML_CF_PROXY_ENABLED=true, substitui a URL destino pela URL do Worker
     * e adiciona os headers X-Proxy-Secret e X-ML-Path para que o Worker possa
     * repassar a requisição para api.mercadolibre.com com IP limpo.
     *
     * O Worker espera:
     *   POST/GET <CF_WORKER_URL>
     *   X-Proxy-Secret: <secret>   → security shared secret
     *   X-ML-Path: /endpoint?qs    → path+query enviado ao ML
     *   Authorization: Bearer ..   → token OAuth repassado (opcional)
     *
     * @param  string              $url     URL original (api.mercadolibre.com/...)
     * @param  array<string,mixed> $options Opções Guzzle
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function applyCfProxy(string $url, array $options): array
    {
        $enabled = filter_var(
            $_ENV['ML_CF_PROXY_ENABLED'] ?? getenv('ML_CF_PROXY_ENABLED') ?? 'false',
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$enabled) {
            return [$url, $options];
        }

        $proxyUrl    = rtrim((string) ($_ENV['ML_CF_PROXY_URL']    ?? getenv('ML_CF_PROXY_URL')    ?? ''), '/');
        $proxySecret = (string) ($_ENV['ML_CF_PROXY_SECRET'] ?? getenv('ML_CF_PROXY_SECRET') ?? '');

        if ($proxyUrl === '' || $proxySecret === '') {
            log_warning('ML CF Proxy ativo mas ML_CF_PROXY_URL ou ML_CF_PROXY_SECRET não configurados', []);
            return [$url, $options];
        }

        // Extract path+query from the original ML URL to send as X-ML-Path
        $parsed  = parse_url($url);
        $mlPath  = ($parsed['path'] ?? '/');
        if (!empty($parsed['query'])) {
            $mlPath .= '?' . $parsed['query'];
        }
        // Also carry query params from Guzzle $options['query'] if present
        if (!empty($options['query']) && str_contains($mlPath, '?') === false) {
            $mlPath .= '?' . http_build_query($options['query']);
        } elseif (!empty($options['query'])) {
            $mlPath .= '&' . http_build_query($options['query']);
        }

        $options['query'] = []; // already embedded in mlPath

        // Inject CF proxy headers into Guzzle options
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'X-Proxy-Secret' => $proxySecret,
            'X-ML-Path'      => $mlPath,
        ]);

        return [$proxyUrl, $options];
    }

        private function buildProxyOption(): ?string
    {
        $enabledRaw = $_ENV['ML_PROXY_ENABLED'] ?? getenv('ML_PROXY_ENABLED') ?? null;
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return null;
        }

        $type = (string)($_ENV['ML_PROXY_TYPE'] ?? getenv('ML_PROXY_TYPE') ?? 'http');
        $host = (string)($_ENV['ML_PROXY_HOST'] ?? getenv('ML_PROXY_HOST') ?? '');
        $port = (string)($_ENV['ML_PROXY_PORT'] ?? getenv('ML_PROXY_PORT') ?? '');
        $user = (string)($_ENV['ML_PROXY_USER'] ?? getenv('ML_PROXY_USER') ?? '');
        $pass = (string)($_ENV['ML_PROXY_PASS'] ?? getenv('ML_PROXY_PASS') ?? '');

        if ($host === '' || $port === '') {
            return null;
        }

        $scheme = strtolower(trim($type));
        if (!in_array($scheme, ['http', 'https', 'socks5', 'socks5h'], true)) {
            $scheme = 'http';
        }

        $auth = '';
        if ($user !== '') {
            $auth = rawurlencode($user);
            if ($pass !== '') {
                $auth .= ':' . rawurlencode($pass);
            }
            $auth .= '@';
        }

        return $scheme . '://' . $auth . $host . ':' . $port;
    }

    private function refreshHttpClient(): void
    {
        $this->httpClient = $this->createHttpClient(true);
        $this->publicHttpClient = null;
    }

    /**
     * Carrega dados da conta e descriptografa tokens quando necessário
     */
    public function loadAccount(): bool
    {
        if ($this->accountId === null) {
            return false;
        }

        $db = Database::getInstance();
        try {
            $stmt = $db->prepare(
                'SELECT access_token, refresh_token, token_expires_at, tokens_encrypted, status, last_refresh_error, nickname
                 FROM ml_accounts WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $this->accountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Compatibilidade com esquemas antigos sem last_refresh_error.
            $errorInfoCode = (int)($e->errorInfo[1] ?? 0);
            $isUnknownColumn = $errorInfoCode === 1054 || stripos($e->getMessage(), 'Unknown column') !== false;
            if (!$isUnknownColumn) {
                throw $e;
            }

            $stmt = $db->prepare(
                "SELECT access_token, refresh_token, token_expires_at, tokens_encrypted, status, NULL AS last_refresh_error, nickname
                 FROM ml_accounts WHERE id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $this->accountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$row) {
            return false;
        }

        $this->accountNickname = isset($row['nickname']) ? (string)$row['nickname'] : null;
        $this->accountStatus = isset($row['status']) ? (string)$row['status'] : null;
        $this->lastRefreshError = isset($row['last_refresh_error']) ? (string)$row['last_refresh_error'] : null;

        $this->accountDisconnected = $this->isAccountDisconnectedState($this->accountStatus, $this->lastRefreshError);
        if ($this->accountDisconnected) {
            $this->accessToken = '';
            $this->refreshToken = '';
            $this->tokenExpiresAt = $row['token_expires_at'] ?? null;
            $this->hasAccessToken = false;
            $this->tokenSource = 'db_disconnected';
            $this->refreshHttpClient();
            return false;
        }

        $accessToken = $row['access_token'] ?? '';
        $refreshToken = $row['refresh_token'] ?? '';

        if (!empty($row['tokens_encrypted'])) {
            try {
                $enc = new EncryptionService();
                $accessToken = $accessToken !== '' ? $enc->decrypt($accessToken) : '';
                $refreshToken = $refreshToken !== '' ? $enc->decrypt($refreshToken) : '';
            } catch (\Throwable $e) {
                log_error('Falha ao descriptografar tokens ML', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);

                // Tokens corrompidos/indecifráveis: tratar como conta desconectada para evitar loops de 401.
                $this->accountDisconnected = true;
                $this->accountStatus = $this->accountStatus ?? 'unknown';
                $this->lastRefreshError = $this->lastRefreshError ?: 'decrypt_failed';
                $this->tokenSource = 'db_decrypt_failed';
                $this->accessToken = '';
                $this->refreshToken = '';
                $this->tokenExpiresAt = $row['token_expires_at'] ?? null;
                $this->hasAccessToken = false;
                $this->refreshHttpClient();

                return false;
            }
        }

        $this->accessToken = (string) $accessToken;
        $this->refreshToken = (string) $refreshToken;
        $this->tokenExpiresAt = $row['token_expires_at'] ?? null;
        $this->hasAccessToken = $this->accessToken !== '';
        if ($this->hasAccessToken) {
            $this->tokenSource = 'db';
        }
        $this->refreshHttpClient();

        return $this->hasAccessToken;
    }

    private function isAccountDisconnectedState(?string $status, ?string $lastRefreshError): bool
    {
        $s = $status !== null ? trim($status) : '';
        if ($s === 'disconnected') {
            return true;
        }

        $err = $lastRefreshError !== null ? trim($lastRefreshError) : '';
        if ($err === '') {
            return false;
        }

        return stripos($err, 'invalid_grant') !== false;
    }

    private function accountDisconnectedError(string $endpoint): array
    {
        $nickname = $this->accountNickname;
        $accountId = $this->accountId;

        $label = $nickname !== null && $nickname !== ''
            ? $nickname
            : ($accountId !== null ? (string)$accountId : '');

        return [
            'error' => 'account_disconnected',
            'message' => $label !== ''
                ? "A conta {$label} precisa ser reconectada ao Mercado Livre."
                : 'Esta conta precisa ser reconectada ao Mercado Livre.',
            'endpoint' => $endpoint,
            'status' => 401,
            'account_id' => $accountId,
            'nickname' => $nickname,
            'reconnect_url' => $accountId !== null ? '/auth/authorize?reconnect=' . $accountId : null,
            'token_source' => $this->tokenSource,
            'last_refresh_error' => $this->lastRefreshError,
        ];
    }

    /**
     * Garante que o access token está válido (renova se necessário)
     */
    public function ensureValidAccessToken(int $bufferMinutes = 60): bool
    {
        if ($this->accountId === null) {
            return false;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT token_expires_at, status, last_refresh_error FROM ml_accounts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $this->accountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $status = isset($row['status']) ? (string)$row['status'] : null;
        $lastError = isset($row['last_refresh_error']) ? (string)$row['last_refresh_error'] : null;
        $this->accountStatus = $status;
        $this->lastRefreshError = $lastError;
        $this->accountDisconnected = $this->isAccountDisconnectedState($status, $lastError);
        if ($this->accountDisconnected) {
            return false;
        }

        $expiresAt = $row['token_expires_at'] ?? null;
        if (!$expiresAt) {
            return $this->loadAccount();
        }

        $secondsLeft = strtotime($expiresAt) - time();
        if ($secondsLeft > ($bufferMinutes * 60)) {
            return $this->loadAccount();
        }

        $authService = $this->authService ?? new MercadoLivreAuthService();
        $refreshed = $authService->refreshToken($this->accountId);
        if (!$refreshed) {
            // O refresh pode ter marcado a conta como disconnected.
            try {
                $this->loadAccount();
            } catch (\Throwable $t) {
                // best-effort
            }
            return false;
        }

        return $this->loadAccount();
    }

    private function isDbUnavailableError(?\Throwable $e): bool
    {
        if ($e === null) {
            return false;
        }

        $msg = $e->getMessage();
        if (!is_string($msg) || $msg === '') {
            return false;
        }

        // Database.php encapsula PDOException e lança \Exception com esta mensagem.
        if (stripos($msg, 'Database connection failed') !== false) {
            return true;
        }

        // Outros padrões comuns de indisponibilidade
        if (stripos($msg, 'SQLSTATE[HY000]') !== false) {
            return true;
        }
        if (stripos($msg, 'Connection refused') !== false) {
            return true;
        }
        if (stripos($msg, 'No such file or directory') !== false) {
            return true;
        }

        return false;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    protected function isConfigured(): bool
    {
        return $this->hasAccessToken;
    }

    protected function missingTokenError(string $endpoint): array
    {
        $allowTokenHeaderRaw = $_ENV['ML_ALLOW_TOKEN_HEADER'] ?? getenv('ML_ALLOW_TOKEN_HEADER') ?? null;
        $allowTokenHeader = filter_var($allowTokenHeaderRaw, FILTER_VALIDATE_BOOLEAN);

        return [
            'error' => 'missing_access_token',
            'message' => 'Mercado Livre access token not configured.',
            'endpoint' => $endpoint,
            'status' => 401,
            'token_source' => $this->tokenSource,
            'db_unavailable' => $this->dbUnavailable,
            'hint' => $this->dbUnavailable
                ? 'DB indisponível; configure MySQL ou defina ML_ACCESS_TOKEN para modo degradado.'
                : ($allowTokenHeader
                    ? 'Configure ML_ACCESS_TOKEN, envie X-ML-Access-Token, ou conecte uma conta em ml_accounts via OAuth.'
                    : 'Configure ML_ACCESS_TOKEN ou conecte uma conta em ml_accounts via OAuth.'),
        ];
    }

    private function getCacheService(): CacheService
    {
        if ($this->cacheService === null) {
            $this->cacheService = new CacheService();
        }

        return $this->cacheService;
    }

    /**
     * Obtém instância do Circuit Breaker (lazy load)
     * Protege o sistema quando a API do ML está instável
     */
    private function getCircuitBreaker(): ?CircuitBreakerService
    {
        if (!self::$circuitBreakerEnabled) {
            return null;
        }

        if ($this->circuitBreaker === null) {
            try {
                $this->circuitBreaker = new CircuitBreakerService('mercadolivre_api');
                $this->circuitBreaker->configure([
                    'failure_threshold' => 5,    // 5 falhas para abrir
                    'success_threshold' => 3,    // 3 sucessos para fechar
                    'open_timeout' => 60,        // 60s antes de testar novamente
                    'half_open_max_requests' => 3,
                ]);
            } catch (\Exception $e) {
                // Se falhar ao criar circuit breaker, continua sem ele
                log_warning('Falha ao inicializar CircuitBreaker', [
                    'error' => $e->getMessage(),
                ]);
                self::$circuitBreakerEnabled = false;
                return null;
            }
        }

        return $this->circuitBreaker;
    }

    /**
     * Desabilita circuit breaker (útil para testes)
     */
    public static function disableCircuitBreaker(): void
    {
        self::$circuitBreakerEnabled = false;
    }

    /**
     * Habilita circuit breaker
     */
    public static function enableCircuitBreaker(): void
    {
        self::$circuitBreakerEnabled = true;
    }

    /**
     * Obtém estatísticas do Circuit Breaker
     */
    public function getCircuitBreakerStats(): ?array
    {
        $cb = $this->getCircuitBreaker();
        return $cb ? $cb->getStats() : null;
    }

    /**
     * Em ambientes de teste, bloqueia rede por padrão para manter testes determinísticos.
     * Habilite explicitamente com ML_ALLOW_NETWORK=true.
     */
    private function isNetworkAllowed(): bool
    {
        $env = (string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production');
        if (strtolower($env) !== 'testing') {
            return true;
        }

        $allow = $_ENV['ML_ALLOW_NETWORK'] ?? getenv('ML_ALLOW_NETWORK') ?? null;
        return filter_var($allow, FILTER_VALIDATE_BOOLEAN);
    }

    private function networkDisabledError(string $method, string $endpoint): array
    {
        return [
            'error' => 'network_disabled',
            'message' => 'External network calls are disabled in testing by default. Set ML_ALLOW_NETWORK=true to enable.',
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => 503,
        ];
    }

    private function getPublicClientId(): string
    {
        return (string)($_ENV['ML_APP_ID'] ?? getenv('ML_APP_ID') ?? '');
    }

    private function getPublicClientIdMode(): string
    {
        $mode = strtolower(trim((string)($_ENV['ML_PUBLIC_CLIENT_ID_MODE'] ?? getenv('ML_PUBLIC_CLIENT_ID_MODE') ?? 'auto')));

        return in_array($mode, ['auto', 'never'], true) ? $mode : 'auto';
    }

    private function normalizePublicEndpointPolicyKey(string $endpoint): string
    {
        return match (true) {
            preg_match('#^/sites/[^/]+$#', $endpoint) === 1 => '/sites/{site}',
            preg_match('#^/sites/[^/]+/search$#', $endpoint) === 1 => '/sites/{site}/search',
            default => $endpoint,
        };
    }

    private function isKnownPolicyBlockedPublicEndpoint(string $endpoint): bool
    {
        $key = $this->normalizePublicEndpointPolicyKey($endpoint);
        if (isset(self::$publicClientIdPolicyBlockedEndpoints[$key])) {
            return true;
        }

        foreach (self::PUBLIC_CLIENT_ID_POLICY_BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $endpoint) === 1) {
                return true;
            }
        }

        return false;
    }

    private function markPublicEndpointAsPolicyBlocked(string $endpoint): void
    {
        self::$publicClientIdPolicyBlockedEndpoints[$this->normalizePublicEndpointPolicyKey($endpoint)] = true;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function shouldAttachClientIdToPublicRequest(string $method, string $endpoint, array $options): bool
    {
        if ($method !== 'GET') {
            return false;
        }

        if ($this->getPublicClientIdMode() === 'never') {
            return false;
        }

        if ($this->getPublicClientId() === '') {
            return false;
        }

        if ($this->isKnownPolicyBlockedPublicEndpoint($endpoint)) {
            return false;
        }

        return !(isset($options['query']) && is_array($options['query']) && array_key_exists('client_id', $options['query']));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function attachClientIdToPublicRequestOptions(array $options): array
    {
        $clientId = $this->getPublicClientId();
        if ($clientId === '') {
            return $options;
        }

        if (!isset($options['query']) || !is_array($options['query'])) {
            $options['query'] = [];
        }

        $options['query']['client_id'] = $clientId;

        return $options;
    }

    private function isPolicyBlockedHttpError(?int $status, ?string $body, string $message = ''): bool
    {
        if ($status !== 403) {
            return false;
        }

        $haystack = strtolower(trim($message));
        if ($body !== null && $body !== '') {
            $haystack .= ' ' . strtolower($body);
        }

        return str_contains($haystack, 'pa_unauthorized_result_from_policies')
            || (str_contains($haystack, 'policy') && str_contains($haystack, 'unauthorized'));
    }

    /**
     * @return array{pattern: string, status_codes: array<int, int>, error: string, feature: string, message: string}|null
     */
    private function matchOptionalFeatureEndpoint(string $endpoint, ?int $status): ?array
    {
        if ($status === null) {
            return null;
        }

        foreach (self::OPTIONAL_FEATURE_ENDPOINTS as $definition) {
            if (preg_match($definition['pattern'], $endpoint) !== 1) {
                continue;
            }

            if (!in_array($status, $definition['status_codes'], true)) {
                continue;
            }

            return $definition;
        }

        return null;
    }

    /**
     * @param array{pattern: string, status_codes: array<int, int>, error: string, feature: string, message: string} $definition
     */
    private function optionalFeatureUnavailableResponse(string $method, string $endpoint, int $status, array $definition): array
    {
        return $this->decorateLegacyResponse([
            'error' => $definition['error'],
            'message' => $definition['message'],
            'feature' => $definition['feature'],
            'optional_feature' => true,
            'status' => $status,
            'method' => $method,
            'endpoint' => $endpoint,
        ]);
    }

    private function normalizeHttpError(string $method, string $endpoint, ?int $status, ?string $body, string $fallbackMessage): array
    {
        $payload = [];

        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if (!isset($payload['error'])) {
            $payload['error'] = 'http_error';
        }

        if (!isset($payload['message'])) {
            $payload['message'] = $fallbackMessage;
        }

        $payload['status'] = $status;
        $payload['method'] = $method;
        $payload['endpoint'] = $endpoint;

        return $this->decorateLegacyResponse($payload);
    }

    /**
     * Mantém compatibilidade com consumidores legados que esperam:
     * - success (bool)
     * - body (payload original)
     *
     * Não aplica envelope em listas (ex.: multiget /items?ids=...)
     * para evitar quebrar iterações existentes.
     */
    private function decorateLegacyResponse(array $payload): array
    {
        $isList = array_keys($payload) === range(0, count($payload) - 1);

        if ($isList) {
            return $payload;
        }

        if (isset($payload['error'])) {
            $payload['success'] = false;
            return $payload;
        }

        if (!array_key_exists('success', $payload)) {
            $payload['success'] = true;
        }

        if (!array_key_exists('body', $payload)) {
            $payload['body'] = $payload;
        }

        return $payload;
    }

    private function shouldCacheResponse(array $response): bool
    {
        if (isset($response['error'])) {
            return false;
        }

        return true;
    }

    private function buildCacheKey(string $method, string $endpoint, array $params, bool $public): string
    {
        $identity = [
            'method' => $method,
            'endpoint' => $endpoint,
            'params' => $params,
            'public' => $public,
            'account_id' => $this->accountId,
        ];

        if ($this->hasAccessToken) {
            $identity['token_hash'] = substr(hash('sha256', $this->accessToken), 0, 16);
        }

        return 'ml_http:' . md5(json_encode($identity));
    }

    private function requiresAuthForEndpoint(string $method, string $endpoint, ?bool $explicitPublic): bool
    {
        if ($explicitPublic !== null) {
            return !$explicitPublic;
        }

        if ($method !== 'GET') {
            return true;
        }

        $patterns = [
            '#^/users/me#',
            '#^/users/[^/]+/items/search#',
            '#^/users/[^/]+/products#',
            '#^/users/[^/]+/listings_quality#',
            '#^/users/[^/]+/shipping_preferences#',
            '#^/users/[^/]+/fulfillment#',
            '#^/users/[^/]+/brands_official_store#',
            '#^/orders#',
            '#^/merchant_orders#',
            '#^/questions#',
            '#^/shipments#',
            '#^/payments#',
            '#^/billing#',
            '#^/messages#',
            '#^/items/[^/]+/visits#',
            '#^/items/[^/]+/health#',
            '#^/item/[^/]+/performance#',
            '#^/reputation/items/[^/]+/purchase_experience#',
            '#^/items/[^/]+/shipping#',
            '#^/items/[^/]+/fulfillment#',
            '#^/answers#',
            '#^/my/#',
            '#^/v1/claims#',
            '#^/moderations/last_moderation#',
            '#^/moderations/infractions#',
            '#^/moderations/pictures/diagnostic#',
            // Search é endpoint público - usar searchItems() para forçar public
            // '#^/sites/[^/]+/search#',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $endpoint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Executa requisição com retry opcional (ex.: token expirado).
     * Retorna sempre array decodificado.
     * Inclui proteção via Circuit Breaker para resiliência.
     */
    private function requestWithRetry(string $method, string $endpoint, array $options = [], bool $allowRetry = true, bool $requiresAuth = true): array
    {
        if (!$this->isNetworkAllowed()) {
            return $this->decorateLegacyResponse($this->networkDisabledError($method, $endpoint));
        }

        if ($requiresAuth && $this->accountId !== null && $this->accountDisconnected) {
            return $this->decorateLegacyResponse($this->accountDisconnectedError($endpoint));
        }

        if ($requiresAuth && !$this->isConfigured()) {
            return $this->decorateLegacyResponse($this->missingTokenError($endpoint));
        }

        // CIRCUIT BREAKER: Verifica se API está em estado de falha
        $circuitBreaker = $this->getCircuitBreaker();
        if ($circuitBreaker && !$circuitBreaker->canRequest()) {
            return $this->decorateLegacyResponse([
                'error' => 'circuit_breaker_open',
                'message' => 'API do Mercado Livre temporariamente indisponível. Tente novamente em alguns minutos.',
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => 503,
            ]);
        }

        // PROACTIVE TOKEN REFRESH: Verifica e renova token ANTES de fazer a requisição
        // Isso evita erros 401 e garante fluxo sem interrupções
        if ($requiresAuth && $this->accountId !== null) {
            $ok = $this->ensureValidAccessToken(120); // 2 horas de margem
            if (!$ok) {
                // Se falhou por desconexão (invalid_grant), retornar erro acionável já aqui.
                if ($this->accountDisconnected) {
                    return $this->decorateLegacyResponse($this->accountDisconnectedError($endpoint));
                }

                // Caso contrário, segue com o token atual (pode ainda estar válido) e deixa o 401 tratar.
                log_warning('Falha ao garantir token válido antes da requisição ML', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'account_id' => $this->accountId,
                ]);
            }
        }

        $publicClientIdApplied = false;
        if (!$requiresAuth && $this->shouldAttachClientIdToPublicRequest($method, $endpoint, $options)) {
            $options = $this->attachClientIdToPublicRequestOptions($options);
            $publicClientIdApplied = true;
        }

        $url = $this->baseUrl . $endpoint;

        // Cloudflare Worker proxy: reroute via proxy when ML_CF_PROXY_ENABLED=true
        [$url, $options] = $this->applyCfProxy($url, $options);

        try {
            // Para endpoints públicos, algumas políticas podem bloquear respostas sem Authorization.
            // Então usamos o client autenticado quando houver token; e só usamos client público quando não há token.
            $client = ($requiresAuth || $this->hasAccessToken) ? $this->httpClient : $this->getPublicHttpClient();
            $response = $client->request($method, $url, $options);
            $result = json_decode((string)$response->getBody(), true) ?: [];

            // CIRCUIT BREAKER: Registra sucesso
            if ($circuitBreaker) {
                $circuitBreaker->recordSuccess();
            }

            return $this->decorateLegacyResponse($result);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $body = null;
            try {
                $body = (string) $e->getResponse()?->getBody();
            } catch (\Throwable $t) {
                $body = null;
            }

            // Se o endpoint exige auth e não há token, padroniza erro.
            if ($status === 401 && $requiresAuth && !$this->hasAccessToken) {
                return $this->decorateLegacyResponse($this->missingTokenError($endpoint));
            }

            $optionalFeature = $this->matchOptionalFeatureEndpoint($endpoint, $status);
            if ($optionalFeature !== null) {
                log_warning('ML optional feature unavailable for this account/app', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'feature' => $optionalFeature['feature'],
                ]);

                return $this->optionalFeatureUnavailableResponse($method, $endpoint, $status, $optionalFeature);
            }

            if (
                $allowRetry
                && !$requiresAuth
                && $publicClientIdApplied
                && $this->isPolicyBlockedHttpError($status, $body, $e->getMessage())
            ) {
                $this->markPublicEndpointAsPolicyBlocked($endpoint);

                if (isset($options['query']) && is_array($options['query'])) {
                    unset($options['query']['client_id']);
                }

                log_warning('ML public endpoint bloqueou client_id; retry automático sem client_id', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $status,
                ]);

                return $this->requestWithRetry($method, $endpoint, $options, false, $requiresAuth);
            }

            // Token pode ter expirado / sido revogado; tenta refresh e reenvia 1x.
            if ($allowRetry && $status === 401 && $this->accountId !== null) {
                try {
                    $ok = $this->ensureValidAccessToken();
                    if ($ok) {
                        // ensureValidAccessToken() recarrega e atualiza headers
                        return $this->requestWithRetry($method, $endpoint, $options, false, $requiresAuth);
                    }

                    if ($this->accountDisconnected) {
                        return $this->decorateLegacyResponse($this->accountDisconnectedError($endpoint));
                    }
                } catch (\Throwable $t) {
                    // segue para log abaixo
                }
            }

            // RATE LIMIT (429) - Respeitar Retry-After
            if ($allowRetry && $status === 429) {
                $retryAfter = (int)($e->getResponse()?->getHeaderLine('Retry-After') ?? 0);
                if ($this->isHttpContext()) {
                    log_warning('ML API Rate Limit atingido (fail-fast em contexto HTTP)', [
                        'endpoint' => $endpoint,
                        'retry_after_seconds' => $retryAfter,
                    ]);
                } else {
                    // Se Retry-After for muito longo (> 60s), melhor falhar do que segurar o processo
                    if ($retryAfter > 0 && $retryAfter <= 60) {
                        log_warning('ML API Rate Limit atingido', [
                            'endpoint' => $endpoint,
                            'retry_after_seconds' => $retryAfter,
                        ]);
                        sleep($retryAfter + 1); // +1s de margem
                        return $this->requestWithRetry($method, $endpoint, $options, false, $requiresAuth);
                    }
                }
            }

            // CIRCUIT BREAKER: Registra falha em erros de servidor (5xx)
            if ($circuitBreaker && $status >= 500) {
                $circuitBreaker->recordFailure("HTTP {$status} on {$method} {$endpoint}");
            }

            if ($this->shouldRetryTransientHttpFailure($status, $allowRetry)) {
                $delaySeconds = $this->calculateTransientRetryDelaySeconds();
                log_warning('ML API transient HTTP error, retrying once', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'retry_delay_seconds' => $delaySeconds,
                ]);
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }
                return $this->requestWithRetry($method, $endpoint, $options, false, $requiresAuth);
            }

            // PolicyAgent 403 em endpoint público sem client_id (pré-bloqueado ou nunca enviado):
            // é comportamento esperado — downgrade de ERROR para WARNING para evitar ruído nos logs.
            if (!$requiresAuth && $this->isPolicyBlockedHttpError($status, $body, $e->getMessage())) {
                log_warning('ML API public endpoint bloqueado por policy (sem client_id)', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $status,
                ]);

                return $this->decorateLegacyResponse(
                    $this->normalizeHttpError($method, $endpoint, $status, $body, $e->getMessage())
                );
            }

            log_error('ML API HTTP Error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return $this->decorateLegacyResponse(
                $this->normalizeHttpError($method, $endpoint, $status, $body, $e->getMessage())
            );
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            // Erros 5xx - sempre contam para circuit breaker
            $status = $e->getResponse()?->getStatusCode() ?? 500;

            if ($circuitBreaker) {
                $circuitBreaker->recordFailure("HTTP {$status} on {$method} {$endpoint}");
            }

            if ($this->shouldRetryTransientHttpFailure($status, $allowRetry)) {
                $delaySeconds = $this->calculateTransientRetryDelaySeconds();
                log_warning('ML API server error, retrying once', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $status,
                    'retry_delay_seconds' => $delaySeconds,
                ]);
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }
                return $this->requestWithRetry($method, $endpoint, $options, false, $requiresAuth);
            }

            log_error('ML API Server Error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            $body = null;
            try {
                $body = (string) $e->getResponse()?->getBody();
            } catch (\Throwable $t) {
                $body = null;
            }

            return $this->decorateLegacyResponse(
                $this->normalizeHttpError($method, $endpoint, $status, $body, $e->getMessage())
            );
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            // Erros de conexão (timeout, DNS, etc) - contam para circuit breaker
            if ($circuitBreaker) {
                $circuitBreaker->recordFailure("Connection error on {$method} {$endpoint}: " . $e->getMessage());
            }

            if ($allowRetry && !$this->isHttpContext()) {
                $delaySeconds = $this->calculateTransientRetryDelaySeconds();
                log_warning('ML API connection error, retrying once', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'retry_delay_seconds' => $delaySeconds,
                    'error' => $e->getMessage(),
                ]);
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }
                return $this->requestWithRetry($method, $endpoint, $options, false, $requiresAuth);
            }

            log_error('ML API Connection Error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return $this->decorateLegacyResponse([
                'error' => 'connection_error',
                'message' => 'Não foi possível conectar à API do Mercado Livre. Verifique sua conexão.',
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => 0,
            ]);
        } catch (\Exception $e) {
            // Outros erros de rede
            if ($circuitBreaker) {
                $circuitBreaker->recordFailure("Network error on {$method} {$endpoint}: " . $e->getMessage());
            }

            if ($allowRetry && !$this->isHttpContext() && $this->isTransientNetworkException($e)) {
                $delaySeconds = $this->calculateTransientRetryDelaySeconds();
                log_warning('ML API network error considered transient, retrying once', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'retry_delay_seconds' => $delaySeconds,
                    'error' => $e->getMessage(),
                ]);
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }
                return $this->requestWithRetry($method, $endpoint, $options, false, $requiresAuth);
            }

            log_error('ML API Network Error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return $this->decorateLegacyResponse([
                'error' => 'network_error',
                'message' => $e->getMessage(),
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => 0,
            ]);
        }
    }

    private function shouldRetryTransientHttpFailure(?int $status, bool $allowRetry): bool
    {
        if (!$allowRetry || $this->isHttpContext()) {
            return false;
        }

        if ($status === null) {
            return false;
        }

        return $status >= 500 && $status < 600;
    }

    private function calculateTransientRetryDelaySeconds(): int
    {
        $base = max(1, min(30, (int)($_ENV['ML_TRANSIENT_RETRY_BASE_SECONDS'] ?? 2)));
        $jitterMax = max(0, min(5, (int)($_ENV['ML_TRANSIENT_RETRY_JITTER_SECONDS'] ?? 1)));
        $jitter = $jitterMax > 0 ? random_int(0, $jitterMax) : 0;
        return $base + $jitter;
    }

    private function isTransientNetworkException(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());
        if ($message === '') {
            return false;
        }

        $patterns = [
            'timeout',
            'timed out',
            'connection reset',
            'temporarily unavailable',
            'temporary failure',
            'dns',
            'name or service not known',
            'could not resolve host',
            'connection refused',
            'connection aborted',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generic GET request
     */
    public function get(string $endpoint, array $params = [], int|bool|null $cacheTtlOrPublic = null, ?bool $public = null): array
    {
        $cacheTtl = null;
        $explicitPublic = null;

        if (is_int($cacheTtlOrPublic)) {
            $cacheTtl = $cacheTtlOrPublic;
            if (is_bool($public)) {
                $explicitPublic = $public;
            }
        } elseif (is_bool($cacheTtlOrPublic)) {
            $explicitPublic = $cacheTtlOrPublic;
        } elseif (is_bool($public)) {
            $explicitPublic = $public;
        }

        $requiresAuth = $this->requiresAuthForEndpoint('GET', $endpoint, $explicitPublic);
        $isPublic = $explicitPublic ?? !$requiresAuth;

        $query = [];
        if (!empty($params)) {
            $query['query'] = $params;
        }

        if ($cacheTtl !== null && $cacheTtl > 0) {
            $cache = $this->getCacheService();
            $cacheKey = $this->buildCacheKey('GET', $endpoint, $params, $isPublic);

            if ($cache->has($cacheKey)) {
                $cached = $cache->get($cacheKey);
                if (is_array($cached)) {
                    return $cached;
                }
            }

            $fresh = $this->requestWithRetry('GET', $endpoint, $query, true, $requiresAuth);
            if ($this->shouldCacheResponse($fresh)) {
                $cache->set($cacheKey, $fresh, $cacheTtl);
            }
            return $fresh;
        }

        return $this->requestWithRetry('GET', $endpoint, $query, true, $requiresAuth);
    }

    /**
     * Generic POST request
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->requestWithRetry('POST', $endpoint, ['json' => $data], true, true);
    }

    /**
     * Generic PUT request
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->requestWithRetry('PUT', $endpoint, ['json' => $data], true, true);
    }

    /**
     * Generic DELETE request
     */
    public function delete(string $endpoint): array
    {
        return $this->requestWithRetry('DELETE', $endpoint, [], true, true);
    }

    /**
     * Busca itens usando o endpoint público /sites/{siteId}/search.
     * Retorna a resposta bruta da API (ex.: results, paging, available_filters).
     */
    public function searchItems(array $params = [], int $cacheTtl = 300): array
    {
        $siteId = (string)($params['site_id'] ?? $params['siteId'] ?? ($_ENV['ML_SITE_ID'] ?? (getenv('ML_SITE_ID') ?: null) ?? 'MLB'));
        unset($params['site_id'], $params['siteId']);

        return $this->get("/sites/{$siteId}/search", $params, $cacheTtl, true);
    }

    /**
     * Get seller ID
     */
    public function getSellerId(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        if ($this->sellerId) {
            return $this->sellerId;
        }

        try {
            $user = $this->get('/users/me');
            $rawSellerId = $user['id'] ?? null;
            $this->sellerId = $rawSellerId !== null ? (string)$rawSellerId : null;
            return $this->sellerId;
        } catch (\Exception $e) {
            log_error('Erro ao obter seller ID da ML API', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Compatibilidade: alguns serviços antigos chamam getMe().
     */
    public function getMe(): array
    {
        return $this->get('/users/me');
    }

    /**
     * Get account ID
     */
    public function getAccountId(): ?int
    {
        return $this->accountId;
    }

    /**
     * Get my items (seller's items)
     *
     * Usa o endpoint correto /users/{user_id}/items/search
     * O endpoint /users/me/items/search NÃO existe na API do ML
     *
     * @param array $params Filtros: status, limit, offset, search_type, etc
     * @return array Lista de itens ou erro
     */
    public function getMyItems(array $params = []): array
    {
        $sellerId = $this->getSellerId();

        if (!$sellerId) {
            return [
                'error' => 'seller_not_found',
                'message' => 'Não foi possível obter o ID do vendedor. Verifique a autenticação.',
                'results' => [],
                'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]
            ];
        }

        // Parâmetros padrão
        $defaults = [
            'status' => 'active',
            'limit' => 50,
            'offset' => 0
        ];

        $params = array_merge($defaults, $params);

        return $this->get("/users/{$sellerId}/items/search", $params);
    }

    /**
     * Get trends for a category
     */
    public function getTrends(string $categoryId): array
    {
        try {
            $siteId = $_ENV['ML_SITE_ID'] ?? (getenv('ML_SITE_ID') ?: null) ?? 'MLB';
            $data = $this->get("/trends/{$siteId}/{$categoryId}", [], 600, true);

            if (isset($data['error'])) {
                return [];
            }

            $keywords = $data['keywords'] ?? [];
            if (!is_array($keywords)) {
                return [];
            }

            $terms = [];
            foreach ($keywords as $kw) {
                if (is_string($kw) && $kw !== '') {
                    $terms[] = $kw;
                    continue;
                }
                if (is_array($kw)) {
                    $term = (string)($kw['keyword'] ?? $kw['term'] ?? $kw['q'] ?? '');
                    if ($term !== '') {
                        $terms[] = $term;
                    }
                }
            }

            return array_values(array_unique($terms));
        } catch (\Exception $e) {
            log_warning('Erro ao obter tendências da ML API', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get autocomplete suggestions
     */
    public function getAutocompleteSuggestions(string $keyword, ?string $categoryId = null): array
    {
        try {
            $siteId = $_ENV['ML_SITE_ID'] ?? (getenv('ML_SITE_ID') ?: null) ?? 'MLB';
            $params = [
                'q' => $keyword,
                'limit' => 20,
            ];

            // Alguns consumidores passam categoria; se a API ignorar, ok.
            if ($categoryId !== null && $categoryId !== '') {
                $params['category'] = $categoryId;
            }

            $data = $this->get("/sites/{$siteId}/autosuggest", $params, 600, true);

            if (isset($data['error'])) {
                return [];
            }

            $terms = [];

            // Formato comum: suggested_queries => [{q: "..."}, ...]
            foreach (($data['suggested_queries'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $q = (string)($row['q'] ?? '');
                if ($q !== '') {
                    $terms[] = $q;
                }
            }

            // Formato alternativo: suggestions => [{term: "..."}, ...]
            foreach (($data['suggestions'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $term = (string)($row['term'] ?? '');
                if ($term !== '') {
                    $terms[] = $term;
                }
            }

            return array_values(array_unique($terms));
        } catch (\Exception $e) {
            log_warning('Erro ao obter autocomplete da ML API', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get category information
     */
    public function getCategory(string $categoryId): array
    {
        try {
            $data = $this->get("/categories/{$categoryId}", [], 86400, true);
            if (isset($data['error']) || !is_array($data)) {
                return [];
            }
            return $data;
        } catch (\Exception $e) {
            log_warning('Erro ao obter categoria da ML API', [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get category attributes
     */
    public function getCategoryAttributes(string $categoryId): array
    {
        try {
            $data = $this->get("/categories/{$categoryId}/attributes", [], 43200, true);
            if (isset($data['error']) || !is_array($data)) {
                return [];
            }

            // A API retorna uma lista de atributos (arrays). Mantemos estrutura completa.
            return array_values(array_filter($data, fn($row) => is_array($row) && isset($row['id'])));
        } catch (\Exception $e) {
            log_warning('Erro ao obter atributos da ML API', [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get item details
     *
     * Returns the item with description as both:
     *  - 'description' (string): plain text for most consumers
     *  - 'description_data' (array): full API response for services needing HTML/metadata
     */
    public function getItemDetails(string $itemId): array
    {
        try {
            $item = $this->get("/items/{$itemId}", [], 300, true);
            if (isset($item['error']) || !is_array($item) || empty($item)) {
                return [];
            }

            $desc = $this->get("/items/{$itemId}/description", [], 300, true);
            if (!isset($desc['error']) && is_array($desc) && !empty($desc)) {
                // Store plain text for most consumers (title audit, AI prompts, etc.)
                $item['description'] = $desc['plain_text'] ?? $desc['text'] ?? '';
                // Preserve full response for services needing HTML or metadata
                $item['description_data'] = $desc;
            }

            return $item;
        } catch (\Exception $e) {
            log_error('Erro ao obter detalhes do item na ML API', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Update item — usa requestWithRetry para circuit breaker, retry em 401/429, e proteção de rede
     */
    public function updateItem(string $itemId, array $updates): array
    {
        $result = $this->requestWithRetry('PUT', "/items/{$itemId}", [
            'json' => $updates,
        ]);

        $hasError = isset($result['error']);

        return [
            'success' => !$hasError,
            'item_id' => $itemId,
            'updated_fields' => array_keys($updates),
            'response' => $result,
            'message' => $hasError
                ? ($result['message'] ?? 'Erro ao atualizar item')
                : 'Item atualizado com sucesso',
        ];
    }

    /**
     * Update item description via dedicated ML endpoint.
     *
     * ML API requires PUT /items/{id}/description — updating description
     * via the main item endpoint is not supported.
     *
     * @param string $itemId ML item ID (e.g. MLB1234567890)
     * @param string $plainText New description text
     * @return array{success: bool, item_id: string, message: string}
     */
    public function updateDescription(string $itemId, string $plainText): array
    {
        $result = $this->requestWithRetry('PUT', "/items/{$itemId}/description", [
            'json' => ['plain_text' => $plainText],
        ]);

        $hasError = isset($result['error']);

        return [
            'success' => !$hasError,
            'item_id' => $itemId,
            'response' => $result,
            'message' => $hasError
                ? ($result['message'] ?? 'Erro ao atualizar descrição')
                : 'Descrição atualizada com sucesso',
        ];
    }

    /**
     * Get listing quality performance for a specific item (new API, replaces /health).
     *
     * ML API: GET /item/{id}/performance
     * Returns quality score (0-100), level, and improvement actions grouped in buckets.
     * Note: /items/{id}/health was deprecated in Feb 2025.
     *
     * @return array Raw performance data from ML
     */
    public function getItemPerformance(string $itemId): array
    {
        try {
            return $this->get("/item/{$itemId}/performance");
        } catch (\Exception $e) {
            log_error('Erro ao obter performance do item', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get listing quality health for a specific item.
     *
     * Internally uses /item/{id}/performance (replaces deprecated /items/{id}/health).
     * Returns a normalized response compatible with legacy callers.
     *
     * @return array{item_id: string, health_score: int, status: string, level_wording: string, issues: array, recommendations: array, buckets: array}
     */
    public function getItemHealth(string $itemId): array
    {
        try {
            $data = $this->get("/item/{$itemId}/performance");

            if (isset($data['error'])) {
                return ['error' => $data['error']];
            }

            $issues = [];
            $recommendations = [];
            foreach ($data['buckets'] ?? [] as $bucket) {
                foreach ($bucket['variables'] ?? [] as $variable) {
                    if (($variable['status'] ?? '') !== 'PENDING') {
                        continue;
                    }
                    foreach ($variable['rules'] ?? [] as $rule) {
                        if (($rule['status'] ?? '') === 'PENDING') {
                            $issues[] = [
                                'bucket' => $bucket['key'] ?? '',
                                'key' => $rule['key'] ?? '',
                                'mode' => $rule['mode'] ?? '',
                                'title' => $rule['wordings']['title'] ?? '',
                                'label' => $rule['wordings']['label'] ?? '',
                            ];
                            if (!empty($rule['wordings']['title'])) {
                                $recommendations[] = $rule['wordings']['title'];
                            }
                        }
                    }
                }
            }

            return [
                'item_id' => $itemId,
                'health_score' => (int) ($data['score'] ?? 0),
                'status' => $data['level'] ?? 'unknown',
                'level_wording' => $data['level_wording'] ?? '',
                'issues' => $issues,
                'recommendations' => array_values(array_unique($recommendations)),
                'buckets' => $data['buckets'] ?? [],
                'calculated_at' => $data['calculated_at'] ?? null,
            ];
        } catch (\Exception $e) {
            log_error('Erro ao obter saúde do item', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get buyer purchase experience for a specific item.
     *
     * ML API: GET /reputation/items/{id}/purchase_experience/integrators
     * Returns reputation color/value, metrics detail (complaints, cancellations).
     *
     * @param string $itemId ML item ID
     * @param string $locale BCP47 locale, e.g. 'pt_BR'
     * @return array Purchase experience data or ['error' => ...]
     */
    public function getPurchaseExperience(string $itemId, string $locale = 'pt_BR'): array
    {
        try {
            return $this->get(
                "/reputation/items/{$itemId}/purchase_experience/integrators",
                ['locale' => $locale]
            );
        } catch (\Exception $e) {
            log_error('Erro ao obter experiência de compra do item', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Última moderação ativa do anúncio (reason/remedy oficiais).
     *
     * ML API: GET /moderations/last_moderation/{ITEM_ID}-ITM
     *
     * @return list<array<string, mixed>>|array{error: string, message?: string}
     */
    public function getLastModeration(string $itemId): array
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return ['error' => 'invalid_item_id', 'message' => 'item_id obrigatório'];
        }

        // moderation_reference_id = element_id + "-" + element_type (ITM para publicações)
        $referenceId = str_ends_with($itemId, '-ITM') ? $itemId : $itemId . '-ITM';

        try {
            $response = $this->get('/moderations/last_moderation/' . rawurlencode($referenceId));
            if (isset($response['error'])) {
                return $response;
            }

            // Docs oficiais: resposta é um ARRAY de moderações (não objeto único).
            if (!is_array($response)) {
                return [];
            }

            return $response;
        } catch (\Exception $e) {
            log_error('Erro ao obter last_moderation', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Histórico de infrações do vendedor.
     *
     * ML API: GET /moderations/infractions/{USER_ID}
     *
     * @param array<string, scalar|null> $params date_created_since, limit, offset, language, related_item_id, sort
     * @return array<string, mixed>
     */
    public function getModerationInfractions(string $sellerId, array $params = []): array
    {
        $sellerId = trim($sellerId);
        if ($sellerId === '') {
            return ['error' => 'invalid_seller_id', 'message' => 'seller_id obrigatório', 'infractions' => []];
        }

        $defaults = [
            'language' => 'PT',
            'limit' => 20,
            'offset' => 0,
            'sort' => 'date_created_desc',
        ];
        $query = array_merge($defaults, $params);

        try {
            return $this->get('/moderations/infractions/' . rawurlencode($sellerId), $query);
        } catch (\Exception $e) {
            log_error('Erro ao obter infractions', [
                'seller_id' => $sellerId,
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage(), 'infractions' => []];
        }
    }

    /**
     * Diagnóstico preventivo de imagem (antes de associar ao anúncio).
     *
     * ML API: POST /moderations/pictures/diagnostic
     *
     * @param array{picture_url?: string, picture_id?: string, context: array<string, mixed>, id?: string} $payload
     * @return array<string, mixed>
     */
    public function diagnosePicture(array $payload): array
    {
        if (!isset($payload['context']) || !is_array($payload['context'])) {
            return ['error' => 'invalid_payload', 'message' => 'context é obrigatório'];
        }

        $hasUrl = isset($payload['picture_url']) && is_string($payload['picture_url']) && $payload['picture_url'] !== '';
        $hasId = isset($payload['picture_id']) && is_string($payload['picture_id']) && $payload['picture_id'] !== '';
        if ($hasUrl === $hasId) {
            return [
                'error' => 'invalid_payload',
                'message' => 'Envie exatamente um de: picture_url ou picture_id',
            ];
        }

        try {
            return $this->post('/moderations/pictures/diagnostic', $payload);
        } catch (\Exception $e) {
            log_error('Erro no diagnóstico de imagem ML', [
                'error' => $e->getMessage(),
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get seller's listing quality rankings.
     *
     * ML API: GET /users/{sellerId}/items/search with quality filters.
     * Useful for finding items that need SEO optimization.
     *
     * @param array $filters Filters: status, category, sort, offset, limit
     * @return array{items: array, paging: array}
     */
    public function getSellerItemsForOptimization(array $filters = []): array
    {
        $sellerId = $this->getSellerId();
        if (empty($sellerId)) {
            return ['items' => [], 'paging' => ['total' => 0]];
        }

        $params = [
            'status' => $filters['status'] ?? 'active',
            'offset' => $filters['offset'] ?? 0,
            'limit' => min($filters['limit'] ?? 50, 100),
        ];

        if (!empty($filters['category'])) {
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['sort'])) {
            $params['sort'] = $filters['sort'];
        }

        try {
            $data = $this->get("/users/{$sellerId}/items/search", $params);

            if (isset($data['error'])) {
                return ['items' => [], 'paging' => ['total' => 0]];
            }

            $itemIds = $data['results'] ?? [];
            $paging = $data['paging'] ?? ['total' => 0, 'offset' => 0, 'limit' => 50];

            // Fetch basic details for each item in chunks of 20 (ML multi-get limit)
            $items = [];
            if (!empty($itemIds)) {
                $chunks = array_chunk($itemIds, 20);
                foreach ($chunks as $idsChunk) {
                    $multiGet = $this->get('/items', ['ids' => implode(',', $idsChunk)], 120, true);

                    if (is_array($multiGet)) {
                        foreach ($multiGet as $entry) {
                            $body = $entry['body'] ?? $entry;
                            if (isset($body['id'])) {
                                $items[] = [
                                    'id' => $body['id'],
                                    'title' => $body['title'] ?? '',
                                    'price' => $body['price'] ?? 0,
                                    'status' => $body['status'] ?? '',
                                    'category_id' => $body['category_id'] ?? '',
                                    'permalink' => $body['permalink'] ?? '',
                                    'sold_quantity' => $body['sold_quantity'] ?? 0,
                                    'available_quantity' => $body['available_quantity'] ?? 0,
                                    'thumbnail' => $body['thumbnail'] ?? '',
                                    'listing_type_id' => $body['listing_type_id'] ?? '',
                                    'health' => $body['health'] ?? null,
                                ];
                            }
                        }
                    }

                    if (count($chunks) > 1) {
                        usleep(100_000); // 100ms entre batches para rate limiting
                    }
                }
            }

            return [
                'items' => $items,
                'paging' => $paging,
            ];
        } catch (\Exception $e) {
            log_error('Erro ao buscar itens para otimização', [
                'seller_id' => $sellerId,
                'error' => $e->getMessage(),
            ]);
            return ['items' => [], 'paging' => ['total' => 0]];
        }
    }

    /**
     * Get search results for a keyword in a category.
     *
     * Returns the full paged response including 'results' and 'paging' keys,
     * so callers can access total counts and iterate pages.
     *
     * @return array{results: array, paging: array}
     */
    public function searchByKeyword(string $keyword, string $categoryId, int $limit = 20): array
    {
        try {
            $siteId = (string)($_ENV['ML_SITE_ID'] ?? (getenv('ML_SITE_ID') ?: null) ?? 'MLB');
            $data = $this->get("/sites/{$siteId}/search", [
                'q' => $keyword,
                'category' => $categoryId,
                'limit' => $limit,
            ], 300, true);

            if (isset($data['error'])) {
                return ['results' => [], 'paging' => ['total' => 0]];
            }

            $items = [];
            foreach ($data['results'] ?? [] as $item) {
                $items[] = [
                    'id' => $item['id'] ?? '',
                    'title' => $item['title'] ?? '',
                    'price' => $item['price'] ?? 0,
                    'sold_quantity' => $item['sold_quantity'] ?? 0,
                    'permalink' => $item['permalink'] ?? '',
                ];
            }

            return [
                'results' => $items,
                'paging' => $data['paging'] ?? ['total' => count($items)],
            ];
        } catch (\Exception $e) {
            log_error('Erro ao buscar por keyword na ML API', [
                'keyword' => $keyword,
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            return ['results' => [], 'paging' => ['total' => 0]];
        }
    }

    /**
     * Get competitor analysis
     */
    public function getCompetitorAnalysis(string $keyword, string $categoryId): array
    {
        try {
            // searchByKeyword now returns {results: [...], paging: {...}}
            $searchData = $this->searchByKeyword($keyword, $categoryId, 50);
            $results = $searchData['results'] ?? [];

            // Analyze pricing and performance
            $prices = array_column($results, 'price');
            $avgPrice = count($prices) > 0 ? array_sum($prices) / count($prices) : 0;
            $minPrice = count($prices) > 0 ? min($prices) : 0;
            $maxPrice = count($prices) > 0 ? max($prices) : 0;

            // Get top performing items based on sales
            usort($results, function ($a, $b) {
                return $b['sold_quantity'] <=> $a['sold_quantity'];
            });

            $topPerformers = array_slice($results, 0, 5);

            return [
                'top_performers' => $topPerformers,
                'price_analysis' => [
                    'min' => $minPrice,
                    'max' => $maxPrice,
                    'avg' => $avgPrice,
                ],
                'market_insights' => [
                    'total_results' => $searchData['paging']['total'] ?? count($results),
                    'competitor_density' => count($results) > 20 ? 'high' : (count($results) > 5 ? 'medium' : 'low'),
                ],
            ];
        } catch (\Exception $e) {
            log_error('Erro ao obter análise de concorrência da ML API', [
                'error' => $e->getMessage(),
            ]);
            return [
                'top_performers' => [],
                'price_analysis' => ['min' => 0, 'max' => 0, 'avg' => 0],
                'market_insights' => ['total_results' => 0, 'competitor_density' => 'unknown']
            ];
        }
    }

    /**
     * Obtém visitas de um item individual.
     *
     * ML API: GET /visits/items?ids={id}&date_from=...&date_to=...
     *
     * @param string $itemId ML item ID (e.g. MLB1234567890)
     * @param int $days Número de dias para consultar (máx 30)
     * @return array{total: int, visits: int, daily: array}
     */
    public function getItemVisits(string $itemId, int $days = 30): array
    {
        return $this->getMultiItemVisits([$itemId], $days)[$itemId] ?? [
            'total' => 0,
            'visits' => 0,
            'daily' => [],
        ];
    }

    /**
     * Obtém visitas de múltiplos itens em uma única chamada.
     *
     * ML API: GET /visits/items?ids={id1,id2,...}&date_from=...&date_to=...
     * Máximo 50 IDs por chamada.
     *
     * @param array<string> $itemIds Lista de IDs (máx 50)
     * @param int $days Período em dias (máx 30)
     * @return array<string, array{total: int, visits: int, daily: array}>
     */
    public function getMultiItemVisits(array $itemIds, int $days = 30): array
    {
        $results = [];
        $empty = ['total' => 0, 'visits' => 0, 'daily' => []];

        if (empty($itemIds)) {
            return $results;
        }

        $days = min(max(1, $days), 30);
        $dateTo = date('Y-m-d');
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        // ML limita 50 IDs por chamada
        $chunks = array_chunk($itemIds, 50);

        foreach ($chunks as $chunk) {
            try {
                $data = $this->get('/visits/items', [
                    'ids' => implode(',', $chunk),
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ]);

                if (isset($data['error'])) {
                    log_warning('ML Visits API retornou erro', [
                        'error' => $data['error'],
                        'message' => $data['message'] ?? '',
                        'ids_count' => count($chunk),
                    ]);
                    foreach ($chunk as $id) {
                        $results[$id] = $empty;
                    }
                    continue;
                }

                // A resposta é um array indexado por item_id
                foreach ($chunk as $id) {
                    if (isset($data[$id])) {
                        $itemData = $data[$id];
                        $daily = [];
                        $totalVisits = 0;

                        if (is_array($itemData)) {
                            foreach ($itemData as $entry) {
                                $date = $entry['date'] ?? null;
                                $count = (int)($entry['total'] ?? $entry['quantity'] ?? 0);
                                if ($date !== null) {
                                    $daily[$date] = $count;
                                    $totalVisits += $count;
                                }
                            }
                        }

                        $results[$id] = [
                            'total' => $totalVisits,
                            'visits' => $totalVisits,
                            'daily' => $daily,
                        ];
                    } else {
                        $results[$id] = $empty;
                    }
                }
            } catch (\Exception $e) {
                log_error('Falha ao obter visitas ML', [
                    'ids_count' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
                foreach ($chunk as $id) {
                    $results[$id] = $empty;
                }
            }
        }

        return $results;
    }

    /**
     * Obtém detalhes de múltiplos itens em uma única chamada (multi-get).
     *
     * ML API: GET /items?ids={id1,id2,...}&attributes=id,title,price,sold_quantity,status,category_id
     * Máximo 20 IDs por chamada.
     *
     * @param array<string> $itemIds Lista de IDs (máx 20 por chamada, paginado automaticamente)
     * @param array<string> $attributes Campos desejados (vazio = todos)
     * @return array<string, array> Indexado por item_id
     */
    public function getMultiItemDetails(array $itemIds, array $attributes = []): array
    {
        $results = [];

        if (empty($itemIds)) {
            return $results;
        }

        $defaultAttributes = ['id', 'title', 'price', 'sold_quantity', 'status', 'category_id', 'available_quantity', 'thumbnail'];
        $attrs = !empty($attributes) ? $attributes : $defaultAttributes;

        $chunks = array_chunk($itemIds, 20);

        foreach ($chunks as $chunk) {
            try {
                $params = ['ids' => implode(',', $chunk)];
                if (!empty($attrs)) {
                    $params['attributes'] = implode(',', $attrs);
                }

                $data = $this->get('/items', $params, 120, true);

                if (is_array($data)) {
                    foreach ($data as $entry) {
                        $body = $entry['body'] ?? $entry;
                        $code = $entry['code'] ?? 200;

                        if ($code === 200 && isset($body['id'])) {
                            $results[$body['id']] = $body;
                        }
                    }
                }
            } catch (\Exception $e) {
                log_error('Falha no multi-get de itens ML', [
                    'ids_count' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Detecta resposta 403 do ML por policy quando client_id é enviado em endpoint público.
     */
    private function isPolicyBlockedResponse(array $response): bool
    {
        $haystack = strtolower((string) json_encode([
            'error' => $response['error'] ?? '',
            'message' => $response['message'] ?? '',
            'cause' => $response['cause'] ?? [],
        ]));

        if ($haystack === '') {
            return false;
        }

        return str_contains($haystack, 'pa_unauthorized_result_from_policies')
            || (str_contains($haystack, 'policy') && str_contains($haystack, 'unauthorized'));
    }

    /**
     * Probe sem injetar client_id para evitar bloqueios de policy em /sites/MLB.
     */
    private function probePublicSiteWithoutClientId(): array
    {
        try {
            $response = $this->getPublicHttpClient()->request('GET', $this->baseUrl . '/sites/MLB', [
                'timeout' => 8,
            ]);

            $decoded = json_decode((string)$response->getBody(), true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [
                'error' => 'public_probe_error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verifica conectividade pública de forma resiliente (inclui fallback sem client_id).
     *
     * @return array{api_accessible: bool, status: string, policy_blocked: bool, reason: ?string, check: string}
     */
    private function probePublicApiConnectivity(): array
    {
        try {
            $site = $this->get('/sites/MLB', [], 60, true);

            if (isset($site['id'])) {
                return [
                    'api_accessible' => true,
                    'status' => 'ok',
                    'policy_blocked' => false,
                    'reason' => null,
                    'check' => 'ok',
                ];
            }

            if (is_array($site) && isset($site['error']) && $this->isPolicyBlockedResponse($site)) {
                $fallback = $this->probePublicSiteWithoutClientId();

                if (isset($fallback['id'])) {
                    return [
                        'api_accessible' => true,
                        'status' => 'ok_no_client_id',
                        'policy_blocked' => true,
                        'reason' => 'policy_blocked_with_client_id',
                        'check' => 'ok (fallback sem client_id)',
                    ];
                }

                $reason = (string)($site['message'] ?? $site['error'] ?? 'policy_blocked');
                return [
                    'api_accessible' => false,
                    'status' => 'policy_blocked',
                    'policy_blocked' => true,
                    'reason' => $reason,
                    'check' => 'policy_blocked',
                ];
            }

            $reason = (string)($site['message'] ?? $site['error'] ?? 'Resposta inválida da API pública');
            return [
                'api_accessible' => false,
                'status' => 'failed',
                'policy_blocked' => false,
                'reason' => $reason,
                'check' => 'failed: ' . $reason,
            ];
        } catch (\Throwable $e) {
            return [
                'api_accessible' => false,
                'status' => 'error',
                'policy_blocked' => false,
                'reason' => $e->getMessage(),
                'check' => 'error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Diagnose connection status
     *
     * Verifica se a conexão com a API está funcionando corretamente
     *
     * @return array Diagnóstico completo
     */
    public function diagnose(): array
    {
        $result = [
            'connected' => false,
            'has_token' => $this->hasAccessToken,
            'account_id' => $this->accountId,
            'token_source' => $this->tokenSource,
            'db_unavailable' => $this->dbUnavailable,
            'account_disconnected' => $this->accountDisconnected,
            'seller_id' => null,
            'user_info' => null,
            'token_status' => $this->accountDisconnected
                ? 'disconnected'
                : ($this->hasAccessToken ? 'unknown' : 'missing'),
            'api_accessible' => false,
            'public_api_status' => 'unknown',
            'public_api_policy_blocked' => false,
            'public_api_reason' => null,
            'items_count' => 0,
            'error' => null,
            'checks' => []
        ];

        // Check 1: Token disponível
        $result['checks']['token'] = $this->hasAccessToken ? 'ok' : 'missing';

        // Check 2: API pública acessível (com fallback para bloqueio de policy)
        $publicProbe = $this->probePublicApiConnectivity();
        $result['api_accessible'] = (bool)($publicProbe['api_accessible'] ?? false);
        $result['public_api_status'] = (string)($publicProbe['status'] ?? 'unknown');
        $result['public_api_policy_blocked'] = (bool)($publicProbe['policy_blocked'] ?? false);
        $result['public_api_reason'] = $publicProbe['reason'] ?? null;
        $result['checks']['public_api'] = (string)($publicProbe['check'] ?? 'unknown');

        // Check 3: Autenticação
        if ($this->accountDisconnected) {
            $result['token_status'] = 'disconnected';
            $result['error'] = 'Conta desconectada — reautorização OAuth necessária';
            $result['checks']['auth'] = 'failed: account_disconnected';
        } elseif ($this->hasAccessToken) {
            try {
                $me = $this->get('/users/me');
                if (isset($me['id'])) {
                    $result['connected'] = true;
                    $result['seller_id'] = $me['id'];
                    $result['user_info'] = [
                        'id' => $me['id'],
                        'nickname' => $me['nickname'] ?? null,
                        'email' => $me['email'] ?? null,
                    ];
                    $result['token_status'] = 'valid';
                    $result['checks']['auth'] = 'ok';
                } elseif (isset($me['error'])) {
                    $result['token_status'] = 'invalid';
                    $result['error'] = $me['message'] ?? $me['error'];
                    $result['checks']['auth'] = 'failed: ' . ($me['message'] ?? $me['error']);
                }
            } catch (\Throwable $e) {
                $result['token_status'] = 'error';
                $result['error'] = $e->getMessage();
                $result['checks']['auth'] = 'error: ' . $e->getMessage();
            }
        } else {
            $result['checks']['auth'] = 'skipped (no token)';
        }

        // Check 4: Listagem de itens (se autenticado)
        if ($result['connected']) {
            try {
                $items = $this->getMyItems(['limit' => 1]);
                if (isset($items['paging']['total'])) {
                    $result['items_count'] = $items['paging']['total'];
                    $result['checks']['items'] = 'ok (' . $items['paging']['total'] . ' items)';
                } elseif (isset($items['error'])) {
                    $result['checks']['items'] = 'failed: ' . ($items['message'] ?? $items['error']);
                }
            } catch (\Throwable $e) {
                $result['checks']['items'] = 'error: ' . $e->getMessage();
            }
        }

        // Derived/computed fields for easier consumption
        $result['token_valid'] = $result['token_status'] === 'valid';
        $result['public_api'] = $result['api_accessible'] || $result['public_api_status'] === 'policy_blocked';
        $result['auth_ok'] = $result['connected'];

        return $result;
    }
}
