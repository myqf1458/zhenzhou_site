<?php
/**
 * 后台友链管理 admin/links.php
 * 操作：审核通过 / 拒绝 / 移除 / 重新检测 / 排序 / 编辑
 */
require_once 'config.php';
checkAdminLogin();
require_once __DIR__ . '/../links_lib.php';

$admin = getCurrentAdmin();
$isSuper = isSuperAdmin();
$msg = '';
$msgType = 'success';

/* ============== 处理操作 ============== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);

    if (!$isSuper && in_array($action, ['approve', 'reject', 'remove', 'delete', 'recheck', 'save'], true)) {
        $msg = '仅超级管理员可执行此操作';
        $msgType = 'error';
    } elseif ($action === 'approve' && $id > 0) {
        $res = @mysqli_query($conn, "UPDATE links SET status=1, reject_reason='' WHERE id=$id");
        $msg = $res ? '已通过审核' : '操作失败';
        $msgType = $res ? 'success' : 'error';
        SecGuard::writeLog(2, 'links_approve', "ID:$id");
    } elseif ($action === 'reject' && $id > 0) {
        $reason = trim(mb_substr($_POST['reason'] ?? '', 0, 200));
        $rE = links_esc($reason);
        $res = @mysqli_query($conn, "UPDATE links SET status=2, reject_reason='$rE' WHERE id=$id");
        $msg = $res ? '已拒绝申请' : '操作失败';
        $msgType = $res ? 'success' : 'error';
        SecGuard::writeLog(2, 'links_reject', "ID:$id reason:$reason");
    } elseif ($action === 'remove' && $id > 0) {
        $res = @mysqli_query($conn, "UPDATE links SET status=3 WHERE id=$id");
        $msg = $res ? '已标记移除' : '操作失败';
        $msgType = $res ? 'success' : 'error';
        SecGuard::writeLog(2, 'links_remove', "ID:$id");
    } elseif ($action === 'delete' && $id > 0) {
        $res = @mysqli_query($conn, "DELETE FROM links WHERE id=$id");
        $msg = $res ? '已彻底删除' : '操作失败';
        $msgType = $res ? 'success' : 'error';
        SecGuard::writeLog(2, 'links_delete', "ID:$id");
    } elseif ($action === 'recheck' && $id > 0) {
        $row = null;
        $res = @mysqli_query($conn, "SELECT site_url FROM links WHERE id=$id LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $r = links_check_backlink($row['site_url']);
            $st = $r['ok'] ? 1 : 2;
            $msgE = links_esc($r['msg']);
            $ok = @mysqli_query($conn, "UPDATE links SET check_status=$st, check_detail='$msgE', check_time=NOW() WHERE id=$id");
            $msg = $ok ? ($r['ok'] ? '检测通过：' : '检测失败：') . $r['msg'] : '更新数据库失败';
            $msgType = $ok ? ($r['ok'] ? 'success' : 'warn') : 'error';
        } else {
            $msg = '记录不存在';
            $msgType = 'error';
        }
        SecGuard::writeLog(2, 'links_recheck', "ID:$id " . substr($msg, 0, 120));
    } elseif ($action === 'save' && $id > 0) {
        $nameE = links_esc(trim(mb_substr($_POST['site_name'] ?? '', 0, 80)));
        $urlE  = links_esc(trim(mb_substr($_POST['site_url'] ?? '', 0, 255)));
        $descE = links_esc(trim(mb_substr($_POST['site_desc'] ?? '', 0, 255)));
        $logoE = links_esc(trim(mb_substr($_POST['site_logo'] ?? '', 0, 255)));
        $sort  = intval($_POST['sort'] ?? 0);
        $st    = intval($_POST['status'] ?? 0);
        $ok = @mysqli_query($conn, "UPDATE links SET site_name='$nameE', site_url='$urlE', site_desc='$descE', site_logo='$logoE', `sort`=$sort, status=$st WHERE id=$id");
        $msg = $ok ? '已保存修改' : '保存失败';
        $msgType = $ok ? 'success' : 'error';
        SecGuard::writeLog(2, 'links_save', "ID:$id");
    }
}

/* ============== 查询参数 ============== */
$status = isset($_GET['status']) ? intval($_GET['status']) : -1; // -1 = 全部
$kw = trim($_GET['kw'] ?? '');
$where = [];
if ($status >= 0) $where[] = 'status=' . $status;
if ($kw !== '') {
    $k = links_esc($kw);
    $where[] = "(site_name LIKE '%$k%' OR site_url LIKE '%$k%' OR contact LIKE '%$k%' OR id LIKE '%$k%')";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// 统计
$cRes = @mysqli_query($conn, "SELECT COUNT(*) FROM links $whereSql");
$total = $cRes ? intval(mysqli_fetch_row($cRes)[0]) : 0;

$pageSize = 20;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $pageSize;
$totalPages = max(1, (int)ceil($total / $pageSize));
$page = min($page, $totalPages);

$list = [];
$sql = "SELECT * FROM links $whereSql ORDER BY FIELD(status,0,2,1,3), id DESC LIMIT $offset, $pageSize";
$res = @mysqli_query($conn, $sql);
if ($res) while ($row = mysqli_fetch_assoc($res)) $list[] = $row;

// 顶部统计
$stRow = [];
foreach ([0, 1, 2, 3] as $s) {
    $rr = @mysqli_query($conn, "SELECT COUNT(*) FROM links WHERE status=$s");
    $stRow[$s] = $rr ? intval(mysqli_fetch_row($rr)[0]) : 0;
}
$checkRow = [];
foreach ([1, 2] as $s) {
    $rr = @mysqli_query($conn, "SELECT COUNT(*) FROM links WHERE check_status=$s");
    $checkRow[$s] = $rr ? intval(mysqli_fetch_row($rr)[0]) : 0;
}

function statusBadge($s) {
    $map = [
        0 => ['label' => '待审核', 'cls' => 'badge badge-warn'],
        1 => ['label' => '已通过', 'cls' => 'badge badge-ok'],
        2 => ['label' => '已拒绝', 'cls' => 'badge badge-err'],
        3 => ['label' => '已移除', 'cls' => 'badge badge-muted'],
    ];
    $m = $map[$s] ?? ['label' => '未知', 'cls' => 'badge badge-muted'];
    return '<span class="' . $m['cls'] . '">' . $m['label'] . '</span>';
}
function checkBadge($s) {
    $map = [
        0 => ['label' => '未检测', 'cls' => 'badge badge-muted'],
        1 => ['label' => '检测通过', 'cls' => 'badge badge-ok'],
        2 => ['label' => '无本站链', 'cls' => 'badge badge-err'],
    ];
    $m = $map[$s] ?? ['label' => '-', 'cls' => 'badge badge-muted'];
    return '<span class="' . $m['cls'] . '">' . $m['label'] . '</span>';
}
$pageTitle = '友链管理';
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $pageTitle; ?> - 后台管理系统</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="common.css">
<style>
    :root {
        --primary: #c9a9d4; --primary-dark: #b892c2; --primary-light: #f3e8f7;
        --secondary: #e8c5d0; --secondary-light: #f9f0f3; --success: #a8d5ba;
        --warning: #e8d4a8; --danger: #e8b0b0;
        --bg-page: #ffffff; --bg-card: rgba(255,255,255,.85); --bg-input: rgba(248,244,250,.8);
        --border-color: rgba(201,169,212,.3); --text-primary: #4a4556; --text-secondary: #8b8594;
        --text-muted: #b5b0bc; --sidebar-width: 240px; --header-height: 64px;
        --shadow-light: 0 4px 20px rgba(201,169,212,.1);
    }
    body {
        font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
        background: var(--bg-page);
        background-image:
            radial-gradient(circle at 20% 80%, rgba(232,197,208,.15) 0%, transparent 50%),
            radial-gradient(circle at 80% 20%, rgba(201,169,212,.15) 0%, transparent 50%);
        color: var(--text-primary); min-height: 100vh;
    }
    .sidebar {
        position: fixed; left: 0; top: 0; bottom: 0; width: var(--sidebar-width);
        background: rgba(255,255,255,.7); backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px); border-right: 1px solid var(--border-color);
        z-index: 1000; display: flex; flex-direction: column;
    }
    .sidebar-header { height: var(--header-height); display: flex; align-items: center; padding: 0 24px; border-bottom: 1px solid var(--border-color); }
    .sidebar-logo { display: flex; align-items: center; gap: 12px; }
    .sidebar-logo .logo-icon {
        width: 36px; height: 36px; border-radius: 14px; display:flex; align-items:center; justify-content:center; overflow:hidden;
        box-shadow: 0 4px 12px rgba(201,169,212,.3); border: 2px solid rgba(201,169,212,.3);
    }
    .sidebar-logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; }
    .sidebar-logo .logo-text { font-size: 18px; font-weight: 600; color: var(--text-primary); }
    .sidebar-menu { flex: 1; padding: 16px 0; overflow-y: auto; }
    .menu-item a {
        display: flex; align-items: center; gap: 12px; padding: 12px 24px; color: var(--text-secondary);
        text-decoration: none; font-size: 14px; transition: all .3s ease;
        border-left: 3px solid transparent; border-radius: 0 12px 12px 0;
    }
    .menu-item a:hover { background: rgba(201,169,212,.1); color: var(--text-primary); border-left-color: rgba(201,169,212,.5); }
    .menu-item a.active { background: rgba(201,169,212,.15); color: var(--primary-dark); border-left-color: var(--primary); }
    .menu-item .icon { font-size: 17px; width: 20px; text-align: center; }
    .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }
    .header {
        height: var(--header-height); background: rgba(255,255,255,.7); backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid var(--border-color);
        display:flex; align-items:center; justify-content:space-between; padding: 0 24px; position: sticky; top: 0; z-index: 100;
    }
    .header-left { display: flex; align-items: center; gap: 16px; }
    .header-title { font-size: 18px; font-weight: 600; }
    .header-breadcrumb { display:flex; align-items:center; gap:8px; font-size: 13px; color: var(--text-secondary); }
    .header-breadcrumb a { color: var(--text-secondary); text-decoration: none; }
    .header-breadcrumb a:hover { color: var(--primary-dark); }
    .header-right { display:flex; align-items:center; gap:12px; }
    .header-action {
        width: auto; padding: 0 12px; height: 36px; background: var(--bg-input); border:1px solid var(--border-color);
        border-radius:12px; display:flex; align-items:center; justify-content:center; gap:6px;
        font-size:13px; color: var(--text-secondary);
    }
    .page-content { padding: 24px; }

    /* 卡片 & 表格 */
    .stat-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
    .stat-card {
        background: var(--bg-card); backdrop-filter: blur(14px) saturate(160%);
        -webkit-backdrop-filter: blur(14px) saturate(160%);
        border-radius: 16px; border:1px solid var(--border-color); padding: 20px 22px;
        box-shadow: var(--shadow-light);
    }
    .stat-label { font-size: 13px; color: var(--text-secondary); margin-bottom: 6px; }
    .stat-value { font-size: 26px; font-weight: 700; color: var(--text-primary); }
    .stat-value .mini { font-size: 12px; font-weight: 500; color: var(--text-muted); margin-left: 6px; }

    .toolbar {
        background: var(--bg-card); border:1px solid var(--border-color); border-radius:16px;
        padding: 16px 20px; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
        backdrop-filter: blur(14px);
    }
    .toolbar .tabs { display: flex; gap: 6px; flex-wrap: wrap; }
    .tab-btn {
        padding: 7px 14px; border-radius: 10px; border:1px solid var(--border-color);
        background: rgba(255,255,255,.7); color: var(--text-secondary); cursor: pointer;
        font-size: 13px; text-decoration: none; transition: all .2s;
    }
    .tab-btn:hover { border-color: var(--primary); color: var(--primary-dark); }
    .tab-btn.active { background: rgba(201,169,212,.2); color: var(--primary-dark); border-color: var(--primary); font-weight: 600; }
    .search-box {
        display:flex; align-items:center; gap:8px; margin-left: auto;
    }
    .search-box input {
        height: 38px; padding: 0 14px; border-radius: 10px; border:1px solid var(--border-color);
        background: rgba(255,255,255,.8); min-width: 220px; outline: none; color: var(--text-primary); font-family: inherit;
    }
    .search-box input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(201,169,212,.15); }
    .search-box button {
        height: 38px; padding: 0 14px; border-radius: 10px; border: none; cursor: pointer;
        background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; font-weight: 600;
    }

    .data-card {
        background: var(--bg-card); backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border-radius: 16px; border: 1px solid var(--border-color); overflow: hidden; box-shadow: var(--shadow-light);
    }
    table.data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
        background: rgba(201,169,212,.08); padding: 14px 18px; text-align: left;
        font-size: 13px; font-weight: 600; color: var(--text-secondary); border-bottom:1px solid var(--border-color);
    }
    .data-table td {
        padding: 14px 18px; font-size: 14px; color: var(--text-primary);
        border-bottom: 1px solid rgba(201,169,212,.15); vertical-align: top;
    }
    .data-table tr:hover td { background: rgba(201,169,212,.06); }
    .link-title {
        font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px;
    }
    .link-title img {
        width: 28px; height: 28px; border-radius: 8px; object-fit: cover;
        border: 1px solid var(--border-color); background: #fff; flex-shrink: 0;
    }
    .link-title a { color: inherit; text-decoration: none; }
    .link-title a:hover { color: var(--primary-dark); }
    .link-url { font-size: 12px; color: var(--text-muted); margin-top: 4px; word-break: break-all; }
    .link-desc { font-size: 13px; color: var(--text-secondary); line-height: 1.6; max-width: 260px; }
    .link-meta { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
    .badge {
        display:inline-block; padding:3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;
    }
    .badge-ok { background: rgba(168,213,186,.3); color: #3a7556; }
    .badge-warn { background: rgba(232,212,168,.4); color: #936c25; }
    .badge-err { background: rgba(232,176,176,.3); color: #a64b4b; }
    .badge-muted { background: rgba(140,140,160,.2); color: #777; }
    .row-actions { display:flex; flex-wrap: wrap; gap: 6px; }
    .btn-row {
        padding: 5px 10px; font-size: 12px; border-radius: 8px; border: 1px solid var(--border-color);
        background: rgba(255,255,255,.7); color: var(--text-secondary); cursor: pointer;
        text-decoration: none; transition: all .2s;
    }
    .btn-row:hover { border-color: var(--primary); color: var(--primary-dark); }
    .btn-row.primary { background: rgba(168,213,186,.25); color:#3a7556; border-color: rgba(168,213,186,.5); }
    .btn-row.primary:hover { background: rgba(168,213,186,.4); }
    .btn-row.danger { background: rgba(232,176,176,.2); color:#a64b4b; border-color: rgba(232,176,176,.45); }
    .btn-row.danger:hover { background: rgba(232,176,176,.35); }
    .check-detail {
        margin-top: 6px; font-size: 12px; color: var(--text-muted); max-width: 280px; line-height: 1.55;
    }
    .reject-reason {
        margin-top: 6px; font-size: 12px; color: #a64b4b;
    }

    /* 分页 */
    .pager {
        display: flex; justify-content: center; align-items: center; gap: 6px;
        padding: 18px; color: var(--text-secondary); font-size: 13px;
    }
    .pager a, .pager span {
        padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border-color);
        background: rgba(255,255,255,.7); text-decoration: none; color: var(--text-secondary);
    }
    .pager a:hover { border-color: var(--primary); color: var(--primary-dark); }
    .pager .current { background: rgba(201,169,212,.2); color: var(--primary-dark); border-color: var(--primary); font-weight: 600; }

    /* 弹窗 */
    .modal-mask {
        position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 2000;
        display: none; align-items: center; justify-content: center; padding: 16px;
    }
    .modal-mask.show { display: flex; }
    .modal {
        width: 100%; max-width: 520px; background: #fff; border-radius: 16px;
        padding: 22px 24px; box-shadow: 0 30px 80px rgba(0,0,0,.25);
    }
    .modal h3 { font-size: 18px; font-weight: 600; color: var(--text-primary); margin-bottom: 14px; }
    .modal label { display:block; font-size: 13px; color: var(--text-primary); font-weight: 600; margin: 10px 0 6px; }
    .modal input, .modal textarea, .modal select {
        width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color);
        background: var(--bg-input); color: var(--text-primary); font-family: inherit; font-size: 14px; outline: none;
    }
    .modal input:focus, .modal textarea:focus, .modal select:focus {
        border-color: var(--primary); box-shadow: 0 0 0 3px rgba(201,169,212,.15);
    }
    .modal .actions { display:flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
    .btn {
        padding: 8px 18px; border-radius: 10px; border: none; cursor: pointer; font-weight: 600; font-size: 14px;
        font-family: inherit; text-decoration: none;
    }
    .btn-cancel { background: rgba(201,169,212,.18); color: var(--primary-dark); }
    .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; }
    .btn-danger { background: #e8b0b0; color: #8a3e3e; }

    /* 响应式 */
    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); transition: transform .3s; width: 260px; z-index: 1600; background: rgba(255,255,255,.96); }
        .sidebar.open { transform: translateX(0); box-shadow: 4px 0 20px rgba(201,169,212,.2); }
        .main-content { margin-left: 0; }
        .stat-row { grid-template-columns: repeat(2, 1fr); }
        .search-box { margin-left: 0; width: 100%; }
        .search-box input { min-width: 0; flex: 1; }
        .data-table th, .data-table td { padding: 10px 12px; }
    }
    .alert {
        padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; display: flex; align-items: center; gap: 8px;
    }
    .alert-success { background: rgba(168,213,186,.22); color: #3d6a4f; border:1px solid rgba(168,213,186,.4); }
    .alert-error { background: rgba(232,176,176,.22); color: #8a4040; border:1px solid rgba(232,176,176,.4); }
    .alert-warn { background: rgba(232,212,168,.22); color: #8a6a20; border:1px solid rgba(232,212,168,.4); }
    .mobile-menu-btn {
        position: fixed; top: 14px; left: 14px; z-index: 1700; width: 40px; height: 40px;
        background: rgba(255,255,255,.9); border:1px solid var(--border-color); border-radius: 12px;
        display: none; align-items: center; justify-content: center; cursor: pointer; color: var(--text-primary);
    }
    @media (max-width: 768px) { .mobile-menu-btn { display: flex; } }
    .sidebar-overlay {
        position: fixed; inset: 0; background: rgba(0,0,0,.28); z-index: 1500; display: none;
    }
    .sidebar-overlay.show { display: block; }
</style>
</head>
<body>
<div class="mobile-menu-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></div>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <div class="logo-icon"><img src="../img/5.jpg" alt="logo"></div>
            <div class="logo-text">真昼小破站</div>
        </div>
    </div>
    <div class="sidebar-menu">
        <div class="menu-item"><a href="index.php"><i class="fas fa-chart-pie icon"></i><span>仪表盘</span></a></div>
        <div class="menu-item"><a href="users.php"><i class="fas fa-users icon"></i><span>用户管理</span></a></div>
        <div class="menu-item"><a href="community.php"><i class="fas fa-comments icon"></i><span>社区管理</span></a></div>
        <div class="menu-item"><a href="avatar_frames.php"><i class="fas fa-circle-notch icon"></i><span>头像框管理</span></a></div>
        <div class="menu-item"><a href="comments.php"><i class="fas fa-comment-dots icon"></i><span>留言管理</span></a></div>
        <div class="menu-item"><a href="bangumi.php"><i class="fas fa-tv icon"></i><span>追番管理</span></a></div>
        <div class="menu-item"><a href="reports.php"><i class="fas fa-flag icon"></i><span>举报处理</span></a></div>
        <div class="menu-item"><a href="links.php" class="active"><i class="fas fa-link icon"></i><span>友链管理</span></a></div>
        <?php if ($isSuper): ?>
        <div class="menu-item"><a href="admins.php"><i class="fas fa-shield-alt icon"></i><span>管理员管理</span></a></div>
        <div class="menu-item"><a href="security.php"><i class="fas fa-user-shield icon"></i><span>安全中心</span></a></div>
        <div class="menu-item"><a href="qiniu.php"><i class="fas fa-cloud icon"></i><span>七牛存储</span></a></div>
        <div class="menu-item"><a href="qq_config.php"><i class="fas fa-qrcode icon"></i><span>QQ登录配置</span></a></div>
        <?php endif; ?>
        <div class="menu-item"><a href="logout.php"><i class="fas fa-sign-out-alt icon"></i><span>退出登录</span></a></div>
    </div>
</div>

<div class="main-content">
    <div class="header">
        <div class="header-left">
            <h1 class="header-title"><?php echo $pageTitle; ?></h1>
            <div class="header-breadcrumb">
                <a href="index.php">首页</a> <span>/</span> <span><?php echo $pageTitle; ?></span>
            </div>
        </div>
        <div class="header-right">
            <div class="header-action">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($admin['nickname'] ?? $admin['username']); ?></span>
            </div>
        </div>
    </div>

    <div class="page-content">
        <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msgType; ?>">
            <i class="fas fa-<?php echo $msgType === 'success' ? 'check-circle' : ($msgType === 'warn' ? 'exclamation-triangle' : 'times-circle'); ?>"></i>
            <?php echo htmlspecialchars($msg); ?>
        </div>
        <?php endif; ?>

        <div class="stat-row">
            <div class="stat-card"><div class="stat-label">总申请数</div><div class="stat-value"><?php echo $stRow[0]+$stRow[1]+$stRow[2]+$stRow[3]; ?></div></div>
            <div class="stat-card"><div class="stat-label">待审核</div><div class="stat-value" style="color:#b37820"><?php echo $stRow[0]; ?></div></div>
            <div class="stat-card"><div class="stat-label">已通过</div><div class="stat-value" style="color:#3a7556"><?php echo $stRow[1]; ?></div></div>
            <div class="stat-card"><div class="stat-label">反向检测<span class="mini">通过/未通过</span></div>
                <div class="stat-value" style="font-size:20px">
                    <span style="color:#3a7556"><?php echo $checkRow[1] ?? 0; ?></span>
                    <span style="color:#aaa">/</span>
                    <span style="color:#a64b4b"><?php echo $checkRow[2] ?? 0; ?></span>
                </div>
            </div>
        </div>

        <div class="toolbar">
            <div class="tabs">
                <?php
                $tabs = [
                    -1 => '全部',
                    0  => '待审核',
                    1  => '已通过',
                    2  => '已拒绝',
                    3  => '已移除',
                ];
                $qsBase = [];
                if ($kw !== '') $qsBase['kw'] = $kw;
                foreach ($tabs as $s => $label) {
                    $qs = $qsBase;
                    if ($s >= 0) $qs['status'] = $s;
                    $href = 'links.php' . ($qs ? ('?' . http_build_query($qs)) : '');
                    $active = $status === $s ? 'active' : '';
                    echo "<a class=\"tab-btn $active\" href=\"$href\">$label</a>";
                }
                ?>
            </div>
            <form class="search-box" method="GET" action="">
                <?php if ($status >= 0): ?><input type="hidden" name="status" value="<?php echo $status; ?>"><?php endif; ?>
                <input type="text" name="kw" value="<?php echo htmlspecialchars($kw); ?>" placeholder="搜索名称/URL/联系方式/ID">
                <button type="submit"><i class="fas fa-search"></i> 搜索</button>
                <?php if ($kw !== '' || $status >= 0): ?>
                <a class="tab-btn" href="links.php">重置</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="data-card">
            <table class="data-table">
                <thead>
                <tr>
                    <th style="width:30%">站点</th>
                    <th style="width:22%">简介/联系方式</th>
                    <th>状态/反向检测</th>
                    <th style="width:15%">排序/时间</th>
                    <th style="width:220px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$list): ?>
                    <tr><td colspan="5" style="padding:48px 0;text-align:center;color:var(--text-muted)">暂无数据</td></tr>
                <?php endif; ?>
                <?php foreach ($list as $r): ?>
                <tr>
                    <td>
                        <div class="link-title">
                            <?php if (!empty($r['site_logo'])): ?>
                            <img src="<?php echo htmlspecialchars($r['site_logo']); ?>" alt="logo" onerror="this.style.display='none'">
                            <?php endif; ?>
                            <a href="<?php echo htmlspecialchars($r['site_url']); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo htmlspecialchars($r['site_name']); ?>
                            </a>
                        </div>
                        <div class="link-url" title="<?php echo htmlspecialchars($r['site_url']); ?>">
                            <?php echo htmlspecialchars($r['site_url']); ?>
                        </div>
                        <?php if (!empty($r['link_prove'])): ?>
                        <div class="link-meta">证明: <?php echo htmlspecialchars(mb_substr($r['link_prove'], 0, 60)); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="link-desc"><?php echo htmlspecialchars($r['site_desc'] ?: '—'); ?></div>
                        <?php if (!empty($r['contact'])): ?>
                        <div class="link-meta"><i class="fas fa-address-book"></i> <?php echo htmlspecialchars($r['contact']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($r['ip'])): ?>
                        <div class="link-meta"><i class="fas fa-globe-asia"></i> <?php echo htmlspecialchars($r['ip']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?php echo statusBadge(intval($r['status'])); ?></div>
                        <?php if (!empty($r['reject_reason'])): ?>
                        <div class="reject-reason">原因: <?php echo htmlspecialchars($r['reject_reason']); ?></div>
                        <?php endif; ?>
                        <div style="margin-top:8px"><?php echo checkBadge(intval($r['check_status'])); ?></div>
                        <?php if (!empty($r['check_detail'])): ?>
                        <div class="check-detail"><?php echo htmlspecialchars($r['check_detail']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($r['check_time'])): ?>
                        <div class="link-meta">检测时间: <?php echo htmlspecialchars($r['check_time']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div>排序: <b><?php echo intval($r['sort']); ?></b></div>
                        <div class="link-meta">提交: <?php echo htmlspecialchars($r['create_time']); ?></div>
                        <div class="link-meta">更新: <?php echo htmlspecialchars($r['update_time']); ?></div>
                    </td>
                    <td>
                        <div class="row-actions">
                            <?php if (intval($r['status']) === 0 && $isSuper): ?>
                            <form method="POST" style="display:inline">
                                <?php echo SecGuard::csrfField(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="btn-row primary" onclick="return confirm('确认通过 #<?php echo $r['id']; ?> 的友链申请？')">通过</button>
                            </form>
                            <button class="btn-row" onclick="openReject(<?php echo $r['id']; ?>)">拒绝</button>
                            <?php endif; ?>
                            <form method="POST" style="display:inline">
                                <?php echo SecGuard::csrfField(); ?>
                                <input type="hidden" name="action" value="recheck">
                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="btn-row">再检测</button>
                            </form>
                            <button class="btn-row" onclick="openEdit(
                                <?php echo (int)$r['id']; ?>,
                                <?php echo json_encode((string)$r['site_name'], JSON_UNESCAPED_UNICODE); ?>,
                                <?php echo json_encode((string)$r['site_url'], JSON_UNESCAPED_UNICODE); ?>,
                                <?php echo json_encode((string)$r['site_desc'], JSON_UNESCAPED_UNICODE); ?>,
                                <?php echo json_encode((string)$r['site_logo'], JSON_UNESCAPED_UNICODE); ?>,
                                <?php echo (int)$r['sort']; ?>,
                                <?php echo (int)$r['status']; ?>
                            )">编辑</button>
                            <?php if ($isSuper): ?>
                            <?php if (intval($r['status']) !== 3): ?>
                            <form method="POST" style="display:inline">
                                <?php echo SecGuard::csrfField(); ?>
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="btn-row" onclick="return confirm('确认标记移除 #<?php echo $r['id']; ?>？')">移除</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline">
                                <?php echo SecGuard::csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="btn-row danger" onclick="return confirm('彻底删除 #<?php echo $r['id']; ?>？不可恢复！')">删除</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
            <div class="pager">
                <?php
                $buildQs = function ($page) use ($status, $kw) {
                    $a = [];
                    if ($page > 1) $a['page'] = $page;
                    if ($status >= 0) $a['status'] = $status;
                    if ($kw !== '') $a['kw'] = $kw;
                    return $a ? ('?' . http_build_query($a)) : 'links.php';
                };
                if ($page > 1) echo '<a href="' . $buildQs($page - 1) . '">上一页</a>';
                $start = max(1, $page - 3);
                $end = min($totalPages, $start + 6);
                for ($i = $start; $i <= $end; $i++) {
                    if ($i === $page) echo "<span class=\"current\">$i</span>";
                    else echo '<a href="' . $buildQs($i) . '">' . $i . '</a>';
                }
                if ($page < $totalPages) echo '<a href="' . $buildQs($page + 1) . '">下一页</a>';
                ?>
                <span>共 <?php echo $total; ?> 条 / <?php echo $totalPages; ?> 页</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 拒绝原因弹窗 -->
<div class="modal-mask" id="rejectModal">
<form class="modal" method="POST" id="rejectForm">
    <?php echo SecGuard::csrfField(); ?>
    <input type="hidden" name="action" value="reject">
    <input type="hidden" name="id" id="rejectId" value="0">
    <h3>拒绝友链申请</h3>
    <label>拒绝原因（可选）</label>
    <textarea id="rejectReason" name="reason" rows="4" placeholder="如：站点未添加本站友链、内容不符合要求等"></textarea>
    <div class="actions">
        <button type="button" class="btn btn-cancel" onclick="document.getElementById('rejectModal').classList.remove('show')">取消</button>
        <button type="submit" class="btn btn-danger" onclick="return confirm('确认拒绝？')">确认拒绝</button>
    </div>
</form>
</div>

<!-- 编辑弹窗 -->
<div class="modal-mask" id="editModal">
<form class="modal" method="POST" id="editForm">
    <?php echo SecGuard::csrfField(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" id="editId" value="0">
    <h3>编辑友链 #<span id="editIdShow"></span></h3>
    <label>站点名称</label>
    <input type="text" name="site_name" id="editSiteName" required maxlength="80">
    <label>网站地址</label>
    <input type="url" name="site_url" id="editSiteUrl" required maxlength="255">
    <label>站点简介</label>
    <textarea name="site_desc" id="editSiteDesc" rows="2" maxlength="255"></textarea>
    <label>站点图标 URL（可选）</label>
    <input type="text" name="site_logo" id="editSiteLogo" maxlength="255" placeholder="https://.../favicon.ico">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
            <label>排序（越小越靠前）</label>
            <input type="number" name="sort" id="editSort" value="0">
        </div>
        <div>
            <label>状态</label>
            <select name="status" id="editStatus">
                <option value="0">待审核</option>
                <option value="1">已通过</option>
                <option value="2">已拒绝</option>
                <option value="3">已移除</option>
            </select>
        </div>
    </div>
    <div class="actions">
        <button type="button" class="btn btn-cancel" onclick="document.getElementById('editModal').classList.remove('show')">取消</button>
        <button type="submit" class="btn btn-primary" onclick="return confirm('确认保存？')">保存</button>
    </div>
</form>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
function openReject(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectModal').classList.add('show');
}
function openEdit(id, name, url, desc, logo, sort, status) {
    document.getElementById('editId').value = id;
    document.getElementById('editIdShow').textContent = id;
    document.getElementById('editSiteName').value = name;
    document.getElementById('editSiteUrl').value = url;
    document.getElementById('editSiteDesc').value = desc;
    document.getElementById('editSiteLogo').value = logo;
    document.getElementById('editSort').value = sort;
    document.getElementById('editStatus').value = status;
    document.getElementById('editModal').classList.add('show');
}
// 点遮罩关闭弹窗
document.querySelectorAll('.modal-mask').forEach(function(m){
    m.addEventListener('click', function(e){ if (e.target === m) m.classList.remove('show'); });
});
</script>
</body>
</html>
