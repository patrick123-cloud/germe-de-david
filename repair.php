<?php
// Mise à jour et réparation des données (administrateur). Non destructive : voir repair_lib.php.
require_once 'repair_lib.php';
require_role(['admin']);
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $result = repair_run();
    $_SESSION['repair_checked'] = 1;
}
$pending = repair_pending_count();
$dups = repair_duplicates();
$gaps = repair_closure_gaps();
$tot = (int)db()->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
$page_title = 'Mise à jour et réparation';
include 'partials/header.php';
?>
<?php if ($result): ?><div class="<?= $result['error'] ? 'alert' : 'notice' ?>"><?php if ($result['error']): ?><?= h($result['error']) ?><?php else: ?><b>Réparation terminée.</b><?php endif ?><ul><?php foreach ($result['log'] as $l): ?><li><?= h($l) ?></li><?php endforeach ?></ul></div><?php endif ?>
<div class="panel"><h2>Mise à jour des données enregistrées</h2>
<p>Cette opération ajoute le champ « catégorie » séparé sur chaque opération et le remplit automatiquement à partir des opérations déjà enregistrées (catégorie prédéfinie, sinon libellé). Elle recrée aussi les soldes initiaux annuels manquants. <b>Aucune opération, aucun montant et aucun libellé n'est modifié ni supprimé.</b> Elle peut être relancée sans risque.</p>
<table style="min-width:0;max-width:520px"><tr><th>Opérations enregistrées</th><td class="num"><?= $tot ?></td></tr>
<tr><th>Colonne « category_name »</th><td class="num"><?= $pending === -1 ? 'absente' : 'présente' ?></td></tr>
<tr><th>Opérations sans catégorie séparée</th><td class="num"><?= $pending === -1 ? $tot : $pending ?></td></tr></table>
<form method="post" class="stack" style="margin-top:14px"><?= csrf_field() ?><button class="btn primary" onclick="return confirm('Lancer la mise à jour et la réparation des données ?')"><?= $pending === 0 ? 'Relancer la vérification' : 'Lancer la mise à jour maintenant' ?></button></form>
<p style="margin-top:10px">Conseil : téléchargez d'abord une sauvegarde — <a href="backup_download.php">Télécharger la sauvegarde SQL</a>.</p></div>

<div class="panel"><h2>Doublons probables</h2><p>Même compte, date, type, devise, montant et libellé. Rien n'est supprimé automatiquement : ouvrez la fiche et supprimez la ligne mal saisie si c'est un vrai doublon.</p>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Compte n°</th><th>Type</th><th>Libellé</th><th>Montant</th><th>Fois</th><th>Fiche</th></tr></thead><tbody>
<?php foreach ($dups as $d): ?><tr><td><?= fmt_dmy($d['tx_date']) ?></td><td><?= (int)$d['account_id'] ?></td><td><?= $d['type'] === 'income' ? 'Entrée' : 'Sortie' ?></td><td><?= h($d['lbl']) ?></td><td class="num"><?= money($d['amount'], $d['currency']) ?></td><td class="num"><?= (int)$d['n'] ?></td><td><a href="transactions.php?scope=day&amp;jour=<?= h($d['tx_date']) ?>">Voir le jour</a></td></tr><?php endforeach ?>
<?php if (!$dups): ?><tr><td colspan="7" class="empty">Aucun doublon probable.</td></tr><?php endif ?></tbody></table></div></div>

<div class="panel"><h2>Clôtures à contrôler</h2><p>Mois clôturés dont les totaux enregistrés ne correspondent plus aux opérations (lecture seule).</p>
<div class="table-wrap"><table><thead><tr><th>Compte</th><th>Mois</th><th>Devise</th><th>Entrées clôture</th><th>Entrées actuelles</th><th>Sorties clôture</th><th>Sorties actuelles</th></tr></thead><tbody>
<?php foreach ($gaps as $g): ?><tr><td><?= h($g['account_name']) ?></td><td><?= h(month_fr((int)$g['month'])) ?> <?= (int)$g['year'] ?></td><td><?= h($g['currency']) ?></td><td class="num"><?= money($g['income'], $g['currency']) ?></td><td class="num"><?= money($g['calc_income'], $g['currency']) ?></td><td class="num"><?= money($g['expense'], $g['currency']) ?></td><td class="num"><?= money($g['calc_expense'], $g['currency']) ?></td></tr><?php endforeach ?>
<?php if (!$gaps): ?><tr><td colspan="7" class="empty">Toutes les clôtures correspondent aux opérations.</td></tr><?php endif ?></tbody></table></div></div>
<?php include 'partials/footer.php'; ?>
