<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT); if (!$id) { header('Location: index.php'); exit; }
$pdo = getDbConnection(); $stmt = $pdo->prepare('SELECT c.*, b.invoice_number, customer.full_name FROM collections c JOIN billing b ON b.id = c.billing_id JOIN customers customer ON customer.id = c.customer_id WHERE c.id = ?'); $stmt->execute([$id]); $collection = $stmt->fetch(); if (!$collection) { http_response_code(404); exit('Collection record not found.'); }
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { $errors[] = 'Invalid request. Please try again.'; }
    else {
        $collection['collection_status'] = $_POST['collection_status'] ?? '';
        $collection['follow_up_date'] = trim($_POST['follow_up_date'] ?? '');
        $collection['remarks'] = trim($_POST['remarks'] ?? '');
        if (!in_array($collection['collection_status'], ['pending', 'in_progress', 'settled'], true)) $errors[] = 'Invalid collection status.';
        if ($collection['remarks'] !== '' && strlen($collection['remarks']) > 255) $errors[] = 'Remarks must not exceed 255 characters.';
        if (!$errors) { $pdo->prepare('UPDATE collections SET collection_status = ?, follow_up_date = ?, remarks = ?, updated_by = ? WHERE id = ?')->execute([$collection['collection_status'], $collection['follow_up_date'] ?: null, $collection['remarks'] ?: null, currentUser()['id'], $id]); $pdo->prepare('INSERT INTO collection_activities (collection_id,status,follow_up_date,remarks,staff_id) VALUES (?,?,?,?,?)')->execute([$id,$collection['collection_status'],$collection['follow_up_date']?:null,$collection['remarks']?:null,currentUser()['id']]); header('Location: index.php?msg=' . urlencode('Collection follow-up updated.')); exit; }
    }
}
$pageTitle = 'Collection Follow-up'; include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/sidebar.php';
?>
<h3 class="mb-1">Collection Follow-up</h3><p class="text-muted mb-4">Record the next action and remarks for an overdue account.</p><?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card" style="max-width:680px;"><div class="card-body"><p class="mb-4"><strong><?= htmlspecialchars($collection['full_name']) ?></strong><br><span class="text-muted"><?= htmlspecialchars($collection['invoice_number']) ?></span></p><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><div class="mb-3"><label class="form-label">Collection Status</label><select class="form-select" name="collection_status"><?php foreach (['pending', 'in_progress', 'settled'] as $option): ?><option value="<?= $option ?>" <?= $collection['collection_status'] === $option ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $option)) ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Next Follow-up Date</label><input class="form-control" type="date" name="follow_up_date" value="<?= htmlspecialchars($collection['follow_up_date'] ?: '') ?>"></div><div class="mb-3"><label class="form-label">Remarks</label><textarea class="form-control" name="remarks" maxlength="255" rows="3"><?= htmlspecialchars($collection['remarks'] ?: '') ?></textarea></div><button class="btn btn-primary">Save Follow-up</button><a class="btn btn-outline-secondary" href="index.php">Cancel</a></form></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>