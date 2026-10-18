<?php
require_once __DIR__ . '/notifications/NotificationService.php';
require_once __DIR__ . '/DisconnectionService.php';

class BillingAutomation
{
    public function __construct(private PDO $pdo) {}

    public static function nextPeriod(string $start, string $frequency): string
    {
        $months = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12][$frequency] ?? 1;
        $date = new DateTimeImmutable($start);
        $target = $date->modify('first day of this month')->modify("+$months months");
        $day = min((int) $date->format('d'), (int) $target->format('t'));
        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'), $day)->format('Y-m-d');
    }

    public static function dailyRunDue(array $settings, DateTimeImmutable $now): bool
    {
        $now=$now->setTimezone(new DateTimeZone('Asia/Manila'));
        return (bool)$settings['enabled'] && ($settings['last_scheduled_date']??null)!==$now->format('Y-m-d')
            && $now->format('H:i:s')>=($settings['send_time']??'08:00:00');
    }

    public function runScheduled(): array
    {
        if(!$this->pdo->query("SELECT GET_LOCK('cloudinterweb_daily_schedule',0)")->fetchColumn())return ['busy'=>true];
        try {
            $settings=$this->pdo->query('SELECT * FROM automation_settings WHERE id=1')->fetch();
            $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
            // Invoice creation must not wait for (or miss) the daily reminder run.
            $queued=$this->run(false);
            if(isset($queued['busy']) || isset($queued['enabled']))return $queued;
            if(!self::dailyRunDue($settings,$now)) {
                $result=$this->run(true, true);
                $result['generated']=($result['generated']??0)+$queued['generated'];
                return ['waiting_for_daily_time'=>true] + $result;
            }
            $result=$this->run();
            $result['generated']=($result['generated']??0)+$queued['generated'];
            if(!isset($result['busy']) && !isset($result['enabled']))$this->pdo->prepare('UPDATE automation_settings SET last_scheduled_date=? WHERE id=1')->execute([$now->format('Y-m-d')]);
            return $result;
        } finally {$this->pdo->query("SELECT RELEASE_LOCK('cloudinterweb_daily_schedule')");}
    }

    public function synchronize(): void
    {
        $this->pdo->exec("UPDATE billing b LEFT JOIN (SELECT billing_id,SUM(amount_paid) paid FROM payments WHERE verification_status='verified' GROUP BY billing_id) p ON p.billing_id=b.id SET b.status=CASE WHEN COALESCE(p.paid,0)>=b.amount THEN 'paid' WHEN b.due_date<CURDATE() THEN 'overdue' ELSE 'unpaid' END");
        $this->pdo->exec("INSERT INTO collections (billing_id,customer_id,outstanding_balance,overdue_days,follow_up_date) SELECT b.id,b.customer_id,GREATEST(b.amount-COALESCE(p.paid,0),0),DATEDIFF(CURDATE(),b.due_date),DATE_ADD(CURDATE(),INTERVAL (SELECT follow_up_days FROM automation_settings WHERE id=1) DAY) FROM billing b LEFT JOIN (SELECT billing_id,SUM(amount_paid) paid FROM payments WHERE verification_status='verified' GROUP BY billing_id) p ON p.billing_id=b.id LEFT JOIN collections c ON c.billing_id=b.id WHERE b.status='overdue' AND c.id IS NULL");
        $this->pdo->exec("UPDATE collections c JOIN billing b ON b.id=c.billing_id LEFT JOIN (SELECT billing_id,SUM(amount_paid) paid FROM payments WHERE verification_status='verified' GROUP BY billing_id) p ON p.billing_id=b.id SET c.outstanding_balance=GREATEST(b.amount-COALESCE(p.paid,0),0),c.overdue_days=GREATEST(DATEDIFF(CURDATE(),b.due_date),0),c.collection_status=IF(b.status='paid','settled',IF(c.collection_status='settled','pending',c.collection_status))");
    }

    public function queueInvoice(int $id, string $kind = 'invoice', string $suffix = ''): void
    {
        $settings = $this->pdo->query('SELECT * FROM automation_settings WHERE id=1')->fetch();
        if (!$settings['enabled']) return;
        $stmt = $this->pdo->prepare('SELECT due_date FROM billing WHERE id=?'); $stmt->execute([$id]);
        $due = $stmt->fetchColumn();
        foreach (explode(',', $settings['channels']) as $channel) {
            if (!in_array($channel, ['telegram','sms','email'], true)) continue;
            $key = implode(':', [$id,$kind,$due,$suffix,$channel]);
            $this->pdo->prepare('INSERT IGNORE INTO notification_queue (event_key,billing_id,kind,channel,due_date) VALUES (?,?,?,?,?)')->execute([$key,$id,$kind,$channel,$due]);
        }
    }

    public function run(bool $deliver = true, bool $paymentsOnly = false): array
    {
        $settings = $this->pdo->query('SELECT * FROM automation_settings WHERE id=1')->fetch();
        if (!$settings['enabled']) return ['enabled' => false];
        if (!$this->pdo->query("SELECT GET_LOCK('cloudinterweb_billing_automation',0)")->fetchColumn()) return ['busy' => true];
        $generated = 0; $sent = 0; $failed = 0;
        try {
            if (!$paymentsOnly) {
            $subscriptions = $this->pdo->query("SELECT cs.id FROM customer_services cs JOIN customers c ON c.id=cs.customer_id JOIN services s ON s.id=cs.service_id WHERE cs.status IN ('active','disconnected') AND c.status='active' AND s.status='active' AND cs.next_billing_date<=CURDATE()")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($subscriptions as $subscriptionId) {
                for ($cycle=0; $cycle<24; $cycle++) {
                    $this->pdo->beginTransaction();
                    try {
                        $query=$this->pdo->prepare('SELECT cs.*,s.service_name,ip.name plan_name,ip.monthly_rate FROM customer_services cs JOIN services s ON s.id=cs.service_id LEFT JOIN internet_plans ip ON ip.id=cs.plan_id WHERE cs.id=? FOR UPDATE');
                        $query->execute([$subscriptionId]); $cs=$query->fetch();
                        if (!$cs['next_billing_date'] || $cs['next_billing_date']>date('Y-m-d')) { $this->pdo->commit(); break; }
                        $start=$cs['next_billing_date']; $next=self::nextPeriod($start,$cs['billing_frequency']);
                        $end=(new DateTimeImmutable($next))->modify('-1 day')->format('Y-m-d');
                        $due=(new DateTimeImmutable($start))->modify('+' . (int)$cs['due_days'] . ' days')->format('Y-m-d');
                        $exists=$this->pdo->prepare('SELECT MAX(billing_period_end) FROM billing WHERE customer_service_id=? AND billing_period_start<=? AND billing_period_end>=?'); $exists->execute([$cs['id'],$end,$start]);
                        $existingEnd=$exists->fetchColumn();
                        if ($existingEnd) $next=(new DateTimeImmutable($existingEnd))->modify('+1 day')->format('Y-m-d');
                        if (!$existingEnd) {
                            $factor=['monthly'=>1,'quarterly'=>3,'annually'=>12][$cs['billing_frequency']];
                            $rate=$cs['plan_id']!==null ? $cs['monthly_rate'] : $cs['billing_amount'];
                            $insert=$this->pdo->prepare('INSERT INTO billing (invoice_number,customer_id,customer_service_id,billing_period_start,billing_period_end,due_date,amount,service_name_snapshot,account_number_snapshot) VALUES (?,?,?,?,?,?,?,?,?)');
                            $insert->execute(['INV-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3))),$cs['customer_id'],$cs['id'],$start,$end,$due,round((float)$rate*$factor,2),$cs['plan_name']?:$cs['service_name'],$cs['service_account_number']]);
                            $invoiceId=(int)$this->pdo->lastInsertId();
                            (new DisconnectionService($this->pdo))->applyCharges((int)$cs['id'],$invoiceId);
                            $this->queueInvoice($invoiceId); $generated++;
                        }
                        $this->pdo->prepare('UPDATE customer_services SET next_billing_date=? WHERE id=?')->execute([$next,$cs['id']]);
                        $this->pdo->commit();
                    } catch (Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
                }
            }
            $this->synchronize();
            foreach ($this->pdo->query("SELECT id,due_date,DATEDIFF(due_date,CURDATE()) days FROM billing WHERE status!='paid'") as $bill) {
                $days=(int)$bill['days'];
                if ($days>0 && $days<=(int)$settings['pre_due_days']) $this->queueInvoice((int)$bill['id'],'pre_due');
                elseif ($days===0) $this->queueInvoice((int)$bill['id'],'due');
                elseif ($days<0) $this->queueInvoice((int)$bill['id'],'overdue',(string)intdiv(-$days-1,max(1,(int)$settings['overdue_repeat_days'])));
            }
            }
            if ($deliver) {
                $notifier=new NotificationService($this->pdo);
                $paymentFilter = $paymentsOnly ? " AND kind='payment'" : '';
                $jobs=$this->pdo->query("SELECT * FROM notification_queue WHERE status='pending' AND next_attempt_at<=NOW()" . $paymentFilter . " ORDER BY id")->fetchAll();
                foreach ($jobs as $job) {
                    $query=$this->pdo->prepare("SELECT b.*,c.full_name,c.email,c.contact_number,c.telegram_chat_id,GREATEST(b.amount-COALESCE(p.paid,0),0) outstanding_balance FROM billing b JOIN customers c ON c.id=b.customer_id LEFT JOIN (SELECT billing_id,SUM(amount_paid) paid FROM payments WHERE verification_status='verified' GROUP BY billing_id) p ON p.billing_id=b.id WHERE b.id=?"); $query->execute([$job['billing_id']]); $bill=$query->fetch();
                    $days=(int)(new DateTimeImmutable(date('Y-m-d')))->diff(new DateTimeImmutable($bill['due_date']))->format('%r%a');
                    $expired=($job['kind']==='pre_due' && $days<=0) || ($job['kind']==='due' && $days!==0) || ($job['kind']==='overdue' && $days>=0);
                    if ($job['kind']!=='payment' && ($expired || $bill['due_date']!==$job['due_date'] || ($bill['status']==='paid' && $job['kind']!=='invoice'))) { $this->pdo->prepare("UPDATE notification_queue SET status='cancelled' WHERE id=?")->execute([$job['id']]); continue; }
                    // Claim before network I/O: uncertain interrupted sends require staff review rather than duplicate retries.
                    $this->pdo->prepare("UPDATE notification_queue SET status='processing',attempts=attempts+1 WHERE id=?")->execute([$job['id']]);
                    if ($job['kind']==='payment') {
                        $query=$this->pdo->prepare("SELECT * FROM payments WHERE id=? AND billing_id=? AND verification_status='verified'");$query->execute([$job['payment_id'],$bill['id']]);$payment=$query->fetch();
                        $result=$payment ? $notifier->sendPaymentConfirmation($bill,$payment,$job['channel']) : ['success'=>false,'message'=>'Verified payment not found.'];
                    } else $result=$job['kind']==='overdue' ? $notifier->sendOverdueNotification($bill,max(1,(int)(new DateTimeImmutable($bill['due_date']))->diff(new DateTimeImmutable(date('Y-m-d')))->days),$job['channel']) : $notifier->sendReminder($bill,$job['channel'],$job['kind']);
                    $status=$result['success']?'sent':((int)$job['attempts']>=2?'failed':'pending');
                    $this->pdo->prepare('UPDATE notification_queue SET status=?,last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 6 HOUR) WHERE id=?')->execute([$status,$result['success']?null:$result['message'],$job['id']]);
                    $result['success']?$sent++:$failed++;
                }
            }
            return compact('generated','sent','failed');
        } finally { $this->pdo->query("SELECT RELEASE_LOCK('cloudinterweb_billing_automation')"); }
    }

    public function queuePayment(int $invoiceId,int $paymentId):void
    {
        $settings=$this->pdo->query('SELECT * FROM automation_settings WHERE id=1')->fetch();
        $query=$this->pdo->prepare('SELECT due_date FROM billing WHERE id=?');$query->execute([$invoiceId]);$due=$query->fetchColumn();
        foreach(explode(',',$settings['channels']) as $channel) {
            if(!in_array($channel,['telegram','email','sms'],true))continue;
            $this->pdo->prepare("INSERT IGNORE INTO notification_queue(event_key,billing_id,payment_id,kind,channel,due_date) VALUES (?,?,?,'payment',?,?)")->execute(['payment:'.$paymentId.':'.$channel,$invoiceId,$paymentId,$channel,$due]);
        }
    }
}
