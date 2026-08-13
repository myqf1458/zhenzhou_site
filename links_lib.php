<?php
/**
 * 友链核心函数库
 * - 自动建表 links
 * - 对方站点反向检测（抓取 HTML，检查是否包含本站链接）
 * - 前台提交字段校验
 */

if (!defined('IN_LINKS_LIB')) define('IN_LINKS_LIB', true);

require_once __DIR__ . '/db.php';

/* ============== 自动建表 ============== */
function links_ensure_table() {
    global $conn;
    $sql = "CREATE TABLE IF NOT EXISTS `links` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `site_name` VARCHAR(80) NOT NULL DEFAULT '',
        `site_url` VARCHAR(255) NOT NULL DEFAULT '',
        `site_desc` VARCHAR(255) NOT NULL DEFAULT '',
        `site_logo` VARCHAR(255) NOT NULL DEFAULT '',
        `contact` VARCHAR(120) NOT NULL DEFAULT '',
        `link_prove` VARCHAR(500) NOT NULL DEFAULT '',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=待审核 1=已通过 2=已拒绝 3=已移除',
        `reject_reason` VARCHAR(255) NOT NULL DEFAULT '',
        `check_status` TINYINT NOT NULL DEFAULT 0 COMMENT '反向检测：0=未测 1=检测通过(有本站链) 2=检测失败(无本站链)',
        `check_detail` VARCHAR(255) NOT NULL DEFAULT '',
        `check_time` DATETIME DEFAULT NULL,
        `ip` VARCHAR(45) NOT NULL DEFAULT '',
        `sort` INT NOT NULL DEFAULT 0,
        `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_status` (`status`),
        KEY `idx_site_url` (`site_url`(191)),
        KEY `idx_check` (`check_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友情链接表'";
    @mysqli_query($conn, $sql);
}
links_ensure_table();

/* ============== 工具 ============== */
function links_real_ip() {
    $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', $_SERVER[$k])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function links_esc($s) {
    global $conn;
    if (is_array($s)) return '';
    return mysqli_real_escape_string($conn, (string)$s);
}

/* ============== 反向检测：对方站点是否已添加本站友链 ==============
 * 返回: ['ok'=>bool, 'msg'=>string, 'match'=>string|false]
 */
function links_check_backlink($targetUrl, $myHostList = []) {
    // 默认本站域名：从 HTTP_HOST 推断 + 常见形式
    if (empty($myHostList)) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $host = preg_replace('/:\d+$/', '', $host);
        $candidates = [$host];
        if (!empty($host)) {
            // 如果是 www 开头，也加一份根域名；反之也加一份 www
            if (strpos($host, 'www.') === 0) $candidates[] = substr($host, 4);
            else $candidates[] = 'www.' . $host;
        }
        $myHostList = array_values(array_unique(array_filter($candidates)));
    }

    // 1. 基础校验
    $targetUrl = trim($targetUrl);
    if ($targetUrl === '' || strpos($targetUrl, 'http') !== 0) {
        return ['ok' => false, 'msg' => '对方网址无效'];
    }
    $parts = parse_url($targetUrl);
    if (!$parts || empty($parts['host'])) {
        return ['ok' => false, 'msg' => '无法解析对方域名'];
    }
    // 防止让检测变成内网 SSRF
    $targetHost = $parts['host'];
    foreach (['127.0.0.', '10.', '192.168.', '172.16.', '172.17.', '172.18.', '172.19.',
               '172.2', '172.30.', '172.31.', 'localhost', '::1'] as $prefix) {
        if (stripos($targetHost, $prefix) === 0) {
            return ['ok' => false, 'msg' => '禁止访问内网地址'];
        }
    }

    // 2. HTTP 抓取（10秒超时，跟随一次跳转，伪造常见 UA）
    $ua = 'Mozilla/5.0 (compatible; FriendLinkBot/1.0; +' . (site_cors_origin() ?: 'https://example.com') . ')';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: {$ua}\r\nAccept: text/html,application/xhtml+xml\r\nAccept-Language: zh-CN,zh;q=0.9\r\n",
            'follow_location' => true,
            'max_redirects' => 2,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $html = @file_get_contents($targetUrl, false, $ctx);
    if ($html === false || $html === '') {
        // 降级：尝试 curl
        if (function_exists('curl_init')) {
            $ch = curl_init($targetUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_HTTPHEADER => ['Accept-Language: zh-CN,zh;q=0.9'],
                CURLOPT_ENCODING => '',
            ]);
            $html = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($html === false) {
                return ['ok' => false, 'msg' => '抓取失败: ' . ($err ?: 'HTTP ' . intval($code))];
            }
        } else {
            return ['ok' => false, 'msg' => '抓取失败（网络不可达或超时）'];
        }
    }

    // 3. 只取 <body> 降低误判（跳过脚本/style）
    $body = $html;
    if (preg_match('/<body[^>]*>([\s\S]*)<\/body>/i', $html, $m)) $body = $m[1];
    $body = preg_replace('/<script\b[\s\S]*?<\/script>/i', ' ', $body);
    $body = preg_replace('/<style\b[\s\S]*?<\/style>/i', ' ', $body);
    // 解码 HTML 实体，兼容 href="..." 中的 &amp;
    $bodyDecoded = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 4. 匹配：a 标签 href 包含任一本站域名；或纯文本包含本站域名
    $found = false;
    $matchedHost = false;
    foreach ($myHostList as $h) {
        $h = trim($h);
        if ($h === '') continue;
        $pattern = '/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>[\s\S]*?<\/a>/i';
        if (preg_match_all($pattern, $bodyDecoded, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $mm) {
                $href = $mm[1] ?? '';
                if ($href === '') continue;
                $hrefDec = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // 精确匹配域名（host 部分）
                $hParts = parse_url($hrefDec);
                if ($hParts && !empty($hParts['host'])) {
                    if (strcasecmp($hParts['host'], $h) === 0 || stripos($hParts['host'], '.' . $h) === strlen($hParts['host']) - strlen('.' . $h)) {
                        $found = true;
                        $matchedHost = $h;
                        break 3;
                    }
                }
                // 宽松：直接字符串包含（协议/路径也可能）
                if (stripos($hrefDec, $h) !== false) {
                    $found = true;
                    $matchedHost = $h;
                    break 3;
                }
            }
        }
        // 退一步：纯文本包含
        if (!$found && (stripos($bodyDecoded, $h) !== false)) {
            $found = true;
            $matchedHost = $h;
            break;
        }
    }

    if ($found) {
        return ['ok' => true, 'msg' => "检测到站点包含本站链接（$matchedHost）", 'match' => $matchedHost];
    }
    return ['ok' => false, 'msg' => '在对方站点未检测到本站友链，请先添加后再提交（或稍后手动重试检测）'];
}

/* ============== 前台提交校验 + 写入 ============== */
function links_submit(array $post) {
    global $conn;
    links_ensure_table();

    $siteName = trim($post['site_name'] ?? '');
    $siteUrl  = trim($post['site_url'] ?? '');
    $siteDesc = trim($post['site_desc'] ?? '');
    $contact  = trim($post['contact'] ?? '');
    $linkProve = trim($post['link_prove'] ?? '');
    $siteLogo = trim($post['site_logo'] ?? '');

    // 1. 字段长度
    if (mb_strlen($siteName, 'UTF-8') < 2 || mb_strlen($siteName, 'UTF-8') > 40) {
        return ['code' => 400, 'msg' => '站点名称长度需 2-40 字符'];
    }
    if (strlen($siteUrl) < 8 || strlen($siteUrl) > 255) {
        return ['code' => 400, 'msg' => '网站地址无效'];
    }
    if (strpos($siteUrl, 'http://') !== 0 && strpos($siteUrl, 'https://') !== 0) {
        return ['code' => 400, 'msg' => '网站地址必须以 http(s):// 开头'];
    }
    if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
        return ['code' => 400, 'msg' => '网站地址格式不正确'];
    }
    if (mb_strlen($siteDesc, 'UTF-8') > 200) {
        return ['code' => 400, 'msg' => '站点简介最多 200 字符'];
    }
    if ($siteLogo !== '' && !filter_var($siteLogo, FILTER_VALIDATE_URL) && strpos($siteLogo, '/') !== 0) {
        return ['code' => 400, 'msg' => '站点图标地址无效'];
    }
    if ($contact !== '' && mb_strlen($contact, 'UTF-8') > 60) {
        return ['code' => 400, 'msg' => '联系方式过长'];
    }
    if (mb_strlen($linkProve, 'UTF-8') > 300) {
        return ['code' => 400, 'msg' => '证明信息过长'];
    }

    // 2. 提交频率：同 IP 5 分钟最多 2 次
    $ip = links_real_ip();
    $since = date('Y-m-d H:i:s', time() - 300);
    $ipE = links_esc($ip);
    $res = @mysqli_query($conn, "SELECT COUNT(*) FROM links WHERE ip='$ipE' AND create_time>='$since'");
    if ($res && ($r = mysqli_fetch_row($res)) && intval($r[0]) >= 2) {
        return ['code' => 429, 'msg' => '提交过于频繁，请 5 分钟后再试'];
    }

    // 3. 同 URL 不得重复申请（已通过的直接告知，待审的最多一条）
    $urlE = links_esc($siteUrl);
    $res = @mysqli_query($conn, "SELECT status FROM links WHERE site_url='$urlE' ORDER BY id DESC LIMIT 1");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        if (intval($row['status']) === 1) {
            return ['code' => 409, 'msg' => '该站点已经是本站友链，无需重复申请'];
        }
        if (intval($row['status']) === 0) {
            return ['code' => 409, 'msg' => '该站点正在审核中，请耐心等待'];
        }
    }

    // 4. 反向检测（尽力而为；超时不阻塞提交，结果存到 check_status）
    $checkStatus = 0;
    $checkDetail = '';
    $r = links_check_backlink($siteUrl);
    if ($r['ok']) {
        $checkStatus = 1;
        $checkDetail = $r['msg'];
    } else {
        $checkStatus = 2;
        $checkDetail = $r['msg'];
    }

    // 5. 写入
    $sql = "INSERT INTO links (site_name, site_url, site_desc, site_logo, contact, link_prove, status, check_status, check_detail, check_time, ip) VALUES (?,?,?,?,?,?, 0, ?, ?, NOW(), ?)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['code' => 500, 'msg' => '数据库错误：' . mysqli_error($conn)];
    }
    $statusPending = 0;
    mysqli_stmt_bind_param($stmt, 'ssssssiss',
        $siteName, $siteUrl, $siteDesc, $siteLogo, $contact, $linkProve,
        $checkStatus, $checkDetail, $ip
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        return ['code' => 500, 'msg' => '写入失败，请稍后重试'];
    }
    return [
        'code' => 200,
        'msg'  => $checkStatus == 1
            ? '提交成功！已检测到贵站包含本站友链，审核将更快通过。'
            : '提交成功！未检测到贵站包含本站友链，请先添加后等待审核（审核员可手动重试检测）。',
        'check_status' => $checkStatus,
    ];
}

/* ============== 前台列表：仅已通过 ============== */
function links_list_approved() {
    global $conn;
    links_ensure_table();
    $sql = "SELECT id, site_name, site_url, site_desc, site_logo, create_time FROM links WHERE status=1 ORDER BY sort ASC, id DESC";
    $res = @mysqli_query($conn, $sql);
    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
    }
    return $out;
}
