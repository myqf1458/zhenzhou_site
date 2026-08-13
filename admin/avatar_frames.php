<?php
require_once 'config.php';
checkAdminLogin();
$admin = getCurrentAdmin();

// 建表
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

// 写入默认数据
$fcntRes = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM avatar_frames");
$fcntRow = $fcntRes ? mysqli_fetch_assoc($fcntRes) : ['c' => 0];
if ((int)$fcntRow['c'] === 0) {
    $defaults = [
        ['粉色花环', '2.5px solid #ff69b4', '0 0 8px rgba(255,105,180,0.4)', '#ff69b4', 0, 10],
        ['蓝色光环', '2.5px solid #5b9bd5', '0 0 8px rgba(91,155,213,0.4)', '#5b9bd5', 50, 20],
        ['金色辉光', '2.5px solid #f0c040', '0 0 10px rgba(240,192,64,0.5)', '#f0c040', 100, 30],
        ['紫色梦境', '2.5px solid #9b59b6', '0 0 10px rgba(155,89,182,0.5)', '#9b59b6', 100, 40],
        ['渐变流光', '2.5px solid', '0 0 12px rgba(255,105,180,0.5)', 'linear-gradient(135deg,#ff69b4,#5b9bd5)', 200, 50],
        ['霓虹闪烁', '3px solid #00e5ff', '0 0 12px rgba(0,229,255,0.6), 0 0 4px rgba(0,229,255,0.4)', '#00e5ff', 300, 60],
    ];
    foreach ($defaults as $d) {
        $nm = mysqli_real_escape_string($conn, $d[0]);
        $bc = mysqli_real_escape_string($conn, $d[1]);
        $gc = mysqli_real_escape_string($conn, $d[2]);
        $pc = mysqli_real_escape_string($conn, $d[3]);
        $pr = (int)$d[4];
        $so = (int)$d[5];
        @mysqli_query($conn, "INSERT INTO avatar_frames (name, border_css, glow_css, preview_color, price, sort) VALUES ('$nm', '$bc', '$gc', '$pc', $pr, $so)");
    }
}

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $borderCss = trim($_POST['border_css'] ?? '');
        $glowCss = trim($_POST['glow_css'] ?? '');
        $previewColor = trim($_POST['preview_color'] ?? '#ff69b4');
        $price = (int)($_POST['price'] ?? 0);
        $sort = (int)($_POST['sort'] ?? 0);
        $status = isset($_POST['status']) ? 1 : 0;

        if (empty($name)) {
            $error = '名称不能为空';
        } else {
            $nm = mysqli_real_escape_string($conn, $name);
            $bc = mysqli_real_escape_string($conn, $borderCss);
            $gc = mysqli_real_escape_string($conn, $glowCss);
            $pc = mysqli_real_escape_string($conn, $previewColor);

            if ($action === 'create') {
                $sql = "INSERT INTO avatar_frames (name, border_css, glow_css, preview_color, price, status, sort) VALUES ('$nm', '$bc', '$gc', '$pc', $price, $status, $sort)";
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $sql = "UPDATE avatar_frames SET name='$nm', border_css='$bc', glow_css='$gc', preview_color='$pc', price=$price, status=$status, sort=$sort WHERE id=$id";
            }
            if (mysqli_query($conn, $sql)) {
                header('Location: avatar_frames.php?msg=success');
                exit;
            } else {
                $error = '操作失败: ' . mysqli_error($conn);
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            mysqli_query($conn, "DELETE FROM avatar_frames WHERE id = $id");
            header('Location: avatar_frames.php?msg=deleted');
            exit;
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            mysqli_query($conn, "UPDATE avatar_frames SET status = 1 - status WHERE id = $id");
            header('Location: avatar_frames.php?msg=toggled');
            exit;
        }
    }
}

// 查询列表
$frames = [];
$res = mysqli_query($conn, "SELECT * FROM avatar_frames ORDER BY sort ASC, id ASC");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) $frames[] = $row;
}

// 统计使用人数
$usageMap = [];
$uRes = @mysqli_query($conn, "SELECT avatar_frame, COUNT(*) as cnt FROM user_info WHERE avatar_frame > 0 GROUP BY avatar_frame");
if ($uRes) {
    while ($r = mysqli_fetch_assoc($uRes)) $usageMap[(int)$r['avatar_frame']] = (int)$r['cnt'];
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>头像框管理</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="common.css">
    <style>
        :root {
            --primary: #c9a9d4; --primary-dark: #b892c2; --primary-light: #f3e8f7;
            --secondary: #e8c5d0; --success: #a8d5ba; --warning: #e8d4a8; --danger: #e8b0b0;
            --bg-page: #ffffff; --bg-card: rgba(255,255,255,0.85); --bg-input: rgba(248,244,250,0.8);
            --border-color: rgba(201,169,212,0.3); --text-primary: #4a4556; --text-secondary: #8b8594; --text-muted: #b5b0bc;
            --sidebar-width: 240px; --header-height: 64px;
            --shadow-light: 0 4px 20px rgba(201,169,212,0.1); --shadow-hover: 0 8px 30px rgba(201,169,212,0.15);
        }
        body { font-family: 'Inter', -apple-system, sans-serif; background: var(--bg-page); background-image: radial-gradient(circle at 20% 80%, rgba(232,197,208,0.15) 0%, transparent 50%), radial-gradient(circle at 80% 20%, rgba(201,169,212,0.15) 0%, transparent 50%); color: var(--text-primary); min-height: 100vh; }
        .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: var(--sidebar-width); background: rgba(255,255,255,0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-right: 1px solid var(--border-color); z-index: 1000; display: flex; flex-direction: column; }
        .sidebar-header { height: var(--header-height); display: flex; align-items: center; padding: 0 24px; border-bottom: 1px solid var(--border-color); }
        .sidebar-logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-logo .logo-icon { width: 36px; height: 36px; border-radius: 14px; display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: 0 4px 12px rgba(201,169,212,0.3); border: 2px solid rgba(201,169,212,0.3); }
        .sidebar-logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; }
        .sidebar-logo .logo-text { font-size: 18px; font-weight: 600; }
        .sidebar-menu { flex: 1; padding: 16px 0; overflow-y: auto; }
        .menu-item a { display: flex; align-items: center; gap: 12px; padding: 12px 24px; color: var(--text-secondary); text-decoration: none; font-size: 14px; transition: all 0.3s; border-left: 3px solid transparent; border-radius: 0 12px 12px 0; }
        .menu-item a:hover { background: rgba(201,169,212,0.1); color: var(--text-primary); }
        .menu-item a.active { background: rgba(201,169,212,0.15); color: var(--primary-dark); border-left-color: var(--primary); }
        .menu-item .icon { font-size: 17px; width: 20px; text-align: center; }
        .menu-item .badge { margin-left: auto; padding: 2px 8px; background: rgba(232,176,176,0.2); color: var(--danger); font-size: 12px; border-radius: 10px; }
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }
        .header { height: var(--header-height); background: rgba(255,255,255,0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 24px; position: sticky; top: 0; z-index: 100; }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-title { font-size: 18px; font-weight: 600; }
        .header-breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
        .page-content { padding: 24px; }
        .message { background: rgba(168,213,186,0.15); border: 1px solid rgba(168,213,186,0.3); color: #7ab893; padding: 14px 20px; border-radius: 12px; margin-bottom: 20px; }
        .error { background: rgba(232,176,176,0.15); border: 1px solid rgba(232,176,176,0.3); color: var(--danger); padding: 14px 20px; border-radius: 12px; margin-bottom: 20px; }
        .data-card { background: var(--bg-card); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; box-shadow: var(--shadow-light); }
        .card-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--border-color); }
        .card-title { font-size: 16px; font-weight: 600; }
        .btn-add { padding: 10px 20px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; border: none; border-radius: 12px; font-size: 14px; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 12px rgba(201,169,212,0.3); }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(201,169,212,0.4); }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: rgba(201,169,212,0.08); padding: 16px 20px; text-align: left; font-size: 13px; font-weight: 600; color: var(--text-secondary); border-bottom: 1px solid var(--border-color); }
        .data-table td { padding: 16px 20px; font-size: 14px; border-bottom: 1px solid rgba(201,169,212,0.15); }
        .data-table tr:hover { background: rgba(201,169,212,0.08); }
        .frame-preview { width: 44px; height: 44px; border-radius: 50%; background: #f0f0f0; }
        .status-tag { padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; }
        .status-tag.on { background: rgba(168,213,186,0.2); color: #7ab893; }
        .status-tag.off { background: rgba(181,176,188,0.2); color: var(--text-muted); }
        .action-btn { padding: 6px 12px; border: none; border-radius: 8px; font-size: 12px; cursor: pointer; transition: all 0.3s; margin-right: 4px; }
        .action-btn.edit { background: rgba(201,169,212,0.15); color: var(--primary-dark); }
        .action-btn.edit:hover { background: rgba(201,169,212,0.25); }
        .action-btn.toggle { background: rgba(232,212,168,0.15); color: #c9a96e; }
        .action-btn.toggle:hover { background: rgba(232,212,168,0.25); }
        .action-btn.delete { background: rgba(232,176,176,0.15); color: var(--danger); }
        .action-btn.delete:hover { background: rgba(232,176,176,0.25); }
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 20px; }
        .modal-overlay.show { display: flex; }
        .modal-box { background: #fff; border-radius: 16px; width: 100%; max-width: 500px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .modal-close { width: 32px; height: 32px; border: none; background: var(--bg-input); border-radius: 50%; cursor: pointer; font-size: 18px; color: var(--text-secondary); display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: rgba(201,169,212,0.2); }
        .modal-body { padding: 24px; max-height: 500px; overflow-y: auto; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
        .form-input { width: 100%; padding: 10px 14px; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 10px; font-size: 14px; color: var(--text-primary); outline: none; transition: all 0.3s; box-sizing: border-box; }
        .form-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(201,169,212,0.1); }
        .form-check { display: flex; align-items: center; gap: 8px; }
        .form-check input { width: 18px; height: 18px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px; }
        .btn-save { padding: 10px 24px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: #fff; border: none; border-radius: 10px; font-size: 14px; cursor: pointer; transition: all 0.3s; }
        .btn-save:hover { transform: translateY(-1px); }
        .btn-cancel { padding: 10px 24px; background: var(--bg-input); color: var(--text-secondary); border: 1px solid var(--border-color); border-radius: 10px; font-size: 14px; cursor: pointer; }
        .mobile-menu-btn { display: none; position: fixed; top: 16px; left: 16px; z-index: 1500; width: 40px; height: 40px; background: rgba(255,255,255,0.8); border: 1px solid var(--border-color); border-radius: 12px; align-items: center; justify-content: center; cursor: pointer; }
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.3); z-index: 1200; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; z-index: 1600; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .mobile-menu-btn { display: flex; }
            .data-table { font-size: 12px; }
            .data-table th, .data-table td { padding: 10px 12px; }
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
                    <img src="./logo.png" alt="Logo" onerror="this.style.display='none';this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:16px;color:#c9a9d4;background:linear-gradient(135deg, #f3e8f7, #f9f0f3);\'><i class=\'fas fa-crown\'></i></div>';" />
                </div>
                <span class="logo-text">真昼小破站</span>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item"><a href="index.php"><i class="fas fa-chart-pie icon"></i><span>仪表盘</span></a></div>
            <div class="menu-item"><a href="users.php"><i class="fas fa-users icon"></i><span>用户管理</span></a></div>
            <div class="menu-item"><a href="community.php"><i class="fas fa-comments icon"></i><span>社区管理</span></a></div>
            <div class="menu-item"><a href="avatar_frames.php" class="active"><i class="fas fa-circle-notch icon"></i><span>头像框管理</span></a></div>
            <div class="menu-item"><a href="comments.php"><i class="fas fa-comment-dots icon"></i><span>留言管理</span></a></div>
            <div class="menu-item"><a href="bangumi.php"><i class="fas fa-tv icon"></i><span>追番管理</span></a></div>
            <div class="menu-item"><a href="reports.php"><i class="fas fa-flag icon"></i><span>举报处理</span></a></div>
            <?php if (isSuperAdmin()): ?>
            <div class="menu-item"><a href="admins.php"><i class="fas fa-shield-alt icon"></i><span>管理员管理</span></a></div>
            <div class="menu-item"><a href="security.php"><i class="fas fa-user-shield icon"></i><span>安全中心</span></a></div>
            <div class="menu-item"><a href="qiniu.php"><i class="fas fa-cloud icon"></i><span>七牛存储</span></a></div>
            <?php endif; ?>
            <div class="menu-item"><a href="logout.php"><i class="fas fa-sign-out-alt icon"></i><span>退出登录</span></a></div>
        </div>
    </div>
    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1 class="header-title">头像框管理</h1>
                <div class="header-breadcrumb"><a href="index.php">首页</a><span>/</span><span>头像框管理</span></div>
            </div>
        </div>
        <div class="page-content">
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'success'): ?>
                <div class="message"><i class="fas fa-check-circle"></i> 操作成功</div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
                <div class="message"><i class="fas fa-check-circle"></i> 已删除</div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'toggled'): ?>
                <div class="message"><i class="fas fa-check-circle"></i> 状态已切换</div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="data-card">
                <div class="card-header">
                    <div class="card-title">头像框列表（<?php echo count($frames); ?> 个）</div>
                    <button class="btn-add" onclick="openModal()"><i class="fas fa-plus"></i> 新增头像框</button>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>预览</th>
                            <th>名称</th>
                            <th>边框CSS</th>
                            <th>光效CSS</th>
                            <th>价格</th>
                            <th>使用人数</th>
                            <th>排序</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($frames as $f): ?>
                        <tr>
                            <td><?php echo (int)$f['id']; ?></td>
                            <td>
                                <?php
                                $pv = $f['preview_color'];
                                $style = strpos($pv, 'gradient') !== false ? "border: 2.5px solid; border-image: $pv 1;" : "border: 2.5px solid $pv;";
                                ?>
                                <div class="frame-preview" style="<?php echo htmlspecialchars($style); ?>"></div>
                            </td>
                            <td><?php echo htmlspecialchars($f['name']); ?></td>
                            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($f['border_css']); ?></td>
                            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($f['glow_css']); ?></td>
                            <td><?php echo (int)$f['price']; ?> 积分</td>
                            <td><?php echo $usageMap[(int)$f['id']] ?? 0; ?> 人</td>
                            <td><?php echo (int)$f['sort']; ?></td>
                            <td><span class="status-tag <?php echo $f['status'] ? 'on' : 'off'; ?>"><?php echo $f['status'] ? '上架' : '下架'; ?></span></td>
                            <td>
                                <button class="action-btn edit" onclick='openModal(<?php echo json_encode($f, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'>编辑</button>
                                <form method="post" style="display:inline" onsubmit="return confirm('确定切换状态？')">
                                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>">
                                    <button class="action-btn toggle" type="submit"><?php echo $f['status'] ? '下架' : '上架'; ?></button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('确定删除？')">
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>">
                                    <button class="action-btn delete" type="submit">删除</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($frames)): ?>
                        <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">暂无头像框</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 新增/编辑弹窗 -->
    <div class="modal-overlay" id="frameModal">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-title" id="modalTitle">新增头像框</div>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="post" id="frameForm">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="formId" value="0">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">名称</label>
                        <input type="text" name="name" id="formName" class="form-input" required maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label">边框 CSS（如 2.5px solid #ff69b4）</label>
                        <input type="text" name="border_css" id="formBorder" class="form-input" placeholder="2.5px solid #ff69b4">
                    </div>
                    <div class="form-group">
                        <label class="form-label">光效 CSS（如 0 0 8px rgba(255,105,180,0.4)）</label>
                        <input type="text" name="glow_css" id="formGlow" class="form-input" placeholder="0 0 8px rgba(255,105,180,0.4)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">预览颜色（#hex 或 linear-gradient(...)）</label>
                        <input type="text" name="preview_color" id="formPreview" class="form-input" placeholder="#ff69b4" value="#ff69b4">
                    </div>
                    <div class="form-group">
                        <label class="form-label">价格（积分，0=免费）</label>
                        <input type="number" name="price" id="formPrice" class="form-input" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">排序（数字越小越靠前）</label>
                        <input type="number" name="sort" id="formSort" class="form-input" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" name="status" id="formStatus" checked>
                            <label for="formStatus" style="font-size:14px;cursor:pointer;">上架</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn-save">保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').style.display = document.getElementById('sidebar').classList.contains('open') ? 'block' : 'none';
        }
        function openModal(data) {
            var modal = document.getElementById('frameModal');
            if (data) {
                document.getElementById('modalTitle').textContent = '编辑头像框';
                document.getElementById('formAction').value = 'update';
                document.getElementById('formId').value = data.id;
                document.getElementById('formName').value = data.name;
                document.getElementById('formBorder').value = data.border_css;
                document.getElementById('formGlow').value = data.glow_css;
                document.getElementById('formPreview').value = data.preview_color;
                document.getElementById('formPrice').value = data.price;
                document.getElementById('formSort').value = data.sort;
                document.getElementById('formStatus').checked = data.status == 1;
            } else {
                document.getElementById('modalTitle').textContent = '新增头像框';
                document.getElementById('formAction').value = 'create';
                document.getElementById('formId').value = 0;
                document.getElementById('frameForm').reset();
                document.getElementById('formPreview').value = '#ff69b4';
                document.getElementById('formStatus').checked = true;
            }
            modal.classList.add('show');
        }
        function closeModal() {
            document.getElementById('frameModal').classList.remove('show');
        }
    </script>
</body>
</html>
