<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once 'db.php';

// 自动建表（如果不存在）
$createSql = "CREATE TABLE IF NOT EXISTS `bangumi_follow` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `season_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `media_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `cover` VARCHAR(500) NOT NULL DEFAULT '',
  `total_count` INT(11) NOT NULL DEFAULT 0,
  `follow_count` INT(11) NOT NULL DEFAULT 0,
  `score` DECIMAL(3,1) NOT NULL DEFAULT 0.0,
  `summary` TEXT,
  `area` VARCHAR(100) NOT NULL DEFAULT '',
  `season_type_name` VARCHAR(50) NOT NULL DEFAULT '',
  `url` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `is_visible` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_media_id` (`media_id`),
  KEY `idx_sort` (`sort_order`),
  KEY `idx_visible` (`is_visible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
mysqli_query($conn, $createSql);

// 读取追番列表（仅返回可见的）
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $sql = "SELECT id, season_id, media_id, title, cover, total_count, follow_count,
                   score, summary, area, season_type_name, url
            FROM bangumi_follow
            WHERE is_visible = 1
            ORDER BY sort_order DESC, id ASC";
    $result = mysqli_query($conn, $sql);
    $list = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // 封面代理：B站图片走代理绕过防盗链，其他图片仅修正协议
            if (!empty($row['cover'])) {
                if (strpos($row['cover'], 'hdslb.com') !== false) {
                    $row['cover'] = 'bangumi_cover.php?url=' . urlencode($row['cover']);
                } else {
                    $row['cover'] = str_replace('http://', 'https://', $row['cover']);
                }
            }
            $row['progress'] = $row['total_count'] > 0
                ? round($row['follow_count'] / $row['total_count'] * 100)
                : 0;
            $list[] = $row;
        }
    }
    returnJson(0, 'success', ['list' => $list, 'total' => count($list)]);
}

returnJson(400, '未知操作');
