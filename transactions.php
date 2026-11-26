<?php
// Recettes / dépenses : saisie, modification, recherche (jour, mois, année, période, global), suppression, impression.
require_once 'tx_lib.php';
require_login();
$pdo = db();
$message = '';
$edit = null;
$canWrite = in_array(role(), ['admin', 'treasurer'], true);

// ---------- Enregistrement / modification ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    require_role(['admin', 'treasurer']);
    $action = $_POST['action'] ?? 'save';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        // Conservé pour compatibilité : même règle que delete_operation.php
        $q = $pdo->prepare('SELECT * FROM transactions WHERE id=?'); $q->execute([$id]); $old = $q->fetch();
        if (!$old) $message = 'Opération introuvable.';
        elseif (period_locked((int)$old['account_id'], $old['tx_date'])) $message = 'Suppression refusée : ce mois est clôturé.';
        else {
            $pdo->prepare('DELETE FROM transactions WHERE id=?')->execute([$id]);
            audit('DELETE', 'transactions', $id, json_encode($old, JSON_UNESCAPED_UNICODE));
            $message = 'Opération supprimée et journalisée.';
        }
    } else {
        $account = (int)($_POST['account_id'] ?? 0);
        $cat = (int)($_POST['category_id'] ?? 0) ?: null;
        $date = $_POST['tx_date'] ?? '';
        $type = $_POST['type'] ?? '';
        $cur = $_POST['currency'] ?? '';
        $amount = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
        $label = trim($_POST['label'] ?? '');
        $catText = trim((string)($_POST['category_name'] ?? ''));
        $catRow = null;
        if ($cat !== null) {
            $q = $pdo->prepare("SELECT name FROM categories WHERE id=? AND (account_id=? OR account_id IS NULL) AND (kind=? OR kind='both')");
            $q->execute([$cat, $account, $type]);
            $catRow = $q->fetch();
        }
        if ($amount <= 0 || !in_array($type, ['income', 'expense'], true) || !in_array($cur, ['USD', 'FC'], true) || !valid_ymd($date) || $label === '') $message = 'Vérifiez les informations saisies.';
        elseif (!$pdo->query('SELECT id FROM accounts WHERE id=' . (int)$account . ' AND active=1')->fetchColumn()) $message = 'Compte invalide ou désactivé.';
        elseif ($cat !== null && !$catRow) $message = 'La catégorie sélectionnée ne correspond pas au compte ou au type d’opération.';
        elseif (period_locked($account, $date)) $message = 'Ce mois est clôturé. Un administrateur peut le rouvrir (Rapports et inventaires → « Rouvrir ou recalculer un mois clôturé »).';
        else {
            // Catégorie dans son propre champ : texte saisi > catégorie prédéfinie > libellé.
            // Un nom qui n'est pas encore configuré (ex. un nouveau libellé) est AJOUTÉ automatiquement aux catégories du compte :
            // les rapports l'affichent sous son propre nom au lieu de « Autres ».
            $newCatNote = '';
            if ($catText === '' && $catRow !== null) {
                $catName = mb_substr((string)$catRow['name'], 0, 180);   // catégorie prédéfinie choisie
            } else {
                $auto = ensure_category($pdo, $account, $catText !== '' ? $catText : $label, $type);
                if ($auto) {
                    $cat = $auto['id']; $catName = $auto['name'];
                    if ($auto['created']) $newCatNote = ' Nouvelle catégorie « ' . $auto['name'] . ' » créée automatiquement.';
                } else {
                    $catName = mb_substr($catText !== '' ? $catText : $label, 0, 180);
                }
            }
            $hasCol = tx_has_category_col();
            if ($id) {
                $oq = $pdo->prepare('SELECT * FROM transactions WHERE id=?'); $oq->execute([$id]); $old = $oq->fetch();
                if (!$old || period_locked((int)$old['account_id'], $old['tx_date'])) $message = 'Modification refusée : opération introuvable ou mois clôturé.';
                else {
                    $sql = 'UPDATE transactions SET account_id=?,category_id=?,tx_date=?,type=?,currency=?,amount=?,label=?,description=?,updated_by=?,updated_at=NOW()' . ($hasCol ? ',category_name=?' : '') . ' WHERE id=?';
                    $args = [$account, $cat, $date, $type, $cur, $amount, $label, null, $_SESSION['user_id']];
                    if ($hasCol) $args[] = $catName;
                    $args[] = $id;
                    $pdo->prepare($sql)->execute($args);
                    audit('UPDATE', 'transactions', $id, json_encode(['avant' => $old, 'apres' => $_POST], JSON_UNESCAPED_UNICODE));
                    $message = 'Opération modifiée.' . $newCatNote;
                }
            } else {
                $sql = 'INSERT INTO transactions(account_id,category_id,tx_date,type,currency,amount,label,description,created_by' . ($hasCol ? ',category_name' : '') . ') VALUES(?,?,?,?,?,?,?,?,?' . ($hasCol ? ',?' : '') . ')';
                $args = [$account, $cat, $date, $type, $cur, $amount, $label, null, $_SESSION['user_id']];
                if ($hasCol) $args[] = $catName;
                $pdo->prepare($sql)->execute($args);
                $id = (int)$pdo->lastInsertId();
                audit('CREATE', 'transactions', $id, $label);
                $message = 'Opération enregistrée.' . $newCatNote;
            }
        }
    }
}
if (isset($_GET['edit'])) {
    $q = $pdo->prepare('SELECT * FROM transactions WHERE id=?'); $q->execute([(int)$_GET['edit']]); $edit = $q->fetch() ?: null;
}

// ---------- Recherche ----------
$accounts = $pdo->query('SELECT * FROM accounts WHERE active=1 ORDER BY name')->fetchAll();
$cats = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$per = period_from_request($_GET, 'month');
[$where, $params] = tx_filters($_GET, $per);
$fromSql = tx_from_sql();
$catExpr = tx_cat_expr();
$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = in_array($_GET['type'] ?? '', ['income', 'expense'], true) ? $_GET['type'] : '';
$curFilter = in_array($_GET['cur'] ?? '', ['USD', 'FC'], true) ? $_GET['cur'] : '';
$accFilter = (int)($_GET['account'] ?? 0);

// Totaux de TOUS les résultats (pas seulement de la page affichée)
$sq = $pdo->prepare("SELECT t.currency cur,t.type ty,SUM(t.amount) s,COUNT(*) n FROM $fromSql WHERE $where GROUP BY t.currency,t.type");
$sq->execute($params);
$sum = ['USD' => ['income' => 0.0, 'expense' => 0.0], 'FC' => ['income' => 0.0, 'expense' => 0.0]];
$total = 0;
foreach ($sq->fetchAll() as $r) { $sum[$r['cur']][$r['ty']] = (float)$r['s']; $total += (int)$r['n']; }

$perPage = 100;
$pages = max(1, (int)ceil($total / $perPage));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$q = $pdo->prepare("SELECT t.*,a.name account_name,$catExpr category_label,u.full_name creator FROM $fromSql WHERE $where ORDER BY t.tx_date DESC,t.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$q->execute($params);
$rows = $q->fetchAll();

$baseQuery = filter_query($per, $_GET);
$returnUrl = 'transactions.php?' . $baseQuery . '&page=' . $page;
$page_title = 'Recettes et dépenses';
include 'partials/header.php';
?>
<?php if ($message): ?><div class="notice"><?= h($message) ?></div><?php endif ?>

<div class="panel no-print"><h2><?= $edit ? 'Modifier une opération' : 'Enregistrer une opération' ?></h2>
<p>Les périodes clôturées sont protégées. Seuls les administrateurs et trésoriers peuvent saisir.</p>
<?php if ($canWrite): ?>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= h($edit['id'] ?? 0) ?>">
<div><label>Compte</label><select name="account_id" required><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= ($edit['account_id'] ?? '') == $a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach ?></select></div>
<div><label>Catégorie prédéfinie (facultative)</label><select name="category_id" id="transactionCategory"><option value="">— Sans catégorie —</option><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" data-account="<?= $c['account_id'] ?>" data-kind="<?= h($c['kind']) ?>" <?= ($edit['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach ?></select></div>
<div><label>Date</label><input type="date" name="tx_date" value="<?= h($edit['tx_date'] ?? date('Y-m-d')) ?>" required></div>
<div><label>Type</label><select name="type"><option value="income" <?= ($edit['type'] ?? '') === 'income' ? 'selected' : '' ?>>Recette / Entrée</option><option value="expense" <?= ($edit['type'] ?? '') === 'expense' ? 'selected' : '' ?>>Dépense / Sortie</option></select></div>
<div><label>Devise</label><select name="currency"><option value="USD" <?= ($edit['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>Dollar ($)</option><option value="FC" <?= ($edit['currency'] ?? 'FC') === 'FC' ? 'selected' : '' ?>>Franc congolais (FC)</option></select></div>
<div><label>Montant</label><input type="number" name="amount" min="0.01" step="0.01" value="<?= h($edit['amount'] ?? '') ?>" required></div>
<div class="span-2"><label>Libellé</label><input name="label" value="<?= h($edit['label'] ?? '') ?>" required></div>
<?php
$editCat = '';
if ($edit && !empty($edit['category_name']) && norm_key((string)$edit['category_name']) !== norm_key((string)$edit['label'])) $editCat = (string)$edit['category_name'];
?>
<div class="span-2"><label>Catégorie (champ séparé — facultatif)</label><input name="category_name" list="catSuggest" maxlength="180" autocomplete="off" value="<?= h($editCat) ?>" placeholder="Laisser vide : la catégorie sera la catégorie prédéfinie ou, à défaut, le libellé"><datalist id="catSuggest"><?php foreach ($cats as $c): ?><option value="<?= h($c['name']) ?>"><?php endforeach ?></datalist></div>
<div class="span-2"><button class="btn primary">Enregistrer</button> <?php if ($edit): ?><a class="btn" href="transactions.php">Annuler</a><?php endif ?></div></form>
<?php else: ?><p>Votre rôle permet uniquement la consultation.</p><?php endif ?></div>

<div class="panel"><div class="panel-head no-print"><div><h2>Historique et recherche</h2><p>Retrouvez une opération par jour, par mois, par année, sur une période libre ou sur l'ensemble des données. Le texte cherché porte sur le libellé et la catégorie.</p></div>
<div class="filter-actions"><a class="btn" href="dashboard.php">⌂ Retour à l’accueil</a>
<a class="btn" href="export.php?<?= h($baseQuery) ?>">Exporter Excel (CSV)</a>
<a class="btn" href="rapport_periodique.php?gran=detail&amp;<?= h($baseQuery) ?>&amp;print=1">Rapport détaillé imprimable</a>
<button type="button" class="btn primary" data-preview="#printArea">Aperçu et impression</button></div></div>

<form class="filter-row no-print" method="get" data-scope-form>
<?= period_filter_html($per) ?>
<input name="search" placeholder="Libellé ou catégorie : carburant pasteur, dîme, garde…" value="<?= h($search) ?>" style="flex:1;min-width:220px">
<select name="account"><option value="0">Tous les comptes</option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>" <?= $accFilter === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach ?></select>
<select name="type"><option value="">Entrées et sorties</option><option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Sorties seulement</option><option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Entrées seulement</option></select>
<select name="cur"><option value="">USD et FC</option><option value="USD" <?= $curFilter === 'USD' ? 'selected' : '' ?>>USD</option><option value="FC" <?= $curFilter === 'FC' ? 'selected' : '' ?>>FC</option></select>
<button class="btn primary">Rechercher</button> <a class="btn" href="transactions.php">Réinitialiser</a></form>

<div id="printArea">
<?= print_head('Opérations enregistrées', $per['label'] . ($search !== '' ? ' · recherche « ' . $search . ' »' : '')) ?>
<div class="grid cards" style="margin:14px 0"><?php foreach (['USD', 'FC'] as $cu): if ($curFilter && $curFilter !== $cu) continue; $in = $sum[$cu]['income']; $out = $sum[$cu]['expense']; ?>
<article class="stat-card"><div class="stat-top"><span>Résultat en <?= $cu ?></span></div>
<div class="stat-sub">Entrées : <b><?= money($in, $cu) ?></b></div><div class="stat-sub">Sorties : <b><?= money($out, $cu) ?></b></div><div class="stat-value" style="font-size:18px">Solde : <?= money($in - $out, $cu) ?></div></article><?php endforeach ?>
<article class="stat-card"><div class="stat-top"><span>Opérations trouvées</span></div><div class="stat-value"><?= $total ?></div><div class="stat-foot"><?= h($per['label']) ?></div></article></div>

<div class="table-wrap"><table><thead><tr><th>Date</th><th>Compte</th><th>Type</th><th>Catégorie</th><th>Libellé</th><th>Devise</th><th>Montant</th><th>Enregistré par</th><th class="no-print">Actions</th></tr></thead><tbody>
<?php foreach ($rows as $r): $locked = period_locked((int)$r['account_id'], $r['tx_date']); ?>
<tr><td><?= fmt_dmy($r['tx_date']) ?></td><td><?= h($r['account_name']) ?></td><td><?= $r['type'] === 'income' ? 'Entrée' : 'Sortie' ?></td><td><?= h($r['category_label'] ?? '—') ?></td><td><?= h($r['label']) ?></td><td><?= h($r['currency']) ?></td><td class="num"><?= money($r['amount'], $r['currency']) ?></td><td><?= h($r['creator'] ?? '—') ?></td>
<td class="no-print"><a href="operation.php?id=<?= $r['id'] ?>">Fiche</a> · <a href="operation.php?id=<?= $r['id'] ?>&amp;print=1">Imprimer</a>
<?php if ($canWrite && !$locked): ?> · <a href="?edit=<?= $r['id'] ?>">Modifier</a>
<form method="post" action="delete_operation.php" style="display:inline" onsubmit="return confirm('Supprimer définitivement cette opération (<?= h(addslashes($r['label'])) ?>) ?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="return" value="<?= h($returnUrl) ?>"> · <button class="link-danger">Supprimer</button></form>
<?php elseif ($locked): ?> · Verrouillé<?php endif ?></td></tr>
<?php endforeach ?>
<?php if (!$rows): ?><tr><td colspan="9" class="empty">Aucune opération trouvée.</td></tr><?php endif ?></tbody></table></div>
<?php if ($pages > 1): ?><p class="no-print" style="margin-top:12px">Page <?= $page ?> / <?= $pages ?> — <?php if ($page > 1): ?><a class="btn" href="transactions.php?<?= h($baseQuery) ?>&amp;page=<?= $page - 1 ?>">← Précédente</a> <?php endif ?><?php if ($page < $pages): ?><a class="btn" href="transactions.php?<?= h($baseQuery) ?>&amp;page=<?= $page + 1 ?>">Suivante →</a><?php endif ?> · Les totaux ci-dessus portent sur les <?= $total ?> résultats ; pour tout imprimer, utilisez « Rapport détaillé imprimable ».</p><?php endif ?>
</div></div>
<script>
const accountSelect=document.querySelector('select[name=account_id]'),categorySelect=document.getElementById('transactionCategory'),typeSelect=document.querySelector('form.form-grid select[name=type]');
function filterCategories(){if(!categorySelect)return;const account=accountSelect?.value,type=typeSelect?.value;for(const option of categorySelect.options){if(!option.value)continue;const accountValid=option.dataset.account===account||option.dataset.account==='null'||option.dataset.account==='',kindValid=option.dataset.kind==='both'||option.dataset.kind===type,valid=accountValid&&kindValid;option.hidden=!valid;if(!valid&&option.selected)option.selected=false;}}
accountSelect?.addEventListener('change',filterCategories);typeSelect?.addEventListener('change',filterCategories);filterCategories();
</script>
<?php include 'partials/footer.php'; ?>
