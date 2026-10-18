<?php
require_once __DIR__.'/../../includes/auth.php';requireRole('admin','staff');
require_once __DIR__.'/../../includes/DisconnectionService.php';
$pdo=getDbConnection();$engine=new DisconnectionService($pdo);
$services=$pdo->query("SELECT cs.*,c.full_name,c.address,COALESCE(ip.name,s.service_name) plan_name FROM customer_services cs JOIN customers c ON c.id=cs.customer_id JOIN services s ON s.id=cs.service_id LEFT JOIN internet_plans ip ON ip.id=cs.plan_id WHERE cs.status IN ('active','disconnected') ORDER BY c.full_name,cs.id")->fetchAll();
$days=(int)$pdo->query('SELECT disconnection_days FROM automation_settings WHERE id=1')->fetchColumn();
$pageTitle='Disconnection Monitoring';include __DIR__.'/../../includes/header.php';include __DIR__.'/../../includes/sidebar.php';
?>
<h3>Disconnection Monitoring</h3><p>Active services with unpaid invoices at least <?= $days ?> days past due, and services already disconnected. Record the field worker's actual action to update service status.</p>
<div class="card"><div class="table-responsive"><table class="table"><thead><tr><th>Customer / Area</th><th>Service Account / Plan</th><th>Status</th><th>Overdue Invoice</th><th>Unpaid Balance</th><th>Action</th></tr></thead><tbody>
<?php $count=0;foreach($services as $service): $eligible=$engine->eligibleInvoice((int)$service['id']);if(!$eligible && $service['status']!=='disconnected')continue;$count++; ?>
<tr><td><?= htmlspecialchars($service['full_name']) ?><br><small><?= htmlspecialchars($service['address']??'') ?></small></td><td><?= htmlspecialchars($service['service_account_number'].' / '.$service['plan_name']) ?></td><td><?= htmlspecialchars(ucfirst($service['status'])) ?></td><td><?= htmlspecialchars($eligible['invoice_number']??'-') ?></td><td>PHP <?= number_format((float)($eligible['outstanding']??0),2) ?></td><td><a class="btn btn-outline-primary" href="../customers/disconnection.php?id=<?= $service['id'] ?>"><?= $service['status']==='disconnected'?'Reconnection / History':'Record Disconnection' ?></a></td></tr>
<?php endforeach;if(!$count): ?><tr><td colspan="6">No services currently require disconnection follow-up.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
