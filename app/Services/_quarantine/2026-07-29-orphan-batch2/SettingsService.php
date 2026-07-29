<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

class SettingsService
{
    private ?PDO $db;
    private int $accountId;

    public function __construct(
        int $accountId,
        ?PDO $db = null,
        bool $skipDbAutoConnect = false
    ) {
        $this->accountId = $accountId;

        if ($db !== null) {
            $this->db = $db;
        } elseif (!$skipDbAutoConnect) {
            $this->db = Database::getInstance();
        } else {
            $this->db = null;
        }
    }

    /**
     * Get a setting value, or default if not set
     */
    public function get(string $key, $default = null)
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE account_id = :aid AND key_name = :key");
        $stmt->execute(['aid' => $this->accountId, 'key' => $key]);
        $value = $stmt->fetchColumn();
        
        return $value !== false ? $value : $default;
    }

    /**
     * Set a setting value
     */
    public function set(string $key, $value): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO settings (account_id, key_name, value)
            VALUES (:aid, :key, :val)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");
        $stmt->execute(['aid' => $this->accountId, 'key' => $key, 'val' => $value]);
    }

    /**
     * Get all settings as associative array
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare("SELECT key_name, value FROM settings WHERE account_id = :aid");
        $stmt->execute(['aid' => $this->accountId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    /**
     * Get numeric default tax rate
     */
    public function getDefaultTaxRate(): float
    {
        return (float) $this->get('default_tax_rate', 0);
    }

    /**
     * Get default pricing strategy
     */
    public function getDefaultPricingStrategy(): string
    {
        return $this->get('default_pricing_strategy', '');
    }
}
