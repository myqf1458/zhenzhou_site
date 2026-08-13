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

// 获取客户端 IP（用于限流）
function getClientIpCode() {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}
$clientIp = getClientIpCode();

// IP 限流：同一 IP 60 秒内最多发送 1 次验证码，1 小时内最多 5 次
if ($clientIp) {
    $ipCheckSql = "SELECT COUNT(*) as cnt FROM `email_codes` WHERE client_ip = ? AND create_time > DATE_SUB(NOW(), INTERVAL 60 SECOND)";
    $stmt = mysqli_prepare($conn, $ipCheckSql);
    mysqli_stmt_bind_param($stmt, 's', $clientIp);
    mysqli_stmt_execute($stmt);
    $recentRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($recentRow && $recentRow['cnt'] >= 1) {
        returnJson(400, '请求过于频繁，请60秒后再试');
    }

    $ipHourSql = "SELECT COUNT(*) as cnt FROM `email_codes` WHERE client_ip = ? AND create_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
    $stmt = mysqli_prepare($conn, $ipHourSql);
    mysqli_stmt_bind_param($stmt, 's', $clientIp);
    mysqli_stmt_execute($stmt);
    $hourRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($hourRow && $hourRow['cnt'] >= 5) {
        returnJson(400, '请求次数过多，请稍后再试');
    }
}

/**
 * 读取完整的SMTP响应（处理多行响应）
 */
function readSMTPResponse($socket) {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket);
        $response .= $line;
        if (isset($line[3]) && $line[3] !== '-') {
            break;
        }
    }
    return $response;
}

/**
 * 通过SMTP发送邮件
 */
function sendEmailBySMTP($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $from_email, $to_email, $subject, $message) {
    // 检查SSL扩展
    if (!extension_loaded('openssl')) {
        error_log("SMTP Error: openssl extension not loaded");
        return false;
    }
    
    // 使用stream_socket_client替代fsockopen（更好的SSL支持）
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);
    
    $socket = @stream_socket_client("ssl://{$smtp_host}:{$smtp_port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log("SMTP Error: Failed to connect to {$smtp_host}:{$smtp_port}, errno: {$errno}, errstr: {$errstr}");
        return false;
    }
    
    // 设置超时
    stream_set_timeout($socket, 30);
    
    // 读取服务器响应
    $response = readSMTPResponse($socket);
    if (substr($response, 0, 3) != '220') {
        error_log("SMTP Error: Unexpected greeting: {$response}");
        fclose($socket);
        return false;
    }
    
    // EHLO命令（支持扩展）
    fputs($socket, "EHLO localhost\r\n");
    $response = readSMTPResponse($socket);
    if (substr($response, 0, 3) != '250') {
        error_log("SMTP Error: EHLO failed: {$response}");
        fclose($socket);
        return false;
    }
    
    // AUTH LOGIN命令
    fputs($socket, "AUTH LOGIN\r\n");
    $response = readSMTPResponse($socket);
    if (substr($response, 0, 3) != '334') {
        error_log("SMTP Error: AUTH LOGIN failed: {$response}");
        fclose($socket);
        return false;
    }
    
    // 发送用户名（base64编码）
    fputs($socket, base64_encode($smtp_user) . "\r\n");
    $response = readSMTPResponse($socket);
    if (substr($response, 0, 3) != '334') {
        error_log("SMTP Error: Username rejected: {$response}");
        fclose($socket);
        return false;
    }
    
    // 发送密码（base64编码）
    fputs($socket, base64_encode($smtp_pass) . "\r\n");
    $response = readSMTPResponse($socket);
    if (substr($response, 0, 3) != '235') {
        error_log("SMTP Error: Authentication failed: {$response}");
        fclose($socket);
        return false;
    }
    
    // 发送发件人
    fputs($socket, "MAIL FROM:<{$from_email}>\r\n");
    $response = readSMTPResponse($socket);
    if (substr($response, 0, 3) != '250') {
        error_log("SMTP Error: MAIL FROM failed: {$response}");
        fclose($socket);
        return false;
    }
    
    // 发送收件人
    fputs($socket, "RCPT TO:<{$to_email}>\r\n");
    $response = readSMTPResponse($socket);
    if (substr($response, 0, 3) != '250') {
        error_log("SMTP Error: RCPT TO failed: {$response}");
        fclose($socket);
        return false;
    }
    
    // DATA命令
    fputs($socket, "DATA\r\n");
    $response = readSMTPResponse($socket);
    if (substr($response, 0, 3) != '354') {
        error_log("SMTP Error: DATA failed: {$response}");
        fclose($socket);
        return false;
    }
    
    // 编码主题（处理中文）
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    
    // 构造邮件内容
    $mail_content = "From: Shinamah.cn <{$from_email}>\r\n";
    $mail_content .= "To: <{$to_email}>\r\n";
    $mail_content .= "Subject: {$encoded_subject}\r\n";
    $mail_content .= "MIME-Version: 1.0\r\n";
    $mail_content .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $mail_content .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $mail_content .= base64_encode($message) . "\r\n";
    
    // 发送邮件内容
    fputs($socket, $mail_content . ".\r\n");
    $response = readSMTPResponse($socket);
    
    // 记录DATA响应用于判断发送成功
    $dataResponse = $response;
    
    if (substr($response, 0, 3) != '250') {
        error_log("SMTP Error: Data sending failed: {$response}");
        fclose($socket);
        return false;
    }
    
    // QUIT命令
    fputs($socket, "QUIT\r\n");
    readSMTPResponse($socket);
    
    // 关闭连接
    fclose($socket);
    
    // 检查是否发送成功（基于DATA响应的250）
    return substr($dataResponse, 0, 3) == '250';
}

/**
 * 创建email_codes表
 */
function createEmailCodesTable() {
    global $conn;
    $sql = "CREATE TABLE IF NOT EXISTS `email_codes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `email` varchar(255) NOT NULL,
        `code` varchar(10) NOT NULL,
        `purpose` varchar(20) NOT NULL,
        `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expire_time` datetime NOT NULL,
        `used` tinyint(1) NOT NULL DEFAULT 0,
        `client_ip` varchar(45) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        INDEX `idx_email_purpose` (`email`, `purpose`),
        INDEX `idx_client_ip` (`client_ip`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        mysqli_query($conn, $sql);
    } catch (\mysqli_sql_exception $e) {
        error_log('Create email_codes table error: ' . $e->getMessage());
    }

    // 兼容旧表：若 client_ip 字段不存在则自动添加
    try {
        $checkCol = mysqli_query($conn, "SHOW COLUMNS FROM `email_codes` LIKE 'client_ip'");
        if ($checkCol && mysqli_num_rows($checkCol) == 0) {
            mysqli_query($conn, "ALTER TABLE `email_codes` ADD COLUMN `client_ip` varchar(45) NOT NULL DEFAULT '' AFTER `used`, ADD INDEX `idx_client_ip` (`client_ip`)");
        }
    } catch (\mysqli_sql_exception $e) {
        error_log('Alter email_codes table error: ' . $e->getMessage());
    }
}

// 创建 email_codes 表（如果不存在）
createEmailCodesTable();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    returnJson(400, '仅支持POST提交');
}

$email = trim($_POST['email'] ?? '');
$purpose = trim($_POST['purpose'] ?? '');

if (empty($email) || empty($purpose)) {
    returnJson(400, '邮箱和用途不能为空');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    returnJson(400, '邮箱格式错误');
}

if (!in_array($purpose, ['login', 'register', 'reset'])) {
    returnJson(400, '用途参数错误');
}

// 验证邮箱是否符合要求
if ($purpose == 'register') {
    $checkEmailSql = "SELECT id FROM `user_info` WHERE email = ?";
    $stmt = mysqli_prepare($conn, $checkEmailSql);
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($userRow) {
        returnJson(400, '该邮箱已注册，请直接登录');
    }
} else {
    // login 和 reset 都需要邮箱已注册
    $checkEmailSql = "SELECT id FROM `user_info` WHERE email = ?";
    $stmt = mysqli_prepare($conn, $checkEmailSql);
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$userRow) {
        returnJson(400, '该邮箱未注册');
    }
}

// 检查频率限制（60秒内只能发送一次）
$checkRateSql = "SELECT id, create_time FROM `email_codes` WHERE email = ? AND purpose = ? AND create_time > DATE_SUB(NOW(), INTERVAL 60 SECOND) ORDER BY id DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $checkRateSql);
mysqli_stmt_bind_param($stmt, 'ss', $email, $purpose);
mysqli_stmt_execute($stmt);
$recentCode = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($recentCode) {
    returnJson(400, '发送过于频繁，请稍后再试');
}

// 删除该邮箱之前的过期验证码
$deleteExpiredSql = "DELETE FROM `email_codes` WHERE email = ? AND purpose = ? AND expire_time < NOW()";
$stmt = mysqli_prepare($conn, $deleteExpiredSql);
mysqli_stmt_bind_param($stmt, 'ss', $email, $purpose);
mysqli_stmt_execute($stmt);

// 生成6位验证码
$code = rand(100000, 999999);

// 存储验证码（5分钟过期）
$insertSql = "INSERT INTO `email_codes`(email, code, purpose, expire_time, used, client_ip) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, ?)";
$stmt = mysqli_prepare($conn, $insertSql);
mysqli_stmt_bind_param($stmt, 'ssss', $email, $code, $purpose, $clientIp);

if (!mysqli_stmt_execute($stmt)) {
    returnJson(500, '验证码存储失败');
}

// SMTP 配置由 config.php 提供（SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS）
$smtp_host = parse_url(SMTP_HOST, PHP_URL_HOST) ?: str_replace('ssl://', '', SMTP_HOST);
$smtp_port = SMTP_PORT;
$smtp_user = SMTP_USER;
$smtp_pass = SMTP_PASS;

if (empty($smtp_user) || empty($smtp_pass)) {
    returnJson(500, '邮件服务未配置，请联系管理员');
}

// 发送邮件
$mail_subject = '真昼小站 | '.($purpose == 'login' ? '登录确认码(๑>ᴗ<๑)' : ($purpose == 'register' ? '注册确认码ヽ(｡>﹏<｡)ﾉ' : '密码重置码｡•́︿•̀｡'));
$mail_message = "Hi～(๑˃̵ᴗ˂̵)و\n验证码：{$code}\n只有5分钟有效期，抓紧使用！\n不要转发、不要泄露给别人，谨防账号被盗哦";

$mailResult = sendEmailBySMTP($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_user, $email, $mail_subject, $mail_message);

if ($mailResult) {
    returnJson(200, '验证码已发送，请注意查收');
} else {
    error_log("Failed to send email to: {$email}, code: {$code}, purpose: {$purpose}");
    returnJson(500, '邮件发送失败，请稍后重试');
}
