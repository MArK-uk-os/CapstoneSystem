<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$customerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
if (!$customerId) { header('Location: index.php'); exit; }
$pdo = getDbConnection();
$customer = $pdo->prepare('SELECT id, full_name FROM customers WHERE id = ?'); $customer->execute([$customerId]); $customer = $customer->fetch();
if (!$customer) { header('Location: index.php'); exit; }
$services = $pdo->prepare('SELECT s.id, s.service_name, s.monthly_fee FROM services s WHERE s.status = "active" AND ? > 0 ORDER BY s.service_name'); $services->execute([$customerId]); $services = $services->fetchAll();
$plans = $pdo->query("SELECT * FROM internet_plans WHERE enabled=1 ORDER BY id")->fetchAll();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) $errors[] = 'Invalid request.';
    else {
        $serviceId = $_POST['service_id'] ?? ''; $amount = $_POST['billing_amount'] ?? ''; $frequency = $_POST['billing_frequency'] ?? ''; $startDate = $_POST['start_date'] ?? ''; $nextBillingDate = $_POST['next_billing_date'] ?? ''; $dueDays = $_POST['due_days'] ?? ''; $serviceStatus = $_POST['status'] ?? '';
        if (!ctype_digit($serviceId) || !is_numeric($amount) || (float) $amount < 0 || !in_array($frequency, ['monthly','quarterly','annually'], true) || !$startDate || !$nextBillingDate || !ctype_digit($dueDays) || !in_array($serviceStatus, ['active','inactive'], true)) $errors[] = 'Complete all service billing fields with valid values.';
        $planId = (int)($_POST['plan_id'] ?? 0);
        if ($planId) { $plan = $pdo->prepare('SELECT * FROM internet_plans WHERE id=? AND enabled=1'); $plan->execute([$planId]); $plan=$plan->fetch(); if (!$plan) $errors[]='Select an available internet plan.'; else $amount=$plan['monthly_rate']; }
        if (!$errors) {
            try { $pdo->prepare('INSERT INTO customer_services (customer_id, service_id, billing_amount, billing_frequency, start_date, next_billing_date, due_days, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$customerId, $serviceId, $amount, $frequency, $startDate, $nextBillingDate, $dueDays, $serviceStatus]); $subscriptionId=(int)$pdo->lastInsertId(); $pdo->prepare('UPDATE customer_services SET service_account_number=?,plan_id=? WHERE id=?')->execute(['ACC-'.str_pad((string)$subscriptionId,6,'0',STR_PAD_LEFT),$planId?:null,$subscriptionId]); header('Location: view.php?id=' . $customerId . '&msg=' . urlencode('Service added successfully.')); exit; }
            catch (PDOException $exception) { $errors[] = 'This service already belongs to this customer.'; }
        }
    }
}
$pageTitle = 'Add Customer Service'; include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/sidebar.php';
?>
<h3 class="mb-1">Add Service</h3><p class="text-muted mb-1">Add a service subscription under this customer's existing account.</p><p class="text-muted mb-4"><?= htmlspecialchars($customer['full_name']) ?></p>
<?php if ($errors): ?><div class="alert alert-danger"><?= htmlspecialchars($errors[0]) ?></div><?php endif; ?>
<div class="card" style="max-width:760px;"><div class="card-body"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><div class="row g-3"><div class="col-md-6"><label class="form-label">Service</label><select class="form-select" name="service_id"><option value="">-- Select service --</option><?php foreach ($services as $service): ?><option value="<?= $service['id'] ?>"><?= htmlspecialchars($service['service_name'] . ' (PHP ' . number_format((float) $service['monthly_fee'], 2) . ')') ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Internet plan (optional for other services)</label><select class="form-select" name="plan_id"><option value="">Other service / custom rate</option><?php foreach($plans as $plan): ?><option value="<?= $plan['id'] ?>"><?= htmlspecialchars($plan['name'].' ? '.$plan['speed_mbps'].' Mbps ? PHP '.$plan['monthly_rate']) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Monthly rate (plan selection overrides this)</label><input class="form-control" type="number" min="0" step="0.01" name="billing_amount" value="0" required></div><div class="col-md-4"><label class="form-label">Billing Frequency</label><select class="form-select" name="billing_frequency"><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="annually">Annually</option></select></div><div class="col-md-4"><label class="form-label">Start Date</label><input class="form-control" type="date" name="start_date" value="<?= date('Y-m-d') ?>"></div><div class="col-md-4"><label class="form-label">Next Billing Date</label><input class="form-control" type="date" name="next_billing_date" value="<?= date('Y-m-d') ?>"></div><div class="col-md-6"><label class="form-label">Due Date Rule</label><div class="input-group"><input class="form-control" type="number" min="0" name="due_days" value="14"><span class="input-group-text">days after billing</span></div></div><div class="col-md-6"><label class="form-label">Service Status</label><select class="form-select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div><div class="mt-4 action-buttons"><button class="btn btn-primary">Add Service</button><a class="btn btn-outline-secondary" href="view.php?id=<?= $customerId ?>">Cancel</a></div></form></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>