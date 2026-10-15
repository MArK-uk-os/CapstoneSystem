<?php
date_default_timezone_set('Asia/Manila');
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/BillingAutomation.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
$pdo=getDbConnection();
// Temporary tables shadow live tables only for this connection. No live records are changed.
foreach(['customers','services','customer_services','billing','payments','collections','internet_plans','automation_settings','notification_queue','notifications'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE `test_$table` LIKE `$table`");
    $pdo->exec("ALTER TABLE `test_$table` RENAME TO `$table`");
}
$pdo->exec("INSERT INTO customers (id,account_number,full_name,status) VALUES (1,'CUST-TEST','Automation Test','active')");
$pdo->exec("INSERT INTO services (id,service_name,monthly_fee) VALUES (1,'Internet',100)");
$pdo->exec("INSERT INTO internet_plans (id,name,speed_mbps,monthly_rate,enabled) VALUES (1,'Test Plan',50,799,1)");
$pdo->exec("INSERT INTO automation_settings (id,enabled,channels,pre_due_days) VALUES (1,1,'telegram,email',3)");
$pdo->exec("INSERT INTO customer_services (id,customer_id,service_id,plan_id,billing_amount,start_date,next_billing_date,service_account_number) VALUES (1,1,1,1,1,CURDATE(),CURDATE(),'ACC-TEST')");
$engine=new BillingAutomation($pdo);$result=$engine->run(false);
check($result['generated']===1,'Expected one scheduled invoice');
$invoice=$pdo->query('SELECT * FROM billing')->fetch();
check((float)$invoice['amount']===799.0,'Invoice must use fixed plan rate');
check($invoice['service_name_snapshot']==='Test Plan','Plan snapshot missing');
check($invoice['account_number_snapshot']==='ACC-TEST','Service account snapshot missing');
check($engine->run(false)['generated']===0,'Duplicate invoice generated');
$queueCount=(int)$pdo->query('SELECT COUNT(*) FROM notification_queue')->fetchColumn();
$engine->queueInvoice((int)$invoice['id']);check((int)$pdo->query('SELECT COUNT(*) FROM notification_queue')->fetchColumn()===$queueCount,'Duplicate notification queued');
$pdo->exec("UPDATE billing SET due_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY)");
$pdo->exec("INSERT INTO payments (billing_id,payment_date,amount_paid,payment_method,verification_status) VALUES ({$invoice['id']},CURDATE(),299,'Cash','verified')");
$engine->run(false);$collection=$pdo->query('SELECT * FROM collections')->fetch();
check((float)$collection['outstanding_balance']===500.0,'Partial payment balance incorrect');
check((int)$collection['overdue_days']===1,'Overdue days incorrect');
check(!empty($collection['follow_up_date']),'Follow-up not scheduled');
$queueCount=(int)$pdo->query('SELECT COUNT(*) FROM notification_queue')->fetchColumn();$engine->run(false);
check((int)$pdo->query('SELECT COUNT(*) FROM notification_queue')->fetchColumn()===$queueCount,'Repeated worker duplicated reminder');
$pdo->exec("UPDATE billing SET due_date=DATE_ADD(CURDATE(),INTERVAL 2 DAY)");$engine->run(false);
check($pdo->query('SELECT status FROM billing')->fetchColumn()==='unpaid','Due date extension did not clear overdue');
check((int)$pdo->query("SELECT COUNT(*) FROM notification_queue WHERE kind='pre_due'")->fetchColumn()===2,'Pre-due reminders not queued');
$pdo->exec("INSERT INTO payments (billing_id,payment_date,amount_paid,payment_method,verification_status) VALUES ({$invoice['id']},CURDATE(),500,'Cash','verified')");$engine->synchronize();
check($pdo->query('SELECT status FROM billing')->fetchColumn()==='paid','Settled invoice status incorrect');
check($pdo->query('SELECT collection_status FROM collections')->fetchColumn()==='settled','Collection not settled');
$paymentId=(int)$pdo->query('SELECT MAX(id) FROM payments')->fetchColumn();
$engine->queuePayment((int)$invoice['id'],$paymentId);$engine->queuePayment((int)$invoice['id'],$paymentId);
check((int)$pdo->query("SELECT COUNT(*) FROM notification_queue WHERE kind='payment'")->fetchColumn()===2,'Payment confirmation events duplicated');
check(BillingAutomation::nextPeriod('2026-01-31','monthly')==='2026-02-28','Month-end billing overflow');
check(BillingAutomation::nextPeriod('2026-10-11','quarterly')==='2027-01-11','Quarterly schedule incorrect');
echo "PASS: generation, plan snapshots, duplicate prevention, partial payments, overdue follow-up, due-date changes, pre-due reminders, settlement, and calendar boundaries. No messages sent.\n";
