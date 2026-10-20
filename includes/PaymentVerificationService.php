<?php
require_once __DIR__ . '/BillingAutomation.php';

class PaymentVerificationService
{
    public function __construct(private PDO $pdo, private ?BillingAutomation $automation = null) {}

    public function verify(int $paymentId, int $userId): string
    {
        return $this->process($paymentId, $userId, 'verified');
    }

    public function reject(int $paymentId, int $userId): string
    {
        return $this->process($paymentId, $userId, 'rejected');
    }

    private function process(int $paymentId, int $userId, string $action): string
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT p.*, b.id AS billing_id, b.invoice_number, b.amount AS invoice_amount, b.due_date, b.customer_id, c.full_name, c.contact_number, c.email FROM payments p JOIN billing b ON b.id = p.billing_id JOIN customers c ON c.id = b.customer_id WHERE p.id = ? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            if (!$payment || $payment['verification_status'] !== 'pending') {
                throw new RuntimeException('This payment has already been processed.');
            }

            $this->pdo->prepare('SELECT id FROM billing WHERE id = ? FOR UPDATE')->execute([$payment['billing_id']]);

            if ($action === 'rejected') {
                $update = $this->pdo->prepare("UPDATE payments SET verification_status = 'rejected', verified_by = ? WHERE id = ? AND verification_status = 'pending'");
                $update->execute([$userId, $paymentId]);
                if ($update->rowCount() !== 1) throw new RuntimeException('This payment has already been processed.');
                $this->pdo->commit();
                return 'Payment rejected.';
            }

            if ($payment['payment_method'] !== 'Cash' && (empty($payment['reference_number']) || empty($payment['proof_of_payment']))) {
                throw new RuntimeException('Non-cash payments require a transaction reference number and proof before verification.');
            }
            $this->assertUniqueReference($payment);

            $previous = $this->pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE billing_id = ? AND verification_status = 'verified'");
            $previous->execute([$payment['billing_id']]);
            if (round((float) $previous->fetchColumn() + (float) $payment['amount_paid'], 2) > (float) $payment['invoice_amount']) {
                throw new RuntimeException('Payment exceeds the remaining invoice balance.');
            }

            $receiptNumber = $this->assignReceipt($paymentId, $userId);
            $payment['receipt_number'] = $receiptNumber;
            $total = $this->pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE billing_id = ? AND verification_status = 'verified'");
            $total->execute([$payment['billing_id']]);
            $totalPaid = (float) $total->fetchColumn();
            $billingStatus = $totalPaid >= (float) $payment['invoice_amount'] ? 'paid' : ($payment['due_date'] < date('Y-m-d') ? 'overdue' : 'unpaid');
            $this->pdo->prepare('UPDATE billing SET status = ? WHERE id = ?')->execute([$billingStatus, $payment['billing_id']]);
            if ($billingStatus === 'paid') {
                $this->pdo->prepare("UPDATE collections SET collection_status = 'settled', outstanding_balance = 0 WHERE billing_id = ?")->execute([$payment['billing_id']]);
                $this->cancelPaidQueue((int) $payment['billing_id']);
            }
            $this->automation()->queuePayment((int) $payment['billing_id'], $paymentId);
            $this->pdo->commit();
            return 'Payment verified. Receipt ' . $receiptNumber . ' created. Customer confirmation queued for the next worker run.';
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function assertUniqueReference(array $payment): void
    {
        if ($payment['payment_method'] === 'Cash' || empty($payment['reference_number'])) return;
        $stmt = $this->pdo->prepare("SELECT id FROM payments WHERE billing_id = ? AND reference_number = ? AND id <> ? AND verification_status = 'verified' LIMIT 1");
        $stmt->execute([$payment['billing_id'], $payment['reference_number'], $payment['id']]);
        if ($stmt->fetchColumn()) throw new RuntimeException('A verified payment with this reference already exists.');
    }

    private function assignReceipt(int $paymentId, int $userId): string
    {
        $update = $this->pdo->prepare("UPDATE payments SET verification_status = 'verified', receipt_number = ?, verified_by = ? WHERE id = ? AND verification_status = 'pending'");
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $receiptNumber = $this->generateReceiptNumber();
            try {
                $update->execute([$receiptNumber, $userId, $paymentId]);
                if ($update->rowCount() !== 1) throw new RuntimeException('This payment has already been processed.');
                return $receiptNumber;
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000' && $attempt < 4) continue;
                throw $exception;
            }
        }
        throw new RuntimeException('Unable to allocate a unique receipt number. Please retry.');
    }

    protected function generateReceiptNumber(): string
    {
        return 'RCT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function cancelPaidQueue(int $billingId): void
    {
        $stmt = $this->pdo->prepare("UPDATE notification_queue SET status = 'cancelled', failure_class = NULL, last_error = 'Invoice fully paid.', next_attempt_at = NOW(), lease_expires_at = NULL WHERE billing_id = ? AND kind <> 'payment' AND status IN ('pending', 'failed')");
        $stmt->execute([$billingId]);
    }

    private function automation(): BillingAutomation
    {
        return $this->automation ??= new BillingAutomation($this->pdo);
    }
}
