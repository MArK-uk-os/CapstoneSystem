<?php
date_default_timezone_set('Asia/Manila');
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/notifications/ManualNotificationService.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
class FakeManualNotifier extends NotificationService
{
    public string $mode='success';
    public array $calls=[];
    public function __construct() {}
    private function result(string $channel,string $kind):array
    {
        $this->calls[]=$kind.':'.$channel;
        if($this->mode==='success')return ['success'=>true,'message'=>'ok','retryable'=>false,'ambiguous'=>false];
        if($this->mode==='ambiguous')return ['success'=>false,'message'=>'Provider timeout.','retryable'=>false,'ambiguous'=>true];
        return ['success'=>false,'message'=>'Permanent provider failure.','retryable'=>false,'ambiguous'=>false];
    }
    public function sendBillingNotification(array $billing,string $channel,bool $log=true):array { return $this->result($channel,'billing'); }
    public function sendOverdueNotification(array $billing,int $overdueDays,string $channel,bool $log=true):array { return $this->result($channel,'overdue'); }
}
$pdo=getDbConnection();
foreach(['customers','billing','notifications','notification_queue'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `test_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `test_$table` RENAME TO `$table`");
}
$pdo->exec("INSERT INTO customers (id,account_number,full_name,email,contact_number,status) VALUES (1,'CUST-MANUAL','Manual Test','manual@example.com','09170000000','active')");
$pdo->exec("INSERT INTO billing (id,invoice_number,customer_id,billing_period_start,billing_period_end,due_date,amount,status) VALUES (1,'INV-MANUAL-1',1,CURDATE(),CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),100,'unpaid')");
$billing=$pdo->query("SELECT b.*,c.full_name,c.email,c.contact_number FROM billing b JOIN customers c ON c.id=b.customer_id WHERE b.id=1")->fetch();
function resetData(PDO $pdo):void { $pdo->exec('DELETE FROM notification_queue');$pdo->exec('DELETE FROM notifications'); }
function queueJob(PDO $pdo,string $kind,string $channel):int
{
    static $n=0;$n++;
    $stmt=$pdo->prepare('INSERT INTO notification_queue(event_key,billing_id,kind,channel,due_date,status,attempts,next_attempt_at) VALUES (?,1,?,?,CURDATE(),"pending",0,NOW())');
    $stmt->execute(['manual-test-'.$n,$kind,$channel]);
    return (int)$pdo->lastInsertId();
}
function queueRow(PDO $pdo,int $id):array { $stmt=$pdo->prepare('SELECT * FROM notification_queue WHERE id=?');$stmt->execute([$id]);return $stmt->fetch(); }
function notificationRow(PDO $pdo,string $key):array { $stmt=$pdo->prepare('SELECT * FROM notifications WHERE request_key=?');$stmt->execute([$key]);return $stmt->fetch(); }
$fake=new FakeManualNotifier();
$service=new ManualNotificationService($pdo,$fake);

resetData($pdo);
$job=queueJob($pdo,'invoice','email');
$key=str_repeat('a',32);
$result=$service->sendBilling($billing,'email',$key,1);
check($result['success']===true&&$result['alert']==='success','Successful manual send must return success.');
check($fake->calls===['billing:email'],'Successful manual send must contact the provider once.');
check($result['cancelled_queue']===1,'Successful manual send must cancel one matching queue job.');
check(queueRow($pdo,$job)['status']==='cancelled','Matching automatic job must be cancelled.');
check(str_contains((string)queueRow($pdo,$job)['last_error'],'manual staff'),'Cancellation reason must be recorded.');
$row=notificationRow($pdo,$key);
check($row['status']==='sent'&&(int)$row['requested_by']===1&&$row['error_message']===null,'Sent audit row is incorrect.');

$fake->calls=[];
$result=$service->sendBilling($billing,'email',str_repeat('b',32),1);
check($result['success']===false&&$result['alert']==='warning'&&!empty($result['cooldown']),'Cooldown must block rapid repeat sends.');
check($fake->calls===[],'Cooldown must not contact the provider.');
check((int)$pdo->query('SELECT COUNT(1) FROM notifications')->fetchColumn()===1,'Cooldown must not create another audit row.');

$pdo->exec("UPDATE notifications SET sent_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE) WHERE request_key='$key'");
$fake->calls=[];
$result=$service->sendBilling($billing,'email',$key,1);
check($result['success']===false&&$result['alert']==='warning'&&!empty($result['duplicate']),'Duplicate request key must be rejected after cooldown.');
check($fake->calls===[],'Duplicate request key must not contact the provider.');

resetData($pdo);
$fake->mode='permanent';$fake->calls=[];
$result=$service->sendOverdue($billing,5,'sms',str_repeat('c',32),1);
check($result['success']===false&&$result['alert']==='danger','Failed manual send must return danger.');
$row=notificationRow($pdo,str_repeat('c',32));
check($row['status']==='failed'&&str_contains((string)$row['error_message'],'Permanent provider failure'),'Failed manual send must store the error.');
$fake->mode='success';
$result=$service->sendOverdue($billing,5,'sms',str_repeat('d',32),1);
check($result['success']===true&&$result['alert']==='success','Failed attempts must be retryable immediately without cooldown.');
check(count($fake->calls)===2,'Failed attempt plus immediate retry must contact the provider twice.');

resetData($pdo);
$payment=queueJob($pdo,'payment','email');
$overdue=queueJob($pdo,'overdue','email');
$fake->mode='ambiguous';$fake->calls=[];
$result=$service->sendOverdue($billing,5,'email',str_repeat('e',32),1);
check($result['success']===false&&$result['alert']==='warning'&&!empty($result['ambiguous']),'Ambiguous manual send must return warning.');
check($result['cancelled_queue']===1,'Ambiguous manual send must cancel matching automatic jobs.');
check(queueRow($pdo,$overdue)['status']==='cancelled','Matching overdue queue job must be cancelled on ambiguity.');
check(queueRow($pdo,$payment)['status']==='pending','Payment confirmation queue job must not be cancelled.');
check(str_contains((string)notificationRow($pdo,str_repeat('e',32))['error_message'],'Provider timeout'),'Ambiguous manual error must be stored.');

require_once __DIR__.'/../includes/auth.php';
flash('warning','Review manual send.');
$flash=takeFlash();
check($flash['type']==='warning'&&$flash['message']==='Review manual send.','Flash severity and message must round-trip.');
check(takeFlash()===null,'Flash must be consumed once.');

echo "PASS: manual idempotency, cooldown, failed retry, queue cancellation, ambiguous handling, error logging, and flash severity. No messages sent.\n";
