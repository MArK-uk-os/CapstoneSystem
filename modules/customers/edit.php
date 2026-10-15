<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

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
$values = $customer;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        foreach (['full_name', 'contact_number', 'email', 'address', 'status', 'telegram_chat_id'] as $key) {
            $values[$key] = trim($_POST[$key] ?? '');
        }

        if ($values['telegram_chat_id'] !== '' && !preg_match('/^[1-9][0-9]{0,19}$/', $values['telegram_chat_id'])) $errors[] = 'Enter a valid private Telegram chat ID.';
        if ($values['full_name'] === '') $errors[] = 'Full name is required.';
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is invalid.';
        }

        if (!$errors) {
            $update = $pdo->prepare(
                'UPDATE customers SET full_name = ?, contact_number = ?, email = ?, address = ?, status = ?, telegram_chat_id = ? WHERE id = ?'
            );
            $update->execute([
                $values['full_name'],
                $values['contact_number'],
                $values['email'],
                $values['address'],
                $values['status'],
                $values['telegram_chat_id'] ?: null,
                $id,
            ]);
            header('Location: index.php?msg=' . urlencode('Customer updated successfully.'));
            exit;
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
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($values['full_name']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($values['contact_number']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($values['email']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Telegram Chat ID</label><input class="form-control mb-2" name="telegram_chat_id" value="<?= htmlspecialchars($values['telegram_chat_id'] ?? '') ?>"><small>Customer must start @CloudInterBot. Use their own private chat ID.</small><br><label class="form-label">Address</label>
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
