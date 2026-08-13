<?php
/**
 * 友链提交接收 - /submit_link.php
 * 支持 POST（表单 / JSON），返回 JSON {code,msg,check_status}
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/links_lib.php';

// 允许跨站：同源 JSON fetch 即可，此处不打开 CORS
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 接受 application/x-www-form-urlencoded 或 application/json
$data = $_POST;
$ctype = strtolower($_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) $data = $json + $_POST;
}

$result = links_submit($data);
http_response_code($result['code'] >= 400 && $result['code'] < 600 ? $result['code'] : 200);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
