<?php
// 追番管理后台
require_once 'config.php';
checkAdminLogin();

$admin = getCurrentAdmin();

// 自动创建追番数据表
$createFollowTable = "
CREATE TABLE IF NOT EXISTS `bangumi_follow` (
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
  UNIQUE KEY `uk_media_id` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
mysqli_query($conn, $createFollowTable);

// 自动创建配置表
$createConfigTable = "
CREATE TABLE IF NOT EXISTS `bangumi_config` (
  `config_key` VARCHAR(50) NOT NULL,
  `config_value` TEXT,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
mysqli_query($conn, $createConfigTable);

// 读取配置辅助函数
function getConfig($conn, $key) {
    $key = mysqli_real_escape_string($conn, $key);
    $result = mysqli_query($conn, "SELECT config_value FROM bangumi_config WHERE config_key = '{$key}'");
    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row['config_value'];
    }
    return '';
}

// 安全转义辅助函数
function esc($conn, $value) {
    return mysqli_real_escape_string($conn, (string)$value);
}

// ===== AJAX 接口处理 =====
if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        // 获取追番列表
        case 'get_list': {
            $list = [];
            $result = mysqli_query($conn, "SELECT * FROM bangumi_follow ORDER BY sort_order DESC, id ASC");
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $row['score'] = (float)$row['score'];
                    $list[] = $row;
                }
            }
            returnJson(200, '获取成功', $list);
            break;
        }

        // 新增或编辑追番
        case 'save': {
            $id = intval($_POST['id'] ?? 0);
            $media_id = intval($_POST['media_id'] ?? 0);
            $season_id = intval($_POST['season_id'] ?? 0);
            $title = esc($conn, $_POST['title'] ?? '');
            $cover = esc($conn, $_POST['cover'] ?? '');
            $total_count = intval($_POST['total_count'] ?? 0);
            $follow_count = intval($_POST['follow_count'] ?? 0);
            $score = floatval($_POST['score'] ?? 0);
            $summary = esc($conn, $_POST['summary'] ?? '');
            $area = esc($conn, $_POST['area'] ?? '');
            $season_type_name = esc($conn, $_POST['season_type_name'] ?? '');
            $url = esc($conn, $_POST['url'] ?? '');
            $sort_order = intval($_POST['sort_order'] ?? 0);
            $is_visible = intval($_POST['is_visible'] ?? 1) ? 1 : 0;

            if ($title === '') {
                returnJson(400, '标题不能为空');
                break;
            }

            if ($id > 0) {
                // 编辑
                $sql = "UPDATE bangumi_follow SET 
                    season_id={$season_id}, media_id={$media_id}, title='{$title}', cover='{$cover}',
                    total_count={$total_count}, follow_count={$follow_count}, score={$score},
                    summary='{$summary}', area='{$area}', season_type_name='{$season_type_name}',
                    url='{$url}', sort_order={$sort_order}, is_visible={$is_visible}
                    WHERE id={$id}";
                if (mysqli_query($conn, $sql)) {
                    returnJson(200, '更新成功');
                } else {
                    returnJson(500, '更新失败: ' . mysqli_error($conn));
                }
            } else {
                // 新增，media_id 重复则更新
                $sql = "INSERT INTO bangumi_follow 
                    (season_id, media_id, title, cover, total_count, follow_count, score, summary, area, season_type_name, url, sort_order, is_visible)
                    VALUES 
                    ({$season_id}, {$media_id}, '{$title}', '{$cover}', {$total_count}, {$follow_count}, {$score}, '{$summary}', '{$area}', '{$season_type_name}', '{$url}', {$sort_order}, {$is_visible})
                    ON DUPLICATE KEY UPDATE 
                    season_id=VALUES(season_id), title=VALUES(title), cover=VALUES(cover),
                    total_count=VALUES(total_count), follow_count=VALUES(follow_count), score=VALUES(score),
                    summary=VALUES(summary), area=VALUES(area), season_type_name=VALUES(season_type_name),
                    url=VALUES(url), sort_order=VALUES(sort_order), is_visible=VALUES(is_visible)";
                if (mysqli_query($conn, $sql)) {
                    returnJson(200, '保存成功');
                } else {
                    returnJson(500, '保存失败: ' . mysqli_error($conn));
                }
            }
            break;
        }

        // 删除追番
        case 'delete': {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                returnJson(400, '无效的ID');
                break;
            }
            if (mysqli_query($conn, "DELETE FROM bangumi_follow WHERE id={$id}")) {
                returnJson(200, '删除成功');
            } else {
                returnJson(500, '删除失败: ' . mysqli_error($conn));
            }
            break;
        }

        // 切换显示状态
        case 'toggle': {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                returnJson(400, '无效的ID');
                break;
            }
            $result = mysqli_query($conn, "UPDATE bangumi_follow SET is_visible = 1 - is_visible WHERE id={$id}");
            if ($result) {
                $visResult = mysqli_query($conn, "SELECT is_visible FROM bangumi_follow WHERE id={$id}");
                $visible = 1;
                if ($visResult && $row = mysqli_fetch_assoc($visResult)) {
                    $visible = (int)$row['is_visible'];
                }
                returnJson(200, '切换成功', ['is_visible' => $visible]);
            } else {
                returnJson(500, '切换失败: ' . mysqli_error($conn));
            }
            break;
        }

        // 获取配置
        case 'get_config': {
            $data = [
                'bilibili_uid' => getConfig($conn, 'bilibili_uid'),
                'bilibili_cookie' => getConfig($conn, 'bilibili_cookie'),
            ];
            returnJson(200, '获取成功', $data);
            break;
        }

        // 保存配置
        case 'save_config': {
            $uid = esc($conn, $_POST['bilibili_uid'] ?? '');
            $cookie = esc($conn, $_POST['bilibili_cookie'] ?? '');
            $ok1 = mysqli_query($conn, "INSERT INTO bangumi_config (config_key, config_value) VALUES ('bilibili_uid', '{$uid}') ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)");
            $ok2 = mysqli_query($conn, "INSERT INTO bangumi_config (config_key, config_value) VALUES ('bilibili_cookie', '{$cookie}') ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)");
            if ($ok1 && $ok2) {
                returnJson(200, '配置已保存');
            } else {
                returnJson(500, '保存失败: ' . mysqli_error($conn));
            }
            break;
        }

        // 从B站同步追番
        case 'sync': {
            $uid = trim(getConfig($conn, 'bilibili_uid'));
            $cookie = trim(getConfig($conn, 'bilibili_cookie'));

            if ($uid === '') {
                returnJson(400, '请先配置B站UID');
                break;
            }

            if (!function_exists('curl_init')) {
                returnJson(500, '服务器未启用 curl 扩展');
                break;
            }

            $apiUrl = "https://api.bilibili.com/x/space/bangumi/follow/list?vmid=" . urlencode($uid) . "&pn=1&ps=30&type=1";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer: https://www.bilibili.com/',
                'Accept: application/json, text/plain, */*',
            ]);
            if ($cookie !== '') {
                curl_setopt($ch, CURLOPT_COOKIE, $cookie);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                returnJson(500, '请求B站接口失败: ' . $curlError);
                break;
            }

            $data = json_decode($response, true);
            if (!isset($data['code']) || $data['code'] !== 0) {
                $msg = $data['message'] ?? ('HTTP ' . $httpCode);
                returnJson(500, 'B站接口返回错误: ' . $msg);
                break;
            }

            $list = $data['data']['list'] ?? [];
            if (empty($list)) {
                returnJson(200, '同步完成，但未获取到追番数据', ['count' => 0]);
                break;
            }

            $syncCount = 0;
            foreach ($list as $item) {
                $media_id = intval($item['media_id'] ?? 0);
                if ($media_id <= 0) {
                    continue;
                }
                $season_id = intval($item['season_id'] ?? 0);
                $title = esc($conn, $item['title'] ?? '');
                $cover = esc($conn, $item['cover'] ?? '');
                $total_count = intval($item['total_count'] ?? 0);
                $follow_count = intval($item['follow_count'] ?? 0);
                $score = floatval($item['score'] ?? 0);
                $summary = esc($conn, $item['summary'] ?? '');
                $season_type_name = esc($conn, $item['season_type_name'] ?? '');

                // 地区：逗号拼接 areas 的 name
                $areaNames = [];
                if (!empty($item['areas']) && is_array($item['areas'])) {
                    foreach ($item['areas'] as $area) {
                        if (isset($area['name'])) {
                            $areaNames[] = $area['name'];
                        }
                    }
                }
                $area = esc($conn, implode(',', $areaNames));
                $url = esc($conn, "https://www.bilibili.com/bangumi/media/md" . $media_id);

                $sql = "INSERT INTO bangumi_follow 
                    (season_id, media_id, title, cover, total_count, follow_count, score, summary, area, season_type_name, url, sort_order, is_visible)
                    VALUES 
                    ({$season_id}, {$media_id}, '{$title}', '{$cover}', {$total_count}, {$follow_count}, {$score}, '{$summary}', '{$area}', '{$season_type_name}', '{$url}', 0, 1)
                    ON DUPLICATE KEY UPDATE 
                    season_id=VALUES(season_id), title=VALUES(title), cover=VALUES(cover),
                    total_count=VALUES(total_count), follow_count=VALUES(follow_count), score=VALUES(score),
                    summary=VALUES(summary), area=VALUES(area), season_type_name=VALUES(season_type_name),
                    url=VALUES(url)";
                if (mysqli_query($conn, $sql)) {
                    $syncCount++;
                }
            }

            returnJson(200, '同步成功，共处理 ' . $syncCount . ' 条追番', ['count' => $syncCount]);
            break;
        }

        default:
            returnJson(400, '未知的操作类型');
            break;
    }
    exit;
}

// 非 AJAX 请求渲染 HTML 页面
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理系统 - 追番管理</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="common.css">
    <style>
        :root {
            --primary: #c9a9d4;
            --primary-dark: #b892c2;
            --primary-light: #f3e8f7;
            --secondary: #e8c5d0;
            --secondary-light: #f9f0f3;
            --success: #a8d5ba;
            --warning: #e8d4a8;
            --danger: #e8b0b0;
            --bg-page: #ffffff;
            --bg-card: rgba(255, 255, 255, 0.85);
            --bg-input: rgba(248, 244, 250, 0.8);
            --border-color: rgba(201, 169, 212, 0.3);
            --text-primary: #4a4556;
            --text-secondary: #8b8594;
            --text-muted: #b5b0bc;
            --sidebar-width: 240px;
            --header-height: 64px;
            --shadow-light: 0 4px 20px rgba(201, 169, 212, 0.1);
            --shadow-hover: 0 8px 30px rgba(201, 169, 212, 0.15);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-page);
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(232, 197, 208, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(201, 169, 212, 0.15) 0%, transparent 50%);
            color: var(--text-primary);
            min-height: 100vh;
        }

        /* 侧边栏 */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-logo .logo-icon {
            width: 36px;
            height: 36px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3);
            border: 2px solid rgba(201, 169, 212, 0.3);
        }

        .sidebar-logo .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .sidebar-logo .logo-text {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .sidebar-menu {
            flex: 1;
            padding: 16px 0;
            overflow-y: auto;
        }

        .menu-item {
            position: relative;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 24px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            border-radius: 0 12px 12px 0;
        }

        .menu-item a:hover {
            background: rgba(201, 169, 212, 0.1);
            color: var(--text-primary);
            border-left-color: rgba(201, 169, 212, 0.5);
        }

        .menu-item a.active {
            background: rgba(201, 169, 212, 0.15);
            color: var(--primary-dark);
            border-left-color: var(--primary);
        }

        .menu-item .icon {
            font-size: 17px;
            width: 20px;
            text-align: center;
        }

        .menu-item .badge {
            margin-left: auto;
            padding: 2px 8px;
            background: rgba(232, 176, 176, 0.2);
            color: var(--danger);
            font-size: 12px;
            border-radius: 10px;
        }

        /* 主内容区 */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        /* 顶部导航栏 */
        .header {
            height: var(--header-height);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-title {
            font-size: 18px;
            font-weight: 600;
        }

        .header-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .header-breadcrumb a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .header-breadcrumb a:hover {
            color: var(--primary-dark);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-action {
            width: 36px;
            height: 36px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .header-action:hover {
            background: rgba(201, 169, 212, 0.15);
            border-color: var(--primary);
            color: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* 页面内容 */
        .page-content {
            padding: 24px;
        }

        /* 卡片通用 */
        .data-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-light);
        }

        .config-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-light);
            background-image: linear-gradient(135deg, rgba(201, 169, 212, 0.05) 0%, rgba(232, 197, 208, 0.05) 100%);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .config-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
            font-family: inherit;
            box-sizing: border-box;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(201, 169, 212, 0.1);
        }

        .form-input::placeholder, .form-textarea::placeholder {
            color: var(--text-muted);
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        /* 按钮 */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
            font-weight: 500;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(201, 169, 212, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            background: var(--bg-input);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: rgba(201, 169, 212, 0.1);
            border-color: var(--primary);
            color: var(--text-primary);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #8fc8a3);
            color: #fff;
            box-shadow: 0 4px 12px rgba(168, 213, 186, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(168, 213, 186, 0.4);
        }

        .config-actions {
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }

        /* 操作栏 */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .toolbar-info {
            color: var(--text-muted);
            font-size: 13px;
        }

        /* 数据表格 */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: rgba(201, 169, 212, 0.08);
            padding: 16px 20px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .data-table td {
            padding: 16px 20px;
            font-size: 14px;
            color: var(--text-primary);
            border-bottom: 1px solid rgba(201, 169, 212, 0.15);
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: rgba(201, 169, 212, 0.08);
        }

        .cover-thumb {
            width: 60px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            background: var(--bg-input);
            box-shadow: 0 2px 8px rgba(201, 169, 212, 0.15);
        }

        .cover-placeholder {
            width: 60px;
            height: 80px;
            border-radius: 8px;
            background: var(--bg-input);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 18px;
        }
        .cover-preview {
            margin-top: 10px;
            width: 90px;
            height: 120px;
            border-radius: 10px;
            background: var(--bg-input);
            border: 1px dashed var(--border-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 12px;
            gap: 6px;
            overflow: hidden;
            position: relative;
        }
        .cover-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px;
            position: absolute;
            inset: 0;
        }
        .cover-preview .preview-icon {
            font-size: 20px;
        }

        .title-cell {
            max-width: 220px;
        }

        .title-text {
            font-weight: 500;
            color: var(--text-primary);
            display: block;
        }

        .title-link {
            font-size: 12px;
            color: var(--text-muted);
            text-decoration: none;
            margin-top: 2px;
            display: inline-block;
        }

        .title-link:hover {
            color: var(--primary-dark);
        }

        .progress-text {
            color: var(--text-secondary);
            font-size: 13px;
        }

        .score-text {
            color: var(--warning);
            font-weight: 600;
        }

        .area-text, .type-text {
            color: var(--text-secondary);
            font-size: 13px;
        }

        .sort-text {
            color: var(--text-muted);
            font-size: 13px;
        }

        /* 切换按钮 */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 42px;
            height: 24px;
            cursor: pointer;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            transition: 0.3s;
        }

        .toggle-slider:before {
            content: "";
            position: absolute;
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background: var(--text-muted);
            border-radius: 50%;
            transition: 0.3s;
        }

        .toggle-switch.on .toggle-slider {
            background: rgba(168, 213, 186, 0.4);
            border-color: var(--success);
        }

        .toggle-switch.on .toggle-slider:before {
            transform: translateX(18px);
            background: var(--success);
        }

        /* 操作按钮 */
        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .action-btn.edit {
            background: rgba(201, 169, 212, 0.15);
            color: var(--primary-dark);
        }

        .action-btn.edit:hover {
            background: rgba(201, 169, 212, 0.25);
            transform: translateY(-1px);
        }

        .action-btn.delete {
            background: rgba(232, 176, 176, 0.15);
            color: var(--danger);
        }

        .action-btn.delete:hover {
            background: rgba(232, 176, 176, 0.25);
            transform: translateY(-1px);
        }

        /* 空状态 */
        .empty {
            padding: 60px;
            text-align: center;
            color: var(--text-muted);
        }

        .empty i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* 弹窗 */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
            padding: 20px 0;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            padding: 24px;
            box-shadow: var(--shadow-hover);
            animation: modalFadeIn 0.3s ease;
            margin: auto;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-close {
            width: 32px;
            height: 32px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(232, 176, 176, 0.2);
            border-color: var(--danger);
            color: var(--danger);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-grid .full {
            grid-column: 1 / -1;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .modal-btn {
            padding: 10px 24px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .modal-btn.cancel {
            background: var(--bg-input);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .modal-btn.cancel:hover {
            background: rgba(201, 169, 212, 0.1);
            border-color: var(--primary);
            color: var(--text-primary);
        }

        .modal-btn.confirm {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3);
        }

        .modal-btn.confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(201, 169, 212, 0.4);
        }

        /* 切换开关样式（弹窗内） */
        .checkbox-switch {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .checkbox-switch input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .checkbox-switch span {
            font-size: 14px;
            color: var(--text-primary);
        }

        /* Toast 提示 */
        .toast-container {
            position: fixed;
            top: 80px;
            right: 24px;
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 14px;
            color: #fff;
            box-shadow: var(--shadow-hover);
            display: flex;
            align-items: center;
            gap: 8px;
            animation: toastIn 0.3s ease;
            max-width: 360px;
        }

        .toast.success {
            background: linear-gradient(135deg, var(--success), #8fc8a3);
        }

        .toast.error {
            background: linear-gradient(135deg, var(--danger), #d49a9a);
        }

        .toast.info {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        @keyframes toastIn {
            from {
                opacity: 0;
                transform: translateX(40px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* 加载动画 */
        .loading-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* 底部信息 */
        .footer {
            text-align: center;
            padding: 24px;
            color: var(--text-muted);
            font-size: 12px;
        }

        /* 响应式 */
        @media (max-width: 1200px) {
            .config-row {
                grid-template-columns: 1fr;
            }
            .form-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                width: 260px;
                transform: translateX(-100%);
                transition: transform 0.3s ease-out;
                z-index: 1600;
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                box-shadow: 4px 0 20px rgba(201, 169, 212, 0.2);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .sidebar-logo .logo-text {
                display: block;
            }

            .menu-item a span:not(.icon) {
                display: block;
            }

            .config-row {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .data-card {
                overflow: visible;
            }

            .data-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
            }

            .data-table thead {
                display: none;
            }

            .data-table tbody {
                display: flex;
                flex-direction: column;
                gap: 12px;
                padding: 12px;
            }

            .data-table tr {
                display: flex;
                flex-direction: column;
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: 12px;
                padding: 12px;
                box-shadow: var(--shadow-light);
            }

            .data-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 0;
                border-bottom: 1px solid rgba(201, 169, 212, 0.1);
                font-size: 13px;
            }

            .data-table td:last-child {
                border-bottom: none;
            }

            .data-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-secondary);
                font-size: 12px;
                flex-shrink: 0;
                margin-right: 12px;
            }

            .toast-container {
                right: 12px;
                left: 12px;
            }

            .toast {
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <!-- 移动端菜单按钮 -->
    <div class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </div>
    
    <!-- 侧边栏遮罩 -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    
    <!-- 侧边栏 -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="logo-icon">
                    <img src="./logo.png" alt="Logo" onerror="this.style.display='none';this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:16px;color:#c9a9d4;background:linear-gradient(135deg, #f3e8f7, #f9f0f3);\'><i class=\'fas fa-crown\'></i></div>';" />
                </div>
                <span class="logo-text">真昼小破站</span>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-item">
                <a href="index.php">
                    <i class="fas fa-chart-pie icon"></i>
                    <span>仪表盘</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="users.php">
                    <i class="fas fa-users icon"></i>
                    <span>用户管理</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="community.php">
                    <i class="fas fa-comments icon"></i>
                    <span>社区管理</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="comments.php">
                    <i class="fas fa-comment-dots icon"></i>
                    <span>留言管理</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="bangumi.php" class="active">
                    <i class="fas fa-tv icon"></i>
                    <span>追番管理</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="reports.php">
                    <i class="fas fa-flag icon"></i>
                    <span>举报处理</span>
                </a>
            </div>
            <?php if (isSuperAdmin()): ?>
            <div class="menu-item">
                <a href="admins.php">
                    <i class="fas fa-shield-alt icon"></i>
                    <span>管理员管理</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="security.php">
                    <i class="fas fa-user-shield icon"></i>
                    <span>安全中心</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="qiniu.php">
                    <i class="fas fa-cloud icon"></i>
                    <span>七牛存储</span>
                </a>
            </div>
            <?php endif; ?>
            <div class="menu-item">
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt icon"></i>
                    <span>退出登录</span>
                </a>
            </div>
        </div>
    </div>

    <!-- 主内容区 -->
    <div class="main-content">
        <!-- 顶部导航栏 -->
        <div class="header">
            <div class="header-left">
                <h1 class="header-title">追番管理</h1>
                <div class="header-breadcrumb">
                    <a href="index.php">首页</a>
                    <span>/</span>
                    <span>追番管理</span>
                </div>
            </div>
            
            <div class="header-right">
                <div class="header-action" title="刷新" onclick="loadList()">
                    <i class="fas fa-sync-alt"></i>
                </div>
                <div class="header-action" title="设置">
                    <i class="fas fa-cog"></i>
                </div>
            </div>
        </div>

        <!-- 页面内容 -->
        <div class="page-content">
            <!-- 配置卡片 -->
            <div class="config-card">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                    <i class="fas fa-cog" style="color: var(--primary-dark);"></i>
                    <h3 style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0;">B站同步配置</h3>
                </div>
                <div class="config-row">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">B站 UID</label>
                        <input type="text" id="bilibiliUid" class="form-input" placeholder="请输入B站用户UID" />
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Cookie（可选，用于访问需登录的接口）</label>
                        <textarea id="bilibiliCookie" class="form-textarea" placeholder="粘贴B站Cookie（可选）" rows="2"></textarea>
                    </div>
                </div>
                <div class="config-actions">
                    <button class="btn btn-primary" onclick="saveConfig()">
                        <i class="fas fa-save"></i>
                        <span>保存配置</span>
                    </button>
                    <button class="btn btn-success" id="syncBtn" onclick="syncBilibili()">
                        <i class="fas fa-cloud-download-alt"></i>
                        <span>从B站同步</span>
                    </button>
                </div>
            </div>

            <!-- 操作栏 -->
            <div class="toolbar">
                <span class="toolbar-info" id="listInfo">共 0 条追番</span>
                <button class="btn btn-primary" onclick="openEdit(0)">
                    <i class="fas fa-plus"></i>
                    <span>添加追番</span>
                </button>
            </div>

            <!-- 数据表格 -->
            <div class="data-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-tv"></i>
                        <span>追番列表</span>
                    </h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>封面</th>
                            <th>标题</th>
                            <th>集数进度</th>
                            <th>评分</th>
                            <th>地区</th>
                            <th>类型</th>
                            <th>排序值</th>
                            <th>显示状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="bangumiTableBody">
                        <tr>
                            <td colspan="9" class="empty">
                                <i class="fas fa-spinner fa-spin"></i>
                                <p>加载中...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 页脚 -->
        <div class="footer">
            <p>&copy; 2026 建湖梦玖信息技术工作室（个体工商户） · 管理后台系统</p>
        </div>
    </div>

    <!-- 编辑弹窗 -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitleText">添加追番</h3>
                <button class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="bangumiForm" onsubmit="return false;">
                    <input type="hidden" id="editId" value="0">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">标题 <span style="color: var(--danger);">*</span></label>
                            <input type="text" id="editTitle" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">封面 URL</label>
                            <input type="text" id="editCover" class="form-input" placeholder="https://..." oninput="updateCoverPreview()">
                            <div class="cover-preview" id="coverPreview">
                                <i class="fas fa-image preview-icon"></i>
                                <span>输入封面URL预览</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">总集数</label>
                            <input type="number" id="editTotalCount" class="form-input" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">已观看集数</label>
                            <input type="number" id="editFollowCount" class="form-input" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">评分（0-10）</label>
                            <input type="number" id="editScore" class="form-input" value="0" step="0.1" min="0" max="10">
                        </div>
                        <div class="form-group">
                            <label class="form-label">地区</label>
                            <input type="text" id="editArea" class="form-input" placeholder="如：日本,中国">
                        </div>
                        <div class="form-group">
                            <label class="form-label">类型</label>
                            <input type="text" id="editSeasonTypeName" class="form-input" placeholder="如：番剧,国创">
                        </div>
                        <div class="form-group">
                            <label class="form-label">排序值（越大越靠前）</label>
                            <input type="number" id="editSortOrder" class="form-input" value="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">season_id</label>
                            <input type="number" id="editSeasonId" class="form-input" value="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">media_id</label>
                            <input type="number" id="editMediaId" class="form-input" value="0">
                        </div>
                        <div class="form-group full">
                            <label class="form-label">简介</label>
                            <textarea id="editSummary" class="form-textarea" placeholder="追番简介"></textarea>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">链接 URL</label>
                            <input type="text" id="editUrl" class="form-input" placeholder="https://www.bilibili.com/bangumi/media/...">
                        </div>
                        <div class="form-group full">
                            <label class="checkbox-switch">
                                <input type="checkbox" id="editIsVisible" checked>
                                <span>显示在前台</span>
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" onclick="closeEditModal()">取消</button>
                <button class="modal-btn confirm" onclick="saveBangumi()">
                    <i class="fas fa-check"></i>
                    <span>保存</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Toast 容器 -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        // 当前列表数据缓存
        var bangumiList = [];

        // Toast 提示
        function toast(msg, type) {
            type = type || 'info';
            var container = document.getElementById('toastContainer');
            var el = document.createElement('div');
            el.className = 'toast ' + type;
            var icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
            el.innerHTML = '<i class="fas ' + icon + '"></i><span>' + escapeHtml(msg) + '</span>';
            container.appendChild(el);
            setTimeout(function() {
                el.style.opacity = '0';
                el.style.transform = 'translateX(40px)';
                el.style.transition = 'all 0.3s ease';
                setTimeout(function() {
                    if (el.parentNode) el.parentNode.removeChild(el);
                }, 300);
            }, 3000);
        }

        // HTML 转义
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // 加载列表
        function loadList() {
            var formData = new FormData();
            formData.append('action', 'get_list');
            fetch('bangumi.php', { method: 'POST', body: formData })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    if (res.code === 200) {
                        bangumiList = res.data || [];
                        renderList();
                    } else {
                        toast(res.msg || '加载失败', 'error');
                    }
                })
                .catch(function(err) {
                    toast('网络错误：' + err.message, 'error');
                });
        }

        // 渲染列表
        function renderList() {
            var tbody = document.getElementById('bangumiTableBody');
            document.getElementById('listInfo').textContent = '共 ' + bangumiList.length + ' 条追番';

            if (bangumiList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="empty"><i class="fas fa-tv"></i><p>暂无追番数据</p></td></tr>';
                return;
            }

            var html = '';
            bangumiList.forEach(function(item) {
                var coverSrc = coverUrl(item.cover);
                var coverHtml = coverSrc
                    ? '<div class="cover-placeholder"><img src="' + escapeHtml(coverSrc) + '" class="cover-thumb" onerror="this.onerror=null;this.style.display=\'none\';"></div>'
                    : '<div class="cover-placeholder"><i class="fas fa-image"></i></div>';

                var total = item.total_count || 0;
                var follow = item.follow_count || 0;
                var progressText = follow + ' / ' + total;

                var scoreText = (parseFloat(item.score) || 0).toFixed(1);
                if (scoreText === '0.0') scoreText = '-';

                var visibleClass = item.is_visible == 1 ? 'on' : '';
                var visibleChecked = item.is_visible == 1 ? 'checked' : '';

                var titleHtml = '<span class="title-text">' + escapeHtml(item.title || '') + '</span>';
                if (item.url) {
                    titleHtml += '<a href="' + escapeHtml(item.url) + '" target="_blank" class="title-link"><i class="fas fa-external-link-alt"></i> 查看详情</a>';
                }

                html += '<tr>' +
                    '<td data-label="封面">' + coverHtml + '</td>' +
                    '<td data-label="标题" class="title-cell">' + titleHtml + '</td>' +
                    '<td data-label="集数进度"><span class="progress-text">' + progressText + '</span></td>' +
                    '<td data-label="评分"><span class="score-text">' + scoreText + '</span></td>' +
                    '<td data-label="地区"><span class="area-text">' + escapeHtml(item.area || '-') + '</span></td>' +
                    '<td data-label="类型"><span class="type-text">' + escapeHtml(item.season_type_name || '-') + '</span></td>' +
                    '<td data-label="排序值"><span class="sort-text">' + (item.sort_order || 0) + '</span></td>' +
                    '<td data-label="显示状态"><div class="toggle-switch ' + visibleClass + '" onclick="toggleVisible(' + item.id + ')"><input type="checkbox" ' + visibleChecked + '><span class="toggle-slider"></span></div></td>' +
                    '<td data-label="操作"><div class="action-buttons">' +
                        '<button class="action-btn edit" onclick="openEdit(' + item.id + ')"><i class="fas fa-edit"></i><span>编辑</span></button>' +
                        '<button class="action-btn delete" onclick="deleteBangumi(' + item.id + ')"><i class="fas fa-trash"></i><span>删除</span></button>' +
                    '</div></td>' +
                '</tr>';
            });
            tbody.innerHTML = html;
        }

        // 打开编辑弹窗
        function openEdit(id) {
            var modal = document.getElementById('editModal');
            var form = document.getElementById('bangumiForm');
            form.reset();

            if (id && id > 0) {
                var item = bangumiList.find(function(b) { return b.id == id; });
                if (item) {
                    document.getElementById('modalTitleText').textContent = '编辑追番';
                    document.getElementById('editId').value = item.id;
                    document.getElementById('editTitle').value = item.title || '';
                    document.getElementById('editCover').value = item.cover || '';
                    document.getElementById('editTotalCount').value = item.total_count || 0;
                    document.getElementById('editFollowCount').value = item.follow_count || 0;
                    document.getElementById('editScore').value = item.score || 0;
                    document.getElementById('editArea').value = item.area || '';
                    document.getElementById('editSeasonTypeName').value = item.season_type_name || '';
                    document.getElementById('editSortOrder').value = item.sort_order || 0;
                    document.getElementById('editSeasonId').value = item.season_id || 0;
                    document.getElementById('editMediaId').value = item.media_id || 0;
                    document.getElementById('editSummary').value = item.summary || '';
                    document.getElementById('editUrl').value = item.url || '';
                    document.getElementById('editIsVisible').checked = item.is_visible == 1;
                }
            } else {
                document.getElementById('modalTitleText').textContent = '添加追番';
                document.getElementById('editId').value = 0;
                document.getElementById('editIsVisible').checked = true;
            }
            modal.classList.add('active');
            updateCoverPreview();
        }

        // 更新封面预览
        function updateCoverPreview() {
            var url = document.getElementById('editCover').value.trim();
            var preview = document.getElementById('coverPreview');
            preview.innerHTML = '';
            if (!url) {
                var icon = document.createElement('i');
                icon.className = 'fas fa-image preview-icon';
                var tip = document.createElement('span');
                tip.textContent = '输入封面URL预览';
                preview.appendChild(icon);
                preview.appendChild(tip);
                return;
            }
            var img = document.createElement('img');
            img.src = coverUrl(url);
            img.onerror = function() {
                preview.innerHTML = '';
                var warn = document.createElement('i');
                warn.className = 'fas fa-exclamation-triangle preview-icon';
                var tip = document.createElement('span');
                tip.textContent = '图片加载失败';
                preview.appendChild(warn);
                preview.appendChild(tip);
            };
            preview.appendChild(img);
        }

        // 封面URL代理：B站图片走代理绕过防盗链
        function coverUrl(url) {
            if (!url) return '';
            if (url.indexOf('hdslb.com') !== -1) {
                return '../bangumi_cover.php?url=' + encodeURIComponent(url);
            }
            return url;
        }

        // 关闭编辑弹窗
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        // 保存追番
        function saveBangumi() {
            var title = document.getElementById('editTitle').value.trim();
            if (!title) {
                toast('标题不能为空', 'error');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'save');
            formData.append('id', document.getElementById('editId').value);
            formData.append('title', title);
            formData.append('cover', document.getElementById('editCover').value);
            formData.append('total_count', document.getElementById('editTotalCount').value);
            formData.append('follow_count', document.getElementById('editFollowCount').value);
            formData.append('score', document.getElementById('editScore').value);
            formData.append('summary', document.getElementById('editSummary').value);
            formData.append('area', document.getElementById('editArea').value);
            formData.append('season_type_name', document.getElementById('editSeasonTypeName').value);
            formData.append('url', document.getElementById('editUrl').value);
            formData.append('season_id', document.getElementById('editSeasonId').value);
            formData.append('media_id', document.getElementById('editMediaId').value);
            formData.append('sort_order', document.getElementById('editSortOrder').value);
            formData.append('is_visible', document.getElementById('editIsVisible').checked ? 1 : 0);

            fetch('bangumi.php', { method: 'POST', body: formData })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    if (res.code === 200) {
                        toast(res.msg || '保存成功', 'success');
                        closeEditModal();
                        loadList();
                    } else {
                        toast(res.msg || '保存失败', 'error');
                    }
                })
                .catch(function(err) {
                    toast('网络错误：' + err.message, 'error');
                });
        }

        // 删除追番
        function deleteBangumi(id) {
            if (!confirm('确定要删除该追番吗？删除后将无法恢复！')) return;

            var formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('bangumi.php', { method: 'POST', body: formData })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    if (res.code === 200) {
                        toast(res.msg || '删除成功', 'success');
                        loadList();
                    } else {
                        toast(res.msg || '删除失败', 'error');
                    }
                })
                .catch(function(err) {
                    toast('网络错误：' + err.message, 'error');
                });
        }

        // 切换显示状态
        function toggleVisible(id) {
            var formData = new FormData();
            formData.append('action', 'toggle');
            formData.append('id', id);

            fetch('bangumi.php', { method: 'POST', body: formData })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    if (res.code === 200) {
                        toast(res.msg || '切换成功', 'success');
                        loadList();
                    } else {
                        toast(res.msg || '切换失败', 'error');
                    }
                })
                .catch(function(err) {
                    toast('网络错误：' + err.message, 'error');
                });
        }

        // 加载配置
        function loadConfig() {
            var formData = new FormData();
            formData.append('action', 'get_config');
            fetch('bangumi.php', { method: 'POST', body: formData })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    if (res.code === 200 && res.data) {
                        document.getElementById('bilibiliUid').value = res.data.bilibili_uid || '';
                        document.getElementById('bilibiliCookie').value = res.data.bilibili_cookie || '';
                    }
                })
                .catch(function(err) {
                    // 静默处理
                });
        }

        // 保存配置
        function saveConfig() {
            var formData = new FormData();
            formData.append('action', 'save_config');
            formData.append('bilibili_uid', document.getElementById('bilibiliUid').value);
            formData.append('bilibili_cookie', document.getElementById('bilibiliCookie').value);

            fetch('bangumi.php', { method: 'POST', body: formData })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    if (res.code === 200) {
                        toast(res.msg || '配置已保存', 'success');
                    } else {
                        toast(res.msg || '保存失败', 'error');
                    }
                })
                .catch(function(err) {
                    toast('网络错误：' + err.message, 'error');
                });
        }

        // 从B站同步
        function syncBilibili() {
            var uid = document.getElementById('bilibiliUid').value.trim();
            if (!uid) {
                toast('请先填写B站 UID', 'error');
                return;
            }

            var btn = document.getElementById('syncBtn');
            var originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading-spinner"></span><span>同步中...</span>';

            var formData = new FormData();
            formData.append('action', 'sync');

            fetch('bangumi.php', { method: 'POST', body: formData })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    if (res.code === 200) {
                        toast(res.msg || '同步成功', 'success');
                        loadList();
                    } else {
                        toast(res.msg || '同步失败', 'error');
                    }
                })
                .catch(function(err) {
                    toast('网络错误：' + err.message, 'error');
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                });
        }

        // 点击遮罩层关闭弹窗
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        // ESC 键关闭弹窗
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
            }
        });

        // 移动端侧边栏切换
        function toggleSidebar() {
            var sidebar = document.getElementById('sidebar');
            var overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }

        // 点击侧边栏链接时关闭侧边栏（移动端）
        document.querySelectorAll('.sidebar-menu a').forEach(function(link) {
            link.addEventListener('click', function() {
                var sidebar = document.getElementById('sidebar');
                var overlay = document.getElementById('sidebarOverlay');
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            });
        });

        // 页面加载时初始化
        document.addEventListener('DOMContentLoaded', function() {
            loadConfig();
            loadList();
        });
    </script>
</body>
</html>
