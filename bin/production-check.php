#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Production Health Check — CLI
 * Verifica todos os serviços necessários para o sistema funcionar.
 *
 * Uso: php bin/production-check.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$errors = 0;
$warnings = 0;

function ok(string $msg): void
{
    echo "  ✅ {$msg}\n";
}

function fail(string $msg): void
{
    global $errors;
    echo "  ❌ {$msg}\n";
    $errors++;
}

function warn(string $msg): void
{
    global $warnings;
    echo "  ⚠️  {$msg}\n";
    $warnings++;
}

function info(string $msg): void
{
    echo "  ℹ️  {$msg}\n";
}

// 1. MySQL
echo "\n── MySQL ──\n";
try {
    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $port = $_ENV['DB_PORT'] ?? '3306';
    $dbname = $_ENV['DB_DATABASE'] ?? 'meli';
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USERNAME'] ?? '', $_ENV['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    ok("MySQL conectado — {$dbname} ({$host}:{$port}) — " . count($tables) . " tabelas");

    $critical = ['users', 'ml_accounts', 'items', 'ml_orders', 'notifications'];
    $missing = array_diff($critical, $tables);
    if (!empty($missing)) {
        warn("Tabelas faltando: " . implode(', ', $missing) . " — rode: php bin/migrate.php");
    } else {
        ok("Tabelas críticas presentes");
    }

    // Check observability/monitoring tables (ML-004)
    $monitoring = ['worker_execution_logs', 'clone_health_logs', 'clone_duplicate_registry', 'clone_sync_logs'];
    $missingMonitor = array_diff($monitoring, $tables);
    if (!empty($missingMonitor)) {
        warn("Tabelas de monitoramento ausentes: " . implode(', ', $missingMonitor) . " — rode: mysql -u \$DB_USERNAME -p\$DB_PASSWORD \$DB_DATABASE < database/migrations/2026_02_26_000001_stabilize_production_schema.sql");
    } else {
        ok("Tabelas de monitoramento presentes");
    }

    // Check ML accounts
    try {
        $stmt = $pdo->query(
            'SELECT id, nickname, status, access_token IS NOT NULL as has_token, token_expires_at FROM ml_accounts ORDER BY id'
        );
        $accounts = $stmt->fetchAll();
        if (empty($accounts)) {
            warn("Nenhuma conta ML vinculada — acesse /auth/authorize para vincular");
        } else {
            ok(count($accounts) . " conta(s) ML vinculada(s):");
            $disconnectedCount = 0;
            foreach ($accounts as $a) {
                $tokenStatus = $a['has_token'] ? 'token=yes' : 'token=NO';
                if ($a['token_expires_at'] && strtotime($a['token_expires_at']) < time()) {
                    $tokenStatus .= ' EXPIRED';
                }
                if ($a['status'] === 'disconnected') {
                    $tokenStatus .= ' *** DISCONNECTED — reauth required ***';
                    $disconnectedCount++;
                }
                echo "     [{$a['id']}] {$a['nickname']} status={$a['status']} {$tokenStatus}\n";
            }
            if ($disconnectedCount > 0) {
                warn("{$disconnectedCount} conta(s) com status=disconnected — acesse /auth/authorize para reconectar (ML-001)");
            }
        }
    } catch (Throwable $e) {
        info("Tabela ml_accounts não encontrada (rode migrations)");
    }

    // Check users
    try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $userCount = (int)$stmt->fetchColumn();
        if ($userCount === 0) {
            warn("Nenhum usuário cadastrado — acesse /register para criar conta");
        } else {
            ok("{$userCount} usuário(s) cadastrado(s)");
        }
    } catch (Throwable $e) {
        info("Tabela users não encontrada");
    }
} catch (PDOException $e) {
    fail("MySQL: " . $e->getMessage());
    $pdo = null;
}

// 2. Redis
echo "\n── Redis ──\n";
try {
    if (!extension_loaded('redis')) {
        warn("Extensão redis não instalada — cache desabilitado");
    } else {
        $redis = new Redis();
        $redis->connect(
            $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            (int)($_ENV['REDIS_PORT'] ?? 6379),
            2.0
        );
        $password = $_ENV['REDIS_PASSWORD'] ?? '';
        if (!empty($password) && $password !== 'null') {
            $redis->auth($password);
        }
        $pong = $redis->ping();
        ok("Redis conectado — PING=" . ($pong ?: 'PONG'));
    }
} catch (Throwable $e) {
    warn("Redis: " . $e->getMessage() . " — sistema funciona sem Redis");
}

// 3. PHP-FPM
echo "\n── PHP-FPM ──\n";
$fp = @fsockopen('127.0.0.1', 19001, $errno, $errstr, 2);
if ($fp) {
    fclose($fp);
    ok("PHP-FPM rodando em 127.0.0.1:19001");
} elseif (file_exists('/run/php/php8.4-fpm.sock')) {
    ok("PHP-FPM rodando via socket");
} else {
    fail("PHP-FPM não detectado — o site não responderá");
    info("Reinicie PHP-FPM via painel CloudWays ou: service php8.4-fpm restart");
}

// 4. Nginx
echo "\n── Nginx ──\n";
exec('pgrep -x nginx 2>/dev/null', $output, $ret);
if ($ret === 0) {
    ok("Nginx rodando");
} else {
    fail("Nginx não está rodando");
    info("Reinicie via CloudWays ou: nginx");
}

// 5. PHP Extensions
echo "\n── PHP Extensions ──\n";
$required = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring', 'openssl'];
$optional = ['redis', 'gd', 'imagick', 'zip'];
foreach ($required as $ext) {
    extension_loaded($ext) ? ok($ext) : fail("{$ext} FALTANDO");
}
foreach ($optional as $ext) {
    extension_loaded($ext) ? ok("{$ext} (opcional)") : info("{$ext} não instalado (opcional)");
}

// 6. ML API Config
echo "\n── Mercado Livre API ──\n";
$mlVars = [
    'ML_APP_ID' => $_ENV['ML_APP_ID'] ?? '',
    'ML_CLIENT_SECRET' => $_ENV['ML_CLIENT_SECRET'] ?? '',
    'ML_REDIRECT_URI' => $_ENV['ML_REDIRECT_URI'] ?? '',
];
$mlOk = true;
foreach ($mlVars as $key => $val) {
    if (empty($val)) {
        fail("{$key} não configurado no .env");
        $mlOk = false;
    }
}
if ($mlOk) {
    ok("ML credentials configuradas");
    info("Redirect URI: " . $mlVars['ML_REDIRECT_URI']);
}

// 7. OpenAI
echo "\n── OpenAI ──\n";
$openaiKey = $_ENV['OPENAI_API_KEY'] ?? '';
if (!empty($openaiKey) && strlen($openaiKey) > 10) {
    ok("OPENAI_API_KEY configurada (" . strlen($openaiKey) . " chars)");
} else {
    warn("OPENAI_API_KEY não configurada — geração IA desabilitada");
}

// 8. Telegram
echo "\n── Telegram ──\n";
$tgEnabled = filter_var($_ENV['TELEGRAM_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
$tgToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
$tgChat = $_ENV['TELEGRAM_CHAT_ID'] ?? '';
if ($tgEnabled && !empty($tgToken) && !empty($tgChat)) {
    ok("Telegram configurado e habilitado");
} elseif (!$tgEnabled) {
    info("Telegram desabilitado (TELEGRAM_ENABLED=false)");
} else {
    warn("Telegram habilitado mas faltam BOT_TOKEN ou CHAT_ID");
}

// 9. Disk
echo "\n── Disco ──\n";
$projectPath = __DIR__ . '/..';
$free = @disk_free_space($projectPath) ?: @disk_free_space('/');
if ($free) {
    $freeGB = round($free / 1024 / 1024 / 1024, 2);
    if ($freeGB < 1) {
        fail("Disco com apenas {$freeGB} GB livre");
    } elseif ($freeGB < 5) {
        warn("Disco com {$freeGB} GB livre");
    } else {
        ok("Disco: {$freeGB} GB livre");
    }
} else {
    info("Não foi possível verificar espaço em disco");
}

// 10. Process user
echo "\n── Processo ──\n";
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    warn("Processo rodando como root — use um usuário de aplicação com grants mínimos em produção");
} elseif (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
    $userInfo = posix_getpwuid(posix_getuid());
    ok("Rodando como usuário: " . ($userInfo['name'] ?? 'desconhecido'));
} else {
    info("Não foi possível verificar usuário do processo");
}

// Summary
echo "\n══════════════════════════════════════════════\n";
if ($errors > 0) {
    echo "  ❌ {$errors} erros, {$warnings} avisos\n";
} elseif ($warnings > 0) {
    echo "  ⚠️  {$warnings} avisos — sistema pode funcionar\n";
} else {
    echo "  ✅ Tudo OK — sistema pronto!\n";
}
echo "══════════════════════════════════════════════\n\n";

exit($errors);
