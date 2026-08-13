<?php
/**
 * 真昼小站 - 集中式配置加载器
 *
 * 读取优先级：环境变量 > config.local.php > 默认占位值
 *
 * 部署方式（任选其一）：
 *   1. 复制 config.example.php 为 config.local.php 并填写真实值（推荐；config.local.php 已加入 .gitignore）
 *   2. 通过环境变量注入（适合容器化部署，key 形如 DB_HOST / SMTP_USER / SITE_URL）
 *   3. 通过后台安装向导 admin/install.php 自动生成 config.local.php
 *
*/

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'config.php') {
    http_response_code(403);
    exit('Forbidden');
}

// 加载本地配置（如存在；该文件不应提交到仓库）
$localConfigFile = __DIR__ . '/config.local.php';
$__localConfig = [];
if (is_file($localConfigFile)) {
    $__tmp = include $localConfigFile;
    if (is_array($__tmp)) $__localConfig = $__tmp;
}
unset($localConfigFile, $__tmp);

/**
 * 读取配置项
 * 优先级：环境变量 > 本地配置文件（支持点号分级）> 默认值
 */
function cfg(string $key, $default = '')
{
    global $__localConfig;

    // 1. 环境变量：db.host -> DB_HOST
    $envKey = strtoupper(str_replace('.', '_', $key));
    $val = getenv($envKey);
    if ($val !== false && $val !== '') {
        return $val;
    }

    // 2. 本地配置文件（支持点号分级数组）
    $cursor = $__localConfig;
    foreach (explode('.', $key) as $p) {
        if (!is_array($cursor) || !array_key_exists($p, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$p];
    }
    return $cursor === null ? $default : $cursor;
}

/* ============== 数据库配置 ============== */
$db_host    = cfg('db.host', 'localhost');
$db_user    = cfg('db.user', '');
$db_pwd     = cfg('db.pass', '');
$db_name    = cfg('db.name', '');
$db_charset = cfg('db.charset', 'utf8mb4');

/* ============== SMTP 邮件配置 ============== */
if (!defined('SMTP_HOST'))      define('SMTP_HOST', cfg('smtp.host', 'ssl://smtp.qq.com'));
if (!defined('SMTP_PORT'))      define('SMTP_PORT', (int)cfg('smtp.port', 465));
if (!defined('SMTP_USER'))      define('SMTP_USER', cfg('smtp.user', ''));
if (!defined('SMTP_PASS'))      define('SMTP_PASS', cfg('smtp.pass', ''));
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', cfg('smtp.from_name', '真昼小站'));

/* ============== 站点配置 ============== */
if (!defined('SITE_URL'))  define('SITE_URL',  cfg('site.url', ''));
if (!defined('SITE_NAME')) define('SITE_NAME', cfg('site.name', '真昼小站'));

/**
 * 获取 CORS 允许来源
 * - 已配置 SITE_URL 时返回站点地址
 * - 未配置时按当前请求 host 推断（便于本地开发）
 */
function site_cors_origin(): string
{
    $url = defined('SITE_URL') ? SITE_URL : '';
    if ($url !== '') {
        return rtrim($url, '/');
    }
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? '';
    return $host !== '' ? $proto . '://' . $host : '';
}

/**
 * 输出统一的 CORS 头（用于 API 接口）
 */
function site_cors_headers(): void
{
    $origin = site_cors_origin();
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
}
