-- Migration facultative pour une base déjà installée (exécuter une seule fois).
-- Sauvegarder la base avant. Cet index accélère les rapports par compte, devise et date.
ALTER TABLE transactions ADD INDEX idx_account_currency_date (account_id, currency, tx_date);
