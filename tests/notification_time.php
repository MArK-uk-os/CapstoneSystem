<?php
require __DIR__.'/../includes/BillingAutomation.php';
function checkWindow(bool $actual,bool $expected):void {if($actual!==$expected)throw new RuntimeException('Delivery window check failed.');}
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T07:59:59+08:00')),false);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T08:00:00+08:00')),true);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T12:00:00+08:00')),true);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T19:59:59+08:00')),true);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T20:00:00+08:00')),false);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T23:00:00+08:00')),false);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T01:00:00Z')),true);
checkWindow(BillingAutomation::withinDeliveryWindow(new DateTimeImmutable('2026-10-11T12:00:00Z')),false);
echo "PASS: delivery window boundaries, timezone conversion, and outside-window retention.\n";
