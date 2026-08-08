-- Livro financeiro canônico (Fase 1)
-- Independente de ml_orders: eventos financeiros idempotentes (ML/MP/manual).
-- source_detail_id NOT NULL DEFAULT '' — UNIQUE no MySQL trata NULL como distinto.

CREATE TABLE IF NOT EXISTS financial_ledger_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    account_id INT NOT NULL,

    source_system VARCHAR(20) NOT NULL COMMENT 'ml|mp|internal|manual',
    source_type VARCHAR(50) NOT NULL COMMENT 'order|payment|shipment|claim|refund|settlement|billing',
    source_id VARCHAR(100) NOT NULL,
    source_detail_id VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'linha/fee/sender id; vazio se N/A',

    order_id VARCHAR(50) NULL,
    payment_id VARCHAR(50) NULL,
    shipment_id VARCHAR(50) NULL,
    claim_id VARCHAR(50) NULL,
    refund_id VARCHAR(50) NULL,
    settlement_id VARCHAR(100) NULL,

    entry_type VARCHAR(50) NOT NULL,
    entry_category VARCHAR(50) NOT NULL,

    direction ENUM('credit', 'debit') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    signed_amount DECIMAL(15, 2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',

    occurred_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    available_at DATETIME NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'posted',
    description VARCHAR(255) NULL,

    raw_data JSON NULL,
    payload_hash CHAR(64) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_financial_source (
        account_id,
        source_system,
        source_type,
        source_id,
        source_detail_id,
        entry_type
    ),

    INDEX idx_financial_order (account_id, order_id),
    INDEX idx_financial_payment (account_id, payment_id),
    INDEX idx_financial_period (account_id, occurred_at),
    INDEX idx_financial_category (account_id, entry_category),
    INDEX idx_financial_type (account_id, entry_type),
    INDEX idx_financial_status (account_id, status),

    CONSTRAINT fk_financial_ledger_account
        FOREIGN KEY (account_id) REFERENCES ml_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
