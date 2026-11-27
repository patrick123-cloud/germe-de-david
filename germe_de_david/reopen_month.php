<?php
// Mois clôturé : le ROUVRIR (déverrouiller : saisie, modification et suppression redeviennent possibles)
// ou le RÉINITIALISER (rouvrir puis refaire immédiatement la clôture avec les chiffres actuels).
// Réservé aux administrateurs. Aucune opération n'est modifiée ; l'ancienne clôture est conservée dans le journal d'audit.
require_once 'tx_lib.php';
require_once 'close_lib.php';
require_role(['admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: reports.php'); exit; }
check_csrf();
$year = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);
$only = (int)($_POST['account_id'] ?? 0);
$mode = ($_POST['mode'] ?? '') === 'recalc' ? 'recalc' : 'reopen';
if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) exit('Période invalide.');
$back = 'reports.php?year=' . $year . '&month=' . $month . '&account=' . $only;
$pdo = db();

$where = 'year=? AND month=?';
$args = [$year, $month];
if ($only) { $where .= ' AND account_id=?'; $args[] = $only; }

$pdo->beginTransaction();
try {
    $sel = $pdo->prepare("SELECT account_id,currency,opening_amount,income,expense,closing_amount FROM monthly_inventories WHERE $where");
    $sel->execute($args);
    $old = $sel->fetchAll();
    if (!$old) {
        $pdo->rollBack();
        flash_tx('Ce mois n\'est pas clôturé pour la sélection choisie : rien à rouvrir.');
        header('Location: ' . $back); exit;
    }
    $accIds = array_values(array_unique(array_map(fn($r) => (int)$r['account_id'], $old)));
    $pdo->prepare("DELETE FROM monthly_inventories WHERE $where")->execute($args);
    if ($mode === 'recalc') {
        close_month_accounts($pdo, $year, $month, $accIds, (int)$_SESSION['user_id']);
    }
    // Des mois suivants encore clôturés gardent l'ancien solde d'ouverture : on prévient.
    $in = implode(',', array_map('intval', $accIds));
    $later = (int)$pdo->query("SELECT COUNT(*) FROM monthly_inventories WHERE account_id IN ($in) AND (year>$year OR (year=$year AND month>$month))")->fetchColumn();
    audit($mode === 'recalc' ? 'RECLOSE' : 'REOPEN', 'monthly_inventories', null,
        json_encode(['periode' => sprintf('%04d-%02d', $year, $month), 'comptes' => $accIds, 'clotures_avant' => $old], JSON_UNESCAPED_UNICODE));
    $pdo->commit();
    $msg = $mode === 'recalc'
        ? 'Clôture de ' . month_fr($month) . ' ' . $year . ' réinitialisée : rouverte puis refaite avec les chiffres actuels.'
        : ucfirst(month_fr($month)) . ' ' . $year . ' rouvert : la saisie, la modification et la suppression sont de nouveau possibles. Pensez à clôturer à nouveau après vos corrections.';
    if ($later > 0) $msg .= ' Attention : des mois suivants sont encore clôturés ; si vous corrigez des opérations, rouvrez-les aussi et recalculez-les.';
    flash_tx($msg);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_tx('Échec : aucune clôture n\'a été modifiée. ' . $e->getMessage());
}
header('Location: ' . $back);
exit;
