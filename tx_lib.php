<?php
// Fonctions communes (v4) : périodes, filtres de recherche, catégories, soldes, impression.
// Ce fichier ne modifie aucune donnée : il ne fait que lire / calculer.
require_once __DIR__.'/config.php';

/** La colonne transactions.category_name existe-t-elle ? (la page de réparation la crée si besoin) */
function tx_has_category_col(bool $refresh = false): bool {
    static $v = null;
    if ($refresh) $v = null;
    if ($v === null) {
        try { db()->query('SELECT category_name FROM transactions WHERE 1=0'); $v = true; }
        catch (Throwable $e) { $v = false; }
    }
    return $v;
}

/** Expression SQL de la catégorie d'une opération : champ séparé, sinon catégorie prédéfinie, sinon libellé. */
function tx_cat_expr(string $t = 't', string $c = 'c'): string {
    return tx_has_category_col()
        ? "COALESCE(NULLIF(TRIM($t.category_name),''),$c.name,TRIM($t.label))"
        : "COALESCE($c.name,TRIM($t.label))";
}

/** FROM commun : opération + compte + catégorie prédéfinie + utilisateur. */
function tx_from_sql(): string {
    return 'transactions t JOIN accounts a ON a.id=t.account_id LEFT JOIN categories c ON c.id=t.category_id LEFT JOIN users u ON u.id=t.created_by';
}

/** Clé de regroupement sans tenir compte de la casse ni des espaces multiples. */
function norm_key(string $v): string {
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($v)), 'UTF-8');
}

function valid_ymd($s): bool {
    return is_string($s) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function fmt_dmy(string $ymd): string { return date('d/m/Y', strtotime($ymd)); }

/** Date/heure stockée en UTC (session SQL en +00:00) affichée à l'heure locale de l'église. */
function fmt_dt(?string $v): string { return $v ? date('d/m/Y H:i', strtotime($v.' UTC')) : '—'; }

function weekday_fr(string $ymd): string {
    return ['dimanche','lundi','mardi','mercredi','jeudi','vendredi','samedi'][(int)date('w', strtotime($ymd))];
}

/**
 * Lit la période demandée : scope = day | month | year | range | all.
 * Paramètres : jour (AAAA-MM-JJ), mois (AAAA-MM), annee (AAAA), from / to (période libre).
 * Compatibilité : un lien ancien avec seulement from/to devient une période libre.
 */
function period_from_request(array $in, string $defaultScope = 'month'): array {
    $scope = $in['scope'] ?? '';
    if (!in_array($scope, ['day','month','year','range','all'], true)) {
        $scope = (isset($in['from']) || isset($in['to'])) ? 'range' : $defaultScope;
    }
    $today = date('Y-m-d');
    $jour  = valid_ymd($in['jour'] ?? null) ? $in['jour'] : $today;
    $mois  = (isset($in['mois']) && is_string($in['mois']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $in['mois'])) ? $in['mois'] : date('Y-m');
    $annee = (int)($in['annee'] ?? date('Y')); if ($annee < 2000 || $annee > 2100) $annee = (int)date('Y');
    $from  = valid_ymd($in['from'] ?? null) ? $in['from'] : date('Y-m-01');
    $to    = valid_ymd($in['to'] ?? null) ? $in['to'] : $today;
    if ($from > $to) [$from, $to] = [$to, $from];

    switch ($scope) {
        case 'day':   $f = $t = $jour; $label = 'Jour : '.weekday_fr($jour).' '.fmt_dmy($jour); break;
        case 'month': $f = $mois.'-01'; $t = date('Y-m-t', strtotime($f)); $label = 'Mois : '.month_fr((int)substr($mois,5,2)).' '.substr($mois,0,4); break;
        case 'year':  $f = sprintf('%04d-01-01', $annee); $t = sprintf('%04d-12-31', $annee); $label = 'Année '.$annee; break;
        case 'range': $f = $from; $t = $to; $label = 'Du '.fmt_dmy($from).' au '.fmt_dmy($to); break;
        default:      $f = $t = null; $label = 'Rapport global (toutes les dates)';
    }
    return ['scope'=>$scope,'jour'=>$jour,'mois'=>$mois,'annee'=>$annee,'rf'=>$from,'rt'=>$to,'from'=>$f,'to'=>$t,'label'=>$label];
}

/** Paramètres d'URL qui reproduisent la période et les filtres (pour les liens d'export / d'impression). */
function filter_query(array $per, array $in, array $extra = []): string {
    $q = ['scope'=>$per['scope'],'jour'=>$per['jour'],'mois'=>$per['mois'],'annee'=>$per['annee'],'from'=>$per['rf'],'to'=>$per['rt'],
          'account'=>(int)($in['account'] ?? 0),'cur'=>$in['cur'] ?? '','type'=>$in['type'] ?? '','search'=>trim((string)($in['search'] ?? ''))];
    return http_build_query(array_merge($q, $extra));
}

/** Échappe % _ | pour LIKE ... ESCAPE '|' (fonctionne sur MySQL/TiDB). */
function like_escape(string $s): string { return str_replace(['|','%','_'], ['||','|%','|_'], $s); }

/** Construit WHERE + paramètres à partir de la période et des filtres (compte, devise, type, texte). */
function tx_filters(array $in, array $per): array {
    $w = []; $p = [];
    if ($per['from'] !== null) { $w[] = 't.tx_date>=?'; $p[] = $per['from']; }
    if ($per['to']   !== null) { $w[] = 't.tx_date<=?'; $p[] = $per['to']; }
    $acc = (int)($in['account'] ?? 0);              if ($acc > 0) { $w[] = 't.account_id=?'; $p[] = $acc; }
    $cur = $in['cur'] ?? '';                         if (in_array($cur, ['USD','FC'], true)) { $w[] = 't.currency=?'; $p[] = $cur; }
    $typ = $in['type'] ?? '';                        if (in_array($typ, ['income','expense'], true)) { $w[] = 't.type=?'; $p[] = $typ; }
    $s = trim((string)($in['search'] ?? ''));
    if ($s !== '') {
        $like = '%'.like_escape($s).'%';
        $w[] = "(t.label LIKE ? ESCAPE '|' OR t.description LIKE ? ESCAPE '|' OR ".tx_cat_expr()." LIKE ? ESCAPE '|')";
        array_push($p, $like, $like, $like);
    }
    return [$w ? implode(' AND ', $w) : '1=1', $p];
}

/** Première et dernière dates d'opérations (pour le rapport global). */
function tx_date_bounds(array $in): array {
    $w = '1=1'; $p = [];
    $acc = (int)($in['account'] ?? 0); if ($acc > 0) { $w = 'account_id=?'; $p[] = $acc; }
    $q = db()->prepare("SELECT MIN(tx_date),MAX(tx_date) FROM transactions WHERE $w"); $q->execute($p);
    [$a, $b] = $q->fetch(PDO::FETCH_NUM);
    return [$a ?: date('Y-m-d'), $b ?: date('Y-m-d')];
}

/** Résout les bornes d'un rapport global (from/to nuls) en dates réelles. */
function period_resolve(array $per, array $in): array {
    if ($per['from'] === null) { [$per['from'], $per['to']] = tx_date_bounds($in); }
    return $per;
}

/** Identifiants des comptes concernés (un compte ou tous). */
function account_ids(array $in): array {
    $acc = (int)($in['account'] ?? 0);
    if ($acc > 0) return [$acc];
    return array_map('intval', db()->query('SELECT id FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
}

/** Solde (initial de l'année + opérations de l'année avant $date) des comptes donnés, dans une devise. */
function opening_before(array $accountIds, string $cur, string $date): float {
    if (!$accountIds) return 0.0;
    $y = (int)substr($date, 0, 4);
    $sum = 0.0;
    foreach ($accountIds as $id) $sum += balance_for((int)$id, $y, $cur)['opening_amount'];
    $in = implode(',', array_map('intval', $accountIds));
    $q = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE currency=? AND tx_date>=? AND tx_date<? AND account_id IN ($in)");
    $q->execute([$cur, sprintf('%04d-01-01', $y), $date]);
    return $sum + (float)$q->fetchColumn();
}

/** Début (lundi) d'une semaine ISO. */
function week_start(string $ymd): string {
    $d = new DateTime($ymd);
    $d->modify('-'.((int)$d->format('N') - 1).' days');
    return $d->format('Y-m-d');
}

/** Clé et libellé du « seau » (jour, semaine, mois, année) d'une date. */
function bucket_of(string $gran, string $ymd): array {
    switch ($gran) {
        case 'day':
            return [$ymd, ucfirst(weekday_fr($ymd)).' '.fmt_dmy($ymd)];
        case 'week':
            $s = week_start($ymd); $e = date('Y-m-d', strtotime($s.' +6 days'));
            return [$s, 'Semaine '.date('W', strtotime($s)).' · '.date('d/m', strtotime($s)).' → '.fmt_dmy($e)];
        case 'month':
            $k = substr($ymd, 0, 7); return [$k, ucfirst(month_fr((int)substr($k,5,2))).' '.substr($k,0,4)];
        default:
            $k = substr($ymd, 0, 4); return [$k, 'Année '.$k];
    }
}

/** Tous les seaux entre deux dates (mois / années : y compris ceux sans opération). */
function bucket_range(string $gran, string $from, string $to): array {
    $out = []; $d = new DateTime($from); $end = new DateTime($to); $n = 0;
    if ($gran === 'month') { $d->modify('first day of this month'); while ($d <= $end && $n++ < 600) { [$k,$l] = bucket_of('month', $d->format('Y-m-d')); $out[$k] = $l; $d->modify('+1 month'); } }
    elseif ($gran === 'year') { $d = new DateTime($d->format('Y').'-01-01'); while ($d <= $end && $n++ < 200) { [$k,$l] = bucket_of('year', $d->format('Y-m-d')); $out[$k] = $l; $d->modify('+1 year'); } }
    return $out;
}

/**
 * Rapport par période pour UNE devise.
 * $dayRows : lignes [d, cur, ty, s, n] déjà groupées par jour.
 * Retourne ['rows'=>[[label,n,open,in,out,net,close]],'tot'=>[...],'open'=>..,'close'=>..].
 */
function period_rows(array $dayRows, string $cur, string $gran, string $from, string $to, float $opening): array {
    $b = [];
    foreach (bucket_range($gran, $from, $to) as $k => $l) $b[$k] = ['label'=>$l,'n'=>0,'in'=>0.0,'out'=>0.0];
    foreach ($dayRows as $r) {
        if ($r['cur'] !== $cur) continue;
        [$k, $l] = bucket_of($gran, $r['d']);
        $b[$k] ??= ['label'=>$l,'n'=>0,'in'=>0.0,'out'=>0.0];
        $b[$k]['n'] += (int)$r['n'];
        if ($r['ty'] === 'income') $b[$k]['in'] += (float)$r['s']; else $b[$k]['out'] += (float)$r['s'];
    }
    ksort($b);
    $rows = []; $bal = $opening; $ti = 0.0; $to_ = 0.0; $tn = 0;
    foreach ($b as $x) {
        $net = $x['in'] - $x['out'];
        $rows[] = ['label'=>$x['label'],'n'=>$x['n'],'open'=>$bal,'in'=>$x['in'],'out'=>$x['out'],'net'=>$net,'close'=>$bal + $net];
        $bal += $net; $ti += $x['in']; $to_ += $x['out']; $tn += $x['n'];
    }
    return ['rows'=>$rows,'open'=>$opening,'close'=>$bal,'in'=>$ti,'out'=>$to_,'net'=>$ti-$to_,'n'=>$tn];
}

/** Retour sûr après une action : seulement une page relative de l'application. */
function safe_return(?string $r, string $default = 'transactions.php'): string {
    $r = (string)$r;
    return preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_%&=.+\-:\[\]]*)?$/', $r) ? $r : $default;
}

/** Message affiché une fois sur la page suivante. */
function flash_tx(string $msg): void { $_SESSION['flash_tx'] = $msg; }

/** En-tête de rapport imprimable : logo, nom de l'église, titre, période, date d'impression. */
function print_head(string $title, string $subtitle = ''): string {
    $s = settings_all();
    $logo = (string)($s['logo_path'] ?? '');
    if ($logo === '' || !is_file(__DIR__.'/'.$logo)) $logo = 'assets/egd-logo.svg';
    return '<div class="report-head"><img src="'.h($logo).'" alt="Logo" style="max-width:80px;max-height:64px;object-fit:contain">'
        .'<h1>'.h($s['church_name'] ?? 'EGLISE GERME DE DAVID').'</h1>'
        .'<p><b>'.h($title).'</b></p>'
        .($subtitle !== '' ? '<small>'.h($subtitle).'</small><br>' : '')
        .'<small>Imprimé le '.date('d/m/Y H:i').' · '.h($_SESSION['full_name'] ?? '').'</small></div>';
}

/** Filtres de période réutilisables (liste Jour/Mois/Année/Période/Global + champs + raccourcis). */
function period_filter_html(array $per): string {
    $o = '<select name="scope" data-scope class="scope-sel">';
    foreach (['day'=>'Un jour précis','month'=>'Un mois','year'=>'Une année','range'=>'Période libre (du … au …)','all'=>'Global (toutes les dates)'] as $k => $l)
        $o .= '<option value="'.$k.'"'.($per['scope']===$k?' selected':'').'>'.h($l).'</option>';
    $o .= '</select>';
    $o .= '<input type="date" name="jour" value="'.h($per['jour']).'" data-scope-field="day" title="Jour">';
    $o .= '<input type="month" name="mois" value="'.h($per['mois']).'" data-scope-field="month" title="Mois">';
    $o .= '<select name="annee" data-scope-field="year" title="Année">';
    for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 15; $y--) $o .= '<option value="'.$y.'"'.($per['annee']===$y?' selected':'').'>'.$y.'</option>';
    $o .= '</select>';
    $o .= '<input type="date" name="from" value="'.h($per['rf']).'" data-scope-field="range" title="Du">';
    $o .= '<input type="date" name="to" value="'.h($per['rt']).'" data-scope-field="range" title="Au">';
    $o .= '<span class="quick-btns">'
        .'<button type="button" class="btn" data-quick="today">Aujourd\'hui</button>'
        .'<button type="button" class="btn" data-quick="month">Ce mois</button>'
        .'<button type="button" class="btn" data-quick="year">Cette année</button>'
        .'<button type="button" class="btn" data-quick="all">Global</button></span>';
    return $o;
}

/** Nom de catégorie nettoyé : espaces multiples réduits, 120 caractères maximum (taille de la colonne categories.name). */
function clean_cat_name(string $v): string {
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $v)), 0, 120);
}

/**
 * Retrouve la catégorie $name du compte, ou la crée si elle n'existe pas encore (jamais de doublon).
 * La comparaison ignore majuscules, espaces multiples et — comme la base — les accents : « CARBURANT » = « Carburant ».
 * $type = income | expense | both. Si la catégorie existe pour l'autre type, elle devient « both ».
 * Retourne ['id','name','created'=>bool,'widened'=>bool] ou null si le nom est vide.
 */
function ensure_category(PDO $pdo, int $account, string $name, string $type): ?array {
    $name = clean_cat_name($name);
    if ($name === '' || $account <= 0) return null;
    $key = norm_key($name);
    $widen = function (array $c) use ($pdo, $type): bool {
        if ($c['kind'] === 'both' || $c['kind'] === $type) return false;
        $pdo->prepare("UPDATE categories SET kind='both' WHERE id=?")->execute([(int)$c['id']]);
        return true;
    };
    $q = $pdo->prepare('SELECT id,name,kind FROM categories WHERE account_id=?');
    $q->execute([$account]);
    foreach ($q->fetchAll() as $c) {
        if (norm_key((string)$c['name']) === $key) {
            return ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'created' => false, 'widened' => $widen($c)];
        }
    }
    try {
        $pdo->prepare('INSERT INTO categories(account_id,name,kind) VALUES(?,?,?)')->execute([$account, $name, $type]);
        return ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'created' => true, 'widened' => false];
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') throw $e;
        // La base juge ce nom identique à un existant (accents/casse) : on reprend l'existant au lieu de planter.
        $s = $pdo->prepare('SELECT id,name,kind FROM categories WHERE account_id=? AND name=?');
        $s->execute([$account, $name]);
        $c = $s->fetch();
        if (!$c) throw $e;
        return ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'created' => false, 'widened' => $widen($c)];
    }
}
