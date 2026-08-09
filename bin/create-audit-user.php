<?php

declare(strict_types=1);

/**
 * Create audit user with read-only (viewer) role
 * 
 * Usage: php bin/create-audit-user.php
 * 
 * Creates the user auditoria@eskill.com.br with viewer role,
 * giving read-only access to Dashboard, Relatórios and Auditoria.
 */

use App\Database;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

try {
    $db = Database::getInstance();

    $email = 'auditoria@eskill.com.br';
    $name = 'Auditoria';
    $role = 'viewer';

    $passwordFromEnv = $_ENV['AUDIT_USER_PASSWORD'] ?? null;
    if ($passwordFromEnv !== null && !is_string($passwordFromEnv)) {
        $passwordFromEnv = null;
    }

    $generatedPassword = null;
    if ($passwordFromEnv !== null) {
        $passwordFromEnv = trim($passwordFromEnv);
        if (strlen($passwordFromEnv) < 12) {
            throw new \RuntimeException('AUDIT_USER_PASSWORD deve ter pelo menos 12 caracteres');
        }
    } else {
        // 32 hex chars (~128 bits) — sem caracteres especiais para evitar problemas em copy/paste
        $generatedPassword = bin2hex(random_bytes(16));
    }

    $passwordToSet = $passwordFromEnv ?? $generatedPassword;
    if (!is_string($passwordToSet) || $passwordToSet === '') {
        throw new \RuntimeException('Falha ao determinar senha do usuário de auditoria');
    }

    // Check if user already exists
    $stmt = $db->prepare("SELECT id, role FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($existing) {
        echo "Usuário {$email} já existe (ID: {$existing['id']}, role: {$existing['role']}).\n";
        echo "Atualizando role para '{$role}'...\n";
        $stmt = $db->prepare("UPDATE users SET role = :role, status = 'active' WHERE id = :id");
        $stmt->execute(['role' => $role, 'id' => $existing['id']]);
        echo "Role atualizado com sucesso.\n";
        exit(0);
    }

    // Verificar se o banco já suporta a role 'viewer' (via migration 2026_02_20_add_viewer_role_to_users.sql)
    $roleColumn = $db->query("SHOW COLUMNS FROM users LIKE 'role'")?->fetch(\PDO::FETCH_ASSOC);
    $roleType = is_array($roleColumn) ? (string)($roleColumn['Type'] ?? '') : '';
    if ($roleType !== '' && stripos($roleType, "'viewer'") === false) {
        throw new \RuntimeException(
            "A coluna users.role não inclui 'viewer'. Aplique a migration database/migrations/2026_02_20_add_viewer_role_to_users.sql antes de continuar."
        );
    }

    // Hash password with bcrypt cost 12
    $hashedPassword = password_hash($passwordToSet, PASSWORD_BCRYPT, ['cost' => 12]);
    if (!is_string($hashedPassword) || $hashedPassword === '') {
        throw new \RuntimeException('Falha ao gerar hash da senha');
    }

    $stmt = $db->prepare("
        INSERT INTO users (name, email, password, role, status, email_verified_at, created_at, updated_at)
        VALUES (:name, :email, :password, :role, 'active', NOW(), NOW(), NOW())
    ");
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'password' => $hashedPassword,
        'role' => $role,
    ]);

    $userId = (int) $db->lastInsertId();

    echo "=== Usuário de auditoria criado com sucesso ===\n";
    echo "ID:    {$userId}\n";
    echo "Nome:  {$name}\n";
    echo "Email: {$email}\n";
    echo "Role:  {$role}\n";

    if ($generatedPassword !== null) {
        echo "Senha (gerada): {$generatedPassword}\n";
        echo "\n⚠️  IMPORTANTE: Troque a senha no primeiro login!\n";
    } else {
        echo "Senha: definida via AUDIT_USER_PASSWORD (não exibida por segurança)\n";
    }
} catch (\Exception $e) {
    echo "Erro ao criar usuário: " . $e->getMessage() . "\n";
    exit(1);
}
