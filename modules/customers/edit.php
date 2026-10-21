<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/customer_validation.php';

$pdo = getDbConnection();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: index.php?msg=' . urlencode('Customer not found.'));
    exit;
}

$errors = [];
$values = [
    'first_name' => (string) ($customer['first_name'] ?? $customer['full_name']),
    'middle_initial' => (string) ($customer['middle_initial'] ?? ''),
    'last_name' => (string) ($customer['last_name'] ?? ''),
    'suffix' => (string) ($customer['suffix'] ?? ''),
    'contact_number' => (string) ($customer['contact_number'] ?? ''),
    'email' => (string) ($customer['email'] ?? ''),
    'address' => (string) ($customer['address'] ?? ''),
    'status' => (string) $customer['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        foreach (['first_name', 'middle_initial', 'last_name', 'suffix', 'contact_number', 'email', 'address', 'status'] as $key) {
            $values[$key] = trim($_POST[$key] ?? '');
        }

        $validated = validateCustomer($pdo, $values, ['exclude_id' => $id]);
        $errors = $validated['errors'];
        $values = $validated['values'] + [
            'address' => $values['address'],
            'status' => $values['status'],
        ];

        if (!$errors) {
            try {
                $update = $pdo->prepare(
                    'UPDATE customers SET full_name = ?, first_name = ?, middle_initial = ?, last_name = ?, suffix = ?, contact_number = ?, email = ?, address = ?, status = ? WHERE id = ?'
                );
                $update->execute([
                    $values['full_name'],
                    $values['first_name'],
                    $values['middle_initial'],
                    $values['last_name'],
                    $values['suffix'] !== '' ? $values['suffix'] : null,
                    $values['contact_number'] !== '' ? $values['contact_number'] : null,
                    $values['email'] !== '' ? $values['email'] : null,
                    $values['address'],
                    $values['status'],
                    $id,
                ]);
                header('Location: index.php?msg=' . urlencode('Customer updated successfully.'));
                exit;
            } catch (PDOException $exception) {
                $errors[] = customerWriteError($exception);
            }
        }
    }
}

$pageTitle = 'Edit Customer';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<h3 class="mb-1">Edit Customer</h3><p class="text-muted mb-4">Update customer contact details and account status.</p>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card" style="max-width:700px;">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-control" maxlength="50" pattern="[A-Za-z][A-Za-z '\-]{0,49}" title="Letters only. Spaces, hyphens, and apostrophes are allowed." value="<?= htmlspecialchars($values['first_name']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Middle Initial</label>
                    <input type="text" name="middle_initial" class="form-control" maxlength="2" pattern="[A-Za-z]\.?" title="One letter, with an optional period." value="<?= htmlspecialchars($values['middle_initial']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control" maxlength="50" pattern="[A-Za-z][A-Za-z '\-]{0,49}" title="Letters only. Spaces, hyphens, and apostrophes are allowed." value="<?= htmlspecialchars($values['last_name']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Suffix</label>
                    <select name="suffix" class="form-select">
                        <option value="" <?= $values['suffix'] === '' ? 'selected' : '' ?>>None</option>
                        <?php foreach (CUSTOMER_SUFFIXES as $s): if ($s === '') continue; ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $values['suffix'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s === 'Jr' || $s === 'Sr' ? $s . '.' : $s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control" inputmode="tel" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($values['contact_number']) ?>">
                    <div class="form-text">Philippine mobile number. Must be unique if provided.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($values['email']) ?>">
                    <div class="form-text">Must be unique if provided.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($values['address']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?= $values['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $values['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="mt-4 action-buttons">
                <button type="submit" class="btn btn-primary">Update Customer</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
