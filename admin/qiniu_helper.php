<?php
/**
 * 七牛云对象存储对接助手
 * 纯原生 PHP 实现（无 composer 依赖），基于 cURL
 * 支持新版（Qiniu 完整请求签）和旧版（QBox 路径签）两种鉴权
 * 支持各区域的 RS/RSF 域名
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'qiniu_helper.php') {
    http_response_code(403);
    exit('Forbidden');
}

class Qiniu {
    private $accessKey;
    private $secretKey;
    private $bucket;
    private $domain;
    private $region;

    // 上传域名（使用区域特定域名，避免 SSL 证书不匹配）
    private static $uploadHosts = [
        'z0'         => 'https://up-z0.qiniup.com',
        'cn-east-2'  => 'https://up-cn-east-2.qiniup.com',
        'z1'         => 'https://up-z1.qiniup.com',
        'z2'         => 'https://up-z2.qiniup.com',
        'na0'        => 'https://up-na0.qiniup.com',
        'as0'        => 'https://up-as0.qiniup.com',
        'cn-north-1' => 'https://up-z1.qiniup.com',
        'cn-south-1' => 'https://up-z2.qiniup.com',
    ];

    // RS（资源管理）域名
    private static $rsHosts = [
        'z0'         => 'rs.qiniu.com',
        'cn-east-2'  => 'rs-cn-east-2.qiniuapi.com',
        'z1'         => 'rs-z1.qiniu.com',
        'z2'         => 'rs-z2.qiniu.com',
        'na0'        => 'rs-na0.qiniu.com',
        'as0'        => 'rs-as0.qiniu.com',
        'cn-north-1' => 'rs-z1.qiniuapi.com',
        'cn-south-1' => 'rs-z2.qiniuapi.com',
    ];

    // RSF（列举资源）域名
    private static $rsfHosts = [
        'z0'         => 'rsf.qiniu.com',
        'cn-east-2'  => 'rsf-cn-east-2.qiniuapi.com',
        'z1'         => 'rsf-z1.qiniu.com',
        'z2'         => 'rsf-z2.qiniu.com',
        'na0'        => 'rsf-na0.qiniu.com',
        'as0'        => 'rsf-as0.qiniu.com',
        'cn-north-1' => 'rsf-z1.qiniuapi.com',
        'cn-south-1' => 'rsf-z2.qiniuapi.com',
    ];

    public function __construct($accessKey, $secretKey, $bucket, $domain = '', $region = 'z0') {
        $this->accessKey = trim((string)$accessKey);
        $this->secretKey = trim((string)$secretKey);
        $this->bucket    = trim((string)$bucket);
        $this->domain    = rtrim(trim((string)$domain), '/');
        $this->region    = trim((string)$region);
    }

    public static function urlSafeBase64Encode($data) {
        // 严格对齐七牛官方 SDK：仅替换 +/ 为 -_，不去除尾部 = 号
        return str_replace(['+', '/'], ['-', '_'], base64_encode($data));
    }

    public static function urlSafeBase64Decode($data) {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    private function rsHost() {
        return self::$rsHosts[$this->region] ?? self::$rsHosts['z0'];
    }

    private function rsfHost() {
        return self::$rsfHosts[$this->region] ?? self::$rsfHosts['z0'];
    }

    // ========== 鉴权方式 1：QBox 路径签（旧版 Kodo 管理接口） ==========
    // 签名原文: Path?Query\n  +  (若 Content-Type=application/x-www-form-urlencoded 且 body 非空) Body\n
    // Authorization: QBox <AK>:<UrlSafeBase64(HMAC-SHA1(SigningData, SK))>
    private function signQBox($pathWithQuery, $body = '', $contentType = '') {
        $signingData = $pathWithQuery . "\n";
        if ($contentType === 'application/x-www-form-urlencoded' && $body !== '') {
            $signingData .= $body . "\n";
        }
        $sign = hash_hmac('sha1', $signingData, $this->secretKey, true);
        return [
            'header'      => 'QBox ' . $this->accessKey . ':' . self::urlSafeBase64Encode($sign),
            'signingData' => $signingData,
            'signingHex'  => bin2hex($signingData),
        ];
    }

    // ========== 鉴权方式 2：Qiniu 完整请求签（新版 CDN/Kodo v2 接口） ==========
    // 签名原文:
    //   {Method} {PathWithQuery}\n
    //   Host: {Host}\n
    //   Content-Type: {ContentType}\n
    //   [X-Qiniu-* headers sorted asc]\n
    //   \n
    //   {Body}
    // Authorization: Qiniu <AK>:<UrlSafeBase64(HMAC-SHA1(SigningData, SK))>
    private function signQiniu($method, $path, $queryStr = '', $host = '', $contentType = '', $body = '') {
        $pathWithQuery = $path;
        if ($queryStr !== '') $pathWithQuery .= '?' . $queryStr;

        // 构造 X-Qiniu-Date 头（UTC 时间，格式 YYYYMMDDTHHMMSSZ）
        $dateStr = gmdate('Ymd\THis\Z');
        $xHeaders = ['X-Qiniu-Date' => $dateStr];
        ksort($xHeaders);

        $signingData = strtoupper($method) . ' ' . $pathWithQuery . "\n";
        $signingData .= 'Host: ' . $host . "\n";
        $signingData .= 'Content-Type: ' . $contentType . "\n";
        foreach ($xHeaders as $k => $v) {
            $signingData .= $k . ': ' . $v . "\n";
        }
        $signingData .= "\n";
        if ($body !== '') {
            $signingData .= $body;
        }

        $sign = hash_hmac('sha1', $signingData, $this->secretKey, true);
        return [
            'header'      => 'Qiniu ' . $this->accessKey . ':' . self::urlSafeBase64Encode($sign),
            'signingData' => $signingData,
            'signingHex'  => bin2hex($signingData),
            'xDate'       => $dateStr,
        ];
    }

    // ========== 生成上传凭证 ==========
    public function createUploadToken($key = null, $expires = 3600) {
        $scope = $key !== null ? ($this->bucket . ':' . $key) : $this->bucket;
        $putPolicy = [
            'scope'      => $scope,
            'deadline'   => time() + $expires,
            'returnBody' => '{"key":"$(key)","hash":"$(etag)","fsize":$(fsize),"mimeType":"$(mimeType)","bucket":"$(bucket)"}',
        ];
        $encoded = self::urlSafeBase64Encode(json_encode($putPolicy));
        $sign = hash_hmac('sha1', $encoded, $this->secretKey, true);
        return $this->accessKey . ':' . self::urlSafeBase64Encode($sign) . ':' . $encoded;
    }

    private function uploadHost() {
        return self::$uploadHosts[$this->region] ?? 'https://upload.qiniu.com';
    }

    public function buildUrl($key) {
        $domain = $this->domain !== '' ? $this->domain : ('https://' . $this->bucket . '.qiniudn.com');
        return $domain . '/' . ltrim($key, '/');
    }

    // ========== 通用 cURL 请求 ==========
    private function doCurl($url, $authHeader = '', $method = 'POST', $body = '', $extraHeaders = []) {
        $headers = [];
        if ($authHeader !== '') $headers[] = 'Authorization: ' . $authHeader;
        foreach ($extraHeaders as $h) $headers[] = $h;
        if ($body !== '' && $method !== 'GET') {
            $hasCT = false;
            foreach ($headers as $h) {
                if (stripos($h, 'content-type:') === 0) { $hasCT = true; break; }
            }
            if (!$hasCT) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($method === 'POST' && $body === '') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Content-Length: 0';
        }
        $headers[] = 'Accept: application/json';

        $ch = curl_init($url);
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ];
        if ($body !== '' && $method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = '';
        }
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            return ['ok' => false, 'msg' => '请求失败：' . $err];
        }
        return ['ok' => ($code === 200), 'code' => $code, 'body' => $res];
    }

    // ========== 列举文件 ==========
    // 同时尝试：新版API(GET+Qiniu签+rsf.qiniuapi.com) vs 旧版API(POST+QBox签+rsf.qiniu.com)
    public function listFiles($limit = 50, $prefix = '', $marker = '', $debug = false) {
        $params = ['bucket' => $this->bucket, 'limit' => (int)$limit];
        if ($prefix !== '') $params['prefix'] = $prefix;
        if ($marker !== '') $params['marker'] = $marker;
        $queryStr = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $path = '/list';
        $contentType = 'application/x-www-form-urlencoded';
        $results = [];

        // ===== 候选 1: 新版 API (GET + Qiniu 完整签 + rsf.qiniuapi.com) =====
        $newHost = 'rsf.qiniuapi.com';
        $newUrl = 'https://' . $newHost . $path . '?' . $queryStr;
        $newSign = $this->signQiniu('GET', $path, $queryStr, $newHost, $contentType, '');
        $r1 = $this->doCurl($newUrl, $newSign['header'], 'GET', '', [
            'Content-Type: ' . $contentType,
            'X-Qiniu-Date: ' . $newSign['xDate'],
        ]);
        $results[] = [
            'host' => '新版(' . $newHost . ')',
            'auth' => 'Qiniu完整签(GET)',
            'method' => 'GET',
            'signing' => $newSign['signingData'],
            'signingHex' => $newSign['signingHex'],
            'authHeader' => $newSign['header'],
            'result' => $r1,
        ];
        if (!$debug && $r1['ok']) return $this->parseListResult($r1);

        // ===== 候选 2: 旧版 API (POST + QBox 路径签 + rsf.qiniu.com) =====
        $oldHost = 'rsf.qiniu.com';
        $oldUrl = 'https://' . $oldHost . $path . '?' . $queryStr;
        $pathWithQuery = $path . '?' . $queryStr;
        $oldSign = $this->signQBox($pathWithQuery, '', $contentType);
        $r2 = $this->doCurl($oldUrl, $oldSign['header'], 'POST', '', [
            'Content-Type: ' . $contentType,
        ]);
        $results[] = [
            'host' => '旧版(' . $oldHost . ')',
            'auth' => 'QBox路径签(POST)',
            'method' => 'POST',
            'signing' => $oldSign['signingData'],
            'signingHex' => $oldSign['signingHex'],
            'authHeader' => $oldSign['header'],
            'result' => $r2,
        ];
        if (!$debug && $r2['ok']) return $this->parseListResult($r2);

        // ===== 候选 3: 旧版备选域名 rsf.qbox.me =====
        $oldHost2 = 'rsf.qbox.me';
        $oldUrl2 = 'https://' . $oldHost2 . $path . '?' . $queryStr;
        $r3 = $this->doCurl($oldUrl2, $oldSign['header'], 'POST', '', [
            'Content-Type: ' . $contentType,
        ]);
        $results[] = [
            'host' => '旧版(' . $oldHost2 . ')',
            'auth' => 'QBox路径签(POST)',
            'method' => 'POST',
            'signing' => $oldSign['signingData'],
            'signingHex' => $oldSign['signingHex'],
            'authHeader' => $oldSign['header'],
            'result' => $r3,
        ];
        if (!$debug && $r3['ok']) return $this->parseListResult($r3);

        if ($debug) {
            return $this->buildDebugInfo($results, $queryStr, [$newHost, $oldHost, $oldHost2], $params);
        }

        // 返回第一个错误结果
        $first = $results[0]['result'];
        $body2 = json_decode($first['body'] ?? '', true);
        $msg = $body2['error'] ?? ('HTTP ' . ($first['code'] ?? 0));
        return ['ok' => false, 'msg' => '列举失败：' . $msg . '（HTTP ' . ($first['code'] ?? 0) . '）'];
    }

    private function parseListResult($r) {
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'msg' => '列举响应解析失败'];
        }
        if (!empty($data['items'])) {
            foreach ($data['items'] as &$it) {
                $it['url'] = $this->buildUrl($it['key']);
            }
        }
        return ['ok' => true, 'data' => $data];
    }

    // ========== 删除文件 ==========
    public function deleteFile($key) {
        $entry = self::urlSafeBase64Encode($this->bucket . ':' . $key);
        $path = '/delete/' . $entry;
        $contentType = 'application/x-www-form-urlencoded';

        // 候选 1: 新版 API (POST + Qiniu 签 + rs.qiniuapi.com)
        $newHost = 'rs.qiniuapi.com';
        $newUrl = 'https://' . $newHost . $path;
        $newSign = $this->signQiniu('POST', $path, '', $newHost, $contentType, '');
        $r1 = $this->doCurl($newUrl, $newSign['header'], 'POST', '', [
            'Content-Type: ' . $contentType,
            'X-Qiniu-Date: ' . $newSign['xDate'],
        ]);
        if ($r1['ok']) return ['ok' => true];

        // 候选 2: 旧版 API (POST + QBox 签 + rs.qiniu.com)
        $oldHost = 'rs.qiniu.com';
        $oldUrl = 'https://' . $oldHost . $path;
        $oldSign = $this->signQBox($path, '', $contentType);
        $r2 = $this->doCurl($oldUrl, $oldSign['header'], 'POST', '', [
            'Content-Type: ' . $contentType,
        ]);
        if ($r2['ok']) return ['ok' => true];

        // 候选 3: 旧版 rs.qbox.me
        $oldHost2 = 'rs.qbox.me';
        $oldUrl2 = 'https://' . $oldHost2 . $path;
        $r3 = $this->doCurl($oldUrl2, $oldSign['header'], 'POST', '', [
            'Content-Type: ' . $contentType,
        ]);
        if ($r3['ok']) return ['ok' => true];

        $body = json_decode($r1['body'] ?? '', true);
        $msg = $body['error'] ?? ('HTTP ' . ($r1['code'] ?? 0));
        return ['ok' => false, 'msg' => '删除失败：' . $msg . '（HTTP ' . ($r1['code'] ?? 0) . '）'];
    }

    // ========== 上传文件 ==========
    public function uploadFile($key, $filePath, $mimeType = null) {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['ok' => false, 'msg' => '本地文件不可读'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'msg' => '服务器未启用 cURL 扩展'];
        }
        $token = $this->createUploadToken($key);
        $url = $this->uploadHost() . '/';

        if (class_exists('CURLFile')) {
            $fileField = new CURLFile($filePath, $mimeType, basename($filePath));
        } else {
            $fileField = '@' . $filePath . ($mimeType ? ';type=' . $mimeType : '');
        }
        $post = [
            'token' => $token,
            'key'   => $key,
            'file'  => $fileField,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            return ['ok' => false, 'msg' => '上传请求失败：' . $err];
        }
        $data = json_decode($res, true);
        if ($code !== 200 || !$data) {
            return ['ok' => false, 'msg' => '上传失败（HTTP ' . $code . '）：' . $res];
        }
        $data['url'] = $this->buildUrl($key);
        return ['ok' => true, 'data' => $data];
    }

    // ========== 测试连接 ==========
    public function testConnection() {
        return $this->listFiles(1);
    }

    // ========== 调试数据组装 ==========
    private function buildDebugInfo($listResults, $body, $rsfHosts, $params) {
        // ping / 外网 / UC / bucket 列表
        $pingUrl = 'https://rs.qiniuapi.com/ping';
        $ping = $this->doCurl($pingUrl, '', 'GET');
        $baidu = $this->doCurl('https://www.baidu.com', '', 'GET');
        $ucUrl = 'https://uc.qbox.me/v2/query?ak=' . urlencode($this->accessKey) . '&bucket=' . urlencode($this->bucket);
        $uc = $this->doCurl($ucUrl, '', 'GET');

        // buckets 列表：新版域名 + 旧版域名
        $bucketResults = [];
        $ct = 'application/x-www-form-urlencoded';
        $testHosts = ['rs.qiniuapi.com', 'rs.qiniu.com', 'rs.qbox.me'];
        foreach ($testHosts as $host) {
            $url = 'https://' . $host . '/buckets';
            // Qiniu 签
            $qiniu = $this->signQiniu('GET', '/buckets', '', $host, $ct, '');
            $r1 = $this->doCurl($url, $qiniu['header'], 'GET', '', [
                'Content-Type: ' . $ct,
                'X-Qiniu-Date: ' . $qiniu['xDate'],
            ]);
            $bucketResults[] = ['host'=>$host,'auth'=>'Qiniu','result'=>$r1];
            if ($r1['ok']) break;
            // QBox 签
            $qbox = $this->signQBox('/buckets', '', $ct);
            $r2 = $this->doCurl($url, $qbox['header'], 'GET', '', ['Content-Type: ' . $ct]);
            $bucketResults[] = ['host'=>$host,'auth'=>'QBox','result'=>$r2];
            if ($r2['ok']) break;
        }

        // cURL 详细
        $chDebug = curl_init($pingUrl);
        curl_setopt_array($chDebug, [
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER         => true,
        ]);
        $debugRes  = curl_exec($chDebug);
        $debugInfo = curl_getinfo($chDebug);
        $debugErr  = curl_error($chDebug);
        $headerSize = $debugInfo['header_size'] ?? 0;
        $respHeader = $headerSize > 0 ? substr((string)$debugRes, 0, $headerSize) : '';
        curl_close($chDebug);

        // SK 字符分析
        $skLen = strlen($this->secretKey);
        $skAnalysis = [];
        for ($i = 0; $i < $skLen; $i++) {
            $ord = ord($this->secretKey[$i]);
            if ($ord < 32 || $ord > 126) $skAnalysis[] = "pos=$i ord=$ord";
        }

        return [
            'ok' => false,
            'msg' => 'debug',
            'php_version' => PHP_VERSION,
            'region' => $this->region,
            'rsf_host' => $this->rsfHost(),
            'rs_host'  => $this->rsHost(),
            'ak' => $this->accessKey,
            'ak_md5' => md5($this->accessKey),
            'sk_len' => $skLen,
            'sk_md5' => md5($this->secretKey),
            'sk_ok' => empty($skAnalysis),
            'sk_issues' => $skAnalysis,
            'bucket' => $this->bucket,
            'params' => $params,
            'body' => $body,
            'list_results' => $listResults,
            'bucket_results' => $bucketResults,
            'ping_code' => $ping['code'] ?? 0,
            'ping_body' => $ping['body'] ?? '',
            'baidu_code' => $baidu['code'] ?? 0,
            'baidu_len' => strlen($baidu['body'] ?? ''),
            'uc_code' => $uc['code'] ?? 0,
            'uc_body' => $uc['body'] ?? '',
            'primary_ip' => $debugInfo['primary_ip'] ?? '-',
            'local_ip' => $debugInfo['local_ip'] ?? '-',
            'total_time' => $debugInfo['total_time'] ?? 0,
            'curl_err' => $debugErr,
            'resp_header' => $respHeader,
        ];
    }
}
