-- Fase 3: divergências financeiras + view de conciliação por pedido

CREATE TABLE IF NOT EXISTS financial_discrepancies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    account_id INT NOT NULL,
    order_id VARCHAR(50) NULL,
    payment_id VARCHAR(50) NULL,

    discrepancy_type VARCHAR(50) NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',

    expected_amount DECIMAL(15, 2) NULL,
    actual_amount DECIMAL(15, 2) NULL,
    difference_amount DECIMAL(15, 2) NULL,

    status ENUM('open', 'investigating', 'resolved', 'ignored') NOT NULL DEFAULT 'open',
    explanation TEXT NULL,

    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,

    fingerprint CHAR(64) NOT NULL COMMENT 'hash idempotente account+type+order+payment',

    PRIMARY KEY (id),

    UNIQUE KEY uq_discrepancy_fingerprint (fingerprint),
    INDEX idx_discrepancy_account (account_id, status, severity),
    INDEX idx_discrepancy_order (account_id, order_id),
    INDEX idx_discrepancy_type (account_id, discrepancy_type),

    CONSTRAINT fk_financial_discrepancies_account
        FOREIGN KEY (account_id) REFERENCES ml_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
