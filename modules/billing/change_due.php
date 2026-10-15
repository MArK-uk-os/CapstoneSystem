<?php
require_once __DIR__.'/../../includes/auth.php';requireLogin();
require_once __DIR__.'/../../includes/BillingAutomation.php';
$pdo=getDbConnection();$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);$q=$pdo->prepare('SELECT * FROM billing WHERE id=?');$q->execute([$id]);$bill=$q->fetch();if(!$bill){http_response_code(404);exit('Invoice not found.');}$error='';
if($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrfToken($_POST['csrf_token']??null)) {
    $due=$_POST['due_date']??'';$date=DateTimeImmutable::createFromFormat('!Y-m-d',$due);
    if(!$date || $date->format('Y-m-d')!==$due || $due<$bill['billing_period_start'])$error='Enter a due date on or after the billing start date.';
    elseif($bill['status']==='paid')$error='A settled invoice cannot have its due date changed.';
    else {
        $pdo->beginTransaction();try {
            $q=$pdo->prepare('SELECT * FROM billing WHERE id=? FOR UPDATE');$q->execute([$id]);$current=$q->fetch();
            if($current['status']==='paid')throw new RuntimeException('Invoice is already settled.');
            if($current['due_date']!==$due){
                $pdo->prepare('UPDATE billing SET due_date=? WHERE id=?')->execute([$due,$id]);
                $pdo->prepare("UPDATE notification_queue SET status='cancelled' WHERE billing_id=? AND kind!='payment' AND status IN ('pending','failed')")->execute([$id]);
                (new BillingAutomation($pdo))->queueInvoice($id,'due_changed',bin2hex(random_bytes(4)));
            }
            $pdo->commit();(new BillingAutomation($pdo))->synchronize();
            header('Location: invoice.php?id='.$id.'&msg='.urlencode('Due date updated. Automatic notifications queued.'));exit;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
    }
}
$pageTitle='Change Due Date';include __DIR__.'/../../includes/header.php';include __DIR__.'/../../includes/sidebar.php';
?>
<h3>Change invoice due date</h3><p><?= htmlspecialchars($bill['invoice_number']) ?></p><?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post" class="card p-4" style="max-width:500px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input class="form-control mb-3" type="date" name="due_date" value="<?= htmlspecialchars($bill['due_date']) ?>" required><button class="btn btn-primary">Save and queue notification</button></form>
<?php include __DIR__.'/../../includes/footer.php'; ?>
