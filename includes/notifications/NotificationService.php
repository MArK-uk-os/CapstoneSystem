<?php

class NotificationService
{
    private PDO $pdo;
    private array $config;
    private string $deliveryError = '';
    private bool $deliveryRetryable = false;
    private bool $deliveryAmbiguous = false;
    private string $deliveryFailureClass = 'pre_send';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->config = require __DIR__ . '/../../config/notifications.php';
    }

    private function fail(string $message, bool $retryable = false, bool $ambiguous = false, string $failureClass = 'pre_send'): bool
    {
        $this->deliveryError = $message;
        $this->deliveryRetryable = $retryable;
        $this->deliveryAmbiguous = $ambiguous;
        $this->deliveryFailureClass = $failureClass;
        return false;
    }

    public static function curlFailureKind(int $errno): array
    {
        if (in_array($errno, [6, 7, 35], true)) return ['retryable' => true, 'ambiguous' => false];
        return ['retryable' => false, 'ambiguous' => true];
    }

    public static function httpFailureKind(int $statusCode): array
    {
        if ($statusCode === 429) return ['retryable' => true, 'ambiguous' => false];
        if ($statusCode === 408 || $statusCode >= 500) return ['retryable' => false, 'ambiguous' => true];
        return ['retryable' => false, 'ambiguous' => false];
    }

    private function curlFailure(int $errno, string $error, string $label): bool
    {
        $error = $error !== '' ? $error : 'network error';
        $kind = self::curlFailureKind($errno);
        if ($errno === 28) {
            $message = $label . ' timed out; delivery outcome unknown.';
        } elseif ($kind['ambiguous']) {
            $message = $label . ' failed after transmission may have started: ' . $error . '; delivery outcome unknown.';
        } else {
            $message = $label . ' connection failed: ' . $error;
        }
        return $this->fail($message, $kind['retryable'], $kind['ambiguous'], $kind['ambiguous'] ? 'post_send_unknown' : 'pre_send');
    }

    private function failHttp(int $statusCode, string $label): bool
    {
        $kind = self::httpFailureKind($statusCode);
        if ($kind['ambiguous']) return $this->fail($label . ' HTTP ' . $statusCode . '; delivery outcome unknown.', false, true, 'post_send_unknown');
        if ($kind['retryable']) return $this->fail($label . ' HTTP ' . $statusCode . '; transient failure.', true, false);
        return $this->fail($label . ' rejected the message (HTTP ' . $statusCode . ').', false, false, 'provider_rejected');
    }

    public function sendBillingNotification(array $billing, string $channel, bool $log = true): array
    {
        $message = sprintf(
            'Invoice %s for PHP %s is due on %s. Thank you, Cloud Interweb.',
            $billing['invoice_number'],
            number_format((float) $billing['amount'], 2),
            date('M j, Y', strtotime($billing['due_date']))
        );

        return $this->send($billing, $channel, 'billing', 'Billing Invoice ' . $billing['invoice_number'], $message, [], $log);
    }

    public function sendPaymentConfirmation(array $billing, array $payment, string $channel): array
    {
        $message = sprintf(
            'Payment of PHP %s for invoice %s has been verified. Receipt: %s. Thank you, Cloud Interweb.',
            number_format((float) $payment['amount_paid'], 2),
            $billing['invoice_number'],
            $payment['receipt_number']
        );

        if (isset($billing['outstanding_balance'])) {
            $balance = (float) $billing['outstanding_balance'];
            $message .= $balance > 0
                ? ' Remaining balance: PHP ' . number_format($balance, 2) . '.'
                : ' This invoice is fully paid.';
        }
        return $this->send($billing, $channel, 'payment_confirmation', 'Payment Confirmed - ' . $billing['invoice_number'], $message, $payment);
    }

    public function sendOverdueNotification(array $billing, int $overdueDays, string $channel, bool $log = true): array
    {
        $billing['amount'] = $billing['outstanding_balance'] ?? $billing['amount'];
        $message = sprintf(
            'Invoice %s for PHP %s is overdue by %d day(s). Please settle your balance promptly. Cloud Interweb.',
            $billing['invoice_number'],
            number_format((float) $billing['amount'], 2),
            $overdueDays
        );

        return $this->send($billing, $channel, 'overdue', 'Overdue Invoice - ' . $billing['invoice_number'], $message, [], $log);
    }

    public function sendReminder(array $billing, string $channel, string $kind): array
    {
        $amount = $kind === 'invoice' ? $billing['amount'] : ($billing['outstanding_balance'] ?? $billing['amount']);
        $heading = ['invoice'=>'New invoice','pre_due'=>'Upcoming payment reminder','due'=>'Payment due today','due_changed'=>'Invoice due date updated'][$kind] ?? 'Billing reminder';
        $message = sprintf('%s: invoice %s, PHP %s. Due date: %s. Cloud Interweb.', $heading, $billing['invoice_number'], number_format((float)$amount,2), date('M j, Y',strtotime($billing['due_date'])));
        $billing['amount']=$amount;
        return $this->send($billing,$channel,'billing',$heading . ' - ' . $billing['invoice_number'],$message);
    }

    private function send(array $billing, string $channel, string $type, string $subject, string $message, array $payment = [], bool $log = true): array
    {
        $this->deliveryError = '';
        $this->deliveryRetryable = false;
        $this->deliveryAmbiguous = false;
        $this->deliveryFailureClass = 'pre_send';
        if (!in_array($channel, ['sms', 'email', 'telegram'], true)) {
            return ['success' => false, 'message' => 'Invalid notification channel.', 'retryable' => false, 'ambiguous' => false, 'failure_class' => 'pre_send'];
        }

        try {
            if ($channel === 'telegram') {
                $lookup = $this->pdo->prepare('SELECT telegram_chat_id FROM customers WHERE id = ?');
                $lookup->execute([$billing['customer_id']]);
                $chatId = $lookup->fetchColumn();
                if (!$chatId) return ['success' => false, 'message' => 'Link a Telegram chat ID in Edit Customer first.', 'retryable' => false, 'ambiguous' => false, 'failure_class' => 'pre_send'];
                $sent = $this->sendTelegram((string) $chatId, $subject . "\n\n" . $message);
            } else {
            $sent = $channel === 'sms'
                ? $this->sendSms($billing['contact_number'] ?? '', $message)
                : $this->sendEmail($billing['email'] ?? '', $billing['full_name'], $subject, $message, $this->renderEmail($billing, $type, $message, $payment));
            }
        } catch (Throwable $exception) {
            $sent = false;
            if ($this->deliveryError === '') $this->fail('Unexpected provider error: ' . $exception->getMessage(), false, true, 'post_send_unknown');
        }

        if ($log) {
            $this->log($billing['customer_id'], $billing['id'], $type, $channel, $sent ? 'sent' : 'failed', $sent ? null : ($this->deliveryError ?: 'Notification failed.'));
        }
        $successMessage = $channel === 'sms'
            ? 'SMS accepted by the provider; handset delivery is not confirmed.'
            : ucfirst($channel) . ' notification sent.';
        $failureMessage = $this->deliveryAmbiguous
            ? 'Outcome unknown: ' . ($this->deliveryError ?: 'no provider response.') . ' Review delivery before retrying.'
            : ($this->deliveryError ?: ucfirst($channel) . ' notification could not be sent. Check recipient and notification settings.');
        return [
            'success' => $sent,
            'message' => $sent ? $successMessage : $failureMessage,
            'retryable' => $sent ? false : $this->deliveryRetryable,
            'ambiguous' => $sent ? false : $this->deliveryAmbiguous,
            'failure_class' => $sent ? null : $this->deliveryFailureClass,
        ];
    }

    private function sendSms(string $recipient, string $message): bool
    {
        if ($recipient === '') return $this->fail('No contact number on file.');
        if ($this->config['sms_provider'] === 'httpsms') return $this->sendHttpSms($recipient, $message);
        if ($this->config['sms_provider'] === 'infobip') {
            return $this->sendInfobipSms($recipient, $message);
        }
        if ($this->config['sms_provider'] !== 'semaphore') return $this->fail('SMS provider is not supported.');
        if ($this->config['semaphore_api_key'] === '' || !function_exists('curl_init')) {
            return $this->fail('SMS provider is not configured.');
        }

        $request = curl_init('https://api.semaphore.co/api/v4/messages');
        curl_setopt_array($request, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'apikey' => $this->config['semaphore_api_key'],
                'number' => $recipient,
                'message' => $message,
                'sendername' => $this->config['semaphore_sender_name'],
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($request);
        $statusCode = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($request);
        $error = curl_error($request);
        curl_close($request);
        if ($body === false) return $this->curlFailure($errno, $error, 'SMS provider');

        $response = json_decode($body ?: '', true);
        $accepted = $statusCode >= 200 && $statusCode < 300 && !empty($response[0]['message_id'])
            && in_array(strtolower($response[0]['status'] ?? ''), ['queued','pending','sent'], true);
        if ($accepted) return true;
        return $this->failHttp($statusCode, 'SMS provider');
    }

    private function sendTelegram(string $chatId, string $message): bool
    {
        if ($this->config['telegram_bot_token'] === '' || !function_exists('curl_init')) return $this->fail('Telegram is not configured.');
        $request = curl_init('https://api.telegram.org/bot' . $this->config['telegram_bot_token'] . '/sendMessage');
        curl_setopt_array($request, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $message]), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
        $body = curl_exec($request);
        $code = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($request);
        $error = curl_error($request);
        curl_close($request);
        if ($body === false) return $this->curlFailure($errno, $error, 'Telegram');
        $data = json_decode($body ?: '', true);
        if ($code === 200 && ($data['ok'] ?? false) === true) return true;
        return $this->failHttp($code, 'Telegram');
    }

    public static function normalizeSmsNumber(string $number): ?string
    {
        $number = preg_replace('/[\s()+-]/', '', trim($number));
        if (preg_match('/^09\d{9}$/', $number)) $number = '63' . substr($number, 1);
        return preg_match('/^[1-9]\d{7,14}$/', $number) ? '+' . $number : null;
    }

    public static function httpSmsAccepted(int $httpCode, array $response): bool
    {
        return $httpCode >= 200 && $httpCode < 300 && ($response['status'] ?? '') === 'success'
            && !empty($response['data']['id'])
            && !in_array(strtolower($response['data']['status'] ?? ''), ['failed', 'expired', 'cancelled'], true);
    }

    private function sendHttpSms(string $recipient, string $message): bool
    {
        $to = self::normalizeSmsNumber($recipient);
        $from = self::normalizeSmsNumber($this->config['httpsms_from']);
        if (!$to) return $this->fail('httpSMS requires a valid recipient number.');
        if (!$from || $this->config['httpsms_api_key'] === '' || !function_exists('curl_init')) {
            return $this->fail('httpSMS requires a valid sender number, an API key, and PHP cURL.');
        }
        $request = curl_init('https://api.httpsms.com/v1/messages/send');
        curl_setopt_array($request, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . $this->config['httpsms_api_key'], 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['from' => $from, 'to' => $to, 'content' => $message], JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($request);
        $code = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($request);
        $networkError = curl_error($request);
        curl_close($request);
        if ($body === false) return $this->curlFailure($errno, $networkError, 'httpSMS');
        $data = json_decode($body ?: '', true);
        $accepted = is_array($data) && self::httpSmsAccepted($code, $data);
        if ($accepted) return true;
        $details = [];
        if (is_array($data['data'] ?? null)) foreach ($data['data'] as $field => $errors) {
            if (is_array($errors)) foreach ($errors as $error) if (is_string($error)) $details[] = $field . ': ' . $error;
        }
        $reason = $networkError ?: ($data['message'] ?? 'Invalid response from httpSMS.');
        $message = 'httpSMS (HTTP ' . $code . '): ' . $reason . ($details ? ' ' . implode('; ', $details) : '');
        $message = str_replace($this->config['httpsms_api_key'], '[redacted]', $message);
        $kind = self::httpFailureKind($code);
        if ($kind['ambiguous']) $message .= ' Delivery outcome unknown.';
        elseif ($kind['retryable']) $message .= ' Transient failure.';
        return $this->fail($message, $kind['retryable'], $kind['ambiguous'], $kind['ambiguous'] ? 'post_send_unknown' : ($kind['retryable'] ? 'pre_send' : 'provider_rejected'));
    }

    private function sendInfobipSms(string $recipient, string $message): bool
    {
        $number = preg_replace('/[\s()+-]/', '', $recipient);
        if (preg_match('/^09\d{9}$/', $number)) $number = '63' . substr($number, 1);
        if (!preg_match('/^[1-9]\d{7,14}$/', $number)) return $this->fail('Infobip requires a valid recipient number.');
        if (!function_exists('curl_init')) return $this->fail('Infobip requires PHP cURL.');
        $baseUrl = rtrim($this->config['infobip_base_url'], '/');
        if (!preg_match('~^https://[a-z0-9-]+\.api\.infobip\.com$~i', $baseUrl)
            || $this->config['infobip_api_key'] === '' || $this->config['infobip_sender'] === '') return $this->fail('Infobip is not configured.');
        $request = curl_init($baseUrl . '/sms/3/messages');
        curl_setopt_array($request, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: App ' . $this->config['infobip_api_key'], 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['messages' => [[
                'sender' => $this->config['infobip_sender'],
                'destinations' => [['to' => $number]],
                'content' => ['text' => $message],
            ]]], JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($request);
        $statusCode = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($request);
        $error = curl_error($request);
        curl_close($request);
        if ($body === false) return $this->curlFailure($errno, $error, 'Infobip');
        if ($statusCode < 200 || $statusCode >= 300) return $this->failHttp($statusCode, 'Infobip');
        $response = json_decode($body, true);
        $messages = $response['messages'] ?? [];
        if (count($messages) !== 1) return $this->fail('Infobip returned an unexpected response.', false, true, 'post_send_unknown');
        if (!empty($messages[0]['messageId']) && in_array((int) ($messages[0]['status']['groupId'] ?? 0), [1, 3], true)) return true;
        return $this->fail('Infobip did not accept the message.', false, false, 'provider_rejected');
    }

    public function renderEmail(array $billing, string $type, string $message, array $payment = []): string
    {
        $escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $confirmed = $type === 'payment_confirmation';
        $overdue = $type === 'overdue';
        $accent = $confirmed ? '#15803d' : ($overdue ? '#b91c1c' : '#2563eb');
        $title = $confirmed ? 'Payment received' : ($overdue ? 'Payment reminder' : 'Your billing invoice');
        $label = $confirmed ? 'AMOUNT RECEIVED' : 'INVOICE AMOUNT';
        $amount = $escape(number_format((float) ($confirmed ? ($payment['amount_paid'] ?? 0) : $billing['amount']), 2));
        $name = $escape($billing['full_name']);
        $summary = $escape($message);
        $details = ['Invoice number' => $billing['invoice_number']];
        if (!$confirmed && (float)($billing['disconnection_charge']??0)>0) $details['Included disconnection charge'] = 'PHP '.number_format((float)$billing['disconnection_charge'],2);
        if (!empty($billing['account_number'])) $details['Account number'] = $billing['account_number'];
        if (!empty($billing['service_name'])) $details['Service plan'] = $billing['service_name'];
        if (!empty($billing['billing_period_start']) && !empty($billing['billing_period_end'])) {
            $details['Billing period'] = date('M j, Y', strtotime($billing['billing_period_start'])) . ' - ' . date('M j, Y', strtotime($billing['billing_period_end']));
        }
        if ($confirmed) {
            $details['Receipt number'] = $payment['receipt_number'] ?? '';
            if (!empty($payment['payment_date'])) $details['Payment date'] = date('M j, Y', strtotime($payment['payment_date']));
            if (!empty($payment['payment_method'])) $details['Payment method'] = $payment['payment_method'];
            if (isset($billing['outstanding_balance'])) $details['Remaining balance'] = 'PHP ' . number_format((float) $billing['outstanding_balance'], 2);
        } elseif (!empty($billing['due_date'])) {
            $details['Due date'] = date('M j, Y', strtotime($billing['due_date']));
        }
        $rows = '';
        foreach ($details as $key => $value) {
            $rows .= '<tr><td style="padding:13px 0;border-bottom:1px solid #e2e8f0;color:#64748b;">' . $escape($key) . '</td><td align="right" style="padding:13px 0;border-bottom:1px solid #e2e8f0;font-weight:bold;color:#0f172a;">' . $escape($value) . '</td></tr>';
        }
        $closing = $confirmed ? 'Thank you for your payment and for choosing Cloud Interweb.' : 'For payment assistance or questions about this invoice, please contact our billing team.';
        return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{$title}</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#334155;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9;"><tr><td align="center" style="padding:32px 12px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:12px;">
<tr><td style="padding:28px 32px;background-color:#0f172a;border-radius:12px 12px 0 0;"><div style="font-size:24px;font-weight:bold;color:#ffffff;">Cloud Interweb</div><div style="margin-top:6px;font-size:12px;color:#cbd5e1;">BILLING &amp; COLLECTION SERVICES</div></td></tr>
<tr><td style="padding:32px;"><div style="font-size:12px;font-weight:bold;color:{$accent};letter-spacing:1px;">CUSTOMER NOTIFICATION</div><h1 style="margin:10px 0 24px;font-size:28px;line-height:1.25;color:#0f172a;">{$title}</h1><p style="font-size:15px;line-height:1.7;">Hello {$name},</p><p style="font-size:15px;line-height:1.7;">{$summary}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0;background-color:#f8fafc;border-left:4px solid {$accent};"><tr><td style="padding:22px 24px;"><div style="font-size:11px;letter-spacing:1px;color:#64748b;font-weight:bold;">{$label}</div><div style="margin-top:8px;font-size:32px;font-weight:bold;color:{$accent};">PHP {$amount}</div></td></tr></table>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;">{$rows}</table><p style="margin:26px 0 0;font-size:14px;line-height:1.7;color:#64748b;">{$closing}</p></td></tr>
<tr><td style="padding:20px 32px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.7;color:#64748b;">Cloud Interweb Network and Data Solution<br>This email was generated by our Billing and Collection Management System.</td></tr>
</table></td></tr></table></body></html>
HTML;
    }

    private function sendEmail(string $recipient, string $name, string $subject, string $message, string $html): bool
    {
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if ($recipient === '') return $this->fail('No email address on file.');
        if ($this->config['smtp_host'] === '' || !is_file($autoload)) {
            return $this->fail('Email is not configured.');
        }

        require_once $autoload;
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return $this->fail('Email library is unavailable.');
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;
        $mail->isSMTP();
        $mail->Host = $this->config['smtp_host'];
        $mail->Port = $this->config['smtp_port'];
        $mail->SMTPAuth = $this->config['smtp_username'] !== '';
        $mail->Username = $this->config['smtp_username'];
        $mail->Password = $this->config['smtp_password'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom($this->config['smtp_from_email'] ?: $this->config['smtp_username'], $this->config['smtp_from_name']);
        $mail->addAddress($recipient, $name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $message;

        try {
            return $mail->send();
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $lower = strtolower($error);
            if (str_contains($lower, 'invalid address') || str_contains($lower, 'could not authenticate')
                || str_contains($lower, 'username and password not accepted') || str_contains($lower, 'data not accepted')
                || str_contains($lower, 'recipient address rejected') || str_contains($lower, 'message body empty')) {
                return $this->fail('Email: ' . $error, false, false);
            }
            if (str_contains($lower, 'connect() failed') || str_contains($lower, 'could not connect')
                || str_contains($lower, 'connection refused') || str_contains($lower, 'network is unreachable')) {
                return $this->fail('Email SMTP connection failed: ' . $error, true, false);
            }
            if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
                return $this->fail('Email SMTP timed out; delivery outcome unknown.', false, true, 'post_send_unknown');
            }
            return $this->fail('Email sending failed: ' . $error, false, true, 'post_send_unknown');
        }
    }

    private function log(int $customerId, int $billingId, string $type, string $channel, string $status, ?string $error = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (customer_id, billing_id, type, channel, status, error_message) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$customerId, $billingId, $type, $channel, $status, $error === null ? null : substr($error, 0, 255)]);
    }
}
