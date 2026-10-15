<?php
require __DIR__.'/../includes/notifications/NotificationService.php';
function verifyHttpSms(bool $condition): void { if(!$condition)throw new RuntimeException('httpSMS validation failed.'); }
verifyHttpSms(NotificationService::normalizeSmsNumber('0917 123 4567') === '+639171234567');
verifyHttpSms(NotificationService::normalizeSmsNumber('+63 917-123-4567') === '+639171234567');
verifyHttpSms(NotificationService::normalizeSmsNumber('not a number') === null);
verifyHttpSms(NotificationService::httpSmsAccepted(200,['status'=>'success','data'=>['id'=>'sample','status'=>'pending']]));
verifyHttpSms(!NotificationService::httpSmsAccepted(401,['status'=>'error']));
verifyHttpSms(!NotificationService::httpSmsAccepted(200,['status'=>'error','data'=>['id'=>'sample']]));
verifyHttpSms(!NotificationService::httpSmsAccepted(200,['status'=>'success','data'=>['id'=>'sample','status'=>'failed']]));
verifyHttpSms(!NotificationService::httpSmsAccepted(200,['status'=>'success','data'=>[]]));
echo "PASS: phone normalization and httpSMS acceptance/error validation. No SMS sent.\n";
