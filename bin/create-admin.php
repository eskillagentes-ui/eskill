<?php

declare(strict_types=1);

/**
 * Script CLI para criar usuário administrador
 *
 * Uso:
 *   php bin/create-admin.php
 *   php bin/create-admin.php --name="Jess" --email="admin@eskill.com.br" --password="SuaSenha123"
 *   php bin/create-admin.php --non-interactive --name="Admin" --email="admin@eskill.com.br" --password="Admin2026!"
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via CLI.');
}

// Definir constantes
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Autoload
require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/autoload.php';
require_once APP_PATH . '/Helpers/LogHelper.php';

// Carregar .env
if (file_exists(ROOT_PATH . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
    $dotenv->load();
}

// Cores para terminal
function green(string $text): string
{
    return "\033[32m{$text}\033[0m";
}
function red(string $text): string
{
    return "\033[31m{$text}\033[0m";
}
function yellow(string $text): string
{
    return "\033[33m{$text}\033[0m";
}
function cyan(string $text): string
{
    return "\033[36m{$text}\033[0m";
}
function bold(string $text): string
{
    return "\033[1m{$text}\033[0m";
}

echo "\n" . bold("═══════════════════════════════════════════") . "\n";
echo bold("  🔐 Criar Administrador — eskill.com.br") . "\n";
echo bold("═══════════════════════════════════════════") . "\n\n";

// Parse CLI arguments
$options = getopt('', ['name:', 'email:', 'password:', 'non-interactive', 'help']);

if (isset($options['help'])) {
    echo "Uso:\n";
    echo "  php bin/create-admin.php                              # Modo interativo\n";
    echo "  php bin/create-admin.php --name=\"Admin\" --email=\"x@y.com\" --password=\"Senha123\"  # Args diretos\n";
    echo "  php bin/create-admin.php --non-interactive ...        # Sem confirmação\n\n";
    exit(0);
}

$isNonInteractive = isset($options['non-interactive']);

// Função para ler input do terminal
function readInput(string $prompt, bool $hidden = false): string
{
    echo $prompt;
    if ($hidden && function_exists('readline')) {
        // Tentar ocultar senha no terminal
        system('stty -echo 2>/dev/null');
        $input = trim((string) fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
        return $input;
    }
    return trim((string) fgets(STDIN));
}

// Coletar dados
$name = $options['name'] ?? null;
$email = $options['email'] ?? null;
$password = $options['password'] ?? null;

if ($name === null && !$isNonInteractive) {
    $name = readInput(cyan("Nome completo: "));
}
if ($email === null && !$isNonInteractive) {
    $email = readInput(cyan("E-mail: "));
}
if ($password === null && !$isNonInteractive) {
    $password = readInput(cyan("Senha (mín. 8 chars, maiúsc+minúsc+número): "), true);
    $passwordConfirm = readInput(cyan("Confirme a senha: "), true);
    if ($password !== $passwordConfirm) {
        echo red("❌ As senhas não coincidem.") . "\n";
        exit(1);
    }
}

// Validações
$errors = [];

if (empty($name)) {
    $errors[] = 'Nome é obrigatório (--name="Seu Nome")';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'E-mail inválido (--email="seu@email.com")';
}

if (empty($password) || strlen($password) < 8) {
    $errors[] = 'Senha deve ter no mínimo 8 caracteres';
}

if (!empty($password)) {
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Senha deve conter ao menos 1 letra maiúscula';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Senha deve conter ao menos 1 letra minúscula';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Senha deve conter ao menos 1 número';
    }
}

if (!empty($errors)) {
    echo red("❌ Erros de validação:") . "\n";
    foreach ($errors as $error) {
        echo red("  • {$error}") . "\n";
    }
    exit(1);
}

// Cast to string after validation
$name = (string) $name;
$email = (string) $email;
$password = (string) $password;

// Confirmar
if (!$isNonInteractive) {
    echo "\n" . yellow("Resumo:") . "\n";
    echo "  Nome:  {$name}\n";
    echo "  Email: {$email}\n";
    echo "  Role:  admin\n\n";
    $confirm = readInput(yellow("Criar este usuário? (s/n): "));
    if (strtolower($confirm) !== 's' && strtolower($confirm) !== 'y') {
        echo yellow("Operação cancelada.") . "\n";
        exit(0);
    }
}

// Conectar ao banco
echo "\nConectando ao banco de dados... ";
try {
    $db = \App\Database::getInstance();
    echo green("✓") . "\n";
} catch (\Throwable $e) {
    echo red("✗") . "\n";
    echo red("Erro ao conectar: {$e->getMessage()}") . "\n";
    echo yellow("Verifique as variáveis DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD no .env") . "\n";
    exit(1);
}

// Verificar se e-mail já existe
echo "Verificando e-mail... ";
$stmt = $db->prepare("SELECT id, name FROM users WHERE email = :email");
$stmt->execute(['email' => $email]);
$existing = $stmt->fetch(\PDO::FETCH_ASSOC);

if ($existing) {
    echo yellow("⚠") . "\n";
    echo yellow("Usuário já existe: ID={$existing['id']}, Nome={$existing['name']}") . "\n";

    if (!$isNonInteractive) {
        $resetPw = readInput(yellow("Deseja resetar a senha deste usuário? (s/n): "));
        if (strtolower($resetPw) === 's' || strtolower($resetPw) === 'y') {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare("
                UPDATE users
                SET password = :password,
                    email_verified_at = COALESCE(email_verified_at, NOW()),
                    role = 'admin',
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['password' => $hashedPassword, 'id' => $existing['id']]);
            echo green("✅ Senha resetada com sucesso para o usuário ID={$existing['id']}") . "\n";
            echo green("   Role atualizada para: admin") . "\n";
            echo green("   E-mail verificado: sim") . "\n";
            exit(0);
        }
    }
    echo yellow("Nenhuma alteração feita.") . "\n";
    exit(0);
}
echo green("✓ (disponível)") . "\n";

// Criar usuário
echo "Criando administrador... ";
try {
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Verificar se a coluna role existe
    $columns = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetchAll();
    $hasRoleColumn = count($columns) > 0;

    // Verificar se a coluna verification_token existe
    $columns = $db->query("SHOW COLUMNS FROM users LIKE 'verification_token'")->fetchAll();
    $hasVerificationToken = count($columns) > 0;

    if ($hasRoleColumn && $hasVerificationToken) {
        $sql = "INSERT INTO users (name, email, password, role, email_verified_at, verification_token, created_at, updated_at)
                VALUES (:name, :email, :password, 'admin', NOW(), NULL, NOW(), NOW())";
    } elseif ($hasRoleColumn) {
        $sql = "INSERT INTO users (name, email, password, role, email_verified_at, created_at, updated_at)
                VALUES (:name, :email, :password, 'admin', NOW(), NOW(), NOW())";
    } else {
        $sql = "INSERT INTO users (name, email, password, email_verified_at, created_at, updated_at)
                VALUES (:name, :email, :password, NOW(), NOW(), NOW())";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'password' => $hashedPassword,
    ]);

    $userId = (int) $db->lastInsertId();
    echo green("✓") . "\n";

    echo "\n" . green("═══════════════════════════════════════════") . "\n";
    echo green("  ✅ Administrador criado com sucesso!") . "\n";
    echo green("═══════════════════════════════════════════") . "\n";
    echo "  ID:    {$userId}\n";
    echo "  Nome:  {$name}\n";
    echo "  Email: {$email}\n";
    echo "  Role:  admin\n";
    echo "  Verificado: sim (acesso imediato)\n\n";
    echo cyan("  Acesse: https://eskill.com.br/login") . "\n\n";

    // Log de auditoria (se disponível)
    try {
        $auditLog = new \App\Services\AuditLogService(db: $db, skipDbAutoConnect: true);
        $auditLog->log('admin_created_cli', $userId, null, [
            'description' => "Admin criado via CLI: {$name} <{$email}>",
        ]);
    } catch (\Throwable $e) {
        // Audit log is optional
    }
} catch (\Throwable $e) {
    echo red("✗") . "\n";
    echo red("Erro: {$e->getMessage()}") . "\n";
    exit(1);
}
