<?php
header('Content-Type: text/html; charset=UTF-8');
declare(strict_types=1); $secureCookie=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'); session_set_cookie_params(['httponly'=>true,'secure'=>$secureCookie,'samesite'=>'Lax']); session_start(); date_default_timezone_set('Africa/Lubumbashi');
define('DB_HOST',getenv('DB_HOST')?:'localhost'); define('DB_PORT',getenv('DB_PORT')?:'3306'); define('DB_NAME',getenv('DB_NAME')?:'eglise_germe_de_david'); define('DB_USER',getenv('DB_USER')?:'root'); define('DB_PASS',getenv('DB_PASS')!==false?getenv('DB_PASS'):''); define('DB_SSL',getenv('DB_SSL')==='1'); define('DB_SSL_CA',getenv('DB_SSL_CA')?:'/etc/ssl/certs/ca-certificates.crt');
function db():PDO{static $p=null;if(!$p){$o=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>getenv('DB_EMULATE')!=='0',PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone='+00:00'"];if(DB_SSL){$o[PDO::MYSQL_ATTR_SSL_CA]=DB_SSL_CA;$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;}$p=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,$o);}return $p;}
function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function logged_in():bool{return !empty($_SESSION['user_id']);}
function require_login():void{if(!logged_in()){header('Location: login.php');exit;} $q=db()->prepare('SELECT active,role,full_name FROM users WHERE id=?');$q->execute([$_SESSION['user_id']]);$u=$q->fetch();if(!$u||(int)$u['active']!==1){session_destroy();header('Location: login.php');exit;}$_SESSION['role']=$u['role'];$_SESSION['full_name']=$u['full_name'];}
function role():string{return $_SESSION['role']??'viewer';} function require_role(array $roles):void{require_login();if(!in_array(role(),$roles,true)){http_response_code(403);exit('Accès refusé : permission insuffisante.');}}
function csrf_field():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';}
function check_csrf():void{if($_SERVER['REQUEST_METHOD']==='POST'&&!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);exit('Session expirée. Rechargez la page.');}}
function money($n,$c):string{return number_format((float)$n,2,',',' ').($c==='USD'?' $':' FC');}
function audit(string $action,string $table,?int $id,string $details=''):void{$q=db()->prepare('INSERT INTO audit_logs(user_id,action,table_name,record_id,details) VALUES(?,?,?,?,?)');$q->execute([$_SESSION['user_id']??null,$action,$table,$id,$details]);}
function settings_all():array{static $s=null;if($s===null)$s=db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);return $s;}
function setting(string $key,string $default=''):string{$s=settings_all();return array_key_exists($key,$s)?(string)$s[$key]:$default;}
// ---- Performances : les données sont lues une seule fois par requête (groupées) puis réutilisées. ----
function active_accounts():array{static $a=null;if($a===null)$a=db()->query('SELECT * FROM accounts WHERE active=1 ORDER BY id')->fetchAll();return $a;}
function opening_rows(int $year,bool $refresh=false):array{static $c=[];if($refresh||!isset($c[$year])){$q=db()->prepare('SELECT account_id,currency,opening_amount FROM account_openings WHERE year=?');$q->execute([$year]);$c[$year]=[];foreach($q->fetchAll() as $r)$c[$year][(int)$r['account_id']][$r['currency']]=(float)$r['opening_amount'];}return $c[$year];}
function ensure_year(int $year):void{static $done=[];if(isset($done[$year]))return;$pdo=db();$have=opening_rows($year);$created=false;foreach(active_accounts() as $a)foreach(['USD','FC'] as $cur){if(isset($have[(int)$a['id']][$cur]))continue;$opening=0.0;$prev=$pdo->prepare('SELECT year,opening_amount FROM account_openings WHERE account_id=? AND currency=? AND year<? ORDER BY year DESC LIMIT 1');$prev->execute([$a['id'],$cur,$year]);$r=$prev->fetch();$from=1900;if($r){$from=(int)$r['year'];$opening=(float)$r['opening_amount'];}$sum=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE account_id=? AND currency=? AND tx_date>=? AND tx_date<?");$sum->execute([$a['id'],$cur,sprintf('%04d-01-01',$from),sprintf('%04d-01-01',$year)]);$opening+=(float)$sum->fetchColumn();$ins=$pdo->prepare('INSERT IGNORE INTO account_openings(account_id,year,currency,opening_amount) VALUES(?,?,?,?)');$ins->execute([$a['id'],$year,$cur,$opening]);$created=true;}if($created)opening_rows($year,true);$done[$year]=true;}
/** Totaux de l'année groupés en UNE requête : [compte][devise][mois]['income'|'expense'] (somme). */
function month_matrix(int $year,int $account=0):array{static $c=[];$k=$year.'-'.$account;if(isset($c[$k]))return $c[$k];$sql='SELECT account_id,currency,MONTH(tx_date) m,type,SUM(amount) s FROM transactions WHERE tx_date>=? AND tx_date<?'.($account?' AND account_id=?':'').' GROUP BY account_id,currency,MONTH(tx_date),type';$q=db()->prepare($sql);$a=[sprintf('%04d-01-01',$year),sprintf('%04d-01-01',$year+1)];if($account)$a[]=$account;$q->execute($a);$r=[];foreach($q->fetchAll() as $x)$r[(int)$x['account_id']][$x['currency']][(int)$x['m']][$x['type']]=(float)$x['s'];return $c[$k]=$r;}
function year_data(int $year):array{static $c=[];if(isset($c[$year]))return $c[$year];ensure_year($year);$mx=month_matrix($year);$r=[];foreach(opening_rows($year) as $acc=>$curs)foreach($curs as $cur=>$op){$i=0.0;$e=0.0;foreach($mx[$acc][$cur]??[] as $mm){$i+=$mm['income']??0.0;$e+=$mm['expense']??0.0;}$r[$acc][$cur]=['opening_amount'=>$op,'income'=>$i,'expense'=>$e,'balance'=>$op+$i-$e];}return $c[$year]=$r;}
function balance_for(int $account,int $year,string $cur):array{return year_data($year)[$account][$cur]??['opening_amount'=>0.0,'income'=>0.0,'expense'=>0.0,'balance'=>0.0];}
function month_fr(int $m):string{return ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'][$m]??'';}

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

function locked_periods():array{static $l=null;if($l===null){$l=[];foreach(db()->query('SELECT DISTINCT account_id,year,month FROM monthly_inventories') as $r)$l[(int)$r['account_id'].'-'.(int)$r['year'].'-'.(int)$r['month']]=true;}return $l;}
function period_locked(int $account,string $date):bool{$d=new DateTime($date);return isset(locked_periods()[$account.'-'.(int)$d->format('Y').'-'.(int)$d->format('n')]);}
