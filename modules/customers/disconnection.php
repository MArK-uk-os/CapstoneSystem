<?php
require_once __DIR__.'/../../includes/auth.php';requireRole('admin','staff');
require_once __DIR__.'/../../includes/DisconnectionService.php';
$pdo=getDbConnection();$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$q=$pdo->prepare('SELECT cs.*,c.full_name,c.address,COALESCE(ip.name,s.service_name) plan_name FROM customer_services cs JOIN customers c ON c.id=cs.customer_id JOIN services s ON s.id=cs.service_id LEFT JOIN internet_plans ip ON ip.id=cs.plan_id WHERE cs.id=?');$q->execute([$id]);$service=$q->fetch();
if(!$service){http_response_code(404);exit('Service account not found.');}
$engine=new DisconnectionService($pdo);$settings=$pdo->query('SELECT * FROM automation_settings WHERE id=1')->fetch();$error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!verifyCsrfToken($_POST['csrf_token']??''))throw new RuntimeException('Invalid request.');
        if(($_POST['action']??'')==='reconnect')$engine->reconnect($id,currentUser()['id']);
        elseif(($_POST['action']??'')==='disconnect')$engine->disconnect($id,trim($_POST['charge']??''),trim($_POST['field_worker']??''),trim($_POST['remarks']??''),currentUser()['id']);
        else throw new RuntimeException('Invalid action.');
        header('Location: view.php?id='.$service['customer_id'].'&msg='.urlencode('Service status updated. Any disconnection charge will appear on the next invoice.'));exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$eligible=$engine->eligibleInvoice($id);
$q=$pdo->prepare('SELECT d.*,b.invoice_number FROM service_disconnections d LEFT JOIN billing b ON b.id=d.billed_invoice_id WHERE d.customer_service_id=? ORDER BY d.id DESC');$q->execute([$id]);$history=$q->fetchAll();
$pageTitle='Service Disconnection';include __DIR__.'/../../includes/header.php';include __DIR__.'/../../includes/sidebar.php';
?>
<h3>Disconnection / Reconnection</h3><p><?= htmlspecialchars($service['full_name'].' - '.$service['service_account_number'].' - '.$service['plan_name']) ?><br><?= htmlspecialchars($service['address']??'') ?></p>
<p>Service status: <strong><?= htmlspecialchars(ucfirst($service['status'])) ?></strong>. Disconnection threshold: <?= (int)$settings['disconnection_days'] ?> days after due date.</p>
<?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if($service['status']==='disconnected'): ?>
<form method="post" class="card p-4"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><p>Confirm only after the field worker has restored the connection. Pending charges remain payable.</p><button class="btn btn-success" name="action" value="reconnect">Confirm Reconnection</button></form>
<?php elseif($eligible && $service['status']==='active'): ?>
<form method="post" class="card p-4" style="max-width:700px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><p>Invoice <?= htmlspecialchars($eligible['invoice_number']) ?> is <?= (int)$eligible['overdue_days'] ?> days overdue. Unpaid balance: PHP <?= number_format((float)$eligible['outstanding'],2) ?>.</p>
<label>Disconnection charge (PHP)</label><input class="form-control mb-3" name="charge" type="number" min="0" max="99999999.99" step="0.01" value="<?= htmlspecialchars($_POST['charge']??$settings['disconnection_fee']) ?>" required>
<label>Field worker who performed the disconnection</label><input class="form-control mb-3" name="field_worker" maxlength="100" value="<?= htmlspecialchars($_POST['field_worker']??'') ?>" required>
<label>Remarks / area details</label><textarea class="form-control mb-3" name="remarks" maxlength="1000"><?= htmlspecialchars($_POST['remarks']??'') ?></textarea>
<p>Record only an actual field disconnection. The charge is added once to the next newly generated invoice. Scheduled plan billing continues while disconnected.</p><button class="btn btn-danger" name="action" value="disconnect">Confirm Disconnection</button></form>
<?php else: ?><div class="alert alert-info">This service is not eligible for disconnection. It must be active with an unpaid invoice at least <?= (int)$settings['disconnection_days'] ?> days past due.</div><?php endif; ?>
<h5 class="mt-4">Disconnection History</h5><div class="table-responsive"><table class="table"><thead><tr><th>Disconnected</th><th>Field worker</th><th>Charge</th><th>Billed on</th><th>Reconnected</th><th>Remarks</th></tr></thead><tbody><?php foreach($history as $event): ?><tr><td><?= htmlspecialchars($event['disconnected_at']) ?></td><td><?= htmlspecialchars($event['field_worker']) ?></td><td>PHP <?= number_format((float)$event['charge'],2) ?></td><td><?= htmlspecialchars($event['invoice_number']??'Pending next invoice') ?></td><td><?= htmlspecialchars($event['reconnected_at']??'-') ?></td><td><?= htmlspecialchars($event['remarks']??'') ?></td></tr><?php endforeach; ?></tbody></table></div><a class="btn btn-outline-secondary" href="view.php?id=<?= $service['customer_id'] ?>">Back to Customer</a>
<?php include __DIR__.'/../../includes/footer.php'; ?>
