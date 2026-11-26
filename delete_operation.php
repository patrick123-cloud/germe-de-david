<?php
// Suppression d'UNE opération mal saisie (ligne supprimée, copie complète conservée dans le journal d'audit).
require_once 'tx_lib.php';
require_role(['admin', 'treasurer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: transactions.php'); exit; }
check_csrf();
$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$back = safe_return($_POST['return'] ?? '', 'transactions.php');

$q = $pdo->prepare('SELECT * FROM transactions WHERE id=?'); $q->execute([$id]); $old = $q->fetch();
if (!$old) {
    flash_tx('Opération introuvable (déjà supprimée ?). Rien n\'a été modifié.');
} elseif (period_locked((int)$old['account_id'], $old['tx_date'])) {
    flash_tx('Suppression refusée : le mois de cette opération est clôturé.');
} else {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM transactions WHERE id=?')->execute([$id]);
        audit('DELETE', 'transactions', $id, json_encode($old, JSON_UNESCAPED_UNICODE));
        $pdo->commit();
        flash_tx('Opération supprimée : « ' . $old['label'] . ' » du ' . fmt_dmy($old['tx_date']) . ' (' . money($old['amount'], $old['currency']) . '). Copie conservée dans le journal d\'audit.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_tx('Échec de la suppression : aucune donnée n\'a été supprimée.');
    }
}
header('Location: ' . $back);
exit;
