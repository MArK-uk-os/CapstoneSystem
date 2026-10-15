<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'staff');
require_once __DIR__ . '/../../includes/notifications/NotificationService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('Invalid request.'); }
$paymentId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';
if (!$paymentId || !in_array($action, ['verified', 'rejected'], true)) { http_response_code(400); exit('Invalid payment action.'); }

$pdo = getDbConnection();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT p.*, b.id AS billing_id, b.invoice_number, b.amount AS invoice_amount, b.due_date, b.customer_id, c.full_name, c.contact_number, c.email FROM payments p JOIN billing b ON b.id = p.billing_id JOIN customers c ON c.id = b.customer_id WHERE p.id = ? FOR UPDATE');
    $stmt->execute([$paymentId]); $payment = $stmt->fetch();
    if (!$payment || $payment['verification_status'] !== 'pending') throw new RuntimeException('This payment has already been processed.');

    if ($action === 'rejected') {
        $pdo->prepare("UPDATE payments SET verification_status = 'rejected', verified_by = ? WHERE id = ?")->execute([currentUser()['id'], $paymentId]);
        $message = 'Payment rejected.';
    } else {
        if ($payment['payment_method'] !== 'Cash' && (empty($payment['reference_number']) || empty($payment['proof_of_payment']))) {
            throw new RuntimeException('Non-cash payments require a transaction reference number and proof before verification.');
        }
        $previous=$pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE billing_id=? AND verification_status='verified'");$previous->execute([$payment['billing_id']]);
        if (round((float)$previous->fetchColumn()+(float)$payment['amount_paid'],2)>(float)$payment['invoice_amount']) throw new RuntimeException('Payment exceeds the remaining invoice balance.');
        do { $receiptNumber = 'RCT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))); $check = $pdo->prepare('SELECT id FROM payments WHERE receipt_number = ?'); $check->execute([$receiptNumber]); } while ($check->fetch());
        $pdo->prepare("UPDATE payments SET verification_status = 'verified', receipt_number = ?, verified_by = ? WHERE id = ?")->execute([$receiptNumber, currentUser()['id'], $paymentId]);
        $payment['receipt_number'] = $receiptNumber;
        $total = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE billing_id = ? AND verification_status = 'verified'"); $total->execute([$payment['billing_id']]); $totalPaid = (float) $total->fetchColumn();
        $billingStatus = $totalPaid >= (float) $payment['invoice_amount'] ? 'paid' : ($payment['due_date'] < date('Y-m-d') ? 'overdue' : 'unpaid');
        $pdo->prepare('UPDATE billing SET status = ? WHERE id = ?')->execute([$billingStatus, $payment['billing_id']]);
        if ($billingStatus === 'paid') $pdo->prepare("UPDATE collections SET collection_status = 'settled', outstanding_balance = 0 WHERE billing_id = ?")->execute([$payment['billing_id']]);
        $message = 'Payment verified. Receipt ' . $receiptNumber . ' created.';
    }
    if($action==='verified') {
        require_once __DIR__.'/../../includes/BillingAutomation.php';
        (new BillingAutomation($pdo))->queuePayment((int)$payment['billing_id'],$paymentId);
        $message .= ' Customer confirmation queued for the next automatic notification check.';
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: index.php?msg=' . urlencode($exception->getMessage())); exit;
}

header('Location: index.php?msg=' . urlencode($message));
exit;
