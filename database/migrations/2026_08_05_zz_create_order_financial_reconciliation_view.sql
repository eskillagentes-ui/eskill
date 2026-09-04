-- A view depende de financial_ledger_entries. O sufixo zz garante que a
-- migration da tabela seja aplicada antes em bancos recriados do zero.

CREATE OR REPLACE VIEW vw_order_financial_reconciliation AS
SELECT
    account_id,
    order_id,

    ROUND(SUM(CASE WHEN entry_type = 'sale_revenue' AND status NOT IN ('cancelled', 'rejected', 'covered')
        THEN signed_amount ELSE 0 END), 2) AS sale_revenue,

    ROUND(SUM(CASE WHEN entry_type IN ('sale_fee', 'payment_fee') AND status NOT IN ('cancelled', 'rejected', 'covered')
        THEN signed_amount ELSE 0 END), 2) AS fees,

    ROUND(SUM(CASE WHEN entry_category = 'shipping' AND status NOT IN ('cancelled', 'rejected', 'covered')
        THEN signed_amount ELSE 0 END), 2) AS shipping_net,

    ROUND(SUM(CASE WHEN entry_category = 'refund' AND status NOT IN ('cancelled', 'rejected', 'covered')
        THEN signed_amount ELSE 0 END), 2) AS refund_net,

    ROUND(SUM(CASE WHEN entry_category = 'protection' AND status NOT IN ('cancelled', 'rejected', 'covered')
        THEN signed_amount ELSE 0 END), 2) AS protection_net,

    ROUND(SUM(CASE WHEN entry_category = 'adjustment' AND status NOT IN ('cancelled', 'rejected', 'covered')
        THEN signed_amount ELSE 0 END), 2) AS adjustment_net,

    ROUND(SUM(CASE WHEN status NOT IN ('cancelled', 'rejected', 'covered')
        THEN signed_amount ELSE 0 END), 2) AS marketplace_net,

    COUNT(*) AS entries_count,
    SUM(CASE WHEN status = 'covered' THEN 1 ELSE 0 END) AS covered_entries

FROM financial_ledger_entries
WHERE order_id IS NOT NULL AND order_id <> ''
GROUP BY account_id, order_id;
