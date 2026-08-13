<?php
error_reporting(0);
ini_set('display_errors', 0);

function logError($msg) {
    @file_put_contents(dirname(__FILE__) . '/shop_error.log', date('[Y-m-d H:i:s]') . ' ' . $msg . "\n", FILE_APPEND);
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

// ========== 自动建表/升级 ==========
$colRes = @mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'points'");
if (!$colRes || mysqli_num_rows($colRes) === 0) {
    @mysqli_query($conn, "ALTER TABLE user_info ADD COLUMN points INT NOT NULL DEFAULT 0 AFTER avatar");
}

// 商品表
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `shop_items` (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL DEFAULT '',
    description VARCHAR(500) NOT NULL DEFAULT '',
    price INT NOT NULL DEFAULT 0,
    category VARCHAR(50) NOT NULL DEFAULT 'normal',
    stock INT NOT NULL DEFAULT -1,
    status TINYINT UNSIGNED NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_sort (status, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 兑换记录表
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `exchange_records` (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
    item_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
    item_name VARCHAR(100) NOT NULL DEFAULT '',
    cost_points INT NOT NULL DEFAULT 0,
    status TINYINT UNSIGNED NOT NULL DEFAULT 0,
    note VARCHAR(500) DEFAULT '',
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_item_id (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 写入默认商品
$defaultItems = [
    ['name' => '自定义头衔', 'description' => '在社区显示自定义头衔，有效期30天', 'price' => 200, 'category' => 'privilege', 'sort' => 10],
    ['name' => '更换头像框', 'description' => '解锁精选头像边框装饰，永久有效', 'price' => 150, 'category' => 'cosmetic', 'sort' => 20],
    ['name' => '置顶帖子x1', 'description' => '将一条帖子置顶显示，有效期7天', 'price' => 100, 'category' => 'privilege', 'sort' => 30],
    ['name' => '签到双倍卡', 'description' => '下次签到获得双倍积分，有效期3天', 'price' => 80, 'category' => 'buff', 'sort' => 40],
    ['name' => '改名卡', 'description' => '修改一次社区昵称', 'price' => 300, 'category' => 'privilege', 'sort' => 50],
    ['name' => '专属铭牌', 'description' => '在帖子作者旁显示专属铭牌标记，永久有效', 'price' => 500, 'category' => 'cosmetic', 'sort' => 60],
];
$cntRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM shop_items");
$cntRow = $cntRes ? mysqli_fetch_assoc($cntRes) : ['c' => 0];
if ((int)$cntRow['c'] === 0) {
    foreach ($defaultItems as $item) {
        $nm = mysqli_real_escape_string($conn, $item['name']);
        $de = mysqli_real_escape_string($conn, $item['description']);
        $pr = (int)$item['price'];
        $ca = mysqli_real_escape_string($conn, $item['category']);
        $so = (int)$item['sort'];
        @mysqli_query($conn, "INSERT INTO shop_items (name, description, price, category, stock, sort) VALUES ('$nm', '$de', $pr, '$ca', -1, $so)");
    }
}

// ========== 头像框表 ==========
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `avatar_frames` (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL DEFAULT '',
    border_css VARCHAR(500) NOT NULL DEFAULT '',
    glow_css VARCHAR(500) NOT NULL DEFAULT '',
    preview_color VARCHAR(20) NOT NULL DEFAULT '#ff69b4',
    price INT NOT NULL DEFAULT 0,
    status TINYINT UNSIGNED NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_sort (status, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 默认头像框
$defaultFrames = [
    ['name' => '粉色花环', 'border_css' => '2.5px solid #ff69b4', 'glow_css' => '0 0 8px rgba(255,105,180,0.4)', 'preview_color' => '#ff69b4', 'price' => 0, 'sort' => 10],
    ['name' => '蓝色光环', 'border_css' => '2.5px solid #5b9bd5', 'glow_css' => '0 0 8px rgba(91,155,213,0.4)', 'preview_color' => '#5b9bd5', 'price' => 50, 'sort' => 20],
    ['name' => '金色辉光', 'border_css' => '2.5px solid #f0c040', 'glow_css' => '0 0 10px rgba(240,192,64,0.5)', 'preview_color' => '#f0c040', 'price' => 100, 'sort' => 30],
    ['name' => '紫色梦境', 'border_css' => '2.5px solid #9b59b6', 'glow_css' => '0 0 10px rgba(155,89,182,0.5)', 'preview_color' => '#9b59b6', 'price' => 100, 'sort' => 40],
    ['name' => '渐变流光', 'border_css' => '2.5px solid', 'glow_css' => '0 0 12px rgba(255,105,180,0.5)', 'preview_color' => 'linear-gradient(135deg,#ff69b4,#5b9bd5)', 'price' => 200, 'sort' => 50],
    ['name' => '霓虹闪烁', 'border_css' => '3px solid #00e5ff', 'glow_css' => '0 0 12px rgba(0,229,255,0.6), 0 0 4px rgba(0,229,255,0.4)', 'preview_color' => '#00e5ff', 'price' => 300, 'sort' => 60],
];
$fcntRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM avatar_frames");
$fcntRow = $fcntRes ? mysqli_fetch_assoc($fcntRes) : ['c' => 0];
if ((int)$fcntRow['c'] === 0) {
    foreach ($defaultFrames as $f) {
        $nm = mysqli_real_escape_string($conn, $f['name']);
        $bc = mysqli_real_escape_string($conn, $f['border_css']);
        $gc = mysqli_real_escape_string($conn, $f['glow_css']);
        $pc = mysqli_real_escape_string($conn, $f['preview_color']);
        $pr = (int)$f['price'];
        $so = (int)$f['sort'];
        @mysqli_query($conn, "INSERT INTO avatar_frames (name, border_css, glow_css, preview_color, price, sort) VALUES ('$nm', '$bc', '$gc', '$pc', $pr, $so)");
    }
}

// user_info 添加 avatar_frame 字段
$afCol = @mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'avatar_frame'");
if (!$afCol || mysqli_num_rows($afCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE user_info ADD COLUMN avatar_frame INT NOT NULL DEFAULT 0 AFTER avatar");
}

// 用户头像框拥有记录表
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `user_frames` (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
    frame_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_frame (user_id, frame_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 默认所有用户拥有 frame 1（粉色花环，免费）
$freeFrameId = 1;
$ownsCheck = @mysqli_query($conn, "SELECT id FROM user_frames WHERE user_id = {$currentUid} AND frame_id = {$freeFrameId} LIMIT 1");
if (!$ownsCheck || mysqli_num_rows($ownsCheck) === 0) {
    @mysqli_query($conn, "INSERT IGNORE INTO user_frames (user_id, frame_id) VALUES ({$currentUid}, {$freeFrameId})");
}

function getCurrentUserId() {
    return isset($_SESSION['uid']) ? intval($_SESSION['uid']) : 0;
}

function jsonReturn($code, $msg, $data = []) {
    global $conn;
    if ($conn) @mysqli_close($conn);
    echo json_encode(compact('code','msg','data'), JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUid = getCurrentUserId();
if ($currentUid <= 0) {
    jsonReturn(401, '请先登录');
}

// ========== GET: 商品列表 + 兑换记录 + 积分 ==========
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 用户积分
    $stmt = mysqli_prepare($conn, "SELECT points FROM user_info WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $currentUid);
    mysqli_stmt_execute($stmt);
    $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // 商品列表
    $items = [];
    $res = @mysqli_query($conn, "SELECT id, name, description, price, category, stock, sort FROM shop_items WHERE status = 1 ORDER BY sort ASC, id ASC");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'description' => $r['description'],
                'price' => (int)$r['price'],
                'category' => $r['category'],
                'stock' => (int)$r['stock'],
            ];
        }
    }

    // 兑换记录（最近20条）
    $records = [];
    $stmt2 = mysqli_prepare($conn, "SELECT id, item_name, cost_points, status, note, create_time FROM exchange_records WHERE user_id = ? ORDER BY create_time DESC LIMIT 20");
    mysqli_stmt_bind_param($stmt2, 'i', $currentUid);
    mysqli_stmt_execute($stmt2);
    $recRes = mysqli_stmt_get_result($stmt2);
    while ($r = mysqli_fetch_assoc($recRes)) {
        $records[] = [
            'id' => (int)$r['id'],
            'itemName' => $r['item_name'],
            'costPoints' => (int)$r['cost_points'],
            'status' => (int)$r['status'],
            'note' => $r['note'],
            'time' => $r['create_time'],
        ];
    }

    // 头像框列表
    $frames = [];
    $fRes = @mysqli_query($conn, "SELECT id, name, border_css, glow_css, preview_color, price, sort FROM avatar_frames WHERE status = 1 ORDER BY sort ASC, id ASC");
    if ($fRes) {
        while ($r = mysqli_fetch_assoc($fRes)) {
            $frames[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'borderCss' => $r['border_css'],
                'glowCss' => $r['glow_css'],
                'previewColor' => $r['preview_color'],
                'price' => (int)$r['price'],
            ];
        }
    }

    // 用户已拥有的头像框
    $ownedFrames = [];
    $ofStmt = mysqli_prepare($conn, "SELECT frame_id FROM user_frames WHERE user_id = ?");
    if ($ofStmt) {
        mysqli_stmt_bind_param($ofStmt, 'i', $currentUid);
        mysqli_stmt_execute($ofStmt);
        $ofRes = mysqli_stmt_get_result($ofStmt);
        while ($r = mysqli_fetch_assoc($ofRes)) $ownedFrames[] = (int)$r['frame_id'];
    }

    // 用户当前装备的头像框
    $afStmt = mysqli_prepare($conn, "SELECT avatar_frame FROM user_info WHERE id = ? LIMIT 1");
    if ($afStmt) {
        mysqli_stmt_bind_param($afStmt, 'i', $currentUid);
        mysqli_stmt_execute($afStmt);
        $afRow = mysqli_fetch_assoc(mysqli_stmt_get_result($afStmt));
        $userRow['avatar_frame'] = $afRow['avatar_frame'] ?? 0;
    }

    // 构建 frameMap（id -> css 属性，供前端渲染用）
    $frameMap = [];
    foreach ($frames as $f) {
        $frameMap[$f['id']] = [
            'borderCss' => $f['borderCss'],
            'glowCss' => $f['glowCss'],
        ];
    }

    jsonReturn(200, 'success', [
        'totalPoints' => (int)($userRow['points'] ?? 0),
        'items' => $items,
        'records' => $records,
        'frames' => $frames,
        'ownedFrames' => $ownedFrames,
        'equippedFrame' => (int)($userRow['avatar_frame'] ?? 0),
        'frameMap' => $frameMap,
    ]);
}

// ========== POST: 兑换商品 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $postData = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonReturn(400, '请求数据格式错误');
    }

    $itemId = (int)($postData['item_id'] ?? 0);
    $postAction = trim($postData['action'] ?? '');

    // ========== 购买头像框 ==========
    if ($postAction === 'buy_frame') {
        $frameId = (int)($postData['frame_id'] ?? 0);
        if ($frameId <= 0) jsonReturn(400, '参数错误');

        // 查头像框
        $fStmt = mysqli_prepare($conn, "SELECT id, name, price, status FROM avatar_frames WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($fStmt, 'i', $frameId);
        mysqli_stmt_execute($fStmt);
        $frameRow = mysqli_fetch_assoc(mysqli_stmt_get_result($fStmt));
        if (!$frameRow) jsonReturn(404, '头像框不存在');
        if ((int)$frameRow['status'] !== 1) jsonReturn(400, '头像框已下架');

        // 检查是否已拥有
        $ownStmt = mysqli_prepare($conn, "SELECT id FROM user_frames WHERE user_id = ? AND frame_id = ? LIMIT 1");
        mysqli_stmt_bind_param($ownStmt, 'ii', $currentUid, $frameId);
        mysqli_stmt_execute($ownStmt);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($ownStmt))) jsonReturn(200, '已拥有该头像框');

        $framePrice = (int)$frameRow['price'];

        // 免费框直接发放
        if ($framePrice === 0) {
            $insStmt = mysqli_prepare($conn, "INSERT IGNORE INTO user_frames (user_id, frame_id) VALUES (?, ?)");
            mysqli_stmt_bind_param($insStmt, 'ii', $currentUid, $frameId);
            mysqli_stmt_execute($insStmt);
            jsonReturn(200, '领取成功');
        }

        // 查积分
        $pStmt = mysqli_prepare($conn, "SELECT points FROM user_info WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($pStmt, 'i', $currentUid);
        mysqli_stmt_execute($pStmt);
        $pRow = mysqli_fetch_assoc(mysqli_stmt_get_result($pStmt));
        if ((int)($pRow['points'] ?? 0) < $framePrice) jsonReturn(403, '积分不足');

        // 扣积分
        $deductStmt = mysqli_prepare($conn, "UPDATE user_info SET points = points - ? WHERE id = ? AND points >= ?");
        mysqli_stmt_bind_param($deductStmt, 'iii', $framePrice, $currentUid, $framePrice);
        mysqli_stmt_execute($deductStmt);
        if (mysqli_affected_rows($conn) === 0) jsonReturn(403, '积分不足');

        // 写拥有记录
        $insStmt = mysqli_prepare($conn, "INSERT IGNORE INTO user_frames (user_id, frame_id) VALUES (?, ?)");
        mysqli_stmt_bind_param($insStmt, 'ii', $currentUid, $frameId);
        mysqli_stmt_execute($insStmt);

        // 写兑换记录
        $frameName = $frameRow['name'];
        $recStmt = mysqli_prepare($conn, "INSERT INTO exchange_records (user_id, item_id, item_name, cost_points, status, note) VALUES (?, 0, ?, ?, 1, '头像框')");
        mysqli_stmt_bind_param($recStmt, 'isi', $currentUid, $frameName, $framePrice);
        mysqli_stmt_execute($recStmt);

        logError("头像框购买: uid={$currentUid}, frame={$frameName}, cost={$framePrice}");
        jsonReturn(200, "购买成功：{$frameName}");
    }

    // ========== 装备/卸下头像框 ==========
    if ($postAction === 'equip_frame') {
        $frameId = (int)($postData['frame_id'] ?? 0);

        if ($frameId === 0) {
            // 卸下头像框
            $unStmt = mysqli_prepare($conn, "UPDATE user_info SET avatar_frame = 0 WHERE id = ?");
            mysqli_stmt_bind_param($unStmt, 'i', $currentUid);
            mysqli_stmt_execute($unStmt);
            jsonReturn(200, '已卸下头像框');
        }

        // 检查是否拥有
        $ownStmt = mysqli_prepare($conn, "SELECT id FROM user_frames WHERE user_id = ? AND frame_id = ? LIMIT 1");
        mysqli_stmt_bind_param($ownStmt, 'ii', $currentUid, $frameId);
        mysqli_stmt_execute($ownStmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($ownStmt))) jsonReturn(403, '尚未拥有该头像框');

        // 装备
        $eqStmt = mysqli_prepare($conn, "UPDATE user_info SET avatar_frame = ? WHERE id = ?");
        mysqli_stmt_bind_param($eqStmt, 'ii', $frameId, $currentUid);
        mysqli_stmt_execute($eqStmt);
        jsonReturn(200, '装备成功');
    }

    if ($itemId <= 0 && !$postAction) {
        jsonReturn(400, '参数错误');
    }
    if ($itemId <= 0) {
        jsonReturn(400, '参数错误');
    }

    // 查商品
    $stmt = mysqli_prepare($conn, "SELECT id, name, price, stock, status FROM shop_items WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $itemId);
    mysqli_stmt_execute($stmt);
    $itemRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$itemRow) {
        jsonReturn(404, '商品不存在');
    }
    if ((int)$itemRow['status'] !== 1) {
        jsonReturn(400, '商品已下架');
    }
    if ((int)$itemRow['stock'] === 0) {
        jsonReturn(400, '库存不足');
    }

    $price = (int)$itemRow['price'];

    // 查用户积分
    $stmt2 = mysqli_prepare($conn, "SELECT points FROM user_info WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt2, 'i', $currentUid);
    mysqli_stmt_execute($stmt2);
    $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));
    $currentPoints = (int)($userRow['points'] ?? 0);

    if ($currentPoints < $price) {
        jsonReturn(403, '积分不足，还差' . ($price - $currentPoints) . '积分');
    }

    // 扣积分
    $updateSql = "UPDATE user_info SET points = points - ? WHERE id = ? AND points >= ?";
    $stmt3 = mysqli_prepare($conn, $updateSql);
    if (!$stmt3) {
        logError('扣积分预处理失败: ' . mysqli_error($conn));
        jsonReturn(500, '操作失败');
    }
    mysqli_stmt_bind_param($stmt3, 'iii', $price, $currentUid, $price);
    mysqli_stmt_execute($stmt3);
    if (mysqli_affected_rows($conn) === 0) {
        jsonReturn(403, '积分不足');
    }

    // 写兑换记录
    $itemName = $itemRow['name'];
    $insertSql = "INSERT INTO exchange_records (user_id, item_id, item_name, cost_points, status, note) VALUES (?, ?, ?, ?, 0, '')";
    $stmt4 = mysqli_prepare($conn, $insertSql);
    mysqli_stmt_bind_param($stmt4, 'iisi', $currentUid, $itemId, $itemName, $price);
    mysqli_stmt_execute($stmt4);

    // 减库存（如果非无限）
    if ((int)$itemRow['stock'] > 0) {
        $stmt5 = mysqli_prepare($conn, "UPDATE shop_items SET stock = stock - 1 WHERE id = ?");
        mysqli_stmt_bind_param($stmt5, 'i', $itemId);
        mysqli_stmt_execute($stmt5);
    }

    // 查最新积分
    $stmt6 = mysqli_prepare($conn, "SELECT points FROM user_info WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt6, 'i', $currentUid);
    mysqli_stmt_execute($stmt6);
    $updatedRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt6));

    logError("兑换成功: uid={$currentUid}, item={$itemName}, cost={$price}");

    jsonReturn(200, "兑换成功：{$itemName}", [
        'totalPoints' => (int)($updatedRow['points'] ?? 0),
    ]);
}

jsonReturn(405, '请求方式不支持');
