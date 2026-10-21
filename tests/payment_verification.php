<?php
date_default_timezone_set('Asia/Manila');
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/PaymentVerificationService.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
class FakePaymentNotifier extends NotificationService
{
    public array $calls=[];
    public function __construct() {}
    private function result(string $channel,string $kind):array
    {
        $this->calls[]=$kind.':'.$channel;
        return ['success'=>true,'message'=>'ok','retryable'=>false,'ambiguous'=>false];
    }
    public function sendReminder(array $billing,string $channel,string $kind):array { return $this->result($channel,$kind); }
    public function sendOverdueNotification(array $billing,int $overdueDays,string $channel,bool $log=true):array { return $this->result($channel,'overdue'); }
    public function sendPaymentConfirmation(array $billing,array $payment,string $channel):array { return $this->result($channel,'payment'); }
}
class CollisionPaymentVerificationService extends PaymentVerificationService
{
    public int $calls=0;
    protected function generateReceiptNumber(): string
    {
        $this->calls++;
        return $this->calls===1 ? 'RCT-COLLISION' : parent::generateReceiptNumber();
    }
}
$pdo=getDbConnection();
foreach(['customers','services','customer_services','billing','payments','collections','internet_plans','automation_settings','notification_queue','notifications','service_disconnections'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `test_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `test_$table` RENAME TO `$table`");
}
$pdo->exec("INSERT INTO customers (id,account_number,full_name,email,contact_number,status) VALUES (1,'CUST-PAY','Payment Test','pay@example.com','09170000000','active')");
$pdo->exec("INSERT INTO billing (id,invoice_number,customer_id,billing_period_start,billing_period_end,due_date,amount,status) VALUES (1,'INV-PAY-1',1,CURDATE(),CURDATE(),DATE_ADD(CURDATE(),INTERVAL 10 DAY),100,'unpaid')");
$pdo->exec("INSERT INTO billing (id,invoice_number,customer_id,billing_period_start,billing_period_end,due_date,amount,status) VALUES (2,'INV-PAY-2',1,CURDATE(),CURDATE(),DATE_ADD(CURDATE(),INTERVAL 10 DAY),100,'unpaid')");
$pdo->exec("INSERT INTO automation_settings (id,enabled,channels,pre_due_days,overdue_repeat_days,follow_up_days,delivery_window_start,delivery_window_end) VALUES (1,1,'email,sms',3,1,1,'09:00:00','17:00:00')");

function resetPaymentData(PDO $pdo):void
{
    $pdo->exec('DELETE FROM notification_queue');
    $pdo->exec('DELETE FROM notifications');
    $pdo->exec('DELETE FROM collections');
    $pdo->exec('DELETE FROM payments');
    $pdo->exec("UPDATE billing SET status='unpaid',due_date=DATE_ADD(CURDATE(),INTERVAL 10 DAY),amount=100");
    $pdo->exec("UPDATE automation_settings SET enabled=1,channels='email,sms',delivery_window_start='09:00:00',delivery_window_end='17:00:00' WHERE id=1");
}
function addPayment(PDO $pdo,int $id,int $billingId,float $amount,string $method='Cash',?string $reference=null,?string $proof=null,string $status='pending',?string $receipt=null):void
{
    $stmt=$pdo->prepare('INSERT INTO payments (id,billing_id,payment_date,amount_paid,payment_method,reference_number,proof_of_payment,verification_status,receipt_number) VALUES (?,?,CURDATE(),?,?,?,?,?,?)');
    $stmt->execute([$id,$billingId,$amount,$method,$reference,$proof,$status,$receipt]);
}
function addQueueJob(PDO $pdo,int $billingId,string $kind,string $channel,string $status='pending',?int $paymentId=null,?string $lease=null):int
{
    static $n=0;$n++;
    $due=$pdo->query("SELECT due_date FROM billing WHERE id=$billingId")->fetchColumn();
    $stmt=$pdo->prepare('INSERT INTO notification_queue (event_key,billing_id,payment_id,kind,channel,due_date,status,attempts,next_attempt_at,lease_expires_at) VALUES (?,?,?,?,?,?,?,0,DATE_SUB(NOW(),INTERVAL 1 MINUTE),?)');
    $stmt->execute(['payment-test-'.$n,$billingId,$paymentId,$kind,$channel,$due,$status,$lease]);
    return (int)$pdo->lastInsertId();
}
function queueRow(PDO $pdo,int $id):array { $stmt=$pdo->prepare('SELECT * FROM notification_queue WHERE id=?');$stmt->execute([$id]);return $stmt->fetch(); }
function paymentRow(PDO $pdo,int $id):array { $stmt=$pdo->prepare('SELECT * FROM payments WHERE id=?');$stmt->execute([$id]);return $stmt->fetch(); }

resetPaymentData($pdo);
addPayment($pdo,1,1,100);
$service=new PaymentVerificationService($pdo);
$message=$service->verify(1,1);
check(str_contains($message,'Receipt'),'Verification must return the receipt number.');
check(paymentRow($pdo,1)['verification_status']==='verified','Payment must become verified.');
check($pdo->query('SELECT status FROM billing WHERE id=1')->fetchColumn()==='paid','Full payment must mark the invoice paid.');
check((int)$pdo->query("SELECT COUNT(*) FROM notification_queue WHERE kind='payment'")->fetchColumn()===2,'One payment confirmation must be queued per configured channel.');
try {$service->verify(1,1);throw new LogicException('Repeat verification accepted.');}catch(RuntimeException $e){check(!($e instanceof LogicException),'Repeat verification must fail.');}
check((int)$pdo->query("SELECT COUNT(*) FROM notification_queue WHERE kind='payment'")->fetchColumn()===2,'Repeat verification must not enqueue another receipt.');

resetPaymentData($pdo);
addPayment($pdo,1,1,100);
$jobs=[
    addQueueJob($pdo,1,'invoice','email'),
    addQueueJob($pdo,1,'pre_due','email','failed'),
    addQueueJob($pdo,1,'due','sms'),
    addQueueJob($pdo,1,'overdue','sms'),
    addQueueJob($pdo,1,'due_changed','email'),
];
$paymentJob=addQueueJob($pdo,1,'payment','email','pending',1);
$processing=addQueueJob($pdo,1,'invoice','sms','processing',null,date('Y-m-d H:i:s',time()+120));
$service->verify(1,1);
foreach($jobs as $job) {
    $row=queueRow($pdo,$job);
    check($row['status']==='cancelled'&&$row['last_error']==='Invoice fully paid.','All pending and failed non-payment jobs must be cancelled.');
}
check(queueRow($pdo,$paymentJob)['status']==='pending','Payment confirmation jobs must survive queue cleanup.');
check(queueRow($pdo,$processing)['status']==='processing','In-flight jobs must not be force-cancelled.');

resetPaymentData($pdo);
addPayment($pdo,1,1,40);
$partial=addQueueJob($pdo,1,'invoice','email');
$service->verify(1,1);
check($pdo->query('SELECT status FROM billing WHERE id=1')->fetchColumn()==='unpaid','Partial payment must leave the invoice unpaid.');
check(queueRow($pdo,$partial)['status']==='pending','Partial payment must not cancel reminder jobs.');

resetPaymentData($pdo);
addPayment($pdo,1,1,10,'GCash','REF-DUP','proof.jpg');
addPayment($pdo,2,1,10,'GCash','REF-DUP','proof.jpg');
$service->verify(1,1);
try {$service->verify(2,1);throw new LogicException('Duplicate reference accepted.');}catch(RuntimeException $e){check(!($e instanceof LogicException),'Duplicate verified reference must fail.');}
check(paymentRow($pdo,2)['verification_status']==='pending','Duplicate reference payment must remain pending.');

resetPaymentData($pdo);
addPayment($pdo,1,1,10,'Cash',null,null,'verified','RCT-COLLISION');
addPayment($pdo,2,2,10);
$collision=new CollisionPaymentVerificationService($pdo);
$message=$collision->verify(2,1);
check($collision->calls>=2,'Receipt collision must retry.');
check(!str_contains($message,'RCT-COLLISION'),'Retried receipt must use a new number.');
check(paymentRow($pdo,2)['verification_status']==='verified','Payment must succeed after a receipt collision.');

resetPaymentData($pdo);
addPayment($pdo,1,1,1,'Cash',null,null,'verified','RCT-OUTSIDE');
$paymentJob=addQueueJob($pdo,1,'payment','email','pending',1);
$reminder=addQueueJob($pdo,1,'overdue','email');
$fake=new FakePaymentNotifier();
$engine=new BillingAutomation($pdo,$fake);
$outside=new DateTimeImmutable('2026-10-20T03:00:00+08:00');
$result=$engine->run(true,false,$outside);
check(!empty($result['waiting_for_delivery_window']),'Outside-window run must still report waiting for non-payment jobs.');
check(queueRow($pdo,$paymentJob)['status']==='sent','Payment jobs must send outside the delivery window.');
check(queueRow($pdo,$reminder)['status']==='pending','Non-payment jobs must wait outside the delivery window.');
check(in_array('payment:email',$fake->calls,true),'Payment job must reach the provider outside the window.');

resetPaymentData($pdo);
$pdo->exec('UPDATE automation_settings SET enabled=0 WHERE id=1');
addPayment($pdo,1,1,1,'Cash',null,null,'verified','RCT-DISABLED');
$paymentJob=addQueueJob($pdo,1,'payment','email','pending',1);
$reminder=addQueueJob($pdo,1,'invoice','email');
$fake->calls=[];
$inside=new DateTimeImmutable('2026-10-20T10:00:00+08:00');
$result=$engine->run(true,false,$inside);
check(($result['enabled']??true)===false,'Disabled automation must be reported.');
check(queueRow($pdo,$paymentJob)['status']==='sent','Payment jobs must send while automation is disabled.');
check(queueRow($pdo,$reminder)['status']==='pending','Non-payment jobs must not send while automation is disabled.');

resetPaymentData($pdo);
addPayment($pdo,1,1,100,'Cash',null,null,'verified','RCT-PAID');
$paidJob=addQueueJob($pdo,1,'invoice','email');
$fake->calls=[];
$engine->run(true,false,$inside);
$row=queueRow($pdo,$paidJob);
check($row['status']==='cancelled'&&$row['last_error']==='Invoice fully paid.','Paid invoice jobs must be cancelled with the paid reason.');
check($fake->calls===[],'Paid invoice jobs must not reach the provider.');

echo "PASS: payment verification, receipt idempotency, queue cleanup, duplicate references, receipt collisions, and transactional delivery. No messages sent.\n";
