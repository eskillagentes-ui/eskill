#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mercado Livre Integration Health Check — CLI
 *
 * Verifica rapidamente se a integração com ML está pronta para produção:
 * - Valida variáveis de ambiente críticas (incl. ML_WEBHOOK_SECRET)
 * - Lista contas vinculadas em ml_accounts (se MySQL disponível)
 * - Checa infraestrutura de webhooks (tabela webhook_event_inbox, HMAC secret)
 * - Prova /users/me e /users/{id}/items/search (quando rede e token estão disponíveis)
 * - (Opcional) testa endpoint interno /api/items via APP_URL
 *
 * Uso:
 *   php bin/ml-health-check.php
 *   php bin/ml-health-check.php --json
 *   php bin/ml-health-check.php --account-id=123
 *   php bin/ml-health-check.php --app-url=https://eskill.com.br --api-token=XXX
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Database;
use App\Services\MercadoLivreAuthService;
use App\Services\MercadoLivreClient;
use App\Services\StructuredLogService;

final class MercadoLivreHealthCheck
{
    private StructuredLogService $logger;
    private int $errors = 0;
    private int $warnings = 0;
    /** @var array<int, array{level:string, title:string, details?:array<string, mixed>}> */
    private array $checks = [];
    private bool $jsonOnly = false;

    /** @param array<string, mixed> $options */
    public function __construct(private array $options)
    {
        $this->logger = new StructuredLogService();
        $this->jsonOnly = !empty($this->options['json']);
    }

    public function run(): int
    {
        $startedAt = microtime(true);
        $this->logger->info('ML health-check started', [
            'options' => $this->options,
            'app_env' => $this->envString('APP_ENV', 'production'),
        ]);

        $this->header();

        $appEnv = strtolower($this->envString('APP_ENV', 'production'));
        $isTesting = $appEnv === 'testing';

        $this->checkEnv($appEnv);
        $this->checkOAuthEndpoints($appEnv);

        $db = $this->tryGetDb($appEnv);
        $accounts = [];
        if ($db instanceof \PDO) {
            $accounts = $this->loadAccounts($db);
        }

        $this->checkWebhookInfrastructure($appEnv, $db instanceof \PDO ? $db : null);
        $this->checkMlApiConnectivity($accounts, $isTesting);
        $this->checkInternalApiItemsEndpoint();

        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        $this->summary($durationMs);

        $this->logger->info('ML health-check finished', [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'duration_ms' => $durationMs,
        ]);

        return $this->errors > 0 ? 1 : 0;
    }

    private function header(): void
    {
        if ($this->jsonOnly) {
            return;
        }
        echo "\n══════════════════════════════════════════════\n";
        echo "  Mercado Livre — Health Check (CLI)\n";
        echo "══════════════════════════════════════════════\n\n";
    }

    private function summary(int $durationMs): void
    {
        if ($this->jsonOnly) {
            $payload = [
                'ok' => $this->errors === 0,
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'duration_ms' => $durationMs,
                'checks' => $this->checks,
            ];
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }

        echo "\n══════════════════════════════════════════════\n";
        if ($this->errors > 0) {
            echo "  ❌ {$this->errors} erro(s), {$this->warnings} aviso(s) — {$durationMs}ms\n";
        } elseif ($this->warnings > 0) {
            echo "  ⚠️  {$this->warnings} aviso(s) — {$durationMs}ms\n";
        } else {
            echo "  ✅ Tudo OK — {$durationMs}ms\n";
        }
        echo "══════════════════════════════════════════════\n\n";
    }

    private function ok(string $title, array $details = []): void
    {
        if (!$this->jsonOnly) {
            echo "  ✅ {$title}\n";
        }
        $this->checks[] = ['level' => 'ok', 'title' => $title, 'details' => $details];
    }

    private function warn(string $title, array $details = []): void
    {
        $this->warnings++;
        if (!$this->jsonOnly) {
            echo "  ⚠️  {$title}\n";
        }
        $this->checks[] = ['level' => 'warning', 'title' => $title, 'details' => $details];
    }

    private function fail(string $title, array $details = []): void
    {
        $this->errors++;
        if (!$this->jsonOnly) {
            echo "  ❌ {$title}\n";
        }
        $this->checks[] = ['level' => 'error', 'title' => $title, 'details' => $details];
    }

    private function info(string $title, array $details = []): void
    {
        if (!$this->jsonOnly) {
            echo "  ℹ️  {$title}\n";
        }
        $this->checks[] = ['level' => 'info', 'title' => $title, 'details' => $details];
    }

    private function checkEnv(string $appEnv): void
    {
        if (!$this->jsonOnly) {
            echo "── Config (env) ──\n";
        }

        $required = [
            'ML_APP_ID',
            'ML_CLIENT_SECRET',
            'ML_REDIRECT_URI',
            'APP_KEY',
        ];

        $missing = [];
        foreach ($required as $key) {
            $value = $this->envString($key, '');
            if ($value === '' || $this->isPlaceholderValue($value)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            $msg = 'Variáveis obrigatórias ausentes ou com placeholder: ' . implode(', ', $missing);
            if (in_array($appEnv, ['production', 'staging'], true)) {
                $this->fail($msg);
            } else {
                $this->warn($msg);
            }
        } else {
            $this->ok('Variáveis obrigatórias configuradas com valores não-placeholder');
        }

        $redirect = $this->envString('ML_REDIRECT_URI', '');
        if ($redirect !== '' && strpos($redirect, '/auth/callback') === false) {
            $this->warn('ML_REDIRECT_URI não parece apontar para /auth/callback', ['ml_redirect_uri' => $redirect]);
        }

        $hasEnvToken = $this->envString('ML_ACCESS_TOKEN', '') !== '';
        if ($hasEnvToken) {
            $this->ok('ML_ACCESS_TOKEN presente (modo simples habilitado)');
        } else {
            $this->info('ML_ACCESS_TOKEN ausente (ok se usar multi-conta via ml_accounts)');
        }

        $allowNetworkRaw = $_ENV['ML_ALLOW_NETWORK'] ?? getenv('ML_ALLOW_NETWORK') ?? null;
        $allowNetwork = filter_var($allowNetworkRaw, FILTER_VALIDATE_BOOLEAN);
        if ($appEnv === 'testing' && !$allowNetwork) {
            $this->info('Rede externa desabilitada em APP_ENV=testing (ML_ALLOW_NETWORK=false) — chamadas reais à API ML serão puladas');
        }

        try {
            $diagnostics = (new MercadoLivreAuthService())->getOAuthConfigDiagnostics();
            if ($diagnostics['ready'] ?? false) {
                $this->ok('Diagnóstico OAuth validado pelo serviço', [
                    'redirect_uri' => $diagnostics['details']['redirect_uri'] ?? null,
                ]);
            } else {
                $this->fail('Diagnóstico OAuth reprovado pelo serviço', [
                    'message' => $diagnostics['message'] ?? null,
                    'issues' => $diagnostics['issues'] ?? [],
                ]);
            }

            foreach (($diagnostics['warnings'] ?? []) as $warning) {
                $this->warn((string)$warning);
            }
        } catch (\Throwable $e) {
            $this->fail('Falha ao executar diagnóstico OAuth interno', [
                'error' => $e->getMessage(),
            ]);
        }

        if (!$this->jsonOnly) {
            echo "\n";
        }
    }

    private function checkOAuthEndpoints(string $appEnv): void
    {
        if (!$this->jsonOnly) {
            echo "── OAuth / SSL / Endpoints ──\n";
        }

        if (!$this->isNetworkAllowed()) {
            $this->info('Rede externa desabilitada — pulando probes HTTP de OAuth/SSL');
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return;
        }

        try {
            $diagnostics = (new MercadoLivreAuthService())->getOAuthConfigDiagnostics();
        } catch (\Throwable $e) {
            $this->fail('Falha ao carregar endpoints OAuth para probe', ['error' => $e->getMessage()]);
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return;
        }

        $details = is_array($diagnostics['details'] ?? null) ? $diagnostics['details'] : [];
        $authUrl = (string)($details['auth_url'] ?? '');
        $apiUrl = rtrim((string)($details['api_url'] ?? ''), '/');
        $callbackUrl = (string)($details['redirect_uri'] ?? '');

        if ($authUrl !== '') {
            $this->probeReachability($authUrl, 'OAuth authorization endpoint');
        }

        if ($apiUrl !== '') {
            $this->probeReachability($apiUrl . '/sites/MLB', 'Mercado Livre API endpoint');
        }

        $issues = is_array($diagnostics['issues'] ?? null) ? $diagnostics['issues'] : [];
        $hasRedirectIssue = array_filter($issues, static fn($issue): bool => is_string($issue) && str_contains($issue, 'ML_REDIRECT_URI'));

        if ($callbackUrl !== '' && $hasRedirectIssue === []) {
            $this->probeReachability($callbackUrl, 'Aplicação callback endpoint');
        } elseif (in_array($appEnv, ['production', 'staging'], true)) {
            $this->fail('ML_REDIRECT_URI inválido — callback não pode ser validado com segurança');
        }

        if (!$this->jsonOnly) {
            echo "\n";
        }
    }

    private function tryGetDb(string $appEnv): ?\PDO
    {
        if (!$this->jsonOnly) {
            echo "── Database ──\n";
        }

        try {
            $pdo = Database::getInstance();
            $this->ok('MySQL conectado');
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return $pdo;
        } catch (\Throwable $e) {
            $msg = 'MySQL indisponível';
            $details = ['error' => $e->getMessage()];
            if (in_array($appEnv, ['production', 'staging'], true)) {
                $this->fail($msg, $details);
            } else {
                $this->warn($msg, $details);
            }
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function loadAccounts(\PDO $db): array
    {
        if (!$this->jsonOnly) {
            echo "── Contas vinculadas (ml_accounts) ──\n";
        }

        try {
            $stmt = $db->query(
                "SELECT id, user_id, ml_user_id, nickname, status, token_expires_at, tokens_encrypted,\n" .
                    "last_refresh_error, refresh_failure_count, last_refresh_at, last_oauth_connection_at, created_at, updated_at\n" .
                    "FROM ml_accounts\n" .
                    "ORDER BY id"
            );
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Compatibilidade com esquemas antigos: fallback sem colunas novas.
            try {
                $stmt = $db->query(
                    "SELECT id, user_id, ml_user_id, nickname, status, token_expires_at, tokens_encrypted,\n" .
                        "NULL AS last_refresh_error, NULL AS refresh_failure_count, NULL AS last_refresh_at, NULL AS last_oauth_connection_at, created_at, updated_at\n" .
                        "FROM ml_accounts\n" .
                        "ORDER BY id"
                );
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $this->warn('ml_accounts sem campos de tracking (last_refresh_at/refresh_failure_count). Considere aplicar migrations.', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $inner) {
                $this->warn('Não foi possível ler ml_accounts (migrations pendentes?)', ['error' => $inner->getMessage()]);
                if (!$this->jsonOnly) {
                    echo "\n";
                }
                return [];
            }
        }

        if (empty($rows)) {
            $this->warn('Nenhuma conta ML vinculada — vincule em /auth/authorize');
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return [];
        }

        $disconnected = 0;
        $estimatedExpiringSoon = 0;
        $estimatedExpired = 0;

        $ttlDays = max(1, (int)$this->envString('ML_REFRESH_TOKEN_TTL_DAYS', '180'));
        $warnDays = max(1, (int)$this->envString('ML_REFRESH_TOKEN_EXPIRY_WARN_DAYS', '7'));

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $nick = (string)($row['nickname'] ?? 'N/A');
            $status = (string)($row['status'] ?? 'unknown');
            $expiresAt = $row['token_expires_at'] ?? null;
            $enc = !empty($row['tokens_encrypted']);
            $lastErr = (string)($row['last_refresh_error'] ?? '');
            $failureCount = isset($row['refresh_failure_count']) ? (int)$row['refresh_failure_count'] : 0;
            $lastRefreshAt = isset($row['last_refresh_at']) ? (string)$row['last_refresh_at'] : '';
            $lastOauthAt = isset($row['last_oauth_connection_at']) ? (string)$row['last_oauth_connection_at'] : '';
            $updatedAt = isset($row['updated_at']) ? (string)$row['updated_at'] : '';
            $createdAt = isset($row['created_at']) ? (string)$row['created_at'] : '';

            $flags = [];
            if ($enc) {
                $flags[] = 'encrypted';
            }
            if (is_string($expiresAt) && $expiresAt !== '' && strtotime($expiresAt) < time()) {
                $flags[] = 'EXPIRED';
            }
            if ($status === 'disconnected' || stripos($lastErr, 'invalid_grant') !== false) {
                $flags[] = 'DISCONNECTED';
                $disconnected++;
            }

            // Best-effort: estimativa de validade do refresh token.
            // Como o ML não expõe um "refresh_token_expires_at" no nosso schema, estimamos por atividade.
            $activityBase = '';
            if ($lastRefreshAt !== '' && strtotime($lastRefreshAt) !== false) {
                $activityBase = $lastRefreshAt;
            } elseif ($lastOauthAt !== '' && strtotime($lastOauthAt) !== false) {
                $activityBase = $lastOauthAt;
            } elseif ($updatedAt !== '' && strtotime($updatedAt) !== false) {
                $activityBase = $updatedAt;
            } elseif ($createdAt !== '' && strtotime($createdAt) !== false) {
                $activityBase = $createdAt;
            }

            if ($activityBase !== '') {
                $baseTs = (int)strtotime($activityBase);
                $estimatedExpiryTs = $baseTs + ($ttlDays * 86400);
                $secondsLeft = $estimatedExpiryTs - time();
                $daysLeft = (int)floor($secondsLeft / 86400);

                if ($daysLeft < 0) {
                    $flags[] = 'REFRESH_EXPIRED?';
                    $estimatedExpired++;
                } elseif ($daysLeft < $warnDays) {
                    $flags[] = 'REFRESH_EXPIRING_SOON';
                    $estimatedExpiringSoon++;
                }
            }

            if ($failureCount > 0) {
                $flags[] = 'REFRESH_FAILS=' . $failureCount;
            }

            $flagStr = empty($flags) ? '' : (' [' . implode(', ', $flags) . ']');
            if (!$this->jsonOnly) {
                echo "     [{$id}] {$nick} status={$status}{$flagStr}\n";
            }
        }

        $this->ok(count($rows) . ' conta(s) encontrada(s) em ml_accounts');
        if ($disconnected > 0) {
            $this->warn("{$disconnected} conta(s) desconectada(s) — reconecte via /auth/authorize?reconnect={id}");
        }

        if ($estimatedExpired > 0) {
            $this->warn('Estimativa: ' . $estimatedExpired . ' conta(s) com refresh token possivelmente expirado (atividade > ' . $ttlDays . ' dias)', [
                'ttl_days' => $ttlDays,
                'warn_days' => $warnDays,
                'note' => 'Estimativa baseada em last_refresh_at/last_oauth_connection_at; em caso de dúvida, reconecte a conta via OAuth.',
            ]);
        } elseif ($estimatedExpiringSoon > 0) {
            $this->warn('Estimativa: ' . $estimatedExpiringSoon . ' conta(s) com refresh token expiring soon (< ' . $warnDays . ' dias)', [
                'ttl_days' => $ttlDays,
                'warn_days' => $warnDays,
                'note' => 'Estimativa baseada em last_refresh_at/last_oauth_connection_at; em caso de dúvida, reconecte a conta via OAuth.',
            ]);
        } else {
            $this->ok('Refresh token: nenhuma conta com expiração iminente (estimativa por atividade)', [
                'ttl_days' => $ttlDays,
                'warn_days' => $warnDays,
            ]);
        }

        if (!$this->jsonOnly) {
            echo "\n";
        }
        return $rows;
    }

    /** @param array<int, array<string, mixed>> $accounts */
    private function checkMlApiConnectivity(array $accounts, bool $isTesting): void
    {
        if (!$this->jsonOnly) {
            echo "── Mercado Livre API (/users/me, /items) ──\n";
        }

        $allowNetwork = $this->isNetworkAllowed();
        if (!$allowNetwork) {
            $this->info('Rede externa desabilitada — pulando probes reais (ML_ALLOW_NETWORK=false em testing)');
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return;
        }

        $accountIdOpt = $this->options['account_id'] ?? null;
        $allAccounts = !empty($this->options['all_accounts']);

        $accountIds = [];
        if (is_int($accountIdOpt) && $accountIdOpt > 0) {
            $accountIds = [$accountIdOpt];
        } elseif (!empty($accounts)) {
            foreach ($accounts as $a) {
                $id = (int)($a['id'] ?? 0);
                if ($id > 0) {
                    $accountIds[] = $id;
                }
            }
            if (!$allAccounts && !empty($accountIds)) {
                $accountIds = [$accountIds[0]];
            }
        }

        $hasEnvToken = $this->envString('ML_ACCESS_TOKEN', '') !== '';

        if (empty($accountIds) && !$hasEnvToken) {
            $this->warn('Sem conta vinculada (ml_accounts) e sem ML_ACCESS_TOKEN — não há token para testar /users/me');
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return;
        }

        if (empty($accountIds) && $hasEnvToken) {
            // single-token mode
            $this->probeClient(new MercadoLivreClient(null), 'env');
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return;
        }

        $authService = null;
        try {
            $authService = new MercadoLivreAuthService();
        } catch (\Throwable $e) {
            // best-effort
            $authService = null;
            $this->warn('Não foi possível inicializar MercadoLivreAuthService (refresh automático pode falhar)', [
                'error' => $e->getMessage(),
            ]);
        }

        $max = 10;
        if (count($accountIds) > $max) {
            $this->warn('Muitas contas para probe; limitando', ['total' => count($accountIds), 'limit' => $max]);
            $accountIds = array_slice($accountIds, 0, $max);
        }

        foreach ($accountIds as $id) {
            try {
                $client = new MercadoLivreClient($id, $authService instanceof MercadoLivreAuthService ? $authService : null);
                $this->probeClient($client, 'account_id=' . $id);
            } catch (\Throwable $e) {
                $this->fail('Falha ao instanciar MercadoLivreClient para account ' . $id, ['error' => $e->getMessage()]);
            }
        }

        if (!$this->jsonOnly) {
            echo "\n";
        }
    }

    private function probeClient(MercadoLivreClient $client, string $label): void
    {
        try {
            $me = $client->getMe();
            if (isset($me['error'])) {
                $this->warn('ML /users/me falhou (' . $label . ')', [
                    'error' => (string)($me['error'] ?? 'unknown'),
                    'message' => (string)($me['message'] ?? ''),
                    'status' => $me['status'] ?? null,
                    'reconnect_url' => $me['reconnect_url'] ?? null,
                ]);
                return;
            }

            $mlUserId = $me['id'] ?? null;
            $nickname = $me['nickname'] ?? null;
            $this->ok('ML /users/me OK (' . $label . ')', [
                'ml_user_id' => $mlUserId,
                'nickname' => $nickname,
            ]);
        } catch (\Throwable $e) {
            $this->fail('ML /users/me exception (' . $label . ')', ['error' => $e->getMessage()]);
            return;
        }

        // Probe a lightweight items endpoint (1 result) to validate scope + seller resolution.
        try {
            $items = $client->getMyItems(['limit' => 1, 'status' => 'active']);
            if (isset($items['error'])) {
                $this->warn('ML /users/{id}/items/search falhou (' . $label . ')', [
                    'error' => (string)($items['error'] ?? 'unknown'),
                    'message' => (string)($items['message'] ?? ''),
                    'status' => $items['status'] ?? null,
                ]);
                return;
            }

            $total = null;
            if (isset($items['paging']) && is_array($items['paging']) && isset($items['paging']['total'])) {
                $total = $items['paging']['total'];
            }
            $this->ok('ML items search OK (' . $label . ')', [
                'paging_total' => $total,
            ]);
        } catch (\Throwable $e) {
            $this->warn('ML items search exception (' . $label . ')', ['error' => $e->getMessage()]);
        }
    }

    private function checkWebhookInfrastructure(string $appEnv, ?\PDO $db): void
    {
        if (!$this->jsonOnly) {
            echo "── Webhook infrastructure ──\n";
        }

        $secret = $this->envString('ML_WEBHOOK_SECRET', '');
        if ($secret === '' || $this->isPlaceholderValue($secret)) {
            $msg = 'ML_WEBHOOK_SECRET ausente ou com placeholder — validação HMAC de webhooks desabilitada';
            if (in_array($appEnv, ['production', 'staging'], true)) {
                $this->fail($msg);
            } else {
                $this->warn($msg);
            }
        } else {
            $this->ok('ML_WEBHOOK_SECRET configurado — HMAC habilitado');
        }

        if ($db === null) {
            $this->info('DB indisponível — pulando check da tabela webhook_event_inbox');
        } else {
            try {
                $db->query('SELECT 1 FROM webhook_event_inbox LIMIT 1');
                $this->ok('Tabela webhook_event_inbox acessível');
            } catch (\Throwable $e) {
                $msg = 'Tabela webhook_event_inbox inacessível (migrations pendentes?)';
                $details = ['error' => $e->getMessage()];
                if (in_array($appEnv, ['production', 'staging'], true)) {
                    $this->fail($msg, $details);
                } else {
                    $this->warn($msg, $details);
                }
            }
        }

        if (!$this->jsonOnly) {
            echo "\n";
        }
    }

    private function checkInternalApiItemsEndpoint(): void
    {
        if (!$this->jsonOnly) {
            echo "── API interna (/api/items) ──\n";
        }

        if (!empty($this->options['skip_internal_api'])) {
            $this->info('Check interno pulado (--skip-internal-api)');
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return;
        }

        $appUrl = $this->options['app_url'] ?? $this->envString('APP_URL', '');
        if (!is_string($appUrl) || trim($appUrl) === '') {
            $this->info('APP_URL não configurado — pulando check do endpoint interno');
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return;
        }
        $appUrl = rtrim(trim($appUrl), '/');
        $url = $appUrl . '/api/items?limit=1';

        if (!$this->isNetworkAllowed()) {
            $this->info('Rede externa desabilitada em testing — pulando check HTTP para ' . $url);
            if (!$this->jsonOnly) {
                echo "\n";
            }
            return;
        }

        $apiToken = (string)($this->options['api_token'] ?? $this->envString('ML_HEALTHCHECK_API_TOKEN', ''));

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'SEO-Optimizer-ML-HealthCheck/1.0',
        ];
        if ($apiToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiToken;
        }

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 20,
                'headers' => $headers,
                'http_errors' => false,
            ]);
            $resp = $client->get($url);
            $status = $resp->getStatusCode();
            $body = (string)$resp->getBody();

            if ($status === 200) {
                $this->ok('GET /api/items OK (200)', ['url' => $url]);
            } elseif (in_array($status, [401, 403], true) && $apiToken === '') {
                $this->ok('GET /api/items protegido (401/403 sem token) — esperado', ['url' => $url, 'status' => $status]);
                $this->info('Dica: passe --api-token=... para validar resposta 200 em produção');
            } else {
                $preview = $body !== '' ? substr($body, 0, 300) : '';
                $this->warn('GET /api/items retornou ' . $status, ['url' => $url, 'preview' => $preview]);
            }
        } catch (\Throwable $e) {
            $this->warn('Falha ao chamar /api/items (HTTP)', ['url' => $url, 'error' => $e->getMessage()]);
        }

        if (!$this->jsonOnly) {
            echo "\n";
        }
    }

    private function probeReachability(string $url, string $label): void
    {
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 15,
                'http_errors' => false,
                'allow_redirects' => false,
                'verify' => true,
                'proxy' => false,
                'headers' => [
                    'User-Agent' => 'SEO-Optimizer-ML-HealthCheck/1.0',
                    'Accept' => 'application/json,text/html;q=0.9,*/*;q=0.8',
                ],
            ]);

            $resp = $client->request('GET', $url);
            $status = $resp->getStatusCode();

            $this->ok($label . ' alcançável', [
                'url' => $url,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            $this->fail($label . ' indisponível', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function envString(string $key, string $default = ''): string
    {
        $val = $_ENV[$key] ?? getenv($key) ?? '';
        if (!is_string($val)) {
            return $default;
        }
        $val = trim($val);
        return $val !== '' ? $val : $default;
    }

    private function isNetworkAllowed(): bool
    {
        $env = strtolower($this->envString('APP_ENV', 'production'));
        if ($env !== 'testing') {
            return true;
        }
        $allow = $_ENV['ML_ALLOW_NETWORK'] ?? getenv('ML_ALLOW_NETWORK') ?? null;
        return filter_var($allow, FILTER_VALIDATE_BOOLEAN);
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

/**
 * @return array<string, mixed>
 */
function parseMlHealthArgs(array $argv): array
{
    $opts = [
        'json' => false,
        'all_accounts' => false,
        'skip_internal_api' => false,
        'account_id' => null,
        'api_token' => null,
        'app_url' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "\nUso: php bin/ml-health-check.php [--json] [--account-id=ID] [--all-accounts] [--app-url=URL] [--api-token=TOKEN] [--skip-internal-api]\n\n";
            exit(0);
        }
        if ($arg === '--json') {
            $opts['json'] = true;
            continue;
        }
        if ($arg === '--all-accounts') {
            $opts['all_accounts'] = true;
            continue;
        }
        if ($arg === '--skip-internal-api') {
            $opts['skip_internal_api'] = true;
            continue;
        }
        if (strpos($arg, '--account-id=') === 0) {
            $v = (string)substr($arg, strlen('--account-id='));
            $id = (int)$v;
            if ($id > 0) {
                $opts['account_id'] = $id;
            }
            continue;
        }
        if (strpos($arg, '--api-token=') === 0) {
            $opts['api_token'] = (string)substr($arg, strlen('--api-token='));
            continue;
        }
        if (strpos($arg, '--app-url=') === 0) {
            $opts['app_url'] = (string)substr($arg, strlen('--app-url='));
            continue;
        }
    }

    return $opts;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = parseMlHealthArgs($argv);
    $check = new MercadoLivreHealthCheck($options);
    exit($check->run());
}
