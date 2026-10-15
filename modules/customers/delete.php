<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    header('Location: index.php?msg=' . urlencode('Invalid request.'));
    exit;
}

$pdo = getDbConnection();
$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare("UPDATE customers SET status = 'inactive' WHERE id = ?");
$stmt->execute([$id]);
$pdo->prepare("UPDATE customer_services SET status = 'inactive' WHERE customer_id = ?")->execute([$id]);

header('Location: index.php?msg=' . urlencode('Customer archived and services deactivated.'));
exit;
