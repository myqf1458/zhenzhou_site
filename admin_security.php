<?php
// =============================================
// 真昼小站后台安全防护核心
// 功能：CSRF防护 / 登录限流 / IP黑名单 / 访问日志 / Session加固 / XSS过滤
// 加载时机：所有 admin/*.php 页面在 session_start 之后调用 SecGuard::init()
// =============================================

if (!defined('IN_ADMIN_SEC')) define('IN_ADMIN_SEC', true);

class SecGuard
{
    private static $conn = null;
    private static $ip = '';
    private static $ua = '';
    private static $inited = false;

    public static function init($dbConn = null)
    {
        if (self::$inited) return;
        self::$inited = true;

        if ($dbConn) self::$conn = $dbConn;

        // 获取真实IP（兼容反向代理）
        self::$ip = self::getRealIp();
        self::$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // 1. 自动建表
        self::autoCreateTables();

        // 2. IP黑名单拦截（最先执行）
        self::checkIpBlacklist();

        // 3. Session安全加固
        self::hardenSession();

        // 4. 访问日志（非静态资源）
        self::logVisit();

        // 5. POST请求：CSRF校验（除登录页外，登录页单独处理）
        self::checkCsrfOnPost();
    }

    private static function getDb()
    {
        if (self::$conn) return self::$conn;
        // 延迟连接
        if (file_exists(__DIR__ . '/db.php')) {
            require_once __DIR__ . '/db.php';
            global $conn;
            if (isset($conn)) self::$conn = $conn;
        }
        return self::$conn;
    }

    private static function getRealIp()
    {
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

    // ========== 自动建表 ==========
    private static function autoCreateTables()
    {
        $db = self::getDb();
        if (!$db) return;

        $tables = [
            // 访问日志
            "CREATE TABLE IF NOT EXISTS `sec_access_log` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `ip` VARCHAR(45) NOT NULL DEFAULT '',
              `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
              `uri` VARCHAR(255) NOT NULL DEFAULT '',
              `method` VARCHAR(10) NOT NULL DEFAULT '',
              `admin_id` INT UNSIGNED NOT NULL DEFAULT 0,
              `admin_name` VARCHAR(100) NOT NULL DEFAULT '',
              `action` VARCHAR(100) NOT NULL DEFAULT '',
              `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0-正常 1-拦截 2-敏感',
              `remark` VARCHAR(255) NOT NULL DEFAULT '',
              `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_ip` (`ip`),
              KEY `idx_time` (`create_time`),
              KEY `idx_admin` (`admin_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台访问日志'",

            // IP黑名单
            "CREATE TABLE IF NOT EXISTS `sec_ip_blacklist` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `ip` VARCHAR(45) NOT NULL DEFAULT '',
              `ip_from` INT UNSIGNED NOT NULL DEFAULT 0,
              `ip_to` INT UNSIGNED NOT NULL DEFAULT 0,
              `reason` VARCHAR(255) NOT NULL DEFAULT '',
              `expire_time` DATETIME DEFAULT NULL COMMENT 'NULL永久',
              `create_admin` INT UNSIGNED NOT NULL DEFAULT 0,
              `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_ip` (`ip`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IP黑名单'",

            // 登录失败记录（限流用）
            "CREATE TABLE IF NOT EXISTS `sec_login_failed` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `ip` VARCHAR(45) NOT NULL DEFAULT '',
              `username` VARCHAR(100) NOT NULL DEFAULT '',
              `ua` VARCHAR(500) NOT NULL DEFAULT '',
              `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_ip_time` (`ip`, `create_time`),
              KEY `idx_user_time` (`username`, `create_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录失败记录'",

            // 安全配置
            "CREATE TABLE IF NOT EXISTS `sec_config` (
              `cfg_key` VARCHAR(100) NOT NULL,
              `cfg_value` TEXT,
              `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`cfg_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='安全配置'"
        ];

        foreach ($tables as $sql) {
            @mysqli_query($db, $sql);
        }

        // 写入默认配置
        $defaults = [
            'login_max_5min'    => 5,    // 单IP 5分钟内允许失败次数（超了临时封）
            'login_max_hour'    => 20,   // 单IP 1小时内允许失败总数（超了封更久）
            'login_lock_min'    => 15,   // 限流封禁时长（分钟）
            'rate_limit_page'   => 120,  // 单IP每分钟页面访问上限（0为不限）
        ];
        foreach ($defaults as $k => $v) {
            @mysqli_query($db, "INSERT IGNORE INTO sec_config (cfg_key, cfg_value) VALUES ('$k', '$v')");
        }
    }

    // ========== IP黑名单 ==========
    private static function checkIpBlacklist()
    {
        $db = self::getDb();
        if (!$db) return;

        $ip = self::$ip;
        // IPv4转数字用于范围匹配
        $ipNum = ip2long($ip);
        if ($ipNum === false) $ipNum = 0;

        $sql = "SELECT * FROM sec_ip_blacklist
                WHERE (ip = '$ip' OR (ip_from>0 AND ip_to>0 AND $ipNum BETWEEN ip_from AND ip_to))
                LIMIT 1";
        $res = @mysqli_query($db, $sql);
        if ($res && $row = mysqli_fetch_assoc($res)) {
            if ($row['expire_time'] !== null && strtotime($row['expire_time']) < time()) {
                // 已过期，删除
                @mysqli_query($db, "DELETE FROM sec_ip_blacklist WHERE id={$row['id']}");
                return;
            }
            // 命中拦截
            self::writeLog(1, 'ip_blacklist_block', $row['reason'] ?? '命中IP黑名单');
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            $reason = htmlspecialchars($row['reason'] ?: '命中安全策略');
            $expire = $row['expire_time'] ? ' 解封时间: ' . $row['expire_time'] : '';
            exit('<div style="padding:60px 20px;text-align:center;font-family:Microsoft YaHei">
                <h1 style="color:#e74c3c">访问被拒绝</h1>
                <p style="font-size:16px;color:#555">您的IP <b>' . htmlspecialchars($ip) . '</b> 已被系统限制访问。</p>
                <p style="color:#888">原因: ' . $reason . $expire . '</p>
            </div>');
        }
    }

    // ========== Session加固 ==========
    private static function hardenSession()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;

        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');

        // 1. 登录后 Session 绑定 UA + IP 指纹 + 防会话劫持
        if (isset($_SESSION['admin_id'])) {
            if (!isset($_SESSION['_sec_fingerprint'])) {
                $_SESSION['_sec_fingerprint'] = md5(self::$ua . self::$ip);
            } else {
                $current = md5(self::$ua . self::$ip);
                if ($_SESSION['_sec_fingerprint'] !== $current) {
                    if ($script === 'login.php') {
                        // 登录页：仅清除登录态，保留 CSRF 令牌，避免表单提交时校验失败
                        $keepCsrf = $_SESSION['_sec_csrf'] ?? '';
                        $keepCsrfTime = $_SESSION['_sec_csrf_time'] ?? 0;
                        $_SESSION = [];
                        if ($keepCsrf) {
                            $_SESSION['_sec_csrf'] = $keepCsrf;
                            $_SESSION['_sec_csrf_time'] = $keepCsrfTime;
                        }
                    } else {
                        // 其他页面：销毁会话并跳转登录
                        $_SESSION = [];
                        session_destroy();
                        header('Location: login.php?err=hijack');
                        exit;
                    }
                }
            }
            // 2. 定期重新生成 Session ID（降低固定会话攻击）
            if (!isset($_SESSION['_sec_regen_at'])) {
                $_SESSION['_sec_regen_at'] = time();
            } elseif (time() - $_SESSION['_sec_regen_at'] > 900) { // 15分钟
                session_regenerate_id(true);
                $_SESSION['_sec_regen_at'] = time();
            }
        }

        // 3. Session Cookie 安全属性（在 session_regenerate_id 之后发送，确保 ID 一致）
        $params = session_get_cookie_params();
        $isSecure = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );

        $cookieParts = [
            session_name() . '=' . session_id(),
            'Path=' . $params['path'],
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($isSecure) {
            $cookieParts[] = 'Secure';
        }
        if (!empty($params['lifetime'])) {
            $cookieParts[] = 'Max-Age=' . (int)$params['lifetime'];
        }
        if (!empty($params['domain'])) {
            $cookieParts[] = 'Domain=' . $params['domain'];
        }
        header('Set-Cookie: ' . implode('; ', $cookieParts), false);
    }

    // ========== 访问日志 ==========
    private static function logVisit()
    {
        $db = self::getDb();
        if (!$db) return;

        // 日志采样：如果访问过于频繁则跳过写入（防止DOS把表写爆）
        $limit = (int)self::getConfig('rate_limit_page', 0);
        if ($limit > 0) {
            $timeAgo = date('Y-m-d H:i:s', time() - 60);
            $res = @mysqli_query($db, "SELECT COUNT(*) c FROM sec_access_log WHERE ip='" . self::esc(self::$ip) . "' AND create_time>='$timeAgo'");
            if ($res && ($r = mysqli_fetch_row($res)) && $r[0] > $limit) {
                // 超过限流阈值，拒绝请求
                self::writeLog(1, 'rate_limit_block', '超过每分钟' . $limit . '次访问限制');
                http_response_code(429);
                header('Content-Type: text/html; charset=utf-8');
                exit('<div style="padding:60px 20px;text-align:center;font-family:Microsoft YaHei">
                    <h1 style="color:#f39c12">请求过于频繁</h1>
                    <p style="color:#555">请稍后再试，您当前的访问已被临时限制。</p>
                </div>');
            }
        }

        $action = self::detectAction();
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $adminName = self::esc($_SESSION['admin_username'] ?? '');
        $uri = self::esc(substr($_SERVER['REQUEST_URI'] ?? '', 0, 255));
        $method = self::esc($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $ua = self::esc(substr(self::$ua, 0, 500));
        $ip = self::esc(self::$ip);

        @mysqli_query($db, "INSERT INTO sec_access_log (ip, user_agent, uri, method, admin_id, admin_name, action)
            VALUES ('$ip', '$ua', '$uri', '$method', $adminId, '$adminName', '$action')");
    }

    public static function writeLog($status, $action, $remark = '')
    {
        $db = self::getDb();
        if (!$db) return;
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $adminName = self::esc($_SESSION['admin_username'] ?? '');
        $uri = self::esc(substr($_SERVER['REQUEST_URI'] ?? '', 0, 255));
        $method = self::esc($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $ua = self::esc(substr(self::$ua, 0, 500));
        $ip = self::esc(self::$ip);
        $action = self::esc($action);
        $remark = self::esc($remark);
        @mysqli_query($db, "INSERT INTO sec_access_log (ip, user_agent, uri, method, admin_id, admin_name, action, status, remark)
            VALUES ('$ip', '$ua', '$uri', '$method', $adminId, '$adminName', '$action', $status, '$remark')");
    }

    private static function detectAction()
    {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $action = $_REQUEST['action'] ?? '';
        if (is_array($action)) $action = '';
        $m = $script;
        if ($action) $m .= ':' . substr($action, 0, 80);
        return substr($m, 0, 100);
    }

    // ========== CSRF ==========
    public static function csrfToken()
    {
        if (empty($_SESSION['_sec_csrf'])) {
            $_SESSION['_sec_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['_sec_csrf_time'] = time();
        }
        return $_SESSION['_sec_csrf'];
    }

    public static function csrfField()
    {
        return '<input type="hidden" name="_sec_csrf" value="' . self::csrfToken() . '">';
    }

    public static function csrfMeta()
    {
        return '<meta name="csrf-token" content="' . self::csrfToken() . '">';
    }

    // 校验失败直接拒绝请求
    public static function verifyCsrf($throw = true)
    {
        $token = '';
        if (!empty($_POST['_sec_csrf'])) {
            $token = $_POST['_sec_csrf'];
        } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (empty($_SESSION['_sec_csrf']) || empty($token) || !hash_equals($_SESSION['_sec_csrf'], $token)) {
            if ($throw) {
                self::writeLog(1, 'csrf_invalid', 'CSRF token 校验失败');
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['code' => 400, 'msg' => '安全校验失败(CSRF)，请刷新页面重试'], JSON_UNESCAPED_UNICODE));
            }
            return false;
        }
        // 校验通过则一次性重置
        $_SESSION['_sec_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['_sec_csrf_time'] = time();
        return true;
    }

    // POST 请求 CSRF 校验（宽松模式，兼容历史页面）
    // 规则：
    //   1. login.php 登录页用独立的手动校验
    //   2. 如果请求中出现了 _sec_csrf 或 X-CSRF-Token 头，则严格校验（用于新页面/手动启用的接口）
    //   3. 未出现 token 的普通 POST 请求：放行（避免历史 AJAX 代码被误杀），但留下告警日志供分析
    private static function checkCsrfOnPost()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script === 'login.php') return;
        if (!empty($_POST['_sec_skip_csrf'])) return;

        $hasToken = !empty($_POST['_sec_csrf']) || !empty($_SERVER['HTTP_X_CSRF_TOKEN']);
        if ($hasToken) {
            // 显式带 token 的请求，必须严格校验
            self::verifyCsrf(true);
        } elseif (
            $script !== 'install.php'
            && strpos(strtolower($_SERVER['HTTP_CONTENT_TYPE'] ?? ''), 'multipart/form-data') === false
        ) {
            // 记录告警日志，便于未来评估是否升级为严格模式
            if (mt_rand(1, 10) === 1) {
                self::writeLog(0, 'csrf_no_token', '未携带CSRF token的POST请求，已放行（宽松模式）');
            }
        }
    }

    // ========== 登录限流 ==========
    public static function isLoginLocked()
    {
        $db = self::getDb();
        if (!$db) return false;
        $ip = self::esc(self::$ip);
        $lockMin = (int)self::getConfig('login_lock_min', 15);
        $max5m = (int)self::getConfig('login_max_5min', 5);
        $maxHour = (int)self::getConfig('login_max_hour', 20);

        $fiveMinAgo = date('Y-m-d H:i:s', time() - 300);
        $hourAgo = date('Y-m-d H:i:s', time() - 3600);

        // 检查是否在IP黑名单临时封禁（由登录失败自动封禁）
        $blk = @mysqli_query($db, "SELECT expire_time FROM sec_ip_blacklist WHERE ip='$ip' AND reason LIKE '登录失败%' LIMIT 1");
        if ($blk && ($row = mysqli_fetch_assoc($blk)) && $row['expire_time'] && strtotime($row['expire_time']) >= time()) {
            return ['locked' => true, 'unlock' => $row['expire_time'], 'reason' => '登录失败过多'];
        }

        // 检查最近5分钟失败数
        $c1 = 0;
        $r1 = @mysqli_query($db, "SELECT COUNT(*) c FROM sec_login_failed WHERE ip='$ip' AND create_time>='$fiveMinAgo'");
        if ($r1 && $row = mysqli_fetch_row($r1)) $c1 = (int)$row[0];
        if ($c1 >= $max5m) {
            // 达到阈值，先自动封禁
            $expire = date('Y-m-d H:i:s', time() + $lockMin * 60);
            @mysqli_query($db, "INSERT INTO sec_ip_blacklist (ip, reason, expire_time) VALUES ('$ip', '登录失败过多（5分钟内{$c1}次）', '$expire')
                ON DUPLICATE KEY UPDATE reason=CONCAT('登录失败过多（', {$c1}, '次）'), expire_time='$expire'");
            return ['locked' => true, 'unlock' => $expire, 'reason' => '5分钟内失败过多次数'];
        }
        // 1小时总量判定（更严格，失败次数更多）
        $c2 = 0;
        $r2 = @mysqli_query($db, "SELECT COUNT(*) c FROM sec_login_failed WHERE ip='$ip' AND create_time>='$hourAgo'");
        if ($r2 && $row = mysqli_fetch_row($r2)) $c2 = (int)$row[0];
        if ($c2 >= $maxHour) {
            $expire = date('Y-m-d H:i:s', time() + $lockMin * 4 * 60);
            @mysqli_query($db, "INSERT INTO sec_ip_blacklist (ip, reason, expire_time) VALUES ('$ip', '登录失败过多（1小时内{$c2}次）', '$expire')
                ON DUPLICATE KEY UPDATE reason=CONCAT('登录失败过多（1小时内', {$c2}, '次）'), expire_time='$expire'");
            return ['locked' => true, 'unlock' => $expire, 'reason' => '1小时内失败过多次数'];
        }
        return ['locked' => false, 'count_5min' => $c1, 'count_hour' => $c2];
    }

    public static function recordLoginFail($username = '')
    {
        $db = self::getDb();
        if (!$db) return;
        $ip = self::esc(self::$ip);
        $ua = self::esc(substr(self::$ua, 0, 500));
        $uname = self::esc(substr($username, 0, 100));
        @mysqli_query($db, "INSERT INTO sec_login_failed (ip, username, ua) VALUES ('$ip', '$uname', '$ua')");
        self::writeLog(1, 'login_failed', '用户名: ' . $username);
        // 清理超过1天的老数据
        $dayAgo = date('Y-m-d H:i:s', time() - 86400);
        @mysqli_query($db, "DELETE FROM sec_login_failed WHERE create_time < '$dayAgo'");
    }

    // 登录成功，清除失败记录
    public static function onLoginSuccess($username = '')
    {
        $db = self::getDb();
        if ($db) {
            $ip = self::esc(self::$ip);
            @mysqli_query($db, "DELETE FROM sec_login_failed WHERE ip='$ip'");
        }
        // 重新生成 Session ID 防固定会话攻击
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_sec_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['_sec_regen_at'] = time();
            $_SESSION['_sec_fingerprint'] = md5(self::$ua . self::$ip);
        }
        self::writeLog(2, 'login_success', '管理员登录: ' . $username);
    }

    // ========== 安全工具函数 ==========
    public static function esc($s)
    {
        if (is_array($s)) return '';
        $db = self::getDb();
        if ($db) return mysqli_real_escape_string($db, (string)$s);
        return addslashes((string)$s);
    }

    public static function getConfig($key, $default = null)
    {
        $db = self::getDb();
        if (!$db) return $default;
        $k = self::esc($key);
        $r = @mysqli_query($db, "SELECT cfg_value FROM sec_config WHERE cfg_key='$k' LIMIT 1");
        if ($r && $row = mysqli_fetch_row($r)) {
            $v = $row[0];
            if (is_numeric($v)) return $v + 0;
            if ($v === null || $v === '') return $default;
            return $v;
        }
        return $default;
    }

    public static function setConfig($key, $value)
    {
        $db = self::getDb();
        if (!$db) return false;
        $k = self::esc($key);
        $v = self::esc($value);
        return @mysqli_query($db, "INSERT INTO sec_config (cfg_key, cfg_value) VALUES ('$k', '$v')
            ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value)");
    }

    // XSS 安全输出（前台通用）
    public static function x($str)
    {
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function ip()
    {
        return self::$ip;
    }
}

// 便捷函数（全局可用）
function csrf_field() { return SecGuard::csrfField(); }
function csrf_token() { return SecGuard::csrfToken(); }
function s_x($s) { return SecGuard::x($s); }
