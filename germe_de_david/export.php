<?php
// Export CSV (Excel) des opérations : mêmes filtres que la recherche (période, compte, devise, type, texte).
require_once 'tx_lib.php';
require_login();
$per = period_resolve(period_from_request($_GET, 'month'), $_GET);
[$where, $params] = tx_filters($_GET, $per);
$cat = (int)($_GET['cat'] ?? 0);   // ancien paramètre : identifiant de catégorie prédéfinie
if ($cat) { $where .= ' AND t.category_id=?'; $params[] = $cat; }
$q = db()->prepare('SELECT t.tx_date,a.name account_name,t.type,' . tx_cat_expr() . ' category,t.label,t.description,t.currency,t.amount,u.full_name creator FROM ' . tx_from_sql() . " WHERE $where ORDER BY t.tx_date,t.id");
$q->execute($params);
$safe = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;   // protection contre l'injection de formules Excel
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="rapport_financier_' . $per['from'] . '_' . $per['to'] . '.csv"');
echo "\xEF\xBB\xBF";
$o = fopen('php://output', 'w');
fputcsv($o, ['Date', 'Compte', 'Type', 'Catégorie', 'Libellé', 'Description', 'Devise', 'Montant', 'Enregistré par'], ';');
while ($r = $q->fetch()) {
    $r['type'] = $r['type'] === 'income' ? 'Entrée' : 'Sortie';
    fputcsv($o, array_map($safe, $r), ';');
}
fclose($o);
