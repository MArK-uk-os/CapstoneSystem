<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$report = $_GET['report'] ?? 'billing';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$reports = ['billing', 'payments', 'collections', 'overdue', 'revenue'];
if (!in_array($report, $reports, true)) $report = 'billing';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
if ($to < $from) { $from = $to; }

$pdo = getDbConnection();
$parameters = [$from, $to];
$grossInvoiced = 0.0;
$collectedRevenue = 0.0;
$outstandingBalance = 0.0;
$collectionEfficiency = 0.0;
$revenueBreakdown = [];
switch ($report) {
    case 'payments':
        $sql = 'SELECT p.payment_date AS report_date, b.invoice_number, c.account_number, c.full_name, p.amount_paid AS amount, p.payment_method, p.reference_number, p.verification_status AS status FROM payments p JOIN billing b ON b.id = p.billing_id JOIN customers c ON c.id = b.customer_id WHERE p.payment_date BETWEEN ? AND ?';
        $statusOptions = ['pending', 'verified', 'rejected'];
        break;
    case 'collections':
        $sql = 'SELECT b.due_date AS report_date, b.invoice_number, c.account_number, c.full_name, col.outstanding_balance AS amount, col.overdue_days, col.follow_up_date, col.collection_status AS status FROM collections col JOIN billing b ON b.id = col.billing_id JOIN customers c ON c.id = col.customer_id WHERE b.due_date BETWEEN ? AND ?';
        $statusOptions = ['pending', 'in_progress', 'settled'];
        break;
    case 'overdue':
        $sql = "SELECT b.due_date AS report_date, b.invoice_number, c.account_number, c.full_name, GREATEST(b.amount-(SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE billing_id=b.id AND verification_status='verified'),0) amount, DATEDIFF(CURDATE(), b.due_date) AS overdue_days, b.status FROM billing b JOIN customers c ON c.id = b.customer_id WHERE b.status = 'overdue' AND b.due_date BETWEEN ? AND ?";
        $statusOptions = ['overdue'];
        break;
    case 'revenue':
        $statusOptions = [];
        break;
    default:
        $sql = 'SELECT b.billing_period_start AS report_date, b.invoice_number, c.account_number, c.full_name, b.amount, b.due_date, b.status FROM billing b JOIN customers c ON c.id = b.customer_id WHERE b.billing_period_start BETWEEN ? AND ?';
        $statusOptions = ['unpaid', 'paid', 'overdue'];
}
if ($report === 'revenue') {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM billing WHERE billing_period_start BETWEEN ? AND ?');
    $stmt->execute([$from, $to]);
    $grossInvoiced = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE verification_status = 'verified' AND payment_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    $collectedRevenue = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(b.amount-COALESCE(p.paid,0),0)),0) FROM billing b LEFT JOIN (SELECT billing_id,SUM(amount_paid) paid FROM payments WHERE verification_status='verified' GROUP BY billing_id) p ON p.billing_id=b.id WHERE b.status IN ('unpaid','overdue') AND b.billing_period_start BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    $outstandingBalance = (float) $stmt->fetchColumn();

    $collectionEfficiency = $grossInvoiced > 0 ? round($collectedRevenue / $grossInvoiced * 100, 2) : 0.0;

    $stmt = $pdo->prepare("SELECT COALESCE(ip.name, 'Unassigned / Legacy') AS plan_name, COUNT(p.id) AS transaction_count, COALESCE(SUM(p.amount_paid), 0) AS total_revenue FROM payments p JOIN billing b ON b.id = p.billing_id LEFT JOIN customer_services cs ON cs.id = b.customer_service_id LEFT JOIN internet_plans ip ON ip.id = cs.plan_id WHERE p.verification_status = 'verified' AND p.payment_date BETWEEN ? AND ? GROUP BY ip.id, ip.name ORDER BY total_revenue DESC");
    $stmt->execute([$from, $to]);
    $revenueBreakdown = $stmt->fetchAll();
    $rows = [];
} else {
    if (!in_array($status, $statusOptions, true)) $status = '';
    if ($status !== '') { $sql .= ' AND ' . ($report === 'payments' ? 'p.verification_status' : ($report === 'collections' ? 'col.collection_status' : 'b.status')) . ' = ?'; $parameters[] = $status; } elseif ($report === 'payments') { $sql .= " AND p.verification_status != 'rejected'"; }
    $sql .= ' ORDER BY report_date DESC';
    $stmt = $pdo->prepare($sql); $stmt->execute($parameters); $rows = $stmt->fetchAll();
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $report . '-report-' . date('Ymd') . '.csv');
    $output = fopen('php://output', 'w');
    if ($report === 'revenue') {
        fputcsv($output, ['Metric', 'Value']);
        fputcsv($output, ['Total Billed Amount', $grossInvoiced]);
        fputcsv($output, ['Total Verified Revenue', $collectedRevenue]);
        fputcsv($output, ['Outstanding Balance', $outstandingBalance]);
        fputcsv($output, ['Collection Efficiency (%)', $collectionEfficiency]);
        fputcsv($output, []);
        fputcsv($output, ['Plan Name', 'Transactions', 'Total Revenue']);
        foreach ($revenueBreakdown as $row) fputcsv($output, [$row['plan_name'], $row['transaction_count'], $row['total_revenue']]);
    } elseif ($rows) {
        fputcsv($output, array_keys($rows[0]));
        foreach ($rows as $row) fputcsv($output, $row);
    }
    fclose($output); exit;
}

$pageTitle = $report === 'revenue' ? 'Revenue Summary Report' : ucfirst($report) . ' Report'; include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print"><div><h3 class="mb-0">Reports</h3><small class="text-muted">Analyze billing, payments, collections, revenue, and customer account activity.</small></div><button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
<form method="get" class="row g-2 mb-4 no-print"><div class="col-md-3"><label class="form-label">Report</label><select name="report" class="form-select"><?php foreach ($reports as $option): ?><option value="<?= $option ?>" <?= $report === $option ? 'selected' : '' ?>><?= $option === 'revenue' ? 'Revenue Summary' : ucfirst($option) . ' Report' ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div><div class="col-md-2"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div><?php if ($report !== 'revenue'): ?><div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">All statuses</option><?php foreach ($statusOptions as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $option)) ?></option><?php endforeach; ?></select></div><?php endif; ?><div class="<?= $report === 'revenue' ? 'col-md-5' : 'col-md-3' ?> d-flex gap-2 align-items-end"><button class="btn btn-primary">Generate</button><button class="btn btn-outline-secondary" name="export" value="csv">Export CSV</button></div></form>
<?php if ($report === 'revenue'): ?>
<section class="row g-2 mb-3">
    <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted">Total Billed Amount</div><div class="fs-5 fw-bold">PHP <?= number_format($grossInvoiced, 2) ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted">Total Verified Revenue</div><div class="fs-5 fw-bold text-success">PHP <?= number_format($collectedRevenue, 2) ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted">Outstanding Balance</div><div class="fs-5 fw-bold text-danger">PHP <?= number_format($outstandingBalance, 2) ?></div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted">Collection Efficiency</div><div class="fs-5 fw-bold text-primary"><?= number_format($collectionEfficiency, 2) ?>%</div></div></div></div>
</section>
<div class="card"><div class="card-header bg-white"><strong>Revenue Breakdown by Plan</strong><span class="text-muted ms-2"><?= date('M j, Y', strtotime($from)) ?> to <?= date('M j, Y', strtotime($to)) ?></span></div><div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead class="table-light"><tr><th>Plan Name</th><th>Transactions</th><th>Total Revenue</th></tr></thead><tbody><?php foreach ($revenueBreakdown as $row): ?><tr><td><?= htmlspecialchars($row['plan_name']) ?></td><td><?= (int) $row['transaction_count'] ?></td><td>PHP <?= number_format((float) $row['total_revenue'], 2) ?></td></tr><?php endforeach; ?><?php if (!$revenueBreakdown): ?><tr><td colspan="3" class="text-center text-muted py-4">No verified revenue for the selected filters.</td></tr><?php endif; ?></tbody></table></div></div>
<?php else: ?>
<div class="card"><div class="card-header bg-white"><strong><?= ucfirst($report) ?> Report</strong><span class="text-muted ms-2"><?= date('M j, Y', strtotime($from)) ?> to <?= date('M j, Y', strtotime($to)) ?></span></div><div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead class="table-light"><tr><?php if ($rows): ?><?php foreach (array_keys($rows[0]) as $heading): ?><th><?= ucwords(str_replace('_', ' ', htmlspecialchars($heading))) ?></th><?php endforeach; ?><?php endif; ?></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($row as $key => $value): ?><td><?php if ($key === 'amount'): ?>PHP <?= number_format((float) $value, 2) ?><?php elseif ($key === 'report_date' || $key === 'due_date' || $key === 'follow_up_date'): ?><?= $value ? date('M j, Y', strtotime($value)) : '-' ?><?php else: ?><?= htmlspecialchars((string) $value) ?><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td class="text-center text-muted py-4">No report data for the selected filters.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
