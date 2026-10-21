<?php
require_once __DIR__ . '/notifications/NotificationService.php';
require_once __DIR__ . '/DisconnectionService.php';

class BillingAutomation
{
    private const DEFAULT_DELIVERY_START = '08:00:00';
    private const DEFAULT_DELIVERY_END = '20:00:00';
    private const MAX_JOBS_PER_RUN = 50;
    private const STALE_LEASE_MINUTES = 3;
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

    public static function isValidDeliveryTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)===1;
    }

    public static function isValidDeliveryWindow(string $start,string $end): bool
    {
        return self::isValidDeliveryTime($start) && self::isValidDeliveryTime($end) && $start!==$end;
    }

    public static function withinDeliveryWindow(?DateTimeImmutable $now=null,?string $start=null,?string $end=null): bool
    {
        $tz=new DateTimeZone('Asia/Manila');
        $now=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz);
        $start=self::timeToSeconds($start ?: self::DEFAULT_DELIVERY_START);
        $end=self::timeToSeconds($end ?: self::DEFAULT_DELIVERY_END);
        $current=(int)$now->format('G')*3600+(int)$now->format('i')*60+(int)$now->format('s');
        if ($start===$end) return true;
        if ($start<$end) return $current>=$start && $current<$end;
        return $current>=$start || $current<$end;
    }

    private static function timeToSeconds(string $time): int
    {
        $parts=array_map('intval',explode(':',$time));
        return ($parts[0]??0)*3600+($parts[1]??0)*60+($parts[2]??0);
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
            if (!in_array($channel, ['sms','email'], true)) continue;
            $key = implode(':', [$id,$kind,$due,$suffix,$channel]);
            $this->pdo->prepare('INSERT IGNORE INTO notification_queue (event_key,billing_id,kind,channel,due_date) VALUES (?,?,?,?,?)')->execute([$key,$id,$kind,$channel,$due]);
        }
    }

    private function queueOverdue(int $id,int $overdueDays,int $repeatDays): void
    {
        $settings=$this->pdo->query('SELECT * FROM automation_settings WHERE id=1')->fetch();
        if (!$settings['enabled']) return;
        $repeatDays=max(1,$repeatDays);
        $stmt=$this->pdo->prepare('SELECT due_date FROM billing WHERE id=?');$stmt->execute([$id]);
        $due=$stmt->fetchColumn();
        if ($due===false) return;
        $bucket=(string)intdiv(-$overdueDays-1,$repeatDays);
        foreach (explode(',',$settings['channels']) as $channel) {
            if (!in_array($channel,['sms','email'],true)) continue;
            $recent=$this->pdo->prepare("SELECT id FROM notifications WHERE billing_id=? AND type='overdue' AND channel=? AND status IN ('processing','sent','delivered') AND sent_at>DATE_SUB(NOW(),INTERVAL $repeatDays DAY) LIMIT 1");
            $recent->execute([$id,$channel]);
            if ($recent->fetchColumn()) {
                $this->cancelPendingOverdue($id,$channel,'Previous overdue notice sent within the repeat interval.');
                continue;
            }
            $active=$this->pdo->prepare("SELECT id,status FROM notification_queue WHERE billing_id=? AND channel=? AND kind='overdue' AND status IN ('pending','processing') ORDER BY id DESC");
            $active->execute([$id,$channel]);
            $jobs=$active->fetchAll();
            if ($jobs) {
                $processing=false;
                foreach ($jobs as $job) if ($job['status']==='processing') {$processing=true;break;}
                if ($processing) {
                    $this->cancelPendingOverdue($id,$channel,'Superseded by an in-flight overdue notification.');
                } else {
                    $cancel=$this->pdo->prepare("UPDATE notification_queue SET status='cancelled',failure_class=NULL,last_error='Superseded by a newer overdue interval.',next_attempt_at=NOW(),lease_expires_at=NULL WHERE billing_id=? AND channel=? AND kind='overdue' AND status='pending' AND id<>?");
                    $cancel->execute([$id,$channel,(int)$jobs[0]['id']]);
                }
                continue;
            }
            $key=implode(':',[$id,'overdue',$due,$repeatDays.':'.$bucket,$channel]);
            $this->pdo->prepare('INSERT IGNORE INTO notification_queue (event_key,billing_id,kind,channel,due_date) VALUES (?,?,?,?,?)')->execute([$key,$id,'overdue',$channel,$due]);
        }
    }

    private function cancelPendingOverdue(int $id,string $channel,string $message): void
    {
        $stmt=$this->pdo->prepare("UPDATE notification_queue SET status='cancelled',failure_class=NULL,last_error=?,next_attempt_at=NOW(),lease_expires_at=NULL WHERE billing_id=? AND channel=? AND kind='overdue' AND status='pending'");
        $stmt->execute([$message,$id,$channel]);
    }

    public function run(bool $deliver = true, bool $paymentsOnly = false, ?DateTimeImmutable $now=null): array
    {
        $settings = $this->pdo->query('SELECT * FROM automation_settings WHERE id=1')->fetch();
        if (!$this->pdo->query("SELECT GET_LOCK('cloudinterweb_billing_automation',0)")->fetchColumn()) return ['busy' => true];
        $enabled = !empty($settings['enabled']);
        $generated = 0; $sent = 0; $failed = 0;
        $now=$now??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
        try {
            $this->failStaleProcessing();
            if ($enabled && !$paymentsOnly) {
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
                elseif ($days<0) $this->queueOverdue((int)$bill['id'],$days,max(1,(int)$settings['overdue_repeat_days']));
            }
            }
            if ($deliver) {
                $inWindow=self::withinDeliveryWindow($now,$settings['delivery_window_start']??null,$settings['delivery_window_end']??null);
                $transactionalOnly=$paymentsOnly||!$enabled||!$inWindow;
                $notifier=$this->notifier??new NotificationService($this->pdo);
                $channels=array_values(array_filter(array_map('trim',explode(',',(string)$settings['channels']))));
                $jobFilter = $transactionalOnly ? " AND kind='payment'" : '';
                $jobs=$this->pdo->query("SELECT * FROM notification_queue WHERE status='pending' AND next_attempt_at<=NOW()" . $jobFilter . " ORDER BY id LIMIT ".self::MAX_JOBS_PER_RUN)->fetchAll();
                foreach ($jobs as $job) {
                    $claimed = false;
                    $lease = null;
                    try {
                        if (!in_array($job['channel'],$channels,true)) {
                            $this->pdo->prepare("UPDATE notification_queue SET status='cancelled',last_error='Channel disabled.' WHERE id=? AND status='pending'")->execute([$job['id']]);
                            continue;
                        }
                        $query=$this->pdo->prepare("SELECT b.*,c.full_name,c.email,c.contact_number,c.status customer_status,GREATEST(b.amount-COALESCE(p.paid,0),0) outstanding_balance FROM billing b JOIN customers c ON c.id=b.customer_id LEFT JOIN (SELECT billing_id,SUM(amount_paid) paid FROM payments WHERE verification_status='verified' GROUP BY billing_id) p ON p.billing_id=b.id WHERE b.id=?"); $query->execute([$job['billing_id']]); $bill=$query->fetch();
                        if (!$bill) {
                            $this->pdo->prepare("UPDATE notification_queue SET status='failed',failure_class='pre_send',last_error='Billing record not found.' WHERE id=? AND status='pending'")->execute([$job['id']]);
                            $failed++;
                            continue;
                        }
                        $days=(int)(new DateTimeImmutable(date('Y-m-d')))->diff(new DateTimeImmutable($bill['due_date']))->format('%r%a');
                        if ($job['kind'] !== 'payment' && ($bill['customer_status'] ?? '') !== 'active') {
                            $this->pdo->prepare("UPDATE notification_queue SET status='cancelled' WHERE id=? AND status='pending'")->execute([$job['id']]);
                            continue;
                        }
                        if ($job['kind']!=='payment' && $bill['status']==='paid') {
                            $this->pdo->prepare("UPDATE notification_queue SET status='cancelled',last_error='Invoice fully paid.' WHERE id=? AND status='pending'")->execute([$job['id']]);
                            continue;
                        }
                        $expired=($job['kind']==='pre_due' && $days<=0) || ($job['kind']==='due' && $days!==0) || ($job['kind']==='overdue' && $days>=0);
                        if ($job['kind']!=='payment' && ($expired || $bill['due_date']!==$job['due_date'])) {
                            $this->pdo->prepare("UPDATE notification_queue SET status='cancelled' WHERE id=? AND status='pending'")->execute([$job['id']]);
                            continue;
                        }
                        $lease=$this->claimJob((int)$job['id']);
                        if ($lease===null) continue;
                        $claimed=true;
                        if ($job['kind']!=='payment') {
                            $statusCheck=$this->pdo->prepare('SELECT status FROM billing WHERE id=?');
                            $statusCheck->execute([$job['billing_id']]);
                            if ($statusCheck->fetchColumn()==='paid') {
                                $this->cancelJob((int)$job['id'],$lease,'Invoice fully paid.');
                                continue;
                            }
                        }
                        if ($job['kind']==='payment') {
                            $query=$this->pdo->prepare("SELECT * FROM payments WHERE id=? AND billing_id=? AND verification_status='verified'");$query->execute([$job['payment_id'],$bill['id']]);$payment=$query->fetch();
                            $result=$payment ? $notifier->sendPaymentConfirmation($bill,$payment,$job['channel']) : ['success'=>false,'message'=>'Verified payment not found.','retryable'=>false,'ambiguous'=>false,'failure_class'=>'pre_send'];
                        } else $result=$job['kind']==='overdue' ? $notifier->sendOverdueNotification($bill,max(1,(int)(new DateTimeImmutable($bill['due_date']))->diff(new DateTimeImmutable(date('Y-m-d')))->days),$job['channel']) : $notifier->sendReminder($bill,$job['channel'],$job['kind']);
                        $attempt=(int)$job['attempts']+1;
                        if ($result['success']) {
                            if ($this->markSent((int)$job['id'],$lease)) $sent++;
                            continue;
                        }
                        $failed++;
                        $message=(string)($result['message']??'Notification failed.');
                        $ambiguous=!empty($result['ambiguous']);
                        $retryable=!empty($result['retryable']);
                        if ($ambiguous && stripos($message,'Outcome unknown')!==0) $message='Outcome unknown: '.$message.' Review delivery before retrying.';
                        $message=self::truncateError($message);
                        $failureClass=(string)($result['failure_class']??($ambiguous?'post_send_unknown':($retryable?'pre_send':'provider_rejected')));
                        if ($ambiguous || !$retryable || $attempt>=self::MAX_ATTEMPTS) {
                            $this->markFailed((int)$job['id'],$lease,$message,$failureClass);
                        } else {
                            $minutes=self::RETRY_MINUTES[$attempt]??15;
                            $this->scheduleRetry((int)$job['id'],$lease,$message,$minutes);
                        }
                    } catch (Throwable $exception) {
                        if ($claimed && $lease!==null) {
                            try {
                                $message=self::truncateError('Outcome unknown: worker exception: '.$exception->getMessage());
                                if ($this->markFailed((int)$job['id'],$lease,$message,'post_send_unknown')) $failed++;
                            } catch (Throwable $ignored) {}
                        }
                    }
                }
                if (!$inWindow) return ['waiting_for_delivery_window'=>true,'generated'=>$generated,'sent'=>$sent,'failed'=>$failed] + ($enabled?[]:['enabled'=>false]);
            }
            $result=compact('generated','sent','failed');
            if (!$enabled) $result['enabled']=false;
            return $result;
        } finally { $this->pdo->query("SELECT RELEASE_LOCK('cloudinterweb_billing_automation')"); }
    }

    private function failStaleProcessing(): void
    {
        $this->pdo->exec("UPDATE notification_queue SET status='failed',last_error='Outcome unknown: previous send was interrupted. Review delivery before retrying.',failure_class='stale',lease_expires_at=NULL,next_attempt_at=NOW() WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at<NOW()");
    }

    private function claimJob(int $id): ?string
    {
        $stmt=$this->pdo->prepare("UPDATE notification_queue SET status='processing',attempts=attempts+1,processing_started_at=NOW(),lease_expires_at=DATE_ADD(NOW(),INTERVAL ".self::STALE_LEASE_MINUTES." MINUTE),next_attempt_at=NOW() WHERE id=? AND status='pending' AND next_attempt_at<=NOW()");
        $stmt->execute([$id]);
        if ($stmt->rowCount()!==1) return null;
        $stmt=$this->pdo->prepare('SELECT lease_expires_at FROM notification_queue WHERE id=?');
        $stmt->execute([$id]);
        $lease=$stmt->fetchColumn();
        return $lease===false?null:(string)$lease;
    }

    private function markSent(int $id,string $lease): bool
    {
        $stmt=$this->pdo->prepare("UPDATE notification_queue SET status='sent',last_error=NULL,failure_class=NULL,next_attempt_at=NOW(),lease_expires_at=NULL WHERE id=? AND status='processing' AND lease_expires_at=?");
        $stmt->execute([$id,$lease]);
        return $stmt->rowCount()===1;
    }

    private function markFailed(int $id,string $lease,string $message,string $failureClass): bool
    {
        $stmt=$this->pdo->prepare("UPDATE notification_queue SET status='failed',last_error=?,failure_class=?,next_attempt_at=NOW(),lease_expires_at=NULL WHERE id=? AND status='processing' AND lease_expires_at=?");
        $stmt->execute([$message,$failureClass,$id,$lease]);
        return $stmt->rowCount()===1;
    }

    private function scheduleRetry(int $id,string $lease,string $message,int $minutes): bool
    {
        $stmt=$this->pdo->prepare("UPDATE notification_queue SET status='pending',last_error=?,failure_class='pre_send',next_attempt_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),lease_expires_at=NULL WHERE id=? AND status='processing' AND lease_expires_at=?");
        $stmt->execute([$message,$minutes,$id,$lease]);
        return $stmt->rowCount()===1;
    }

    private function cancelJob(int $id,string $lease,string $message): bool
    {
        $stmt=$this->pdo->prepare("UPDATE notification_queue SET status='cancelled',failure_class=NULL,last_error=?,next_attempt_at=NOW(),lease_expires_at=NULL WHERE id=? AND status='processing' AND lease_expires_at=?");
        $stmt->execute([$message,$id,$lease]);
        return $stmt->rowCount()===1;
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
            if(!in_array($channel,['email','sms'],true))continue;
            $this->pdo->prepare("INSERT IGNORE INTO notification_queue(event_key,billing_id,payment_id,kind,channel,due_date) VALUES (?,?,?,'payment',?,?)")->execute(['payment:'.$paymentId.':'.$channel,$invoiceId,$paymentId,$channel,$due]);
        }
    }
}
