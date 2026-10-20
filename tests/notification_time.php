<?php
require __DIR__.'/../includes/BillingAutomation.php';
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function checkWindow(bool $actual,bool $expected,string $message='Delivery window check failed.'):void {check($actual===$expected,$message);}
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T07:59:59+08:00')),false);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T08:00:00+08:00')),true);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T12:00:00+08:00')),true);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T19:59:59+08:00')),true);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T20:00:00+08:00')),false);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T23:00:00+08:00')),false);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T01:00:00Z')),true);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T12:00:00Z')),false);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T08:59:59+08:00'),'09:00','17:00'),false,'Custom window must start inclusively.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T09:00:00+08:00'),'09:00','17:00'),true,'Custom window start must be inside.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T16:59:59+08:00'),'09:00','17:00'),true,'Custom window before end must be inside.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T17:00:00+08:00'),'09:00','17:00'),false,'Custom window end must be exclusive.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T19:59:59+08:00'),'20:00','06:00'),false,'Overnight window must start inclusively.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T20:00:00+08:00'),'20:00','06:00'),true,'Overnight window start must be inside.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T23:59:59+08:00'),'20:00','06:00'),true,'Overnight window before midnight must be inside.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T00:00:00+08:00'),'20:00','06:00'),true,'Overnight window after midnight must be inside.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T05:59:59+08:00'),'20:00','06:00'),true,'Overnight window before end must be inside.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T06:00:00+08:00'),'20:00','06:00'),false,'Overnight window end must be exclusive.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T12:00:00+08:00'),'20:00','06:00'),false,'Overnight window daytime must be outside.');
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T01:00:00Z'),'09:00','17:00'),true,'Custom window must evaluate in Asia/Manila.');
check(BillingAutomation::isValidDeliveryWindow('20:00','06:00'),'Overnight windows must be valid.');
check(!BillingAutomation::isValidDeliveryWindow('08:00','08:00'),'Identical delivery times must be rejected.');
check(!BillingAutomation::isValidDeliveryTime('8:00'),'Unpadded delivery times must be rejected.');
echo "PASS: default and custom delivery windows, overnight boundaries, timezone conversion, and identical-time rejection.\n";
