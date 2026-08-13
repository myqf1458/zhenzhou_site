<?php
// 后台配置文件
session_start();

// 数据库连接（先加载以便安全模块复用 $conn）
require_once '../db.php';

// 加载安全防护核心
require_once __DIR__ . '/../admin_security.php';
SecGuard::init($conn);

// 检查是否登录
function checkAdminLogin() {
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
        header('Location: login.php');
        exit;
    }
}

// 获取当前管理员信息
function getCurrentAdmin() {
    return [
        'id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'],
        'nickname' => $_SESSION['admin_nickname'],
        'role' => $_SESSION['admin_role'] ?? 'admin'
    ];
}

// 检查是否是超级管理员
function isSuperAdmin() {
    return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin';
}

// 检查是否是管理员（包括超级管理员）
function isAdmin() {
    return isset($_SESSION['admin_role']) && ($_SESSION['admin_role'] === 'super_admin' || $_SESSION['admin_role'] === 'admin');
}

// 权限检查函数
function checkPermission($permission) {
    $role = $_SESSION['admin_role'] ?? 'admin';

    // 超级管理员拥有所有权限
    if ($role === 'super_admin') {
        return true;
    }

    // 管理员权限配置
    $adminPermissions = [
        'view_dashboard',
        'view_users',
        'edit_users',
        'delete_users',
        'view_community',
        'delete_community',
        'block_community',
        'restore_community',
        'view_comments',
        'delete_comments',
        'view_reports',
        'process_reports',
        'view_bangumi',
        'edit_bangumi'
    ];

    return in_array($permission, $adminPermissions);
}

// 退出登录
function logout() {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    header('Location: login.php');
    exit;
}
?>
