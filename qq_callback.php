<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();

// 加载QQ配置
$configFile = __DIR__ . '/qq_oauth_config.php';
if (!file_exists($configFile)) {
    header('Location: Login.html?qq_error=config');
    exit;
}
$qqConfig = include $configFile;
if (!is_array($qqConfig) || empty($qqConfig['enabled']) || empty($qqConfig['app_id'])) {
    header('Location: Login.html?qq_error=disabled');
    exit;
}

$appId = $qqConfig['app_id'];
$appKey = $qqConfig['app_key'];
$redirectUri = $qqConfig['redirect_uri'];
if (empty($redirectUri)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $redirectUri = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/qq_callback.php';
}

// 检查回调参数
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    error_log('QQ callback error: ' . $error . ' desc=' . ($_GET['error_description'] ?? ''));
    header('Location: Login.html?qq_error=cancelled');
    exit;
}

if (empty($code) || empty($state)) {
    header('Location: Login.html?qq_error=param');
    exit;
}

// 验证 state 防 CSRF
if (!isset($_SESSION['qq_state']) || $_SESSION['qq_state'] !== $state) {
    error_log('QQ callback state mismatch');
    header('Location: Login.html?qq_error=state');
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

$tokenResponse = httpGet($tokenUrl . '?' . http_build_query($tokenParams));
if (!$tokenResponse) {
    error_log('QQ token request failed');
    header('Location: Login.html?qq_error=network');
    exit;
}

$tokenData = json_decode($tokenResponse, true);
if (!$tokenData || isset($tokenData['error'])) {
    error_log('QQ token error: ' . json_encode($tokenData));
    header('Location: Login.html?qq_error=token');
    exit;
}

$accessToken = $tokenData['access_token'];

// 获取 openid
$openidResponse = httpGet('https://graph.qq.com/oauth2.0/me?access_token=' . $accessToken . '&fmt=json');
if (!$openidResponse) {
    header('Location: Login.html?qq_error=network');
    exit;
}
$openidData = json_decode($openidResponse, true);
if (!$openidData || isset($openidData['error'])) {
    error_log('QQ openid error: ' . json_encode($openidData));
    header('Location: Login.html?qq_error=openid');
    exit;
}

$openid = $openidData['openid'];

// 获取用户信息
$userInfoUrl = 'https://graph.qq.com/user/get_user_info?' . http_build_query([
    'access_token' => $accessToken,
    'oauth_consumer_key' => $appId,
    'openid' => $openid,
    'fmt' => 'json'
]);

$userInfoResponse = httpGet($userInfoUrl);
if (!$userInfoResponse) {
    header('Location: Login.html?qq_error=network');
    exit;
}
$userInfo = json_decode($userInfoResponse, true);
if (!$userInfo || (isset($userInfo['ret']) && $userInfo['ret'] != 0)) {
    error_log('QQ user info error: ' . json_encode($userInfo));
    header('Location: Login.html?qq_error=userinfo');
    exit;
}

$nickname = $userInfo['nickname'] ?? 'QQ用户';
$qqAvatar = $userInfo['figureurl_qq_2'] ?: ($userInfo['figureurl_qq_1'] ?: ($userInfo['figureurl_1'] ?? 'img/5.jpg'));
$gender = ($userInfo['gender'] ?? '1') == '2' ? 'female' : 'male';

// 连接数据库
require_once 'db.php';

// 确保字段存在（自动迁移）
ensureQqColumn($conn);

// 查找是否已绑定
$stmt = mysqli_prepare($conn, "SELECT id FROM user_info WHERE qq_openid = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $openid);
mysqli_stmt_execute($stmt);
$existingUser = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($existingUser) {
    $userId = intval($existingUser['id']);
    updateUserInfo($conn, $userId, $nickname, $qqAvatar, $gender);
    $_SESSION['uid'] = $userId;
    $_SESSION['login_method'] = 'qq';
    header('Location: index2.html');
    exit;
}

// 未绑定，自动创建账号
$username = 'qq_' . substr(md5($openid), 0, 8);
$password = password_hash(uniqid('', true), PASSWORD_DEFAULT);
$email = $username . '@qq.local';

// 检查用户名是否重复
$checkStmt = mysqli_prepare($conn, "SELECT id FROM user_info WHERE username = ? LIMIT 1");
mysqli_stmt_bind_param($checkStmt, 's', $username);
mysqli_stmt_execute($checkStmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt))) {
    $username = 'qq_' . substr(md5($openid . time()), 0, 8);
}

$insertStmt = mysqli_prepare($conn, "INSERT INTO user_info (username, nickname, email, password, avatar, gender, qq_openid, role) VALUES (?, ?, ?, ?, ?, ?, ?, 'user')");
mysqli_stmt_bind_param($insertStmt, 'sssssss', $username, $nickname, $email, $password, $qqAvatar, $gender, $openid);

if (mysqli_stmt_execute($insertStmt)) {
    $userId = intval(mysqli_insert_id($conn));
    $_SESSION['uid'] = $userId;
    $_SESSION['login_method'] = 'qq';
    $_SESSION['need_email_bind'] = true; // 新用户强制绑定邮箱
    header('Location: index2.html');
    exit;
}

error_log('QQ login insert failed: ' . mysqli_error($conn));
header('Location: Login.html?qq_error=db');
exit;

// ===== 辅助函数 =====

function ensureQqColumn($conn) {
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

function updateUserInfo($conn, $userId, $nickname, $avatar, $gender) {
    $stmt = mysqli_prepare($conn, "UPDATE user_info SET nickname=?, avatar=?, gender=? WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'sssi', $nickname, $avatar, $gender, $userId);
    @mysqli_stmt_execute($stmt);
}

function httpGet($url) {
    // 优先使用 file_get_contents（无需 curl 扩展）
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'header' => "User-Agent: Mozilla/5.0 (compatible; QQLogin/1.0)\r\n",
            'follow_location' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response !== false) {
        return $response;
    }

    // 降级：尝试使用 curl（如果可用）
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; QQLogin/1.0)');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode == 200 && $response !== false) {
            return $response;
        }
    }

    error_log('QQ httpGet failed for URL: ' . $url);
    return false;
}