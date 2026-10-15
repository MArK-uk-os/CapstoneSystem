<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$config = require __DIR__ . '/../../config/notifications.php';
$automation = getDbConnection()->query('SELECT * FROM automation_settings WHERE id=1')->fetch();
$checks = [
    'SMS provider (' . $config['sms_provider'] . ')' => in_array($config['sms_provider'], ['semaphore', 'infobip', 'httpsms'], true),
    'SMS API key' => (match ($config['sms_provider']) { 'httpsms' => $config['httpsms_api_key'], 'infobip' => $config['infobip_api_key'], default => $config['semaphore_api_key'] }) !== '',
    'SMS sender' => (match ($config['sms_provider']) { 'httpsms' => $config['httpsms_from'], 'infobip' => $config['infobip_sender'], default => $config['semaphore_sender_name'] }) !== '',
    'PHP cURL extension' => function_exists('curl_init'),
    'SMTP host' => $config['smtp_host'] !== '',
    'SMTP sender address' => $config['smtp_from_email'] !== '' || $config['smtp_username'] !== '',
    'PHPMailer installed' => is_file(__DIR__ . '/../../vendor/autoload.php'),
    'Telegram bot token' => $config['telegram_bot_token'] !== '',
];
$pageTitle = 'Notification Settings';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<?php if($config['sms_provider']==='httpsms'): ?><div class="alert alert-info">httpSMS sends from your Android phone's SIM. Configure your API key and sending SIM number, keep the phone online, and enable SMS in Automation Settings when ready. API acceptance does not confirm delivery; check the httpSMS dashboard.</div><?php endif; ?>
<div class="row g-3 mb-4">
    <div class="col-md-6"><div class="card h-100"><div class="card-body"><h4>Automatic notifications</h4><p>Automation is <?= $automation['enabled'] ? 'enabled' : 'disabled' ?>. Channels: <?= htmlspecialchars(implode(', ', explode(',', $automation['channels']))) ?>.</p><ul><li>Daily send time: <?= htmlspecialchars(date('g:i A', strtotime($automation['send_time']))) ?> (Philippine time).</li><li>Pre-due reminder: <?= (int)$automation['pre_due_days'] ?> days before due (0 disables it).</li><li>Due-day reminder: once on the due date.</li><li>Overdue reminders: every <?= (int)$automation['overdue_repeat_days'] ?> days.</li><li>Failed sends: retried at a later daily run, up to 3 attempts.</li></ul><p class="text-muted">Invoices, payment confirmations, and due-date changes are sent at the next daily send time. The computer and MySQL must be running.</p><a class="btn btn-primary" href="automation.php">Configure reminders and channels</a></div></div></div>
    <div class="col-md-6"><div class="card h-100"><div class="card-body"><h4>Telegram chat lookup</h4><p>Ask the customer to start @CloudInterBot and send a message. Refresh the lookup, then save their chat ID in Edit Customer.</p><a class="btn btn-outline-primary" href="telegram.php">Open Telegram chat lookup</a></div></div></div>
</div>
<div class="d-flex justify-content-between align-items-center mb-4"><div><h3 class="mb-0">Notification Settings</h3><small class="text-muted">Check the readiness of email and SMS delivery providers.</small></div><a class="btn btn-outline-secondary" href="index.php">Back to Billing</a></div>
<div class="card" style="max-width:760px;"><div class="card-body"><p class="text-muted">Provider credentials are read from server environment variables or the private <code>config/notifications.local.php</code> file.</p><p><a href="automation.php">Billing automation settings</a> ? <a href="telegram.php">Telegram chat lookup</a></p><table class="table mb-0"><tbody><?php foreach ($checks as $label => $ready): ?><tr><td><?= htmlspecialchars($label) ?></td><td class="text-end"><span class="badge bg-<?= $ready ? 'success' : 'danger' ?>"><?= $ready ? 'Ready' : 'Not configured' ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
