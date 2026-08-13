<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();

// 必须已登录且处于绑定模式
if (!isset($_SESSION['uid']) || empty($_SESSION['qq_bind_mode'])) {
    header('Location: profile.html?qq_bind_error=auth');
    exit;
}

// 加载QQ配置
$configFile = __DIR__ . '/qq_oauth_config.php';
if (!file_exists($configFile)) {
    header('Location: profile.html?qq_bind_error=config');
    exit;
}
$qqConfig = include $configFile;
if (!is_array($qqConfig) || empty($qqConfig['enabled']) || empty($qqConfig['app_id'])) {
    header('Location: profile.html?qq_bind_error=disabled');
    exit;
}

$appId = $qqConfig['app_id'];
$appKey = $qqConfig['app_key'];
$redirectUri = $qqConfig['redirect_uri'];
if (empty($redirectUri)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $redirectUri = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/qq_bind_callback.php';
} else {
    $redirectUri = preg_replace('/qq_callback\.php$/', 'qq_bind_callback.php', $redirectUri);
}

// 检查回调参数
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    header('Location: profile.html?qq_bind_error=cancelled');
    exit;
}
if (empty($code) || empty($state)) {
    header('Location: profile.html?qq_bind_error=param');
    exit;
}
// 验证 state 防 CSRF
if (!isset($_SESSION['qq_state']) || $_SESSION['qq_state'] !== $state) {
    header('Location: profile.html?qq_bind_error=state');
    exit;
}
unset($_SESSION['qq_state']);

// 换取 access_token
$tokenUrl = 'https://graph.qq.com/oauth2.0/token';
$tokenParams = [
    'grant_type' => 'authorization_code',
    'client_id' => $appId,
    'client_secret' => $appKey,
    'code' => $code,
    'redirect_uri' => $redirectUri,
    'fmt' => 'json'
];

$tokenResponse = httpGetBind($tokenUrl . '?' . http_build_query($tokenParams));
if (!$tokenResponse) {
    header('Location: profile.html?qq_bind_error=network');
    exit;
}
$tokenData = json_decode($tokenResponse, true);
if (!$tokenData || isset($tokenData['error'])) {
    error_log('QQ bind token error: ' . json_encode($tokenData));
    header('Location: profile.html?qq_bind_error=token');
    exit;
}
$accessToken = $tokenData['access_token'];

// 获取 openid
$openidResponse = httpGetBind('https://graph.qq.com/oauth2.0/me?access_token=' . $accessToken . '&fmt=json');
if (!$openidResponse) {
    header('Location: profile.html?qq_bind_error=network');
    exit;
}
$openidData = json_decode($openidResponse, true);
if (!$openidData || isset($openidData['error'])) {
    header('Location: profile.html?qq_bind_error=openid');
    exit;
}
$openid = $openidData['openid'];

// 连接数据库
require_once 'db.php';

$uid = intval($_SESSION['uid']);

// 确保字段存在
ensureQqColumnBind($conn);

// 检查该QQ是否已被其他账号绑定
$checkStmt = mysqli_prepare($conn, "SELECT id FROM user_info WHERE qq_openid = ? AND id != ? LIMIT 1");
mysqli_stmt_bind_param($checkStmt, 'si', $openid, $uid);
mysqli_stmt_execute($checkStmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt))) {
    header('Location: profile.html?qq_bind_error=occupied');
    exit;
}

// 获取QQ用户信息用于更新昵称和头像
$userInfoUrl = 'https://graph.qq.com/user/get_user_info?' . http_build_query([
    'access_token' => $accessToken,
    'oauth_consumer_key' => $appId,
    'openid' => $openid,
    'fmt' => 'json'
]);
$userInfoResponse = httpGetBind($userInfoUrl);
$nickname = 'QQ用户';
$qqAvatar = '';
if ($userInfoResponse) {
    $userInfo = json_decode($userInfoResponse, true);
    if ($userInfo && (!isset($userInfo['ret']) || $userInfo['ret'] == 0)) {
        $nickname = $userInfo['nickname'] ?? 'QQ用户';
        $qqAvatar = $userInfo['figureurl_qq_2'] ?: ($userInfo['figureurl_qq_1'] ?: ($userInfo['figureurl_1'] ?? ''));
    }
}

// 绑定QQ到当前账号
if ($qqAvatar) {
    $bindStmt = mysqli_prepare($conn, "UPDATE user_info SET qq_openid = ?, nickname = ?, avatar = ? WHERE id = ?");
    mysqli_stmt_bind_param($bindStmt, 'sssi', $openid, $nickname, $qqAvatar, $uid);
} else {
    $bindStmt = mysqli_prepare($conn, "UPDATE user_info SET qq_openid = ?, nickname = ? WHERE id = ?");
    mysqli_stmt_bind_param($bindStmt, 'ssi', $openid, $nickname, $uid);
}

if (mysqli_stmt_execute($bindStmt)) {
    unset($_SESSION['qq_bind_mode']);
    $_SESSION['login_method'] = 'qq';
    header('Location: profile.html?qq_bind_success=1');
    exit;
}

error_log('QQ bind failed: uid=' . $uid . ' err=' . mysqli_error($conn));
header('Location: profile.html?qq_bind_error=db');
exit;

// ===== 辅助函数 =====

function ensureQqColumnBind($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $check = @mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'qq_openid'");
    if ($check && mysqli_num_rows($check) == 0) {
        @mysqli_query($conn, "ALTER TABLE user_info ADD COLUMN qq_openid VARCHAR(100) DEFAULT NULL AFTER gender");
        @mysqli_query($conn, "ALTER TABLE user_info ADD INDEX idx_qq_openid (qq_openid)");
    }
    $check2 = @mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'nickname'");
    if ($check2 && mysqli_num_rows($check2) == 0) {
        @mysqli_query($conn, "ALTER TABLE user_info ADD COLUMN nickname VARCHAR(50) DEFAULT '' AFTER username");
    }
}

function httpGetBind($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'header' => "User-Agent: Mozilla/5.0 (compatible; QQBind/1.0)\r\n",
            'follow_location' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response !== false) return $response;

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; QQBind/1.0)');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode == 200 && $response !== false) return $response;
    }
    error_log('QQ bind httpGet failed: ' . $url);
    return false;
}
