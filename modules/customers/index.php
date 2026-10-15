<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = getDbConnection();
$search = trim($_GET['q'] ?? '');
$serviceId = $_GET['service_id'] ?? '';
$status = $_GET['status'] ?? '';
$services = $pdo->query('SELECT id, service_name FROM services ORDER BY service_name')->fetchAll();
$where = [];
$parameters = [];
if ($search !== '') {
    $where[] = '(c.full_name LIKE ? OR c.account_number LIKE ? OR c.contact_number LIKE ? OR c.email LIKE ?)';
    $like = "%$search%";
    array_push($parameters, $like, $like, $like, $like);
}
if (ctype_digit($serviceId)) {
    $where[] = 'EXISTS (SELECT 1 FROM customer_services cs2 WHERE cs2.customer_id = c.id AND cs2.service_id = ?)';
    $parameters[] = $serviceId;
}
if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'c.status = ?';
    $parameters[] = $status;
} else {
    $status = '';
}
$sql = 'SELECT c.*, GROUP_CONCAT(CONCAT(s.service_name, " (", cs.status, ")") ORDER BY s.service_name SEPARATOR ", ") AS service_names
        FROM customers c
        LEFT JOIN customer_services cs ON cs.customer_id = c.id
        LEFT JOIN services s ON s.id = cs.service_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' GROUP BY c.id ORDER BY c.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($parameters);
$customers = $stmt->fetchAll();

$pageTitle = 'Customers';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><h3 class="mb-0">Customer Management</h3><small class="text-muted">Manage customer accounts, service subscriptions, and account status.</small></div>
    <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Customer</a>
</div>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= htmlspecialchars($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Name, account #, contact, or email" value="<?= htmlspecialchars($search) ?>"></div>
    <div class="col-md-3"><select name="service_id" class="form-select"><option value="">All Services</option><?php foreach ($services as $service): ?><option value="<?= $service['id'] ?>" <?= $serviceId == $service['id'] ? 'selected' : '' ?>><?= htmlspecialchars($service['service_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><select name="status" class="form-select"><option value="">All Customer Statuses</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
    <div class="col-md-2 d-flex gap-2 action-buttons"><button class="btn btn-outline-secondary">Search</button><a class="btn btn-outline-secondary" href="index.php">Clear</a></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Account #</th>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Services</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['account_number']) ?></td>
                    <td><?= htmlspecialchars($c['full_name']) ?></td>
                    <td><?= htmlspecialchars($c['contact_number']) ?></td>
                    <td><?= htmlspecialchars($c['email']) ?></td>
                    <td><?= htmlspecialchars($c['service_names'] ?: '-') ?></td>
                    <td>
                        <span class="badge bg-<?= $c['status'] === 'active' ? 'success' : 'secondary' ?>">
                            <?= htmlspecialchars($c['status']) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                        <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                        <?php if ($c['status'] === 'active'): ?><form action="delete.php" method="post" class="d-inline" onsubmit="return confirm('Archive this customer and deactivate their services?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Archive</button>
                        </form><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$customers): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No customers found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
