<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'staff');

$pdo = getDbConnection();
$invoices = $pdo->query("SELECT b.id, b.invoice_number, b.amount, c.account_number, c.full_name FROM billing b JOIN customers c ON c.id = b.customer_id WHERE b.status IN ('unpaid', 'overdue') ORDER BY b.due_date ASC")->fetchAll();
$errors = [];
$values = ['billing_id' => '', 'payment_date' => date('Y-m-d'), 'amount_paid' => '', 'payment_method' => 'Cash', 'reference_number' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        foreach ($values as $key => $_) $values[$key] = trim($_POST[$key] ?? '');
        if (!ctype_digit($values['billing_id'])) $errors[] = 'Please select an invoice.';
        $paymentDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['payment_date']);
        if (!$paymentDate || $paymentDate->format('Y-m-d') !== $values['payment_date']) $errors[] = 'Enter a valid payment date.';
        if (!is_numeric($values['amount_paid']) || (float) $values['amount_paid'] <= 0) $errors[] = 'Enter a valid payment amount.';
        if (!in_array($values['payment_method'], ['Cash', 'GCash', 'Maya', 'Bank Transfer', 'Other'], true)) $errors[] = 'Select a valid payment method.';
        $isCash = $values['payment_method'] === 'Cash';
        if ($isCash) $values['reference_number'] = '';
        else {
            if ($values['reference_number'] === '') $errors[] = 'Transaction reference number is required for non-cash payments.';
            if (strlen($values['reference_number']) > 50) $errors[] = 'Reference number must not exceed 50 characters.';
            if (!isset($_FILES['proof_of_payment']) || $_FILES['proof_of_payment']['error'] === UPLOAD_ERR_NO_FILE) $errors[] = 'Upload proof of payment for non-cash payments.';
        }

        if (!$errors) {
            $invoice = $pdo->prepare("SELECT id, amount FROM billing WHERE id = ? AND status IN ('unpaid', 'overdue')");
            $invoice->execute([$values['billing_id']]);
            $invoice = $invoice->fetch();
            if (!$invoice) $errors[] = 'The selected invoice is no longer available for payment.';
            else { $paid=$pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE billing_id=? AND verification_status='verified'"); $paid->execute([$invoice['id']]); if(round((float)$values['amount_paid']+(float)$paid->fetchColumn(),2)>(float)$invoice['amount']) $errors[]='Payment exceeds the remaining invoice balance.'; }
        }

        $proofPath = null;
        if (!$errors && !$isCash && isset($_FILES['proof_of_payment']) && $_FILES['proof_of_payment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['proof_of_payment'];
            $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
            $mimeType = $file['error'] === UPLOAD_ERR_OK && function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : false;
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024 || !isset($allowedTypes[$mimeType])) {
                $errors[] = 'Proof must be a JPG, PNG, or PDF file no larger than 5 MB.';
            } else {
                $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
                $proofPath = 'uploads/proofs/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../../' . $proofPath)) {
                    $errors[] = 'Unable to save the proof of payment.';
                }
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO payments (billing_id, payment_date, amount_paid, payment_method, reference_number, proof_of_payment) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$invoice['id'], $values['payment_date'], $values['amount_paid'], $values['payment_method'], $values['reference_number'] ?: null, $proofPath]);
            header('Location: index.php?msg=' . urlencode($isCash ? 'Cash payment recorded. Confirm cash received to issue the receipt and notify the customer.' : 'Payment recorded. Verify the transaction reference and proof before issuing the receipt.'));
            exit;
        }
    }
}

$pageTitle = 'Record Payment';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<h3 class="mb-1">Record Payment</h3><p class="text-muted mb-4">Submit a customer payment for verification and invoice reconciliation.</p>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card" style="max-width:720px;"><div class="card-body"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><div class="row g-3"><div class="col-12"><label class="form-label" for="billing_id">Invoice</label><select class="form-select" id="billing_id" name="billing_id" required><option value="">-- Select unpaid invoice --</option><?php foreach ($invoices as $invoice): ?><option value="<?= $invoice['id'] ?>" <?= $values['billing_id'] == $invoice['id'] ? 'selected' : '' ?>><?= htmlspecialchars($invoice['invoice_number'] . ' - ' . $invoice['account_number'] . ' - ' . $invoice['full_name'] . ' (PHP ' . number_format((float) $invoice['amount'], 2) . ')') ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label" for="payment_date">Payment Date</label><input class="form-control" id="payment_date" type="date" name="payment_date" value="<?= htmlspecialchars($values['payment_date']) ?>" required></div><div class="col-md-6"><label class="form-label" for="amount_paid">Amount Paid</label><input class="form-control" id="amount_paid" type="number" min="0.01" step="0.01" name="amount_paid" value="<?= htmlspecialchars($values['amount_paid']) ?>" required></div><div class="col-md-6"><label class="form-label" for="payment_method">Payment Method</label><select class="form-select" id="payment_method" name="payment_method"><option>Cash</option><option <?= $values['payment_method'] === 'GCash' ? 'selected' : '' ?>>GCash</option><option <?= $values['payment_method'] === 'Maya' ? 'selected' : '' ?>>Maya</option><option <?= $values['payment_method'] === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option><option <?= $values['payment_method'] === 'Other' ? 'selected' : '' ?>>Other</option></select><div class="form-text" id="payment_help"></div></div><div class="col-md-6" id="reference_fields"><label class="form-label" for="reference_number">Transaction Reference Number</label><input class="form-control" id="reference_number" name="reference_number" maxlength="50" value="<?= htmlspecialchars($values['reference_number']) ?>"></div><div class="col-12" id="proof_fields"><label class="form-label" for="proof_of_payment">Proof of Payment</label><input class="form-control" id="proof_of_payment" type="file" name="proof_of_payment" accept="image/jpeg,image/png,application/pdf"><div class="form-text">Required for non-cash payments. Upload a transaction screenshot or deposit slip: JPG, PNG, or PDF, up to 5 MB.</div></div></div><div class="mt-4 action-buttons"><button class="btn btn-primary" type="submit">Record Payment</button><a class="btn btn-outline-secondary" href="index.php">Cancel</a></div></form></div></div>
<script>
(() => {
    const method = document.getElementById('payment_method');
    const reference = document.getElementById('reference_number');
    const proof = document.getElementById('proof_of_payment');
    function updatePaymentFields() {
        const cash = method.value === 'Cash';
        document.getElementById('reference_fields').hidden = cash;
        document.getElementById('proof_fields').hidden = cash;
        reference.disabled = proof.disabled = cash;
        reference.required = proof.required = !cash;
        document.getElementById('payment_help').textContent = cash
            ? 'No reference number or proof is needed. Staff confirms cash received before a receipt is issued.'
            : 'Use the transaction reference from the payment provider. Staff checks it against the proof and received funds.';
    }
    method.addEventListener('change', updatePaymentFields);
    method.addEventListener('input', updatePaymentFields);
    document.addEventListener('DOMContentLoaded', () => {
        // Chosen triggers jQuery events rather than native DOM change events.
        if (window.jQuery) window.jQuery(method).on('change.paymentFields', updatePaymentFields);
        updatePaymentFields();
    });
    window.addEventListener('pageshow', updatePaymentFields);
    updatePaymentFields();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
