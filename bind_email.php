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

$email = trim($_POST['email'] ?? '');
$code = trim($_POST['code'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    returnJson(400, '请输入合法的邮箱地址');
}
if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
    returnJson(400, '请输入6位数字验证码');
}

// 校验验证码
if (!isset($_SESSION['email_code']) || !isset($_SESSION['email_code_email']) || !isset($_SESSION['email_code_expire'])) {
    returnJson(400, '请先获取验证码');
}
if (time() > $_SESSION['email_code_expire']) {
    unset($_SESSION['email_code'], $_SESSION['email_code_email'], $_SESSION['email_code_expire']);
    returnJson(400, '验证码已过期，请重新获取');
}
if ($_SESSION['email_code_email'] !== $email) {
    returnJson(400, '邮箱与验证码不匹配');
}
if ($_SESSION['email_code'] !== $code) {
    returnJson(400, '验证码错误');
}

// 检查邮箱是否已被其他用户绑定
$checkStmt = mysqli_prepare($conn, "SELECT id FROM user_info WHERE email = ? AND id != ? LIMIT 1");
mysqli_stmt_bind_param($checkStmt, 'si', $email, $uid);
mysqli_stmt_execute($checkStmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt))) {
    returnJson(400, '该邮箱已被其他账号绑定');
}

// 更新邮箱
$updateStmt = mysqli_prepare($conn, "UPDATE user_info SET email = ? WHERE id = ?");
mysqli_stmt_bind_param($updateStmt, 'si', $email, $uid);

if (mysqli_stmt_execute($updateStmt)) {
    // 清除验证码session
    unset($_SESSION['email_code'], $_SESSION['email_code_email'], $_SESSION['email_code_time'], $_SESSION['email_code_expire']);
    returnJson(200, '邮箱换绑成功');
} else {
    error_log('BindEmail Failed: uid=' . $uid . ' err=' . mysqli_error($conn));
    returnJson(500, '换绑失败，请稍后重试');
}
