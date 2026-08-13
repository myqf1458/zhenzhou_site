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
require_once 'db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    returnJson(400, '仅支持POST提交');
}

// 获取客户端 IP（用于安全审计）
function getClientIp() {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    // 取第一个 IP（可能有多个代理）
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

$username = trim($_POST['name'] ?? '');
$email = trim($_POST['Email'] ?? '');
$password = trim($_POST['Password'] ?? '');
$avatar = trim($_POST['avatar'] ?? '');
$code = trim($_POST['code'] ?? '');

// 输入长度限制（防止超长输入攻击）
if (mb_strlen($username) > 20) {
    returnJson(400, '用户名长度不能超过20个字符');
}
if (strlen($password) > 64) {
    returnJson(400, '密码长度不能超过64个字符');
}
// 头像字段仅允许 QQ 号或 URL，过滤危险字符
$avatar = preg_replace('/[<>"\']/', '', $avatar);
if (mb_strlen($avatar) > 255) {
    returnJson(400, '头像参数过长');
}

if (empty($username) || empty($email) || empty($password) || empty($code)) {
    returnJson(400, '用户名、邮箱、密码和验证码不能为空');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    returnJson(400, '邮箱格式错误');
}
if (strlen($password) < 6) {
    returnJson(400, '密码长度不能少于6位');
}
// 用户名过滤危险字符
$username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

// 验证验证码
$codeSql = "SELECT id, used FROM `email_codes` WHERE email = ? AND purpose = 'register' AND code = ? AND expire_time > NOW() ORDER BY id DESC LIMIT 1";
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

$checkSql = "SELECT id FROM `user_info` WHERE email = ?";
$stmt = mysqli_prepare($conn, $checkSql);
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if ($row) {
    returnJson(400, '该邮箱已注册，请直接登录');
}
$hashPwd = password_hash($password, PASSWORD_DEFAULT);
$insertSql = "INSERT INTO `user_info`(username, email, password, avatar) VALUES (?, ?, ?, ?)";
$stmt = mysqli_prepare($conn, $insertSql);
mysqli_stmt_bind_param($stmt, 'ssss', $username, $email, $hashPwd, $avatar);
if (mysqli_stmt_execute($stmt)) {
    // 注册成功不返回 email，防止信息泄露
    returnJson(200, '注册成功，请登录', [
        'uid' => mysqli_insert_id($conn)
    ]);
} else {
    // 不暴露具体数据库错误
    error_log('Register Failed: ' . mysqli_error($conn));
    returnJson(500, '注册失败，请稍后重试', []);
}
