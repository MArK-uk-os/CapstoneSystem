<?php
require_once __DIR__ . '/includes/auth.php';
header('Location: ' . (isLoggedIn() ? '/modules/dashboard/index.php' : '/modules/auth/login.php'));
exit;
