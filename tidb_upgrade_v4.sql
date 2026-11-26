-- Version 4 : catégorie dans son propre champ. À exécuter UNE SEULE FOIS (facultatif : l'application le fait
-- automatiquement quand l'administrateur se connecte, ou via le menu « Mise à jour & réparation »).
-- Non destructif : aucune opération n'est modifiée ni supprimée.
ALTER TABLE transactions ADD COLUMN category_name VARCHAR(180) NULL;
UPDATE transactions SET category_name = SUBSTR(COALESCE((SELECT c.name FROM categories c WHERE c.id = transactions.category_id), TRIM(label)), 1, 180) WHERE category_name IS NULL OR TRIM(category_name) = '';
