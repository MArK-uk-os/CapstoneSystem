<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'staff');
require_once __DIR__ . '/../../includes/PaymentVerificationService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('Invalid request.'); }
$paymentId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';
if (!$paymentId || !in_array($action, ['verified', 'rejected'], true)) { http_response_code(400); exit('Invalid payment action.'); }

$pdo = getDbConnection();
try {
    $service = new PaymentVerificationService($pdo);
    $message = $action === 'verified'
        ? $service->verify((int) $paymentId, (int) currentUser()['id'])
        : $service->reject((int) $paymentId, (int) currentUser()['id']);
} catch (Throwable $exception) {
    header('Location: index.php?msg=' . urlencode($exception->getMessage())); exit;
}

header('Location: index.php?msg=' . urlencode($message));
exit;
