<?php

/**
 * Reseta a senha do admin diretamente no banco.
 * Uso: php bin/reset-admin-password.php [email] [nova_senha]
 * Exemplo: php bin/reset-admin-password.php admin@eskill.com.br MinhaNovasenha@123
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

$email = $argv[1] ?? 'admin@eskill.com.br';
$newPassword = $argv[2] ?? null;

if (!$newPassword) {
    echo "Uso: php bin/reset-admin-password.php [email] [nova_senha]\n";
    echo "Exemplo: php bin/reset-admin-password.php admin@eskill.com.br MinhaNovasenha@123\n";
    exit(1);
}

try {
    $db = \App\Database::getInstance();

    // Verificar usuário existente
    $stmt = $db->prepare("SELECT id, name, email, role, status FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$user) {
        echo "❌ Usuário não encontrado: {$email}\n";
        echo "   Usuários existentes:\n";
        $all = $db->query("SELECT id, name, email, role, status FROM users LIMIT 10")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($all as $u) {
            echo "   - [{$u['id']}] {$u['email']} (role: {$u['role']}, status: {$u['status']})\n";
        }
        exit(1);
    }

    echo "✓ Usuário encontrado: {$user['name']} (id: {$user['id']}, role: {$user['role']}, status: {$user['status']})\n";

    // Gerar hash bcrypt cost=12
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    // Atualizar senha + garantir conta ativa e e-mail verificado
    $stmt = $db->prepare("
        UPDATE users
        SET password = :password,
            status = 'active',
            email_verified_at = COALESCE(email_verified_at, NOW()),
            updated_at = NOW()
        WHERE email = :email
    ");
    $stmt->execute(['password' => $hash, 'email' => $email]);

    echo "✓ Senha atualizada com sucesso!\n";

    // Verificar hash imediatamente
    $stmt = $db->prepare("SELECT password FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (password_verify($newPassword, $row['password'])) {
        echo "✓ Verificação do hash: OK — senha bate com o hash gravado.\n";
    } else {
        echo "❌ ERRO: hash gravado não confere com a senha informada!\n";
    }

    // Limpar cache de falhas de login para este e-mail
    try {
        $cacheKey = 'login_fails_' . md5($email);
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
        $redis->del($cacheKey);
        echo "✓ Cache de falhas de login limpo.\n";
    } catch (\Throwable $e) {
        echo "  (Redis indisponível — cache não limpo: {$e->getMessage()})\n";
    }

    echo "\nAgora faça login com:\n  E-mail: {$email}\n  Senha:  {$newPassword}\n";
} catch (\Throwable $e) {
    echo "❌ Erro: {$e->getMessage()}\n";
    exit(1);
}
