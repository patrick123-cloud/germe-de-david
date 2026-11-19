<?php
declare(strict_types=1); $secureCookie=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'); session_set_cookie_params(['httponly'=>true,'secure'=>$secureCookie,'samesite'=>'Lax']); session_start(); date_default_timezone_set('Africa/Lubumbashi');
define('DB_HOST',getenv('DB_HOST')?:'localhost'); define('DB_PORT',getenv('DB_PORT')?:'3306'); define('DB_NAME',getenv('DB_NAME')?:'eglise_germe_de_david'); define('DB_USER',getenv('DB_USER')?:'root'); define('DB_PASS',getenv('DB_PASS')!==false?getenv('DB_PASS'):''); define('DB_SSL',getenv('DB_SSL')==='1'); define('DB_SSL_CA',getenv('DB_SSL_CA')?:'/etc/ssl/certs/ca-certificates.crt');
function db():PDO{static $p=null;if(!$p){$o=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];if(DB_SSL){$o[PDO::MYSQL_ATTR_SSL_CA]=DB_SSL_CA;$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;}$p=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,$o);$p->exec("SET time_zone='+00:00'");}return $p;}
function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function logged_in():bool{return !empty($_SESSION['user_id']);}
function require_login():void{if(!logged_in()){header('Location: login.php');exit;} $q=db()->prepare('SELECT active,role,full_name FROM users WHERE id=?');$q->execute([$_SESSION['user_id']]);$u=$q->fetch();if(!$u||(int)$u['active']!==1){session_destroy();header('Location: login.php');exit;}$_SESSION['role']=$u['role'];$_SESSION['full_name']=$u['full_name'];}
function role():string{return $_SESSION['role']??'viewer';} function require_role(array $roles):void{require_login();if(!in_array(role(),$roles,true)){http_response_code(403);exit('Accès refusé : permission insuffisante.');}}
function csrf_field():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';}
function check_csrf():void{if($_SERVER['REQUEST_METHOD']==='POST'&&!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);exit('Session expirée. Rechargez la page.');}}
function money($n,$c):string{return number_format((float)$n,2,',',' ').($c==='USD'?' $':' FC');}
function audit(string $action,string $table,?int $id,string $details=''):void{$q=db()->prepare('INSERT INTO audit_logs(user_id,action,table_name,record_id,details) VALUES(?,?,?,?,?)');$q->execute([$_SESSION['user_id']??null,$action,$table,$id,$details]);}
function setting(string $key,string $default=''):string{$q=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:(string)$v;}
function ensure_year(int $year):void{$pdo=db();$as=$pdo->query('SELECT id FROM accounts WHERE active=1')->fetchAll();foreach($as as $a)foreach(['USD','FC'] as $cur){$q=$pdo->prepare('SELECT id FROM account_openings WHERE account_id=? AND year=? AND currency=?');$q->execute([$a['id'],$year,$cur]);if(!$q->fetch()){$opening=0.0;$prev=$pdo->prepare('SELECT year,opening_amount FROM account_openings WHERE account_id=? AND currency=? AND year<? ORDER BY year DESC LIMIT 1');$prev->execute([$a['id'],$cur,$year]);$r=$prev->fetch();$from=1900;if($r){$from=(int)$r['year'];$opening=(float)$r['opening_amount'];}$sum=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE account_id=? AND currency=? AND tx_date>=? AND tx_date<?");$sum->execute([$a['id'],$cur,sprintf('%04d-01-01',$from),sprintf('%04d-01-01',$year)]);$opening+=(float)$sum->fetchColumn();$ins=$pdo->prepare('INSERT IGNORE INTO account_openings(account_id,year,currency,opening_amount) VALUES(?,?,?,?)');$ins->execute([$a['id'],$year,$cur,$opening]);}}}
function balance_for(int $account,int $year,string $cur):array{ensure_year($year);$q=db()->prepare("SELECT o.opening_amount,COALESCE(SUM(CASE WHEN t.type='income' THEN t.amount ELSE 0 END),0) income,COALESCE(SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END),0) expense FROM account_openings o LEFT JOIN transactions t ON t.account_id=o.account_id AND t.currency=o.currency AND YEAR(t.tx_date)=o.year WHERE o.account_id=? AND o.year=? AND o.currency=? GROUP BY o.id");$q->execute([$account,$year,$cur]);$r=$q->fetch()?:['opening_amount'=>0,'income'=>0,'expense'=>0];$r['balance']=(float)$r['opening_amount']+(float)$r['income']-(float)$r['expense'];return $r;}

function classify_label(string $label): string {
    $v = mb_strtolower(trim($label), 'UTF-8');
    $rules = [
      'Carburant pasteur'=>['carburant pasteur','essence pasteur','gasoil pasteur'],
      'Entretien'=>['entretien','réparation','reparation'],
      'Transport'=>['transport','taxi','course'],
      'Eau et sucré'=>['eau','sucré','sucre','boisson'],
      'Collation'=>['collation','repas','nourriture'],
      'Modérateur et interprète'=>['modérateur','moderateur','interprète','interprete'],
      'Protocole'=>['protocole'],
      'SNEL'=>['snel','électricité','electricite'],
      'Carburant groupe'=>['carburant groupe','groupe électrogène','groupe electrogene'],
      'Administration'=>['administration','bureau'],
      'Presses'=>['presse','impression','photocopie'],
      'Unités'=>['unité','unite','unités','unites'],
      'Garde'=>['garde','gardien'],
      'Assistance'=>['assistance','aide sociale'],
      'Prêt'=>['prêt','pret','emprunt'],
      'Maintenance'=>['maintenance']
    ];
    foreach($rules as $category=>$terms) foreach($terms as $term) if(mb_strpos($v,$term)!==false) return $category;
    return 'Autres';
}

function period_locked(int $account,string $date):bool{$d=new DateTime($date);$q=db()->prepare('SELECT COUNT(*) FROM monthly_inventories WHERE account_id=? AND year=? AND month=?');$q->execute([$account,(int)$d->format('Y'),(int)$d->format('n')]);return (int)$q->fetchColumn()>0;}
