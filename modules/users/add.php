<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_profile.php';
requireRole('admin');

$pdo = getDbConnection();
$errors = [];
$values = ['username' => '', 'full_name' => '', 'role' => 'staff', 'status' => 'active'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        foreach ($values as $key => $_) {
            $values[$key] = trim($_POST[$key] ?? '');
        }
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($values['username'] === '') $errors[] = 'Username is required.';
        if ($values['full_name'] === '') $errors[] = 'Full name is required.';
        if (!in_array($values['role'], ['admin', 'staff'], true)) $errors[] = 'Invalid role.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirmPassword) $errors[] = 'Passwords do not match.';

        $photoUpload = uploadProfilePhoto($_FILES['profile_photo'] ?? []);
        if ($photoUpload['error']) $errors[] = $photoUpload['error'];

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $check->execute([$values['username']]);
            if ($check->fetch()) {
                $errors[] = 'Username already exists.';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password, full_name, profile_photo, role, status) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $values['username'],
                password_hash($password, PASSWORD_BCRYPT),
                $values['full_name'],
                $photoUpload['path'],
                $values['role'],
                $values['status'],
            ]);
            header('Location: index.php?msg=' . urlencode('User account created successfully.'));
            exit;
        }
    }
}

$pageTitle = 'Add User';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<h3 class="mb-1">Add User Account</h3><p class="text-muted mb-4">Create a staff account and assign its system access role.</p>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card" style="max-width:600px;">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($values['username']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($values['full_name']) ?>" required>
            </div>
            <div class="mb-3"><label class="form-label" for="profile_photo">Profile Photo</label><input class="form-control" id="profile_photo" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><div class="form-text">Optional JPG, PNG, or WebP image up to 2 MB.</div></div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required minlength="8">
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="8">
            </div>
            <div class="mb-3">
                <label class="form-label">Role</label>
                <select name="role" class="form-select">
                    <option value="staff" <?= $values['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                    <option value="admin" <?= $values['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active" <?= $values['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $values['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Save User</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
