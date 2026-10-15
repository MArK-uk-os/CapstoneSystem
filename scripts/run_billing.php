<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/BillingAutomation.php';
try {
    $engine=new BillingAutomation(getDbConnection());
    echo json_encode(in_array('--scheduled',$argv,true)?$engine->runScheduled():$engine->run(!in_array('--queue-only',$argv,true))) . PHP_EOL;
}
catch (Throwable $e) { fwrite(STDERR,'Billing automation failed: ' . $e->getMessage() . PHP_EOL); exit(1); }
