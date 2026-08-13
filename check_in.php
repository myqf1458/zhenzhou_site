<?php
error_reporting(0);
ini_set('display_errors', 0);

function logError($msg) {
    @file_put_contents(dirname(__FILE__) . '/checkin_error.log', date('[Y-m-d H:i:s]') . ' ' . $msg . "\n", FILE_APPEND);
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
// 给 user_info 加 points 字段
$colRes = @mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'points'");
if (!$colRes || mysqli_num_rows($colRes) === 0) {
    @mysqli_query($conn, "ALTER TABLE user_info ADD COLUMN points INT NOT NULL DEFAULT 0 AFTER avatar");
}

// 签到记录表
@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `check_in_records` (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
    points_earned INT NOT NULL DEFAULT 0,
    continuous_days INT NOT NULL DEFAULT 1,
    check_date DATE NOT NULL,
    create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_date (user_id, check_date),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function getCurrentUserId() {
    return isset($_SESSION['uid']) ? intval($_SESSION['uid']) : 0;
}

function jsonReturn($code, $msg, $data = []) {
    global $conn;
    if ($conn) @mysqli_close($conn);
    echo json_encode(compact('code','msg','data'), JSON_UNESCAPED_UNICODE);
    exit;
}

// 签到奖励规则：连续天数 => 积分
function getRewardPoints($continuousDays) {
    $map = [1 => 10, 2 => 12, 3 => 15, 4 => 18, 5 => 22, 6 => 28, 7 => 35];
    if ($continuousDays >= 7) return 35;
    return $map[$continuousDays] ?? 10;
}

$currentUid = getCurrentUserId();
if ($currentUid <= 0) {
    jsonReturn(401, '请先登录');
}

// ========== GET: 签到状态 ==========
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // 查今天是否已签到
    $stmt = mysqli_prepare($conn, "SELECT id, points_earned, continuous_days, check_date FROM check_in_records WHERE user_id = ? AND check_date = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'is', $currentUid, $today);
    mysqli_stmt_execute($stmt);
    $todayRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // 查昨天是否签到（判断连续）
    $stmt2 = mysqli_prepare($conn, "SELECT continuous_days FROM check_in_records WHERE user_id = ? AND check_date = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt2, 'is', $currentUid, $yesterday);
    mysqli_stmt_execute($stmt2);
    $yesterdayRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));

    // 当前积分
    $stmt3 = mysqli_prepare($conn, "SELECT points FROM user_info WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt3, 'i', $currentUid);
    mysqli_stmt_execute($stmt3);
    $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt3));

    // 连续签到天数
    $continuousDays = 0;
    if ($todayRow) {
        $continuousDays = (int)$todayRow['continuous_days'];
    } elseif ($yesterdayRow) {
        $continuousDays = (int)$yesterdayRow['continuous_days'];
    }

    // 本周签到记录（最近7天）
    $weekStart = date('Y-m-d', strtotime('-6 days'));
    $stmt4 = mysqli_prepare($conn, "SELECT check_date, points_earned FROM check_in_records WHERE user_id = ? AND check_date >= ? ORDER BY check_date ASC");
    mysqli_stmt_bind_param($stmt4, 'is', $currentUid, $weekStart);
    mysqli_stmt_execute($stmt4);
    $weekRes = mysqli_stmt_get_result($stmt4);
    $weekRecords = [];
    while ($r = mysqli_fetch_assoc($weekRes)) {
        $weekRecords[] = ['date' => $r['check_date'], 'points' => (int)$r['points_earned']];
    }

    // 预计今天签到能得多少积分
    $todayContinuous = $todayRow ? (int)$todayRow['continuous_days'] : ($yesterdayRow ? (int)$yesterdayRow['continuous_days'] + 1 : 1);
    $previewPoints = getRewardPoints($todayContinuous);

    jsonReturn(200, 'success', [
        'signedToday' => !empty($todayRow),
        'todayPoints' => $todayRow ? (int)$todayRow['points_earned'] : 0,
        'previewPoints' => $previewPoints,
        'continuousDays' => $continuousDays,
        'todayContinuous' => $todayContinuous,
        'totalPoints' => (int)($userRow['points'] ?? 0),
        'weekRecords' => $weekRecords,
        'rewardTable' => [
            ['day' => 1, 'points' => 10],
            ['day' => 2, 'points' => 12],
            ['day' => 3, 'points' => 15],
            ['day' => 4, 'points' => 18],
            ['day' => 5, 'points' => 22],
            ['day' => 6, 'points' => 28],
            ['day' => 7, 'points' => 35],
        ],
    ]);
}

// ========== POST: 执行签到 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // 检查今天是否已签到
    $stmt = mysqli_prepare($conn, "SELECT id FROM check_in_records WHERE user_id = ? AND check_date = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'is', $currentUid, $today);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        jsonReturn(400, '今天已经签到过了');
    }

    // 查昨天是否签到
    $stmt2 = mysqli_prepare($conn, "SELECT continuous_days FROM check_in_records WHERE user_id = ? AND check_date = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt2, 'is', $currentUid, $yesterday);
    mysqli_stmt_execute($stmt2);
    $yesterdayRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));

    $continuousDays = $yesterdayRow ? (int)$yesterdayRow['continuous_days'] + 1 : 1;
    if ($continuousDays > 7) $continuousDays = 7;

    $rewardPoints = getRewardPoints($continuousDays);

    // 插入签到记录
    $insertSql = "INSERT INTO check_in_records (user_id, points_earned, continuous_days, check_date) VALUES (?, ?, ?, ?)";
    $stmt3 = mysqli_prepare($conn, $insertSql);
    if (!$stmt3) {
        logError('签到插入预处理失败: ' . mysqli_error($conn));
        jsonReturn(500, '操作失败');
    }
    mysqli_stmt_bind_param($stmt3, 'iiis', $currentUid, $rewardPoints, $continuousDays, $today);
    if (!mysqli_stmt_execute($stmt3)) {
        logError('签到插入失败: ' . mysqli_stmt_error($stmt3));
        jsonReturn(500, '签到失败');
    }

    // 增加用户积分
    $updateSql = "UPDATE user_info SET points = points + ? WHERE id = ?";
    $stmt4 = mysqli_prepare($conn, $updateSql);
    mysqli_stmt_bind_param($stmt4, 'ii', $rewardPoints, $currentUid);
    mysqli_stmt_execute($stmt4);

    // 查最新积分
    $stmt5 = mysqli_prepare($conn, "SELECT points FROM user_info WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt5, 'i', $currentUid);
    mysqli_stmt_execute($stmt5);
    $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt5));

    logError("签到成功: uid={$currentUid}, 连续{$continuousDays}天, +{$rewardPoints}积分");

    jsonReturn(200, "签到成功，获得{$rewardPoints}积分", [
        'pointsEarned' => $rewardPoints,
        'continuousDays' => $continuousDays,
        'totalPoints' => (int)($userRow['points'] ?? 0),
    ]);
}

jsonReturn(405, '请求方式不支持');
