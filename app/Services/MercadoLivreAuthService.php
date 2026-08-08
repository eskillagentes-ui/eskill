<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Helpers\SessionHelper;

/**
 * Serviço responsável pelo fluxo OAuth do Mercado Livre (autorização, troca de código e refresh)
 */
class MercadoLivreAuthService
{
    private array $config;
    private ?\PDO $db;
    private bool $compatSchemaEnsured = false;

    /**
     * Marca uma conta como desconectada (falha irrecuperável de refresh).
     *
     * Não lança exceção: erros aqui não devem derrubar workers/controllers.
     *
     * @param array<string, string|int|float|bool|array|null>|null $details
     */
    private function markAccountDisconnected(
        int $accountId,
        string $errorMessage,
        ?int $httpCode = null,
        ?array $details = null,
        ?string $expiresAtBefore = null,
        ?int $executionTimeMs = null
    ): void {
        $safeMessage = mb_substr(trim($errorMessage), 0, 500);

        try {
            $stmt = $this->pdo()->prepare(
                "UPDATE ml_accounts
                 SET status = 'disconnected',
                     refresh_failure_count = refresh_failure_count + 1,
                     last_refresh_error = :error,
                     updated_at = NOW()
                 WHERE id = :id"
            );

            $stmt->execute([
                'error' => $safeMessage,
                'id' => $accountId,
            ]);
        } catch (\Throwable $e) {
            try {
                (new StructuredLogService())->warning('Falha ao marcar conta ML como disconnected', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
                // best-effort
            }
        }

        $this->logAuditEvent(
            $accountId,
            'refresh_disconnected',
            $details,
            $httpCode,
            $safeMessage,
            $expiresAtBefore,
            null,
            $executionTimeMs
        );
    }

    public function __construct(?\PDO $db = null, ?array $config = null)
    {
        $this->config = $config ?? \App\Core\Config::getInstance()->all();
        $this->db = $db;
    }

    private function pdo(): \PDO
    {
        if ($this->db instanceof \PDO) {
            $this->ensureCompatibilitySchema();
            return $this->db;
        }

        try {
            $this->db = Database::getInstance();
            $this->ensureCompatibilitySchema();
            return $this->db;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Database unavailable for MercadoLivreAuthService', 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getMercadoLivreConfig(): array
    {
        $config = $this->config['mercadolivre'] ?? [];
        return is_array($config) ? $config : [];
    }

    private function getMercadoLivreTokenUrl(): string
    {
        return $this->normalizeUrl(
            (string)($this->getMercadoLivreConfig()['token_url'] ?? ''),
            'https://api.mercadolibre.com/oauth/token'
        );
    }

    private function getMercadoLivreApiUrl(): string
    {
        return $this->normalizeUrl(
            (string)($this->getMercadoLivreConfig()['api_url'] ?? ''),
            'https://api.mercadolibre.com'
        );
    }

    /**
     * Normaliza URL com fallback e opcionalmente valida host permitido.
     *
     * @param list<string> $allowedHosts
     */
    private function normalizeUrl(string $rawUrl, string $fallbackUrl, array $allowedHosts = []): string
    {
        $candidate = trim($rawUrl);

        if ($candidate === '') {
            return $fallbackUrl;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:' . $candidate;
        } elseif (!preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://' . $candidate;
        }

        if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
            return $fallbackUrl;
        }

        if ($allowedHosts !== []) {
            $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?? ''));
            if ($host === '') {
                return $fallbackUrl;
            }

            $allowed = false;
            foreach ($allowedHosts as $allowedHost) {
                $allowedHost = strtolower(trim($allowedHost));
                if ($allowedHost === '') {
                    continue;
                }

                if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                return $fallbackUrl;
            }
        }

        return $candidate;
    }

    private function getMercadoLivreAuthUrl(): string
    {
        $authUrl = $this->normalizeUrl(
            (string)($this->getMercadoLivreConfig()['auth_url'] ?? ''),
            'https://auth.mercadolibre.com/authorization',
            ['auth.mercadolibre.com', 'auth.mercadolibre.com.br']
        );

        $path = (string)(parse_url($authUrl, PHP_URL_PATH) ?? '');
        if ($path === '' || $path === '/') {
            $authUrl = rtrim($authUrl, '/') . '/authorization';
        }

        return $authUrl;
    }


    private function isOAuthRedirectUriValid(string $redirectUri): bool
    {
        $candidate = trim($redirectUri);
        if ($candidate === '') {
            return false;
        }

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:' . $candidate;
        } elseif (!preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://' . $candidate;
        }

        if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string)(parse_url($candidate, PHP_URL_SCHEME) ?? ''));
        $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?? ''));
        $path = (string)(parse_url($candidate, PHP_URL_PATH) ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return $path !== '' && $path !== '/';
    }

    private function pruneOAuthSessionState(int $maxAgeSeconds = 1800, int $maxEntries = 20): void
    {
        if (!isset($_SESSION['ml_oauth_states']) || !is_array($_SESSION['ml_oauth_states'])) {
            return;
        }

        $now = time();
        $states = $_SESSION['ml_oauth_states'];

        foreach ($states as $state => $context) {
            $createdAt = is_array($context) ? (int)($context['created_at'] ?? 0) : 0;
            if ($createdAt <= 0 || ($now - $createdAt) > $maxAgeSeconds) {
                unset($states[$state]);
                if (isset($_SESSION['ml_oauth_pkce']) && is_array($_SESSION['ml_oauth_pkce'])) {
                    unset($_SESSION['ml_oauth_pkce'][$state]);
                }
            }
        }

        if (count($states) > $maxEntries) {
            uasort($states, static function ($a, $b): int {
                $aCreated = is_array($a) ? (int)($a['created_at'] ?? 0) : 0;
                $bCreated = is_array($b) ? (int)($b['created_at'] ?? 0) : 0;
                return $aCreated <=> $bCreated;
            });

            while (count($states) > $maxEntries) {
                $oldestState = array_key_first($states);
                if ($oldestState === null) {
                    break;
                }
                unset($states[$oldestState]);
                if (isset($_SESSION['ml_oauth_pkce']) && is_array($_SESSION['ml_oauth_pkce'])) {
                    unset($_SESSION['ml_oauth_pkce'][$oldestState]);
                }
            }
        }

        $_SESSION['ml_oauth_states'] = $states;
    }

    private function shouldRetryTokenRequest(int $httpCode, string $curlError): bool
    {
        if ($curlError !== '') {
            return true;
        }

        return $httpCode === 408 || $httpCode === 429 || ($httpCode >= 500 && $httpCode < 600);
    }

    private function calculateTokenRetryDelaySeconds(int $attempt): int
    {
        $base = max(1, min(30, (int)($_ENV['ML_TRANSIENT_RETRY_BASE_SECONDS'] ?? getenv('ML_TRANSIENT_RETRY_BASE_SECONDS') ?? 2)));
        $maxDelay = max($base, min(60, (int)($_ENV['ML_TRANSIENT_RETRY_MAX_SECONDS'] ?? getenv('ML_TRANSIENT_RETRY_MAX_SECONDS') ?? 30)));
        $jitterMax = max(0, min(5, (int)($_ENV['ML_TRANSIENT_RETRY_JITTER_SECONDS'] ?? getenv('ML_TRANSIENT_RETRY_JITTER_SECONDS') ?? 1)));

        $delay = $base * (int)pow(2, max(0, $attempt - 1));
        $delay = min($maxDelay, $delay);
        $jitter = $jitterMax > 0 ? random_int(0, $jitterMax) : 0;

        return $delay + $jitter;
    }

    /**
     * Compatibilidade com instalações antigas: garante colunas/tabelas usadas pelo fluxo de tokens.
     */
    private function ensureCompatibilitySchema(): void
    {
        if ($this->compatSchemaEnsured || !($this->db instanceof \PDO)) {
            return;
        }

        try {
            $dbNameStmt = $this->db->query('SELECT DATABASE() AS db_name');
            $dbName = $dbNameStmt ? (string)($dbNameStmt->fetch(\PDO::FETCH_ASSOC)['db_name'] ?? '') : '';
            if ($dbName === '') {
                $this->compatSchemaEnsured = true;
                return;
            }

            $this->ensureMlAccountsCompatibilityColumns($dbName);
            $this->ensureTokenRefreshAuditTableExists();
        } catch (\Throwable $e) {
            // best-effort: não bloquear o fluxo por migração incremental
        } finally {
            $this->compatSchemaEnsured = true;
        }
    }

    private function ensureMlAccountsCompatibilityColumns(string $dbName): void
    {
        $stmt = $this->db->prepare(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :db
               AND TABLE_NAME = 'ml_accounts'"
        );
        $stmt->execute([':db' => $dbName]);
        $cols = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $map = array_fill_keys(is_array($cols) ? $cols : [], true);

        $alterStatements = [];
        if (!isset($map['refresh_failure_count'])) {
            $alterStatements[] = "ADD COLUMN refresh_failure_count INT NOT NULL DEFAULT 0";
        }
        if (!isset($map['last_refresh_error'])) {
            $alterStatements[] = "ADD COLUMN last_refresh_error TEXT NULL";
        }
        if (!isset($map['last_refresh_at'])) {
            $alterStatements[] = "ADD COLUMN last_refresh_at DATETIME NULL";
        }
        if (!isset($map['last_token_refresh'])) {
            $alterStatements[] = "ADD COLUMN last_token_refresh DATETIME NULL";
        }

        if (!empty($alterStatements)) {
            $this->db->exec('ALTER TABLE ml_accounts ' . implode(', ', $alterStatements));
        }

        $this->ensureMlAccountsStatusSupportsDisconnected($dbName);
    }

    private function ensureMlAccountsStatusSupportsDisconnected(string $dbName): void
    {
        $stmt = $this->db->prepare(
            "SELECT DATA_TYPE, COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :db
               AND TABLE_NAME = 'ml_accounts'
               AND COLUMN_NAME = 'status'
             LIMIT 1"
        );
        $stmt->execute([':db' => $dbName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return;
        }

        $dataType = strtolower((string)($row['DATA_TYPE'] ?? ''));
        $columnType = strtolower((string)($row['COLUMN_TYPE'] ?? ''));

        if ($dataType !== 'enum') {
            return;
        }

        if (str_contains($columnType, "'disconnected'")) {
            return;
        }

        $this->db->exec(
            "ALTER TABLE ml_accounts
             MODIFY COLUMN status ENUM('active','inactive','expired','disconnected')
             DEFAULT 'active'"
        );
    }

    private function ensureTokenRefreshAuditTableExists(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS token_refresh_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id INT NOT NULL,
                action VARCHAR(80) NOT NULL,
                details JSON NULL,
                http_code INT NULL,
                error_message TEXT NULL,
                expires_at_before DATETIME NULL,
                expires_at_after DATETIME NULL,
                execution_time_ms INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token_refresh_audit_account_id (account_id),
                INDEX idx_token_refresh_audit_action (action),
                INDEX idx_token_refresh_audit_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->ensureTokenRefreshAuditActionSupportsDisconnected();
    }

    private function ensureTokenRefreshAuditActionSupportsDisconnected(): void
    {
        $stmt = $this->db->query(
            "SELECT DATA_TYPE, COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'token_refresh_audit'
               AND COLUMN_NAME = 'action'
             LIMIT 1"
        );
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;

        if (!is_array($row)) {
            return;
        }

        $dataType = strtolower((string)($row['DATA_TYPE'] ?? ''));
        $columnType = strtolower((string)($row['COLUMN_TYPE'] ?? ''));

        if ($dataType !== 'enum') {
            return;
        }

        if (str_contains($columnType, "'refresh_disconnected'")) {
            return;
        }

        $this->db->exec(
            "ALTER TABLE token_refresh_audit
             MODIFY COLUMN action ENUM(
                'refresh_attempt',
                'refresh_success',
                'refresh_failed',
                'authorization_granted',
                'token_expired',
                'lock_acquired',
                'lock_timeout',
                'refresh_disconnected'
             ) NOT NULL"
        );
    }

    /**
     * Grava evento de auditoria na tabela token_refresh_audit
     */
    private function logAuditEvent(
        int $accountId,
        string $action,
        ?array $details = null,
        ?int $httpCode = null,
        ?string $errorMessage = null,
        ?string $expiresAtBefore = null,
        ?string $expiresAtAfter = null,
        ?int $executionTimeMs = null
    ): void {
        try {
            $stmt = $this->pdo()->prepare("
                INSERT INTO token_refresh_audit (
                    account_id, action, details, http_code, error_message,
                    expires_at_before, expires_at_after, execution_time_ms
                ) VALUES (
                    :account_id, :action, :details, :http_code, :error_message,
                    :expires_at_before, :expires_at_after, :execution_time_ms
                )
            ");

            $stmt->execute([
                'account_id' => $accountId,
                'action' => $action,
                'details' => $details ? json_encode($details) : null,
                'http_code' => $httpCode,
                'error_message' => $errorMessage,
                'expires_at_before' => $expiresAtBefore,
                'expires_at_after' => $expiresAtAfter,
                'execution_time_ms' => $executionTimeMs,
            ]);
        } catch (\Throwable $e) {
            // Não falhar por erro de auditoria, apenas logar
            log_warning('Falha ao gravar evento de auditoria de token', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Gera a URL de autorização do Mercado Livre e grava um state seguro na sessão
     */
    public function getAuthUrl(int $userId): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $diagnostics = $this->getOAuthConfigDiagnostics();
        if (!$diagnostics['ready']) {
            throw new \RuntimeException((string) ($diagnostics['message'] ?? 'Configuração OAuth inválida'));
        }

        $ml = $this->config['mercadolivre'] ?? [];
        $clientId = trim((string)($ml['app_id'] ?? ''));
        $rawRedirect = trim((string)($ml['redirect_uri'] ?? ''));
        $redirect = $this->normalizeOAuthRedirectUri($rawRedirect);
        $authBase = $this->getMercadoLivreAuthUrl();

        if ($rawRedirect === '' || !$this->isOAuthRedirectUriValid($rawRedirect)) {
            throw new \RuntimeException('ML_REDIRECT_URI inválido para o fluxo OAuth');
        }

        if (!filter_var($redirect, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('ML_REDIRECT_URI inválido para o fluxo OAuth');
        }

        $this->pruneOAuthSessionState();

        $state = $userId . ':' . bin2hex(random_bytes(16));
        $_SESSION['ml_oauth_state'] = $state;
        if (!isset($_SESSION['ml_oauth_states']) || !is_array($_SESSION['ml_oauth_states'])) {
            $_SESSION['ml_oauth_states'] = [];
        }
        $_SESSION['ml_oauth_states'][$state] = [
            'user_id' => $userId,
            'created_at' => time(),
            'reconnect_account_id' => $_SESSION['reconnect_account_id'] ?? null,
        ];

        $codeVerifier = $this->base64UrlEncode(random_bytes(32));
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
        if (!isset($_SESSION['ml_oauth_pkce']) || !is_array($_SESSION['ml_oauth_pkce'])) {
            $_SESSION['ml_oauth_pkce'] = [];
        }
        $_SESSION['ml_oauth_pkce'][$state] = $codeVerifier;

        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'state' => $state,
            'scope' => 'read write',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return $authBase . '?' . http_build_query($params);
    }

    /**
     * Troca código de autorização por tokens e persiste a conta em `ml_accounts`.
     * Retorna array com 'success' e 'user_info' (dados retornados por /users/me).
     */
    public function exchangeCodeForTokens(string $code, string $state): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $diagnostics = $this->getOAuthConfigDiagnostics();
        if (!$diagnostics['ready']) {
            throw new \RuntimeException((string) ($diagnostics['message'] ?? 'Configuração OAuth inválida'));
        }

        $stored = $_SESSION['ml_oauth_state'] ?? null;
        $stateContexts = $_SESSION['ml_oauth_states'] ?? [];
        $stateContext = is_array($stateContexts) ? ($stateContexts[$state] ?? null) : null;
        $isLegacyMatch = is_string($stored) && hash_equals($stored, $state);
        if (!is_array($stateContext) && !$isLegacyMatch) {
            throw new \Exception('Estado OAuth inválido ou expirado');
        }

        $codeVerifier = null;
        if (isset($_SESSION['ml_oauth_pkce']) && is_array($_SESSION['ml_oauth_pkce'])) {
            $codeVerifier = $_SESSION['ml_oauth_pkce'][$state] ?? null;
        }
        if (!is_string($codeVerifier) || $codeVerifier === '') {
            throw new \Exception('code_verifier ausente ou expirado');
        }

        // Extrair userId do state (formato: <userId>:<random>)
        $parts = explode(':', $state, 2);
        $userId = (int)($stateContext['user_id'] ?? ($parts[0] ?? 0));

        $ml = $this->getMercadoLivreConfig();
        $tokenUrl = $this->getMercadoLivreTokenUrl();
        $clientId = $ml['app_id'] ?? '';
        $clientSecret = $ml['client_secret'] ?? '';
        $redirect = $this->normalizeOAuthRedirectUri((string)($ml['redirect_uri'] ?? ''));

        $post = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirect,
            'code_verifier' => $codeVerifier,
        ];

        $ch = curl_init($tokenUrl);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'SEO-Optimizer/1.0',
        ];
        $this->applyCurlProxyOptions($curlOptions);
        curl_setopt_array($ch, $curlOptions);

        $resp = curl_exec($ch);
        $curlError = $resp === false ? curl_error($ch) : '';
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new \Exception('Falha ao obter tokens do Mercado Livre: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new \Exception('Falha ao obter tokens do Mercado Livre: HTTP ' . $httpCode . ' - ' . $resp);
        }

        $data = json_decode($resp, true);
        if (!isset($data['access_token'])) {
            throw new \Exception('Resposta inválida do provedor de token: ' . $resp);
        }

        $accessToken = $data['access_token'];
        $refreshToken = $data['refresh_token'] ?? null;
        $expiresIn = (int)($data['expires_in'] ?? 21600);
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

        // Buscar informações do usuário ML
        $userInfo = $this->fetchUserInfo($accessToken);

        $mlUserId = $userInfo['id'] ?? null;
        $nickname = $userInfo['nickname'] ?? null;
        $email = $userInfo['email'] ?? null;

        if (!$mlUserId) {
            throw new \Exception('Não foi possível obter o id do usuário Mercado Livre');
        }

        // Criptografar tokens - OBRIGATÓRIO em produção
        $storeAccess = $accessToken;
        $storeRefresh = $refreshToken;
        $tokensEncrypted = 0;
        try {
            $enc = new EncryptionService();
            $storeAccess = $enc->encrypt($accessToken);
            $storeRefresh = $enc->encrypt((string)$refreshToken);
            $tokensEncrypted = 1;
        } catch (\Throwable $e) {
            $appEnv = $_ENV['APP_ENV'] ?? 'production';
            if ($appEnv === 'production' || $appEnv === 'staging') {
                throw new \RuntimeException(
                    'Impossível armazenar tokens sem criptografia em produção. Configure APP_KEY no .env (mínimo 32 caracteres). Erro: ' . $e->getMessage()
                );
            }
            $logger = new StructuredLogService();
            $logger->warning('EncryptionService indisponível - tokens armazenados sem criptografia (apenas dev)', [
                'error' => $e->getMessage()
            ]);
        }

        // Inserir ou atualizar ml_accounts
        $stmt = $this->pdo()->prepare('SELECT id, user_id FROM ml_accounts WHERE ml_user_id = :ml_user_id LIMIT 1');
        $stmt->execute(['ml_user_id' => $mlUserId]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $existingUserId = (int)($existing['user_id'] ?? 0);
            if ($existingUserId > 0 && $existingUserId !== $userId) {
                throw new \RuntimeException('Esta conta do Mercado Livre já está vinculada a outro usuário do sistema.');
            }

            $accountId = (int)$existing['id'];
            $update = $this->pdo()->prepare("UPDATE ml_accounts SET
                user_id = :user_id,
                nickname = :nickname,
                email = :email,
                access_token = :access_token,
                refresh_token = :refresh_token,
                token_expires_at = :expires_at,
                tokens_encrypted = :tokens_encrypted,
                status = 'active',
                updated_at = NOW()
                WHERE id = :id");

            $update->execute([
                'user_id' => $userId,
                'nickname' => $nickname,
                'email' => $email,
                'access_token' => $storeAccess,
                'refresh_token' => $storeRefresh,
                'expires_at' => $expiresAt,
                'tokens_encrypted' => $tokensEncrypted,
                'id' => $accountId
            ]);
        } else {
            $insert = $this->pdo()->prepare("INSERT INTO ml_accounts
                (user_id, ml_user_id, nickname, email, site_id, access_token, refresh_token, token_expires_at, status, tokens_encrypted, created_at, updated_at)
                VALUES
                (:user_id, :ml_user_id, :nickname, :email, :site_id, :access_token, :refresh_token, :expires_at, 'active', :tokens_encrypted, NOW(), NOW())");

            $insert->execute([
                'user_id' => $userId,
                'ml_user_id' => $mlUserId,
                'nickname' => $nickname,
                'email' => $email,
                'site_id' => $ml['site_id'] ?? 'MLB',
                'access_token' => $storeAccess,
                'refresh_token' => $storeRefresh,
                'expires_at' => $expiresAt,
                'tokens_encrypted' => $tokensEncrypted,
            ]);

            $accountId = (int)$this->pdo()->lastInsertId();
        }

        unset($_SESSION['ml_oauth_state']);
        if (isset($_SESSION['ml_oauth_states']) && is_array($_SESSION['ml_oauth_states'])) {
            unset($_SESSION['ml_oauth_states'][$state]);
        }
        if (isset($_SESSION['ml_oauth_pkce']) && is_array($_SESSION['ml_oauth_pkce'])) {
            unset($_SESSION['ml_oauth_pkce'][$state]);
        }

        // Logar autorização OAuth bem-sucedida
        $this->logAuditEvent(
            $accountId,
            'authorization_granted',
            [
                'ml_user_id' => $mlUserId,
                'nickname' => $nickname,
                'is_reconnection' => isset($existing)
            ],
            200,
            null,
            null,
            $expiresAt
        );

        // Atualizar last_oauth_connection_at
        $this->pdo()->prepare(
            'UPDATE ml_accounts SET last_oauth_connection_at = NOW() WHERE id = :id'
        )->execute(['id' => $accountId]);

        SessionHelper::setActiveAccountId($accountId);

        return ['success' => true, 'account_id' => $accountId, 'user_info' => $userInfo];
    }

    public function getOAuthConfigDiagnostics(): array
    {
        $ml = $this->getMercadoLivreConfig();
        $clientId = trim((string)($ml['app_id'] ?? ''));
        $clientSecret = trim((string)($ml['client_secret'] ?? ''));
        $redirectUri = trim((string)($ml['redirect_uri'] ?? ''));
        $normalizedRedirectUri = $this->normalizeOAuthRedirectUri($redirectUri);
        $appKey = trim((string)($this->config['key'] ?? ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?? '')));
        $appUrl = trim((string)($this->config['url'] ?? ''));
        $proxyEnabledRaw = $_ENV['ML_PROXY_ENABLED'] ?? getenv('ML_PROXY_ENABLED') ?? null;
        $proxyEnabled = filter_var($proxyEnabledRaw, FILTER_VALIDATE_BOOLEAN);
        $httpProxy = (string)(getenv('HTTP_PROXY') ?: getenv('http_proxy') ?: '');
        $httpsProxy = (string)(getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: '');
        $issues = [];
        $warnings = [];

        if ($clientId === '' || $this->isPlaceholderValue($clientId)) {
            $issues[] = 'ML_APP_ID ausente ou com valor placeholder';
        }

        if ($clientSecret === '' || $this->isPlaceholderValue($clientSecret)) {
            $issues[] = 'ML_CLIENT_SECRET ausente ou com valor placeholder';
        }

        if ($redirectUri === '' || $this->isPlaceholderValue($redirectUri)) {
            $issues[] = 'ML_REDIRECT_URI ausente ou com valor placeholder';
        } elseif (!filter_var($normalizedRedirectUri, FILTER_VALIDATE_URL)) {
            $issues[] = 'ML_REDIRECT_URI inválido para o fluxo OAuth';
        } else {
            $redirectPath = (string)(parse_url($normalizedRedirectUri, PHP_URL_PATH) ?? '');
            if ($redirectPath !== '/auth/callback') {
                $warnings[] = 'ML_REDIRECT_URI deve apontar para /auth/callback';
            }
        }

        if ($appKey === '' || $this->isPlaceholderValue($appKey) || strlen($appKey) < 32) {
            $issues[] = 'APP_KEY ausente, placeholder ou menor que 32 caracteres';
        }

        if (!$proxyEnabled && ($httpProxy !== '' || $httpsProxy !== '')) {
            $warnings[] = 'Proxy do ambiente detectado com ML_PROXY_ENABLED=false; conexões ML podem herdar proxy indevido em workers externos';
        }

        if ($proxyEnabled) {
            $proxyHost = trim((string)($_ENV['ML_PROXY_HOST'] ?? getenv('ML_PROXY_HOST') ?? ''));
            $proxyPort = trim((string)($_ENV['ML_PROXY_PORT'] ?? getenv('ML_PROXY_PORT') ?? ''));
            if ($proxyHost === '' || $proxyPort === '') {
                $issues[] = 'ML_PROXY_ENABLED=true exige ML_PROXY_HOST e ML_PROXY_PORT válidos';
            }
        }

        if ($appUrl !== '' && filter_var($normalizedRedirectUri, FILTER_VALIDATE_URL)) {
            $appHost = (string)(parse_url($appUrl, PHP_URL_HOST) ?? '');
            $redirectHost = (string)(parse_url($normalizedRedirectUri, PHP_URL_HOST) ?? '');
            if ($appHost !== '' && $redirectHost !== '' && strcasecmp($appHost, $redirectHost) !== 0) {
                $warnings[] = 'Host de ML_REDIRECT_URI difere do host configurado em APP_URL';
            }
        }

        return [
            'ready' => $issues === [],
            'message' => $issues[0] ?? ($warnings[0] ?? 'Configuração OAuth validada com sucesso'),
            'issues' => $issues,
            'warnings' => $warnings,
            'error_type' => $issues === [] ? null : 'configuration',
            'details' => [
                'redirect_uri' => $normalizedRedirectUri,
                'redirect_host' => (string)(parse_url($normalizedRedirectUri, PHP_URL_HOST) ?? ''),
                'redirect_path' => (string)(parse_url($normalizedRedirectUri, PHP_URL_PATH) ?? ''),
                'auth_url' => $this->getMercadoLivreAuthUrl(),
                'token_url' => $this->getMercadoLivreTokenUrl(),
                'api_url' => $this->getMercadoLivreApiUrl(),
                'proxy_enabled' => $proxyEnabled,
                'inherited_proxy_detected' => $httpProxy !== '' || $httpsProxy !== '',
                'http_proxy_host' => (string)(parse_url($httpProxy, PHP_URL_HOST) ?? $httpProxy),
                'https_proxy_host' => (string)(parse_url($httpsProxy, PHP_URL_HOST) ?? $httpsProxy),
            ],
        ];
    }

    /**
     * Renova tokens usando refresh_token salvo no banco
     * Com suporte a retry e backoff exponencial
     */
    public function refreshToken(int $accountId, int $maxRetries = 3): bool
    {
        // Garante APP_TIMEZONE antes de gravar token_expires_at / last_refresh_at
        if (class_exists(\App\Helpers\TimezoneHelper::class)) {
            \App\Helpers\TimezoneHelper::applyFromEnv();
        }

        $startTime = microtime(true);

        $lockName = $this->buildRefreshLockName($accountId);
        if (!$this->acquireRefreshTokenLock($lockName, 15)) {
            try {
                (new StructuredLogService())->warning('Refresh token ML ignorado por lock ativo', [
                    'account_id' => $accountId,
                    'lock' => $lockName,
                ]);
            } catch (\Throwable $ignored) {
                // best-effort
            }

            return false;
        }

        try {
            // Recuperar refresh token e expirar atual
            $stmt = $this->pdo()->prepare('SELECT refresh_token, tokens_encrypted, token_expires_at FROM ml_accounts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $accountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return false;
            }

            $expiresAtBefore = $row['token_expires_at'];

            // Logar tentativa de refresh
            $this->logAuditEvent(
                $accountId,
                'refresh_attempt',
                ['max_retries' => $maxRetries, 'lock' => $lockName],
                null,
                null,
                $expiresAtBefore
            );

            $refreshToken = $row['refresh_token'];
            if ($row['tokens_encrypted']) {
                try {
                    $enc = new EncryptionService();
                    $refreshToken = $enc->decrypt($refreshToken);
                } catch (\Throwable $e) {
                    $logger = new StructuredLogService();
                    $logger->error('Failed to decrypt refresh token', ['error' => $e->getMessage()]);

                    $executionTime = (int)((microtime(true) - $startTime) * 1000);
                    $this->markAccountDisconnected(
                        $accountId,
                        'decrypt_failed: falha ao descriptografar refresh token',
                        null,
                        null,
                        $expiresAtBefore,
                        $executionTime
                    );

                    return false;
                }
            }

            // Bail early: sem refresh token, não adianta tentar a API
            if (empty($refreshToken)) {
                $logger = new StructuredLogService();
                $logger->warning('Refresh token vazio - conta desconectada', [
                    'account_id' => $accountId,
                ]);

                $executionTime = (int)((microtime(true) - $startTime) * 1000);
                $this->markAccountDisconnected(
                    $accountId,
                    'missing_refresh_token: refresh token vazio',
                    null,
                    null,
                    $expiresAtBefore,
                    $executionTime
                );

                return false;
            }

            $ml = $this->getMercadoLivreConfig();
            $tokenUrl = $this->getMercadoLivreTokenUrl();
            $clientId = $ml['app_id'] ?? '';
            $clientSecret = $ml['client_secret'] ?? '';

            $post = [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken
            ];

            $attempt = 0;
            $success = false;
            $resp = null;
            $httpCode = 0;
            $curlError = '';
            $disconnectReason = null;

            while ($attempt < $maxRetries && !$success) {
                $attempt++;

                $ch = curl_init($tokenUrl);
                $curlOptions = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($post),
                    // fix H-B: 'accept: application/json' é obrigatório pela doc oficial do ML
                    // (sem ele, a API pode retornar HTML em cenários de erro em vez de JSON)
                    CURLOPT_HTTPHEADER => ['accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_USERAGENT => 'SEO-Optimizer/1.0',
                ];
                $this->applyCurlProxyOptions($curlOptions);
                curl_setopt_array($ch, $curlOptions);

                $resp = curl_exec($ch);
                $curlError = $resp === false ? curl_error($ch) : '';
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                // Sucesso HTTP 200
                if ($resp !== false && $httpCode === 200) {
                    $success = true;
                    break;
                }

                // Falha fatal (invalid_grant), não adianta tentar de novo
                if ($httpCode === 400 || $httpCode === 401) {
                    $data = json_decode($resp, true);
                    if (isset($data['error']) && $data['error'] === 'invalid_grant') {
                        $disconnectReason = 'invalid_grant: ' . (string)($data['message'] ?? 'refresh token inválido');
                        break;
                    }
                }

                if ($attempt < $maxRetries && $this->shouldRetryTokenRequest($httpCode, $curlError)) {
                    $sleepSeconds = $this->calculateTokenRetryDelaySeconds($attempt);
                    $logger = new StructuredLogService();
                    $logger->warning('Retry de refresh token ML agendado', [
                        'account_id' => $accountId,
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'http_code' => $httpCode,
                        'curl_error' => $curlError !== '' ? $curlError : null,
                        'sleep_seconds' => $sleepSeconds,
                    ]);
                    sleep($sleepSeconds);
                    continue;
                }

                break;
            }

            if (!$success) {
                $executionTime = (int)((microtime(true) - $startTime) * 1000);

                // Falha irrecuperável: invalid_grant => conta precisa reconectar via OAuth
                if (is_string($disconnectReason) && $disconnectReason !== '') {
                    $logger = new StructuredLogService();
                    $logger->warning('Conta ML desconectada (invalid_grant no refresh)', [
                        'account_id' => $accountId,
                        'status' => $httpCode,
                        'response_preview' => substr($resp ?? '', 0, 200),
                    ]);

                    $this->markAccountDisconnected(
                        $accountId,
                        $disconnectReason,
                        $httpCode,
                        ['attempts' => $attempt, 'response_preview' => substr($resp ?? '', 0, 200)],
                        $expiresAtBefore,
                        $executionTime
                    );

                    return false;
                }

                $logger = new StructuredLogService();
                $logger->error('Falha refresh token ML após tentativas', [
                    'attempts' => $attempt,
                    'status' => $httpCode,
                    'response' => $resp,
                    'curl_error' => $curlError,
                    'account_id' => $accountId
                ]);

                $this->logAuditEvent(
                    $accountId,
                    'refresh_failed',
                    ['attempts' => $attempt, 'response_preview' => substr((string)($resp ?? ''), 0, 200), 'curl_error' => $curlError !== '' ? $curlError : null],
                    $httpCode,
                    "Falha após {$attempt} tentativas",
                    $expiresAtBefore,
                    null,
                    $executionTime
                );

                // Atualizar contador de falhas e último erro
                $this->pdo()->prepare(
                    'UPDATE ml_accounts
                SET refresh_failure_count = refresh_failure_count + 1,
                    last_refresh_error = :error,
                    updated_at = NOW()
                WHERE id = :id'
                )->execute([
                    'error' => trim("HTTP {$httpCode}: " . substr((string)($resp ?? ''), 0, 500) . ($curlError !== '' ? ' | cURL: ' . $curlError : '')),
                    'id' => $accountId
                ]);

                return false;
            }

            $data = json_decode($resp, true);
            if (!isset($data['access_token'])) {
                $logger = new StructuredLogService();
                $logger->error('Resposta inválida ao renovar token ML', ['response' => $resp]);
                return false;
            }

            $accessToken = $data['access_token'];

            // fix H-A: a doc do ML garante que TODA resposta 200 de refresh inclui um novo
            // refresh_token de uso único. Se ele vier ausente (resposta truncada por rede),
            // NÃO fazer fallback para o token antigo — ele já foi consumido e a próxima tentativa
            // falharia com invalid_grant irrecuperável. Tratar como erro e forçar reautenticação.
            if (!isset($data['refresh_token']) || trim((string)$data['refresh_token']) === '') {
                $logger = new StructuredLogService();
                $logger->error('Resposta de refresh do ML não contém novo refresh_token', [
                    'account_id' => $accountId,
                    'response_keys' => array_keys($data),
                ]);
                // Marcar como disconnected: sem um refresh_token válido, precisamos de novo OAuth
                $executionTime = (int)((microtime(true) - $startTime) * 1000);
                $this->markAccountDisconnected(
                    $accountId,
                    'missing_new_refresh_token: resposta 200 sem refresh_token',
                    200,
                    ['response_keys' => array_keys($data)],
                    $expiresAtBefore,
                    $executionTime
                );
                return false;
            }

            $newRefresh = (string)$data['refresh_token'];

            $expiresIn = (int)($data['expires_in'] ?? 21600);
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

            // Criptografar tokens - OBRIGATÓRIO em produção
            $storeAccess = $accessToken;
            $storeRefresh = $newRefresh;
            $tokensEncrypted = $row['tokens_encrypted'] ? 1 : 0;
            try {
                $enc = new EncryptionService();
                $storeAccess = $enc->encrypt($accessToken);
                $storeRefresh = $enc->encrypt($newRefresh);
                $tokensEncrypted = 1;
            } catch (\Throwable $e) {
                $appEnv = $_ENV['APP_ENV'] ?? 'production';
                $logger = new StructuredLogService();
                $logger->critical('Falha ao criptografar tokens no refresh', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
                if ($appEnv === 'production' || $appEnv === 'staging') {
                    $executionTime = (int)((microtime(true) - $startTime) * 1000);
                    $this->markAccountDisconnected(
                        $accountId,
                        'encryption_failed: ' . substr($e->getMessage(), 0, 200),
                        null,
                        null,
                        $expiresAtBefore,
                        $executionTime
                    );
                    return false;
                }
            }

            // last_refresh_at/updated_at no mesmo relógio PHP de token_expires_at
            // (evita skew PHP Europe/Berlin vs MySQL UTC em NOW()).
            $refreshedAt = date('Y-m-d H:i:s');
            $upd = $this->pdo()->prepare('UPDATE ml_accounts SET access_token = :access_token, refresh_token = :refresh_token, token_expires_at = :expires_at, tokens_encrypted = :tokens_encrypted, status = :status, last_refresh_at = :last_refresh_at, refresh_failure_count = 0, last_refresh_error = NULL, updated_at = :updated_at WHERE id = :id');
            $upd->execute([
                'access_token' => $storeAccess,
                'refresh_token' => $storeRefresh,
                'expires_at' => $expiresAt,
                'tokens_encrypted' => $tokensEncrypted,
                'status' => 'active',
                'last_refresh_at' => $refreshedAt,
                'updated_at' => $refreshedAt,
                'id' => $accountId
            ]);

            $executionTime = (int)((microtime(true) - $startTime) * 1000);

            $logger = new StructuredLogService();
            $logger->info('Token ML renovado com sucesso', [
                'account_id' => $accountId,
                'expires_at' => $expiresAt,
                'attempts' => $attempt
            ]);

            // Logar sucesso de refresh com tempos de expiração
            $this->logAuditEvent(
                $accountId,
                'refresh_success',
                ['attempts' => $attempt],
                200,
                null,
                $expiresAtBefore,
                $expiresAt,
                $executionTime
            );

            // Não depender de rowCount(): no MySQL/PDO UPDATE pode reportar 0
            // "changed" mesmo com persistência ok, gerando falso negativo após
            // consumir o refresh_token de uso único na API do ML.
            return true;
        } finally {
            $this->releaseRefreshTokenLock($lockName);
        }
    }

    private function buildRefreshLockName(int $accountId): string
    {
        return mb_substr('ml_refresh_account_' . $accountId, 0, 64);
    }

    private function acquireRefreshTokenLock(string $lockName, int $timeoutSeconds = 10): bool
    {
        try {
            $stmt = $this->pdo()->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds) AS acquired');
            $stmt->bindValue(':lock_name', $lockName);
            $stmt->bindValue(':timeout_seconds', max(0, min(60, $timeoutSeconds)), \PDO::PARAM_INT);
            $stmt->execute();
            $value = $stmt->fetchColumn();

            return (int)$value === 1;
        } catch (\Throwable $e) {
            try {
                (new StructuredLogService())->warning('Falha ao adquirir advisory lock de refresh ML', [
                    'lock' => $lockName,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
                // best-effort
            }

            return false;
        }
    }

    private function releaseRefreshTokenLock(string $lockName): void
    {
        try {
            $stmt = $this->pdo()->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute([':lock_name' => $lockName]);
        } catch (\Throwable $e) {
            try {
                (new StructuredLogService())->warning('Falha ao liberar advisory lock de refresh ML', [
                    'lock' => $lockName,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
                // best-effort
            }
        }
    }

    /**
     * Garante token válido para uma conta. Renova se estiver perto de expirar.
     */
    public function ensureValidToken(int $accountId, int $bufferMinutes = 60): bool
    {
        $stmt = $this->pdo()->prepare('SELECT token_expires_at, status FROM ml_accounts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $accountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $expiresAt = $row['token_expires_at'] ?? null;
        $status = $row['status'] ?? 'inactive';

        // Se não há expiração registrada, tenta seguir mesmo assim
        if (!$expiresAt) {
            return true;
        }

        $secondsLeft = strtotime($expiresAt) - time();
        if ($secondsLeft > ($bufferMinutes * 60) && $status === 'active') {
            return true;
        }

        $refreshed = $this->refreshToken($accountId);
        if ($refreshed) {
            try {
                $upd = $this->pdo()->prepare("UPDATE ml_accounts SET status = 'active', updated_at = NOW() WHERE id = :id");
                $upd->execute(['id' => $accountId]);
            } catch (\Throwable $e) {
                // não bloquear se não conseguir atualizar status
                try {
                    (new StructuredLogService())->warning('Falha ao atualizar status ml_accounts para active', [
                        'account_id' => $accountId,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable $ignored) {
                    // último recurso: não bloquear fluxo
                }
            }
            return true;
        }

        // fix H-C: se refreshToken() retornou false por lock contention (outro worker estava
        // refreshing), o token pode agora já ter sido renovado com sucesso pelo outro processo.
        // Re-verificar antes de marcar como expired — evita que o segundo worker desfaça o
        // trabalho do primeiro.
        try {
            $stmt = $this->pdo()->prepare('SELECT status, token_expires_at FROM ml_accounts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $accountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (is_array($row)) {
                if (($row['status'] ?? null) === 'disconnected') {
                    // refreshToken() já marcou como disconnected — não sobrescrever
                    return false;
                }

                // O outro worker pode ter renovado o token com sucesso: re-checar expiração
                $freshExpiry = $row['token_expires_at'] ?? null;
                if ($freshExpiry) {
                    $freshSecondsLeft = strtotime($freshExpiry) - time();
                    if ($freshSecondsLeft > ($bufferMinutes * 60) && ($row['status'] ?? '') === 'active') {
                        // Token já está válido (renovado por outro worker) — não marcar como expired
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        try {
            $upd = $this->pdo()->prepare("UPDATE ml_accounts SET status = 'expired', updated_at = NOW() WHERE id = :id");
            $upd->execute(['id' => $accountId]);
        } catch (\Throwable $e) {
            try {
                (new StructuredLogService())->warning('Falha ao atualizar status ml_accounts para expired', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
                // não bloquear fluxo
            }
        }

        return false;
    }

    /**
     * Busca informações do usuário usando o access_token
     */
    private function fetchUserInfo(string $accessToken): array
    {
        $url = $this->getMercadoLivreApiUrl() . '/users/me';

        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'SEO-Optimizer/1.0',
        ];
        $this->applyCurlProxyOptions($curlOptions);
        curl_setopt_array($ch, $curlOptions);

        $resp = curl_exec($ch);
        $curlError = $resp === false ? curl_error($ch) : '';
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new \Exception('Falha ao obter informações do usuário ML: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new \Exception('Falha ao obter informações do usuário ML: HTTP ' . $httpCode . ' - ' . $resp);
        }

        $data = json_decode($resp, true);
        return is_array($data) ? $data : [];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Verifica se o token de uma conta precisa ser renovado
     * Retorna true se estiver expirando nos próximos 30 minutos
     */
    public function tokenNeedsRefresh(int $accountId, int $bufferMinutes = 30): bool
    {
        $stmt = $this->pdo()->prepare('SELECT token_expires_at FROM ml_accounts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $accountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['token_expires_at'])) {
            return true; // Sem data de expiração, precisa refresh
        }

        $expiresAt = strtotime($row['token_expires_at']);
        $bufferSeconds = $bufferMinutes * 60;
        $threshold = time() + $bufferSeconds;

        return $expiresAt <= $threshold;
    }

    /**
     * Retorna status detalhado do token de uma conta
     */
    public function getTokenStatus(int $accountId): array
    {
        $stmt = $this->pdo()->prepare('
            SELECT id, ml_user_id, nickname, token_expires_at, status, last_token_refresh
            FROM ml_accounts WHERE id = :id LIMIT 1
        ');
        $stmt->execute(['id' => $accountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'exists' => false,
                'status' => 'not_found',
                'expires_at' => null,
                'seconds_remaining' => 0,
            ];
        }

        $expiresAt = $row['token_expires_at'] ? strtotime($row['token_expires_at']) : null;
        $secondsRemaining = $expiresAt ? max(0, $expiresAt - time()) : 0;

        $tokenStatus = 'unknown';
        if ($secondsRemaining <= 0) {
            $tokenStatus = 'expired';
        } elseif ($secondsRemaining <= 1800) { // 30 min
            $tokenStatus = 'expiring_soon';
        } elseif ($secondsRemaining <= 3600) { // 1 hour
            $tokenStatus = 'expiring';
        } else {
            $tokenStatus = 'valid';
        }

        return [
            'exists' => true,
            'account_id' => (int)$row['id'],
            'ml_user_id' => $row['ml_user_id'],
            'nickname' => $row['nickname'],
            'account_status' => $row['status'],
            'expires_at' => $row['token_expires_at'],
            'seconds_remaining' => $secondsRemaining,
            'status' => $tokenStatus,
            'last_refresh' => $row['last_token_refresh'] ?? null,
        ];
    }

    private function applyCurlProxyOptions(array &$curlOptions): void
    {
        $enabledRaw = $_ENV['ML_PROXY_ENABLED'] ?? getenv('ML_PROXY_ENABLED') ?? null;
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            $curlOptions[CURLOPT_PROXY] = '';
            $curlOptions[CURLOPT_NOPROXY] = '*';
            return;
        }

        $type = (string)($_ENV['ML_PROXY_TYPE'] ?? getenv('ML_PROXY_TYPE') ?? 'http');
        $host = (string)($_ENV['ML_PROXY_HOST'] ?? getenv('ML_PROXY_HOST') ?? '');
        $port = (string)($_ENV['ML_PROXY_PORT'] ?? getenv('ML_PROXY_PORT') ?? '');
        $user = (string)($_ENV['ML_PROXY_USER'] ?? getenv('ML_PROXY_USER') ?? '');
        $pass = (string)($_ENV['ML_PROXY_PASS'] ?? getenv('ML_PROXY_PASS') ?? '');

        if ($host === '' || $port === '') {
            $curlOptions[CURLOPT_PROXY] = '';
            $curlOptions[CURLOPT_NOPROXY] = '*';
            return;
        }

        $curlOptions[CURLOPT_PROXY] = $host . ':' . $port;

        $scheme = strtolower(trim($type));
        if ($scheme === 'socks5' || $scheme === 'socks5h') {
            $curlOptions[CURLOPT_PROXYTYPE] = $scheme === 'socks5h' ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5;
        } else {
            $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
        }

        if ($user !== '') {
            $curlOptions[CURLOPT_PROXYUSERPWD] = $user . ':' . $pass;
        }
    }

    private function normalizeOAuthRedirectUri(string $redirect): string
    {
        $normalized = trim($redirect);

        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '//')) {
            return 'https:' . $normalized;
        }

        if (!preg_match('#^https?://#i', $normalized)) {
            return 'https://' . $normalized;
        }

        return $normalized;
    }

    private function isPlaceholderValue(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || $normalized === 'null') {
            return true;
        }

        foreach (
            [
                'your_mercadolibre_',
                'your-domain.com',
                'change_me_with_',
                'change_me',
                'example.com',
                'placeholder',
            ] as $token
        ) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }
}
