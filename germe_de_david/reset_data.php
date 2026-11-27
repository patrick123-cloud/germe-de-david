<?php
require_once 'config.php';
require_role(['admin']);
$pdo=db();$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  check_csrf();
  if(($_POST['confirm_text']??'')!=='REINITIALISER LES DONNEES'){
    $msg='Confirmation incorrecte. Aucune donnée n’a été modifiée.';
  }else{
    try{
      $pdo->beginTransaction();
      $pdo->exec('DELETE FROM transactions');
      $pdo->exec('DELETE FROM monthly_inventories');
      $pdo->exec('DELETE FROM account_openings');
      $pdo->commit();
      audit('RESET','database',null,'Réinitialisation des opérations, inventaires et soldes initiaux; comptes, catégories, utilisateurs, paramètres et journal conservés.');
      $msg='Réinitialisation terminée. Les comptes, catégories, utilisateurs, paramètres et historique de sécurité sont conservés.';
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$msg='Échec de la réinitialisation. Aucune suppression partielle n’a été validée.';}
  }
}
$page_title='Réinitialiser les données';include 'partials/header.php';?>
<div class="panel"><h2>Réinitialisation des données financières</h2><p>Cette action supprime définitivement toutes les opérations (recettes et dépenses), les clôtures mensuelles et les soldes initiaux. Elle conserve les comptes, catégories, utilisateurs, paramètres, logo et journal de sécurité.</p><div class="alert"><strong>Attention :</strong> téléchargez une sauvegarde SQL avant de continuer. Cette action ne peut pas être annulée.</div><?php if($msg):?><div class="notice"><?=h($msg)?></div><?php endif?><form method="post" action="reset_data.php" class="stack"><?=csrf_field()?><label>Saisissez exactement : REINITIALISER LES DONNEES</label><input name="confirm_text" autocomplete="off" required placeholder="REINITIALISER LES DONNEES"><button class="btn primary" type="submit" onclick="return confirm('Confirmez-vous la suppression de toutes les opérations, clôtures et soldes initiaux ?')">Réinitialiser les données</button></form><p><a class="btn" href="backup_download.php">Télécharger une sauvegarde SQL</a> <a class="btn" href="dashboard.php">Annuler</a></p></div>
<?php include 'partials/footer.php';?>
