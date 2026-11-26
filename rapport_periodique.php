<?php
// Rapports des entrées et sorties : détail de chaque opération, par jour, par semaine, par mois, par année, ou global.
// Pour chaque période : entrées, sorties, solde restant (entrées − sorties) et solde cumulé. USD et FC restent séparés.
require_once 'tx_lib.php';
require_login();
$pdo = db();
$gran = in_array($_GET['gran'] ?? '', ['detail', 'day', 'week', 'month', 'year'], true) ? $_GET['gran'] : 'detail';
$per = period_resolve(period_from_request($_GET, 'month'), $_GET);
$accounts = $pdo->query('SELECT id,name FROM accounts WHERE active=1 ORDER BY name')->fetchAll();
$accFilter = (int)($_GET['account'] ?? 0);
$curFilter = in_array($_GET['cur'] ?? '', ['USD', 'FC'], true) ? $_GET['cur'] : '';
$typeFilter = in_array($_GET['type'] ?? '', ['income', 'expense'], true) ? $_GET['type'] : '';
$search = trim((string)($_GET['search'] ?? ''));
$filtered = ($search !== '' || $typeFilter !== '');   // avec un filtre, les soldes ne portent que sur la sélection
$accName = 'Tous les comptes';
foreach ($accounts as $a) if ((int)$a['id'] === $accFilter) $accName = $a['name'];
$accIds = account_ids($_GET);
$currencies = $curFilter ? [$curFilter] : ['USD', 'FC'];
$canWrite = in_array(role(), ['admin', 'treasurer'], true);

[$where, $params] = tx_filters($_GET, $per);
$from = tx_from_sql();
$granLabels = ['detail' => 'Détail de chaque opération', 'day' => 'Par jour', 'week' => 'Par semaine', 'month' => 'Par mois', 'year' => 'Par année'];
$scopeWord = ['day' => 'du jour', 'month' => 'du mois', 'year' => "de l'année", 'range' => 'de la période', 'all' => 'de la période'][$per['scope']];

$data = [];      // par devise
$detail = [];    // détail par devise
$truncated = false;
if ($gran === 'detail') {
    $q = $pdo->prepare('SELECT t.id,t.tx_date,t.created_at,t.type,t.currency,t.amount,t.label,t.account_id,a.name account_name,' . tx_cat_expr() . " category_label,u.full_name creator FROM $from WHERE $where ORDER BY t.tx_date,t.id LIMIT 5001");
    $q->execute($params);
    $all = $q->fetchAll();
    if (count($all) > 5000) { array_pop($all); $truncated = true; }
    foreach ($currencies as $cu) {
        $bal = $filtered ? 0.0 : opening_before($accIds, $cu, $per['from']);
        $open = $bal; $ti = 0.0; $to = 0.0; $rows = [];
        foreach ($all as $r) {
            if ($r['currency'] !== $cu) continue;
            $in = $r['type'] === 'income' ? (float)$r['amount'] : 0.0;
            $out = $r['type'] === 'expense' ? (float)$r['amount'] : 0.0;
            $bal += $in - $out; $ti += $in; $to += $out;
            $rows[] = $r + ['in' => $in, 'out' => $out, 'bal' => $bal];
        }
        $detail[$cu] = ['rows' => $rows, 'open' => $open, 'in' => $ti, 'out' => $to, 'net' => $ti - $to, 'close' => $bal, 'n' => count($rows)];
    }
    foreach ($currencies as $cu) $data[$cu] = $detail[$cu];
} else {
    $q = $pdo->prepare("SELECT t.tx_date d,t.currency cur,t.type ty,SUM(t.amount) s,COUNT(*) n FROM $from WHERE $where GROUP BY t.tx_date,t.currency,t.type");
    $q->execute($params);
    $dayRows = $q->fetchAll();
    foreach ($currencies as $cu) {
        $open = $filtered ? 0.0 : opening_before($accIds, $cu, $per['from']);
        $data[$cu] = period_rows($dayRows, $cu, $gran, $per['from'], $per['to'], $open);
    }
}

$qs = filter_query($per, $_GET);
$returnUrl = 'rapport_periodique.php?' . $qs . '&gran=' . $gran;
$curName = ['USD' => 'Dollars (USD)', 'FC' => 'Francs congolais (FC)'];
$page_title = 'Rapports des entrées et sorties';
include 'partials/header.php';
?>
<div class="panel no-print"><div class="panel-head"><div><h2>Rapports des entrées et sorties</h2><p>Détail de chaque opération, ou totaux par jour, semaine, mois et année. Les montants USD et FC ne sont jamais mélangés.</p></div>
<button type="button" class="btn primary" data-preview="#printArea">Aperçu et impression</button></div>
<div class="tabs"><?php foreach ($granLabels as $k => $l): ?><a class="tab<?= $gran === $k ? ' active' : '' ?>" href="rapport_periodique.php?<?= h($qs) ?>&amp;gran=<?= $k ?>"><?= h($l) ?></a><?php endforeach ?></div>
<form class="filter-row" method="get" data-scope-form><input type="hidden" name="gran" value="<?= h($gran) ?>">
<?= period_filter_html($per) ?>
<select name="account"><option value="0">Tous les comptes</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $accFilter === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach ?></select>
<select name="cur"><option value="">USD et FC</option><option value="USD" <?= $curFilter === 'USD' ? 'selected' : '' ?>>USD</option><option value="FC" <?= $curFilter === 'FC' ? 'selected' : '' ?>>FC</option></select>
<select name="type"><option value="">Entrées et sorties</option><option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Entrées seulement</option><option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Sorties seulement</option></select>
<input name="search" placeholder="Libellé ou catégorie (facultatif)" value="<?= h($search) ?>">
<button class="btn primary">Afficher</button></form>
<p style="margin:10px 0 0"><a href="export.php?<?= h($qs) ?>">Exporter en Excel (CSV)</a> · <a href="categories_rapport.php?<?= h($qs) ?>">Rapport par catégorie →</a></p></div>

<div id="printArea" <?= isset($_GET['print']) ? 'data-autopreview="#printArea"' : '' ?>>
<?= print_head('Rapport des entrées et sorties — ' . $granLabels[$gran], $per['label'] . ' · ' . $accName . ($search !== '' ? ' · « ' . $search . ' »' : '')) ?>
<?php if ($filtered): ?><div class="alert">Filtre actif (texte ou type) : les soldes ci-dessous portent uniquement sur les opérations sélectionnées.</div><?php endif ?>
<?php if ($truncated): ?><div class="alert">Plus de 5 000 opérations : seules les 5 000 premières sont affichées. Réduisez la période.</div><?php endif ?>

<?php foreach ($currencies as $cu): $d = $data[$cu]; ?>
<h3 class="cur-title"><?= h($curName[$cu]) ?></h3>
<div class="grid cards sum-cards">
<article class="stat-card"><div class="stat-top"><span>Entrées <?= h($scopeWord) ?></span></div><div class="stat-value"><?= money($d['in'], $cu) ?></div></article>
<article class="stat-card"><div class="stat-top"><span>Sorties <?= h($scopeWord) ?></span></div><div class="stat-value"><?= money($d['out'], $cu) ?></div></article>
<article class="stat-card"><div class="stat-top"><span>Solde restant <?= h($scopeWord) ?></span></div><div class="stat-value"><?= money($d['net'], $cu) ?></div><div class="stat-foot">Entrées − sorties</div></article>
<article class="stat-card"><div class="stat-top"><span>Solde cumulé (fin de période)</span></div><div class="stat-value"><?= money($d['close'], $cu) ?></div><div class="stat-foot">Report initial <?= money($d['open'], $cu) ?> + solde restant</div></article></div>

<?php if ($gran === 'detail'): ?>
<div class="table-wrap"><table><thead><tr><th>N°</th><th>Date</th><th>Compte</th><th>Catégorie</th><th>Libellé</th><th>Entrée</th><th>Sortie</th><th>Solde cumulé</th><th>Saisi par</th><th class="no-print">Actions</th></tr></thead><tbody>
<tr class="row-open"><td colspan="7"><b>Solde reporté avant le <?= fmt_dmy($per['from']) ?></b></td><td class="num"><b><?= money($d['open'], $cu) ?></b></td><td></td><td class="no-print"></td></tr>
<?php foreach ($d['rows'] as $r): $locked = period_locked((int)$r['account_id'], $r['tx_date']); ?>
<tr><td><?= (int)$r['id'] ?></td><td><?= fmt_dmy($r['tx_date']) ?></td><td><?= h($r['account_name']) ?></td><td><?= h($r['category_label']) ?></td><td><?= h($r['label']) ?></td>
<td class="num"><?= $r['in'] ? money($r['in'], $cu) : '' ?></td><td class="num"><?= $r['out'] ? money($r['out'], $cu) : '' ?></td><td class="num"><?= money($r['bal'], $cu) ?></td><td><?= h($r['creator'] ?? '—') ?></td>
<td class="no-print"><a href="operation.php?id=<?= (int)$r['id'] ?>">Fiche</a> · <a href="operation.php?id=<?= (int)$r['id'] ?>&amp;print=1">Imprimer</a><?php if ($canWrite && !$locked): ?> · <a href="transactions.php?edit=<?= (int)$r['id'] ?>">Modifier</a>
<form method="post" action="delete_operation.php" style="display:inline" onsubmit="return confirm('Supprimer définitivement cette opération (<?= h(addslashes($r['label'])) ?>) ?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return" value="<?= h($returnUrl) ?>"> · <button class="link-danger">Supprimer</button></form><?php endif ?></td></tr>
<?php endforeach ?>
<?php if (!$d['rows']): ?><tr><td colspan="10" class="empty">Aucune opération en <?= h($cu) ?> pour cette sélection.</td></tr><?php endif ?>
<tr class="row-total"><th colspan="5">Total — <?= (int)$d['n'] ?> opération(s)</th><th class="num"><?= money($d['in'], $cu) ?></th><th class="num"><?= money($d['out'], $cu) ?></th><th class="num"><?= money($d['close'], $cu) ?></th><th></th><th class="no-print"></th></tr>
</tbody></table></div>
<?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Période</th><th>Opér.</th><th>Solde initial</th><th>Entrées</th><th>Sorties</th><th>Solde restant</th><th>Solde cumulé</th></tr></thead><tbody>
<?php foreach ($d['rows'] as $r): ?><tr><td><?= h($r['label']) ?></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= money($r['open'], $cu) ?></td><td class="num"><?= money($r['in'], $cu) ?></td><td class="num"><?= money($r['out'], $cu) ?></td><td class="num"><?= money($r['net'], $cu) ?></td><td class="num"><?= money($r['close'], $cu) ?></td></tr><?php endforeach ?>
<?php if (!$d['rows']): ?><tr><td colspan="7" class="empty">Aucune opération en <?= h($cu) ?> pour cette sélection.</td></tr><?php endif ?>
<tr class="row-total"><th>Total</th><th class="num"><?= (int)$d['n'] ?></th><th class="num"><?= money($d['open'], $cu) ?></th><th class="num"><?= money($d['in'], $cu) ?></th><th class="num"><?= money($d['out'], $cu) ?></th><th class="num"><?= money($d['net'], $cu) ?></th><th class="num"><?= money($d['close'], $cu) ?></th></tr>
</tbody></table></div>
<?php endif ?>
<?php endforeach ?>
</div>
<?php include 'partials/footer.php'; ?>
