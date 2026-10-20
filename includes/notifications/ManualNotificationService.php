<?php
require_once __DIR__ . '/NotificationService.php';

class ManualNotificationService
{
    private const COOLDOWN_SECONDS = 60;

    public function __construct(private PDO $pdo, private ?NotificationService $notifier = null) {}

    public function sendBilling(array $billing, string $channel, string $requestKey, int $userId): array
    {
        return $this->send('billing', $billing, $channel, $requestKey, $userId, fn(NotificationService $notifier): array => $notifier->sendBillingNotification($billing, $channel, false));
    }

    public function sendOverdue(array $billing, int $overdueDays, string $channel, string $requestKey, int $userId): array
    {
        return $this->send('overdue', $billing, $channel, $requestKey, $userId, fn(NotificationService $notifier): array => $notifier->sendOverdueNotification($billing, $overdueDays, $channel, false));
    }

    private function send(string $type, array $billing, string $channel, string $requestKey, int $userId, callable $dispatch): array
    {
        if (!in_array($channel, ['sms', 'email', 'telegram'], true)) {
            return $this->result(false, 'Invalid notification channel.', 'danger');
        }
        if (!preg_match('/^[a-f0-9]{32,64}$/', $requestKey)) {
            return $this->result(false, 'Invalid notification request. Reload the page and try again.', 'danger');
        }
        $billingId = (int) ($billing['id'] ?? 0);
        $customerId = (int) ($billing['customer_id'] ?? 0);
        if (!$billingId || !$customerId) {
            return $this->result(false, 'Billing record not found.', 'danger');
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM billing WHERE id=? FOR UPDATE');
            $lock->execute([$billingId]);
            if (!$lock->fetchColumn()) {
                $this->pdo->rollBack();
                return $this->result(false, 'Billing record not found.', 'danger');
            }
            $inFlight = $this->pdo->prepare("SELECT id FROM notification_queue WHERE billing_id=? AND channel=? AND status='processing' LIMIT 1");
            $inFlight->execute([$billingId, $channel]);
            if ($inFlight->fetchColumn()) {
                $this->pdo->rollBack();
                return $this->result(false, 'Notification currently in transit. Wait for the automatic send to finish before retrying.', 'warning', ['in_flight' => true]);
            }
            $cooldown = $this->pdo->prepare("SELECT id FROM notifications WHERE billing_id=? AND type=? AND channel=? AND status IN ('processing','sent','delivered') AND sent_at>DATE_SUB(NOW(),INTERVAL " . self::COOLDOWN_SECONDS . " SECOND) LIMIT 1");
            $cooldown->execute([$billingId, $type, $channel]);
            if ($cooldown->fetchColumn()) {
                $this->pdo->rollBack();
                return $this->result(false, 'A notification for this invoice and channel was sent within the last 60 seconds. Please wait before sending again.', 'warning', ['cooldown' => true]);
            }
            $insert = $this->pdo->prepare("INSERT INTO notifications (customer_id,billing_id,type,channel,status,request_key,requested_by) VALUES (?,?,?,?,'processing',?,?)");
            $insert->execute([$customerId, $billingId, $type, $channel, $requestKey, $userId ?: null]);
            $notificationId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ((string) $exception->getCode() === '23000') {
                return $this->result(false, 'This notification request was already processed. Reload the page before sending again.', 'warning', ['duplicate' => true]);
            }
            throw $exception;
        }

        $notifier = $this->notifier ?? new NotificationService($this->pdo);
        try {
            $result = $dispatch($notifier);
        } catch (Throwable $exception) {
            $result = ['success' => false, 'message' => 'Unexpected provider error: ' . $exception->getMessage(), 'retryable' => false, 'ambiguous' => true];
        }

        $status = !empty($result['success']) ? 'sent' : 'failed';
        $error = $status === 'sent' ? null : substr((string) ($result['message'] ?? 'Notification failed.'), 0, 255);
        $this->pdo->prepare('UPDATE notifications SET status=?,error_message=?,sent_at=NOW() WHERE id=?')->execute([$status, $error, $notificationId]);

        if (!empty($result['success']) || !empty($result['ambiguous'])) {
            $result['cancelled_queue'] = $this->cancelQueue($billingId, $channel, $result);
        }
        $result['alert'] = self::alertFor($result);
        return $result;
    }

    public static function alertFor(array $result): string
    {
        if (!empty($result['success'])) return 'success';
        if (!empty($result['ambiguous'])) return 'warning';
        if (!empty($result['duplicate']) || !empty($result['cooldown'])) return 'warning';
        return 'danger';
    }

    private function cancelQueue(int $billingId, string $channel, array $result): int
    {
        $message = !empty($result['success'])
            ? 'Cancelled by manual staff notification.'
            : 'Manual notification outcome unknown; automatic retry cancelled for review.';
        $stmt = $this->pdo->prepare("UPDATE notification_queue SET status='cancelled',last_error=? WHERE billing_id=? AND channel=? AND kind!='payment' AND status IN ('pending','failed')");
        $stmt->execute([$message, $billingId, $channel]);
        return $stmt->rowCount();
    }

    private function result(bool $success, string $message, string $alert, array $extra = []): array
    {
        return array_merge(['success' => $success, 'message' => $message, 'retryable' => false, 'ambiguous' => false, 'alert' => $alert], $extra);
    }
}
