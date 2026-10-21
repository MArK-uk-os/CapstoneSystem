<?php
require_once __DIR__ . '/../../includes/auth.php'; requireRole('admin');
require_once __DIR__ . '/../../includes/BillingAutomation.php';
$pdo=getDbConnection(); $notice='';
if ($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrfToken($_POST['csrf_token']??null)) {
    $channels=array_values(array_intersect(['email','sms'],(array)($_POST['channels']??[])));
    $pre=filter_var($_POST['pre_due_days']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>30]]);
    $repeat=filter_var($_POST['overdue_repeat_days']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>60]]);
    $follow=filter_var($_POST['follow_up_days']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>60]]);
    $windowStart=substr(trim($_POST['delivery_window_start']??''),0,5);
    $windowEnd=substr(trim($_POST['delivery_window_end']??''),0,5);
    if (!$channels || $pre===false || $repeat===false || $follow===false || !BillingAutomation::isValidDeliveryTime($windowStart) || !BillingAutomation::isValidDeliveryTime($windowEnd)) $notice='Select channels, valid reminder intervals, and delivery times.';
    elseif (!BillingAutomation::isValidDeliveryWindow($windowStart,$windowEnd)) $notice='Start and end delivery times cannot be identical.';
    else {
        $pdo->prepare('UPDATE automation_settings SET enabled=?,channels=?,pre_due_days=?,overdue_repeat_days=?,follow_up_days=?,delivery_window_start=?,delivery_window_end=? WHERE id=1')->execute([isset($_POST['enabled'])?1:0,implode(',',$channels),$pre,$repeat,$follow,$windowStart.':00',$windowEnd.':00']);
        $notice='Automation settings saved.';
    }
}
$settings=$pdo->query('SELECT * FROM automation_settings WHERE id=1')->fetch();
$windowStartValue=$_SERVER['REQUEST_METHOD']==='POST'?substr(trim($_POST['delivery_window_start']??''),0,5):substr((string)($settings['delivery_window_start']??'08:00:00'),0,5);
$windowEndValue=$_SERVER['REQUEST_METHOD']==='POST'?substr(trim($_POST['delivery_window_end']??''),0,5):substr((string)($settings['delivery_window_end']??'20:00:00'),0,5);
$jobs=$pdo->query('SELECT q.*,b.invoice_number FROM notification_queue q JOIN billing b ON b.id=q.billing_id ORDER BY q.id DESC LIMIT 30')->fetchAll();
$pageTitle='Billing Automation'; include __DIR__.'/../../includes/header.php'; include __DIR__.'/../../includes/sidebar.php';
?>
<h3>Billing automation</h3><p>Automatically generate scheduled invoices, detect overdue balances, and send reminders. Plan changes apply to future invoices; existing invoices keep their original rates.</p>
<?php if($notice): ?><div class="alert alert-info"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<form method="post" class="card p-4" style="max-width:700px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
<label><input type="checkbox" name="enabled" <?= $settings['enabled']?'checked':'' ?>> Enable automation</label>
<?php $windowStartLabel=htmlspecialchars(substr((string)($settings['delivery_window_start']??'08:00:00'),0,5)); $windowEndLabel=htmlspecialchars(substr((string)($settings['delivery_window_end']??'20:00:00'),0,5)); ?>
<p class="text-muted mt-3">Notifications are delivered from <?= $windowStartLabel ?> to <?= $windowEndLabel ?> Philippine time. Eligible notices queued outside that window wait for the next window. Payment confirmations are transactional and use the next worker tick even outside the window. Pre-due and due reminders are generated once per billing period; overdue reminders wait for the configured repeat interval after the last sent overdue notice.</p>
<div class="my-3"><?php foreach(['email','sms'] as $channel): ?><label class="me-3"><input type="checkbox" name="channels[]" value="<?= $channel ?>" <?= in_array($channel,explode(',',$settings['channels']))?'checked':'' ?>> <?= ucfirst($channel) ?></label><?php endforeach; ?></div>
<label>Days before due date (0 disables pre-due reminders)</label><input class="form-control mb-3" type="number" min="0" max="30" name="pre_due_days" value="<?= $settings['pre_due_days'] ?>">
<label>Repeat overdue reminder every (days)</label><input class="form-control mb-3" type="number" min="1" max="60" name="overdue_repeat_days" value="<?= $settings['overdue_repeat_days'] ?>">
<label>First collection follow-up after (days)</label><input class="form-control mb-3" type="number" min="1" max="60" name="follow_up_days" value="<?= $settings['follow_up_days'] ?>">
<label>Delivery window start</label><input class="form-control mb-3" type="time" name="delivery_window_start" value="<?= htmlspecialchars($windowStartValue) ?>" required>
<label>Delivery window end</label><input class="form-control mb-3" type="time" name="delivery_window_end" value="<?= htmlspecialchars($windowEndValue) ?>" required>
<small class="text-muted d-block mb-3">End time is exclusive. Overnight windows such as 20:00 to 06:00 are supported.</small><button class="btn btn-primary">Save settings</button></form>
<h5 class="mt-4">Recent automatic notifications</h5><table class="table"><thead><tr><th>Invoice</th><th>Event</th><th>Channel</th><th>Status</th><th>Attempts</th><th>Failure class</th><th>Last error</th></tr></thead><tbody><?php foreach($jobs as $job): ?><tr><td><?= htmlspecialchars($job['invoice_number']) ?></td><td><?= htmlspecialchars($job['kind']) ?></td><td><?= htmlspecialchars($job['channel']) ?></td><td><?= htmlspecialchars($job['status']) ?></td><td><?= $job['attempts'] ?></td><td><?= htmlspecialchars($job['failure_class'] ?? '-') ?></td><td><small><?= htmlspecialchars(substr((string)($job['last_error'] ?? ''),0,120)) ?></small></td></tr><?php endforeach; ?></tbody></table>
<?php include __DIR__.'/../../includes/footer.php'; ?>
