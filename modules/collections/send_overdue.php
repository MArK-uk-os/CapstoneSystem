<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../../includes/notifications/ManualNotificationService.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('Invalid request.'); }
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT); $channel = $_POST['channel'] ?? '';
if (!$id || !in_array($channel, ['sms', 'email', 'telegram'], true)) { http_response_code(400); exit('Invalid notification request.'); }
$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT c.id AS collection_id, c.overdue_days, c.outstanding_balance, b.*, customer.full_name, customer.contact_number, customer.email FROM collections c JOIN billing b ON b.id = c.billing_id JOIN customers customer ON customer.id = c.customer_id WHERE c.id = ? AND c.collection_status != 'settled'"); $stmt->execute([$id]); $collection = $stmt->fetch();
if (!$collection) { header('Location: index.php?msg=' . urlencode('Collection record not found or already settled.')); exit; }
$result = (new ManualNotificationService($pdo))->sendOverdue($collection, (int) $collection['overdue_days'], $channel, trim($_POST['request_token'] ?? ''), (int) currentUser()['id']);
flash($result['alert'] ?? ManualNotificationService::alertFor($result), (string) $result['message']);
header('Location: index.php'); exit;