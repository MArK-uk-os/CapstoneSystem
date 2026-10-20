<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = getDbConnection();
require_once __DIR__.'/../../includes/BillingAutomation.php';
(new BillingAutomation($pdo))->synchronize();
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$period = trim($_GET['period'] ?? '');
$serviceId = $_GET['service_id'] ?? '';
$allowedStatuses = ['unpaid', 'paid', 'overdue'];
if (!in_array($status, $allowedStatuses, true)) $status = '';
$services = $pdo->query("SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name")->fetchAll();
if (!ctype_digit($serviceId)) $serviceId = '';

$where = [];
$parameters = [];
if ($search !== '') {
    $where[] = '(b.invoice_number LIKE ? OR c.full_name LIKE ? OR c.account_number LIKE ?)';
    $like = '%' . $search . '%';
    array_push($parameters, $like, $like, $like);
}
if ($status !== '') {
    $where[] = 'b.status = ?';
    $parameters[] = $status;
}
if ($serviceId !== '') {
    $where[] = '(cs.service_id = ? OR (b.customer_service_id IS NULL AND c.service_id = ?))';
    array_push($parameters, $serviceId, $serviceId);
}
if (preg_match('/^\d{4}-\d{2}$/', $period)) {
    $where[] = "DATE_FORMAT(b.billing_period_start, '%Y-%m') = ?";
    $parameters[] = $period;
}

$sql = "SELECT b.*, c.account_number, c.full_name, COALESCE(s.service_name, legacy_s.service_name) AS service_name
    FROM billing b
    JOIN customers c ON c.id = b.customer_id
    LEFT JOIN customer_services cs ON cs.id = b.customer_service_id
    LEFT JOIN services s ON s.id = cs.service_id
    LEFT JOIN services legacy_s ON legacy_s.id = c.service_id";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY b.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($parameters);
$billings = $stmt->fetchAll();
$totalInvoices = (int) $pdo->query('SELECT COUNT(*) FROM billing')->fetchColumn();
$totalBilled = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM billing')->fetchColumn();
$pendingPayments = (int) $pdo->query("SELECT COUNT(*) FROM billing WHERE status = 'unpaid'")->fetchColumn();
$overdueAccounts = (int) $pdo->query("SELECT COUNT(DISTINCT customer_id) FROM billing WHERE status = 'overdue'")->fetchColumn();
$activeSubscriptions = $pdo->query("SELECT cs.id, c.account_number, c.full_name, s.service_name FROM customer_services cs JOIN customers c ON c.id = cs.customer_id JOIN services s ON s.id = cs.service_id WHERE c.status = 'active' AND cs.status = 'active' AND s.status = 'active' ORDER BY c.full_name, s.service_name")->fetchAll();
$recentInvoices = $pdo->query("SELECT b.id, b.invoice_number, c.full_name FROM billing b JOIN customers c ON c.id = b.customer_id ORDER BY b.created_at DESC LIMIT 10")->fetchAll();

$pageTitle = 'Billing';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h3 class="mb-0">Billing Management</h3><small class="text-muted">Generate, manage, and track customer invoices and billing records.</small></div>
    <div class="action-buttons">
        <?php if (hasRole('admin')): ?>
            <a href="automation.php" class="btn btn-outline-primary">Automation / Reminders</a>
            <a href="settings.php" class="btn btn-outline-secondary"><i class="bi bi-gear"></i> Billing Settings</a>
        <?php endif; ?>
        <a href="add.php" class="btn btn-primary"><i class="bi bi-file-earmark-plus"></i> Generate Invoice</a>
    </div>
</div>
<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<form method="get" class="card card-body mb-3 py-2"><div class="row g-2"><div class="col-lg-4"><input type="search" name="q" class="form-control" placeholder="Search by invoice, customer, account #, or service..." value="<?= htmlspecialchars($search) ?>"></div><div class="col-sm-6 col-lg-2"><select name="service_id" class="form-select"><option value="">All Services</option><?php foreach ($services as $service): ?><option value="<?= $service['id'] ?>" <?= $serviceId == $service['id'] ? 'selected' : '' ?>><?= htmlspecialchars($service['service_name']) ?></option><?php endforeach; ?></select></div><div class="col-sm-6 col-lg-2"><select name="status" class="form-select"><option value="">All Status</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option><?php endforeach; ?></select></div><div class="col-sm-6 col-lg-2"><input type="month" name="period" class="form-control" value="<?= htmlspecialchars($period) ?>"></div><div class="col-sm-6 col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel"></i> Filter</button><a class="btn btn-outline-secondary" href="index.php">Clear</a></div></div></form>
<section class="row g-2 mb-3 billing-metrics"><div class="col-sm-6 col-xl-3"><div class="card metric-card"><div class="card-body"><i class="mdi mdi-file-document-outline metric-icon text-primary"></i><div class="text-muted metric-label">Total Invoices</div><div class="fs-4 fw-bold"><?= $totalInvoices ?></div></div></div></div><div class="col-sm-6 col-xl-3"><div class="card metric-card"><div class="card-body"><i class="mdi mdi-cash-multiple metric-icon text-success"></i><div class="text-muted metric-label">Total Billed Amount</div><div class="fs-4 fw-bold">PHP <?= number_format($totalBilled, 2) ?></div></div></div></div><div class="col-sm-6 col-xl-3"><div class="card metric-card"><div class="card-body"><i class="mdi mdi-clock-outline metric-icon text-warning"></i><div class="text-muted metric-label">Pending Payments</div><div class="fs-4 fw-bold"><?= $pendingPayments ?></div></div></div></div><div class="col-sm-6 col-xl-3"><div class="card metric-card"><div class="card-body"><i class="mdi mdi-alert-outline metric-icon text-danger"></i><div class="text-muted metric-label">Overdue Accounts</div><div class="fs-4 fw-bold"><?= $overdueAccounts ?></div></div></div></div></section>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead class="table-light"><tr><th>Invoice #</th><th>Customer</th><th>Service</th><th>Period</th><th>Due Date</th><th>Amount</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody><?php foreach ($billings as $billing): ?><tr><td><?= htmlspecialchars($billing['invoice_number']) ?></td><td><div><?= htmlspecialchars($billing['full_name']) ?></div><small class="text-muted"><?= htmlspecialchars($billing['account_number']) ?></small></td><td><?= htmlspecialchars($billing['service_name']) ?></td><td><?= date('M j', strtotime($billing['billing_period_start'])) ?> - <?= date('M j, Y', strtotime($billing['billing_period_end'])) ?></td><td><?= date('M j, Y', strtotime($billing['due_date'])) ?></td><td>PHP <?= number_format((float) $billing['amount'], 2) ?></td><td><span class="badge bg-<?= $billing['status'] === 'paid' ? 'success' : ($billing['status'] === 'overdue' ? 'danger' : 'warning text-dark') ?>"><?= ucfirst(htmlspecialchars($billing['status'])) ?></span></td><td class="text-end"><a href="invoice.php?id=<?= $billing['id'] ?>" class="btn btn-sm btn-outline-primary">View Invoice</a></td></tr><?php endforeach; ?><?php if (!$billings): ?><tr><td colspan="8" class="text-center text-muted py-4">No billing records found.</td></tr><?php endif; ?></tbody></table></div></div>
<section class="row g-2 mt-3 billing-workspace">
    <div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-body"><h5><i class="mdi mdi-file-document-outline text-primary me-2"></i>Generate Billing</h5><p class="text-muted small">Create an invoice for an active customer service subscription.</p><form action="add.php" method="get"><label class="form-label" for="quick_customer_service_id">Customer Service</label><select class="form-select" id="quick_customer_service_id" name="customer_service_id"><option value="">Select a subscription</option><?php foreach ($activeSubscriptions as $subscription): ?><option value="<?= $subscription['id'] ?>"><?= htmlspecialchars($subscription['account_number'] . ' - ' . $subscription['full_name'] . ' (' . $subscription['service_name'] . ')') ?></option><?php endforeach; ?></select><button class="btn btn-primary w-100 mt-3" type="submit"><i class="mdi mdi-plus-circle-outline"></i> Generate Invoice</button></form></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-body"><h5><i class="mdi mdi-file-find text-primary me-2"></i>Invoice Preview</h5><p class="text-muted small">Open an invoice to review, print, or save it as a PDF.</p><?php if ($recentInvoices): ?><div class="list-group list-group-flush"><?php foreach (array_slice($recentInvoices, 0, 3) as $invoice): ?><a class="list-group-item list-group-item-action px-0" href="invoice.php?id=<?= $invoice['id'] ?>"><strong><?= htmlspecialchars($invoice['invoice_number']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($invoice['full_name']) ?></small></a><?php endforeach; ?></div><?php else: ?><div class="billing-empty-state">No invoice available for preview.</div><?php endif; ?></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-body"><h5><i class="mdi mdi-send text-primary me-2"></i>Send Notifications</h5><p class="text-muted small">Use each invoice to send its billing notice by email or SMS.</p><?php if ($recentInvoices): ?><a class="btn btn-primary w-100" href="invoice.php?id=<?= $recentInvoices[0]['id'] ?>"><i class="mdi mdi-send"></i> Send Invoice Notice</a><?php else: ?><div class="billing-empty-state">Create an invoice to send a notice.</div><?php endif; ?><a class="btn btn-outline-secondary w-100 mt-2" href="notifications.php">Notification History</a></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-body"><h5><i class="mdi mdi-cog-outline text-primary me-2"></i>Billing Settings</h5><p class="text-muted small">Review delivery provider readiness before sending customer notices.</p><a class="btn btn-outline-primary w-100" href="settings.php"><i class="mdi mdi-cog"></i> Open Settings</a></div></div></div>
</section>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
