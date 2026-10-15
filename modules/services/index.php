<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = getDbConnection();
$services = $pdo->query('SELECT * FROM services ORDER BY service_name')->fetchAll();

$pageTitle = 'Services';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><a class="btn btn-outline-primary mb-2" href="plans.php">Configure three internet plans</a><h3 class="mb-0">Service Plan Management</h3><small class="text-muted">Manage available services and their standard billing rates.</small></div>
    <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Service</a>
</div>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= htmlspecialchars($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Service Name</th>
                    <th>Monthly Fee</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['service_name']) ?></td>
                    <td>&#8369;<?= number_format((float) $s['monthly_fee'], 2) ?></td>
                    <td><?= htmlspecialchars($s['description']) ?></td>
                    <td>
                        <span class="badge bg-<?= $s['status'] === 'active' ? 'success' : 'secondary' ?>">
                            <?= htmlspecialchars($s['status']) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="edit.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                        <form action="delete.php" method="post" class="d-inline" onsubmit="return confirm('Delete this service?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$services): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No services found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
