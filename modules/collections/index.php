<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$pdo = getDbConnection();

require_once __DIR__.'/../../includes/BillingAutomation.php';
(new BillingAutomation($pdo))->synchronize();

$status = $_GET['status'] ?? '';
if (!in_array($status, ['pending', 'in_progress', 'settled'], true)) $status = '';
$sql = 'SELECT c.*, b.invoice_number, b.due_date, customer.full_name, customer.account_number, customer.contact_number, customer.email FROM collections c JOIN billing b ON b.id = c.billing_id JOIN customers customer ON customer.id = c.customer_id';
$parameters = [];
if ($status) { $sql .= ' WHERE c.collection_status = ?'; $parameters[] = $status; }
$sql .= ' ORDER BY c.collection_status = "settled", c.overdue_days DESC';
$stmt = $pdo->prepare($sql); $stmt->execute($parameters); $collections = $stmt->fetchAll();
$pageTitle = 'Collections'; include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h3 class="mb-0">Collection Monitoring</h3><small class="text-muted">Review overdue balances and manage collection follow-ups.</small></div><a class="btn btn-outline-secondary" href="index.php">Refresh Overdue Records</a></div>
<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<form method="get" class="d-flex gap-2 mb-3" style="max-width:340px;"><select name="status" class="form-select"><option value="">All collection statuses</option><?php foreach (['pending', 'in_progress', 'settled'] as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $option)) ?></option><?php endforeach; ?></select><button class="btn btn-outline-secondary">Filter</button></form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead class="table-light"><tr><th>Customer</th><th>Invoice</th><th>Due Date</th><th>Days Past Due</th><th>Balance</th><th>Follow-up</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody><?php foreach ($collections as $collection): ?><tr><td><div><?= htmlspecialchars($collection['full_name']) ?></div><small class="text-muted"><?= htmlspecialchars($collection['account_number']) ?></small></td><td><?= htmlspecialchars($collection['invoice_number']) ?></td><td><?= date('M j, Y', strtotime($collection['due_date'])) ?></td><td><?= $collection['overdue_days'] ?></td><td>PHP <?= number_format((float) $collection['outstanding_balance'], 2) ?></td><td><?= $collection['follow_up_date'] ? date('M j, Y', strtotime($collection['follow_up_date'])) : '-' ?><br><small class="text-muted"><?= htmlspecialchars($collection['remarks'] ?: '') ?></small></td><td><span class="badge bg-<?= $collection['collection_status'] === 'settled' ? 'success' : ($collection['collection_status'] === 'in_progress' ? 'primary' : 'warning text-dark') ?>"><?= ucwords(str_replace('_', ' ', htmlspecialchars($collection['collection_status']))) ?></span></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="update.php?id=<?= $collection['id'] ?>">Follow-up</a><?php if ($collection['collection_status'] !== 'settled'): ?><form action="send_overdue.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="id" value="<?= $collection['id'] ?>"><button class="btn btn-sm btn-outline-danger" name="channel" value="sms">SMS</button><button class="btn btn-sm btn-outline-primary" name="channel" value="telegram">Telegram</button><button class="btn btn-sm btn-outline-danger" name="channel" value="email">Email</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$collections): ?><tr><td colspan="8" class="text-center text-muted py-4">No overdue collection records found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
