<?php
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

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/smtp_error.log');

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
session_set_cookie_params([
    'lifetime' => 86400,
    'secure' => $isSecure,
    'samesite' => $isSecure ? 'None' : 'Lax',
    'httponly' => true
]);
session_start();
require_once 'db.php';

// SMTP 配置由 config.php 提供（SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS / SMTP_FROM_NAME）
if (!defined('SMTP_AUTH_CODE')) define('SMTP_AUTH_CODE', SMTP_PASS); // 兼容别名
if (empty(SMTP_USER) || empty(SMTP_PASS)) {
    returnJson(500, '邮件服务未配置，请联系管理员');
}

function sendSmtpEmail(string $toMail, string $subject, string $body): bool
{
    $socket = fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 15);
    if (!$socket) {
        error_log("连接失败: {$errno} {$errstr}");
        return false;
    }
    stream_set_timeout($socket, 15);
    $read = fn()=>trim(fgets($socket,1024));
    $write = fn($c)=>fwrite($socket,$c."\r\n");

    if(!str_starts_with($read(),'220')){fclose($socket);return false;}
    $write("EHLO " . parse_url(site_cors_origin(), PHP_URL_HOST));
    while(true){$l=$read();if(str_starts_with($l,'250 '))break;}

    $write("AUTH LOGIN");$read();
    $write(base64_encode(SMTP_USER));$read();
    $write(base64_encode(SMTP_AUTH_CODE));
    if(!str_starts_with($read(),'235')){fclose($socket);return false;}

    $write("MAIL FROM:<".SMTP_USER.">");$read();
    $write("RCPT TO:<{$toMail}>");$read();
    $write("DATA");$read();

    $header = "From: ".SMTP_FROM_NAME." <".SMTP_USER.">\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nContent-Type:text/plain;charset=utf-8\r\n\r\n";
    $write($header.$body."\r\n.\r\n");
    $res = $read();
    $write("QUIT");fclose($socket);
    return str_starts_with($res,'250');
}

if($_SERVER['REQUEST_METHOD'] !== 'POST') returnJson(400,'仅支持POST提交');
if(!isset($_SESSION['uid'])) returnJson(401,'未登录');

$email = trim($_POST['email']??'');
if(!filter_var($email,FILTER_VALIDATE_EMAIL)) returnJson(400,'邮箱格式错误');

if(isset($_SESSION['email_code_time']) && time()-$_SESSION['email_code_time']<60){
    returnJson(400,'发送过于频繁，请稍后重试');
}

$uid = $_SESSION['uid'];
$stmt = mysqli_prepare($conn,"SELECT id FROM user_info WHERE email=? AND id!=? LIMIT 1");
mysqli_stmt_bind_param($stmt,'si',$email,$uid);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
if(mysqli_stmt_num_rows($stmt)){
    mysqli_stmt_close($stmt);
    returnJson(400,'该邮箱已被绑定');
}
mysqli_stmt_close($stmt);

$code = str_pad(random_int(100000,999999),6,'0',STR_PAD_LEFT);
$_SESSION['email_code'] = $code;
$_SESSION['email_code_email'] = $email;
$_SESSION['email_code_time'] = time();
$_SESSION['email_code_expire'] = time() + 600; // 10分钟有效

$title = '【真昼小站】换绑验证码';
$content = "验证码：{$code}\n10分钟内有效，请勿泄露。";

if(sendSmtpEmail($email,$title,$content)){
    returnJson(200,'验证码已发送，请注意查收');
}else{
    returnJson(500,'邮件发送失败，请重试');
}
