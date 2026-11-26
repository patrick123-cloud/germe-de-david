<?php
// Rapports par catégorie.
//  • « Séparé »   : chaque catégorie telle qu'enregistrée (Dîme, DÎME et dîme restent trois lignes distinctes), avec chaque opération sur sa ligne.
//  • « Regroupé » : les catégories identiques sans tenir compte de la casse ni des espaces sont additionnées.
require_once 'tx_lib.php';
require_login();
$pdo = db();
$mode = ($_GET['mode'] ?? 'separe') === 'groupe' ? 'groupe' : 'separe';
$detail = isset($_GET['detail']) ? ($_GET['detail'] === '1') : ($mode === 'separe');
$per = period_resolve(period_from_request($_GET, 'month'), $_GET);
$accounts = $pdo->query('SELECT id,name FROM accounts WHERE active=1 ORDER BY name')->fetchAll();
$accFilter = (int)($_GET['account'] ?? 0);
$curFilter = in_array($_GET['cur'] ?? '', ['USD', 'FC'], true) ? $_GET['cur'] : '';
$typeFilter = in_array($_GET['type'] ?? '', ['income', 'expense'], true) ? $_GET['type'] : '';
$search = trim((string)($_GET['search'] ?? ''));
$accName = 'Tous les comptes';
foreach ($accounts as $a) if ((int)$a['id'] === $accFilter) $accName = $a['name'];
$currencies = $curFilter ? [$curFilter] : ['USD', 'FC'];

[$where, $params] = tx_filters($_GET, $per);
$q = $pdo->prepare('SELECT t.id,t.tx_date,t.type,t.currency,t.amount,t.label,a.name account_name,' . tx_cat_expr() . ' cat FROM ' . tx_from_sql() . " WHERE $where ORDER BY t.tx_date,t.id LIMIT 20001");
$q->execute($params);
$ops = $q->fetchAll();
$truncated = count($ops) > 20000;
if ($truncated) array_pop($ops);

// Regroupement en mémoire (la base compare sans casse ; ici on garde la casse pour « séparé »).
$groups = [];
foreach ($ops as $o) {
    $cat = trim((string)$o['cat']); if ($cat === '') $cat = '(sans catégorie)';
    $key = $mode === 'groupe' ? 'k:' . norm_key($cat) : 'k:' . $cat;
    $groups[$key] ??= ['variants' => [], 'n' => 0, 'sum' => ['income' => ['USD' => 0.0, 'FC' => 0.0], 'expense' => ['USD' => 0.0, 'FC' => 0.0]], 'ops' => []];
    $g = &$groups[$key];
    $g['variants'][$cat] = ($g['variants'][$cat] ?? 0) + 1;
    $g['n']++;
    $g['sum'][$o['type']][$o['currency']] += (float)$o['amount'];
    if ($detail) $g['ops'][] = $o;
    unset($g);
}
foreach ($groups as &$g) {
    arsort($g['variants']);   // nom affiché = variante la plus fréquente
    $g['name'] = (string)array_key_first($g['variants']);
}
unset($g);
uasort($groups, fn($a, $b) => strcmp(mb_strtolower($a['name'], 'UTF-8'), mb_strtolower($b['name'], 'UTF-8')) ?: strcmp($a['name'], $b['name']));

$tot = ['n' => 0, 'income' => ['USD' => 0.0, 'FC' => 0.0], 'expense' => ['USD' => 0.0, 'FC' => 0.0]];
foreach ($groups as $g) { $tot['n'] += $g['n']; foreach (['income', 'expense'] as $t) foreach (['USD', 'FC'] as $c) $tot[$t][$c] += $g['sum'][$t][$c]; }

$qs = filter_query($per, $_GET);
$title = $mode === 'groupe' ? 'Rapport par catégorie — catégories identiques regroupées (sans tenir compte de la casse)' : 'Rapport par catégorie — chaque catégorie séparément';
$page_title = 'Rapport par catégorie';
include 'partials/header.php';
?>
<div class="panel no-print"><div class="panel-head"><div><h2>Rapport par catégorie</h2><p>« Séparé » : chaque catégorie telle qu'enregistrée, une ligne par opération. « Regroupé » : les catégories identiques, majuscules ou minuscules, sont additionnées.</p></div>
<button type="button" class="btn primary" data-preview="#printArea">Aperçu et impression</button></div>
<div class="tabs"><a class="tab<?= $mode === 'separe' ? ' active' : '' ?>" href="categories_rapport.php?<?= h($qs) ?>&amp;mode=separe">Chaque catégorie séparée</a><a class="tab<?= $mode === 'groupe' ? ' active' : '' ?>" href="categories_rapport.php?<?= h($qs) ?>&amp;mode=groupe">Catégories identiques regroupées</a></div>
<form class="filter-row" method="get" data-scope-form><input type="hidden" name="mode" value="<?= h($mode) ?>">
<?= period_filter_html($per) ?>
<select name="account"><option value="0">Tous les comptes</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $accFilter === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach ?></select>
<select name="cur"><option value="">USD et FC</option><option value="USD" <?= $curFilter === 'USD' ? 'selected' : '' ?>>USD</option><option value="FC" <?= $curFilter === 'FC' ? 'selected' : '' ?>>FC</option></select>
<select name="type"><option value="">Entrées et sorties</option><option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Entrées seulement</option><option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Sorties seulement</option></select>
<input name="search" placeholder="Catégorie ou libellé (facultatif)" value="<?= h($search) ?>">
<select name="detail"><option value="1" <?= $detail ? 'selected' : '' ?>>Avec chaque opération</option><option value="0" <?= !$detail ? 'selected' : '' ?>>Totaux seulement</option></select>
<button class="btn primary">Afficher</button></form></div>

<div id="printArea">
<?= print_head($title, $per['label'] . ' · ' . $accName . ($search !== '' ? ' · « ' . $search . ' »' : '')) ?>
<?php if ($truncated): ?><div class="alert">Plus de 20 000 opérations : le rapport est tronqué. Réduisez la période.</div><?php endif ?>
<div class="table-wrap"><table><thead><tr><th>Catégorie</th><th>Opér.</th>
<?php foreach ($currencies as $c): ?><th>Entrées <?= $c ?></th><th>Sorties <?= $c ?></th><th>Solde <?= $c ?></th><?php endforeach ?><?php if ($mode === 'groupe'): ?><th>Écritures regroupées</th><?php endif ?></tr></thead><tbody>
<?php foreach ($groups as $g): ?><tr><td><b><?= h($g['name']) ?></b></td><td class="num"><?= $g['n'] ?></td>
<?php foreach ($currencies as $c): $i = $g['sum']['income'][$c]; $o = $g['sum']['expense'][$c]; ?><td class="num"><?= $i ? money($i, $c) : '—' ?></td><td class="num"><?= $o ? money($o, $c) : '—' ?></td><td class="num"><?= ($i || $o) ? money($i - $o, $c) : '—' ?></td><?php endforeach ?>
<?php if ($mode === 'groupe'): ?><td><?php $parts = []; foreach ($g['variants'] as $v => $n) $parts[] = h($v) . ' (' . $n . ')'; echo count($g['variants']) > 1 ? implode(' · ', $parts) : '—'; ?></td><?php endif ?></tr>
<?php endforeach ?>
<?php if (!$groups): ?><tr><td colspan="<?= 2 + 3 * count($currencies) + ($mode === 'groupe' ? 1 : 0) ?>" class="empty">Aucune opération pour cette sélection.</td></tr><?php else: ?>
<tr class="row-total"><th>Total</th><th class="num"><?= $tot['n'] ?></th><?php foreach ($currencies as $c): ?><th class="num"><?= money($tot['income'][$c], $c) ?></th><th class="num"><?= money($tot['expense'][$c], $c) ?></th><th class="num"><?= money($tot['income'][$c] - $tot['expense'][$c], $c) ?></th><?php endforeach ?><?php if ($mode === 'groupe'): ?><th></th><?php endif ?></tr><?php endif ?>
</tbody></table></div>

<?php if ($detail && $groups): ?>
<h3 class="cur-title">Détail de chaque opération par catégorie</h3>
<?php foreach ($groups as $g): ?>
<div class="cat-block"><h4><?= h($g['name']) ?> <small>— <?= $g['n'] ?> opération(s)<?php foreach ($currencies as $c): $i = $g['sum']['income'][$c]; $o = $g['sum']['expense'][$c]; if ($i || $o): ?> · <?= $c ?> : +<?= money($i, $c) ?> / −<?= money($o, $c) ?><?php endif; endforeach ?></small></h4>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Compte</th><th>Libellé</th><th>Catégorie enregistrée</th><th>Entrée</th><th>Sortie</th><th class="no-print">Fiche</th></tr></thead><tbody>
<?php foreach ($g['ops'] as $o): ?><tr><td><?= fmt_dmy($o['tx_date']) ?></td><td><?= h($o['account_name']) ?></td><td><?= h($o['label']) ?></td><td><?= h(trim((string)$o['cat'])) ?></td>
<td class="num"><?= $o['type'] === 'income' ? money($o['amount'], $o['currency']) : '' ?></td><td class="num"><?= $o['type'] === 'expense' ? money($o['amount'], $o['currency']) : '' ?></td><td class="no-print"><a href="operation.php?id=<?= (int)$o['id'] ?>">n° <?= (int)$o['id'] ?></a></td></tr>
<?php endforeach ?></tbody></table></div></div>
<?php endforeach ?><?php endif ?>
</div>
<?php include 'partials/footer.php'; ?>
