<?php
// Suppression groupée des opérations (offrandes) : par libellé, par mois, par type.
// Fonctionne à l'identique en local (XAMPP/MySQL) et en ligne (Render/TiDB) : même code, même base que l'application.
// Sécurités : aperçu obligatoire, confirmation écrite, mois clôturés jamais touchés, journal d'audit détaillé (restaurable).

function bulk_norm(string $v): string { return mb_strtolower(preg_replace('/\s+/u', ' ', trim($v)), 'UTF-8'); }

/** Nettoie et valide les critères venant du formulaire. Retourne [critères, erreur|null]. */
function bulk_criteria(array $in, array $validAccountIds): array {
    $c = [
        'account' => (int)($in['account'] ?? 0),
        'year'    => (int)($in['year'] ?? 0),
        'month'   => (int)($in['month'] ?? 0),
        'type'    => in_array($in['type'] ?? '', ['income', 'expense'], true) ? $in['type'] : '',
        'cur'     => in_array($in['cur'] ?? '', ['USD', 'FC'], true) ? $in['cur'] : '',
        'label'   => trim((string)($in['label'] ?? '')),
        'match'   => ($in['match'] ?? 'exact') === 'contains' ? 'contains' : 'exact',
    ];
    if ($c['year'] < 2000 || $c['year'] > 2100) return [$c, 'Année invalide.'];
    if ($c['month'] < 0 || $c['month'] > 12) return [$c, 'Mois invalide.'];
    if ($c['account'] !== 0 && !in_array($c['account'], $validAccountIds, true)) return [$c, 'Compte invalide.'];
    if ($c['month'] === 0 && $c['label'] === '' && $c['type'] === '')
        return [$c, 'Pour supprimer toute une année, précisez au moins un type (entrées ou sorties) ou un libellé. Pour tout effacer, utilisez « Réinitialiser les données ».'];
    return [$c, null];
}

/** Opérations correspondant aux critères (mois clôturés exclus et comptés à part). */
function bulk_select(PDO $pdo, array $c): array {
    if ($c['month'] === 0) { $from = sprintf('%04d-01-01', $c['year']); $to = sprintf('%04d-01-01', $c['year'] + 1); }
    else { $from = sprintf('%04d-%02d-01', $c['year'], $c['month']); $to = $c['month'] === 12 ? sprintf('%04d-01-01', $c['year'] + 1) : sprintf('%04d-%02d-01', $c['year'], $c['month'] + 1); }
    $sql = 'SELECT t.id,t.account_id,t.tx_date,t.type,t.currency,t.amount,t.label,a.name account_name FROM transactions t JOIN accounts a ON a.id=t.account_id WHERE t.tx_date>=? AND t.tx_date<?';
    $args = [$from, $to];
    if ($c['account']) { $sql .= ' AND t.account_id=?'; $args[] = $c['account']; }
    if ($c['type'] !== '') { $sql .= ' AND t.type=?'; $args[] = $c['type']; }
    if ($c['cur'] !== '') { $sql .= ' AND t.currency=?'; $args[] = $c['cur']; }
    $st = $pdo->prepare($sql . ' ORDER BY t.tx_date,t.id'); $st->execute($args);
    $want = bulk_norm($c['label']);
    $rows = []; $locked = 0;
    foreach ($st->fetchAll() as $r) {
        if ($want !== '') {
            $l = bulk_norm((string)$r['label']);
            if ($c['match'] === 'exact' ? $l !== $want : mb_strpos($l, $want) === false) continue;
        }
        if (period_locked((int)$r['account_id'], $r['tx_date'])) { $locked++; continue; }
        $rows[] = $r;
    }
    return ['rows' => $rows, 'locked' => $locked];
}

/** Totaux par compte/devise/type pour l'aperçu. */
function bulk_summary(array $rows): array {
    $s = [];
    foreach ($rows as $r) {
        $k = $r['account_name'] . '|' . $r['currency'] . '|' . $r['type'];
        $s[$k] ??= ['account' => $r['account_name'], 'currency' => $r['currency'], 'type' => $r['type'], 'n' => 0, 'sum' => 0.0];
        $s[$k]['n']++; $s[$k]['sum'] += (float)$r['amount'];
    }
    return array_values($s);
}

function bulk_describe(array $c, array $accountNames): string {
    $p = [($c['account'] ? ($accountNames[$c['account']] ?? 'compte #' . $c['account']) : 'tous les comptes'),
          $c['month'] === 0 ? 'année ' . $c['year'] : month_fr($c['month']) . ' ' . $c['year'],
          $c['type'] === 'income' ? 'entrées' : ($c['type'] === 'expense' ? 'sorties' : 'entrées et sorties')];
    if ($c['cur'] !== '') $p[] = $c['cur'];
    if ($c['label'] !== '') $p[] = ($c['match'] === 'exact' ? 'libellé = « ' : 'libellé contenant « ') . $c['label'] . ' »';
    return implode(' · ', $p);
}

/** Supprime les lignes (en une transaction) et journalise chaque ligne supprimée (par blocs, pour tenir dans la colonne TEXT). */
function bulk_delete(PDO $pdo, array $rows, string $description): int {
    if (!$rows) return 0;
    $pdo->beginTransaction();
    try {
        foreach (array_chunk(array_column($rows, 'id'), 500) as $ids) {
            $pdo->prepare('DELETE FROM transactions WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute(array_map('intval', $ids));
        }
        $parts = array_chunk($rows, 100); $total = count($parts);
        foreach ($parts as $i => $part) {
            $data = array_map(fn($r) => [(int)$r['id'], (int)$r['account_id'], $r['tx_date'], $r['type'], $r['currency'], (float)$r['amount'], $r['label']], $part);
            audit('DELETE_BULK', 'transactions', null, json_encode(['critères' => $description, 'bloc' => ($i + 1) . '/' . $total, 'total' => count($rows), 'lignes[id,compte,date,type,devise,montant,libellé]' => $data], JSON_UNESCAPED_UNICODE));
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return count($rows);
}
