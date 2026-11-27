-- Mise à jour v2 (offrandes par mois/libellé + performances) — à exécuter UNE SEULE FOIS
-- sur la base existante (TiDB Cloud ET MySQL/XAMPP local). Faire une sauvegarde avant.
-- 1) Vérifier d'abord si l'index existe déjà :
--    SHOW INDEX FROM transactions;   (chercher idx_account_date et idx_account_currency_date)
-- 2) N'exécuter que les lignes dont l'index est ABSENT :
ALTER TABLE transactions ADD INDEX idx_account_date (account_id, tx_date);
-- ALTER TABLE transactions ADD INDEX idx_account_currency_date (account_id, currency, tx_date);
