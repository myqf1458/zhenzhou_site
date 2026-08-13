<?php
require_once 'config.php';
checkAdminLogin();

$admin = getCurrentAdmin();
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? 'all');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// 创建表（如果不存在）
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
mysqli_query($conn, $createBoardSql);

$defaultBoards = [
    ['id'=>1,'name'=>'全部动态','icon'=>'','description'=>'所有板块的帖子','sort'=>0],
    ['id'=>2,'name'=>'动漫讨论','icon'=>'','description'=>'动画、轻小说相关讨论','sort'=>10],
    ['id'=>3,'name'=>'生活日常','icon'=>'','description'=>'日常碎碎念、生活分享','sort'=>20],
    ['id'=>4,'name'=>'作品分享','icon'=>'','description'=>'绘画、剪辑、手作等创作','sort'=>30],
    ['id'=>5,'name'=>'互动交流','icon'=>'','description'=>'提问、求助、闲聊','sort'=>40],
    ['id'=>6,'name'=>'资源共享','icon'=>'','description'=>'壁纸、音乐、电子书等','sort'=>50],
    ['id'=>7,'name'=>'活动专区','icon'=>'','description'=>'站内活动和公告','sort'=>60],
];
// 去重：保留每个 name 的最小 id
mysqli_query($conn, "DELETE b1 FROM community_boards b1 INNER JOIN community_boards b2 ON b1.name = b2.name AND b1.id > b2.id");
// 读取当前 name→id 映射
$oldBoardMap = [];
$bRes = mysqli_query($conn, "SELECT id, name FROM community_boards");
if ($bRes) { while ($r = mysqli_fetch_assoc($bRes)) $oldBoardMap[$r['name']] = (int)$r['id']; }
// 清空并重新用固定 id 写入
mysqli_query($conn, "DELETE FROM community_boards");
foreach ($defaultBoards as $b) {
    $bid = (int)$b['id'];
    $nm = mysqli_real_escape_string($conn, $b['name']);
    $ic = mysqli_real_escape_string($conn, $b['icon']);
    $de = mysqli_real_escape_string($conn, $b['description']);
    $so = (int)$b['sort'];
    mysqli_query($conn, "INSERT INTO community_boards (id, name, icon, description, sort) VALUES ({$bid}, '{$nm}', '{$ic}', '{$de}', {$so})");
    if (isset($oldBoardMap[$b['name']]) && $oldBoardMap[$b['name']] !== $bid) {
        $oldBid = $oldBoardMap[$b['name']];
        mysqli_query($conn, "UPDATE community_posts SET board_id = {$bid} WHERE board_id = {$oldBid}");
    }
}
mysqli_query($conn, "ALTER TABLE community_boards AUTO_INCREMENT = 8");

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
mysqli_query($conn, $createPostSql);

// 升级已有表：补 board_id / title 列
$cols = mysqli_query($conn, "SHOW COLUMNS FROM community_posts LIKE 'board_id'");
if (!$cols || mysqli_num_rows($cols) === 0) {
    @mysqli_query($conn, "ALTER TABLE community_posts ADD COLUMN board_id INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER id");
    @mysqli_query($conn, "ALTER TABLE community_posts ADD INDEX idx_board_time (board_id, create_time)");
}
$cols2 = mysqli_query($conn, "SHOW COLUMNS FROM community_posts LIKE 'title'");
if (!$cols2 || mysqli_num_rows($cols2) === 0) {
    @mysqli_query($conn, "ALTER TABLE community_posts ADD COLUMN title VARCHAR(200) NOT NULL DEFAULT '' AFTER avatar");
}

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $postId = intval($_POST['id'] ?? 0);
    $action = $_POST['action'];
    if ($postId > 0) {
        if ($action === 'delete') {
            $sql = "UPDATE community_posts SET status = 0 WHERE id = {$postId}";
        } elseif ($action === 'block') {
            $sql = "UPDATE community_posts SET status = 2 WHERE id = {$postId}";
        } elseif ($action === 'restore') {
            $sql = "UPDATE community_posts SET status = 1 WHERE id = {$postId}";
        } elseif ($action === 'delete_permanent') {
            $sql = "DELETE FROM community_posts WHERE id = {$postId}";
        }

        if (isset($sql) && mysqli_query($conn, $sql)) {
            $msgMap = ['delete' => '删除成功', 'block' => '屏蔽成功', 'restore' => '恢复成功', 'delete_permanent' => '彻底删除成功'];
            header('Location: community.php?msg=' . $action . '_success&search=' . urlencode($search) . '&status=' . urlencode($status) . '&page=' . $page);
            exit;
        } else {
            $error = '操作失败: ' . mysqli_error($conn);
        }
    }
}

// 构建查询条件
$whereClauses = [];
if ($search) {
    $searchEscaped = mysqli_real_escape_string($conn, $search);
    $whereClauses[] = "(author LIKE '%{$searchEscaped}%' OR content LIKE '%{$searchEscaped}%')";
}
if ($status !== 'all') {
    $statusInt = intval($status);
    $whereClauses[] = "status = {$statusInt}";
}
$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = ' WHERE ' . implode(' AND ', $whereClauses);
}

// 查询总数
$countSql = "SELECT COUNT(*) as total FROM community_posts{$whereSql}";
$result = mysqli_query($conn, $countSql);
$total = 0;
if ($result && $row = mysqli_fetch_assoc($result)) {
    $total = $row['total'];
}
$totalPages = ceil($total / $limit);

// 查询列表
$sql = "SELECT * FROM community_posts{$whereSql} ORDER BY id DESC LIMIT {$offset}, {$limit}";
$result = mysqli_query($conn, $sql);
$posts = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $posts[] = $row;
    }
}

// 统计各状态数量
$statusCounts = ['all' => 0, '1' => 0, '0' => 0, '2' => 0];
$result = mysqli_query($conn, "SELECT status, COUNT(*) as cnt FROM community_posts GROUP BY status");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $statusCounts[$row['status']] = intval($row['cnt']);
        $statusCounts['all'] += intval($row['cnt']);
    }
}

$statusLabels = [1 => '正常', 0 => '已删除', 2 => '已屏蔽'];
$statusColors = [1 => 'var(--success)', 0 => 'var(--text-muted)', 2 => 'var(--danger)'];

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>社区管理</title>
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
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid var(--border-color);
            z-index: 1000; display: flex; flex-direction: column;
        }
        .sidebar-header { height: var(--header-height); display: flex; align-items: center; padding: 0 24px; border-bottom: 1px solid var(--border-color); }
        .sidebar-logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-logo .logo-icon { width: 36px; height: 36px; border-radius: 14px; display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3); border: 2px solid rgba(201, 169, 212, 0.3); }
        .sidebar-logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; }
        .sidebar-logo .logo-text { font-size: 18px; font-weight: 600; color: var(--text-primary); }
        .sidebar-menu { flex: 1; padding: 16px 0; overflow-y: auto; }
        .menu-item a { display: flex; align-items: center; gap: 12px; padding: 12px 24px; color: var(--text-secondary); text-decoration: none; font-size: 14px; transition: all 0.3s ease; border-left: 3px solid transparent; border-radius: 0 12px 12px 0; }
        .menu-item a:hover { background: rgba(201, 169, 212, 0.1); color: var(--text-primary); border-left-color: rgba(201, 169, 212, 0.5); }
        .menu-item a.active { background: rgba(201, 169, 212, 0.15); color: var(--primary-dark); border-left-color: var(--primary); }
        .menu-item .icon { font-size: 17px; width: 20px; text-align: center; }
        .menu-item .badge { margin-left: auto; padding: 2px 8px; background: rgba(232, 176, 176, 0.2); color: var(--danger); font-size: 12px; border-radius: 10px; }

        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }
        .header { height: var(--header-height); background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 24px; position: sticky; top: 0; z-index: 100; }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-title { font-size: 18px; font-weight: 600; }
        .header-breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
        .header-breadcrumb a { color: var(--text-secondary); text-decoration: none; transition: color 0.2s; }
        .header-breadcrumb a:hover { color: var(--primary-dark); }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .header-action { width: 36px; height: 36px; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 15px; color: var(--text-secondary); cursor: pointer; transition: all 0.3s ease; }
        .header-action:hover { background: rgba(201, 169, 212, 0.15); border-color: var(--primary); color: var(--primary-dark); transform: translateY(-1px); }

        .page-content { padding: 24px; }

        .message { background: rgba(168, 213, 186, 0.15); border: 1px solid rgba(168, 213, 186, 0.3); color: #7ab893; padding: 14px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        .error { background: rgba(232, 176, 176, 0.15); border: 1px solid rgba(232, 176, 176, 0.3); color: var(--danger); padding: 14px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }

        /* 统计卡片 */
        .stats-row { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-tab { background: var(--bg-card); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px 24px; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: flex; align-items: center; gap: 12px; box-shadow: var(--shadow-light); }
        .stat-tab:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); }
        .stat-tab.active { background: rgba(201, 169, 212, 0.15); border-color: var(--primary); }
        .stat-tab .stat-num { font-size: 22px; font-weight: 700; color: var(--text-primary); }
        .stat-tab .stat-label { font-size: 13px; color: var(--text-secondary); }

        /* 搜索栏 */
        .search-bar { background: var(--bg-card); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px 24px; margin-bottom: 24px; display: flex; gap: 12px; box-shadow: var(--shadow-light); }
        .search-bar input { flex: 1; padding: 12px 16px; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 12px; font-size: 14px; color: var(--text-primary); outline: none; transition: all 0.3s ease; }
        .search-bar input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(201, 169, 212, 0.1); }
        .search-bar input::placeholder { color: var(--text-muted); }
        .search-bar button { padding: 12px 24px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; border: none; border-radius: 12px; font-size: 14px; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3); }
        .search-bar button:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(201, 169, 212, 0.4); }

        /* 数据表格 */
        .data-card { background: var(--bg-card); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; box-shadow: var(--shadow-light); }
        .card-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--border-color); }
        .card-title { font-size: 16px; font-weight: 600; color: var(--text-primary); }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: rgba(201, 169, 212, 0.08); padding: 16px 20px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-secondary); border-bottom: 1px solid var(--border-color); }
        .data-table td { padding: 16px 20px; font-size: 14px; color: var(--text-primary); border-bottom: 1px solid rgba(201, 169, 212, 0.15); }
        .data-table tr:hover { background: rgba(201, 169, 212, 0.08); }
        .avatar-img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; background: var(--bg-input); }
        .content-preview { max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .status-tag { padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; }
        .status-tag.normal { background: rgba(168, 213, 186, 0.2); color: #7ab893; }
        .status-tag.deleted { background: rgba(181, 176, 188, 0.2); color: var(--text-muted); }
        .status-tag.blocked { background: rgba(232, 176, 176, 0.2); color: var(--danger); }

        .action-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .action-btn { padding: 6px 12px; border: none; border-radius: 8px; font-size: 12px; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 4px; }
        .action-btn.delete { background: rgba(232, 176, 176, 0.15); color: var(--danger); }
        .action-btn.delete:hover { background: rgba(232, 176, 176, 0.25); transform: translateY(-1px); }
        .action-btn.block { background: rgba(232, 212, 168, 0.15); color: #c9a96e; }
        .action-btn.block:hover { background: rgba(232, 212, 168, 0.25); transform: translateY(-1px); }
        .action-btn.restore { background: rgba(168, 213, 186, 0.15); color: #7ab893; }
        .action-btn.restore:hover { background: rgba(168, 213, 186, 0.25); transform: translateY(-1px); }
        .action-btn.permanent { background: rgba(232, 176, 176, 0.2); color: #c62828; }
        .action-btn.permanent:hover { background: rgba(232, 176, 176, 0.35); transform: translateY(-1px); }

        /* 分页 */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; padding: 20px; flex-wrap: wrap; }
        .pagination a { padding: 10px 14px; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 10px; text-decoration: none; color: var(--text-secondary); font-size: 14px; transition: all 0.3s ease; }
        .pagination a:hover { border-color: var(--primary); color: var(--primary-dark); transform: translateY(-1px); }
        .pagination a.active { background: rgba(201, 169, 212, 0.2); border-color: var(--primary); color: var(--primary-dark); }
        .pagination span { padding: 10px 14px; color: var(--text-muted); font-size: 14px; }

        .empty { padding: 60px; text-align: center; color: var(--text-muted); }
        .empty i { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }

        /* 内容查看弹窗 */
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 20px; }
        .modal-overlay.show { display: flex; }
        .modal-box { background: #fff; border-radius: 16px; width: 100%; max-width: 500px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 16px; font-weight: 600; color: var(--text-primary); }
        .modal-close { width: 32px; height: 32px; border: none; background: var(--bg-input); border-radius: 50%; cursor: pointer; font-size: 18px; color: var(--text-secondary); display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: rgba(201, 169, 212, 0.2); }
        .modal-body { padding: 24px; max-height: 400px; overflow-y: auto; }
        .modal-body .detail-row { margin-bottom: 16px; }
        .modal-body .detail-label { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }
        .modal-body .detail-value { font-size: 14px; color: var(--text-primary); line-height: 1.6; }

        @media (max-width: 768px) {
            .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: 260px; transform: translateX(-100%); transition: transform 0.3s ease-out; z-index: 1600; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); box-shadow: 4px 0 20px rgba(201, 169, 212, 0.2); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100%; }
            .sidebar-logo .logo-text { display: block; }
            .menu-item a span:not(.icon) { display: block; }
            .data-table { font-size: 12px; }
            .data-table th, .data-table td { padding: 10px 12px; }
            .content-preview { max-width: 120px; }
        }
    </style>
</head>
<body>
    <div class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

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
                <a href="community.php" class="active">
                    <i class="fas fa-comments icon"></i>
                    <span>社区管理</span>
                    <span class="badge"><?php echo $statusCounts['all']; ?></span>
                </a>
            </div>
            <div class="menu-item">
                <a href="comments.php">
                    <i class="fas fa-comment-dots icon"></i>
                    <span>留言管理</span>
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

    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1 class="header-title">社区管理</h1>
                <div class="header-breadcrumb">
                    <a href="index.php">首页</a>
                    <span>/</span>
                    <span>社区管理</span>
                </div>
            </div>
            <div class="header-right">
                <div class="header-action" title="消息通知">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="header-action" title="设置">
                    <i class="fas fa-cog"></i>
                </div>
            </div>
        </div>

        <div class="page-content">
            <?php if (isset($_GET['msg'])): ?>
                <div class="message">
                    <i class="fas fa-check-circle"></i>
                    <span>操作成功</span>
                </div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <!-- 状态统计 -->
            <div class="stats-row">
                <a href="community.php?status=all" class="stat-tab <?php echo $status === 'all' ? 'active' : ''; ?>">
                    <span class="stat-num"><?php echo $statusCounts['all']; ?></span>
                    <span class="stat-label">全部</span>
                </a>
                <a href="community.php?status=1" class="stat-tab <?php echo $status === '1' ? 'active' : ''; ?>">
                    <span class="stat-num"><?php echo $statusCounts[1]; ?></span>
                    <span class="stat-label">正常</span>
                </a>
                <a href="community.php?status=0" class="stat-tab <?php echo $status === '0' ? 'active' : ''; ?>">
                    <span class="stat-num"><?php echo $statusCounts[0]; ?></span>
                    <span class="stat-label">已删除</span>
                </a>
                <a href="community.php?status=2" class="stat-tab <?php echo $status === '2' ? 'active' : ''; ?>">
                    <span class="stat-num"><?php echo $statusCounts[2]; ?></span>
                    <span class="stat-label">已屏蔽</span>
                </a>
            </div>

            <!-- 搜索栏 -->
            <div class="search-bar">
                <form method="GET">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                    <input type="text" name="search" placeholder="搜索用户名或内容" value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">
                        <i class="fas fa-search"></i>
                        <span>搜索</span>
                    </button>
                </form>
            </div>

            <!-- 数据表格 -->
            <div class="data-card">
                <div class="card-header">
                    <h3 class="card-title">社区动态列表</h3>
                    <span style="color: var(--text-muted); font-size: 13px;">共 <?php echo $total; ?> 条记录</span>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>头像</th>
                            <th>用户名</th>
                            <th>内容</th>
                            <th>QQ</th>
                            <th>状态</th>
                            <th>IP</th>
                            <th>发布时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($posts)): ?>
                            <tr>
                                <td colspan="9" class="empty">
                                    <i class="fas fa-comments"></i>
                                    <p>暂无社区动态</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($posts as $post): ?>
                                <tr>
                                    <td><?php echo $post['id']; ?></td>
                                    <td><img src="<?php echo htmlspecialchars($post['avatar']); ?>" class="avatar-img" onerror="this.style.display='none';this.parentElement.innerHTML='<div style=\'width:40px;height:40px;border-radius:50%;background:var(--bg-input);display:flex;align-items:center;justify-content:center;color:var(--text-secondary);font-size:14px;\'>'+this.alt+'</div>';" alt="<?php echo mb_substr($post['author'], 0, 1); ?>"></td>
                                    <td><?php echo htmlspecialchars($post['author']); ?></td>
                                    <td class="content-preview" title="<?php echo htmlspecialchars($post['content']); ?>">
                                        <?php if ($post['emoji']): ?><span style="font-size:16px;"><?php echo $post['emoji']; ?></span><?php endif; ?>
                                        <?php echo htmlspecialchars($post['content']); ?>
                                    </td>
                                    <td><?php echo $post['qq'] ? htmlspecialchars($post['qq']) : '-'; ?></td>
                                    <td>
                                        <span class="status-tag <?php echo $post['status'] == 1 ? 'normal' : ($post['status'] == 0 ? 'deleted' : 'blocked'); ?>">
                                            <?php echo $statusLabels[$post['status']] ?? '未知'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($post['user_ip']); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($post['create_time'])); ?></td>
                                    <td>
                                        <div class="action-group">
                                            <button class="action-btn" onclick="viewDetail(<?php echo htmlspecialchars(json_encode($post, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>)" style="background:rgba(201,169,212,0.15);color:var(--primary-dark);">
                                                <i class="fas fa-eye"></i>详情
                                            </button>
                                            <?php if ($post['status'] == 1): ?>
                                                <form method="POST" onsubmit="return confirm('确定要删除这条动态吗？');" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo $post['id']; ?>">
                                                    <button type="submit" class="action-btn delete"><i class="fas fa-trash"></i>删除</button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('确定要屏蔽这条动态吗？');" style="display:inline;">
                                                    <input type="hidden" name="action" value="block">
                                                    <input type="hidden" name="id" value="<?php echo $post['id']; ?>">
                                                    <button type="submit" class="action-btn block"><i class="fas fa-ban"></i>屏蔽</button>
                                                </form>
                                            <?php elseif ($post['status'] == 0 || $post['status'] == 2): ?>
                                                <form method="POST" onsubmit="return confirm('确定要恢复这条动态吗？');" style="display:inline;">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="id" value="<?php echo $post['id']; ?>">
                                                    <button type="submit" class="action-btn restore"><i class="fas fa-undo"></i>恢复</button>
                                                </form>
                                                <form method="POST" onsubmit="return confirm('彻底删除将无法恢复，确定继续吗？');" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete_permanent">
                                                    <input type="hidden" name="id" value="<?php echo $post['id']; ?>">
                                                    <button type="submit" class="action-btn permanent"><i class="fas fa-times-circle"></i>彻底删除</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="community.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&page=<?php echo $page - 1; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="community.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&page=<?php echo $i; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="community.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&page=<?php echo $page + 1; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                        <span>共 <?php echo $total; ?> 条</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 详情弹窗 -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title">动态详情</div>
                <button class="modal-close" onclick="closeDetail()">×</button>
            </div>
            <div class="modal-body" id="detailBody"></div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }

        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', () => {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebarOverlay').classList.remove('active');
            });
        });

        function viewDetail(data) {
            var statusLabels = {1: '正常', 0: '已删除', 2: '已屏蔽'};
            var html = '<div class="detail-row"><div class="detail-label">发布者</div><div class="detail-value">' + escapeHtml(data.author) + ' (ID: ' + data.user_id + ')</div></div>';
            html += '<div class="detail-row"><div class="detail-label">内容</div><div class="detail-value">' + (data.emoji ? data.emoji + ' ' : '') + escapeHtml(data.content) + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">QQ号</div><div class="detail-value">' + (data.qq || '未填写') + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">状态</div><div class="detail-value">' + (statusLabels[data.status] || '未知') + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">IP地址</div><div class="detail-value">' + escapeHtml(data.user_ip || '未知') + '</div></div>';
            html += '<div class="detail-row"><div class="detail-label">发布时间</div><div class="detail-value">' + data.create_time + '</div></div>';
            if (data.update_time && data.update_time !== data.create_time) {
                html += '<div class="detail-row"><div class="detail-label">更新时间</div><div class="detail-value">' + data.update_time + '</div></div>';
            }
            document.getElementById('detailBody').innerHTML = html;
            document.getElementById('detailModal').classList.add('show');
        }

        function closeDetail() {
            document.getElementById('detailModal').classList.remove('show');
        }

        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) closeDetail();
        });

        function escapeHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // 自动隐藏成功消息
        setTimeout(function() {
            var msg = document.querySelector('.message');
            if (msg) msg.style.display = 'none';
        }, 3000);
    </script>
</body>
</html>
