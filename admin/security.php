<?php
require 'config.php';
checkAdminLogin();

if (!isSuperAdmin()) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>无权限</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script>';
    echo '<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5;margin:0;font-family:sans-serif;}';
    echo '.box{text-align:center;padding:40px;background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.1);}';
    echo 'h1{color:#e8b0b0;margin:0 0 16px;}p{color:#8b8594;margin:0 0 24px;}';
    echo 'a{display:inline-block;padding:12px 24px;background:linear-gradient(135deg,#c9a9d4,#e8c5d0);color:#fff;text-decoration:none;border-radius:12px;}</style></head><body>';
    echo '<div class="box"><h1><i class="fas fa-lock"></i> 无权限访问</h1><p>只有超级管理员才能访问安全中心</p><a href="index.php">返回首页</a></div></body></html>';
    exit;
}

$admin = getCurrentAdmin();

function cidrToRange($cidr) {
    if (strpos($cidr, '/') === false) return false;
    list($ip, $prefix) = explode('/', $cidr);
    $prefix = intval($prefix);
    if ($prefix < 0 || $prefix > 32) return false;
    $ipLong = ip2long($ip);
    if ($ipLong === false) return false;
    $mask = $prefix == 0 ? 0 : (~((1 << (32 - $prefix)) - 1));
    $from = $ipLong & $mask;
    $to = $from + (~$mask);
    return ['from' => $from, 'to' => $to];
}

if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_REQUEST['action'];

    switch ($action) {
        case 'get_logs':
            $page = max(1, intval($_REQUEST['page'] ?? 1));
            $page_size = max(1, min(100, intval($_REQUEST['page_size'] ?? 20)));
            $offset = ($page - 1) * $page_size;

            $where = [];
            if (!empty($_REQUEST['ip'])) {
                $ip = SecGuard::esc($_REQUEST['ip']);
                $where[] = "ip LIKE '%{$ip}%'";
            }
            if (!empty($_REQUEST['admin_id'])) {
                $aid = intval($_REQUEST['admin_id']);
                $where[] = "admin_id = {$aid}";
            }
            if (isset($_REQUEST['status']) && $_REQUEST['status'] !== '') {
                $st = intval($_REQUEST['status']);
                $where[] = "status = {$st}";
            }
            if (!empty($_REQUEST['date_from'])) {
                $df = SecGuard::esc($_REQUEST['date_from']);
                $where[] = "create_time >= '{$df} 00:00:00'";
            }
            if (!empty($_REQUEST['date_to'])) {
                $dt = SecGuard::esc($_REQUEST['date_to']);
                $where[] = "create_time <= '{$dt} 23:59:59'";
            }
            if (!empty($_REQUEST['keyword'])) {
                $kw = SecGuard::esc($_REQUEST['keyword']);
                $where[] = "(action LIKE '%{$kw}%' OR remark LIKE '%{$kw}%')";
            }
            $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

            $totalRes = mysqli_query($conn, "SELECT COUNT(*) as c FROM sec_access_log {$whereSql}");
            $total = 0;
            if ($totalRes && $row = mysqli_fetch_assoc($totalRes)) $total = intval($row['c']);

            $list = [];
            $sql = "SELECT * FROM sec_access_log {$whereSql} ORDER BY id DESC LIMIT {$offset}, {$page_size}";
            $res = mysqli_query($conn, $sql);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) $list[] = $row;
            }
            returnJson(200, '获取成功', [
                'list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $page_size
            ]);
            break;

        case 'get_blacklist':
            $page = max(1, intval($_REQUEST['page'] ?? 1));
            $page_size = max(1, min(100, intval($_REQUEST['page_size'] ?? 20)));
            $offset = ($page - 1) * $page_size;
            $search = $_REQUEST['search'] ?? '';

            $whereSql = '';
            if (!empty($search)) {
                $s = SecGuard::esc($search);
                $whereSql = "WHERE ip LIKE '%{$s}%' OR reason LIKE '%{$s}%'";
            }

            $totalRes = mysqli_query($conn, "SELECT COUNT(*) as c FROM sec_ip_blacklist {$whereSql}");
            $total = 0;
            if ($totalRes && $row = mysqli_fetch_assoc($totalRes)) $total = intval($row['c']);

            $list = [];
            $sql = "SELECT * FROM sec_ip_blacklist {$whereSql} ORDER BY id DESC LIMIT {$offset}, {$page_size}";
            $res = mysqli_query($conn, $sql);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) $list[] = $row;
            }
            returnJson(200, '获取成功', [
                'list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $page_size
            ]);
            break;

        case 'del_blacklist':
            $id = intval($_REQUEST['id'] ?? 0);
            if ($id <= 0) {
                returnJson(400, '无效的ID');
            }
            if (mysqli_query($conn, "DELETE FROM sec_ip_blacklist WHERE id = {$id}")) {
                SecGuard::writeLog(1, '删除黑名单', "ID:{$id}");
                returnJson(200, '删除成功');
            }
            returnJson(500, '删除失败: ' . mysqli_error($conn));
            break;

        case 'add_blacklist':
            $ip = trim($_REQUEST['ip'] ?? '');
            $reason = SecGuard::esc($_REQUEST['reason'] ?? '');
            $admin_name = SecGuard::esc($admin['username']);
            $ip_from = 0;
            $ip_to = 0;
            $ip_main = SecGuard::esc($ip);

            if ($ip === '') returnJson(400, 'IP不能为空');

            if (strpos($ip, '/') !== false) {
                $range = cidrToRange($ip);
                if (!$range) returnJson(400, 'CIDR格式错误');
                $ip_from = $range['from'];
                $ip_to = $range['to'];
                $parts = explode('/', $ip);
                $ip_main = SecGuard::esc($parts[0]);
            } else {
                $long = ip2long($ip);
                if ($long === false) returnJson(400, 'IP格式错误');
                $ip_from = $long;
                $ip_to = $long;
            }

            $expire_time = 'NULL';
            if (!empty($_REQUEST['expire_date'])) {
                $ed = SecGuard::esc($_REQUEST['expire_date']);
                $expire_time = "'{$ed}'";
            } else {
                $days = intval($_REQUEST['expire_days'] ?? 0);
                if ($days > 0) {
                    $expire_time = "'" . date('Y-m-d H:i:s', time() + $days * 86400) . "'";
                }
            }

            $sql = "INSERT INTO sec_ip_blacklist (ip, ip_from, ip_to, reason, expire_time, create_admin, create_time)
                    VALUES ('{$ip_main}', {$ip_from}, {$ip_to}, '{$reason}', {$expire_time}, '{$admin_name}', NOW())";
            if (mysqli_query($conn, $sql)) {
                SecGuard::writeLog(1, '新增黑名单', "IP:{$ip}, 原因:{$reason}");
                returnJson(200, '添加成功');
            }
            returnJson(500, '添加失败: ' . mysqli_error($conn));
            break;

        case 'get_failed_logins':
            $page = max(1, intval($_REQUEST['page'] ?? 1));
            $page_size = max(1, min(100, intval($_REQUEST['page_size'] ?? 20)));
            $offset = ($page - 1) * $page_size;

            $where = [];
            if (!empty($_REQUEST['ip'])) {
                $ip = SecGuard::esc($_REQUEST['ip']);
                $where[] = "ip LIKE '%{$ip}%'";
            }
            if (!empty($_REQUEST['username'])) {
                $u = SecGuard::esc($_REQUEST['username']);
                $where[] = "username LIKE '%{$u}%'";
            }
            $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

            $totalRes = mysqli_query($conn, "SELECT COUNT(*) as c FROM sec_login_failed {$whereSql}");
            $total = 0;
            if ($totalRes && $row = mysqli_fetch_assoc($totalRes)) $total = intval($row['c']);

            $list = [];
            $sql = "SELECT * FROM sec_login_failed {$whereSql} ORDER BY id DESC LIMIT {$offset}, {$page_size}";
            $res = mysqli_query($conn, $sql);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) $list[] = $row;
            }
            returnJson(200, '获取成功', [
                'list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $page_size
            ]);
            break;

        case 'save_config':
            $keys = ['login_max_5min', 'login_max_hour', 'login_lock_min', 'rate_limit_page'];
            foreach ($keys as $k) {
                if (isset($_REQUEST[$k])) {
                    $v = intval($_REQUEST[$k]);
                    SecGuard::setConfig($k, $v);
                }
            }
            SecGuard::writeLog(1, '修改安全配置', json_encode($_REQUEST, JSON_UNESCAPED_UNICODE));
            returnJson(200, '配置已保存');
            break;

        case 'clear_old_logs':
            $days = max(1, intval($_REQUEST['days'] ?? 30));
            $time = date('Y-m-d H:i:s', time() - $days * 86400);
            $ok1 = mysqli_query($conn, "DELETE FROM sec_access_log WHERE create_time < '{$time}'");
            $ok2 = mysqli_query($conn, "DELETE FROM sec_login_failed WHERE create_time < '{$time}'");
            $cnt1 = $ok1 ? mysqli_affected_rows($conn) : 0;
            $cnt2 = $ok2 ? mysqli_affected_rows($conn) : 0;
            SecGuard::writeLog(1, '清理日志', "{$days}天前,访问日志+{$cnt1},失败日志+{$cnt2}");
            returnJson(200, "清理完成，共删除访问日志{$cnt1}条，失败日志{$cnt2}条", ['access' => $cnt1, 'failed' => $cnt2]);
            break;

        default:
            returnJson(400, '未知的操作类型');
    }
    exit;
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理系统 - 安全中心</title>
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

        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0;
            width: var(--sidebar-width);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            display: flex; flex-direction: column;
        }
        .sidebar-header { height: var(--header-height); display: flex; align-items: center; padding: 0 24px; border-bottom: 1px solid var(--border-color); }
        .sidebar-logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-logo .logo-icon { width: 36px; height: 36px; border-radius: 14px; display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: 0 4px 12px rgba(201,169,212,.3); border: 2px solid rgba(201,169,212,.3); }
        .sidebar-logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; }
        .sidebar-logo .logo-text { font-size: 18px; font-weight: 600; color: var(--text-primary); }
        .sidebar-menu { flex: 1; padding: 16px 0; overflow-y: auto; }
        .menu-item a { display: flex; align-items: center; gap: 12px; padding: 12px 24px; color: var(--text-secondary); text-decoration: none; font-size: 14px; transition: all .3s; border-left: 3px solid transparent; border-radius: 0 12px 12px 0; }
        .menu-item a:hover { background: rgba(201,169,212,.1); color: var(--text-primary); border-left-color: rgba(201,169,212,.5); }
        .menu-item a.active { background: rgba(201,169,212,.15); color: var(--primary-dark); border-left-color: var(--primary); }
        .menu-item .icon { font-size: 17px; width: 20px; text-align: center; }

        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }
        .header {
            height: var(--header-height);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 24px; position: sticky; top: 0; z-index: 100;
        }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-title { font-size: 18px; font-weight: 600; }
        .header-breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
        .header-breadcrumb a { color: var(--text-secondary); text-decoration: none; transition: color .2s; }
        .header-breadcrumb a:hover { color: var(--primary-dark); }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .header-action { width: 36px; height: 36px; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 15px; color: var(--text-secondary); cursor: pointer; transition: all .3s; }
        .header-action:hover { background: rgba(201,169,212,.15); border-color: var(--primary); color: var(--primary-dark); transform: translateY(-1px); }

        .page-content { padding: 24px; }

        /* Tab */
        .tabs-nav { display: flex; gap: 4px; margin-bottom: 20px; background: var(--bg-card); padding: 6px; border-radius: 14px; border: 1px solid var(--border-color); box-shadow: var(--shadow-light); flex-wrap: wrap; }
        .tab-btn { padding: 10px 18px; border: none; background: transparent; border-radius: 10px; font-size: 14px; cursor: pointer; color: var(--text-secondary); transition: all .3s; display: inline-flex; align-items: center; gap: 8px; font-family: inherit; }
        .tab-btn:hover { background: rgba(201,169,212,.1); color: var(--text-primary); }
        .tab-btn.active { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; box-shadow: 0 4px 12px rgba(201,169,212,.3); }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; animation: fadeIn .3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(6px);} to { opacity: 1; transform: none;} }

        /* 统计卡片 */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
        .stat-card {
            background: var(--bg-card); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color); border-radius: 16px; padding: 24px;
            transition: all .3s; box-shadow: var(--shadow-light);
        }
        .stat-card:hover { border-color: rgba(201,169,212,.5); transform: translateY(-4px); box-shadow: var(--shadow-hover); }
        .stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .stat-icon.blue { background: rgba(201,169,212,.2); color: var(--primary-dark); }
        .stat-icon.green { background: rgba(168,213,186,.2); color: var(--success); }
        .stat-icon.orange { background: rgba(232,212,168,.2); color: var(--warning); }
        .stat-icon.red { background: rgba(232,176,176,.2); color: var(--danger); }
        .stat-value { font-size: 32px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
        .stat-label { font-size: 14px; color: var(--text-secondary); }

        /* 搜索栏 */
        .search-bar { display: flex; flex-wrap: wrap; gap: 12px; padding: 16px 20px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; margin-bottom: 16px; align-items: flex-end; }
        .search-field { display: flex; flex-direction: column; gap: 6px; }
        .search-field label { font-size: 12px; color: var(--text-secondary); font-weight: 500; }
        .form-input, .form-select {
            padding: 10px 14px; background: var(--bg-input); border: 1px solid var(--border-color);
            border-radius: 10px; font-size: 13px; color: var(--text-primary); outline: none;
            transition: all .3s; font-family: inherit; box-sizing: border-box; min-width: 140px;
        }
        .form-input:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(201,169,212,.1); }

        .btn {
            padding: 10px 20px; border: none; border-radius: 10px; font-size: 13px; cursor: pointer;
            transition: all .3s; display: inline-flex; align-items: center; gap: 6px;
            font-family: inherit; font-weight: 500;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; box-shadow: 0 4px 12px rgba(201,169,212,.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(201,169,212,.4); }
        .btn-secondary { background: var(--bg-input); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: rgba(201,169,212,.1); border-color: var(--primary); color: var(--text-primary); }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #d49a9a); color: #fff; box-shadow: 0 4px 12px rgba(232,176,176,.3); }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(232,176,176,.4); }
        .btn-success { background: linear-gradient(135deg, var(--success), #8fc8a3); color: #fff; box-shadow: 0 4px 12px rgba(168,213,186,.3); }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(168,213,186,.4); }
        .btn:disabled { opacity: .6; cursor: not-allowed; transform: none; box-shadow: none; }

        /* 数据表格 */
        .data-card {
            background: var(--bg-card); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; box-shadow: var(--shadow-light);
        }
        .card-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; border-bottom: 1px solid var(--border-color); }
        .card-title { font-size: 15px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }

        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: rgba(201,169,212,.08); padding: 14px 18px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-secondary); border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        .data-table td { padding: 14px 18px; font-size: 13px; color: var(--text-primary); border-bottom: 1px solid rgba(201,169,212,.15); vertical-align: middle; }
        .data-table tr:hover { background: rgba(201,169,212,.06); }
        .data-table tr:last-child td { border-bottom: none; }

        .status-tag { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .status-tag.ok { background: rgba(168,213,186,.2); color: #6fa981; }
        .status-tag.fail { background: rgba(232,176,176,.25); color: #c47979; }
        .status-tag.warn { background: rgba(232,212,168,.25); color: #b89b66; }
        .status-tag.info { background: rgba(201,169,212,.2); color: var(--primary-dark); }

        .method-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; }
        .method-GET { background: rgba(168,213,186,.2); color: #6fa981; }
        .method-POST { background: rgba(201,169,212,.2); color: var(--primary-dark); }
        .method-other { background: rgba(232,212,168,.2); color: #b89b66; }

        .action-buttons { display: flex; align-items: center; gap: 6px; }
        .action-btn { padding: 6px 10px; border: none; border-radius: 8px; font-size: 12px; cursor: pointer; transition: all .3s; display: inline-flex; align-items: center; gap: 4px; }
        .action-btn.del { background: rgba(232,176,176,.15); color: var(--danger); }
        .action-btn.del:hover { background: rgba(232,176,176,.28); transform: translateY(-1px); }

        /* 分页 */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; padding: 16px; flex-wrap: wrap; }
        .pagination a, .pagination span {
            padding: 8px 12px; border-radius: 8px; font-size: 13px; cursor: pointer;
            border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-secondary);
            min-width: 34px; text-align: center; transition: all .2s; text-decoration: none;
        }
        .pagination a:hover { border-color: var(--primary); color: var(--primary-dark); background: rgba(201,169,212,.08); }
        .pagination .current { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; border-color: transparent; box-shadow: 0 4px 12px rgba(201,169,212,.3); }
        .pagination .disabled { opacity: .5; cursor: not-allowed; }

        .empty { padding: 50px 24px; text-align: center; color: var(--text-muted); }
        .empty i { font-size: 44px; margin-bottom: 12px; opacity: .5; }

        /* 配置卡片 */
        .config-card {
            background: var(--bg-card); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color); border-radius: 16px; padding: 24px;
            margin-bottom: 24px; box-shadow: var(--shadow-light);
        }
        .config-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; margin-bottom: 18px; }
        .form-group { margin-bottom: 0; }
        .form-label { display: block; font-size: 13px; font-weight: 500; color: var(--text-primary); margin-bottom: 8px; }
        .form-hint { font-size: 12px; color: var(--text-muted); margin-top: 6px; }

        /* 弹窗 */
        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,.3);
            backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
            z-index: 2000; align-items: center; justify-content: center;
            padding: 20px; overflow-y: auto;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--bg-card); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color); border-radius: 16px; width: 100%; max-width: 500px;
            padding: 24px; box-shadow: var(--shadow-hover); animation: modalIn .3s ease;
        }
        @keyframes modalIn { from { opacity: 0; transform: translateY(-20px) scale(.96);} to { opacity: 1; transform: none;} }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-title { font-size: 17px; font-weight: 600; color: var(--text-primary); }
        .modal-close { width: 32px; height: 32px; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; color: var(--text-secondary); cursor: pointer; transition: all .3s; }
        .modal-close:hover { background: rgba(232,176,176,.2); border-color: var(--danger); color: var(--danger); }
        .modal-body { margin-bottom: 20px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; }
        .modal-btn { padding: 10px 20px; border: none; border-radius: 10px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all .3s; display: inline-flex; align-items: center; gap: 6px; }
        .modal-btn.cancel { background: var(--bg-input); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .modal-btn.cancel:hover { background: rgba(201,169,212,.1); border-color: var(--primary); color: var(--text-primary); }
        .modal-btn.confirm { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; box-shadow: 0 4px 12px rgba(201,169,212,.3); }
        .modal-btn.confirm:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(201,169,212,.4); }

        .clean-section { display: flex; flex-direction: column; gap: 14px; max-width: 420px; }

        /* Toast */
        .toast-container { position: fixed; top: 80px; right: 24px; z-index: 3000; display: flex; flex-direction: column; gap: 10px; }
        .toast {
            padding: 12px 20px; border-radius: 12px; font-size: 13px; color: #fff;
            box-shadow: var(--shadow-hover); display: flex; align-items: center; gap: 8px;
            animation: toastIn .3s ease; max-width: 360px;
        }
        .toast.success { background: linear-gradient(135deg, var(--success), #8fc8a3); }
        .toast.error { background: linear-gradient(135deg, var(--danger), #d49a9a); }
        .toast.info { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        @keyframes toastIn { from { opacity: 0; transform: translateX(40px);} to { opacity: 1; transform: none;} }

        .loading-spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .text-truncate { max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; }

        .footer { text-align: center; padding: 24px; color: var(--text-muted); font-size: 12px; }

        .flex-between { display: flex; justify-content: space-between; align-items: center; }
        .mb-16 { margin-bottom: 16px; }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .config-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: 260px; transform: translateX(-100%); transition: transform .3s ease-out; z-index: 1600; background: rgba(255,255,255,.95); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); box-shadow: 4px 0 20px rgba(201,169,212,.2); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100%; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .header-breadcrumb { display: none; }
            .tabs-nav { padding: 4px; }
            .tab-btn { padding: 8px 12px; font-size: 12px; }
            .data-card { overflow: visible; }
            .data-table thead { display: none; }
            .data-table tbody { display: flex; flex-direction: column; gap: 10px; padding: 10px; }
            .data-table tr { display: flex; flex-direction: column; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 10px; box-shadow: var(--shadow-light); }
            .data-table td { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid rgba(201,169,212,.1); font-size: 12px; }
            .data-table td:last-child { border-bottom: none; }
            .data-table td::before { content: attr(data-label); font-weight: 600; color: var(--text-secondary); font-size: 11px; flex-shrink: 0; margin-right: 10px; }
            .toast-container { right: 12px; left: 12px; }
            .toast { max-width: none; }
            .config-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="mobile-menu-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></div>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="logo-icon">
                    <img src="./logo.png" alt="Logo" onerror="this.style.display='none';this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:16px;color:#c9a9d4;background:linear-gradient(135deg,#f3e8f7,#f9f0f3);\'><i class=\'fas fa-crown\'></i></div>';">
                </div>
                <span class="logo-text">真昼小破站</span>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item"><a href="index.php"><i class="fas fa-chart-pie icon"></i><span>仪表盘</span></a></div>
            <div class="menu-item"><a href="users.php"><i class="fas fa-users icon"></i><span>用户管理</span></a></div>
            <div class="menu-item"><a href="community.php"><i class="fas fa-comments icon"></i><span>社区管理</span></a></div>
            <div class="menu-item"><a href="comments.php"><i class="fas fa-comment-dots icon"></i><span>留言管理</span></a></div>
            <div class="menu-item"><a href="bangumi.php"><i class="fas fa-tv icon"></i><span>追番管理</span></a></div>
            <div class="menu-item"><a href="reports.php"><i class="fas fa-flag icon"></i><span>举报处理</span></a></div>
            <?php if (isSuperAdmin()): ?>
            <div class="menu-item"><a href="admins.php"><i class="fas fa-shield-alt icon"></i><span>管理员管理</span></a></div>
            <?php endif; ?>
            <div class="menu-item"><a href="security.php" class="active"><i class="fas fa-user-lock icon"></i><span>安全中心</span></a></div>
            <div class="menu-item"><a href="qiniu.php"><i class="fas fa-cloud icon"></i><span>七牛存储</span></a></div>
            <div class="menu-item"><a href="logout.php"><i class="fas fa-sign-out-alt icon"></i><span>退出登录</span></a></div>
        </div>
    </div>

    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1 class="header-title">安全中心</h1>
                <div class="header-breadcrumb">
                    <a href="index.php">首页</a><span>/</span><span>安全中心</span>
                </div>
            </div>
            <div class="header-right">
                <div class="header-action" title="刷新" onclick="refreshCurrentTab()"><i class="fas fa-sync-alt"></i></div>
                <div class="header-action" title="设置"><i class="fas fa-cog"></i></div>
            </div>
        </div>

        <div class="page-content">
            <div class="tabs-nav">
                <button class="tab-btn active" data-tab="dashboard"><i class="fas fa-chart-line"></i> 仪表盘</button>
                <button class="tab-btn" data-tab="logs"><i class="fas fa-clipboard-list"></i> 访问日志</button>
                <button class="tab-btn" data-tab="blacklist"><i class="fas fa-ban"></i> IP黑名单</button>
                <button class="tab-btn" data-tab="ratelimit"><i class="fas fa-lock"></i> 登录限流</button>
                <button class="tab-btn" data-tab="clean"><i class="fas fa-broom"></i> 日志清理</button>
            </div>

            <!-- Tab1 仪表盘 -->
            <div class="tab-pane active" id="tab-dashboard">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon blue"><i class="fas fa-eye"></i></div>
                        </div>
                        <div class="stat-value" id="stat24h">--</div>
                        <div class="stat-label">24小时访问数</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon red"><i class="fas fa-shield-virus"></i></div>
                        </div>
                        <div class="stat-value" id="statBlocked">--</div>
                        <div class="stat-label">被拦截请求</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                        </div>
                        <div class="stat-value" id="statFailed">--</div>
                        <div class="stat-label">近1小时登录失败</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon green"><i class="fas fa-ban"></i></div>
                        </div>
                        <div class="stat-value" id="statBlack">--</div>
                        <div class="stat-label">黑名单总数</div>
                    </div>
                </div>
                <div class="data-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history"></i> 最近访问日志（前10条）</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>时间</th><th>IP</th><th>管理员</th><th>方法</th><th>操作</th><th>状态</th><th>备注</th>
                            </tr>
                        </thead>
                        <tbody id="recentLogsBody">
                            <tr><td colspan="8" class="empty"><i class="fas fa-spinner fa-spin"></i><p>加载中...</p></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab2 访问日志 -->
            <div class="tab-pane" id="tab-logs">
                <div class="search-bar">
                    <div class="search-field">
                        <label>IP地址</label>
                        <input type="text" id="logIp" class="form-input" placeholder="如 192.168.1.1">
                    </div>
                    <div class="search-field">
                        <label>管理员ID</label>
                        <input type="number" id="logAdminId" class="form-input" placeholder="留空不限">
                    </div>
                    <div class="search-field">
                        <label>状态</label>
                        <select id="logStatus" class="form-select">
                            <option value="">全部</option>
                            <option value="1">成功</option>
                            <option value="0">失败/拦截</option>
                        </select>
                    </div>
                    <div class="search-field">
                        <label>日期从</label>
                        <input type="date" id="logDateFrom" class="form-input">
                    </div>
                    <div class="search-field">
                        <label>日期到</label>
                        <input type="date" id="logDateTo" class="form-input">
                    </div>
                    <div class="search-field" style="flex:1;min-width:200px;">
                        <label>关键词（操作/备注）</label>
                        <input type="text" id="logKeyword" class="form-input" placeholder="模糊搜索">
                    </div>
                    <button class="btn btn-primary" onclick="searchLogs()"><i class="fas fa-search"></i> 搜索</button>
                    <button class="btn btn-secondary" onclick="resetLogsSearch()"><i class="fas fa-redo"></i> 重置</button>
                </div>
                <div class="data-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-clipboard-list"></i> 访问日志列表</h3>
                        <div style="font-size:12px;color:var(--text-muted);" id="logsInfo">共 0 条</div>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>时间</th><th>IP</th><th>管理员</th><th>方法</th><th>URI</th><th>操作</th><th>状态</th><th>备注</th>
                            </tr>
                        </thead>
                        <tbody id="logsBody">
                            <tr><td colspan="9" class="empty"><i class="fas fa-spinner fa-spin"></i><p>加载中...</p></td></tr>
                        </tbody>
                    </table>
                    <div class="pagination" id="logsPager"></div>
                </div>
            </div>

            <!-- Tab3 IP黑名单 -->
            <div class="tab-pane" id="tab-blacklist">
                <div class="search-bar">
                    <div class="search-field" style="flex:1;min-width:220px;">
                        <label>搜索IP或原因</label>
                        <input type="text" id="blSearch" class="form-input" placeholder="模糊搜索">
                    </div>
                    <button class="btn btn-primary" onclick="searchBlacklist()"><i class="fas fa-search"></i> 搜索</button>
                    <button class="btn btn-success" onclick="openAddBlacklist()"><i class="fas fa-plus"></i> 新增黑名单</button>
                </div>
                <div class="data-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-ban"></i> IP黑名单列表</h3>
                        <div style="font-size:12px;color:var(--text-muted);" id="blInfo">共 0 条</div>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>IP / 范围</th><th>原因</th><th>到期时间</th><th>创建者</th><th>创建时间</th><th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="blBody">
                            <tr><td colspan="7" class="empty"><i class="fas fa-spinner fa-spin"></i><p>加载中...</p></td></tr>
                        </tbody>
                    </table>
                    <div class="pagination" id="blPager"></div>
                </div>
            </div>

            <!-- Tab4 登录限流 -->
            <div class="tab-pane" id="tab-ratelimit">
                <div class="config-card">
                    <h3 class="card-title" style="margin-bottom:18px;"><i class="fas fa-sliders-h"></i> 限流参数配置</h3>
                    <div class="config-grid">
                        <div class="form-group">
                            <label class="form-label">5分钟内最大登录尝试次数</label>
                            <input type="number" id="cfg5min" class="form-input" min="1" value="5">
                            <div class="form-hint">超过此次数将触发锁定（默认5）</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">1小时内最大登录尝试次数</label>
                            <input type="number" id="cfgHour" class="form-input" min="1" value="20">
                            <div class="form-hint">超过此次数将触发锁定（默认20）</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">登录锁定时长（分钟）</label>
                            <input type="number" id="cfgLock" class="form-input" min="1" value="15">
                            <div class="form-hint">触发后锁定的分钟数（默认15）</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">页面访问速率限制（次/分钟）</label>
                            <input type="number" id="cfgRate" class="form-input" min="10" value="120">
                            <div class="form-hint">单IP每分钟最大页面请求数（默认120）</div>
                        </div>
                    </div>
                    <button class="btn btn-primary" onclick="saveConfig()"><i class="fas fa-save"></i> 保存配置</button>
                </div>

                <div class="search-bar">
                    <div class="search-field">
                        <label>IP地址</label>
                        <input type="text" id="failIp" class="form-input" placeholder="搜索IP">
                    </div>
                    <div class="search-field">
                        <label>用户名</label>
                        <input type="text" id="failUser" class="form-input" placeholder="搜索用户名">
                    </div>
                    <button class="btn btn-primary" onclick="searchFailed()"><i class="fas fa-search"></i> 搜索</button>
                    <button class="btn btn-secondary" onclick="resetFailedSearch()"><i class="fas fa-redo"></i> 重置</button>
                </div>
                <div class="data-card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-times-circle"></i> 登录失败日志</h3>
                        <div style="font-size:12px;color:var(--text-muted);" id="failInfo">共 0 条</div>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>时间</th><th>IP</th><th>尝试用户名</th><th>UA信息</th>
                            </tr>
                        </thead>
                        <tbody id="failBody">
                            <tr><td colspan="5" class="empty"><i class="fas fa-spinner fa-spin"></i><p>加载中...</p></td></tr>
                        </tbody>
                    </table>
                    <div class="pagination" id="failPager"></div>
                </div>
            </div>

            <!-- Tab5 日志清理 -->
            <div class="tab-pane" id="tab-clean">
                <div class="config-card">
                    <h3 class="card-title" style="margin-bottom:18px;"><i class="fas fa-database"></i> 日志清理</h3>
                    <div class="clean-section">
                        <div class="form-group">
                            <label class="form-label">保留最近多少天的日志</label>
                            <input type="number" id="cleanDays" class="form-input" min="1" value="30" style="max-width:240px;">
                            <div class="form-hint">将删除早于此天数的访问日志和登录失败日志，操作不可恢复</div>
                        </div>
                        <div>
                            <button class="btn btn-danger" onclick="cleanLogs()"><i class="fas fa-broom"></i> 执行清理</button>
                        </div>
                        <div style="padding:14px;background:rgba(232,176,176,.1);border:1px dashed var(--danger);border-radius:10px;font-size:13px;color:var(--danger);">
                            <i class="fas fa-exclamation-circle"></i> 警告：清理操作不可撤销，请确认后再执行。建议先导出备份。
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer"><p>&copy; 2026 建湖梦玖信息技术工作室（个体工商户） · 管理后台系统</p></div>
    </div>

    <!-- 新增黑名单弹窗 -->
    <div class="modal-overlay" id="blModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-plus-circle"></i> 新增IP黑名单</h3>
                <button class="modal-close" onclick="closeBlModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label">IP / CIDR 范围 <span style="color:var(--danger);">*</span></label>
                    <input type="text" id="blIp" class="form-input" placeholder="如 192.168.1.1 或 192.168.1.0/24">
                    <div class="form-hint">支持单IP或IPv4 CIDR（如 10.0.0.0/8）</div>
                </div>
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label">到期方式</label>
                    <select id="blExpireType" class="form-select" onchange="toggleExpireInput()">
                        <option value="forever">永久封禁</option>
                        <option value="days">指定天数</option>
                        <option value="date">指定日期</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:16px;display:none;" id="blDaysBox">
                    <label class="form-label">封禁天数</label>
                    <input type="number" id="blDays" class="form-input" min="1" value="7">
                </div>
                <div class="form-group" style="margin-bottom:16px;display:none;" id="blDateBox">
                    <label class="form-label">到期时间</label>
                    <input type="datetime-local" id="blDate" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">封禁原因</label>
                    <textarea id="blReason" class="form-input" rows="3" placeholder="如：恶意扫描、暴力破解登录" style="resize:vertical;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" onclick="closeBlModal()">取消</button>
                <button class="modal-btn confirm" onclick="submitBlacklist()"><i class="fas fa-check"></i> 确认添加</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        var currentTab = 'dashboard';
        var pages = { logs: 1, blacklist: 1, failed: 1 };
        var pageSize = 20;

        function toast(msg, type) {
            type = type || 'info';
            var c = document.getElementById('toastContainer');
            var el = document.createElement('div');
            el.className = 'toast ' + type;
            var icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
            el.innerHTML = '<i class="fas ' + icon + '"></i><span>' + escapeHtml(msg) + '</span>';
            c.appendChild(el);
            setTimeout(function() {
                el.style.opacity = '0';
                el.style.transform = 'translateX(40px)';
                el.style.transition = 'all .3s ease';
                setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
            }, 3000);
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function toggleSidebar() {
            var s = document.getElementById('sidebar');
            var o = document.getElementById('sidebarOverlay');
            s.classList.toggle('open');
            o.classList.toggle('active');
        }
        document.querySelectorAll('.sidebar-menu a').forEach(function(l) {
            l.addEventListener('click', function() {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebarOverlay').classList.remove('active');
            });
        });

        // Tab 切换
        document.querySelectorAll('.tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var tab = btn.dataset.tab;
                document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
                document.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
                btn.classList.add('active');
                document.getElementById('tab-' + tab).classList.add('active');
                currentTab = tab;
                loadTabData(tab);
            });
        });

        function refreshCurrentTab() { loadTabData(currentTab); }

        function loadTabData(tab) {
            if (tab === 'dashboard') loadDashboard();
            else if (tab === 'logs') loadLogs();
            else if (tab === 'blacklist') loadBlacklist();
            else if (tab === 'ratelimit') { loadRateConfig(); loadFailed(); }
        }

        function apiPost(action, data, cb) {
            var fd = new FormData();
            fd.append('action', action);
            if (data) for (var k in data) fd.append(k, data[k]);
            fetch('security.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) { cb && cb(res); })
                .catch(function(err) { toast('网络错误: ' + err.message, 'error'); });
        }

        function renderPager(containerId, total, page, pageSize, onGo) {
            var el = document.getElementById(containerId);
            var totalPages = Math.max(1, Math.ceil(total / pageSize));
            if (total === 0) { el.innerHTML = ''; return; }
            var html = '';
            html += '<span' + (page <= 1 ? ' class="disabled"' : '') + ' onclick="' + (page > 1 ? onGo + '(' + (page - 1) + ')' : '') + '"><i class="fas fa-chevron-left"></i></span>';
            var s = Math.max(1, page - 2), e = Math.min(totalPages, page + 2);
            if (s > 1) { html += '<a onclick="' + onGo + '(1)">1</a>'; if (s > 2) html += '<span class="disabled">...</span>'; }
            for (var i = s; i <= e; i++) {
                html += (i === page) ? '<span class="current">' + i + '</span>' : '<a onclick="' + onGo + '(' + i + ')">' + i + '</a>';
            }
            if (e < totalPages) { if (e < totalPages - 1) html += '<span class="disabled">...</span>'; html += '<a onclick="' + onGo + '(' + totalPages + ')">' + totalPages + '</a>'; }
            html += '<span' + (page >= totalPages ? ' class="disabled"' : '') + ' onclick="' + (page < totalPages ? onGo + '(' + (page + 1) + ')' : '') + '"><i class="fas fa-chevron-right"></i></span>';
            el.innerHTML = html;
        }

        // 仪表盘
        function loadDashboard() {
            apiPost('get_logs', { page: 1, page_size: 10 }, function(res) {
                if (res.code === 200) renderRecentLogs(res.data.list);
            });
            // 统计数据（通过多次查询模拟）
            var t = Math.floor(Date.now() / 1000);
            apiPost('get_logs', { page: 1, page_size: 1, date_from: fmtDate(t - 86400), date_to: fmtDate(t) }, function(r) {
                if (r.code === 200) document.getElementById('stat24h').textContent = r.data.total;
            });
            apiPost('get_logs', { page: 1, page_size: 1, status: 0, date_from: fmtDate(t - 86400), date_to: fmtDate(t) }, function(r) {
                if (r.code === 200) document.getElementById('statBlocked').textContent = r.data.total;
            });
            apiPost('get_failed_logins', { page: 1, page_size: 1 }, function(r) {
                if (r.code === 200) {
                    // 简单用总数代替，实际可过滤1小时
                    document.getElementById('statFailed').textContent = Math.min(r.data.total, 999);
                }
            });
            apiPost('get_blacklist', { page: 1, page_size: 1 }, function(r) {
                if (r.code === 200) document.getElementById('statBlack').textContent = r.data.total;
            });
        }
        function fmtDate(ts) { var d = new Date(ts * 1000); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
        function pad(n) { return n < 10 ? '0' + n : '' + n; }

        function renderRecentLogs(list) {
            var tb = document.getElementById('recentLogsBody');
            if (!list || list.length === 0) {
                tb.innerHTML = '<tr><td colspan="8" class="empty"><i class="fas fa-inbox"></i><p>暂无日志</p></td></tr>';
                return;
            }
            var h = '';
            list.forEach(function(r) {
                var m = (r.method || 'GET').toUpperCase();
                var mc = m === 'GET' ? 'method-GET' : (m === 'POST' ? 'method-POST' : 'method-other');
                var sc = r.status == 1 ? 'ok' : 'fail';
                var st = r.status == 1 ? '成功' : '失败';
                h += '<tr>' +
                    '<td data-label="ID">' + r.id + '</td>' +
                    '<td data-label="时间">' + escapeHtml(r.create_time || '') + '</td>' +
                    '<td data-label="IP">' + escapeHtml(r.ip || '') + '</td>' +
                    '<td data-label="管理员">' + escapeHtml(r.admin_name || '-') + '</td>' +
                    '<td data-label="方法"><span class="method-tag ' + mc + '">' + m + '</span></td>' +
                    '<td data-label="操作">' + escapeHtml(r.action || '') + '</td>' +
                    '<td data-label="状态"><span class="status-tag ' + sc + '">' + st + '</span></td>' +
                    '<td data-label="备注"><span class="text-truncate" title="' + escapeHtml(r.remark || '') + '">' + escapeHtml(r.remark || '-') + '</span></td>' +
                '</tr>';
            });
            tb.innerHTML = h;
        }

        // 访问日志
        function searchLogs() { pages.logs = 1; loadLogs(); }
        function resetLogsSearch() {
            document.getElementById('logIp').value = '';
            document.getElementById('logAdminId').value = '';
            document.getElementById('logStatus').value = '';
            document.getElementById('logDateFrom').value = '';
            document.getElementById('logDateTo').value = '';
            document.getElementById('logKeyword').value = '';
            pages.logs = 1; loadLogs();
        }
        function goLogsPage(p) { pages.logs = p; loadLogs(); }
        function loadLogs() {
            var d = {
                page: pages.logs, page_size: pageSize,
                ip: document.getElementById('logIp').value.trim(),
                admin_id: document.getElementById('logAdminId').value.trim(),
                status: document.getElementById('logStatus').value,
                date_from: document.getElementById('logDateFrom').value,
                date_to: document.getElementById('logDateTo').value,
                keyword: document.getElementById('logKeyword').value.trim()
            };
            apiPost('get_logs', d, function(res) {
                if (res.code !== 200) { toast(res.msg || '加载失败', 'error'); return; }
                var data = res.data;
                document.getElementById('logsInfo').textContent = '共 ' + data.total + ' 条';
                renderLogsTable(data.list);
                renderPager('logsPager', data.total, data.page, data.page_size, 'goLogsPage');
            });
        }
        function renderLogsTable(list) {
            var tb = document.getElementById('logsBody');
            if (!list || list.length === 0) {
                tb.innerHTML = '<tr><td colspan="9" class="empty"><i class="fas fa-inbox"></i><p>暂无日志</p></td></tr>';
                return;
            }
            var h = '';
            list.forEach(function(r) {
                var m = (r.method || 'GET').toUpperCase();
                var mc = m === 'GET' ? 'method-GET' : (m === 'POST' ? 'method-POST' : 'method-other');
                var sc = r.status == 1 ? 'ok' : 'fail';
                var st = r.status == 1 ? '成功' : '失败';
                h += '<tr>' +
                    '<td data-label="ID">' + r.id + '</td>' +
                    '<td data-label="时间">' + escapeHtml(r.create_time || '') + '</td>' +
                    '<td data-label="IP">' + escapeHtml(r.ip || '') + '</td>' +
                    '<td data-label="管理员">' + escapeHtml(r.admin_name || '-') + '</td>' +
                    '<td data-label="方法"><span class="method-tag ' + mc + '">' + m + '</span></td>' +
                    '<td data-label="URI"><span class="text-truncate" title="' + escapeHtml(r.uri || '') + '">' + escapeHtml(r.uri || '') + '</span></td>' +
                    '<td data-label="操作">' + escapeHtml(r.action || '') + '</td>' +
                    '<td data-label="状态"><span class="status-tag ' + sc + '">' + st + '</span></td>' +
                    '<td data-label="备注"><span class="text-truncate" title="' + escapeHtml(r.remark || '') + '">' + escapeHtml(r.remark || '-') + '</span></td>' +
                '</tr>';
            });
            tb.innerHTML = h;
        }

        // 黑名单
        function searchBlacklist() { pages.blacklist = 1; loadBlacklist(); }
        function goBlPage(p) { pages.blacklist = p; loadBlacklist(); }
        function loadBlacklist() {
            apiPost('get_blacklist', {
                page: pages.blacklist, page_size: pageSize,
                search: document.getElementById('blSearch').value.trim()
            }, function(res) {
                if (res.code !== 200) { toast(res.msg || '加载失败', 'error'); return; }
                var d = res.data;
                document.getElementById('blInfo').textContent = '共 ' + d.total + ' 条';
                renderBlTable(d.list);
                renderPager('blPager', d.total, d.page, d.page_size, 'goBlPage');
            });
        }
        function long2ipStr(l) {
            if (!l) return '';
            return ((l >>> 24) & 0xff) + '.' + ((l >>> 16) & 0xff) + '.' + ((l >>> 8) & 0xff) + '.' + (l & 0xff);
        }
        function renderBlTable(list) {
            var tb = document.getElementById('blBody');
            if (!list || list.length === 0) {
                tb.innerHTML = '<tr><td colspan="7" class="empty"><i class="fas fa-inbox"></i><p>暂无黑名单记录</p></td></tr>';
                return;
            }
            var h = '';
            list.forEach(function(r) {
                var ipText = escapeHtml(r.ip || '');
                if (r.ip_from && r.ip_to && r.ip_from != r.ip_to) {
                    ipText += ' <span class="status-tag info">范围 ' + long2ipStr(parseInt(r.ip_from)) + ' - ' + long2ipStr(parseInt(r.ip_to)) + '</span>';
                }
                var exp = r.expire_time;
                var expTag = exp ? escapeHtml(exp) : '<span class="status-tag warn">永久</span>';
                h += '<tr>' +
                    '<td data-label="ID">' + r.id + '</td>' +
                    '<td data-label="IP">' + ipText + '</td>' +
                    '<td data-label="原因">' + escapeHtml(r.reason || '-') + '</td>' +
                    '<td data-label="到期">' + expTag + '</td>' +
                    '<td data-label="创建者">' + escapeHtml(r.create_admin || '') + '</td>' +
                    '<td data-label="创建时间">' + escapeHtml(r.create_time || '') + '</td>' +
                    '<td data-label="操作"><div class="action-buttons">' +
                        '<button class="action-btn del" onclick="delBlacklist(' + r.id + ')"><i class="fas fa-trash"></i> 删除</button>' +
                    '</div></td>' +
                '</tr>';
            });
            tb.innerHTML = h;
        }
        function openAddBlacklist() {
            document.getElementById('blIp').value = '';
            document.getElementById('blReason').value = '';
            document.getElementById('blExpireType').value = 'forever';
            document.getElementById('blDays').value = '7';
            document.getElementById('blDate').value = '';
            toggleExpireInput();
            document.getElementById('blModal').classList.add('active');
        }
        function closeBlModal() { document.getElementById('blModal').classList.remove('active'); }
        function toggleExpireInput() {
            var t = document.getElementById('blExpireType').value;
            document.getElementById('blDaysBox').style.display = t === 'days' ? 'block' : 'none';
            document.getElementById('blDateBox').style.display = t === 'date' ? 'block' : 'none';
        }
        function submitBlacklist() {
            var ip = document.getElementById('blIp').value.trim();
            if (!ip) { toast('请输入IP地址', 'error'); return; }
            var reason = document.getElementById('blReason').value;
            if (!confirm('确认要添加该IP到黑名单吗？')) return;
            var d = { ip: ip, reason: reason };
            var t = document.getElementById('blExpireType').value;
            if (t === 'days') d.expire_days = document.getElementById('blDays').value;
            else if (t === 'date') {
                var dv = document.getElementById('blDate').value;
                if (!dv) { toast('请选择到期时间', 'error'); return; }
                d.expire_date = dv.replace('T', ' ') + ':00';
            }
            apiPost('add_blacklist', d, function(res) {
                if (res.code === 200) { toast(res.msg, 'success'); closeBlModal(); loadBlacklist(); }
                else toast(res.msg || '添加失败', 'error');
            });
        }
        function delBlacklist(id) {
            if (!confirm('确认要删除该黑名单记录吗？')) return;
            apiPost('del_blacklist', { id: id }, function(res) {
                if (res.code === 200) { toast(res.msg, 'success'); loadBlacklist(); }
                else toast(res.msg || '删除失败', 'error');
            });
        }

        // 限流配置
        function loadRateConfig() {
            // 读取默认值（服务端渲染也可以，这里用默认+兼容）
            document.getElementById('cfg5min').value = document.getElementById('cfg5min').value || 5;
            document.getElementById('cfgHour').value = document.getElementById('cfgHour').value || 20;
            document.getElementById('cfgLock').value = document.getElementById('cfgLock').value || 15;
            document.getElementById('cfgRate').value = document.getElementById('cfgRate').value || 120;
        }
        function saveConfig() {
            if (!confirm('确认要保存限流配置吗？')) return;
            var d = {
                login_max_5min: document.getElementById('cfg5min').value,
                login_max_hour: document.getElementById('cfgHour').value,
                login_lock_min: document.getElementById('cfgLock').value,
                rate_limit_page: document.getElementById('cfgRate').value
            };
            apiPost('save_config', d, function(res) {
                if (res.code === 200) toast(res.msg, 'success');
                else toast(res.msg || '保存失败', 'error');
            });
        }
        function searchFailed() { pages.failed = 1; loadFailed(); }
        function resetFailedSearch() {
            document.getElementById('failIp').value = '';
            document.getElementById('failUser').value = '';
            pages.failed = 1; loadFailed();
        }
        function goFailPage(p) { pages.failed = p; loadFailed(); }
        function loadFailed() {
            apiPost('get_failed_logins', {
                page: pages.failed, page_size: pageSize,
                ip: document.getElementById('failIp').value.trim(),
                username: document.getElementById('failUser').value.trim()
            }, function(res) {
                if (res.code !== 200) { toast(res.msg || '加载失败', 'error'); return; }
                var d = res.data;
                document.getElementById('failInfo').textContent = '共 ' + d.total + ' 条';
                renderFailTable(d.list);
                renderPager('failPager', d.total, d.page, d.page_size, 'goFailPage');
            });
        }
        function renderFailTable(list) {
            var tb = document.getElementById('failBody');
            if (!list || list.length === 0) {
                tb.innerHTML = '<tr><td colspan="5" class="empty"><i class="fas fa-inbox"></i><p>暂无失败记录</p></td></tr>';
                return;
            }
            var h = '';
            list.forEach(function(r) {
                h += '<tr>' +
                    '<td data-label="ID">' + r.id + '</td>' +
                    '<td data-label="时间">' + escapeHtml(r.create_time || '') + '</td>' +
                    '<td data-label="IP">' + escapeHtml(r.ip || '') + '</td>' +
                    '<td data-label="用户名">' + escapeHtml(r.username || '-') + '</td>' +
                    '<td data-label="UA"><span class="text-truncate" title="' + escapeHtml(r.ua || '') + '">' + escapeHtml(r.ua || '-') + '</span></td>' +
                '</tr>';
            });
            tb.innerHTML = h;
        }

        // 清理
        function cleanLogs() {
            var days = parseInt(document.getElementById('cleanDays').value || '30');
            if (days < 1) { toast('天数不能小于1', 'error'); return; }
            if (!confirm('确认要删除 ' + days + ' 天之前的所有日志吗？此操作不可恢复！')) return;
            if (!confirm('再次确认：将清理 ' + days + ' 天前的访问日志和登录失败日志，确定执行？')) return;
            apiPost('clear_old_logs', { days: days }, function(res) {
                if (res.code === 200) toast(res.msg, 'success');
                else toast(res.msg || '清理失败', 'error');
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadDashboard();
        });
    </script>
</body>
</html>
