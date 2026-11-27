<?php
// Offrandes : calcul mensuel + somme par libellé (mois, année) — une seule requête groupée, calculs en mémoire.
require_once 'tx_lib.php';require_login();$pdo=db();
$accounts=active_accounts();
$defaultAccount=0;foreach($accounts as $a)if($a['name']==='Offrandes ordinaires'){$defaultAccount=(int)$a['id'];break;}
$account=isset($_GET['account'])?(int)$_GET['account']:$defaultAccount;
$year=(int)($_GET['year']??date('Y'));if($year<2000||$year>2100)$year=(int)date('Y');
$month=(int)($_GET['month']??date('n'));if($month<0||$month>12)$month=(int)date('n'); // 0 = toute l'année
$search=trim((string)($_GET['q']??''));
// Recherche par jour précis (facultative) : prioritaire sur le mois.
$day=trim((string)($_GET['day']??''));if(!valid_ymd($day))$day='';if($day!==''){$year=(int)substr($day,0,4);$month=(int)substr($day,5,2);}
$mCur=in_array($_GET['cur']??'',['USD','FC'],true)?$_GET['cur']:'FC';
$mType=in_array($_GET['type']??'',['income','expense'],true)?$_GET['type']:'income';
$accountName='Tous les comptes';foreach($accounts as $a)if((int)$a['id']===$account)$accountName=$a['name'];
if($account&&$accountName==='Tous les comptes')$account=0;

// 0) Suppression groupée (admin/trésorier) : aperçu puis confirmation écrite. Mois clôturés protégés.
require_once __DIR__.'/bulk_delete_lib.php';
$canDelete=in_array(role(),['admin','treasurer'],true);$flash=$_SESSION['flash']??'';unset($_SESSION['flash']);$bulkError='';$bulkPreview=null;
$accountNames=[];foreach($accounts as $a)$accountNames[(int)$a['id']]=$a['name'];
if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($_POST['action']??'',['bulk_preview','bulk_delete'],true)){
  check_csrf();require_role(['admin','treasurer']);
  [$crit,$bulkError]=bulk_criteria($_POST,array_keys($accountNames));$bulkError=$bulkError??'';
  if($bulkError===''){
    $sel=bulk_select($pdo,$crit);
    if($_POST['action']==='bulk_delete'){
      if(($_POST['confirm_text']??'')!=='SUPPRIMER')$bulkError='Confirmation incorrecte : tapez exactement SUPPRIMER. Rien n\'a été supprimé.';
      elseif(count($sel['rows'])!==(int)($_POST['expected']??-1))$bulkError='Les données ont changé depuis l\'aperçu. Rien n\'a été supprimé : refaites l\'aperçu.';
      else{
        try{$n=bulk_delete($pdo,$sel['rows'],bulk_describe($crit,$accountNames));
          $_SESSION['flash']=$n.' opération(s) supprimée(s) et journalisée(s)'.($sel['locked']?' ; '.$sel['locked'].' ignorée(s) car le mois est clôturé':'').'.';
          header('Location: offrandes.php?'.http_build_query(['account'=>$crit['account'],'year'=>$crit['year'],'month'=>$crit['month']]));exit;
        }catch(Throwable $e){$bulkError='Échec de la suppression : aucune donnée n\'a été supprimée.';}
      }
    }
    if($bulkError===''||$_POST['action']==='bulk_delete')$bulkPreview=['crit'=>$crit,'rows'=>$sel['rows'],'locked'=>$sel['locked'],'summary'=>bulk_summary($sel['rows']),'desc'=>bulk_describe($crit,$accountNames)];
  }
}

// 1) Une requête : libellé × mois × devise × type.
$sql='SELECT TRIM(label) lbl,MONTH(tx_date) m,currency,type,SUM(amount) s,COUNT(*) n FROM transactions WHERE tx_date>=? AND tx_date<?'.($account?' AND account_id=?':'').' GROUP BY TRIM(label),MONTH(tx_date),currency,type';
$st=$pdo->prepare($sql);$args=[sprintf('%04d-01-01',$year),sprintf('%04d-01-01',$year+1)];if($account)$args[]=$account;$st->execute($args);
$norm=fn(string $v):string=>mb_strtolower(preg_replace('/\s+/u',' ',trim($v)),'UTF-8');
$labels=[]; // clé normalisée => ['name'=>affichage,'d'=>[type][cur][m]=somme,'n'=>[m]=nb]
foreach($st->fetchAll() as $r){
  $name=(string)$r['lbl'];if($name==='')$name='(sans libellé)';$k=$norm($name);
  if($search!==''&&mb_strpos($k,$norm($search))===false)continue;
  $labels[$k]??=['name'=>$name,'d'=>[],'n'=>[]];
  $labels[$k]['d'][$r['type']][$r['currency']][(int)$r['m']]=($labels[$k]['d'][$r['type']][$r['currency']][(int)$r['m']]??0)+(float)$r['s'];
  $labels[$k]['n'][(int)$r['m']]=($labels[$k]['n'][(int)$r['m']]??0)+(int)$r['n'];
}
uasort($labels,fn($a,$b)=>strcmp($norm($a['name']),$norm($b['name'])));
// Mode « jour » : mêmes agrégats, mais pour la seule date choisie (rangés sous le mois du jour pour réutiliser le calcul ci-dessous).
$labelsP=$labels;
if($day!==''){
  $dq=$pdo->prepare('SELECT TRIM(label) lbl,currency,type,SUM(amount) s,COUNT(*) n FROM transactions WHERE tx_date=?'.($account?' AND account_id=?':'').' GROUP BY TRIM(label),currency,type');
  $da=[$day];if($account)$da[]=$account;$dq->execute($da);$labelsP=[];
  foreach($dq->fetchAll() as $r){$name=(string)$r['lbl'];if($name==='')$name='(sans libellé)';$k=$norm($name);
    if($search!==''&&mb_strpos($k,$norm($search))===false)continue;
    $labelsP[$k]??=['name'=>$name,'d'=>[],'n'=>[]];
    $labelsP[$k]['d'][$r['type']][$r['currency']][$month]=($labelsP[$k]['d'][$r['type']][$r['currency']][$month]??0)+(float)$r['s'];
    $labelsP[$k]['n'][$month]=($labelsP[$k]['n'][$month]??0)+(int)$r['n'];}
  uasort($labelsP,fn($a,$b)=>strcmp($norm($a['name']),$norm($b['name'])));
}
$sumPeriod=function(array $L,string $type,string $cur)use($month):float{$t=0.0;foreach($L['d'][$type][$cur]??[] as $m=>$v)if($month===0||$m===$month)$t+=$v;return $t;};
$countPeriod=function(array $L)use($month):int{$t=0;foreach($L['n'] as $m=>$v)if($month===0||$m===$month)$t+=$v;return $t;};
$rows=[];$tot=['income'=>['USD'=>0.0,'FC'=>0.0],'expense'=>['USD'=>0.0,'FC'=>0.0]];$totCount=0;
foreach($labelsP as $L){
  $n=$countPeriod($L);if(!$n)continue;
  $row=['name'=>$L['name'],'n'=>$n];
  foreach(['income','expense'] as $t)foreach(['USD','FC'] as $c){$row[$t.$c]=$sumPeriod($L,$t,$c);$tot[$t][$c]+=$row[$t.$c];}
  $rows[]=$row;$totCount+=$n;
}
$periodLabel=$day!==''?'le '.fmt_dmy($day):($month===0?'toute l\'année '.$year:month_fr($month).' '.$year);

// 2) Export CSV (libellé × 12 mois) — respecte compte, année et recherche.
if(isset($_GET['csv'])){
  header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="offrandes_par_libelle_'.$year.'.csv"');echo "\xEF\xBB\xBF";$o=fopen('php://output','w');
  $safe=fn(string $v):string=>preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:$v;$num=fn(float $v):string=>number_format($v,2,',','');
  $head=['Libellé','Type','Devise'];for($m=1;$m<=12;$m++)$head[]=ucfirst(month_fr($m));$head[]='Total '.$year;fputcsv($o,$head,';');
  foreach($labels as $L)foreach(['income'=>'Entrée','expense'=>'Sortie'] as $t=>$tl)foreach(['USD','FC'] as $c){if(empty($L['d'][$t][$c]))continue;$line=[$safe($L['name']),$tl,$c];$sum=0.0;for($m=1;$m<=12;$m++){$v=$L['d'][$t][$c][$m]??0.0;$sum+=$v;$line[]=$num($v);}$line[]=$num($sum);fputcsv($o,$line,';');}
  fclose($o);exit;
}

// 3) Calcul mensuel du compte (entrées, sorties, solde du mois, solde cumulé reporté de mois en mois).
$mx=month_matrix($year,$account);ensure_year($year);
$ids=$account?[$account]:array_map(fn($a)=>(int)$a['id'],$accounts);
$monthTable=[];
foreach(['FC','USD'] as $cur){
  $op=0.0;foreach($ids as $id)$op+=balance_for($id,$year,$cur)['opening_amount'];
  for($m=1;$m<=12;$m++){$i=0.0;$e=0.0;foreach($ids as $id){$i+=$mx[$id][$cur][$m]['income']??0.0;$e+=$mx[$id][$cur][$m]['expense']??0.0;}
    $monthTable[$cur][$m]=['open'=>$op,'in'=>$i,'out'=>$e,'net'=>$i-$e,'close'=>$op+$i-$e];$op+=$i-$e;}
}
$qs=fn(array $over=[])=>http_build_query(array_merge(['account'=>$account,'year'=>$year,'month'=>$month,'q'=>$search,'day'=>$day,'cur'=>$mCur,'type'=>$mType],$over));
// Opérations correspondantes (affichées quand on cherche un libellé ou un jour précis).
$matchOps=[];$matchMore=false;
if($search!==''||$day!==''){
  if($day!==''){$f=$day;$t=$day;}elseif($month===0){$f=sprintf('%04d-01-01',$year);$t=sprintf('%04d-12-31',$year);}else{$f=sprintf('%04d-%02d-01',$year,$month);$t=date('Y-m-t',strtotime($f));}
  $mq=$pdo->prepare('SELECT t.id,t.tx_date,t.type,t.currency,t.amount,t.label,a.name account_name FROM transactions t JOIN accounts a ON a.id=t.account_id WHERE t.tx_date>=? AND t.tx_date<=?'.($account?' AND t.account_id=?':'').' ORDER BY t.tx_date,t.id');
  $ma=[$f,$t];if($account)$ma[]=$account;$mq->execute($ma);
  foreach($mq->fetchAll() as $r){if($search!==''&&mb_strpos($norm((string)$r['label']),$norm($search))===false)continue;if(count($matchOps)>=500){$matchMore=true;break;}$matchOps[]=$r;}
}
$page_title='Offrandes par mois et libellé';include 'partials/header.php';?>
<div class="panel no-print"><div class="panel-head"><div><h2>Offrandes — somme par mois et par libellé</h2><p>Choisissez le compte, l'année et le mois ; tapez un mot du libellé (ex. « dîme », « action de grâce », « carburant ») pour obtenir sa somme ; choisissez un jour précis pour ne voir que cette date.</p></div><div class="filter-actions"><a class="btn" href="offrandes.php?<?=h($qs(['csv'=>1]))?>">Exporter Excel (CSV)</a><button type="button" class="btn primary" data-preview="#printArea">Aperçu et impression</button></div></div>
<form class="filter-row"><select name="account"><option value="0">Tous les comptes</option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>" <?=$account===(int)$a['id']?'selected':''?>><?=h($a['name'])?></option><?php endforeach?></select><select name="year"><?php for($y=(int)date('Y')+1;$y>=(int)date('Y')-10;$y--):?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endfor?></select><select name="month"><option value="0" <?=$month===0?'selected':''?>>Toute l'année</option><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>" <?=$month===$m?'selected':''?>><?=h(ucfirst(month_fr($m)))?></option><?php endfor?></select><input name="q" placeholder="Rechercher un libellé…" value="<?=h($search)?>"><input type="date" name="day" value="<?=h($day)?>" title="Jour précis (facultatif — remplace le mois)" style="width:auto"><input type="hidden" name="cur" value="<?=h($mCur)?>"><input type="hidden" name="type" value="<?=h($mType)?>"><button class="btn primary">Afficher</button></form></div>

<?php if($flash):?><div class="notice"><?=h($flash)?></div><?php endif?>
<?php if($canDelete):?><details class="panel no-print bulk-del" <?=($bulkPreview||$bulkError)?'open':''?>><summary><b>Supprimer des offrandes (par libellé, mois ou type)</b></summary>
<p>Choisissez ce qu'il faut supprimer, puis cliquez sur « Aperçu » : rien n'est supprimé avant votre confirmation. Les mois clôturés sont protégés. Chaque suppression est enregistrée dans le journal d'audit.</p>
<?php if($bulkError):?><div class="alert"><?=h($bulkError)?></div><?php endif?>
<form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="bulk_preview">
<div><label>Compte</label><select name="account"><option value="0">Tous les comptes</option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>" <?=$account===(int)$a['id']?'selected':''?>><?=h($a['name'])?></option><?php endforeach?></select></div>
<div><label>Année</label><select name="year"><?php for($y=(int)date('Y')+1;$y>=(int)date('Y')-10;$y--):?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endfor?></select></div>
<div><label>Mois</label><select name="month"><option value="0" <?=$month===0?'selected':''?>>Toute l'année</option><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>" <?=$month===$m?'selected':''?>><?=h(ucfirst(month_fr($m)))?></option><?php endfor?></select></div>
<div><label>Type</label><select name="type"><option value="">Entrées et sorties</option><option value="income">Entrées seulement</option><option value="expense">Sorties seulement</option></select></div>
<div><label>Devise</label><select name="cur"><option value="">USD et FC</option><option value="USD">USD seulement</option><option value="FC">FC seulement</option></select></div>
<div><label>Correspondance du libellé</label><select name="match"><option value="exact">Libellé identique (majuscules/espaces ignorés)</option><option value="contains">Libellé contenant le texte</option></select></div>
<div class="span-2"><label>Libellé (facultatif — laisser vide pour tous les libellés)</label><input name="label" list="bulkLabels" autocomplete="off" placeholder="Ex. dîme, action de grâce…" value="<?=h($bulkPreview['crit']['label']??'')?>"><datalist id="bulkLabels"><?php foreach($labels as $L):?><option value="<?=h($L['name'])?>"><?php endforeach?></datalist></div>
<div class="span-2"><button class="btn primary">Aperçu de la suppression</button></div></form>
<?php if($bulkPreview):$bp=$bulkPreview;$cnt=count($bp['rows']);?><div class="bulk-preview"><h3>Aperçu : <?=h($bp['desc'])?></h3>
<?php if($cnt===0):?><p>Aucune opération ne correspond<?=$bp['locked']?' ('.$bp['locked'].' opération(s) dans des mois clôturés sont protégées)':''?>.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Compte</th><th>Type</th><th>Devise</th><th>Opér.</th><th>Montant total</th></tr></thead><tbody><?php foreach($bp['summary'] as $s):?><tr><td><?=h($s['account'])?></td><td><?=$s['type']==='income'?'Entrée':'Sortie'?></td><td><?=h($s['currency'])?></td><td class="num"><?=$s['n']?></td><td class="num"><?=money($s['sum'],$s['currency'])?></td></tr><?php endforeach?></tbody></table></div>
<details><summary>Voir les <?=min($cnt,200)?> premières opérations</summary><div class="table-wrap"><table><thead><tr><th>Date</th><th>Compte</th><th>Type</th><th>Libellé</th><th>Montant</th></tr></thead><tbody><?php foreach(array_slice($bp['rows'],0,200) as $r):?><tr><td><?=date('d/m/Y',strtotime($r['tx_date']))?></td><td><?=h($r['account_name'])?></td><td><?=$r['type']==='income'?'Entrée':'Sortie'?></td><td><?=h($r['label'])?></td><td class="num"><?=money($r['amount'],$r['currency'])?></td></tr><?php endforeach?></tbody></table></div></details>
<?php if($bp['locked']):?><p class="alert"><?=$bp['locked']?> opération(s) dans des mois clôturés ne seront pas supprimées.</p><?php endif?>
<div class="alert"><strong><?=$cnt?> opération(s) seront définitivement supprimées.</strong> Téléchargez une sauvegarde avant si nécessaire.</div>
<form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="bulk_delete"><input type="hidden" name="expected" value="<?=$cnt?>"><?php foreach(['account','year','month','type','cur','label','match'] as $f):?><input type="hidden" name="<?=$f?>" value="<?=h($bp['crit'][$f])?>"><?php endforeach?><label>Tapez exactement : SUPPRIMER</label><input name="confirm_text" autocomplete="off" required placeholder="SUPPRIMER"><button class="btn primary" onclick="return confirm('Supprimer définitivement <?=$cnt?> opération(s) ?')">Supprimer définitivement</button></form>
<?php endif?></div><?php endif?></details><?php endif?>

<div id="printArea"><?=print_head('Offrandes — sommes par libellé',$periodLabel.' · '.$accountName.($search!==''?' · « '.$search.' »':''))?>
<div class="grid cards"><?php foreach([['income','Entrées'],['expense','Sorties']] as [$t,$tl]):?><article class="stat-card"><div class="stat-top"><span><?=$tl?> — <?=h($periodLabel)?><?=$search!==''?' · « '.h($search).' »':''?></span><span class="dot"></span></div><div class="stat-value"><?=money($tot[$t]['USD'],'USD')?></div><div class="stat-sub"><?=money($tot[$t]['FC'],'FC')?></div><div class="stat-foot"><?=h($accountName)?> · <?=$totCount?> opération(s)</div></article><?php endforeach?></div>

<div class="panel"><h2>Somme par libellé — <?=h($periodLabel)?></h2><p><?=h($accountName)?>. Les libellés identiques (majuscules, espaces) sont additionnés ; USD et FC restent séparés.</p><div class="table-wrap"><table><thead><tr><th>Libellé</th><th>Opér.</th><th>Entrées USD</th><th>Entrées FC</th><th>Sorties USD</th><th>Sorties FC</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=h($r['name'])?></td><td class="num"><?=$r['n']?></td><td class="num"><?=$r['incomeUSD']?money($r['incomeUSD'],'USD'):'—'?></td><td class="num"><?=$r['incomeFC']?money($r['incomeFC'],'FC'):'—'?></td><td class="num"><?=$r['expenseUSD']?money($r['expenseUSD'],'USD'):'—'?></td><td class="num"><?=$r['expenseFC']?money($r['expenseFC'],'FC'):'—'?></td></tr><?php endforeach?><?php if(!$rows):?><tr><td colspan="6" class="empty">Aucune opération pour cette période.</td></tr><?php else:?><tr><th>Total</th><th class="num"><?=$totCount?></th><th class="num"><?=money($tot['income']['USD'],'USD')?></th><th class="num"><?=money($tot['income']['FC'],'FC')?></th><th class="num"><?=money($tot['expense']['USD'],'USD')?></th><th class="num"><?=money($tot['expense']['FC'],'FC')?></th></tr><?php endif?></tbody></table></div></div>

<div class="panel"><h2>Calcul mensuel — <?=h($accountName)?> <?=h($year)?></h2><p>Solde du mois = entrées − sorties. Solde cumulé = solde initial de l'année + tous les mois jusqu'au mois concerné.</p><?php foreach(['FC','USD'] as $cur):?><h3><?=$cur==='FC'?'Francs congolais (FC)':'Dollars (USD)'?></h3><div class="table-wrap"><table><thead><tr><th>Mois</th><th>Solde initial</th><th>Entrées</th><th>Sorties</th><th>Solde du mois</th><th>Solde cumulé</th></tr></thead><tbody><?php for($m=1;$m<=12;$m++):$r=$monthTable[$cur][$m];?><tr<?=$m===$month?' class="row-active"':''?>><td><a href="offrandes.php?<?=h($qs(['month'=>$m]))?>"><?=h(ucfirst(month_fr($m)))?></a></td><td class="num"><?=money($r['open'],$cur)?></td><td class="num"><?=money($r['in'],$cur)?></td><td class="num"><?=money($r['out'],$cur)?></td><td class="num"><?=money($r['net'],$cur)?></td><td class="num"><?=money($r['close'],$cur)?></td></tr><?php endfor?></tbody></table></div><?php endforeach?></div>

<?php if($search!==''||$day!==''):?><div class="panel"><h2>Opérations correspondantes — <?=h($periodLabel)?></h2><p><?=count($matchOps)?><?=$matchMore?'+':''?> opération(s)<?=$matchMore?' (affichage limité à 500)':''?>. Cliquez sur « Fiche » pour le détail, l'impression ou la suppression d'une ligne.</p><div class="table-wrap"><table><thead><tr><th>Date</th><th>Compte</th><th>Type</th><th>Libellé</th><th>Montant</th><th class="no-print">Fiche</th></tr></thead><tbody><?php foreach($matchOps as $r):?><tr><td><?=fmt_dmy($r['tx_date'])?></td><td><?=h($r['account_name'])?></td><td><?=$r['type']==='income'?'Entrée':'Sortie'?></td><td><?=h($r['label'])?></td><td class="num"><?=money($r['amount'],$r['currency'])?></td><td class="no-print"><a href="operation.php?id=<?=(int)$r['id']?>">n° <?=(int)$r['id']?></a></td></tr><?php endforeach?><?php if(!$matchOps):?><tr><td colspan="6" class="empty">Aucune opération.</td></tr><?php endif?></tbody></table></div></div><?php endif?>

<div class="panel"><div class="panel-head"><div><h2>Libellé × mois — <?=h($year)?></h2><p>Somme de chaque libellé, mois par mois (<?=$mType==='income'?'entrées':'sorties'?> en <?=h($mCur)?>).</p></div><div class="filter-actions no-print"><?php foreach([['FC','income','Entrées FC'],['USD','income','Entrées USD'],['FC','expense','Sorties FC'],['USD','expense','Sorties USD']] as [$c,$t,$tl]):?><a class="btn<?=$c===$mCur&&$t===$mType?' primary':''?>" href="offrandes.php?<?=h($qs(['cur'=>$c,'type'=>$t]))?>"><?=$tl?></a> <?php endforeach?></div></div><div class="table-wrap"><table class="matrix"><thead><tr><th>Libellé</th><?php for($m=1;$m<=12;$m++):?><th><?=h(mb_substr(ucfirst(month_fr($m)),0,4,'UTF-8'))?>.</th><?php endfor?><th>Total</th></tr></thead><tbody><?php $colTot=array_fill(1,12,0.0);$any=false;foreach($labels as $L):$d=$L['d'][$mType][$mCur]??[];if(!$d)continue;$any=true;$sum=array_sum($d);?><tr><td><?=h($L['name'])?></td><?php for($m=1;$m<=12;$m++):$v=$d[$m]??0.0;$colTot[$m]+=$v;?><td class="num"><?=$v?number_format($v,2,',',' '):'·'?></td><?php endfor?><td class="num"><?=number_format($sum,2,',',' ')?></td></tr><?php endforeach?><?php if(!$any):?><tr><td colspan="14" class="empty">Aucune donnée.</td></tr><?php else:?><tr><th>Total</th><?php for($m=1;$m<=12;$m++):?><th class="num"><?=$colTot[$m]?number_format($colTot[$m],2,',',' '):'·'?></th><?php endfor?><th class="num"><?=number_format(array_sum($colTot),2,',',' ')?></th></tr><?php endif?></tbody></table></div></div>
</div>
<?php include 'partials/footer.php';?>
