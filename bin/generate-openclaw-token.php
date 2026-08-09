<?php

declare(strict_types=1);

/**
 * Gera Bearer Token para OpenClaw conectar na API.
 *
 * Modo 1 (com DB): php bin/generate-openclaw-token.php
 * Modo 2 (sem DB): php bin/generate-openclaw-token.php --offline
 *
 * No modo offline, gera o token e imprime o SQL INSERT
 * para executar manualmente no MySQL de produção.
 */

$offline = in_array('--offline', $argv, true);

$token = bin2hex(random_bytes(32));
$name = 'OpenClaw Connector';
$scopes = ['openclaw:read', 'openclaw:write', 'openclaw:admin'];
$scopesJson = json_encode($scopes);
$expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

if (!$offline) {
    require_once __DIR__ . '/../autoload.php';

    $service = new \App\Services\ApiTokenService();
    $result = $service->createToken(
        userId: 1,
        name: $name,
        scopes: $scopes,
        expiresInDays: 90
    );

    echo "=== Token OpenClaw Gerado ===\n";
    echo "Token ID: " . $result['id'] . "\n";
    echo "Token: " . $result['token'] . "\n";
    echo "Scopes: " . implode(', ', $result['scopes']) . "\n";
    echo "Expires: " . ($result['expires_at'] ?? 'never') . "\n";
    echo "=============================\n";
    exit(0);
}

// Modo offline — gera token + SQL INSERT
echo "============================================\n";
echo " TOKEN GERADO PARA OPENCLAW\n";
echo "============================================\n\n";

echo "Bearer Token:\n";
echo "{$token}\n\n";
echo "Expira em: {$expiresAt}\n\n";

echo "============================================\n";
echo " SQL PARA INSERIR NO BANCO (produção)\n";
echo "============================================\n\n";

echo "-- Execute no MySQL do servidor de produção:\n";
echo "USE meli;\n\n";

echo "CREATE TABLE IF NOT EXISTS api_tokens (\n";
echo "    id INT AUTO_INCREMENT PRIMARY KEY,\n";
echo "    user_id INT NOT NULL,\n";
echo "    token VARCHAR(255) NOT NULL,\n";
echo "    name VARCHAR(255) NOT NULL,\n";
echo "    scopes JSON NULL,\n";
echo "    is_active TINYINT(1) DEFAULT 1,\n";
echo "    last_used_at TIMESTAMP NULL,\n";
echo "    expires_at TIMESTAMP NULL,\n";
echo "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n";
echo "    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n";
echo "    INDEX idx_token (token),\n";
echo "    INDEX idx_user (user_id)\n";
echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";

echo "INSERT INTO api_tokens (user_id, token, name, scopes, expires_at, is_active)\n";
echo "VALUES (1, '{$token}', '{$name}', '{$scopesJson}', '{$expiresAt}', 1);\n\n";

echo "============================================\n";
echo " DADOS PARA OPENCLAW\n";
echo "============================================\n\n";

echo "Base URL:       https://eskill.com.br/api/openclaw\n";
echo "Authorization:  Bearer {$token}\n";
echo "Content-Type:   application/json\n\n";

echo "Teste rápido:\n";
echo "curl -H \"Authorization: Bearer {$token}\" https://eskill.com.br/api/openclaw/health\n";
