<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');
$config = require __DIR__ . '/../../config/notifications.php';
$chats = [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    if (!$config['telegram_bot_token'] || !function_exists('curl_init')) {
        $error = 'Configure the Telegram bot token and PHP cURL first.';
    } else {
        $request = curl_init('https://api.telegram.org/bot' . $config['telegram_bot_token'] . '/getUpdates');
        curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $body = curl_exec($request);
        $networkError = curl_error($request);
        $httpCode = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        $data = json_decode($body ?: '', true);
        if (!($data['ok'] ?? false)) {
            $error = $networkError !== ''
                ? 'Telegram connection failed: ' . str_replace($config['telegram_bot_token'], '[redacted]', $networkError)
                : 'Telegram returned HTTP ' . $httpCode . ': ' . ($data['description'] ?? 'Invalid API response.');
        }
        foreach ($data['result'] ?? [] as $update) {
            $chat = $update['message']['chat'] ?? [];
            if (($chat['type'] ?? '') === 'private') $chats[$chat['id']] = $chat;
        }
        if (!$chats && !$error) $error = 'No recent chats found. Send a new message to @CloudInterBot, then refresh here.';
    }
}
$pageTitle = 'Telegram Chats';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<h3>Link a Telegram customer</h3>
<p>Ask the customer to open <a href="https://t.me/CloudInterBot" target="_blank" rel="noopener">@CloudInterBot</a>, press Start and send a new message. Refresh below, confirm their identity, then copy their chat ID into Edit Customer.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><button class="btn btn-primary">Refresh Telegram chats</button></form>
<?php if ($error): ?><p class="mt-3"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<table class="table mt-3"><thead><tr><th>Name</th><th>Username</th><th>Chat ID</th></tr></thead><tbody>
<?php foreach ($chats as $chat): ?><tr><td><?= htmlspecialchars(trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))) ?></td><td><?= htmlspecialchars($chat['username'] ?? '') ?></td><td><?= htmlspecialchars((string) $chat['id']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
