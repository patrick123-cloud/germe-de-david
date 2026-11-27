<?php
// Fiche détaillée d'une opération : informations, solde du compte après l'opération, historique, impression, suppression.
require_once 'tx_lib.php';
require_login();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare('SELECT t.*,a.name account_name,' . tx_cat_expr() . ' category_label,c.name preset_name,u.full_name creator,u2.full_name updater FROM transactions t JOIN accounts a ON a.id=t.account_id LEFT JOIN categories c ON c.id=t.category_id LEFT JOIN users u ON u.id=t.created_by LEFT JOIN users u2 ON u2.id=t.updated_by WHERE t.id=?');
$q->execute([$id]);
$r = $q->fetch();
$page_title = 'Fiche d\'opération';
if (!$r) {
    include 'partials/header.php';
    echo '<div class="panel"><h2>Opération introuvable</h2><p>Elle n\'existe pas ou a été supprimée.</p><a class="btn" href="transactions.php">← Retour à la recherche</a></div>';
    include 'partials/footer.php';
    exit;
}
$cur = $r['currency'];
$sign = $r['type'] === 'income' ? 1 : -1;
// Solde du compte (dans cette devise) juste après cette opération : initial de l'année + opérations jusqu'à celle-ci.
$y = (int)substr($r['tx_date'], 0, 4);
$s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE account_id=? AND currency=? AND tx_date>=? AND (tx_date<? OR (tx_date=? AND id<=?))");
$s->execute([$r['account_id'], $cur, sprintf('%04d-01-01', $y), $r['tx_date'], $r['tx_date'], $r['id']]);
$balanceAfter = balance_for((int)$r['account_id'], $y, $cur)['opening_amount'] + (float)$s->fetchColumn();
$hist = $pdo->prepare("SELECT l.created_at,l.action,u.full_name FROM audit_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.table_name='transactions' AND l.record_id=? ORDER BY l.id");
$hist->execute([$id]);
$history = $hist->fetchAll();
$locked = period_locked((int)$r['account_id'], $r['tx_date']);
$canWrite = in_array(role(), ['admin', 'treasurer'], true);
$actions = ['CREATE' => 'Création', 'UPDATE' => 'Modification', 'DELETE' => 'Suppression'];
include 'partials/header.php';
?>
<div class="panel no-print"><div class="panel-head"><div><h2>Opération n° <?= (int)$r['id'] ?></h2><p><?= h($r['label']) ?> — <?= fmt_dmy($r['tx_date']) ?></p></div>
<div class="filter-actions"><a class="btn" href="transactions.php?scope=day&amp;jour=<?= h($r['tx_date']) ?>">← Opérations du jour</a>
<button class="btn primary" data-preview="#printArea">Aperçu et impression</button>
<?php if ($canWrite && !$locked): ?><a class="btn" href="transactions.php?edit=<?= (int)$r['id'] ?>">Modifier</a>
<form method="post" action="delete_operation.php" style="display:inline" onsubmit="return confirm('Supprimer définitivement cette opération ?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return" value="transactions.php"><button class="btn" style="color:#b42318">Supprimer cette opération</button></form>
<?php elseif ($locked): ?><span class="tag expense">Mois clôturé : verrouillée</span><?php endif ?></div></div></div>

<div class="panel" id="printArea" <?= isset($_GET['print']) ? 'data-autopreview="#printArea"' : '' ?>>
<?= print_head('Fiche d\'opération n° ' . (int)$r['id'], $r['account_name']) ?>
<div class="table-wrap"><table class="fiche"><tbody>
<tr><th>Date de l'opération</th><td><?= h(ucfirst(weekday_fr($r['tx_date']))) ?> <?= fmt_dmy($r['tx_date']) ?></td></tr>
<tr><th>Compte</th><td><?= h($r['account_name']) ?></td></tr>
<tr><th>Nature</th><td><?= $r['type'] === 'income' ? 'Entrée (recette)' : 'Sortie (dépense)' ?></td></tr>
<tr><th>Catégorie</th><td><?= h($r['category_label']) ?><?= $r['preset_name'] && $r['preset_name'] !== $r['category_label'] ? ' <small>(prédéfinie : ' . h($r['preset_name']) . ')</small>' : '' ?></td></tr>
<tr><th>Libellé</th><td><?= h($r['label']) ?></td></tr>
<tr><th>Montant</th><td><b><?= $r['type'] === 'income' ? '+' : '−' ?> <?= money($r['amount'], $cur) ?></b></td></tr>
<tr><th>Solde du compte après cette opération (<?= h($cur) ?>)</th><td><?= money($balanceAfter, $cur) ?></td></tr>
<tr><th>Saisie par</th><td><?= h($r['creator'] ?? '—') ?> · <?= h(fmt_dt($r['created_at'])) ?></td></tr>
<?php if ($r['updated_at']): ?><tr><th>Dernière modification</th><td><?= h($r['updater'] ?? '—') ?> · <?= h(fmt_dt($r['updated_at'])) ?></td></tr><?php endif ?>
<?php if ($history): ?><tr><th>Historique</th><td><?php foreach ($history as $h): ?><?= h($actions[$h['action']] ?? $h['action']) ?> — <?= h($h['full_name'] ?? '—') ?> — <?= h(fmt_dt($h['created_at'])) ?><br><?php endforeach ?></td></tr><?php endif ?>
</tbody></table></div>
<div class="signatures"><div>Le trésorier<br><br><br>________________</div><div>Le pasteur / visa<br><br><br>________________</div></div>
</div>
<?php include 'partials/footer.php'; ?>
