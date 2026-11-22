<?php
require_once 'config.php';
if(logged_in()){header('Location: dashboard.php');exit;}
$name=setting('church_name','EGLISE GERME DE DAVID');$desc=setting('report_description','Bienvenue à l’Eglise Germe de David. Une communauté de foi, de prière et de partage.');$photo=setting('church_photo_path','');$logo=setting('logo_path','assets/egd-logo.svg');if($logo===''||!is_file(__DIR__.'/'.$logo))$logo='assets/egd-logo.svg';if($photo!==''&&!is_file(__DIR__.'/'.$photo))$photo='';
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($name)?></title><link rel="stylesheet" href="assets/style.css"></head><body class="welcome-bg"><main class="welcome-card">
<div class="welcome-brand"><?php if($logo):?><img src="<?=h($logo)?>" alt="Logo"><?php endif?><span>ESPACE DE GESTION FINANCIÈRE</span></div>
<?php if($photo):?><img class="welcome-photo" src="<?=h($photo)?>" alt="Photo de l'église"><?php else:?><div class="welcome-placeholder">EGLISE GERME DE DAVID</div><?php endif?>
<h1><?=h($name)?></h1><p><?=nl2br(h($desc))?></p><a class="btn primary" href="login.php">Accéder à l'application</a></main><footer class="site-credit welcome-credit">Concepteur: Patrick Pilgrim Soft; +243976959084</footer></body></html>