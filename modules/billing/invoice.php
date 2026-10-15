<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/notifications/NotificationService.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?msg=' . urlencode('Invalid billing record.')); exit; }
$pdo = getDbConnection();
$stmt = $pdo->prepare('SELECT b.*, c.account_number, c.full_name, c.contact_number, c.email, c.address, COALESCE(b.service_name_snapshot,s.service_name, legacy_s.service_name) AS service_name FROM billing b JOIN customers c ON c.id = b.customer_id LEFT JOIN customer_services cs ON cs.id = b.customer_service_id LEFT JOIN services s ON s.id = cs.service_id LEFT JOIN services legacy_s ON legacy_s.id = c.service_id WHERE b.id = ?');
$stmt->execute([$id]);
$billing = $stmt->fetch();
if (!$billing) { http_response_code(404); exit('Billing record not found.'); }

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $notice = ['success' => false, 'message' => 'Invalid request. Please try again.'];
    } elseif (!in_array($_POST['channel'] ?? '', ['sms', 'email', 'telegram'], true)) {
        $notice = ['success' => false, 'message' => 'Invalid notification channel.'];
    } else {
        $notice = (new NotificationService($pdo))->sendBillingNotification($billing, $_POST['channel']);
    }
}

$pageTitle = 'Invoice ' . $billing['invoice_number'];
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print"><div><h3 class="mb-0">Invoice</h3><small class="text-muted">Review billing details, print the invoice, or send a notice.</small></div><div class="d-flex gap-2 action-buttons"><a href="index.php" class="btn btn-outline-secondary">Back</a><button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save PDF</button></div></div>
<?php if ($notice): ?><div class="alert alert-<?= $notice['success'] ? 'success' : 'danger' ?> no-print"><?= htmlspecialchars($notice['message']) ?></div><?php endif; ?>
<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success no-print"><?= htmlspecialchars($_GET['msg']) ?></div><?php endif; ?>
<article class="card" id="invoice"><div class="card-body p-md-5"><div class="d-flex justify-content-between border-bottom pb-4 mb-4"><div><h2 class="mb-1">Cloud Interweb</h2><p class="mb-0 text-muted">Billing &amp; Collection Management System</p></div><div class="text-end"><h4 class="mb-1">INVOICE</h4><div><?= htmlspecialchars($billing['invoice_number']) ?></div><span class="badge bg-<?= $billing['status'] === 'paid' ? 'success' : ($billing['status'] === 'overdue' ? 'danger' : 'warning text-dark') ?>"><?= strtoupper(htmlspecialchars($billing['status'])) ?></span></div></div><div class="row mb-4"><div class="col-md-6"><h6>Bill To</h6><strong><?= htmlspecialchars($billing['full_name']) ?></strong><br>Customer ID: <?= htmlspecialchars($billing['account_number']) ?><br>Service Account: <?= htmlspecialchars($billing['account_number_snapshot'] ?? '') ?><br><?= nl2br(htmlspecialchars($billing['address'] ?: '')) ?></div><div class="col-md-6 text-md-end mt-3 mt-md-0"><div><strong>Billing Period:</strong> <?= date('M j, Y', strtotime($billing['billing_period_start'])) ?> - <?= date('M j, Y', strtotime($billing['billing_period_end'])) ?></div><div><strong>Due Date:</strong> <?= date('M j, Y', strtotime($billing['due_date'])) ?></div></div></div><table class="table"><thead><tr><th>Description</th><th class="text-end">Amount</th></tr></thead><tbody><tr><td><?= htmlspecialchars($billing['service_name']) ?> service</td><td class="text-end">PHP <?= number_format((float) $billing['amount'], 2) ?></td></tr></tbody><tfoot><tr><th class="text-end">Total Due</th><th class="text-end">PHP <?= number_format((float) $billing['amount'], 2) ?></th></tr></tfoot></table></div></article>
<section class="mt-4 no-print"><a class="btn btn-outline-primary mb-3" href="change_due.php?id=<?= $id ?>">Change Due Date</a><h5>Send Billing Notification</h5><div class="d-flex flex-wrap align-items-center" style="gap:8px;"><form method="post" class="m-0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><button class="btn btn-primary" name="channel" value="telegram">Send Telegram</button></form><form method="post" class="m-0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="channel" value="sms"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-chat-dots"></i> Send SMS</button></form><form method="post" class="m-0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="channel" value="email"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-envelope"></i> Send Email</button></form><a class="btn btn-outline-secondary" href="notifications.php?billing_id=<?= $billing['id'] ?>">Notification History</a></div></section>
<?php include __DIR__ . '/../../includes/footer.php'; ?>