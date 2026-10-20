<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = getDbConnection();
$billingId = filter_input(INPUT_GET, 'billing_id', FILTER_VALIDATE_INT);
$channel = $_GET['channel'] ?? '';
$status = $_GET['status'] ?? '';
if (!in_array($channel, ['sms', 'email', 'telegram'], true)) $channel = '';
if (!in_array($status, ['processing', 'sent', 'delivered', 'failed'], true)) $status = '';

$where = [];
$parameters = [];
if ($billingId) { $where[] = 'n.billing_id = ?'; $parameters[] = $billingId; }
if ($channel) { $where[] = 'n.channel = ?'; $parameters[] = $channel; }
if ($status) { $where[] = 'n.status = ?'; $parameters[] = $status; }
$sql = 'SELECT n.*, b.invoice_number, c.full_name, c.account_number FROM notifications n JOIN customers c ON c.id = n.customer_id LEFT JOIN billing b ON b.id = n.billing_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY n.sent_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($parameters);
$notifications = $stmt->fetchAll();

$pageTitle = 'Notification History';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h3 class="mb-0">Notification History</h3><small class="text-muted">Track email and SMS delivery attempts for customer notices.</small></div><a class="btn btn-outline-secondary" href="<?= $billingId ? 'invoice.php?id=' . $billingId : 'index.php' ?>">Back</a></div>
<form method="get" class="row g-2 mb-3"><?php if ($billingId): ?><input type="hidden" name="billing_id" value="<?= $billingId ?>"><?php endif; ?><div class="col-md-3"><select name="channel" class="form-select"><option value="">All channels</option><option value="sms" <?= $channel === 'sms' ? 'selected' : '' ?>>SMS</option><option value="telegram" <?= $channel === 'telegram' ? 'selected' : '' ?>>Telegram</option><option value="email" <?= $channel === 'email' ? 'selected' : '' ?>>Email</option></select></div><div class="col-md-3"><select name="status" class="form-select"><option value="">All statuses</option><?php foreach (['processing', 'sent', 'delivered', 'failed'] as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option><?php endforeach; ?></select></div><div class="col-md-3 d-flex gap-2 action-buttons"><button class="btn btn-outline-secondary" type="submit">Filter</button><a class="btn btn-outline-secondary" href="notifications.php<?= $billingId ? '?billing_id=' . $billingId : '' ?>">Clear</a></div></form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead class="table-light"><tr><th>Sent At</th><th>Customer</th><th>Invoice</th><th>Type</th><th>Channel</th><th>Status</th><th>Error</th></tr></thead><tbody><?php foreach ($notifications as $notification): ?><tr><td><?= date('M j, Y g:i A', strtotime($notification['sent_at'])) ?></td><td><div><?= htmlspecialchars($notification['full_name']) ?></div><small class="text-muted"><?= htmlspecialchars($notification['account_number']) ?></small></td><td><?= htmlspecialchars($notification['invoice_number'] ?: '-') ?></td><td><?= ucwords(str_replace('_', ' ', htmlspecialchars($notification['type']))) ?></td><td><?= strtoupper(htmlspecialchars($notification['channel'])) ?></td><td><span class="badge bg-<?= $notification['status'] === 'failed' ? 'danger' : ($notification['status'] === 'processing' ? 'warning text-dark' : ($notification['status'] === 'delivered' ? 'success' : 'secondary')) ?>"><?= ucfirst(htmlspecialchars($notification['status'])) ?></span></td><td><small class="text-muted"><?= htmlspecialchars(substr((string) ($notification['error_message'] ?? ''), 0, 120)) ?></small></td></tr><?php endforeach; ?><?php if (!$notifications): ?><tr><td colspan="7" class="text-center text-muted py-4">No notification records found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>