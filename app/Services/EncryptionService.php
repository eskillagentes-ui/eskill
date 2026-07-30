<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * EncryptionService - Criptografia de Dados Sensíveis
 *
 * Serviço para criptografar/descriptografar tokens e dados sensíveis
 * utilizando AES-256-GCM para máxima segurança.
 *
 * Re-criado em 2026-07-30 após ser movido pra quarentena por engano
 * no commit d4f01175 (cleanup que quebrou SecureTokenService).
 *
 * IMPORTANTE: tokens criptografados com a versão ANTIGA
 * (que ainda está em _quarantine) SÃO compatíveis porque a lógica
 * de criptografia é idêntica.
 */
class EncryptionService
{
    private string $key;
    private string $cipher = 'aes-256-gcm';
    private int $tagLength = 16;

    public function __construct(?string $key = null)
    {
        // Usar Config singleton (carregado 1x) em vez de require repetido
        $config = Config::getInstance();
        $this->key = $key ?? ($config->get('key', ''));

        if (empty($this->key) || strlen($this->key) < 32) {
            throw new \RuntimeException(
                'Chave de criptografia inválida. Configure APP_KEY no .env (mínimo 32 caracteres)'
            );
        }

        // Garantir que a chave tenha 32 bytes para AES-256
        $this->key = hash('sha256', $this->key, true);
    }

    /**
     * Criptografa dados sensíveis
     */
    public function encrypt(string $data): string
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Dados para criptografar não podem estar vazios');
        }

        // Gerar IV aleatório
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = random_bytes($ivLength);

        // Criptografar
        $tag = '';
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            $this->tagLength
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Falha na criptografia: ' . openssl_error_string());
        }

        // Combinar IV + tag + dados criptografados e codificar em base64
        $combined = $iv . $tag . $encrypted;
        return base64_encode($combined);
    }

    /**
     * Descriptografa dados
     */
    public function decrypt(string $encryptedData): string
    {
        if (empty($encryptedData)) {
            throw new \InvalidArgumentException('Dados criptografados não podem estar vazios');
        }

        // Decodificar base64
        $combined = base64_decode($encryptedData, true);

        if ($combined === false) {
            throw new \InvalidArgumentException('Dados criptografados inválidos (base64)');
        }

        $ivLength = openssl_cipher_iv_length($this->cipher);
        $tagLength = $this->tagLength;

        // Extrair IV, tag e dados criptografados
        $iv = substr($combined, 0, $ivLength);
        $tag = substr($combined, $ivLength, $tagLength);
        $encrypted = substr($combined, $ivLength + $tagLength);

        // Descriptografar
        $decrypted = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Falha na descriptografia: ' . openssl_error_string());
        }

        return $decrypted;
    }

    /**
     * Verifica se um valor pode ser descriptografado
     */
    public function canDecrypt(string $encryptedData): bool
    {
        try {
            $this->decrypt($encryptedData);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}