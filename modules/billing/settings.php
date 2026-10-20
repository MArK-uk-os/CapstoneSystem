<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$config = require __DIR__ . '/../../config/notifications.php';
$settingsNotice='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    $days=filter_var($_POST['disconnection_days']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>365]]);
    $fee=trim($_POST['disconnection_fee']??'');
    if(!verifyCsrfToken($_POST['csrf_token']??'') || $days===false || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$fee)) $settingsNotice='Enter a threshold from 1 to 365 days and a valid charge.';
    else {getDbConnection()->prepare('UPDATE automation_settings SET disconnection_days=?,disconnection_fee=? WHERE id=1')->execute([$days,$fee]);$settingsNotice='Disconnection settings saved.';}
}
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
<?php if($settingsNotice): ?><div class="alert alert-info"><?= htmlspecialchars($settingsNotice) ?></div><?php endif; ?>
<form method="post" class="card p-4 mb-4" style="max-width:760px"><h4>Disconnection Settings</h4><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><label>Unpaid days after due date before disconnection</label><input class="form-control mb-3" name="disconnection_days" type="number" min="1" max="365" value="<?= (int)$automation['disconnection_days'] ?>" required><label>Default disconnection charge (PHP)</label><input class="form-control mb-3" name="disconnection_fee" type="number" min="0" max="99999999.99" step="0.01" value="<?= htmlspecialchars($automation['disconnection_fee']) ?>" required><p>Staff records the actual field disconnection and can override this charge. It is added once to the next invoice.</p><div class="action-buttons"><button class="btn btn-primary">Save Disconnection Settings</button><a class="btn btn-outline-primary" href="../collections/disconnections.php">View Disconnection List</a></div></form>
<div class="row g-3 mb-4">
    <div class="col-md-6"><div class="card h-100"><div class="card-body"><h4>Automatic notifications</h4><p>Automation is <?= $automation['enabled'] ? 'enabled' : 'disabled' ?>. Channels: <?= htmlspecialchars(implode(', ', explode(',', $automation['channels']))) ?>.</p><ul><li>Pre-due reminder: <?= (int)$automation['pre_due_days'] ?> days before due (0 disables it).</li><li>Due-day reminder: once on the due date.</li><li>Overdue reminders: every <?= (int)$automation['overdue_repeat_days'] ?> days from the last sent overdue notice.</li><li>Failed sends: retried after 5 and 15 minutes, up to 3 attempts; permanent and post-send unknown failures require review without automatic retry.</li></ul><p class="text-muted">Notifications are delivered during <?= htmlspecialchars(substr((string)($automation['delivery_window_start'] ?? '08:00:00'),0,5)) ?>–<?= htmlspecialchars(substr((string)($automation['delivery_window_end'] ?? '20:00:00'),0,5)) ?> Philippine time; outside that window reminders wait. Verified payment confirmations are transactional and use the next worker tick even outside the window or while automation is disabled. The computer and MySQL must be running.</p><a class="btn btn-primary" href="automation.php">Configure reminders and channels</a></div></div></div>
    <div class="col-md-6"><div class="card h-100"><div class="card-body"><h4>Telegram chat lookup</h4><p>Ask the customer to start @CloudInterBot and send a message. Refresh the lookup, then save their chat ID in Edit Customer.</p><a class="btn btn-outline-primary" href="telegram.php">Open Telegram chat lookup</a></div></div></div>
</div>
<div class="d-flex justify-content-between align-items-center mb-4"><div><h3 class="mb-0">Notification Settings</h3><small class="text-muted">Check the readiness of email and SMS delivery providers.</small></div><a class="btn btn-outline-secondary" href="index.php">Back to Billing</a></div>
<div class="card" style="max-width:760px;"><div class="card-body"><p class="text-muted">Provider credentials are read from server environment variables or the private <code>config/notifications.local.php</code> file.</p><p><a href="automation.php">Billing automation settings</a> ? <a href="telegram.php">Telegram chat lookup</a></p><table class="table mb-0"><tbody><?php foreach ($checks as $label => $ready): ?><tr><td><?= htmlspecialchars($label) ?></td><td class="text-end"><span class="badge bg-<?= $ready ? 'success' : 'danger' ?>"><?= $ready ? 'Ready' : 'Not configured' ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
