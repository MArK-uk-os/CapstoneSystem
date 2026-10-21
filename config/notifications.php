<?php

$config = [
    'sms_provider' => getenv('SMS_PROVIDER') ?: 'semaphore',
    'httpsms_api_key' => getenv('HTTPSMS_API_KEY') ?: '',
    'httpsms_from' => getenv('HTTPSMS_FROM') ?: '',
    'infobip_api_key' => getenv('INFOBIP_API_KEY') ?: '',
    'infobip_base_url' => getenv('INFOBIP_BASE_URL') ?: '',
    'infobip_sender' => getenv('INFOBIP_SENDER') ?: '',
    'semaphore_api_key' => getenv('SEMAPHORE_API_KEY') ?: '',
    'semaphore_sender_name' => getenv('SEMAPHORE_SENDER_NAME') ?: 'Cloud Interweb',
    'smtp_host' => getenv('SMTP_HOST') ?: '',
    'smtp_port' => (int) (getenv('SMTP_PORT') ?: 587),
    'smtp_username' => getenv('SMTP_USERNAME') ?: '',
    'smtp_password' => getenv('SMTP_PASSWORD') ?: '',
    'smtp_from_email' => getenv('SMTP_FROM_EMAIL') ?: '',
    'smtp_from_name' => getenv('SMTP_FROM_NAME') ?: 'Cloud Interweb',
];

$localConfig = __DIR__ . '/notifications.local.php';
if (is_file($localConfig)) {
    $config = array_replace($config, require $localConfig);
}

return $config;
