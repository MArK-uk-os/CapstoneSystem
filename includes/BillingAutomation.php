<?php
require_once __DIR__ . '/notifications/NotificationService.php';
require_once __DIR__ . '/DisconnectionService.php';

class BillingAutomation
{
    private const DELIVERY_START = '08:00:00';
    private const DELIVERY_END = '20:00:00';
    private const MAX_JOBS_PER_RUN = 50;
    private const STALE_PROCESSING_MINUTES = 15;
    private const MAX_ATTEMPTS = 3;
    private const RETRY_MINUTES = [1 => 5, 2 => 15];

    public function __construct(private PDO $pdo, private ?NotificationService $notifier = null) {}

    public static function nextPeriod(string $start, string $frequency): string
    {
        $months = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12][$frequency] ?? 1;
        $date = new DateTimeImmutable($start);
        $target = $date->modify('first day of this month')->modify("+$months months");
        $day = min((int) $date->format('d'), (int) $target->format('t'));
        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'), $day)->format('Y-m-d');
    }

    public static function withinDeliveryWindow(?DateTimeImmutable $now=null): bool
    {
        $now=($now??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila'));
        $time=$now->format('H:i:s');
        return $time>=self::DELIVERY_START && $time<self::DELIVERY_END;
    }

    public function runScheduled(?DateTimeImmutable $now=null): array
    {
        if(!$this->pdo->query("SELECT GET_LOCK('cloudinterweb_daily_schedule',0)")->fetchColumn())return ['busy'=>true];
        try {
            $now=$now??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
            return $this->run(true,false,$now);
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

    public function run(bool $deliver = true, bool $paymentsOnly = false, ?DateTimeImmutable $now=null): array
    {
        $settings = $this->pdo->query('SELECT * FROM automation_settings WHERE id=1')->fetch();
        if (!$settings['enabled']) return ['enabled' => false];
        if (!$this->pdo->query("SELECT GET_LOCK('cloudinterweb_billing_automation',0)")->fetchColumn()) return ['busy' => true];
        $generated = 0; $sent = 0; $failed = 0;
        $now=$now??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
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
            foreach ($this->pdo->query("SELECT b.id,b.due_date,DATEDIFF(b.due_date,CURDATE()) days FROM billing b JOIN customers c ON c.id=b.customer_id WHERE b.status!='paid' AND c.status='active'") as $bill) {
                $days=(int)$bill['days'];
                if ($days>0 && $days<=(int)$settings['pre_due_days']) $this->queueInvoice((int)$bill['id'],'pre_due');
                elseif ($days===0) $this->queueInvoice((int)$bill['id'],'due');
                elseif ($days<0) $this->queueInvoice((int)$bill['id'],'overdue',(string)intdiv(-$days-1,max(1,(int)$settings['overdue_repeat_days'])));
            }
            }
            if ($deliver) {
                $this->failStaleProcessing();
                if (!self::withinDeliveryWindow($now)) return ['waiting_for_delivery_window'=>true,'generated'=>$generated,'sent'=>0,'failed'=>0];
                $notifier=$this->notifier??new NotificationService($this->pdo);
                $channels=array_values(array_filter(array_map('trim',explode(',',(string)$settings['channels']))));
                $paymentFilter = $paymentsOnly ? " AND kind='payment'" : '';
                $jobs=$this->pdo->query("SELECT * FROM notification_queue WHERE status='pending' AND next_attempt_at<=NOW()" . $paymentFilter . " ORDER BY id LIMIT ".self::MAX_JOBS_PER_RUN)->fetchAll();
                foreach ($jobs as $job) {
                    if (!in_array($job['channel'],$channels,true)) { $this->pdo->prepare("UPDATE notification_queue SET status='cancelled',last_error='Channel disabled.' WHERE id=?")->execute([$job['id']]); continue; }
                    $query=$this->pdo->prepare("SELECT b.*,c.full_name,c.email,c.contact_number,c.telegram_chat_id,c.status customer_status,GREATEST(b.amount-COALESCE(p.paid,0),0) outstanding_balance FROM billing b JOIN customers c ON c.id=b.customer_id LEFT JOIN (SELECT billing_id,SUM(amount_paid) paid FROM payments WHERE verification_status='verified' GROUP BY billing_id) p ON p.billing_id=b.id WHERE b.id=?"); $query->execute([$job['billing_id']]); $bill=$query->fetch();
                    $days=(int)(new DateTimeImmutable(date('Y-m-d')))->diff(new DateTimeImmutable($bill['due_date']))->format('%r%a');
                    if ($job['kind'] !== 'payment' && ($bill['customer_status'] ?? '') !== 'active') { $this->pdo->prepare("UPDATE notification_queue SET status='cancelled' WHERE id=?")->execute([$job['id']]); continue; }
                    $expired=($job['kind']==='pre_due' && $days<=0) || ($job['kind']==='due' && $days!==0) || ($job['kind']==='overdue' && $days>=0);
                    if ($job['kind']!=='payment' && ($expired || $bill['due_date']!==$job['due_date'] || ($bill['status']==='paid' && $job['kind']!=='invoice'))) { $this->pdo->prepare("UPDATE notification_queue SET status='cancelled' WHERE id=?")->execute([$job['id']]); continue; }
                    $this->pdo->prepare("UPDATE notification_queue SET status='processing',attempts=attempts+1,next_attempt_at=NOW() WHERE id=?")->execute([$job['id']]);
                    if ($job['kind']==='payment') {
                        $query=$this->pdo->prepare("SELECT * FROM payments WHERE id=? AND billing_id=? AND verification_status='verified'");$query->execute([$job['payment_id'],$bill['id']]);$payment=$query->fetch();
                        $result=$payment ? $notifier->sendPaymentConfirmation($bill,$payment,$job['channel']) : ['success'=>false,'message'=>'Verified payment not found.','retryable'=>false,'ambiguous'=>false];
                    } else $result=$job['kind']==='overdue' ? $notifier->sendOverdueNotification($bill,max(1,(int)(new DateTimeImmutable($bill['due_date']))->diff(new DateTimeImmutable(date('Y-m-d')))->days),$job['channel']) : $notifier->sendReminder($bill,$job['channel'],$job['kind']);
                    $attempt=(int)$job['attempts']+1;
                    if ($result['success']) {
                        $sent++;
                        $this->pdo->prepare("UPDATE notification_queue SET status='sent',last_error=NULL,next_attempt_at=NOW() WHERE id=?")->execute([$job['id']]);
                        continue;
                    }
                    $failed++;
                    $message=(string)($result['message']??'Notification failed.');
                    $ambiguous=!empty($result['ambiguous']);
                    $retryable=!empty($result['retryable']);
                    if ($ambiguous && stripos($message,'Outcome unknown')!==0) $message='Outcome unknown: '.$message.' Review delivery before retrying.';
                    $message=self::truncateError($message);
                    if ($ambiguous || !$retryable || $attempt>=self::MAX_ATTEMPTS) {
                        $this->pdo->prepare("UPDATE notification_queue SET status='failed',last_error=?,next_attempt_at=NOW() WHERE id=?")->execute([$message,$job['id']]);
                    } else {
                        $minutes=self::RETRY_MINUTES[$attempt]??15;
                        $this->pdo->prepare("UPDATE notification_queue SET status='pending',last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL ? MINUTE) WHERE id=?")->execute([$message,$minutes,$job['id']]);
                    }
                }
            }
            return compact('generated','sent','failed');
        } finally { $this->pdo->query("SELECT RELEASE_LOCK('cloudinterweb_billing_automation')"); }
    }

    private function failStaleProcessing(): void
    {
        $this->pdo->exec("UPDATE notification_queue SET status='failed',last_error='Outcome unknown: previous send was interrupted. Review delivery before retrying.' WHERE status='processing' AND next_attempt_at<DATE_SUB(NOW(),INTERVAL ".self::STALE_PROCESSING_MINUTES." MINUTE)");
    }

    private static function truncateError(string $message): string
    {
        return function_exists('mb_substr')?mb_substr($message,0,255):substr($message,0,255);
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
