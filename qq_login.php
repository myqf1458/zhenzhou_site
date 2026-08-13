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
$redirectUri = $qqConfig['redirect_uri'];
if (empty($redirectUri)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $redirectUri = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/qq_callback.php';
}

// 生成 state 防止 CSRF
$state = md5(uniqid(mt_rand(), true) . session_id());
$_SESSION['qq_state'] = $state;

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