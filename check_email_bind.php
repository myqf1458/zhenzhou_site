<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: " . site_cors_origin());
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
session_set_cookie_params([
    'lifetime' => 86400,
    'secure' => $isSecure,
    'samesite' => $isSecure ? 'None' : 'Lax',
    'httponly' => true
]);
session_start();

// 未登录不需要绑定
if (!isset($_SESSION['uid'])) {
    echo json_encode(['code' => 200, 'need_bind' => false]);
    exit;
}

// 检查 session 标记
if (!empty($_SESSION['need_email_bind'])) {
    echo json_encode(['code' => 200, 'need_bind' => true]);
    exit;
}

// 双重检查：如果邮箱是 @qq.local 占位符，也需要绑定
require_once 'db.php';
$uid = intval($_SESSION['uid']);
$stmt = mysqli_prepare($conn, "SELECT email FROM user_info WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($user) {
    $email = $user['email'] ?? '';
    if ($email === '' || strpos($email, '@qq.local') !== false) {
        echo json_encode(['code' => 200, 'need_bind' => true]);
    } else {
        // 已绑定真实邮箱，清除标记
        unset($_SESSION['need_email_bind']);
        echo json_encode(['code' => 200, 'need_bind' => false]);
    }
} else {
    echo json_encode(['code' => 200, 'need_bind' => false]);
}
