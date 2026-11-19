<?php
require_once 'config.php';
if(logged_in()){header('Location: dashboard.php');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 check_csrf();
 $q=db()->prepare("SELECT * FROM users WHERE username=?");$q->execute([trim($_POST['username']??'')]);$u=$q->fetch();
 if($u && (int)$u['active']===1 && password_verify($_POST['password']??'', $u['password_hash'])){session_regenerate_id(true);$_SESSION['user_id']=$u['id'];$_SESSION['full_name']=$u['full_name'];$_SESSION['role']=$u['role']??'viewer';header('Location: dashboard.php');exit;}
 $error='Identifiants incorrects.';
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-bg"><form class="login-card" method="post"><?=csrf_field()?><div class="brand-mark">EGD</div><h1>EGLISE GERME DE DAVID</h1><p>Gestion financière</p><?php if($error):?><div class="alert"><?=h($error)?></div><?php endif?><label>Nom d'utilisateur</label><input name="username" required autofocus><label>Mot de passe</label><input type="password" name="password" required><button class="btn primary full">Se connecter</button><small>Première utilisation : créez le compte administrateur dans setup_admin.php.</small></form></body></html>
