<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    header('Location: index.php?msg=' . urlencode('Invalid request.'));
    exit;
}

$pdo = getDbConnection();
$id = (int) ($_POST['id'] ?? 0);

if ($id === (int) $_SESSION['user_id']) {
    header('Location: index.php?msg=' . urlencode('You cannot delete your own account.'));
    exit;
}

$stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
$stmt->execute([$id]);

header('Location: index.php?msg=' . urlencode('User account deleted successfully.'));
exit;
