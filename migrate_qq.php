<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';

$result = [];

// 检查并添加 qq_openid 字段
$check = @mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'qq_openid'");
if ($check && mysqli_num_rows($check) == 0) {
    $alterSql = "ALTER TABLE user_info ADD COLUMN qq_openid VARCHAR(100) DEFAULT NULL AFTER gender";
    if (mysqli_query($conn, $alterSql)) {
        $result[] = 'qq_openid 字段添加成功';
    } else {
        $result[] = 'qq_openid 字段添加失败: ' . mysqli_error($conn);
    }
} else {
    $result[] = 'qq_openid 字段已存在';
}

// 检查并添加索引
$checkIdx = @mysqli_query($conn, "SHOW INDEX FROM user_info WHERE Key_name = 'idx_qq_openid'");
if ($checkIdx && mysqli_num_rows($checkIdx) == 0) {
    $idxSql = "ALTER TABLE user_info ADD INDEX idx_qq_openid (qq_openid)";
    if (mysqli_query($conn, $idxSql)) {
        $result[] = 'qq_openid 索引添加成功';
    } else {
        $result[] = 'qq_openid 索引添加失败: ' . mysqli_error($conn);
    }
} else {
    $result[] = 'qq_openid 索引已存在';
}

// 检查并添加 nickname 字段（如果不存在）
$checkNick = @mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'nickname'");
if ($checkNick && mysqli_num_rows($checkNick) == 0) {
    $alterNick = "ALTER TABLE user_info ADD COLUMN nickname VARCHAR(50) DEFAULT '' AFTER username";
    if (mysqli_query($conn, $alterNick)) {
        $result[] = 'nickname 字段添加成功';
    } else {
        $result[] = 'nickname 字段添加失败: ' . mysqli_error($conn);
    }
}

echo json_encode(['code' => 200, 'msg' => '迁移完成', 'data' => $result], JSON_UNESCAPED_UNICODE);
exit;