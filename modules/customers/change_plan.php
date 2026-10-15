<?php
require_once __DIR__.'/../../includes/auth.php'; requireLogin();
require_once __DIR__.'/../../includes/BillingAutomation.php';
$pdo=getDbConnection(); $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$q=$pdo->prepare('SELECT cs.*,c.full_name FROM customer_services cs JOIN customers c ON c.id=cs.customer_id WHERE cs.id=?');$q->execute([$id]);$cs=$q->fetch();if(!$cs){http_response_code(404);exit('Service account not found.');}
$plans=$pdo->query('SELECT * FROM internet_plans WHERE enabled=1 ORDER BY id')->fetchAll();$error='';
if($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrfToken($_POST['csrf_token']??null)) {
    $planId=(int)($_POST['plan_id']??0);$date=$_POST['next_billing_date']??'';$frequency=$_POST['billing_frequency']??'';$due=filter_var($_POST['due_days']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>365]]);
    $validDate=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    $plan=null;foreach($plans as $p)if((int)$p['id']===$planId)$plan=$p;
    if(!$plan || !$validDate || $validDate->format('Y-m-d')!==$date || !in_array($frequency,['monthly','quarterly','annually'],true) || $due===false)$error='Select a configured plan and valid billing schedule.';
    else {
        $pdo->beginTransaction();try {
            $q=$pdo->prepare('SELECT * FROM customer_services WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();
            $pdo->prepare('INSERT INTO subscription_changes (customer_service_id,old_plan_id,new_plan_id,old_rate,new_rate,changed_by) VALUES (?,?,?,?,?,?)')->execute([$id,$old['plan_id'],$planId,$old['billing_amount'],$plan['monthly_rate'],currentUser()['id']]);
            $pdo->prepare('UPDATE customer_services SET plan_id=?,billing_amount=?,billing_frequency=?,next_billing_date=?,due_days=? WHERE id=?')->execute([$planId,$plan['monthly_rate'],$frequency,$date,$due,$id]);$pdo->commit();
            header('Location: view.php?id='.$cs['customer_id'].'&msg='.urlencode('Plan and future billing schedule updated. Existing invoices retain their rates.'));exit;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
$pageTitle='Change Plan';include __DIR__.'/../../includes/header.php';include __DIR__.'/../../includes/sidebar.php';
?>
<h3>Change plan and billing schedule</h3><p><?= htmlspecialchars($cs['full_name'].' · '.$cs['service_account_number']) ?></p><p>Changes affect future invoices. To change an existing invoice’s due date, use its Change Due Date action.</p><?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post" class="card p-4" style="max-width:700px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><label>Internet plan</label><select name="plan_id" class="form-select mb-3" required><option value="">Select plan</option><?php foreach($plans as $p): ?><option value="<?= $p['id'] ?>" <?= $cs['plan_id']==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name'].' — '.$p['speed_mbps'].' Mbps — PHP '.$p['monthly_rate'].'/month') ?></option><?php endforeach; ?></select>
<label>Frequency</label><select class="form-select mb-3" name="billing_frequency"><?php foreach(['monthly','quarterly','annually'] as $f): ?><option <?= $cs['billing_frequency']===$f?'selected':'' ?>><?= $f ?></option><?php endforeach; ?></select><label>Next billing date</label><input class="form-control mb-3" type="date" name="next_billing_date" value="<?= htmlspecialchars($cs['next_billing_date']??'') ?>" required><label>Days after billing before payment is due</label><input class="form-control mb-3" type="number" min="0" max="365" name="due_days" value="<?= $cs['due_days'] ?>"><button class="btn btn-primary">Save change</button></form>
<?php include __DIR__.'/../../includes/footer.php'; ?>
