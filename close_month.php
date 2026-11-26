<?php require_once 'config.php';require_once 'close_lib.php';require_role(['admin','treasurer']);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: reports.php');exit;}
check_csrf();
$year=(int)($_POST['year']??0);$month=(int)($_POST['month']??0);$only=(int)($_POST['account_id']??0);
if($month<1||$month>12||$year<2000||$year>2100)exit('Période invalide.');
$pdo=db();ensure_year($year);
$st=$only?$pdo->prepare('SELECT id FROM accounts WHERE id=? AND active=1'):$pdo->prepare('SELECT id FROM accounts WHERE active=1');
if($only)$st->execute([$only]);else $st->execute();
$ids=array_map('intval',array_column($st->fetchAll(),'id'));
$pdo->beginTransaction();
try{
  close_month_accounts($pdo,$year,$month,$ids,(int)$_SESSION['user_id']);
  audit('CLOSE','monthly_inventories',null,"Clôture $year-$month compte $only");
  $pdo->commit();
  header("Location: reports.php?year=$year&month=$month&account=$only&closed=1");exit;
}catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  http_response_code(409);
  echo '<h2>Clôture non effectuée</h2><p>'.h($e->getMessage()).'</p><a href="reports.php?year='.$year.'&month='.$month.'">Retour aux rapports</a>';
}
