<?php
// Clôture mensuelle : logique commune à close_month.php (clôturer) et reopen_month.php (rouvrir / recalculer).
require_once __DIR__.'/config.php';

/**
 * Enregistre l'inventaire du mois ($year, $month) pour les comptes donnés, dans chaque devise.
 * À appeler à l'intérieur d'une transaction PDO. Lève RuntimeException si un compte/devise est déjà clôturé.
 * Retourne le nombre de lignes d'inventaire créées.
 */
function close_month_accounts(PDO $pdo, int $year, int $month, array $accountIds, ?int $userId): int {
    ensure_year($year);
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-d', strtotime($start.' +1 month'));
    $n = 0;
    foreach ($accountIds as $accId) {
        $accId = (int)$accId;
        foreach (['USD', 'FC'] as $cur) {
            $exists = $pdo->prepare('SELECT id FROM monthly_inventories WHERE account_id=? AND year=? AND month=? AND currency=? FOR UPDATE');
            $exists->execute([$accId, $year, $month, $cur]);
            if ($exists->fetch()) throw new RuntimeException('Clôture déjà effectuée pour un compte/devise. Aucune modification enregistrée.');
            $b = balance_for($accId, $year, $cur);
            $prior = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE account_id=? AND currency=? AND tx_date>=? AND tx_date<?");
            $prior->execute([$accId, $cur, sprintf('%04d-01-01', $year), $start]);
            $opening = (float)$b['opening_amount'] + (float)$prior->fetchColumn();
            $q = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0),COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) FROM transactions WHERE account_id=? AND currency=? AND tx_date>=? AND tx_date<?");
            $q->execute([$accId, $cur, $start, $end]);
            [$inc, $exp] = $q->fetch(PDO::FETCH_NUM);
            $close = $opening + (float)$inc - (float)$exp;
            $ins = $pdo->prepare('INSERT INTO monthly_inventories(account_id,year,month,currency,opening_amount,income,expense,closing_amount,closed_by) VALUES(?,?,?,?,?,?,?,?,?)');
            $ins->execute([$accId, $year, $month, $cur, $opening, $inc, $exp, $close, $userId]);
            $n++;
        }
    }
    return $n;
}
