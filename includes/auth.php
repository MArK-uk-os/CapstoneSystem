<?php
date_default_timezone_set('Asia/Manila');
// Session bootstrap + auth guard. Include at the very top of protected pages.
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

require_once __DIR__ . '/../config/database.php';

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /modules/auth/login.php');
        exit;
    }
}

function hasRole(string ...$roles): bool
{
    return isLoggedIn() && in_array($_SESSION['role'], $roles, true);
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!hasRole(...$roles)) {
        http_response_code(403);
        echo '<div style="font-family:sans-serif;padding:2rem;">'
            . '<h3>403 Forbidden</h3><p>You do not have permission to access this page.</p>'
            . '<a href="/modules/dashboard/index.php">Back to Dashboard</a></div>';
        exit;
    }
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    static $photoByUser = [];
    $userId = (int) $_SESSION['user_id'];
    if (!array_key_exists($userId, $photoByUser)) {
        $statement = getDbConnection()->prepare('SELECT profile_photo FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $photoByUser[$userId] = $statement->fetchColumn() ?: null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'role' => $_SESSION['role'],
        'profile_photo' => $photoByUser[$userId],
    ];
}

// Basic CSRF token helpers
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
