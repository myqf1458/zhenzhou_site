<?php
// 生产环境关闭错误显示，防止 SQL 结构泄露
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/comment_error.log');

// 错误日志函数
function logError($message) {
    $logFile = dirname(__FILE__) . '/comment_error.log';
    $logLine = date('[Y-m-d H:i:s]') . ' ' . $message . "\n";
    file_put_contents($logFile, $logLine, FILE_APPEND);
}

// 初始化session用于获取登录用户信息（必须在任何输出之前）
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
session_set_cookie_params([
    'lifetime' => 86400,
    'secure' => $isSecure,
    'samesite' => $isSecure ? 'None' : 'Lax',
    'httponly' => true
]);

// 尝试启动session，如果失败则继续（可能是OPTIONS请求或其他原因）
@session_start();

require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: " . site_cors_origin());
header("Access-Control-Allow-Methods: GET,POST,DELETE,OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
// 安全响应头
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once 'db.php';

// 确保comments表存在并包含必要字段
$createTableSql = "CREATE TABLE IF NOT EXISTS comments (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    nickname VARCHAR(50) NOT NULL DEFAULT '',
    avatar VARCHAR(255) NOT NULL DEFAULT '',
    user_id INT(11) NOT NULL DEFAULT 0,
    content TEXT NOT NULL,
    user_ip VARCHAR(50) NOT NULL DEFAULT '',
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    parent_id INT(11) NOT NULL DEFAULT 0,
    INDEX idx_parent_id (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!mysqli_query($conn, $createTableSql)) {
    $dbError = mysqli_error($conn);
    logError("创建表失败: " . $dbError);
    jsonReturn(500, "数据库操作失败: " . $dbError);
}

// 确保必要字段存在（兼容MySQL旧版本，不支持ADD COLUMN IF NOT EXISTS）
function addColumnIfNotExists($conn, $table, $column, $definition) {
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if (mysqli_num_rows($result) == 0) {
        $alterSql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}";
        if (!mysqli_query($conn, $alterSql)) {
            return mysqli_error($conn);
        }
    }
    return null;
}

// 添加user_id字段（如果不存在）
$addError = addColumnIfNotExists($conn, 'comments', 'user_id', 'INT(11) DEFAULT 0 AFTER avatar');
if ($addError) {
    logError("添加user_id字段失败: " . $addError);
    jsonReturn(500, "数据库操作失败: " . $addError);
}

// 添加user_ip字段（如果不存在）
$addError = addColumnIfNotExists($conn, 'comments', 'user_ip', "VARCHAR(50) DEFAULT '' AFTER content");
if ($addError) {
    logError("添加user_ip字段失败: " . $addError);
    jsonReturn(500, "数据库操作失败: " . $addError);
}

// 添加create_time字段（如果不存在）
$addError = addColumnIfNotExists($conn, 'comments', 'create_time', 'DATETIME DEFAULT CURRENT_TIMESTAMP AFTER user_ip');
if ($addError) {
    logError("添加create_time字段失败: " . $addError);
    jsonReturn(500, "数据库操作失败: " . $addError);
}

// 添加parent_id字段（如果不存在，用于评论回复功能）
$addError = addColumnIfNotExists($conn, 'comments', 'parent_id', "INT(11) DEFAULT 0 AFTER create_time");
if ($addError) {
    logError("添加parent_id字段失败: " . $addError);
    jsonReturn(500, "数据库操作失败: " . $addError);
}
// 确保parent_id索引存在
$idxCheck = mysqli_query($conn, "SHOW INDEX FROM comments WHERE Key_name = 'idx_parent_id'");
if (mysqli_num_rows($idxCheck) == 0) {
    @mysqli_query($conn, "ALTER TABLE comments ADD INDEX idx_parent_id (parent_id)");
}

// 创建举报表（如果不存在）
$createReportsTableSql = "CREATE TABLE IF NOT EXISTS reports (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    comment_id INT(11) NOT NULL,
    user_id INT(11) NOT NULL DEFAULT 0,
    reporter_name VARCHAR(50) NOT NULL DEFAULT '',
    reason VARCHAR(255) NOT NULL DEFAULT '',
    status ENUM('pending', 'processed', 'rejected') DEFAULT 'pending',
    processed_by INT(11) DEFAULT NULL,
    processed_time DATETIME DEFAULT NULL,
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_comment_id (comment_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!mysqli_query($conn, $createReportsTableSql)) {
    $dbError = mysqli_error($conn);
    logError("创建举报表失败: " . $dbError);
    jsonReturn(500, "数据库操作失败: " . $dbError);
}

$maxContentLen = 500;
$minContentLen = 3;
$submitInterval = 60;
$badWords = ["脏话1", "脏话2", "违规词", "色情", "暴力", "涉政", "赌博", "刷单"];

function getClientIp()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_REAL_IP'])) {
        return $_SERVER['HTTP_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'];
}

function filterBadWord($str, $badWords)
{
    foreach ($badWords as $word) {
        if (stripos($str, $word) !== false) return true;
    }
    return false;
}

function jsonReturn($code, $msg, $data = [])
{
    global $conn;
    if ($conn) mysqli_close($conn);
    echo json_encode(compact('code','msg','data'), JSON_UNESCAPED_UNICODE);
    exit;
}

// GET读取留言，返回顶级留言及其回复
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 查询顶级留言（parent_id=0）
    $sql = "SELECT id, user_id, nickname, avatar, content, create_time FROM comments WHERE parent_id = 0 ORDER BY id DESC";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        $dbError = mysqli_error($conn);
        logError("GET查询失败: " . $dbError);
        jsonReturn(500, "查询失败: " . $dbError);
    }

    // 查询所有回复（parent_id>0），按时间正序
    $replySql = "SELECT id, parent_id, user_id, nickname, avatar, content, create_time FROM comments WHERE parent_id > 0 ORDER BY id ASC";
    $replyRes = mysqli_query($conn, $replySql);
    $repliesMap = [];
    if ($replyRes) {
        while ($r = mysqli_fetch_assoc($replyRes)) {
            $pid = intval($r['parent_id']);
            if (!isset($repliesMap[$pid])) $repliesMap[$pid] = [];
            $repliesMap[$pid][] = [
                "id"       => $r["id"],
                "parent_id"=> $pid,
                "user_id"  => $r["user_id"],
                "user"     => $r["nickname"],
                "content"  => $r["content"],
                "avatar"   => $r["avatar"],
                "time"     => $r["create_time"]
            ];
        }
    }

    $list = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $commentId = intval($row["id"]);
        $list[] = [
            "id"      => $row["id"],
            "user_id" => $row["user_id"],
            "user"    => $row["nickname"],
            "content" => $row["content"],
            "avatar"  => $row["avatar"],
            "time"    => $row["create_time"],
            "replies" => $repliesMap[$commentId] ?? []
        ];
    }
    jsonReturn(200, "success", $list);
}

// POST提交留言，自动存储用户名，数据库自动生成发布时间
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents("php://input");
    $postData = json_decode($raw, true);
    
    // 判断是否是举报请求
    if (isset($postData['action']) && $postData['action'] === 'report') {
        $commentId = intval($postData['comment_id'] ?? 0);
        $userId = intval($postData['user_id'] ?? 0);
        $reporterName = trim($postData['reporter_name'] ?? '');
        $reason = trim($postData['reason'] ?? '');
        
        if ($commentId <= 0) {
            jsonReturn(400, "参数错误");
        }
        
        // 如果没有提供昵称，尝试从session获取
        if (empty($reporterName)) {
            if (isset($_SESSION['username'])) {
                $reporterName = $_SESSION['username'];
            } else {
                $reporterName = '匿名用户';
            }
        }
        
        if (empty($reason)) {
            jsonReturn(400, "请选择举报原因");
        }
        
        // 验证留言是否存在
        $checkSql = "SELECT id FROM comments WHERE id = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $checkSql);
        mysqli_stmt_bind_param($stmt, "i", $commentId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        if (!$row) {
            jsonReturn(404, "留言不存在");
        }
        
        // 检查是否已经举报过
        $checkReportSql = "SELECT id FROM reports WHERE comment_id = ? AND user_id = ? AND status = 'pending' LIMIT 1";
        $stmt = mysqli_prepare($conn, $checkReportSql);
        mysqli_stmt_bind_param($stmt, "ii", $commentId, $userId);
        mysqli_stmt_execute($stmt);
        $reportRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        if ($reportRow) {
            jsonReturn(403, "您已经举报过这条留言，正在处理中");
        }
        
        // 插入举报记录
        $insertSql = "INSERT INTO reports(comment_id, user_id, reporter_name, reason) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insertSql);
        mysqli_stmt_bind_param($stmt, "iiss", $commentId, $userId, $reporterName, $reason);
        
        if (mysqli_stmt_execute($stmt)) {
            jsonReturn(200, "举报提交成功，管理员将尽快处理");
        } else {
            logError("举报插入失败: " . mysqli_stmt_error($stmt));
            jsonReturn(500, "举报提交失败: " . mysqli_stmt_error($stmt));
        }
    }
    
    try {
        $ip = getClientIp();
        
        // 记录请求日志
        logError("POST请求原始数据: " . substr($raw, 0, 500));
        
        $postData = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            logError("JSON解析失败: " . json_last_error_msg());
            jsonReturn(400, "请求数据格式错误");
        }

        $user = trim($postData['user'] ?? '');
        $avatar = trim($postData['avatar'] ?? '');
        $content = trim($postData['content'] ?? '');
        $user_id = intval($postData['user_id'] ?? 0);
        $parent_id = intval($postData['parent_id'] ?? 0);

        logError("准备插入留言 - 用户ID: {$user_id}, 用户名: {$user}, parent_id: {$parent_id}");

        // 验证必填字段
        if (empty($user)) {
            logError("提交失败：用户名为空");
            jsonReturn(400, "请输入昵称");
        }

        $len = mb_strlen($content);
        if ($len < $minContentLen) {
            logError("提交失败：内容过短，长度: {$len}");
            jsonReturn(400, "留言至少{$minContentLen}个字符");
        }

        if ($len > $maxContentLen) {
            logError("提交失败：内容过长，长度: {$len}");
            jsonReturn(400, "留言不能超过{$maxContentLen}个字符");
        }

        if (filterBadWord($user, $badWords) || filterBadWord($content, $badWords)) {
            logError("提交失败：内容包含违规词汇");
            jsonReturn(400, "内容包含违规词汇，请修改后重新提交");
        }

        // 如果是回复，验证父留言存在
        if ($parent_id > 0) {
            $parentCheckSql = "SELECT id FROM comments WHERE id = ? AND parent_id = 0 LIMIT 1";
            $pstmt = mysqli_prepare($conn, $parentCheckSql);
            mysqli_stmt_bind_param($pstmt, "i", $parent_id);
            mysqli_stmt_execute($pstmt);
            $pRow = mysqli_fetch_assoc(mysqli_stmt_get_result($pstmt));
            if (!$pRow) {
                jsonReturn(404, "父留言不存在");
            }
        }

        // IP防刷校验
        $limitSql = "SELECT id FROM comments WHERE user_ip = ? AND create_time > DATE_SUB(NOW(), INTERVAL {$submitInterval} SECOND) LIMIT 1";
        $stmt = mysqli_prepare($conn, $limitSql);
        if (!$stmt) {
            $dbError = mysqli_error($conn);
            logError("预处理失败(防刷): " . $dbError);
            jsonReturn(500, "数据库操作失败: " . $dbError);
        }
        mysqli_stmt_bind_param($stmt, "s", $ip);
        mysqli_stmt_execute($stmt);
        $limitRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($limitRow) {
            logError("提交失败：IP防刷触发，IP: {$ip}");
            jsonReturn(403, "操作过于频繁，请{$submitInterval}秒后再提交");
        }

        // 插入留言（含parent_id），create_time由数据库自动填充
        $insertSql = "INSERT INTO comments(nickname, avatar, user_id, content, user_ip, parent_id) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insertSql);
        if (!$stmt) {
            $dbError = mysqli_error($conn);
            logError("预处理失败(插入): " . $dbError);
            jsonReturn(500, "数据库操作失败: " . $dbError);
        }
        mysqli_stmt_bind_param($stmt, "ssissi", $user, $avatar, $user_id, $content, $ip, $parent_id);
        $insertStatus = mysqli_stmt_execute($stmt);

        if (!$insertStatus) {
            logError("插入失败: " . mysqli_stmt_error($stmt));
            jsonReturn(500, "留言提交失败: " . mysqli_stmt_error($stmt));
        }

        $insertId = mysqli_insert_id($conn);
        logError("留言提交成功，ID: {$insertId}, 用户ID: {$user_id}, 用户: {$user}, parent_id: {$parent_id}");

        // 提交成功重新读取全部留言返回（带回复）
        $sql = "SELECT id, user_id, nickname, avatar, content, create_time FROM comments WHERE parent_id = 0 ORDER BY id DESC";
        $res = mysqli_query($conn, $sql);
        if (!$res) {
            $dbError = mysqli_error($conn);
            logError("查询失败: " . $dbError);
            jsonReturn(500, "查询失败: " . $dbError);
        }
        $replyRes2 = mysqli_query($conn, "SELECT id, parent_id, user_id, nickname, avatar, content, create_time FROM comments WHERE parent_id > 0 ORDER BY id ASC");
        $repliesMap2 = [];
        if ($replyRes2) {
            while ($r = mysqli_fetch_assoc($replyRes2)) {
                $pid = intval($r['parent_id']);
                if (!isset($repliesMap2[$pid])) $repliesMap2[$pid] = [];
                $repliesMap2[$pid][] = [
                    "id"        => $r["id"],
                    "parent_id" => $pid,
                    "user_id"   => $r["user_id"],
                    "user"      => $r["nickname"],
                    "content"   => $r["content"],
                    "avatar"    => $r["avatar"],
                    "time"      => $r["create_time"]
                ];
            }
        }

        $newList = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $cid = intval($row["id"]);
            $newList[] = [
                "id"      => $row["id"],
                "user_id" => $row["user_id"],
                "user"    => $row["nickname"],
                "content" => $row["content"],
                "avatar"  => $row["avatar"],
                "time"    => $row["create_time"],
                "replies" => $repliesMap2[$cid] ?? []
            ];
        }
        jsonReturn(200, "留言提交成功", $newList);
        
    } catch (Exception $e) {
        logError("POST请求异常: " . $e->getMessage());
        jsonReturn(500, "服务器内部错误: " . $e->getMessage());
    }
}

// DELETE删除留言，验证用户身份
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // session已在文件开头初始化
    
    $raw = file_get_contents("php://input");
    $deleteData = json_decode($raw, true);
    $commentId = intval($deleteData['id'] ?? 0);
    
    // 获取用户ID，优先使用前端传递的user_id，其次使用session
    $uid = intval($deleteData['user_id'] ?? 0);
    if ($uid <= 0 && isset($_SESSION['uid'])) {
        $uid = intval($_SESSION['uid']);
    }
    
    // 验证登录状态
    if ($uid <= 0) {
        logError("删除失败：未登录");
        jsonReturn(401, "请先登录");
    }
    
    if ($commentId <= 0) {
        jsonReturn(400, "参数错误");
    }
    
    // 验证留言是否属于当前用户
    $checkSql = "SELECT id, user_id FROM comments WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $checkSql);
    if (!$stmt) {
        $dbError = mysqli_error($conn);
        logError("删除-查询留言失败: " . $dbError);
        jsonReturn(500, "数据库操作失败: " . $dbError);
    }
    mysqli_stmt_bind_param($stmt, "i", $commentId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    
    if (!$row) {
        jsonReturn(404, "留言不存在");
    }
    
    // 只有留言的所有者才能删除
    if ($row['user_id'] != $uid) {
        jsonReturn(403, "无权删除此留言");
    }
    
    // 执行删除
    $deleteSql = "DELETE FROM comments WHERE id = ?";
    $stmt = mysqli_prepare($conn, $deleteSql);
    if (!$stmt) {
        $dbError = mysqli_error($conn);
        logError("删除-预处理失败: " . $dbError);
        jsonReturn(500, "数据库操作失败: " . $dbError);
    }
    mysqli_stmt_bind_param($stmt, "i", $commentId);
    $deleteStatus = mysqli_stmt_execute($stmt);
    
    if (!$deleteStatus) {
        $dbError = mysqli_stmt_error($stmt);
        logError("删除失败: " . $dbError);
        jsonReturn(500, "删除失败: " . $dbError);
    }
    
    // 删除成功返回最新留言列表
    $sql = "SELECT id, user_id, nickname, avatar, content, create_time FROM comments ORDER BY id DESC";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        $dbError = mysqli_error($conn);
        logError("删除后查询失败: " . $dbError);
        jsonReturn(500, "查询失败: " . $dbError);
    }
    $newList = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $newList[] = [
            "id" => $row["id"],
            "user_id" => $row["user_id"],
            "user" => $row["nickname"],
            "content" => $row["content"],
            "avatar" => $row["avatar"],
            "time" => $row["create_time"]
        ];
    }
    jsonReturn(200, "删除成功", $newList);
}

jsonReturn(405, "请求方式不支持");
