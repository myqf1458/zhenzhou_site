<?php
error_reporting(0);
ini_set('display_errors', 0);

function logError($message) {
    $logFile = dirname(__FILE__) . '/community_error.log';
    $logLine = date('[Y-m-d H:i:s]') . ' ' . $message . "\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND);
}

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
session_set_cookie_params([
    'lifetime' => 86400,
    'secure' => $isSecure,
    'samesite' => $isSecure ? 'None' : 'Lax',
    'httponly' => true
]);

@session_start();

header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");

require_once 'db.php';

// ========== 自动建表/字段升级 ==========
// 1. 板块表
$createBoardSql = "CREATE TABLE IF NOT EXISTS `community_boards` (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL DEFAULT '',
    icon VARCHAR(50) NOT NULL DEFAULT '',
    description VARCHAR(255) NOT NULL DEFAULT '',
    sort INT(11) NOT NULL DEFAULT 0,
    status TINYINT UNSIGNED NOT NULL DEFAULT 1,
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sort (sort),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区板块表'";
@mysqli_query($conn, $createBoardSql);

// 2. 写入默认板块（固定 id 1-7，先去重再写入，防止重复累积）
$defaultBoards = [
    ['id'=>1,'name' => '全部动态', 'icon' => '', 'description' => '所有板块的帖子', 'sort' => 0],
    ['id'=>2,'name' => '动漫讨论', 'icon' => '', 'description' => '动画、轻小说相关讨论', 'sort' => 10],
    ['id'=>3,'name' => '生活日常', 'icon' => '', 'description' => '日常碎碎念、生活分享', 'sort' => 20],
    ['id'=>4,'name' => '作品分享', 'icon' => '', 'description' => '绘画、剪辑、手作等创作', 'sort' => 30],
    ['id'=>5,'name' => '互动交流', 'icon' => '', 'description' => '提问、求助、闲聊', 'sort' => 40],
    ['id'=>6,'name' => '资源共享', 'icon' => '', 'description' => '壁纸、音乐、电子书等', 'sort' => 50],
    ['id'=>7,'name' => '活动专区', 'icon' => '', 'description' => '站内活动和公告', 'sort' => 60],
];
// 去重：保留每个 name 的最小 id
@mysqli_query($conn, "DELETE b1 FROM community_boards b1 INNER JOIN community_boards b2 ON b1.name = b2.name AND b1.id > b2.id");
// 读取当前 name→id 映射，用于更新帖子关联
$oldBoardMap = [];
$bRes = @mysqli_query($conn, "SELECT id, name FROM community_boards");
if ($bRes) { while ($r = mysqli_fetch_assoc($bRes)) $oldBoardMap[$r['name']] = (int)$r['id']; }
// 清空并重新用固定 id 写入
@mysqli_query($conn, "DELETE FROM community_boards");
foreach ($defaultBoards as $b) {
    $id = (int)$b['id'];
    $name = mysqli_real_escape_string($conn, $b['name']);
    $icon = mysqli_real_escape_string($conn, $b['icon']);
    $desc = mysqli_real_escape_string($conn, $b['description']);
    $sort = (int)$b['sort'];
    @mysqli_query($conn, "INSERT INTO community_boards (id, name, icon, description, sort) VALUES ({$id}, '$name', '$icon', '$desc', {$sort})");
    if (isset($oldBoardMap[$b['name']]) && $oldBoardMap[$b['name']] !== $id) {
        $oldBid = $oldBoardMap[$b['name']];
        @mysqli_query($conn, "UPDATE community_posts SET board_id = {$id} WHERE board_id = {$oldBid}");
    }
}
@mysqli_query($conn, "ALTER TABLE community_boards AUTO_INCREMENT = 8");

// 3. 帖子表（含 board_id）
$createPostSql = "CREATE TABLE IF NOT EXISTS `community_posts` (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
    user_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
    author VARCHAR(50) NOT NULL DEFAULT '',
    avatar VARCHAR(255) NOT NULL DEFAULT '',
    title VARCHAR(200) NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    emoji VARCHAR(10) DEFAULT NULL,
    qq VARCHAR(20) NOT NULL DEFAULT '',
    status TINYINT UNSIGNED NOT NULL DEFAULT 1,
    user_ip VARCHAR(50) NOT NULL DEFAULT '',
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_board_time (board_id, create_time),
    INDEX idx_user_id (user_id),
    INDEX idx_create_time (create_time),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区帖子表'";
@mysqli_query($conn, $createPostSql);

// 4. 给老表补 board_id/title 字段（如果是老结构升级）
$cols = @mysqli_query($conn, "SHOW COLUMNS FROM community_posts LIKE 'board_id'");
if (!$cols || mysqli_num_rows($cols) === 0) {
    @mysqli_query($conn, "ALTER TABLE community_posts ADD COLUMN board_id INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER id");
    @mysqli_query($conn, "ALTER TABLE community_posts ADD INDEX idx_board_time (board_id, create_time)");
}
$cols2 = @mysqli_query($conn, "SHOW COLUMNS FROM community_posts LIKE 'title'");
if (!$cols2 || mysqli_num_rows($cols2) === 0) {
    @mysqli_query($conn, "ALTER TABLE community_posts ADD COLUMN title VARCHAR(200) NOT NULL DEFAULT '' AFTER avatar");
}

// 5. 评论表
$createCommentSql = "CREATE TABLE IF NOT EXISTS `community_comments` (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
    user_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
    author VARCHAR(50) NOT NULL DEFAULT '',
    avatar VARCHAR(255) NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    user_ip VARCHAR(50) NOT NULL DEFAULT '',
    status TINYINT UNSIGNED NOT NULL DEFAULT 1,
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_post_id (post_id, status, create_time),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区评论表'";
@mysqli_query($conn, $createCommentSql);

$maxContentLen = 2000;
$maxTitleLen = 60;
$minContentLen = 1;
$maxCommentLen = 500;
$submitInterval = 30;
$commentInterval = 10;
$badWords = ["脏话1", "脏话2", "违规词", "色情", "暴力", "涉政", "赌博", "刷单"];

function getClientIp()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
    if ($conn) @mysqli_close($conn);
    echo json_encode(compact('code','msg','data'), JSON_UNESCAPED_UNICODE);
    exit;
}

function getCurrentUserId() {
    if (isset($_SESSION['uid'])) {
        return intval($_SESSION['uid']);
    }
    return 0;
}

function getAllBoards($conn) {
    $list = [];
    $res = @mysqli_query($conn, "SELECT id, name, icon, description, sort FROM community_boards WHERE status = 1 ORDER BY sort ASC, id ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $bid = (int)$row['id'];
            // 统计帖子数（"全部动态"用 COUNT(*)，其他按 board_id）
            if ($bid === 1) {
                // 第一条默认是「全部动态」
                $cntRes = @mysqli_query($conn, "SELECT COUNT(*) FROM community_posts WHERE status = 1");
            } else {
                $cntRes = @mysqli_query($conn, "SELECT COUNT(*) FROM community_posts WHERE board_id = {$bid} AND status = 1");
            }
            $count = 0;
            if ($cntRes && ($r = mysqli_fetch_row($cntRes))) $count = (int)$r[0];
            $list[] = [
                'id' => $bid,
                'name' => $row['name'],
                'icon' => $row['icon'],
                'description' => $row['description'],
                'count' => $count,
            ];
        }
    }
    return $list;
}

// ========== GET: 板块列表 / 帖子列表 ==========
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $currentUid = getCurrentUserId();

    if ($action === 'boards') {
        $boards = getAllBoards($conn);
        jsonReturn(200, 'success', ['boards' => $boards]);
    }

    // 帖子详情 + 评论
    if ($action === 'detail') {
        $postId = (int)($_GET['id'] ?? 0);
        if ($postId <= 0) jsonReturn(400, '参数错误');

        $sql = "SELECT p.id, p.board_id, p.user_id, p.author, p.avatar, p.title, p.content, p.emoji, p.qq, p.create_time, u.avatar_frame FROM community_posts p LEFT JOIN user_info u ON p.user_id = u.id WHERE p.id = ? AND p.status = 1 LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) jsonReturn(500, '查询失败');
        mysqli_stmt_bind_param($stmt, "i", $postId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$row) jsonReturn(404, '帖子不存在');

        $post = [
            "id" => (int)$row["id"],
            "boardId" => (int)$row["board_id"],
            "userId" => (int)$row["user_id"],
            "author" => $row["author"],
            "avatar" => $row["avatar"],
            "title" => $row["title"],
            "content" => $row["content"],
            "emoji" => $row["emoji"],
            "qq" => $row["qq"],
            "time" => $row["create_time"],
            "frameId" => (int)($row["avatar_frame"] ?? 0),
            "isOwn" => ($currentUid > 0 && (int)$row["user_id"] === $currentUid),
        ];

        // 评论列表
        $cSql = "SELECT c.id, c.user_id, c.author, c.avatar, c.content, c.create_time, u.avatar_frame FROM community_comments c LEFT JOIN user_info u ON c.user_id = u.id WHERE c.post_id = ? AND c.status = 1 ORDER BY c.create_time ASC LIMIT 200";
        $stmt = mysqli_prepare($conn, $cSql);
        $comments = [];
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $postId);
            mysqli_stmt_execute($stmt);
            $cRes = mysqli_stmt_get_result($stmt);
            while ($c = mysqli_fetch_assoc($cRes)) {
                $comments[] = [
                    "id" => (int)$c["id"],
                    "userId" => (int)$c["user_id"],
                    "author" => $c["author"],
                    "avatar" => $c["avatar"],
                    "content" => $c["content"],
                    "time" => $c["create_time"],
                    "frameId" => (int)($c["avatar_frame"] ?? 0),
                    "isOwn" => ($currentUid > 0 && (int)$c["user_id"] === $currentUid),
                ];
            }
        }

        jsonReturn(200, 'success', ['post' => $post, 'comments' => $comments]);
    }

    // 帖子列表
    $boardId = isset($_GET['board_id']) ? (int)$_GET['board_id'] : 0;
    $where = ["status = 1"];
    if ($boardId > 1) { // 0或1代表全部动态
        $where[] = "board_id = {$boardId}";
    }
    $whereSql = implode(' AND ', $where);
    $sql = "SELECT p.id, p.board_id, p.user_id, p.author, p.avatar, p.title, p.content, p.emoji, p.qq, p.create_time, u.avatar_frame, (SELECT COUNT(*) FROM community_comments c WHERE c.post_id = p.id AND c.status = 1) AS comment_count FROM community_posts p LEFT JOIN user_info u ON p.user_id = u.id WHERE {$whereSql} ORDER BY p.create_time DESC LIMIT 200";
    $res = @mysqli_query($conn, $sql);
    if (!$res) {
        logError("GET查询失败: " . mysqli_error($conn));
        jsonReturn(500, "查询失败");
    }
    $list = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $list[] = [
            "id" => (int)$row["id"],
            "boardId" => (int)$row["board_id"],
            "userId" => (int)$row["user_id"],
            "author" => $row["author"],
            "avatar" => $row["avatar"],
            "title" => $row["title"],
            "content" => $row["content"],
            "emoji" => $row["emoji"],
            "qq" => $row["qq"],
            "time" => $row["create_time"],
            "commentCount" => (int)($row["comment_count"] ?? 0),
            "frameId" => (int)($row["avatar_frame"] ?? 0),
            "isOwn" => ($currentUid > 0 && (int)$row["user_id"] === $currentUid),
        ];
    }
    $boards = getAllBoards($conn);
    jsonReturn(200, 'success', ['posts' => $list, 'boards' => $boards]);
}

// ========== POST: 发表动态 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentUid = getCurrentUserId();
    if ($currentUid <= 0) {
        jsonReturn(401, "请先登录");
    }

    $raw = file_get_contents("php://input");
    $postData = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonReturn(400, "请求数据格式错误");
    }

    $action = trim($postData['action'] ?? '');

    // ========== 发表评论 ==========
    if ($action === 'comment') {
        $postId = (int)($postData['post_id'] ?? 0);
        $commentContent = trim($postData['content'] ?? '');

        if ($postId <= 0) jsonReturn(400, '参数错误');
        if (empty($commentContent)) jsonReturn(400, '请输入评论内容');
        if (mb_strlen($commentContent) > $maxCommentLen) jsonReturn(400, "评论不能超过{$maxCommentLen}个字符");
        if (filterBadWord($commentContent, $badWords)) jsonReturn(400, '评论包含违规词汇');

        // 检查帖子是否存在
        $checkSql = "SELECT id FROM community_posts WHERE id = ? AND status = 1 LIMIT 1";
        $stmt = mysqli_prepare($conn, $checkSql);
        if (!$stmt) jsonReturn(500, '操作失败');
        mysqli_stmt_bind_param($stmt, "i", $postId);
        mysqli_stmt_execute($stmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) jsonReturn(404, '帖子不存在');

        $ip = getClientIp();

        // 防刷
        $limitSql = "SELECT id FROM community_comments WHERE user_ip = ? AND create_time > DATE_SUB(NOW(), INTERVAL {$commentInterval} SECOND) LIMIT 1";
        $stmt = mysqli_prepare($conn, $limitSql);
        if (!$stmt) jsonReturn(500, '操作失败');
        mysqli_stmt_bind_param($stmt, "s", $ip);
        mysqli_stmt_execute($stmt);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) jsonReturn(403, "操作过于频繁，请{$commentInterval}秒后再试");

        // 用户信息
        $userSql = "SELECT username, nickname, avatar FROM user_info WHERE id = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $userSql);
        if (!$stmt) jsonReturn(500, '操作失败');
        mysqli_stmt_bind_param($stmt, "i", $currentUid);
        mysqli_stmt_execute($stmt);
        $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$userRow) jsonReturn(404, '用户不存在');

        $author = $userRow['nickname'] ?: $userRow['username'];
        $avatar = str_replace(['<','>','"',"'"], '', $userRow['avatar'] ?: '');

        $insertSql = "INSERT INTO community_comments(post_id, user_id, author, avatar, content, user_ip) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insertSql);
        if (!$stmt) jsonReturn(500, '操作失败');
        mysqli_stmt_bind_param($stmt, "iissss", $postId, $currentUid, $author, $avatar, $commentContent, $ip);
        if (!mysqli_stmt_execute($stmt)) jsonReturn(500, '评论失败');

        $insertId = mysqli_insert_id($conn);
        jsonReturn(200, '评论成功', ['id' => $insertId, 'author' => $author, 'avatar' => $avatar]);
    }

    // ========== 发表帖子 ==========
    $content = trim($postData['content'] ?? '');
    $title = trim($postData['title'] ?? '');
    $emoji = trim($postData['emoji'] ?? '');
    $qq = trim($postData['qq'] ?? '');
    $boardId = (int)($postData['board_id'] ?? 0);

    if ($boardId <= 0) {
        jsonReturn(400, "请选择发布板块");
    }
    // 校验板块是否存在（且不是"全部动态"）
    $bRes = @mysqli_query($conn, "SELECT id, name FROM community_boards WHERE id = {$boardId} AND status = 1 LIMIT 1");
    if (!$bRes || !($boardRow = mysqli_fetch_assoc($bRes))) {
        jsonReturn(400, "目标板块不存在");
    }
    if ((int)$boardRow['id'] === 1) {
        jsonReturn(400, "请选择具体的发布板块");
    }

    if (empty($content)) {
        jsonReturn(400, "请输入发表内容");
    }
    if (mb_strlen($title) > $maxTitleLen) {
        jsonReturn(400, "标题不能超过{$maxTitleLen}个字符");
    }
    $len = mb_strlen($content);
    if ($len < $minContentLen) {
        jsonReturn(400, "内容至少{$minContentLen}个字符");
    }
    if ($len > $maxContentLen) {
        jsonReturn(400, "内容不能超过{$maxContentLen}个字符");
    }

    if (empty($qq)) {
        jsonReturn(400, "请输入QQ号");
    }
    if (!preg_match('/^\d{5,11}$/', $qq)) {
        jsonReturn(400, "QQ号格式不正确（5-11位数字）");
    }

    if (filterBadWord($content, $badWords) || filterBadWord($title, $badWords)) {
        jsonReturn(400, "内容包含违规词汇，请修改后重新提交");
    }

    $ip = getClientIp();

    // 防刷
    $limitSql = "SELECT id FROM community_posts WHERE user_ip = ? AND create_time > DATE_SUB(NOW(), INTERVAL {$submitInterval} SECOND) LIMIT 1";
    $stmt = mysqli_prepare($conn, $limitSql);
    if (!$stmt) {
        logError("预处理失败(防刷): " . mysqli_error($conn));
        jsonReturn(500, "数据库操作失败");
    }
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
    $limitRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($limitRow) {
        jsonReturn(403, "操作过于频繁，请{$submitInterval}秒后再提交");
    }

    // 用户信息
    $userSql = "SELECT username, nickname, avatar FROM user_info WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $userSql);
    if (!$stmt) {
        logError("预处理失败(查询用户): " . mysqli_error($conn));
        jsonReturn(500, "数据库操作失败");
    }
    mysqli_stmt_bind_param($stmt, "i", $currentUid);
    mysqli_stmt_execute($stmt);
    $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$userRow) {
        jsonReturn(404, "用户不存在");
    }

    $author = $userRow['nickname'] ?: $userRow['username'];
    $avatar = $userRow['avatar'] ?: '';

    // 安全过滤：头像中去除危险字符
    $avatar = str_replace(['<','>','"',"'"], '', $avatar);

    $insertSql = "INSERT INTO community_posts(board_id, user_id, author, avatar, title, content, emoji, qq, user_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $insertSql);
    if (!$stmt) {
        logError("预处理失败(插入): " . mysqli_error($conn));
        jsonReturn(500, "数据库操作失败");
    }
    $emojiSql = $emoji ?: null;
    mysqli_stmt_bind_param($stmt, "iisssssss", $boardId, $currentUid, $author, $avatar, $title, $content, $emojiSql, $qq, $ip);
    $insertStatus = mysqli_stmt_execute($stmt);

    if (!$insertStatus) {
        logError("插入失败: " . mysqli_stmt_error($stmt));
        jsonReturn(500, "发表失败");
    }

    $insertId = mysqli_insert_id($conn);
    logError("帖子发表成功，ID: {$insertId}, 板块: {$boardId}, 用户: {$author}");
    jsonReturn(200, "发表成功", ["id" => $insertId]);
}

// ========== DELETE: 删除动态 ==========
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $currentUid = getCurrentUserId();
    if ($currentUid <= 0) {
        jsonReturn(401, "请先登录");
    }

    $raw = file_get_contents("php://input");
    $deleteData = json_decode($raw, true);
    $postId = (int)($deleteData['id'] ?? 0);

    if ($postId <= 0) {
        jsonReturn(400, "参数错误");
    }

    $checkSql = "SELECT id, user_id FROM community_posts WHERE id = ? AND status = 1 LIMIT 1";
    $stmt = mysqli_prepare($conn, $checkSql);
    if (!$stmt) {
        logError("删除-查询帖子失败: " . mysqli_error($conn));
        jsonReturn(500, "数据库操作失败");
    }
    mysqli_stmt_bind_param($stmt, "i", $postId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$row) {
        jsonReturn(404, "动态不存在");
    }
    if ((int)$row['user_id'] !== $currentUid) {
        jsonReturn(403, "无权删除此动态");
    }

    $deleteSql = "UPDATE community_posts SET status = 0 WHERE id = ?";
    $stmt = mysqli_prepare($conn, $deleteSql);
    if (!$stmt) {
        logError("删除-预处理失败: " . mysqli_error($conn));
        jsonReturn(500, "数据库操作失败");
    }
    mysqli_stmt_bind_param($stmt, "i", $postId);
    $deleteStatus = mysqli_stmt_execute($stmt);

    if (!$deleteStatus) {
        logError("删除失败: " . mysqli_stmt_error($stmt));
        jsonReturn(500, "删除失败");
    }
    logError("帖子删除成功，ID: {$postId}, 用户ID: {$currentUid}");
    jsonReturn(200, "删除成功");
}

jsonReturn(405, "请求方式不支持");
