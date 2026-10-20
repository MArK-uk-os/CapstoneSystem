<?php
date_default_timezone_set('Asia/Manila');
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/BillingAutomation.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
class FakeNotifier extends NotificationService
{
    public string $mode='success';
    public array $calls=[];
    public function __construct() {}
    private function result(string $channel,string $kind):array
    {
        $this->calls[]=$kind.':'.$channel;
        if($this->mode==='success')return ['success'=>true,'message'=>'ok','retryable'=>false,'ambiguous'=>false];
        if($this->mode==='retry')return ['success'=>false,'message'=>'Temporary provider failure.','retryable'=>true,'ambiguous'=>false];
        if($this->mode==='permanent')return ['success'=>false,'message'=>'Permanent provider failure.','retryable'=>false,'ambiguous'=>false];
        return ['success'=>false,'message'=>'Provider timeout.','retryable'=>false,'ambiguous'=>true];
    }
    public function sendReminder(array $billing,string $channel,string $kind):array { return $this->result($channel,$kind); }
    public function sendOverdueNotification(array $billing,int $overdueDays,string $channel):array { return $this->result($channel,'overdue'); }
    public function sendPaymentConfirmation(array $billing,array $payment,string $channel):array { return $this->result($channel,'payment'); }
}
$pdo=getDbConnection();
foreach(['customers','services','customer_services','billing','payments','collections','internet_plans','automation_settings','notification_queue','notifications','service_disconnections'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `test_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `test_$table` RENAME TO `$table`");
}
$pdo->exec("INSERT INTO customers (id,account_number,full_name,email,contact_number,status) VALUES (1,'CUST-TEST','Delivery Test','test@example.com','09170000000','active')");
$pdo->exec("INSERT INTO automation_settings (id,enabled,channels,pre_due_days,overdue_repeat_days,follow_up_days,send_time) VALUES (1,1,'email,sms',3,1,1,'15:20:00')");
$pdo->exec("INSERT INTO billing (id,invoice_number,customer_id,billing_period_start,billing_period_end,due_date,amount,status) VALUES (1,'INV-TEST-1',1,CURDATE(),CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),100,'unpaid')");
function addJob(PDO $pdo,string $kind,string $channel,string $status='pending',int $attempts=0,?string $next=null):int
{
    static $n=0; $n++;
    $due=$pdo->query('SELECT due_date FROM billing WHERE id=1')->fetchColumn();
    $next=$next??date('Y-m-d H:i:s',time()-60);
    $stmt=$pdo->prepare('INSERT INTO notification_queue(event_key,billing_id,kind,channel,due_date,status,attempts,next_attempt_at) VALUES (?,1,?,?,?,?,?,?)');
    $stmt->execute(['delivery-test-'.$n,$kind,$channel,$due,$status,$attempts,$next]);
    return (int)$pdo->lastInsertId();
}
function row(PDO $pdo,int $id):array
{
    $stmt=$pdo->prepare('SELECT * FROM notification_queue WHERE id=?');$stmt->execute([$id]);return $stmt->fetch();
}
function resetQueue(PDO $pdo):void {$pdo->exec('DELETE FROM notification_queue');}
$fake=new FakeNotifier();
$engine=new BillingAutomation($pdo,$fake);
$inside=new DateTimeImmutable('2026-10-20T10:00:00+08:00');
$outside=new DateTimeImmutable('2026-10-20T03:00:00+08:00');

resetQueue($pdo);$job=addJob($pdo,'invoice','email');$fake->calls=[];
$result=$engine->run(true,false,$outside);
check(!empty($result['waiting_for_delivery_window']),'Outside-window run must defer delivery');
$row=row($pdo,$job);
check($row['status']==='pending'&&(int)$row['attempts']===0,'Outside-window job must stay pending');
check(count($fake->calls)===0,'Outside-window run must not contact providers');

resetQueue($pdo);$job=addJob($pdo,'invoice','email');$fake->calls=[];$pdo->exec('UPDATE automation_settings SET last_scheduled_date=NULL WHERE id=1');
$result=$engine->runScheduled($outside);
check(!empty($result['waiting_for_delivery_window']),'Scheduled outside-window run must defer delivery');
check($pdo->query('SELECT last_scheduled_date FROM automation_settings WHERE id=1')->fetchColumn()===null,'Legacy daily marker must not gate delivery');
check(row($pdo,$job)['status']==='pending','Scheduled outside-window job must stay pending');
$result=$engine->runScheduled($inside);
check($result['sent']===1,'Scheduled in-window run must deliver');

resetQueue($pdo);$job=addJob($pdo,'invoice','email');$fake->calls=[];$result=$engine->run(true,false,$inside);
check($result['sent']===1,'Inside-window job must be sent');
$row=row($pdo,$job);
check($row['status']==='sent'&&(int)$row['attempts']===1,'Sent job state is incorrect');
check(count($fake->calls)===1,'Provider must be called once');
$engine->run(true,false,$inside);
check(count($fake->calls)===1,'Repeated run must not resend a sent job');

resetQueue($pdo);$email=addJob($pdo,'invoice','email');$telegram=addJob($pdo,'invoice','telegram');$fake->calls=[];
$engine->run(true,false,$inside);
check(row($pdo,$email)['status']==='sent','Enabled channel must be sent');
check(row($pdo,$telegram)['status']==='cancelled','Disabled channel must be cancelled');
check($fake->calls===['invoice:email'],'Disabled channel must not reach the provider');

resetQueue($pdo);$job=addJob($pdo,'invoice','email');$fake->calls=[];$fake->mode='retry';
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='pending'&&(int)$row['attempts']===1,'Retryable failure must stay pending');
$delta=strtotime($row['next_attempt_at'])-time();
check($delta>=240&&$delta<=360,'First retry must use a five-minute backoff');
$pdo->exec("UPDATE notification_queue SET next_attempt_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=$job");
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='pending'&&(int)$row['attempts']===2,'Second retryable failure must stay pending');
$delta=strtotime($row['next_attempt_at'])-time();
check($delta>=840&&$delta<=960,'Second retry must use a fifteen-minute backoff');
$pdo->exec("UPDATE notification_queue SET next_attempt_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=$job");
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='failed'&&(int)$row['attempts']===3,'Third failure must stop retrying');
check(str_contains((string)$row['last_error'],'Temporary provider failure'),'Retry failure reason must be recorded');

resetQueue($pdo);$job=addJob($pdo,'invoice','email');$fake->calls=[];$fake->mode='permanent';
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='failed'&&(int)$row['attempts']===1,'Permanent failure must not retry');
check((strtotime($row['next_attempt_at'])-time())<120,'Permanent failure must not schedule a retry');

resetQueue($pdo);$job=addJob($pdo,'invoice','email');$fake->calls=[];$fake->mode='ambiguous';
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='failed'&&(int)$row['attempts']===1,'Ambiguous failure must stop automatic retries');
check(str_contains((string)$row['last_error'],'Outcome unknown'),'Ambiguous failure must be marked for review');

resetQueue($pdo);$job=addJob($pdo,'invoice','email','processing',1,date('Y-m-d H:i:s',time()-1200));$fake->calls=[];$fake->mode='success';
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='failed','Stale processing job must not be reset to pending');
check(str_contains((string)$row['last_error'],'Outcome unknown'),'Stale processing job must record an unknown outcome');
check(count($fake->calls)===0,'Stale processing job must not be resent automatically');

resetQueue($pdo);$fake->mode='success';$fake->calls=[];
$pdo->exec("INSERT INTO payments (billing_id,payment_date,amount_paid,payment_method,verification_status) VALUES (1,CURDATE(),1,'Cash','verified')");
$paymentId=(int)$pdo->lastInsertId();
$engine->queuePayment(1,$paymentId);
$engine->run(true,false,$inside);
$paymentJobs=(int)$pdo->query("SELECT COUNT(*) FROM notification_queue WHERE kind='payment' AND status='sent'")->fetchColumn();
check($paymentJobs===2,'Verified payment confirmations must be delivered in-window');
check(in_array('payment:email',$fake->calls,true)&&in_array('payment:sms',$fake->calls,true),'Payment confirmations must use enabled channels');

echo "PASS: quiet hours, enabled channels, deduplication, retry backoff, permanent/ambiguous failures, stale recovery, and payment timing. No messages sent.\n";
