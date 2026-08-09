<?php

declare(strict_types=1);

/**
 * Script para desbloquear IP e criar/resetar admin
 *
 * Uso: php bin/fix-access.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via CLI.');
}

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/autoload.php';
require_once APP_PATH . '/Helpers/LogHelper.php';

if (file_exists(ROOT_PATH . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
    $dotenv->load();
}

echo "\n═══════════════════════════════════════════\n";
echo "  🔧 Fix Access — eskill.com.br\n";
echo "═══════════════════════════════════════════\n\n";

// 1. Conectar ao banco
echo "Conectando ao banco de dados... ";
try {
    $db = \App\Database::getInstance();
    echo "✓\n";
} catch (\Exception $e) {
    echo "✗\n";
    echo "Erro: {$e->getMessage()}\n";
    exit(1);
}

// 2. Desbloquear IP
$ip = '193.186.4.203';
echo "\n--- Desbloqueando IP {$ip} ---\n";
try {
    $affected = 0;

    $stmt = $db->prepare("SELECT id, reason, blocked_until, attempts FROM blocked_ips WHERE ip_address = :ip");
    $stmt->execute(['ip' => $ip]);
    $blocked = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($blocked) {
        echo "  blocked_ips: {$blocked['reason']} (tentativas: {$blocked['attempts']})\n";
        $stmt = $db->prepare("DELETE FROM blocked_ips WHERE ip_address = :ip");
        $stmt->execute(['ip' => $ip]);
        $affected += $stmt->rowCount();
    }

    $stmt = $db->prepare("SELECT id, reason, expires_at, failure_count FROM auth_blocked_ips WHERE ip_address = :ip");
    $stmt->execute(['ip' => $ip]);
    $authBlocked = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($authBlocked) {
        echo "  auth_blocked_ips: {$authBlocked['reason']} (falhas: {$authBlocked['failure_count']})\n";
        $stmt = $db->prepare("DELETE FROM auth_blocked_ips WHERE ip_address = :ip");
        $stmt->execute(['ip' => $ip]);
        $affected += $stmt->rowCount();
    }

    if ($affected > 0) {
        echo "  ✓ IP desbloqueado!\n";
    } else {
        echo "  IP não estava bloqueado nas tabelas conhecidas.\n";
    }
} catch (\Exception $e) {
    echo "  ⚠ Erro ao verificar tabelas de bloqueio: {$e->getMessage()}\n";
    echo "  (Tabela pode não existir — continuando...)\n";
}

// 3. Limpar todos IPs bloqueados expirados
try {
    $stmt = $db->prepare("DELETE FROM blocked_ips WHERE blocked_until IS NOT NULL AND blocked_until < NOW()");
    $stmt->execute();
    $cleaned = $stmt->rowCount();
    if ($cleaned > 0) {
        echo "  ✓ {$cleaned} bloqueios expirados limpos em blocked_ips.\n";
    }
} catch (\Exception $e) {
    // Silenciar se tabela não existir
}

try {
    $stmt = $db->prepare("DELETE FROM auth_blocked_ips WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    $stmt->execute();
    $cleaned = $stmt->rowCount();
    if ($cleaned > 0) {
        echo "  ✓ {$cleaned} bloqueios expirados limpos em auth_blocked_ips.\n";
    }
} catch (\Exception $e) {
    // Silenciar se tabela não existir
}

// 4. Criar/resetar admin
$email = 'admin@eskill.com.br';
$password = 'Awa@2026#Eskill!';
$name = 'Admin';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

echo "\n--- Configurando admin ({$email}) ---\n";
try {
    $stmt = $db->prepare("SELECT id, name, role, status FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($user) {
        echo "  Usuário existente: {$user['name']} (role: {$user['role']}, status: {$user['status']})\n";
        $stmt = $db->prepare("UPDATE users SET password = :password, role = 'admin', status = 'active', email_verified_at = COALESCE(email_verified_at, NOW()), updated_at = NOW() WHERE email = :email");
        $stmt->execute(['password' => $hash, 'email' => $email]);
        echo "  ✓ Senha resetada, status ativado e e-mail verificado!\n";
    } else {
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status, email_verified_at, created_at, updated_at) VALUES (:name, :email, :password, 'admin', 'active', NOW(), NOW(), NOW())");
        $stmt->execute(['name' => $name, 'email' => $email, 'password' => $hash]);
        echo "  ✓ Admin criado com sucesso!\n";
    }
} catch (\Exception $e) {
    echo "  ✗ Erro: {$e->getMessage()}\n";
    exit(1);
}

// 5. Resumo
echo "\n═══════════════════════════════════════════\n";
echo "  ✅ Tudo pronto!\n";
echo "═══════════════════════════════════════════\n";
echo "\n  E-mail: {$email}";
echo "\n  Senha:  {$password}";
echo "\n  URL:    https://eskill.com.br/login\n\n";
