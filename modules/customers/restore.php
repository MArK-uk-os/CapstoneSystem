<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    header('Location: index.php?msg=' . urlencode('Invalid request.'));
    exit;
}

$pdo = getDbConnection();
$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT status FROM customers WHERE id = ?');
$stmt->execute([$id]);
$customerStatus = (string) ($stmt->fetchColumn() ?: '');
if ($customerStatus !== 'inactive') {
    header('Location: index.php?msg=' . urlencode('Customer is not archived.'));
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE customers SET status = 'active' WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE customer_services SET status = 'active' WHERE customer_id = ? AND status = 'inactive'")->execute([$id]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: index.php?msg=' . urlencode('Restore failed: ' . $exception->getMessage()));
    exit;
}

header('Location: index.php?msg=' . urlencode('Customer restored. Catch-up invoices may be generated for periods missed while archived.'));
exit;
