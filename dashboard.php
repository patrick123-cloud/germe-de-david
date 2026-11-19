<?php
require_once 'config.php';require_login();$year=(int)($_GET['year']??date('Y'));ensure_year($year);
$pdo=db();
$accounts=$pdo->query("SELECT * FROM accounts WHERE active=1 ORDER BY id")->fetchAll();
$accountSums=[];
$sumQuery=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income_total, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense_total FROM transactions WHERE account_id=? AND currency=? AND YEAR(tx_date)=?");
foreach($accounts as $account){
  foreach(['USD','FC'] as $currency){
    $sumQuery->execute([(int)$account['id'],$currency,$year]);
    $totals=$sumQuery->fetch();
    $opening=balance_for((int)$account['id'],$year,$currency)['opening_amount'];
    $accountSums[]=['name'=>$account['name'],'currency'=>$currency,'income'=>(float)$totals['income_total'],'expense'=>(float)$totals['expense_total'],'balance'=>$opening+(float)$totals['income_total']-(float)$totals['expense_total']];
  }
}
$page_title='Tableau de bord';include 'partials/header.php';
?>
<div class="hero"><div><span class="eyebrow">SUIVI FINANCIER · <?=h($year)?></span><h1>Bienvenue dans votre espace financier</h1><p>Suivez les recettes, les dépenses et les soldes de chaque compte.</p><a class="btn" href="converter.php">Convertir USD ⇄ FC</a></div><form><select name="year" onchange="this.form.submit()"><?php for($y=date('Y')+1;$y>=date('Y')-5;$y--):?><option <?=$year===$y?'selected':''?>><?=$y?></option><?php endfor?></select></form></div>
<div class="grid cards"><?php foreach($accounts as $a):$usd=balance_for((int)$a['id'],$year,'USD');$fc=balance_for((int)$a['id'],$year,'FC');?><article class="stat-card"><div class="stat-top"><span><?=h($a['name'])?></span><span class="dot"></span></div><div class="stat-value"><?=money($usd['balance'],'USD')?></div><div class="stat-sub"><?=money($fc['balance'],'FC')?></div><div class="stat-foot">Solde actuel · USD et FC séparés</div><a href="reports.php?account=<?=$a['id']?>&year=<?=$year?>">Voir le rapport →</a></article><?php endforeach?></div>
<div class="panel"><div class="panel-head"><div><h2>Sommes des comptes par type</h2><p>Total des recettes, dépenses et soldes par compte, en USD et en FC séparément — <?=h($year)?></p></div></div><div class="table-wrap"><table><thead><tr><th>Type de compte</th><th>Devise</th><th>Total des entrées</th><th>Total des sorties</th><th>Solde du compte</th></tr></thead><tbody><?php foreach($accountSums as $sum):?><tr><td><?=h($sum['name'])?></td><td><?=h($sum['currency'])?></td><td class="num"><?=money($sum['income'],$sum['currency'])?></td><td class="num"><?=money($sum['expense'],$sum['currency'])?></td><td class="num"><?=money($sum['balance'],$sum['currency'])?></td></tr><?php endforeach?></tbody></table></div></div>
<div class="panel"><div class="panel-head"><div><h2>Dernières opérations</h2><p>Les dix dernières écritures enregistrées</p></div><a class="btn primary" href="transactions.php">＋ Nouvelle opération</a></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Compte</th><th>Type</th><th>Catégorie</th><th>Libellé</th><th>Montant</th></tr></thead><tbody><?php $rows=db()->query("SELECT t.*,a.name account_name,c.name category_name FROM transactions t JOIN accounts a ON a.id=t.account_id LEFT JOIN categories c ON c.id=t.category_id ORDER BY tx_date DESC,t.id DESC LIMIT 10")->fetchAll();foreach($rows as $r):?><tr><td><?=date('d/m/Y',strtotime($r['tx_date']))?></td><td><?=h($r['account_name'])?></td><td><span class="tag <?=$r['type']?>"><?=$r['type']==='income'?'Recette':'Dépense'?></span></td><td><?=h($r['category_name']??'—')?></td><td><?=h($r['label'])?></td><td class="num"><?=money($r['amount'],$r['currency'])?></td></tr><?php endforeach?><?php if(!$rows):?><tr><td colspan="6" class="empty">Aucune opération pour le moment.</td></tr><?php endif?></tbody></table></div></div>
<?php include 'partials/footer.php';?>
