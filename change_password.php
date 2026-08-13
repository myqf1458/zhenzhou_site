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
    returnJson(400, '仅支持POST提交');
}
if (!isset($_SESSION['uid'])) {
    returnJson(401, '未登录，请先登录');
}
$uid = intval($_SESSION['uid']);

$oldPwd = trim($_POST['old_pwd'] ?? '');
$newPwd = trim($_POST['new_pwd'] ?? '');

if ($oldPwd === '' || $newPwd === '') {
    returnJson(400, '原密码和新密码不能为空');
}
if (strlen($oldPwd) > 64 || strlen($newPwd) > 64) {
    returnJson(400, '密码长度不能超过64位');
}
if (strlen($newPwd) < 6) {
    returnJson(400, '新密码至少6位');
}
if ($oldPwd === $newPwd) {
    returnJson(400, '新密码不能与原密码相同');
}

$sql = "SELECT password FROM `user_info` WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$user) {
    returnJson(400, '用户不存在');
}

if (!password_verify($oldPwd, $user['password'])) {
    // 记录失败日志用于安全审计
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($clientIp, ',') !== false) $clientIp = trim(explode(',', $clientIp)[0]);
    error_log('ChangePwd Fail: uid=' . $uid . ' ip=' . $clientIp . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    returnJson(400, '原密码错误');
}

$newHash = password_hash($newPwd, PASSWORD_DEFAULT);
$updateSql = "UPDATE `user_info` SET password = ? WHERE id = ?";
$stmt = mysqli_prepare($conn, $updateSql);
mysqli_stmt_bind_param($stmt, 'si', $newHash, $uid);
if (mysqli_stmt_execute($stmt)) {
    returnJson(200, '密码修改成功');
} else {
    error_log('ChangePwd Failed: uid=' . $uid . ' err=' . mysqli_error($conn));
    returnJson(500, '修改失败，请稍后重试');
}
