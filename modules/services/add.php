<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = getDbConnection();
$errors = [];
$values = ['service_name' => '', 'monthly_fee' => '', 'description' => '', 'status' => 'active'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        foreach ($values as $key => $_) {
            $values[$key] = trim($_POST[$key] ?? '');
        }

        if ($values['service_name'] === '') $errors[] = 'Service name is required.';
        if ($values['monthly_fee'] === '' || !is_numeric($values['monthly_fee']) || (float) $values['monthly_fee'] < 0) {
            $errors[] = 'Monthly fee must be a valid non-negative number.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO services (service_name, monthly_fee, description, status) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$values['service_name'], $values['monthly_fee'], $values['description'], $values['status']]);
            header('Location: index.php?msg=' . urlencode('Service added successfully.'));
            exit;
        }
    }
}

$pageTitle = 'Add Service';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<h3 class="mb-1">Add Service Plan</h3><p class="text-muted mb-4">Create a service offering and define its standard billing amount.</p>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card" style="max-width:600px;">
    <div class="card-body">
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <div class="mb-3">
                <label class="form-label">Service Name</label>
                <input type="text" name="service_name" class="form-control" value="<?= htmlspecialchars($values['service_name']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Monthly Fee (&#8369;)</label>
                <input type="number" step="0.01" min="0" name="monthly_fee" class="form-control" value="<?= htmlspecialchars($values['monthly_fee']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($values['description']) ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active" <?= $values['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $values['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Save Service</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
