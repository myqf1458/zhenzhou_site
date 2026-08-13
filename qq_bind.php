<?php
error_reporting(0);
ini_set('display_errors', 0);
session_start();

// 必须已登录才能绑定QQ
if (!isset($_SESSION['uid'])) {
    header('Location: Login.html');
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
$redirectUri = $qqConfig['redirect_uri'];
if (empty($redirectUri)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $redirectUri = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/qq_bind_callback.php';
} else {
    // 把回调地址换成绑定的回调
    $redirectUri = preg_replace('/qq_callback\.php$/', 'qq_bind_callback.php', $redirectUri);
}

// 生成 state 防止 CSRF
$state = md5(uniqid(mt_rand(), true) . session_id());
$_SESSION['qq_state'] = $state;
$_SESSION['qq_bind_mode'] = true; // 标记为绑定模式

// 构建QQ授权链接
$params = [
    'response_type' => 'code',
    'client_id' => $appId,
    'redirect_uri' => $redirectUri,
    'state' => $state,
    'scope' => 'get_user_info',
    'display' => 'page'
];

$authUrl = 'https://graph.qq.com/oauth2.0/authorize?' . http_build_query($params);
header('Location: ' . $authUrl);
exit;
