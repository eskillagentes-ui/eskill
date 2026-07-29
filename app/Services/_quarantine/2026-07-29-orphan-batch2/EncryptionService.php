<?php

declare(strict_types=1);

namespace App\Services;

/**
 * EncryptionService - Criptografia de Dados Sensíveis
 * 
 * Serviço para criptografar/descriptografar tokens e dados sensíveis
 * utilizando AES-256-GCM para máxima segurança.
 */
class EncryptionService
{
    private string $key;
    private string $cipher = 'aes-256-gcm';
    private int $tagLength = 16;

    public function __construct(?string $key = null)
    {
        // Usar Config singleton (carregado 1x) em vez de require repetido
        $config = \App\Core\Config::getInstance();
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

        // Extrair componentes
        $ivLength = openssl_cipher_iv_length($this->cipher);

        if (strlen($combined) < $ivLength + $this->tagLength) {
            throw new \InvalidArgumentException('Dados criptografados inválidos (tamanho)');
        }

        $iv = substr($combined, 0, $ivLength);
        $tag = substr($combined, $ivLength, $this->tagLength);
        $encrypted = substr($combined, $ivLength + $this->tagLength);

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
            throw new \RuntimeException('Falha na descriptografia: dados corrompidos ou chave inválida');
        }

        return $decrypted;
    }

    /**
     * Criptografa array/objeto como JSON
     */
    public function encryptArray(array $data): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        return $this->encrypt($json);
    }

    /**
     * Descriptografa e retorna array
     */
    public function decryptArray(string $encryptedData): array
    {
        $json = $this->decrypt($encryptedData);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Verifica se uma string está criptografada (teste básico)
     */
    public function isEncrypted(string $data): bool
    {
        // Verificar se é base64 válido
        if (base64_decode($data, true) === false) {
            return false;
        }

        // Verificar tamanho mínimo (IV + tag + dados)
        $decoded = base64_decode($data);
        $ivLength = openssl_cipher_iv_length($this->cipher);

        return strlen($decoded) > ($ivLength + $this->tagLength);
    }

    /**
     * Gera uma chave aleatória segura
     */
    public static function generateKey(int $length = 64): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Hash seguro para senhas (bcrypt)
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verifica senha contra hash
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Gera token seguro para autenticação
     */
    public function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Hash de token para armazenamento (SHA256)
     */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
