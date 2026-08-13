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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    returnJson(400, '仅支持GET请求');
}
if (!isset($_SESSION['uid'])) {
    returnJson(401, '未登录');
}
$uid = intval($_SESSION['uid']);
if ($uid <= 0) {
    returnJson(401, '未登录');
}

// 用 SELECT * 避免 gender/create_time 等字段缺失导致 prepare 失败
$sql = "SELECT * FROM `user_info` WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    error_log('get_profile prepare failed: uid=' . $uid . ' err=' . mysqli_error($conn));
    returnJson(500, '查询失败，请稍后重试');
}
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$user) {
    error_log('get_profile user not found: uid=' . $uid);
    returnJson(404, '用户不存在');
}

// 邮箱脱敏：保留首字符与 @ 后域名，中间用 * 代替
$email = isset($user['email']) ? $user['email'] : '';
if ($email && strpos($email, '@') !== false) {
    $atPos = strpos($email, '@');
    $namePart = substr($email, 0, $atPos);
    $domainPart = substr($email, $atPos);
    $nameLen = strlen($namePart);
    if ($nameLen <= 1) {
        $maskName = $namePart . '*';
    } elseif ($nameLen <= 3) {
        $maskName = substr($namePart, 0, 1) . str_repeat('*', $nameLen - 1);
    } else {
        $maskName = substr($namePart, 0, 2) . str_repeat('*', $nameLen - 2);
    }
    $maskEmail = $maskName . $domainPart;
} else {
    $maskEmail = '';
}

$username = isset($user['username']) ? $user['username'] : '';
$nickname = isset($user['nickname']) && $user['nickname'] !== '' ? $user['nickname'] : ($username ?: '用户');

// QQ绑定信息
$qqOpenid = isset($user['qq_openid']) ? $user['qq_openid'] : '';
$qqId = $qqOpenid !== '' ? '已绑定' : '';
$qqName = $nickname;

returnJson(200, 'ok', [
    'uid' => intval($user['id']),
    'username' => $username,
    'nickname' => $nickname,
    'qq_name' => $qqName,
    'qq_id' => $qqId,
    'avatar' => isset($user['avatar']) ? $user['avatar'] : '',
    'email' => $maskEmail,
    'gender' => isset($user['gender']) ? $user['gender'] : 'unknown',
    'create_time' => isset($user['create_time']) ? $user['create_time'] : ''
]);
