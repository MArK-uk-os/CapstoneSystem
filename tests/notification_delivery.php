<?php
date_default_timezone_set('Asia/Manila');
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/BillingAutomation.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
class FakeNotifier extends NotificationService
{
    public string $mode='success';
    public array $calls=[];
    public int $throwOnCall=0;
    public function __construct() {}
    private function result(string $channel,string $kind):array
    {
        $this->calls[]=$kind.':'.$channel;
        if($this->throwOnCall>0&&count($this->calls)===$this->throwOnCall)throw new RuntimeException('Simulated worker failure.');
        if($this->mode==='success')return ['success'=>true,'message'=>'ok','retryable'=>false,'ambiguous'=>false];
        if($this->mode==='retry')return ['success'=>false,'message'=>'Temporary provider failure.','retryable'=>true,'ambiguous'=>false];
        if($this->mode==='permanent')return ['success'=>false,'message'=>'Permanent provider failure.','retryable'=>false,'ambiguous'=>false];
        return ['success'=>false,'message'=>'Provider timeout.','retryable'=>false,'ambiguous'=>true];
    }
    public function sendReminder(array $billing,string $channel,string $kind):array { return $this->result($channel,$kind); }
    public function sendOverdueNotification(array $billing,int $overdueDays,string $channel,bool $log=true):array { return $this->result($channel,'overdue'); }
    public function sendPaymentConfirmation(array $billing,array $payment,string $channel):array { return $this->result($channel,'payment'); }
}
$pdo=getDbConnection();
foreach(['customers','services','customer_services','billing','payments','collections','internet_plans','automation_settings','notification_queue','notifications','service_disconnections'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `test_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `test_$table` RENAME TO `$table`");
}
$pdo->exec("INSERT INTO customers (id,account_number,full_name,email,contact_number,status) VALUES (1,'CUST-TEST','Delivery Test','test@example.com','09170000000','active')");
$pdo->exec("INSERT INTO automation_settings (id,enabled,channels,pre_due_days,overdue_repeat_days,follow_up_days,send_time,delivery_window_start,delivery_window_end) VALUES (1,1,'email,sms',3,1,1,'15:20:00','09:00:00','17:00:00')");
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

foreach([52,56] as $errno){$kind=NotificationService::curlFailureKind($errno);check(!$kind['retryable']&&$kind['ambiguous'],'cURL '.$errno.' must be ambiguous and non-retryable');}
$kind=NotificationService::curlFailureKind(28);check(!$kind['retryable']&&$kind['ambiguous'],'cURL 28 must remain ambiguous');
foreach([6,7,35] as $errno){$kind=NotificationService::curlFailureKind($errno);check($kind['retryable']&&!$kind['ambiguous'],'cURL '.$errno.' must remain retryable');}
foreach([408,500,502,503,504] as $code){$kind=NotificationService::httpFailureKind($code);check(!$kind['retryable']&&$kind['ambiguous'],'HTTP '.$code.' must be ambiguous and non-retryable');}
$kind=NotificationService::httpFailureKind(429);check($kind['retryable']&&!$kind['ambiguous'],'HTTP 429 must remain retryable');
$kind=NotificationService::httpFailureKind(400);check(!$kind['retryable']&&!$kind['ambiguous'],'HTTP 400 must remain permanent');

$claim=new ReflectionMethod(BillingAutomation::class,'claimJob');$claim->setAccessible(true);
resetQueue($pdo);$job=addJob($pdo,'invoice','email');$pdo->exec("UPDATE notification_queue SET status='cancelled' WHERE id=$job");
check($claim->invoke($engine,$job)===null,'Cancelled job must not be claimed');
resetQueue($pdo);$job=addJob($pdo,'invoice','email');$lease=$claim->invoke($engine,$job);
check(is_string($lease)&&$lease!=='','Pending job must be claimed with a lease');
check($claim->invoke($engine,$job)===null,'Concurrently claimed job must not be claimed twice');
$row=row($pdo,$job);check($row['status']==='processing'&&(int)$row['attempts']===1&&$row['lease_expires_at']!==null,'Claim must set processing state and lease');
resetQueue($pdo);

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
check($row['failure_class']==='pre_send','Retryable failure must be classified as pre-send');
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
check($row['failure_class']==='pre_send','Exhausted retry must retain pre-send classification');
check(str_contains((string)$row['last_error'],'Temporary provider failure'),'Retry failure reason must be recorded');

resetQueue($pdo);$job=addJob($pdo,'invoice','email');$fake->calls=[];$fake->mode='permanent';
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='failed'&&(int)$row['attempts']===1,'Permanent failure must not retry');
check($row['failure_class']==='provider_rejected','Permanent failure must be classified as provider rejection');
check((strtotime($row['next_attempt_at'])-time())<120,'Permanent failure must not schedule a retry');

resetQueue($pdo);$job=addJob($pdo,'invoice','email');$fake->calls=[];$fake->mode='ambiguous';
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='failed'&&(int)$row['attempts']===1,'Ambiguous failure must stop automatic retries');
check($row['failure_class']==='post_send_unknown','Ambiguous failure must be classified as post-send unknown');
check(str_contains((string)$row['last_error'],'Outcome unknown'),'Ambiguous failure must be marked for review');

resetQueue($pdo);$job=addJob($pdo,'invoice','email','processing',1,date('Y-m-d H:i:s',time()-300));$pdo->exec("UPDATE notification_queue SET processing_started_at=DATE_SUB(NOW(),INTERVAL 4 MINUTE),lease_expires_at=DATE_SUB(NOW(),INTERVAL 4 MINUTE) WHERE id=$job");$fake->calls=[];$fake->mode='success';
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='failed','Stale processing job must not be reset to pending');
check($row['failure_class']==='stale','Stale processing job must be classified as stale');
check(str_contains((string)$row['last_error'],'Outcome unknown'),'Stale processing job must record an unknown outcome');
check(count($fake->calls)===0,'Stale processing job must not be resent automatically');

resetQueue($pdo);$job=addJob($pdo,'invoice','email','processing',1,date('Y-m-d H:i:s',time()-60));$pdo->exec("UPDATE notification_queue SET processing_started_at=NOW(),lease_expires_at=DATE_ADD(NOW(),INTERVAL 2 MINUTE) WHERE id=$job");$fake->calls=[];
$engine->run(true,false,$inside);
$row=row($pdo,$job);
check($row['status']==='processing'&&$row['failure_class']===null,'Unexpired processing job must remain in progress');
check(count($fake->calls)===0,'Unexpired processing job must not be resent');

resetQueue($pdo);$first=addJob($pdo,'invoice','email');$second=addJob($pdo,'invoice','email');$fake->mode='success';$fake->throwOnCall=1;$fake->calls=[];
$engine->run(true,false,$inside);
$row=row($pdo,$first);
check($row['status']==='failed'&&$row['failure_class']==='post_send_unknown','Worker exception must mark the job as post-send unknown');
check(str_contains((string)$row['last_error'],'worker exception'),'Worker exception must be recorded');
check(row($pdo,$second)['status']==='sent','Worker exception must not halt remaining jobs');
check(count($fake->calls)===2,'Worker must continue after a per-job exception');
$fake->throwOnCall=0;

resetQueue($pdo);$fake->mode='success';$fake->calls=[];
$pdo->exec("INSERT INTO payments (billing_id,payment_date,amount_paid,payment_method,verification_status) VALUES (1,CURDATE(),1,'Cash','verified')");
$paymentId=(int)$pdo->lastInsertId();
$engine->queuePayment(1,$paymentId);
$engine->run(true,false,$inside);
$paymentJobs=(int)$pdo->query("SELECT COUNT(*) FROM notification_queue WHERE kind='payment' AND status='sent'")->fetchColumn();
check($paymentJobs===2,'Verified payment confirmations must be delivered in-window');
check(in_array('payment:email',$fake->calls,true)&&in_array('payment:sms',$fake->calls,true),'Payment confirmations must use enabled channels');

echo "PASS: atomic leasing, conservative classification, per-job isolation, retry backoff, stale recovery, delivery windows, and payment timing. No messages sent.\n";
