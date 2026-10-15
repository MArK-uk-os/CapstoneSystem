<?php
require_once __DIR__ . '/../../includes/auth.php';

if (isLoggedIn()) {
    header('Location: /modules/dashboard/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username and password are required.';
        } else {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND status = "active"');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['profile_photo'] = $user['profile_photo'] ?? null;
                header('Location: /modules/dashboard/index.php');
                exit;
            }

            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Cloud Interweb Billing System</title>
<link rel="stylesheet" href="/assets/template/assets/plugins/@mdi/font/css/materialdesignicons.min.css">
<link rel="stylesheet" href="/assets/template/css/app.css">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="content-wrapper d-flex align-items-center auth theme-one" style="min-height:100vh; background-image:url('/assets/template/assets/images/auth/login_1.jpg'); background-size:cover; background-position:center;">
    <div class="row w-100"><div class="col-lg-4 mx-auto"><div class="auto-form-wrapper">
            <div class="text-center mb-4"><span class="vendor-brand login-vendor-brand">Cloud<span>Interweb</span></span></div>
            <h4 class="card-title mb-2 text-center">Billing &amp; Collection System</h4>
            <p class="text-muted text-center small">Cloud Interweb Network and Data Solution</p>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <div class="form-group">
                    <label class="label">Username</label>
                    <div class="input-group"><input type="text" name="username" class="form-control" required autofocus><div class="input-group-append"><span class="input-group-text"><i class="mdi mdi-account"></i></span></div></div>
                </div>
                <div class="form-group">
                    <label class="label">Password</label>
                    <div class="input-group"><input type="password" name="password" class="form-control" required><div class="input-group-append"><span class="input-group-text"><i class="mdi mdi-lock"></i></span></div></div>
                </div>
                <button type="submit" class="btn btn-primary submit-btn btn-block">Log In</button>
            </form>
    </div></div></div>
</div>
</body>
</html>
