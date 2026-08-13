<?php
// 追番封面图片代理（解决B站防盗链）
error_reporting(E_ALL);
ini_set('display_errors', 0);

$url = isset($_GET['url']) ? $_GET['url'] : '';
if ($url === '') {
    http_response_code(400);
    exit('缺少 url 参数');
}

// 仅允许B站封面域名，防止被滥用为开放代理
$host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
$allowedHosts = ['i0.hdslb.com', 'i1.hdslb.com', 'i2.hdslb.com', 'i3.hdslb.com', 's1.hdslb.com', 's2.hdslb.com', 's3.hdslb.com', 's4.hdslb.com'];
$allowed = false;
foreach ($allowedHosts as $h) {
    if ($host === $h || (strlen($host) > strlen($h) && substr($host, -strlen($h) - 1) === '.' . $h)) {
        $allowed = true;
        break;
    }
}
// 同时允许 http 和 https 的B站图床
if (!$allowed && strpos($host, 'hdslb.com') !== false) {
    $allowed = true;
}
if (!$allowed) {
    http_response_code(403);
    exit('不允许的图片域名');
}

// 强制 https
$url = str_replace('http://', 'https://', $url);

// 缓存目录
$cacheDir = __DIR__ . '/img/bangumi_cache/';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// 缓存文件名
$ext = 'jpg';
$path = parse_url($url, PHP_URL_PATH) ?: '';
if (preg_match('/\.(png|jpg|jpeg|webp|gif)$/i', $path, $m)) {
    $ext = strtolower($m[1]);
    if ($ext === 'jpeg') $ext = 'jpg';
}
$cacheFile = $cacheDir . md5($url) . '.' . $ext;

// 命中缓存（7天有效）直接输出
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
    $mime = 'image/jpeg';
    $imgInfo = @getimagesize($cacheFile);
    if ($imgInfo && !empty($imgInfo['mime'])) {
        $mime = $imgInfo['mime'];
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800');
    header('X-Cover-Cache: HIT');
    readfile($cacheFile);
    exit;
}

// curl 下载（带B站 Referer 绕过防盗链）
if (!function_exists('curl_init')) {
    http_response_code(500);
    exit('服务器未启用 curl');
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Referer: https://www.bilibili.com/',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
]);
$data = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200 || empty($data)) {
    http_response_code(502);
    exit('封面下载失败');
}

// 保存缓存
@file_put_contents($cacheFile, $data);

// 推断 MIME
$mime = $contentType ?: 'image/jpeg';
if (strpos($mime, 'image/') !== 0) {
    $mime = 'image/jpeg';
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800');
header('X-Cover-Cache: MISS');
echo $data;
