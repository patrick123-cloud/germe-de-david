-- Vérifications de structure après installation dans TiDB Cloud.
SHOW TABLES;
SHOW CREATE TABLE users;
SHOW CREATE TABLE accounts;
SHOW CREATE TABLE categories;
SHOW CREATE TABLE account_openings;
SHOW CREATE TABLE transactions;
SHOW CREATE TABLE monthly_inventories;
SHOW CREATE TABLE audit_logs;
SELECT COUNT(*) AS account_count FROM accounts;
SELECT COUNT(*) AS category_count FROM categories;
SELECT COUNT(*) AS user_count FROM users;
SELECT COUNT(*) AS transaction_count FROM transactions;
