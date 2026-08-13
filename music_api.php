<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * 从 cover/ 目录查找本地封面图片
 * 按音频文件名匹配，支持 jpg/jpeg/png/webp/gif
 * @param string $musicFileName 音频文件名（不含扩展名）
 * @return string|null 找到则返回相对路径，未找到返回 null
 */
function findLocalCover($musicFileName) {
    $coverDir = __DIR__ . '/cover';
    $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    if (!is_dir($coverDir)) return null;

    // 1. 精确匹配：与音频同名的图片文件
    foreach ($imageExts as $ext) {
        $path = $coverDir . '/' . $musicFileName . '.' . $ext;
        if (file_exists($path)) {
            return 'cover/' . $musicFileName . '.' . $ext;
        }
    }

    // 2. 模糊匹配：文件名包含的图片
    $files = scandir($coverDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $fileExt = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($fileExt, $imageExts)) {
            $fileBase = pathinfo($file, PATHINFO_FILENAME);
            // 音频文件名包含图片文件名，或图片文件名包含音频文件名
            if (stripos($musicFileName, $fileBase) !== false || stripos($fileBase, $musicFileName) !== false) {
                return 'cover/' . $file;
            }
        }
    }

    return null;
}

// ============================================
// 音乐播放列表配置
// 音频文件放在 /music/ 目录下
// 封面图片放在 /cover/ 目录下（与音频同名，如 sunset.mp3 -> sunset.jpg）
// 未找到本地封面时使用默认占位图
// ============================================
$defaultCover = 'data:image/svg+xml,' . urlencode('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" rx="32" fill="#c9a9d4"/><text x="32" y="42" font-size="28" text-anchor="middle" fill="#fff">♪</text></svg>');

// 预设播放列表
$playlist = [
    [
        'title'  => '小さな恋のうた',
        'artist' => '石見舞菜香',
        'src'    => 'music/小さな恋のうた-石見舞菜香.mp3',
        'duration' => 0
    ],
    [
        'title'  => '愛唄',
        'artist' => '石見舞菜香',
        'src'    => 'music/石見舞菜香 - 愛唄.mp3',
        'duration' => 0
    ],
    [
        'title'  => '君に届け',
        'artist' => '石見舞菜香',
        'src'    => 'music/石見舞菜香 - 君に届け.mp3',
        'duration' => 0
    ],
    [
        'title'  => 'バレンタイン・キッス',
        'artist' => '石見舞菜香',
        'src'    => 'music/石見舞菜香 - バレンタイン・キッス.mp3',
        'duration' => 0
    ],
];

// 为预设列表匹配本地封面
foreach ($playlist as &$item) {
    $musicBaseName = pathinfo($item['src'], PATHINFO_FILENAME);
    $localCover = findLocalCover($musicBaseName);
    $item['cover'] = $localCover ?: $defaultCover;
}
unset($item);

// 扫描 music 目录下的音频文件（自动加载未配置的文件）
$musicDir = __DIR__ . '/music';
if (is_dir($musicDir)) {
    $files = scandir($musicDir);
    $audioExts = ['mp3', 'ogg', 'wav', 'm4a', 'aac', 'flac'];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $audioExts)) {
            // 检查是否已在列表中
            $alreadyExists = false;
            foreach ($playlist as $item) {
                if (strpos($item['src'], $file) !== false) {
                    $alreadyExists = true;
                    break;
                }
            }
            if (!$alreadyExists) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $localCover = findLocalCover($name);
                $playlist[] = [
                    'title'  => $name,
                    'artist' => '未知艺术家',
                    'cover'  => $localCover ?: $defaultCover,
                    'src'    => 'music/' . $file,
                    'duration' => 0
                ];
            }
        }
    }
}

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    echo json_encode([
        'code' => 200,
        'msg'  => 'success',
        'data' => $playlist
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'code' => 400,
        'msg'  => '未知操作',
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
}
