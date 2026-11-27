<?php
require_once 'config.php';require_role(['admin']);$pdo=db();$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();
 $vals=['church_name'=>trim($_POST['church_name']??''),'report_description'=>trim($_POST['report_description']??''),'remote_url'=>trim($_POST['remote_url']??''),'exchange_rate'=>str_replace(',','.',trim($_POST['exchange_rate']??'2800'))];
 if(!is_numeric($vals['exchange_rate'])||(float)$vals['exchange_rate']<=0)$msg='Le taux doit être un nombre positif.';
 else {
  foreach($vals as $k=>$v){$q=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute([$k,$v]);}
  foreach(['logo'=>'logo_path','church_photo'=>'church_photo_path'] as $field=>$key){
   if(!empty($_FILES[$field]['name'])&&is_uploaded_file($_FILES[$field]['tmp_name'])){
    $info=getimagesize($_FILES[$field]['tmp_name']);$ext=strtolower(pathinfo($_FILES[$field]['name'],PATHINFO_EXTENSION));
    if($info&&in_array($ext,['png','jpg','jpeg','webp'],true)&&$_FILES[$field]['size']<5*1024*1024){
     $dir=__DIR__.'/uploads';if(!is_dir($dir))mkdir($dir,0755,true);$file=$field.'-'.date('YmdHis').'.'.$ext;
     if(move_uploaded_file($_FILES[$field]['tmp_name'],$dir.'/'.$file)){$q=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute([$key,'uploads/'.$file]);}
    }else $msg='Image refusée : PNG/JPG/WEBP de moins de 5 Mo requise.';
   }
  }
  $msg=$msg?:'Paramètres enregistrés.';audit('UPDATE','settings',null,'Personnalisation, taux de change et photo');
 }
}
$get=$pdo->query('SELECT setting_key,setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);$page_title='Paramètres';include 'partials/header.php';
?>
<?php if($msg):?><div class="notice"><?=h($msg)?></div><?php endif?>
<div class="panel"><h2>Église et page d'accueil</h2><p>La photo et la description seront visibles sur la page d'accueil avant la connexion.</p>
<form method="post" enctype="multipart/form-data" class="stack"><?=csrf_field()?>
<label>Nom de l'église</label><input name="church_name" value="<?=h($get['church_name']??'EGLISE GERME DE DAVID')?>" required>
<label>Description de l'église</label><textarea name="report_description" rows="3"><?=h($get['report_description']??'Bienvenue à l’Eglise Germe de David. Une communauté de foi, de prière et de partage.')?></textarea>
<label>Photo de l'église (PNG, JPG ou WEBP, 5 Mo maximum)</label><input type="file" name="church_photo" accept="image/png,image/jpeg,image/webp"><?php if(!empty($get['church_photo_path'])):?><img class="church-preview" src="<?=h($get['church_photo_path'])?>" alt="Photo de l'église"><?php endif?>
<label>Logo (PNG, JPG ou WEBP)</label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"><?php if(!empty($get['logo_path'])):?><img class="logo-preview" src="<?=h($get['logo_path'])?>" alt="Logo actuel"><?php endif?>
<label>Taux de change (1 USD = combien de FC ?)</label><input type="number" min="0.01" step="0.01" name="exchange_rate" value="<?=h($get['exchange_rate']??'2800')?>" required>
<label>Adresse publique HTTPS</label><input type="url" name="remote_url" placeholder="https://votre-adresse-publique" value="<?=h($get['remote_url']??'')?>">
<button class="btn primary">Enregistrer les paramètres</button></form></div>
<div class="panel"><h2>Conversion automatique</h2><p>Le taux enregistré ci-dessus est utilisé dans le convertisseur USD ⇄ FC. Les écritures comptables originales restent conservées dans leur devise de saisie.</p><a class="btn primary" href="converter.php">Ouvrir le convertisseur</a></div>
<?php include 'partials/footer.php';?>
