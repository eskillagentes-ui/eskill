-- PATCH 1 (Fase A — fundação): schema complementar de caixa no livro financeiro.
-- Somente schema + backfill de imported_at. Sem ingestão, sem UI, sem mudança de P&L.
-- Pré-requisito: 2026_08_05_create_financial_ledger_entries.sql e
--                 2026_08_05_create_financial_discrepancies.sql já aplicadas.

-- financial_ledger_entries: distinguir "quando o sistema importou" de created_at/updated_at
-- (created_at/updated_at já existem; imported_at é o timestamp de coleta pela ingestão).
ALTER TABLE financial_ledger_entries
    ADD COLUMN IF NOT EXISTS imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'Quando o sistema coletou o evento (ingestão); != occurred_at/created_at'
        AFTER available_at;

-- Backfill de linhas já existentes (Fases 1-5): imported_at = created_at.
UPDATE financial_ledger_entries
SET imported_at = created_at
WHERE imported_at = created_at
  AND created_at IS NOT NULL;

-- Índices de caixa (settlement/liberação/disponibilidade) — Fase A depende destes.
ALTER TABLE financial_ledger_entries
    ADD INDEX IF NOT EXISTS idx_financial_settlement (account_id, settlement_id);

ALTER TABLE financial_ledger_entries
    ADD INDEX IF NOT EXISTS idx_financial_released (account_id, released_at);

ALTER TABLE financial_ledger_entries
    ADD INDEX IF NOT EXISTS idx_financial_available (account_id, available_at);

ALTER TABLE financial_ledger_entries
    ADD INDEX IF NOT EXISTS idx_financial_category_available (account_id, entry_category, available_at);

-- financial_discrepancies: auditoria de resolução manual (Fase E), preparada agora
-- para não exigir nova migration quando a tela de divergências chegar.
ALTER TABLE financial_discrepancies
    ADD COLUMN IF NOT EXISTS resolved_by INT NULL COMMENT 'users.id de quem resolveu/ignorou' AFTER resolved_at,
    ADD COLUMN IF NOT EXISTS resolution_note TEXT NULL COMMENT 'motivo da resolução manual' AFTER resolved_by,
    ADD COLUMN IF NOT EXISTS previous_amount DECIMAL(15, 2) NULL COMMENT 'valor antes do ajuste manual' AFTER resolution_note,
    ADD COLUMN IF NOT EXISTS new_amount DECIMAL(15, 2) NULL COMMENT 'valor após o ajuste manual' AFTER previous_amount,
    ADD COLUMN IF NOT EXISTS resolution_action VARCHAR(30) NULL COMMENT 'resolved|ignored|reopened|adjusted' AFTER new_amount;

ALTER TABLE financial_discrepancies
    ADD INDEX IF NOT EXISTS idx_discrepancy_resolved_by (account_id, resolved_by);
