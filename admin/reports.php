<?php
require_once 'config.php';
checkAdminLogin();

$admin = getCurrentAdmin();
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// 处理举报（标记为已处理或驳回）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_report') {
    $reportId = intval($_POST['id']);
    $processStatus = $_POST['process_status'] ?? 'processed';
    if ($reportId > 0 && in_array($processStatus, ['processed', 'rejected'])) {
        $sql = "UPDATE reports SET status = '{$processStatus}', processed_by = {$admin['id']}, processed_time = NOW() WHERE id = {$reportId}";
        if (mysqli_query($conn, $sql)) {
            header('Location: reports.php?msg=process_success');
            exit;
        } else {
            $error = '处理失败: ' . mysqli_error($conn);
        }
    }
}

// 删除被举报的留言并标记举报为已处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_comment') {
    $reportId = intval($_POST['id']);
    $commentId = intval($_POST['comment_id']);
    if ($reportId > 0 && $commentId > 0) {
        // 先删除留言
        mysqli_query($conn, "DELETE FROM comments WHERE id = {$commentId}");
        // 再标记举报为已处理
        $sql = "UPDATE reports SET status = 'processed', processed_by = {$admin['id']}, processed_time = NOW() WHERE id = {$reportId}";
        if (mysqli_query($conn, $sql)) {
            header('Location: reports.php?msg=delete_success');
            exit;
        } else {
            $error = '操作失败: ' . mysqli_error($conn);
        }
    }
}

// 查询举报总数
$countSql = "SELECT COUNT(*) as total FROM reports r LEFT JOIN comments c ON r.comment_id = c.id WHERE 1=1";
if ($search) {
    $countSql .= " AND (r.reporter_name LIKE '%{$search}%' OR c.nickname LIKE '%{$search}%' OR c.content LIKE '%{$search}%' OR r.reason LIKE '%{$search}%')";
}
if ($status !== 'all') {
    $countSql .= " AND r.status = '{$status}'";
}
$result = mysqli_query($conn, $countSql);
$total = 0;
if ($result && $row = mysqli_fetch_assoc($result)) {
    $total = $row['total'];
}
$totalPages = ceil($total / $limit);

// 查询举报列表
$sql = "SELECT r.*, c.nickname as comment_nickname, c.content as comment_content, c.user_id as comment_user_id FROM reports r LEFT JOIN comments c ON r.comment_id = c.id WHERE 1=1";
if ($search) {
    $sql .= " AND (r.reporter_name LIKE '%{$search}%' OR c.nickname LIKE '%{$search}%' OR c.content LIKE '%{$search}%' OR r.reason LIKE '%{$search}%')";
}
if ($status !== 'all') {
    $sql .= " AND r.status = '{$status}'";
}
$sql .= " ORDER BY r.id DESC LIMIT {$offset}, {$limit}";
$result = mysqli_query($conn, $sql);
$reports = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $reports[] = $row;
    }
}

// 查询待处理举报数量
$pendingCount = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM reports WHERE status = 'pending'");
if ($result && $row = mysqli_fetch_assoc($result)) {
    $pendingCount = $row['total'];
}

mysqli_close($conn);

$statusNames = [
    'pending' => '待处理',
    'processed' => '已处理',
    'rejected' => '已驳回'
];

$statusColors = [
    'pending' => '#e8d4a8',
    'processed' => '#a8d5ba',
    'rejected' => '#e8b0b0'
];
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>举报处理</title>
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

        /* 消息提示 */
        .message {
            background: rgba(168, 213, 186, 0.15);
            border: 1px solid rgba(168, 213, 186, 0.3);
            color: #7ab893;
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error {
            background: rgba(232, 176, 176, 0.15);
            border: 1px solid rgba(232, 176, 176, 0.3);
            color: var(--danger);
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* 搜索栏 */
        .search-bar {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            box-shadow: var(--shadow-light);
            flex-wrap: wrap;
        }

        .search-bar input {
            flex: 1;
            min-width: 200px;
            padding: 12px 16px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
        }

        .search-bar input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(201, 169, 212, 0.1);
        }

        .search-bar input::placeholder {
            color: var(--text-muted);
        }

        .search-bar select {
            padding: 12px 16px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
            cursor: pointer;
        }

        .search-bar button {
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3);
        }

        .search-bar button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(201, 169, 212, 0.4);
        }

        /* 数据表格 */
        .data-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-light);
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
        }

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
        }

        .data-table td {
            padding: 16px 20px;
            font-size: 14px;
            color: var(--text-primary);
            border-bottom: 1px solid rgba(201, 169, 212, 0.15);
        }

        .data-table tr:hover {
            background: rgba(201, 169, 212, 0.08);
        }

        .content-preview {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-tag {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending {
            background: rgba(232, 212, 168, 0.15);
            color: #d97706;
        }

        .status-processed {
            background: rgba(168, 213, 186, 0.15);
            color: #7ab893;
        }

        .status-rejected {
            background: rgba(232, 176, 176, 0.15);
            color: var(--danger);
        }

        .action-btn {
            padding: 8px 14px;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-right: 8px;
        }

        .action-btn.process {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .action-btn.process:hover {
            background: rgba(59, 130, 246, 0.25);
            transform: translateY(-1px);
        }

        .action-btn.reject {
            background: rgba(232, 176, 176, 0.15);
            color: var(--danger);
        }

        .action-btn.reject:hover {
            background: rgba(232, 176, 176, 0.25);
            transform: translateY(-1px);
        }

        .action-btn.delete {
            background: rgba(232, 197, 208, 0.15);
            color: #d6486b;
        }

        .action-btn.delete:hover {
            background: rgba(232, 197, 208, 0.25);
            transform: translateY(-1px);
        }

        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* 分页 */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            padding: 20px;
        }

        .pagination a {
            padding: 10px 14px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-secondary);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            border-color: var(--primary);
            color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .pagination a.active {
            background: rgba(201, 169, 212, 0.2);
            border-color: var(--primary);
            color: var(--primary-dark);
        }

        .pagination span {
            padding: 10px 14px;
            color: var(--text-muted);
            font-size: 14px;
        }

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

        /* 响应式 */
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

            .action-buttons {
                flex-direction: column;
                align-items: flex-start;
            }

            .action-btn {
                margin-right: 0;
                margin-bottom: 4px;
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
                <a href="reports.php" class="active">
                    <i class="fas fa-flag icon"></i>
                    <span>举报处理</span>
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge"><?php echo $pendingCount; ?></span>
                    <?php endif; ?>
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
                <h1 class="header-title">举报处理</h1>
                <div class="header-breadcrumb">
                    <a href="index.php">首页</a>
                    <span>/</span>
                    <span>举报处理</span>
                </div>
            </div>
            
            <div class="header-right">
                <div class="header-action" title="消息通知">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="header-action" title="搜索">
                    <i class="fas fa-search"></i>
                </div>
                <div class="header-action" title="设置">
                    <i class="fas fa-cog"></i>
                </div>
            </div>
        </div>

        <!-- 页面内容 -->
        <div class="page-content">
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'process_success'): ?>
                <div class="message">
                    <i class="fas fa-check-circle"></i>
                    <span>处理成功</span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'delete_success'): ?>
                <div class="message">
                    <i class="fas fa-check-circle"></i>
                    <span>删除成功</span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <!-- 搜索栏 -->
            <div class="search-bar">
                <form method="GET">
                    <input type="text" name="search" placeholder="搜索举报者、用户名或内容" value="<?php echo htmlspecialchars($search); ?>">
                    <select name="status">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>全部状态</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>待处理</option>
                        <option value="processed" <?php echo $status === 'processed' ? 'selected' : ''; ?>>已处理</option>
                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>已驳回</option>
                    </select>
                    <button type="submit">
                        <i class="fas fa-search"></i>
                        <span>搜索</span>
                    </button>
                </form>
            </div>

            <!-- 数据表格 -->
            <div class="data-card">
                <div class="card-header">
                    <h3 class="card-title">举报列表</h3>
                    <span style="color: var(--text-muted); font-size: 13px;">共 <?php echo $total; ?> 条记录</span>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>举报者</th>
                            <th>被举报用户</th>
                            <th>举报原因</th>
                            <th>被举报内容</th>
                            <th>状态</th>
                            <th>举报时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reports)): ?>
                            <tr>
                                <td colspan="8" class="empty">
                                    <i class="fas fa-flag"></i>
                                    <p>暂无举报记录</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td data-label="ID"><?php echo $report['id']; ?></td>
                                    <td data-label="举报者"><?php echo htmlspecialchars($report['reporter_name']); ?></td>
                                    <td data-label="被举报用户"><?php echo htmlspecialchars($report['comment_nickname'] ?: '已删除'); ?></td>
                                    <td data-label="举报原因"><?php echo htmlspecialchars($report['reason']); ?></td>
                                    <td data-label="被举报内容" class="content-preview" title="<?php echo htmlspecialchars($report['comment_content'] ?: '内容已删除'); ?>">
                                        <?php echo htmlspecialchars($report['comment_content'] ?: '内容已删除'); ?>
                                    </td>
                                    <td data-label="状态">
                                        <span class="status-tag status-<?php echo $report['status']; ?>">
                                            <?php echo $statusNames[$report['status']]; ?>
                                        </span>
                                    </td>
                                    <td data-label="举报时间"><?php echo date('Y-m-d H:i:s', strtotime($report['create_time'])); ?></td>
                                    <td data-label="操作">
                                        <div class="action-buttons">
                                            <?php if ($report['status'] === 'pending'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="process_report">
                                                    <input type="hidden" name="id" value="<?php echo $report['id']; ?>">
                                                    <input type="hidden" name="process_status" value="processed">
                                                    <button type="submit" class="action-btn process">
                                                        <i class="fas fa-check"></i>
                                                        <span>已处理</span>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="process_report">
                                                    <input type="hidden" name="id" value="<?php echo $report['id']; ?>">
                                                    <input type="hidden" name="process_status" value="rejected">
                                                    <button type="submit" class="action-btn reject">
                                                        <i class="fas fa-times"></i>
                                                        <span>驳回</span>
                                                    </button>
                                                </form>
                                                <?php if (!empty($report['comment_id'])): ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('确定要删除这条被举报的留言吗？删除后将无法恢复！');">
                                                        <input type="hidden" name="action" value="delete_comment">
                                                        <input type="hidden" name="id" value="<?php echo $report['id']; ?>">
                                                        <input type="hidden" name="comment_id" value="<?php echo $report['comment_id']; ?>">
                                                        <button type="submit" class="action-btn delete">
                                                            <i class="fas fa-trash"></i>
                                                            <span>删除留言</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-size: 13px;">已处理</span>
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
                            <a href="reports.php?search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&page=<?php echo $page - 1; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php if ($i == $page): ?>
                                <a href="reports.php?search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&page=<?php echo $i; ?>" class="active"><?php echo $i; ?></a>
                            <?php else: ?>
                                <a href="reports.php?search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="reports.php?search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&page=<?php echo $page + 1; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                        
                        <span>共 <?php echo $total; ?> 条</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // 移动端侧边栏切换
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }

        // 点击侧边栏链接时关闭侧边栏（移动端）
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', () => {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            });
        });
    </script>
</body>
</html>