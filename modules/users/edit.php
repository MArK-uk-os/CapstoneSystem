<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/user_profile.php';
requireRole('admin');

$pdo = getDbConnection();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: index.php?msg=' . urlencode('User not found.'));
    exit;
}

$errors = [];
$values = $user;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        foreach (['username', 'full_name', 'role', 'status'] as $key) {
            $values[$key] = trim($_POST[$key] ?? '');
        }
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($values['username'] === '') $errors[] = 'Username is required.';
        if ($values['full_name'] === '') $errors[] = 'Full name is required.';
        if (!in_array($values['role'], ['admin', 'staff'], true)) $errors[] = 'Invalid role.';
        if ($password !== '' && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== '' && $password !== $confirmPassword) $errors[] = 'Passwords do not match.';
        $photoUpload = uploadProfilePhoto($_FILES['profile_photo'] ?? []);
        if ($photoUpload['error']) $errors[] = $photoUpload['error'];
        if ((int) $id === (int) $_SESSION['user_id'] && $values['status'] !== 'active') {
            $errors[] = 'You cannot deactivate your own account.';
        }
        if ((int) $id === (int) $_SESSION['user_id'] && $values['role'] !== 'admin') {
            $errors[] = 'You cannot change your own role.';
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $check->execute([$values['username'], $id]);
            if ($check->fetch()) {
                $errors[] = 'Username already exists.';
            }
        }

        if (!$errors) {
            if ($password !== '') {
                $update = $pdo->prepare(
                    'UPDATE users SET username = ?, password = ?, full_name = ?, profile_photo = COALESCE(?, profile_photo), role = ?, status = ? WHERE id = ?'
                );
                $update->execute([
                    $values['username'],
                    password_hash($password, PASSWORD_BCRYPT),
                    $values['full_name'],
                    $photoUpload['path'],
                    $values['role'],
                    $values['status'],
                    $id,
                ]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE users SET username = ?, full_name = ?, profile_photo = COALESCE(?, profile_photo), role = ?, status = ? WHERE id = ?'
                );
                $update->execute([
                    $values['username'],
                    $values['full_name'],
                    $photoUpload['path'],
                    $values['role'],
                    $values['status'],
                    $id,
                ]);
            }
            header('Location: index.php?msg=' . urlencode('User account updated successfully.'));
            exit;
        }
    }
}

$pageTitle = 'Edit User';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<h3 class="mb-1">Edit User Account</h3><p class="text-muted mb-4">Update account details, assigned role, and access status.</p>

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
            <div class="mb-3"><label class="form-label" for="profile_photo">Profile Photo</label><?php if (!empty($user['profile_photo'])): ?><div class="mb-2"><img class="profile-photo-preview" src="<?= htmlspecialchars($user['profile_photo']) ?>" alt="Current profile photo"></div><?php endif; ?><input class="form-control" id="profile_photo" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><div class="form-text">Leave blank to keep the current photo. JPG, PNG, or WebP up to 2 MB.</div></div>
            <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" minlength="8" placeholder="Leave blank to keep current password">
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" minlength="8">
            </div>
            <div class="mb-3">
                <label class="form-label">Role</label>
                <select name="role" class="form-select" <?= (int) $id === (int) $_SESSION['user_id'] ? 'disabled' : '' ?>>
                    <option value="staff" <?= $values['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                    <option value="admin" <?= $values['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
                <?php if ((int) $id === (int) $_SESSION['user_id']): ?>
                <input type="hidden" name="role" value="<?= htmlspecialchars($values['role']) ?>">
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" <?= (int) $id === (int) $_SESSION['user_id'] ? 'disabled' : '' ?>>
                    <option value="active" <?= $values['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $values['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <?php if ((int) $id === (int) $_SESSION['user_id']): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($values['status']) ?>">
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary">Update User</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
