<?php
// Offrandes : calcul mensuel + somme par libellÃ© (mois, annÃ©e) â€” une seule requÃªte groupÃ©e, calculs en mÃ©moire.
require_once 'tx_lib.php';require_login();$pdo=db();
$accounts=active_accounts();
$defaultAccount=0;foreach($accounts as $a)if($a['name']==='Offrandes ordinaires'){$defaultAccount=(int)$a['id'];break;}
$account=isset($_GET['account'])?(int)$_GET['account']:$defaultAccount;
$year=(int)($_GET['year']??date('Y'));if($year<2000||$year>2100)$year=(int)date('Y');
$month=(int)($_GET['month']??date('n'));if($month<0||$month>12)$month=(int)date('n'); // 0 = toute l'annÃ©e
$search=trim((string)($_GET['q']??''));
// Recherche par jour prÃ©cis (facultative) : prioritaire sur le mois.
$day=trim((string)($_GET['day']??''));if(!valid_ymd($day))$day='';if($day!==''){$year=(int)substr($day,0,4);$month=(int)substr($day,5,2);}
$mCur=in_array($_GET['cur']??'',['USD','FC'],true)?$_GET['cur']:'FC';
$mType=in_array($_GET['type']??'',['income','expense'],true)?$_GET['type']:'income';
$accountName='Tous les comptes';foreach($accounts as $a)if((int)$a['id']===$account)$accountName=$a['name'];
if($account&&$accountName==='Tous les comptes')$account=0;

// 0) Suppression groupÃ©e (admin/trÃ©sorier) : aperÃ§u puis confirmation Ã©crite. Mois clÃ´turÃ©s protÃ©gÃ©s.
require_once __DIR__.'/bulk_delete_lib.php';
$canDelete=in_array(role(),['admin','treasurer'],true);$flash=$_SESSION['flash']??'';unset($_SESSION['flash']);$bulkError='';$bulkPreview=null;
$accountNames=[];foreach($accounts as $a)$accountNames[(int)$a['id']]=$a['name'];
if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($_POST['action']??'',['bulk_preview','bulk_delete'],true)){
  check_csrf();require_role(['admin','treasurer']);
  [$crit,$bulkError]=bulk_criteria($_POST,array_keys($accountNames));$bulkError=$bulkError??'';
  if($bulkError===''){
    $sel=bulk_select($pdo,$crit);
    if($_POST['action']==='bulk_delete'){
      if(($_POST['confirm_text']??'')!=='SUPPRIMER')$bulkError='Confirmation incorrecte : tapez exactement SUPPRIMER. Rien n\'a Ã©tÃ© supprimÃ©.';
      elseif(count($sel['rows'])!==(int)($_POST['expected']??-1))$bulkError='Les donnÃ©es ont changÃ© depuis l\'aperÃ§u. Rien n\'a Ã©tÃ© supprimÃ© : refaites l\'aperÃ§u.';
      else{
        try{$n=bulk_delete($pdo,$sel['rows'],bulk_describe($crit,$accountNames));
          $_SESSION['flash']=$n.' opÃ©ration(s) supprimÃ©e(s) et journalisÃ©e(s)'.($sel['locked']?' ; '.$sel['locked'].' ignorÃ©e(s) car le mois est clÃ´turÃ©':'').'.';
          header('Location: offrandes.php?'.http_build_query(['account'=>$crit['account'],'year'=>$crit['year'],'month'=>$crit['month']]));exit;
        }catch(Throwable $e){$bulkError='Ã‰chec de la suppression : aucune donnÃ©e n\'a Ã©tÃ© supprimÃ©e.';}
      }
    }
    if($bulkError===''||$_POST['action']==='bulk_delete')$bulkPreview=['crit'=>$crit,'rows'=>$sel['rows'],'locked'=>$sel['locked'],'summary'=>bulk_summary($sel['rows']),'desc'=>bulk_describe($crit,$accountNames)];
  }
}

// 1) Une requÃªte : libellÃ© Ã— mois Ã— devise Ã— type.
$sql='SELECT TRIM(label) lbl,MONTH(tx_date) m,currency,type,SUM(amount) s,COUNT(*) n FROM transactions WHERE tx_date>=? AND tx_date<?'.($account?' AND account_id=?':'').' GROUP BY TRIM(label),MONTH(tx_date),currency,type';
$st=$pdo->prepare($sql);$args=[sprintf('%04d-01-01',$year),sprintf('%04d-01-01',$year+1)];if($account)$args[]=$account;$st->execute($args);
$norm=fn(string $v):string=>mb_strtolower(preg_replace('/\s+/u',' ',trim($v)),'UTF-8');
$labels=[]; // clÃ© normalisÃ©e => ['name'=>affichage,'d'=>[type][cur][m]=somme,'n'=>[m]=nb]
foreach($st->fetchAll() as $r){
  $name=(string)$r['lbl'];if($name==='')$name='(sans libellÃ©)';$k=$norm($name);
  if($search!==''&&mb_strpos($k,$norm($search))===false)continue;
  $labels[$k]??=['name'=>$name,'d'=>[],'n'=>[]];
  $labels[$k]['d'][$r['type']][$r['currency']][(int)$r['m']]=($labels[$k]['d'][$r['type']][$r['currency']][(int)$r['m']]??0)+(float)$r['s'];
  $labels[$k]['n'][(int)$r['m']]=($labels[$k]['n'][(int)$r['m']]??0)+(int)$r['n'];
}
uasort($labels,fn($a,$b)=>strcmp($norm($a['name']),$norm($b['name'])));
// Mode Â« jour Â» : mÃªmes agrÃ©gats, mais pour la seule date choisie (rangÃ©s sous le mois du jour pour rÃ©utiliser le calcul ci-dessous).
$labelsP=$labels;
if($day!==''){
  $dq=$pdo->prepare('SELECT TRIM(label) lbl,currency,type,SUM(amount) s,COUNT(*) n FROM transactions WHERE tx_date=?'.($account?' AND account_id=?':'').' GROUP BY TRIM(label),currency,type');
  $da=[$day];if($account)$da[]=$account;$dq->execute($da);$labelsP=[];
  foreach($dq->fetchAll() as $r){$name=(string)$r['lbl'];if($name==='')$name='(sans libellÃ©)';$k=$norm($name);
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
$periodLabel=$day!==''?'le '.fmt_dmy($day):($month===0?'toute l\'annÃ©e '.$year:month_fr($month).' '.$year);

// 2) Export CSV (libellÃ© Ã— 12 mois) â€” respecte compte, annÃ©e et recherche.
if(isset($_GET['csv'])){
  header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="offrandes_par_libelle_'.$year.'.csv"');echo "\xEF\xBB\xBF";$o=fopen('php://output','w');
  $safe=fn(string $v):string=>preg_match('/^[=+\-@\t\r]/',$v)?"'".$v:$v;$num=fn(float $v):string=>number_format($v,2,',','');
  $head=['LibellÃ©','Type','Devise'];for($m=1;$m<=12;$m++)$head[]=ucfirst(month_fr($m));$head[]='Total '.$year;fputcsv($o,$head,';');
  foreach($labels as $L)foreach(['income'=>'EntrÃ©e','expense'=>'Sortie'] as $t=>$tl)foreach(['USD','FC'] as $c){if(empty($L['d'][$t][$c]))continue;$line=[$safe($L['name']),$tl,$c];$sum=0.0;for($m=1;$m<=12;$m++){$v=$L['d'][$t][$c][$m]??0.0;$sum+=$v;$line[]=$num($v);}$line[]=$num($sum);fputcsv($o,$line,';');}
  fclose($o);exit;
}

// 3) Calcul mensuel du compte (entrÃ©es, sorties, solde du mois, solde cumulÃ© reportÃ© de mois en mois).
$mx=month_matrix($year,$account);ensure_year($year);
$ids=$account?[$account]:array_map(fn($a)=>(int)$a['id'],$accounts);
$monthTable=[];
foreach(['FC','USD'] as $cur){
  $op=0.0;foreach($ids as $id)$op+=balance_for($id,$year,$cur)['opening_amount'];
  for($m=1;$m<=12;$m++){$i=0.0;$e=0.0;foreach($ids as $id){$i+=$mx[$id][$cur][$m]['income']??0.0;$e+=$mx[$id][$cur][$m]['expense']??0.0;}
    $monthTable[$cur][$m]=['open'=>$op,'in'=>$i,'out'=>$e,'net'=>$i-$e,'close'=>$op+$i-$e];$op+=$i-$e;}
}
$qs=fn(array $over=[])=>http_build_query(array_merge(['account'=>$account,'year'=>$year,'month'=>$month,'q'=>$search,'day'=>$day,'cur'=>$mCur,'type'=>$mType],$over));
// OpÃ©rations correspondantes (affichÃ©es quand on cherche un libellÃ© ou un jour prÃ©cis).
$matchOps=[];$matchMore=false;
if($search!==''||$day!==''){
  if($day!==''){$f=$day;$t=$day;}elseif($month===0){$f=sprintf('%04d-01-01',$year);$t=sprintf('%04d-12-31',$year);}else{$f=sprintf('%04d-%02d-01',$year,$month);$t=date('Y-m-t',strtotime($f));}
  $mq=$pdo->prepare('SELECT t.id,t.tx_date,t.type,t.currency,t.amount,t.label,a.name account_name FROM transactions t JOIN accounts a ON a.id=t.account_id WHERE t.tx_date>=? AND t.tx_date<=?'.($account?' AND t.account_id=?':'').' ORDER BY t.tx_date,t.id');
  $ma=[$f,$t];if($account)$ma[]=$account;$mq->execute($ma);
  foreach($mq->fetchAll() as $r){if($search!==''&&mb_strpos($norm((string)$r['label']),$norm($search))===false)continue;if(count($matchOps)>=500){$matchMore=true;break;}$matchOps[]=$r;}
}
$page_title='Offrandes par mois et libellÃ©';include 'partials/header.php';?>
<div class="panel no-print"><div class="panel-head"><div><h2>Offrandes â€” somme par mois et par libellÃ©</h2><p>Choisissez le compte, l'annÃ©e et le mois ; tapez un mot du libellÃ© (ex. Â« dÃ®me Â», Â« action de grÃ¢ce Â», Â« carburant Â») pour obtenir sa somme ; choisissez un jour prÃ©cis pour ne voir que cette date.</p></div><div class="filter-actions"><a class="btn" href="offrandes.php?<?=h($qs(['csv'=>1]))?>">Exporter Excel (CSV)</a><button type="button" class="btn primary" data-preview="#printArea">AperÃ§u et impression</button></div></div>
<form class="filter-row"><select name="account"><option value="0">Tous les comptes</option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>" <?=$account===(int)$a['id']?'selected':''?>><?=h($a['name'])?></option><?php endforeach?></select><select name="year"><?php for($y=(int)date('Y')+1;$y>=(int)date('Y')-10;$y--):?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endfor?></select><select name="month"><option value="0" <?=$month===0?'selected':''?>>Toute l'annÃ©e</option><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>" <?=$month===$m?'selected':''?>><?=h(ucfirst(month_fr($m)))?></option><?php endfor?></select><input name="q" placeholder="Rechercher un libellÃ©â€¦" value="<?=h($search)?>"><input type="date" name="day" value="<?=h($day)?>" title="Jour prÃ©cis (facultatif â€” remplace le mois)" style="width:auto"><input type="hidden" name="cur" value="<?=h($mCur)?>"><input type="hidden" name="type" value="<?=h($mType)?>"><button class="btn primary">Afficher</button></form></div>

<?php if($flash):?><div class="notice"><?=h($flash)?></div><?php endif?>
<?php if($canDelete):?><details class="panel no-print bulk-del" <?=($bulkPreview||$bulkError)?'open':''?>><summary><b>Supprimer des offrandes (par libellÃ©, mois ou type)</b></summary>
<p>Choisissez ce qu'il faut supprimer, puis cliquez sur Â« AperÃ§u Â» : rien n'est supprimÃ© avant votre confirmation. Les mois clÃ´turÃ©s sont protÃ©gÃ©s. Chaque suppression est enregistrÃ©e dans le journal d'audit.</p>
<?php if($bulkError):?><div class="alert"><?=h($bulkError)?></div><?php endif?>
<form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="bulk_preview">
<div><label>Compte</label><select name="account"><option value="0">Tous les comptes</option><?php foreach($accounts as $a):?><option value="<?=$a['id']?>" <?=$account===(int)$a['id']?'selected':''?>><?=h($a['name'])?></option><?php endforeach?></select></div>
<div><label>AnnÃ©e</label><select name="year"><?php for($y=(int)date('Y')+1;$y>=(int)date('Y')-10;$y--):?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endfor?></select></div>
<div><label>Mois</label><select name="month"><option value="0" <?=$month===0?'selected':''?>>Toute l'annÃ©e</option><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>" <?=$month===$m?'selected':''?>><?=h(ucfirst(month_fr($m)))?></option><?php endfor?></select></div>
<div><label>Type</label><select name="type"><option value="">EntrÃ©es et sorties</option><option value="income">EntrÃ©es seulement</option><option value="expense">Sorties seulement</option></select></div>
<div><label>Devise</label><select name="cur"><option value="">USD et FC</option><option value="USD">USD seulement</option><option value="FC">FC seulement</option></select></div>
<div><label>Correspondance du libellÃ©</label><select name="match"><option value="exact">LibellÃ© identique (majuscules/espaces ignorÃ©s)</option><option value="contains">LibellÃ© contenant le texte</option></select></div>
<div class="span-2"><label>LibellÃ© (facultatif â€” laisser vide pour tous les libellÃ©s)</label><input name="label" list="bulkLabels" autocomplete="off" placeholder="Ex. dÃ®me, action de grÃ¢ceâ€¦" value="<?=h($bulkPreview['crit']['label']??'')?>"><datalist id="bulkLabels"><?php foreach($labels as $L):?><option value="<?=h($L['name'])?>"><?php endforeach?></datalist></div>
<div class="span-2"><button class="btn primary">AperÃ§u de la suppression</button></div></form>
<?php if($bulkPreview):$bp=$bulkPreview;$cnt=count($bp['rows']);?><div class="bulk-preview"><h3>AperÃ§u : <?=h($bp['desc'])?></h3>
<?php if($cnt===0):?><p>Aucune opÃ©ration ne correspond<?=$bp['locked']?' ('.$bp['locked'].' opÃ©ration(s) dans des mois clÃ´turÃ©s sont protÃ©gÃ©es)':''?>.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Compte</th><th>Type</th><th>Devise</th><th>OpÃ©r.</th><th>Montant total</th></tr></thead><tbody><?php foreach($bp['summary'] as $s):?><tr><td><?=h($s['account'])?></td><td><?=$s['type']==='income'?'EntrÃ©e':'Sortie'?></td><td><?=h($s['currency'])?></td><td class="num"><?=$s['n']?></td><td class="num"><?=money($s['sum'],$s['currency'])?></td></tr><?php endforeach?></tbody></table></div>
<details><summary>Voir les <?=min($cnt,200)?> premiÃ¨res opÃ©rations</summary><div class="table-wrap"><table><thead><tr><th>Date</th><th>Compte</th><th>Type</th><th>LibellÃ©</th><th>Montant</th></tr></thead><tbody><?php foreach(array_slice($bp['rows'],0,200) as $r):?><tr><td><?=date('d/m/Y',strtotime($r['tx_date']))?></td><td><?=h($r['account_name'])?></td><td><?=$r['type']==='income'?'EntrÃ©e':'Sortie'?></td><td><?=h($r['label'])?></td><td class="num"><?=money($r['amount'],$r['currency'])?></td></tr><?php endforeach?></tbody></table></div></details>
<?php if($bp['locked']):?><p class="alert"><?=$bp['locked']?> opÃ©ration(s) dans des mois clÃ´turÃ©s ne seront pas supprimÃ©es.</p><?php endif?>
<div class="alert"><strong><?=$cnt?> opÃ©ration(s) seront dÃ©finitivement supprimÃ©es.</strong> TÃ©lÃ©chargez une sauvegarde avant si nÃ©cessaire.</div>
<form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="bulk_delete"><input type="hidden" name="expected" value="<?=$cnt?>"><?php foreach(['account','year','month','type','cur','label','match'] as $f):?><input type="hidden" name="<?=$f?>" value="<?=h($bp['crit'][$f])?>"><?php endforeach?><label>Tapez exactement : SUPPRIMER</label><input name="confirm_text" autocomplete="off" required placeholder="SUPPRIMER"><button class="btn primary" onclick="return confirm('Supprimer dÃ©finitivement <?=$cnt?> opÃ©ration(s) ?')">Supprimer dÃ©finitivement</button></form>
<?php endif?></div><?php endif?></details><?php endif?>

<div id="printArea"><?=print_head('Offrandes â€” sommes par libellÃ©',$periodLabel.' Â· '.$accountName.($search!==''?' Â· Â« '.$search.' Â»':''))?>
<div class="grid cards"><?php foreach([['income','EntrÃ©es'],['expense','Sorties']] as [$t,$tl]):?><article class="stat-card"><div class="stat-top"><span><?=$tl?> â€” <?=h($periodLabel)?><?=$search!==''?' Â· Â« '.h($search).' Â»':''?></span><span class="dot"></span></div><div class="stat-value"><?=money($tot[$t]['USD'],'USD')?></div><div class="stat-sub"><?=money($tot[$t]['FC'],'FC')?></div><div class="stat-foot"><?=h($accountName)?> Â· <?=$totCount?> opÃ©ration(s)</div></article><?php endforeach?></div>

<div class="panel"><h2>Somme par libellÃ© â€” <?=h($periodLabel)?></h2><p><?=h($accountName)?>. Les libellÃ©s identiques (majuscules, espaces) sont additionnÃ©s ; USD et FC restent sÃ©parÃ©s.</p><div class="table-wrap"><table><thead><tr><th>LibellÃ©</th><th>OpÃ©r.</th><th>EntrÃ©es USD</th><th>EntrÃ©es FC</th><th>Sorties USD</th><th>Sorties FC</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=h($r['name'])?></td><td class="num"><?=$r['n']?></td><td class="num"><?=$r['incomeUSD']?money($r['incomeUSD'],'USD'):'â€”'?></td><td class="num"><?=$r['incomeFC']?money($r['incomeFC'],'FC'):'â€”'?></td><td class="num"><?=$r['expenseUSD']?money($r['expenseUSD'],'USD'):'â€”'?></td><td class="num"><?=$r['expenseFC']?money($r['expenseFC'],'FC'):'â€”'?></td></tr><?php endforeach?><?php if(!$rows):?><tr><td colspan="6" class="empty">Aucune opÃ©ration pour cette pÃ©riode.</td></tr><?php else:?><tr><th>Total</th><th class="num"><?=$totCount?></th><th class="num"><?=money($tot['income']['USD'],'USD')?></th><th class="num"><?=money($tot['income']['FC'],'FC')?></th><th class="num"><?=money($tot['expense']['USD'],'USD')?></th><th class="num"><?=money($tot['expense']['FC'],'FC')?></th></tr><?php endif?></tbody></table></div></div>

<div class="panel"><h2>Calcul mensuel â€” <?=h($accountName)?> <?=h($year)?></h2><p>Solde du mois = entrÃ©es âˆ’ sorties. Solde cumulÃ© = solde initial de l'annÃ©e + tous les mois jusqu'au mois concernÃ©.</p><?php foreach(['FC','USD'] as $cur):?><h3><?=$cur==='FC'?'Francs congolais (FC)':'Dollars (USD)'?></h3><div class="table-wrap"><table><thead><tr><th>Mois</th><th>Solde initial</th><th>EntrÃ©es</th><th>Sorties</th><th>Solde du mois</th><th>Solde cumulÃ©</th></tr></thead><tbody><?php for($m=1;$m<=12;$m++):$r=$monthTable[$cur][$m];?><tr<?=$m===$month?' class="row-active"':''?>><td><a href="offrandes.php?<?=h($qs(['month'=>$m]))?>"><?=h(ucfirst(month_fr($m)))?></a></td><td class="num"><?=money($r['open'],$cur)?></td><td class="num"><?=money($r['in'],$cur)?></td><td class="num"><?=money($r['out'],$cur)?></td><td class="num"><?=money($r['net'],$cur)?></td><td class="num"><?=money($r['close'],$cur)?></td></tr><?php endfor?></tbody></table></div><?php endforeach?></div>

<?php if($search!==''||$day!==''):?><div class="panel"><h2>OpÃ©rations correspondantes â€” <?=h($periodLabel)?></h2><p><?=count($matchOps)?><?=$matchMore?'+':''?> opÃ©ration(s)<?=$matchMore?' (affichage limitÃ© Ã  500)':''?>. Cliquez sur Â« Fiche Â» pour le dÃ©tail, l'impression ou la suppression d'une ligne.</p><div class="table-wrap"><table><thead><tr><th>Date</th><th>Compte</th><th>Type</th><th>LibellÃ©</th><th>Montant</th><th class="no-print">Fiche</th></tr></thead><tbody><?php foreach($matchOps as $r):?><tr><td><?=fmt_dmy($r['tx_date'])?></td><td><?=h($r['account_name'])?></td><td><?=$r['type']==='income'?'EntrÃ©e':'Sortie'?></td><td><?=h($r['label'])?></td><td class="num"><?=money($r['amount'],$r['currency'])?></td><td class="no-print"><a href="operation.php?id=<?=(int)$r['id']?>">nÂ° <?=(int)$r['id']?></a></td></tr><?php endforeach?><?php if(!$matchOps):?><tr><td colspan="6" class="empty">Aucune opÃ©ration.</td></tr><?php endif?></tbody></table></div></div><?php endif?>

<div class="panel"><div class="panel-head"><div><h2>LibellÃ© Ã— mois â€” <?=h($year)?></h2><p>Somme de chaque libellÃ©, mois par mois (<?=$mType==='income'?'entrÃ©es':'sorties'?> en <?=h($mCur)?>).</p></div><div class="filter-actions no-print"><?php foreach([['FC','income','EntrÃ©es FC'],['USD','income','EntrÃ©es USD'],['FC','expense','Sorties FC'],['USD','expense','Sorties USD']] as [$c,$t,$tl]):?><a class="btn<?=$c===$mCur&&$t===$mType?' primary':''?>" href="offrandes.php?<?=h($qs(['cur'=>$c,'type'=>$t]))?>"><?=$tl?></a> <?php endforeach?></div></div><div class="table-wrap"><table class="matrix"><thead><tr><th>LibellÃ©</th><?php for($m=1;$m<=12;$m++):?><th><?=h(mb_substr(ucfirst(month_fr($m)),0,4,'UTF-8'))?>.</th><?php endfor?><th>Total</th></tr></thead><tbody><?php $colTot=array_fill(1,12,0.0);$any=false;foreach($labels as $L):$d=$L['d'][$mType][$mCur]??[];if(!$d)continue;$any=true;$sum=array_sum($d);?><tr><td><?=h($L['name'])?></td><?php for($m=1;$m<=12;$m++):$v=$d[$m]??0.0;$colTot[$m]+=$v;?><td class="num"><?=$v?number_format($v,2,',',' '):'Â·'?></td><?php endfor?><td class="num"><?=number_format($sum,2,',',' ')?></td></tr><?php endforeach?><?php if(!$any):?><tr><td colspan="14" class="empty">Aucune donnÃ©e.</td></tr><?php else:?><tr><th>Total</th><?php for($m=1;$m<=12;$m++):?><th class="num"><?=$colTot[$m]?number_format($colTot[$m],2,',',' '):'Â·'?></th><?php endfor?><th class="num"><?=number_format(array_sum($colTot),2,',',' ')?></th></tr><?php endif?></tbody></table></div></div>
</div>
<?php include 'partials/footer.php';?>

