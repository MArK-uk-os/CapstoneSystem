<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$report = $_GET['report'] ?? 'billing';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? '';
$reports = ['billing', 'payments', 'collections', 'overdue'];
if (!in_array($report, $reports, true)) $report = 'billing';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
if ($to < $from) { $from = $to; }

$pdo = getDbConnection();
$parameters = [$from, $to];
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
    default:
        $sql = 'SELECT b.billing_period_start AS report_date, b.invoice_number, c.account_number, c.full_name, b.amount, b.due_date, b.status FROM billing b JOIN customers c ON c.id = b.customer_id WHERE b.billing_period_start BETWEEN ? AND ?';
        $statusOptions = ['unpaid', 'paid', 'overdue'];
}
if (!in_array($status, $statusOptions, true)) $status = '';
if ($status !== '') { $sql .= ' AND ' . ($report === 'payments' ? 'p.verification_status' : ($report === 'collections' ? 'col.collection_status' : 'b.status')) . ' = ?'; $parameters[] = $status; } elseif ($report === 'payments') { $sql .= " AND p.verification_status != 'rejected'"; }
$sql .= ' ORDER BY report_date DESC';
$stmt = $pdo->prepare($sql); $stmt->execute($parameters); $rows = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $report . '-report-' . date('Ymd') . '.csv');
    $output = fopen('php://output', 'w');
    if ($rows) { fputcsv($output, array_keys($rows[0])); foreach ($rows as $row) fputcsv($output, $row); }
    fclose($output); exit;
}

$pageTitle = ucfirst($report) . ' Report'; include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print"><div><h3 class="mb-0">Reports</h3><small class="text-muted">Analyze billing, payments, collections, and customer account activity.</small></div><button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
<form method="get" class="row g-2 mb-4 no-print"><div class="col-md-3"><label class="form-label">Report</label><select name="report" class="form-select"><?php foreach ($reports as $option): ?><option value="<?= $option ?>" <?= $report === $option ? 'selected' : '' ?>><?= ucfirst($option) ?> Report</option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div><div class="col-md-2"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div><div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">All statuses</option><?php foreach ($statusOptions as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $option)) ?></option><?php endforeach; ?></select></div><div class="col-md-3 d-flex gap-2 align-items-end"><button class="btn btn-primary">Generate</button><button class="btn btn-outline-secondary" name="export" value="csv">Export CSV</button></div></form>
<div class="card"><div class="card-header bg-white"><strong><?= ucfirst($report) ?> Report</strong><span class="text-muted ms-2"><?= date('M j, Y', strtotime($from)) ?> to <?= date('M j, Y', strtotime($to)) ?></span></div><div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead class="table-light"><tr><?php if ($rows): ?><?php foreach (array_keys($rows[0]) as $heading): ?><th><?= ucwords(str_replace('_', ' ', htmlspecialchars($heading))) ?></th><?php endforeach; ?><?php endif; ?></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($row as $key => $value): ?><td><?php if ($key === 'amount'): ?>PHP <?= number_format((float) $value, 2) ?><?php elseif ($key === 'report_date' || $key === 'due_date' || $key === 'follow_up_date'): ?><?= $value ? date('M j, Y', strtotime($value)) : '-' ?><?php else: ?><?= htmlspecialchars((string) $value) ?><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td class="text-center text-muted py-4">No report data for the selected filters.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
