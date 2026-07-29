<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

use App\Database;
use PDO;

class AIConfigService
{
    private PDO $db;

    // Default account ID to use if none provided (e.g. system-wide settings)
    private ?int $defaultAccountId = null;

    // Encryption cipher method
    private const CIPHER_METHOD = 'aes-256-gcm';

    // Key derivation iterations
    private const PBKDF2_ITERATIONS = 100000;

    public function __construct(?int $accountId = null)
    {
        $this->db = Database::getInstance();
        $this->defaultAccountId = $accountId;
    }

    /**
     * Get the encryption key derived from app secret
     */
    private function getEncryptionKey(): string
    {
        $appSecret = $_ENV['APP_SECRET'] ?? $_ENV['APP_KEY'] ?? null;

        if (!$appSecret) {
            throw new \RuntimeException('APP_SECRET or APP_KEY must be set in environment for encryption');
        }

        // Use a fixed salt for key derivation (stored with the app)
        $salt = $_ENV['ENCRYPTION_SALT'] ?? 'eskill_ai_config_salt_v1';

        // Derive a 256-bit key using PBKDF2
        return hash_pbkdf2('sha256', $appSecret, $salt, self::PBKDF2_ITERATIONS, 32, true);
    }

    /**
     * Get API Key for a provider
     */
    public function getApiKey(string $provider): ?string
    {
        // 1. Check DB first (User defined)
        $keyName = "ai_key_{$provider}"; // e.g., ai_key_anthropic
        $dbKey = $this->getSetting($keyName);
        
        if ($dbKey) {
            return $this->decrypt($dbKey);
        }

        // 2. Fallback to ENV (Legacy/Dev)
        $envKey = match($provider) {
            'anthropic' => $_ENV['ANTHROPIC_API_KEY'] ?? null,
            'openai' => $_ENV['OPENAI_API_KEY'] ?? null,
            default => null
        };

        return $envKey;
    }

    /**
     * Set API Key (encrypted)
     */
    public function setApiKey(string $provider, string $key): void
    {
        $keyName = "ai_key_{$provider}";
        $encrypted = $this->encrypt($key);
        $this->saveSetting($keyName, $encrypted);
    }

    /**
     * Encrypt a value using AES-256-GCM
     */
    private function encrypt(string $plaintext): string
    {
        $key = $this->getEncryptionKey();

        // Generate a random IV (12 bytes for GCM)
        $iv = random_bytes(12);

        // Encrypt with authentication tag
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16  // Tag length
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Combine IV + tag + ciphertext and encode as base64
        // Format: base64(iv:tag:ciphertext)
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Get preferred model for a task
     */
    public function getModelPreference(string $task = 'general'): string
    {
        return $this->getSetting("ai_model_{$task}") ?? 'claude-3-5-sonnet-20241022';
    }

    /**
     * Helper to get setting from DB
     */
    private function getSetting(string $key)
    {
        $accountId = $this->defaultAccountId ?? $_SESSION['active_ml_account_id'] ?? null;
        
        if (!$accountId) return null;

        $stmt = $this->db->prepare("SELECT value FROM settings WHERE account_id = :aid AND key_name = :key");
        $stmt->execute(['aid' => $accountId, 'key' => $key]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Helper to save setting to DB
     */
    private function saveSetting(string $key, string $value): void
    {
        $accountId = $this->defaultAccountId ?? $_SESSION['active_ml_account_id'] ?? null;
        
        if (!$accountId) throw new \Exception("No account context for saving settings");

        $stmt = $this->db->prepare("
            INSERT INTO settings (account_id, key_name, value)
            VALUES (:aid, :key, :val)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");
        $stmt->execute(['aid' => $accountId, 'key' => $key, 'val' => $value]);
    }

    /**
     * Decrypt a value encrypted with AES-256-GCM
     */
    private function decrypt(string $encryptedValue): ?string
    {
        // Handle legacy unencrypted values (not base64 or too short)
        if (!$this->isEncrypted($encryptedValue)) {
            return $encryptedValue;
        }

        try {
            $key = $this->getEncryptionKey();

            // Decode from base64
            $data = base64_decode($encryptedValue, true);
            if ($data === false || strlen($data) < 28) {
                // 12 (IV) + 16 (tag) = 28 minimum
                return $encryptedValue; // Return as-is if not valid encrypted format
            }

            // Extract IV (12 bytes), tag (16 bytes), and ciphertext
            $iv = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $ciphertext = substr($data, 28);

            // Decrypt with tag verification
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($plaintext === false) {
                // Decryption failed - might be legacy unencrypted or corrupted
                log_warning('Decryption failed, possibly legacy value', ['service' => 'AIConfigService']);
                return null;
            }

            return $plaintext;
        } catch (\Throwable $e) {
            log_error('Decryption error', ['service' => 'AIConfigService', 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Check if a value appears to be encrypted
     */
    private function isEncrypted(string $value): bool
    {
        // Check if it's valid base64 and has minimum length for our format
        $decoded = base64_decode($value, true);
        return $decoded !== false && strlen($decoded) >= 28;
    }

    /**
     * Re-encrypt all existing API keys (for key rotation)
     */
    public function rotateEncryptionKeys(): array
    {
        $providers = ['anthropic', 'openai', 'google', 'cohere'];
        $results = [];

        foreach ($providers as $provider) {
            $currentKey = $this->getApiKey($provider);
            if ($currentKey) {
                $this->setApiKey($provider, $currentKey);
                $results[$provider] = 'rotated';
            } else {
                $results[$provider] = 'not_set';
            }
        }

        return $results;
    }
}
