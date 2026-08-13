<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: " . site_cors_origin());
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
// 安全响应头
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
    returnJson(400, '仅支持POST提交');
}
$email = trim($_POST['Email'] ?? '');
$pwd = trim($_POST['Password'] ?? '');
$code = trim($_POST['code'] ?? '');
if (empty($email) || empty($pwd) || empty($code)) {
    returnJson(400, '邮箱、密码和验证码不能为空');
}
// 邮箱格式校验
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    returnJson(400, '邮箱格式错误');
}
// 密码长度限制（防止超长输入攻击）
if (strlen($pwd) > 64) {
    returnJson(400, '密码长度不能超过64位');
}

// 验证验证码
$codeSql = "SELECT id, used FROM `email_codes` WHERE email = ? AND purpose = 'login' AND code = ? AND expire_time > NOW() ORDER BY id DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $codeSql);
mysqli_stmt_bind_param($stmt, 'ss', $email, $code);
mysqli_stmt_execute($stmt);
$codeRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$codeRow) {
    returnJson(400, '验证码无效或已过期');
}

if ($codeRow['used'] == 1) {
    returnJson(400, '验证码已使用，请重新获取');
}

// 标记验证码为已使用
$updateCodeSql = "UPDATE `email_codes` SET used = 1 WHERE id = ?";
$stmt = mysqli_prepare($conn, $updateCodeSql);
mysqli_stmt_bind_param($stmt, 'i', $codeRow['id']);
mysqli_stmt_execute($stmt);

$sql = "SELECT id, username, nickname, password, avatar FROM `user_info` WHERE email = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$user) {
    returnJson(400, '该邮箱未注册');
}
if (!password_verify($pwd, $user['password'])) {
    // 记录登录失败日志（用于安全审计）
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($clientIp, ',') !== false) $clientIp = trim(explode(',', $clientIp)[0]);
    error_log('Login Fail: email=' . $email . ' ip=' . $clientIp . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    returnJson(400, '密码错误');
}
$_SESSION['uid'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['email'] = $email;
// 登录成功不返回 email，防止信息泄露
returnJson(200, '登录成功', [
    'uid' => $user['id'],
    'username' => $user['username'],
    'nickname' => $user['nickname'] ?: $user['username'],
    'avatar' => $user['avatar'] ?: ''
]);
