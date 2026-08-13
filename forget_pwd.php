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
$email = trim($_POST['email'] ?? '');
$code = trim($_POST['code'] ?? '');
$newPwd = trim($_POST['new_pwd'] ?? '');
if (empty($email) || empty($code) || empty($newPwd)) {
    returnJson(400, '邮箱、验证码和新密码不能为空');
}
// 邮箱格式校验
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    returnJson(400, '邮箱格式错误');
}
// 密码长度限制
if (strlen($newPwd) > 64) {
    returnJson(400, '密码长度不能超过64位');
}
if (strlen($newPwd) < 6) {
    returnJson(400, '新密码至少6位');
}

// 验证验证码
$codeSql = "SELECT id, used FROM `email_codes` WHERE email = ? AND purpose = 'reset' AND code = ? AND expire_time > NOW() ORDER BY id DESC LIMIT 1";
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
// 校验邮箱存在
$checkSql = "SELECT id FROM user_info WHERE email = ?";
$stmt = mysqli_prepare($conn, $checkSql);
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$user) {
    returnJson(400, '该邮箱未注册');
}
// 更新密码
$newHash = password_hash($newPwd, PASSWORD_DEFAULT);
$updateSql = "UPDATE user_info SET password = ? WHERE email = ?";
$stmt = mysqli_prepare($conn, $updateSql);
mysqli_stmt_bind_param($stmt, 'ss', $newHash, $email);
if (mysqli_stmt_execute($stmt)) {
    returnJson(200, '密码重置成功，使用新密码登录');
} else {
    returnJson(500, '重置失败');
}
?>
