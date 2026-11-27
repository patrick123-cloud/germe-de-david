<?php
// Comptes et catégories : ajout sans doublon, désactivation, suppression, import des libellés déjà saisis.
require_once 'tx_lib.php';
require_role(['admin']);
$pdo = db();

function kind_fr(string $k): string { return ['income' => 'Recette', 'expense' => 'Dépense', 'both' => 'Recette et dépense'][$k] ?? $k; }
function js_confirm(string $text): string { return 'return confirm(' . h(json_encode($text, JSON_UNESCAPED_UNICODE)) . ')'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $msg = '';
    try {
        switch ($action) {

            case 'account':
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
                if ($name === '') { $msg = 'Le nom du compte est obligatoire.'; break; }
                $ex = $pdo->prepare('SELECT name FROM accounts WHERE name=?'); $ex->execute([$name]);
                if ($row = $ex->fetch()) { $msg = 'Le compte « ' . $row['name'] . ' » existe déjà : aucun doublon créé.'; break; }
                $pdo->prepare('INSERT INTO accounts(name,description) VALUES(?,?)')->execute([$name, trim((string)($_POST['description'] ?? ''))]);
                audit('CREATE', 'accounts', (int)$pdo->lastInsertId(), $name);
                $msg = 'Compte ajouté.';
                break;

            case 'category':
                $acc = (int)($_POST['account_id'] ?? 0);
                $name = clean_cat_name((string)($_POST['category_name'] ?? ''));
                $kind = (string)($_POST['kind'] ?? '');
                $v = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE id=?'); $v->execute([$acc]);
                if ($name === '' || !in_array($kind, ['income', 'expense', 'both'], true) || (int)$v->fetchColumn() === 0) { $msg = 'Compte, nom ou type de catégorie invalide.'; break; }
                // « CARBURANT » et « Carburant » sont la même catégorie : on réutilise l'existante au lieu de planter (erreur 1062).
                $r = ensure_category($pdo, $acc, $name, $kind);
                if ($r['created']) { audit('CREATE', 'categories', $r['id'], $r['name']); $msg = 'Catégorie « ' . $r['name'] . ' » ajoutée.'; }
                elseif ($r['widened']) $msg = 'La catégorie « ' . $r['name'] . ' » existe déjà dans ce compte ; elle sert maintenant aux recettes et aux dépenses.';
                else $msg = 'La catégorie « ' . $r['name'] . ' » existe déjà dans ce compte : aucun doublon créé.';
                break;

            case 'delete_category':
                $id = (int)($_POST['id'] ?? 0);
                $q = $pdo->prepare('SELECT * FROM categories WHERE id=?'); $q->execute([$id]); $cat = $q->fetch();
                if (!$cat) { $msg = 'Catégorie introuvable (déjà supprimée ?).'; break; }
                $n = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE category_id=?'); $n->execute([$id]); $n = (int)$n->fetchColumn();
                if ($n > 0 && !tx_has_category_col(true)) { $msg = 'Suppression refusée : lancez d\'abord « Mise à jour & réparation » (le nom de catégorie doit être copié sur chaque opération).'; break; }
                $pdo->beginTransaction();
                if ($n > 0) {
                    // Les opérations gardent le NOM de leur catégorie : les rapports restent identiques.
                    $pdo->prepare("UPDATE transactions SET category_name=? WHERE category_id=? AND (category_name IS NULL OR TRIM(category_name)='')")->execute([$cat['name'], $id]);
                }
                $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
                audit('DELETE', 'categories', $id, json_encode($cat, JSON_UNESCAPED_UNICODE));
                $pdo->commit();
                $msg = 'Catégorie « ' . $cat['name'] . ' » supprimée.' . ($n > 0 ? ' Les ' . $n . ' opération(s) déjà enregistrées gardent ce nom dans les rapports.' : '');
                break;

            case 'toggle_account':
                $id = (int)($_POST['id'] ?? 0);
                $q = $pdo->prepare('SELECT name,active FROM accounts WHERE id=?'); $q->execute([$id]); $a = $q->fetch();
                if (!$a) { $msg = 'Compte introuvable.'; break; }
                $new = (int)$a['active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE accounts SET active=? WHERE id=?')->execute([$new, $id]);
                audit('UPDATE', 'accounts', $id, ($new ? 'Activation : ' : 'Désactivation : ') . $a['name']);
                $msg = 'Compte « ' . $a['name'] . ' » ' . ($new ? 'réactivé.' : 'désactivé (ses opérations sont conservées ; il n\'apparaît plus pour la saisie).');
                break;

            case 'delete_account':
                $id = (int)($_POST['id'] ?? 0);
                $q = $pdo->prepare('SELECT * FROM accounts WHERE id=?'); $q->execute([$id]); $a = $q->fetch();
                if (!$a) { $msg = 'Compte introuvable (déjà supprimé ?).'; break; }
                $c = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE account_id=?'); $c->execute([$id]); $n = (int)$c->fetchColumn();
                if ($n > 0 && trim((string)($_POST['confirm_text'] ?? '')) !== 'SUPPRIMER') {
                    $msg = 'Le compte « ' . $a['name'] . ' » contient ' . $n . ' opération(s). Pour le supprimer avec ses opérations, tapez SUPPRIMER dans le champ de confirmation (ou désactivez-le pour tout conserver).';
                    break;
                }
                $pdo->beginTransaction();
                if ($n > 0) { // copie complète des opérations dans le journal d'audit (restaurable)
                    $rs = $pdo->prepare('SELECT id,tx_date,type,currency,amount,label FROM transactions WHERE account_id=? ORDER BY tx_date,id'); $rs->execute([$id]);
                    $parts = array_chunk($rs->fetchAll(PDO::FETCH_NUM), 100); $tot = count($parts);
                    foreach ($parts as $i => $part) audit('DELETE_BULK', 'transactions', null, json_encode(['compte' => $a['name'], 'bloc' => ($i + 1) . '/' . $tot, 'lignes[id,date,type,devise,montant,libellé]' => $part], JSON_UNESCAPED_UNICODE));
                }
                foreach (['transactions', 'monthly_inventories', 'account_openings', 'categories'] as $t)
                    $pdo->prepare("DELETE FROM $t WHERE account_id=?")->execute([$id]);
                $pdo->prepare('DELETE FROM accounts WHERE id=?')->execute([$id]);
                audit('DELETE', 'accounts', $id, json_encode(['compte' => $a, 'operations_supprimees' => $n], JSON_UNESCAPED_UNICODE));
                $pdo->commit();
                $msg = 'Compte « ' . $a['name'] . ' » supprimé' . ($n > 0 ? ' avec ses ' . $n . ' opération(s) (copie dans le journal d\'audit).' : '.');
                break;

            case 'sync_categories':
                // Crée une catégorie pour chaque libellé/catégorie déjà saisi qui n'est pas encore configuré.
                $expr = tx_has_category_col(true) ? "COALESCE(NULLIF(TRIM(category_name),''),TRIM(label))" : 'TRIM(label)';
                $rows = $pdo->query("SELECT account_id,$expr AS nm,type FROM transactions GROUP BY account_id,$expr,type")->fetchAll();
                $made = 0;
                $pdo->beginTransaction();
                foreach ($rows as $r) {
                    $res = ensure_category($pdo, (int)$r['account_id'], (string)$r['nm'], (string)$r['type']);
                    if ($res && $res['created']) $made++;
                }
                audit('CREATE', 'categories', null, 'Import des libellés existants : ' . $made . ' catégorie(s)');
                $pdo->commit();
                $msg = $made > 0 ? $made . ' nouvelle(s) catégorie(s) créée(s) à partir des libellés déjà saisis.' : 'Tous les libellés déjà saisis sont déjà configurés comme catégories.';
                break;

            default:
                $msg = 'Action inconnue.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = ($e instanceof PDOException && (string)$e->getCode() === '23000')
            ? 'Action impossible : cet élément existe déjà ou est encore utilisé ailleurs.'
            : 'Action impossible : ' . $e->getMessage();
    }
    flash_tx($msg);               // évite aussi de renvoyer le formulaire en actualisant la page
    header('Location: accounts.php');
    exit;
}

$accounts = $pdo->query('SELECT * FROM accounts ORDER BY id')->fetchAll();
$accOps = $pdo->query('SELECT account_id,COUNT(*) n FROM transactions GROUP BY account_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$catOps = $pdo->query('SELECT category_id,COUNT(*) n FROM transactions WHERE category_id IS NOT NULL GROUP BY category_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$cats = $pdo->query('SELECT c.*,a.name account_name FROM categories c LEFT JOIN accounts a ON a.id=c.account_id ORDER BY a.name,c.name')->fetchAll();
$page_title = 'Comptes et catégories';
include 'partials/header.php';
?>
<div class="grid two">
<div class="panel"><h2>Créer un compte</h2><p>Ajoutez un compte financier supplémentaire selon les besoins de l'église.</p>
<form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="account"><label>Nom du compte</label><input name="name" required placeholder="Ex. Construction"><label>Description</label><textarea name="description" rows="3"></textarea><button class="btn primary">Ajouter le compte</button></form></div>
<div class="panel"><h2>Ajouter une catégorie</h2><p>Ex. carburant, entretien, transport, achat de matériel… Un libellé nouveau saisi dans « Recettes / dépenses » est ajouté tout seul ici : cette étape n'est utile que pour préparer une catégorie à l'avance.</p>
<form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="category"><label>Compte concerné</label><select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?><?= (int)$a['active'] === 1 ? '' : ' (désactivé)' ?></option><?php endforeach ?></select><label>Nom de la catégorie</label><input name="category_name" required maxlength="120"><label>Utilisation</label><select name="kind"><option value="expense">Dépense</option><option value="income">Recette</option><option value="both">Les deux</option></select><button class="btn primary">Ajouter la catégorie</button></form></div>
</div>

<div class="panel"><h2>Comptes existants</h2>
<div class="table-wrap"><table><thead><tr><th>Compte</th><th>Description</th><th>Opérations</th><th>Statut</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($accounts as $a): $n = (int)($accOps[$a['id']] ?? 0); $on = (int)$a['active'] === 1; ?>
<tr><td><?= h($a['name']) ?></td><td><?= h($a['description']) ?></td><td class="num"><?= $n ?></td><td><?= $on ? 'Actif' : 'Désactivé' ?></td>
<td>
<form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_account"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn"><?= $on ? 'Désactiver' : 'Réactiver' ?></button></form>
<form method="post" style="display:inline" onsubmit="<?= js_confirm($n > 0 ? 'Supprimer le compte « ' . $a['name'] . ' » AVEC ses ' . $n . ' opération(s), ses soldes, ses clôtures et ses catégories ? Une copie reste dans le journal d\'audit.' : 'Supprimer le compte « ' . $a['name'] . ' » ?') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete_account"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><?php if ($n > 0): ?><input name="confirm_text" autocomplete="off" placeholder="Tapez SUPPRIMER" style="width:130px"> <?php endif ?><button class="link-danger">Supprimer</button></form>
</td></tr>
<?php endforeach ?>
<?php if (!$accounts): ?><tr><td colspan="5" class="empty">Aucun compte.</td></tr><?php endif ?>
</tbody></table></div>
<p style="margin-top:10px"><small>« Désactiver » garde toutes les opérations et le compte disparaît seulement de la saisie. « Supprimer » efface le compte ; s'il contient des opérations, il faut taper SUPPRIMER.</small></p></div>

<div class="panel"><div class="panel-head"><div><h2>Catégories disponibles</h2><p>Quand une opération est enregistrée avec un libellé qui n'est pas encore une catégorie, la catégorie est créée automatiquement : les rapports affichent alors ce nom au lieu de « Autres ».</p></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync_categories"><button class="btn" onclick="<?= js_confirm('Créer une catégorie pour chaque libellé déjà saisi qui n\'est pas encore configuré ? (Aucune opération n\'est modifiée.)') ?>">Importer les libellés déjà saisis</button></form></div>
<div class="table-wrap"><table><thead><tr><th>Compte</th><th>Catégorie</th><th>Type</th><th>Opérations liées</th><th>Action</th></tr></thead><tbody>
<?php foreach ($cats as $c): $n = (int)($catOps[$c['id']] ?? 0); ?>
<tr><td><?= h($c['account_name'] ?? 'Tous comptes') ?></td><td><?= h($c['name']) ?></td><td><?= h(kind_fr((string)$c['kind'])) ?></td><td class="num"><?= $n ?></td>
<td><form method="post" style="display:inline" onsubmit="<?= js_confirm('Supprimer la catégorie « ' . $c['name'] . ' » ?' . ($n > 0 ? ' Les ' . $n . ' opération(s) liées gardent ce nom dans les rapports.' : '')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="link-danger">Supprimer</button></form></td></tr>
<?php endforeach ?>
<?php if (!$cats): ?><tr><td colspan="5" class="empty">Aucune catégorie.</td></tr><?php endif ?>
</tbody></table></div></div>
<?php include 'partials/footer.php'; ?>
