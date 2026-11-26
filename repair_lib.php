<?php
// Mise à jour et réparation des données (v4) — NON destructive :
//  1) ajoute la colonne transactions.category_name si elle manque (chaque catégorie dans son propre champ) ;
//  2) remplit ce champ pour chaque opération déjà enregistrée (catégorie prédéfinie, sinon libellé) ;
//  3) recrée les soldes initiaux annuels manquants (report d'une année à l'autre) ;
//  4) signale (sans rien supprimer) les doublons probables et les écarts de clôture.
// Aucune opération, aucun libellé, aucun montant n'est modifié ou supprimé.
require_once __DIR__.'/tx_lib.php';

/** Combien d'opérations n'ont pas encore leur catégorie dans son propre champ (-1 = colonne absente). */
function repair_pending_count(): int {
    if (!tx_has_category_col(true)) return -1;
    return (int)db()->query("SELECT COUNT(*) FROM transactions WHERE category_name IS NULL OR TRIM(category_name)=''")->fetchColumn();
}

function repair_needed(): bool { return repair_pending_count() !== 0; }

/** Exécute la mise à jour. Retourne ['log'=>[...], 'changed'=>int, 'error'=>string|null]. */
function repair_run(): array {
    $pdo = db(); $log = []; $changed = 0;
    try {
        if (!tx_has_category_col(true)) {
            $pdo->exec('ALTER TABLE transactions ADD COLUMN category_name VARCHAR(180) NULL');
            tx_has_category_col(true);
            $log[] = 'Colonne « category_name » ajoutée à la table des opérations.';
            $changed++;
        } else {
            $log[] = 'Colonne « category_name » déjà présente.';
        }
        $st = $pdo->prepare("UPDATE transactions SET category_name=SUBSTR(COALESCE((SELECT c.name FROM categories c WHERE c.id=transactions.category_id),TRIM(label)),1,180) WHERE category_name IS NULL OR TRIM(category_name)=''");
        $st->execute();
        $n = $st->rowCount();
        $log[] = $n.' opération(s) ont reçu leur catégorie dans un champ séparé (selon l\'opération enregistrée).';
        $changed += $n;

        $min = $pdo->query('SELECT MIN(tx_date) FROM transactions')->fetchColumn();
        if ($min) {
            $made = 0;
            for ($y = (int)substr((string)$min, 0, 4); $y <= (int)date('Y') + 1; $y++) {
                $before = count(opening_rows($y, true), COUNT_RECURSIVE);
                ensure_year($y);
                $made += max(0, count(opening_rows($y, true), COUNT_RECURSIVE) - $before);
            }
            $log[] = $made > 0 ? $made.' ligne(s) de solde initial annuel recréée(s).' : 'Soldes initiaux annuels : rien à recréer.';
            $changed += $made;
        }
        audit('REPAIR', 'transactions', null, json_encode(['log' => $log], JSON_UNESCAPED_UNICODE));
        return ['log' => $log, 'changed' => $changed, 'error' => null];
    } catch (Throwable $e) {
        $log[] = 'Échec : '.$e->getMessage();
        return ['log' => $log, 'changed' => $changed, 'error' => 'La mise à jour n\'a pas pu aller jusqu\'au bout (droits ALTER TABLE manquants ?). Aucune donnée n\'a été supprimée. Détail : '.$e->getMessage()];
    }
}

/** Doublons probables : même compte, date, type, devise, montant et libellé (à vérifier à la main). */
function repair_duplicates(int $limit = 50): array {
    $q = db()->query("SELECT account_id,tx_date,type,currency,amount,TRIM(label) lbl,COUNT(*) n,MIN(id) first_id FROM transactions GROUP BY account_id,tx_date,type,currency,amount,TRIM(label) HAVING COUNT(*)>1 ORDER BY tx_date DESC LIMIT ".(int)$limit);
    return $q->fetchAll();
}

/** Clôtures dont les totaux enregistrés ne correspondent plus aux opérations. */
function repair_closure_gaps(): array {
    $out = [];
    $inv = db()->query('SELECT mi.*,a.name account_name FROM monthly_inventories mi JOIN accounts a ON a.id=mi.account_id ORDER BY mi.year,mi.month')->fetchAll();
    foreach ($inv as $r) {
        $mx = month_matrix((int)$r['year']);
        $i = $mx[(int)$r['account_id']][$r['currency']][(int)$r['month']]['income'] ?? 0.0;
        $e = $mx[(int)$r['account_id']][$r['currency']][(int)$r['month']]['expense'] ?? 0.0;
        if (abs($i - (float)$r['income']) > 0.005 || abs($e - (float)$r['expense']) > 0.005)
            $out[] = $r + ['calc_income' => $i, 'calc_expense' => $e];
    }
    return $out;
}
