<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = getDbConnection();
require_once __DIR__.'/../../includes/BillingAutomation.php';
(new BillingAutomation($pdo))->synchronize();
$totalCustomers = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$activeCustomers = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'active'")->fetchColumn();
$paidBills = (int) $pdo->query("SELECT COUNT(*) FROM billing WHERE status = 'paid'")->fetchColumn();
$unpaidBills = (int) $pdo->query("SELECT COUNT(*) FROM billing WHERE status = 'unpaid'")->fetchColumn();
$overdueBills = (int) $pdo->query("SELECT COUNT(*) FROM billing WHERE status = 'overdue'")->fetchColumn();
$monthlyCollections = (float) $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE verification_status = 'verified' AND DATE_FORMAT(payment_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
$outstandingBalance = (float) $pdo->query("SELECT COALESCE(SUM(GREATEST(b.amount-COALESCE(p.paid,0),0)),0) FROM billing b LEFT JOIN (SELECT billing_id,SUM(amount_paid) paid FROM payments WHERE verification_status='verified' GROUP BY billing_id) p ON p.billing_id=b.id WHERE b.status IN ('unpaid','overdue')")->fetchColumn();
$recentBills = $pdo->query('SELECT b.invoice_number, b.amount, b.status, b.due_date, c.full_name FROM billing b JOIN customers c ON c.id = b.customer_id ORDER BY b.created_at DESC LIMIT 5')->fetchAll();
$paidAccounts=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT customer_service_id FROM billing WHERE customer_service_id IS NOT NULL GROUP BY customer_service_id HAVING SUM(status!='paid')=0) accounts")->fetchColumn();
$unpaidAccounts=(int)$pdo->query("SELECT COUNT(DISTINCT customer_service_id) FROM billing WHERE status IN ('unpaid','overdue')")->fetchColumn();
$overdueAccounts=(int)$pdo->query("SELECT COUNT(DISTINCT customer_service_id) FROM billing WHERE status='overdue'")->fetchColumn();

$pageTitle = 'Dashboard';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center dashboard-heading"><div><h3 class="mb-0">Dashboard</h3><small class="text-muted">Monitor customers, invoices, collections, and account activity.</small></div><span class="text-muted">Current month: <?= date('F Y') ?></span></div>
<section class="row g-2 dashboard-metrics">
    <div class="col-sm-6 col-xl-3">
        <div class="card text-bg-primary metric-card">
            <div class="card-body">
            <i class="mdi mdi-account-multiple metric-icon"></i>
                <div class="fs-2 fw-bold"><?= $totalCustomers ?></div>
            <div class="metric-label">Total Customers</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card text-bg-success metric-card">
            <div class="card-body">
            <i class="mdi mdi-account-check metric-icon"></i>
                <div class="fs-2 fw-bold"><?= $activeCustomers ?></div>
            <div class="metric-label">Active Customers</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card text-bg-warning metric-card">
            <div class="card-body">
            <i class="mdi mdi-alert-circle-outline metric-icon"></i>
                <div class="fs-2 fw-bold"><?= $overdueBills ?></div>
            <div class="metric-label">Overdue Invoices</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card text-bg-info metric-card">
            <div class="card-body">
            <i class="mdi mdi-cash-multiple metric-icon"></i>
                <div class="fs-2 fw-bold">PHP <?= number_format($monthlyCollections, 2) ?></div>
            <div class="metric-label">Monthly Collections</div>
            </div>
        </div>
    </div>
</section>
<section class="row g-2 mt-1 dashboard-metrics-secondary">
    <div class="col-md-4"><div class="card metric-card"><div class="card-body"><i class="mdi mdi-check-circle-outline metric-icon text-success"></i><div class="text-muted metric-label">Paid Invoices</div><div class="fs-5 fw-bold text-success"><?= $paidBills ?></div></div></div></div>
    <div class="col-md-4"><div class="card metric-card"><div class="card-body"><i class="mdi mdi-receipt metric-icon text-primary"></i><div class="text-muted metric-label">Unpaid Invoices</div><div class="fs-5 fw-bold text-primary"><?= $unpaidBills ?></div></div></div></div>
    <div class="col-md-4"><div class="card metric-card"><div class="card-body"><i class="mdi mdi-cash-remove metric-icon text-danger"></i><div class="text-muted metric-label">Outstanding Balance</div><div class="fs-5 fw-bold text-danger">PHP <?= number_format($outstandingBalance, 2) ?></div></div></div></div>
</section>
<div class="card p-3 mb-3"><strong>Service accounts</strong><p class="mb-0">Fully paid: <?= $paidAccounts ?> · With unpaid balances: <?= $unpaidAccounts ?> · Overdue: <?= $overdueAccounts ?></p><small>Accounts with overdue invoices are included in unpaid accounts.</small></div>
<section class="card dashboard-table"><div class="card-header bg-white"><i class="mdi mdi-receipt text-primary me-2"></i><strong>Recent Billing Records</strong></div><div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Invoice</th><th>Customer</th><th>Due Date</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php foreach ($recentBills as $bill): ?><tr><td><?= htmlspecialchars($bill['invoice_number']) ?></td><td><?= htmlspecialchars($bill['full_name']) ?></td><td><?= date('M j, Y', strtotime($bill['due_date'])) ?></td><td>PHP <?= number_format((float) $bill['amount'], 2) ?></td><td><?= ucfirst(htmlspecialchars($bill['status'])) ?></td></tr><?php endforeach; ?><?php if (!$recentBills): ?><tr><td colspan="5" class="text-center text-muted py-3">No billing records yet.</td></tr><?php endif; ?></tbody></table></div></section>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
