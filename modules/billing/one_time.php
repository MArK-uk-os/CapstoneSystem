<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'staff');
require_once __DIR__ . '/../../includes/one_time_services.php';

$pdo = getDbConnection();
$customers = $pdo->query('SELECT id, account_number, full_name FROM customers ORDER BY full_name, id')->fetchAll();
$errors = [];
$values = [
    'customer_id' => ctype_digit($_GET['customer_id'] ?? '') ? $_GET['customer_id'] : '',
    'service_type' => '',
    'service_date' => date('Y-m-d'),
    'due_date' => '',
    'amount' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        foreach ($values as $key => $_) {
            $values[$key] = trim($_POST[$key] ?? '');
        }

        if (!ctype_digit($values['customer_id'])) {
            $errors[] = 'Please select a customer.';
        } else {
            $lookup = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
            $lookup->execute([(int) $values['customer_id']]);
            if (!$lookup->fetch()) $errors[] = 'The selected customer no longer exists.';
        }
        if (!in_array($values['service_type'], ONE_TIME_SERVICES, true)) $errors[] = 'Select a valid one-time service type.';
        $serviceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['service_date']);
        if (!$serviceDate || $serviceDate->format('Y-m-d') !== $values['service_date']) $errors[] = 'Enter a valid service date.';
        if ($values['due_date'] === '' && $serviceDate) $values['due_date'] = $values['service_date'];
        $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['due_date']);
        if (!$dueDate || $dueDate->format('Y-m-d') !== $values['due_date']) $errors[] = 'Enter a valid due date.';
        if (!is_numeric($values['amount']) || (float) $values['amount'] <= 0) $errors[] = 'Enter a valid amount greater than zero.';

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $invoiceNumber = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $stmt = $pdo->prepare('INSERT INTO billing (invoice_number, customer_id, customer_service_id, billing_period_start, billing_period_end, due_date, amount, service_name_snapshot, status) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $invoiceNumber,
                    (int) $values['customer_id'],
                    $values['service_date'],
                    $values['service_date'],
                    $values['due_date'],
                    number_format((float) $values['amount'], 2, '.', ''),
                    $values['service_type'],
                    'unpaid',
                ]);
                $invoiceId = (int) $pdo->lastInsertId();
                require_once __DIR__ . '/../../includes/BillingAutomation.php';
                (new BillingAutomation($pdo))->queueInvoice($invoiceId);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Unable to record the one-time service. Please try again.';
            }
        }

        if (!$errors) {
            header('Location: /modules/customers/view.php?id=' . $values['customer_id'] . '&msg=' . urlencode($values['service_type'] . ' recorded as a one-time charge.'));
            exit;
        }
    }
}

$pageTitle = 'Record One-Time Service';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><h3 class="mb-0">Record One-Time Service</h3><small class="text-muted">Charge a one-time service (installation type) without creating a subscription or recurring billing.</small></div>
    <a class="btn btn-outline-secondary" href="/modules/customers/index.php">Back</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card mb-3" style="max-width:720px;">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Customer</label>
                    <select name="customer_id" class="form-control" required>
                        <option value="">-- Select customer --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['id'] ?>" <?= $values['customer_id'] == $customer['id'] ? 'selected' : '' ?>><?= htmlspecialchars($customer['full_name'] . ' (' . $customer['account_number'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">The charge will appear under this customer's billing history.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Service Type</label>
                    <select name="service_type" class="form-control" required>
                        <option value="">-- Select one-time service --</option>
                        <?php foreach (ONE_TIME_SERVICES as $service): ?>
                            <option value="<?= htmlspecialchars($service) ?>" <?= $values['service_type'] === $service ? 'selected' : '' ?>><?= htmlspecialchars($service) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Service Date</label>
                    <input type="date" name="service_date" id="service_date" class="form-control" value="<?= htmlspecialchars($values['service_date']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Due Date</label>
                    <input type="date" name="due_date" id="due_date" class="form-control" value="<?= htmlspecialchars($values['due_date']) ?>">
                    <div class="form-text">Defaults to the service date; you may change it.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Amount (&#8369;)</label>
                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" value="<?= htmlspecialchars($values['amount']) ?>" required>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Record Charge</button>
                <a class="btn btn-outline-secondary" href="/modules/customers/index.php">Cancel</a>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const serviceDate = document.getElementById('service_date');
    const dueDate = document.getElementById('due_date');
    let touched = dueDate.value !== '';
    dueDate.addEventListener('input', () => { touched = dueDate.value !== ''; });
    serviceDate.addEventListener('change', () => {
        if (!touched) dueDate.value = serviceDate.value;
    });
    if (!dueDate.value) dueDate.value = serviceDate.value;
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
