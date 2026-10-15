<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = getDbConnection();
$customers = $pdo->query(
    "SELECT cs.id AS customer_service_id, c.id AS customer_id, c.account_number, c.full_name, s.service_name, COALESCE(ip.monthly_rate,cs.billing_amount) * CASE cs.billing_frequency WHEN 'quarterly' THEN 3 WHEN 'annually' THEN 12 ELSE 1 END AS billing_amount
     FROM customer_services cs
     JOIN customers c ON c.id = cs.customer_id
     JOIN services s ON s.id = cs.service_id LEFT JOIN internet_plans ip ON ip.id=cs.plan_id
     WHERE c.status = 'active' AND cs.status = 'active' AND s.status = 'active'
     ORDER BY c.full_name"
)->fetchAll();

$errors = [];
$values = [
    'customer_service_id' => ctype_digit($_GET['customer_service_id'] ?? '') ? $_GET['customer_service_id'] : '',
    'billing_period_start' => date('Y-m-01'),
    'billing_period_end' => date('Y-m-t'),
    'due_date' => date('Y-m-d', strtotime('+14 days')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        foreach ($values as $key => $_) {
            $values[$key] = trim($_POST[$key] ?? '');
        }

        if (!ctype_digit($values['customer_service_id'])) $errors[] = 'Please select a customer service.';
        if (!$values['billing_period_start'] || !$values['billing_period_end'] || !$values['due_date']) {
            $errors[] = 'Billing period and due date are required.';
        } elseif ($values['billing_period_end'] < $values['billing_period_start']) {
            $errors[] = 'Billing period end must be on or after the start date.';
        } elseif ($values['due_date'] < $values['billing_period_start']) {
            $errors[] = 'Due date must be on or after the billing period start.';
        }

        if (!$errors) {
            $customer = $pdo->prepare(
                 "SELECT cs.id AS customer_service_id, c.id AS customer_id, COALESCE(ip.monthly_rate,cs.billing_amount) * CASE cs.billing_frequency WHEN 'quarterly' THEN 3 WHEN 'annually' THEN 12 ELSE 1 END AS billing_amount
                  FROM customer_services cs JOIN customers c ON c.id = cs.customer_id JOIN services s ON s.id = cs.service_id LEFT JOIN internet_plans ip ON ip.id=cs.plan_id
                  WHERE cs.id = ? AND c.status = 'active' AND cs.status = 'active' AND s.status = 'active'"
            );
              $customer->execute([$values['customer_service_id']]);
            $customer = $customer->fetch();
            if (!$customer) {
                $errors[] = 'The selected customer does not have an active service plan.';
            }
        }

        if (!$errors) {
            $duplicate = $pdo->prepare(
                'SELECT id FROM billing WHERE customer_service_id = ? AND billing_period_start = ? AND billing_period_end = ?'
            );
            $duplicate->execute([$customer['customer_service_id'], $values['billing_period_start'], $values['billing_period_end']]);
            if ($duplicate->fetch()) {
                $errors[] = 'A billing record already exists for this customer and period.';
            }
        }

        if (!$errors) {
            do {
                $invoiceNumber = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $invoiceExists = $pdo->prepare('SELECT id FROM billing WHERE invoice_number = ?');
                $invoiceExists->execute([$invoiceNumber]);
            } while ($invoiceExists->fetch());

            $stmt = $pdo->prepare(
                'INSERT INTO billing (invoice_number, customer_id, customer_service_id, billing_period_start, billing_period_end, due_date, amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $invoiceNumber,
                $customer['customer_id'],
                $customer['customer_service_id'],
                $values['billing_period_start'],
                $values['billing_period_end'],
                $values['due_date'],
                $customer['billing_amount'],
            ]);
            $invoiceId=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE billing b JOIN customer_services cs ON cs.id=b.customer_service_id JOIN services s ON s.id=cs.service_id LEFT JOIN internet_plans ip ON ip.id=cs.plan_id SET b.service_name_snapshot=COALESCE(ip.name,s.service_name),b.account_number_snapshot=cs.service_account_number WHERE b.id=?')->execute([$invoiceId]);
            require_once __DIR__.'/../../includes/BillingAutomation.php';
            (new BillingAutomation($pdo))->queueInvoice($invoiceId);
            header('Location: invoice.php?id=' . $invoiceId . '&msg=' . urlencode('Billing record created.'));
            exit;
        }
    }
}

$pageTitle = 'Generate Billing Record';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<h3 class="mb-1">Generate Billing Record</h3><p class="text-muted mb-4">Create an invoice for a selected active customer service.</p>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card" style="max-width:720px;">
    <div class="card-body">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="customer_service_id">Customer Service</label>
                    <select class="form-select" id="customer_service_id" name="customer_service_id" required>
                        <option value="">-- Select active customer service --</option>
                        <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['customer_service_id'] ?>" <?= $values['customer_service_id'] == $customer['customer_service_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($customer['account_number'] . ' - ' . $customer['full_name'] . ' (' . $customer['service_name'] . ': PHP ' . number_format((float) $customer['billing_amount'], 2) . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">The invoice amount is based on the selected active service subscription.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="billing_period_start">Billing Period Start</label>
                    <input class="form-control" id="billing_period_start" type="date" name="billing_period_start" value="<?= htmlspecialchars($values['billing_period_start']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="billing_period_end">Billing Period End</label>
                    <input class="form-control" id="billing_period_end" type="date" name="billing_period_end" value="<?= htmlspecialchars($values['billing_period_end']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="due_date">Due Date</label>
                    <input class="form-control" id="due_date" type="date" name="due_date" value="<?= htmlspecialchars($values['due_date']) ?>" required>
                </div>
            </div>
            <div class="mt-4 action-buttons">
                <button class="btn btn-primary" type="submit"><i class="bi bi-file-earmark-plus"></i> Create Invoice</button>
                <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>