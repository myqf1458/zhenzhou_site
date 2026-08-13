<?php
/**
 * 友链列表接口 - /links_api.php
 *   ?action=list  => 已通过的友链 JSON
 *   ?action=check&id=N  => 手动重新检测反向链接（仅提示，不写入）
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/links_lib.php';

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $list = links_list_approved();
    echo json_encode([
        'code' => 200,
        'msg'  => 'ok',
        'total' => count($list),
        'data' => $list,
        'self' => [
            'name' => SITE_NAME,
            'url'  => SITE_URL,
            'desc' => '个人向二次元小型站点。',
            'logo' => SITE_URL ? rtrim(SITE_URL, '/') . '/img/favicon.png' : 'img/favicon.png',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'check') {
    $url = trim($_GET['url'] ?? '');
    if ($url === '') {
        echo json_encode(['code' => 400, 'msg' => '缺少 url 参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 同域名 10 秒最多一次（避免被滥用成抓取代理）
    session_start();
    $key = '_links_check_ts';
    $last = (int)($_SESSION[$key] ?? 0);
    if (time() - $last < 3) {
        echo json_encode(['code' => 429, 'msg' => '检测过于频繁，请稍后再试'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION[$key] = time();
    $r = links_check_backlink($url);
    echo json_encode([
        'code' => $r['ok'] ? 200 : 200, // 检测服务本身仍成功
        'detected' => $r['ok'],
        'msg'  => $r['msg'],
        'match' => $r['match'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['code' => 400, 'msg' => '未知 action'], JSON_UNESCAPED_UNICODE);
