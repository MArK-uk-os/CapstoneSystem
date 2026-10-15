<?php
require __DIR__.'/../includes/BillingAutomation.php';
$s=['enabled'=>1,'send_time'=>'08:00:00','last_scheduled_date'=>null];
function checkTime(bool $actual,bool $expected):void {if($actual!==$expected)throw new RuntimeException('Daily schedule check failed.');}
checkTime(BillingAutomation::dailyRunDue($s,new DateTimeImmutable('2026-10-11T07:59:00+08:00')),false);
checkTime(BillingAutomation::dailyRunDue($s,new DateTimeImmutable('2026-10-11T08:00:00+08:00')),true);
checkTime(BillingAutomation::dailyRunDue($s,new DateTimeImmutable('2026-10-11T12:00:00+08:00')),true);
$s['last_scheduled_date']='2026-10-11';
checkTime(BillingAutomation::dailyRunDue($s,new DateTimeImmutable('2026-10-11T12:00:00+08:00')),false);
checkTime(BillingAutomation::dailyRunDue($s,new DateTimeImmutable('2026-10-12T00:00:00Z')),true);
$s['send_time']='16:30:00';
checkTime(BillingAutomation::dailyRunDue($s,new DateTimeImmutable('2026-10-12T16:29:00+08:00')),false);
checkTime(BillingAutomation::dailyRunDue($s,new DateTimeImmutable('2026-10-12T16:30:00+08:00')),true);
$s['enabled']=0;
checkTime(BillingAutomation::dailyRunDue($s,new DateTimeImmutable('2026-10-12T17:00:00+08:00')),false);
echo "PASS: daily time, custom time, timezone, catch-up, duplicate prevention, and disabled schedule.\n";
