<?php
date_default_timezone_set('Asia/Manila');
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/BillingAutomation.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
class FakeOverdueNotifier extends NotificationService
{
    public array $calls=[];
    public function __construct() {}
    public function sendOverdueNotification(array $billing,int $overdueDays,string $channel,bool $log=true):array
    {
        $this->calls[]='overdue:'.$channel;
        return ['success'=>true,'message'=>'ok','retryable'=>false,'ambiguous'=>false];
    }
}
$pdo=getDbConnection();
foreach(['customers','services','customer_services','billing','payments','collections','internet_plans','automation_settings','notification_queue','notifications','service_disconnections'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `test_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `test_$table` RENAME TO `$table`");
}
$pdo->exec("INSERT INTO customers (id,account_number,full_name,email,status) VALUES (1,'CUST-OVERDUE','Overdue Test','overdue@example.com','active')");
$pdo->exec("INSERT INTO automation_settings (id,enabled,channels,pre_due_days,overdue_repeat_days,follow_up_days,delivery_window_start,delivery_window_end) VALUES (1,1,'email',3,7,1,'09:00:00','17:00:00')");
$pdo->exec("INSERT INTO billing (id,invoice_number,customer_id,billing_period_start,billing_period_end,due_date,amount,status) VALUES (1,'INV-OVERDUE',1,CURDATE(),CURDATE(),CURDATE(),100,'unpaid')");
function setOverdueDays(PDO $pdo,int $days):void
{
    $pdo->exec("UPDATE billing SET status='unpaid',due_date=DATE_SUB(CURDATE(),INTERVAL $days DAY),amount=100 WHERE id=1");
}
function resetOverdue(PDO $pdo,int $days):void
{
    $pdo->exec('DELETE FROM notification_queue');
    $pdo->exec('DELETE FROM notifications');
    $pdo->exec('DELETE FROM payments');
    setOverdueDays($pdo,$days);
}
function overdueRows(PDO $pdo,?string $status=null):array
{
    $sql="SELECT * FROM notification_queue WHERE billing_id=1 AND kind='overdue' AND channel='email'";
    if ($status!==null) $sql.=" AND status='$status'";
    return $pdo->query($sql.' ORDER BY id')->fetchAll();
}
$engine=new BillingAutomation($pdo);
$inside=new DateTimeImmutable('2026-10-20T10:00:00+08:00');

resetOverdue($pdo,1);
$engine->run(false);
$jobs=overdueRows($pdo);
check(count($jobs)===1,'Day 1 overdue must create one overdue job.');
check(str_ends_with($jobs[0]['event_key'],':7:0:email'),'Day 1 overdue must use interval bucket 0.');
$firstId=(int)$jobs[0]['id'];
$pdo->exec("UPDATE notification_queue SET status='sent' WHERE id=$firstId");
$pdo->exec("INSERT INTO notifications (customer_id,billing_id,type,channel,status,sent_at) VALUES (1,1,'overdue','email','sent',NOW())");

for($day=2;$day<=7;$day++) {
    setOverdueDays($pdo,$day);
    $engine->run(false);
    check(count(overdueRows($pdo))===1,'Day '.$day.' must not create another overdue job inside the interval.');
}

setOverdueDays($pdo,8);
$engine->run(false);
check(count(overdueRows($pdo))===1,'Day 8 must wait when the last overdue notice is still inside the interval.');
$pdo->exec("UPDATE notifications SET sent_at=DATE_SUB(NOW(),INTERVAL 8 DAY) WHERE billing_id=1 AND type='overdue' AND channel='email'");
$engine->run(false);
$jobs=overdueRows($pdo);
check(count($jobs)===2,'Day 8 must create the next overdue job after seven full days.');
$new=overdueRows($pdo,'pending');
check(count($new)===1,'Day 8 must leave exactly one pending overdue job.');
check(str_ends_with($new[0]['event_key'],':7:1:email'),'Day 8 must use interval bucket 1.');

resetOverdue($pdo,20);
$due=$pdo->query('SELECT due_date FROM billing WHERE id=1')->fetchColumn();
for($bucket=0;$bucket<3;$bucket++) {
    $pdo->prepare('INSERT INTO notification_queue (event_key,billing_id,kind,channel,due_date,status,attempts,next_attempt_at) VALUES (?,1,\'overdue\',\'email\',?,\'pending\',0,DATE_SUB(NOW(),INTERVAL 1 MINUTE))')->execute(['backlog-'.$bucket,$due]);
}
$pdo->exec("INSERT INTO notifications (customer_id,billing_id,type,channel,status,sent_at) VALUES (1,1,'overdue','email','sent',NOW())");
$engine->run(false);
check(count(overdueRows($pdo,'pending'))===0,'A recent overdue notice must cancel stale pending interval jobs.');

resetOverdue($pdo,20);
$due=$pdo->query('SELECT due_date FROM billing WHERE id=1')->fetchColumn();
for($bucket=0;$bucket<3;$bucket++) {
    $pdo->prepare('INSERT INTO notification_queue (event_key,billing_id,kind,channel,due_date,status,attempts,next_attempt_at) VALUES (?,1,\'overdue\',\'email\',?,\'pending\',0,DATE_SUB(NOW(),INTERVAL 1 MINUTE))')->execute(['flood-'.$bucket,$due]);
}
$fake=new FakeOverdueNotifier();
$engine=new BillingAutomation($pdo,$fake);
$engine->run(true,false,$inside);
check(count($fake->calls)===1,'Day 20 backlog must send exactly one overdue notice.');
check(count(overdueRows($pdo,'pending'))===0,'Day 20 backlog must not leave simultaneous pending overdue jobs.');
check(count(overdueRows($pdo,'sent'))===1,'Day 20 backlog must collapse into one sent overdue job.');

resetOverdue($pdo,20);
$pdo->exec("INSERT INTO payments (billing_id,payment_date,amount_paid,payment_method,verification_status) VALUES (1,CURDATE(),100,'Cash','verified')");
$engine=new BillingAutomation($pdo);
$engine->run(false);
check($pdo->query('SELECT status FROM billing WHERE id=1')->fetchColumn()==='paid','Fully paid bill must be marked paid.');
check(count(overdueRows($pdo))===0,'Fully paid bill must not generate overdue jobs.');

echo "PASS: overdue buckets, interval spacing, backlog coalescing, and paid exclusion. No messages sent.\n";
