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
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    returnJson(400, '仅支持POST请求');
}
if (!isset($_SESSION['uid'])) {
    returnJson(401, '未登录，请先登录');
}
$uid = intval($_SESSION['uid']);

// 检查用户是否有密码（防止QQ注册用户解绑后无法登录）
$stmt = mysqli_prepare($conn, "SELECT password, qq_openid FROM user_info WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$user) {
    returnJson(400, '用户不存在');
}
if (empty($user['qq_openid'])) {
    returnJson(400, '当前账号未绑定QQ');
}

// 检查是否有可用密码（QQ自动注册的用户密码是随机的）
$hasPassword = !empty($user['password']);
$isQQRegistered = !$hasPassword || (isset($_SESSION['login_method']) && $_SESSION['login_method'] === 'qq' && strpos($user['username'] ?? '', 'qq_') === 0);

if ($isQQRegistered && !$hasPassword) {
    returnJson(400, '该账号通过QQ注册，解绑前请先设置登录密码');
}

// 清除QQ绑定
$updateStmt = mysqli_prepare($conn, "UPDATE user_info SET qq_openid = NULL WHERE id = ?");
mysqli_stmt_bind_param($updateStmt, 'i', $uid);

if (mysqli_stmt_execute($updateStmt)) {
    // 清除登录方式标记
    unset($_SESSION['login_method']);
    returnJson(200, 'QQ解绑成功');
} else {
    error_log('UnbindQQ Failed: uid=' . $uid . ' err=' . mysqli_error($conn));
    returnJson(500, '解绑失败，请稍后重试');
}
