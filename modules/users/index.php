<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$pdo = getDbConnection();
$users = $pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();

$pageTitle = 'User Accounts';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><h3 class="mb-0">User Accounts</h3><small class="text-muted">Manage staff access, roles, and account availability.</small></div>
    <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add User</a>
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
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['full_name']) ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($u['role']) ?></span></td>
                    <td>
                        <span class="badge bg-<?= $u['status'] === 'active' ? 'success' : 'secondary' ?>">
                            <?= htmlspecialchars($u['status']) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="edit.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                        <?php if ((int) $u['id'] !== (int) $_SESSION['user_id']): ?>
                        <form action="delete.php" method="post" class="d-inline" onsubmit="return confirm('Delete this user account?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No user accounts found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
