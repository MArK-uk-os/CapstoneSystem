<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    header('Location: index.php?msg=' . urlencode('Invalid request.'));
    exit;
}

$pdo = getDbConnection();
$id = (int) ($_POST['id'] ?? 0);

$check = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE service_id = ?');
$check->execute([$id]);
$checkSubscriptions = $pdo->prepare('SELECT COUNT(*) FROM customer_services WHERE service_id = ?');
$checkSubscriptions->execute([$id]);

if ((int) $check->fetchColumn() > 0 || (int) $checkSubscriptions->fetchColumn() > 0) {
    header('Location: index.php?msg=' . urlencode('Cannot delete: service is assigned to existing customers.'));
    exit;
}

$stmt = $pdo->prepare('DELETE FROM services WHERE id = ?');
$stmt->execute([$id]);

header('Location: index.php?msg=' . urlencode('Service deleted successfully.'));
exit;
