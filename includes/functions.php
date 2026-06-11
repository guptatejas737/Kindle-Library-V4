<?php
require_once __DIR__ . '/config.php';

function sanitizeFileName(string $name): string {
    $name = preg_replace('/[^a-zA-Z0-9\-_ .]/', '_', $name);
    $name = preg_replace('/\s+/', '_', trim($name));
    $name = preg_replace('/_+/', '_', $name);
    return $name;
}

function formatFileSize(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return number_format($bytes / 1024, 0) . ' KB';
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function currentUrl(array $override = []): string {
    $params = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $script = basename($_SERVER['PHP_SELF']);
    return $script . ($params ? '?' . http_build_query($params) : '');
}

function ratingDisplay(int $rating): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= ($i <= $rating) ? '&#9679;' : '&#9675;';
    }
    return $out;
}

function ratingLinks(int $bookId, int $currentRating): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $symbol = ($i <= $currentRating) ? '&#9679;' : '&#9675;';
        $out .= '<a href="book.php?id=' . $bookId . '&rate=' . $i . '" style="text-decoration:none;font-size:20px;padding:2px 4px;">' . $symbol . '</a>';
    }
    if ($currentRating > 0) {
        $out .= ' <a href="book.php?id=' . $bookId . '&rate=0" style="font-size:12px;">[clear]</a>';
    }
    return $out;
}

function statusBadge(string $status): string {
    $labels = ['unread' => 'Unread', 'reading' => 'Reading', 'read' => 'Read'];
    $label = $labels[$status] ?? ucfirst($status);
    $style = 'display:inline-block;padding:2px 8px;font-size:12px;border:1px solid #333;';
    if ($status === 'read') $style .= 'background:#ccc;';
    if ($status === 'reading') $style .= 'background:#ddd;';
    return '<span style="' . $style . '">' . $label . '</span>';
}

function truncate(string $str, int $len = 40): string {
    if (mb_strlen($str) <= $len) return $str;
    return mb_substr($str, 0, $len - 3) . '...';
}

function coverUrl(array $book): string {
    if (!empty($book['cover_path']) && file_exists(APP_ROOT . '/' . $book['cover_path'])) {
        return $book['cover_path'];
    }
    return 'covers/placeholder.php?t=' . urlencode($book['title']) . '&a=' . urlencode($book['author'] ?? '');
}

function buildPagination(int $currentPage, int $totalPages): string {
    if ($totalPages <= 1) return '';
    $out = '<table width="100%"><tr><td align="center" style="padding:10px 0;">';

    if ($currentPage > 1) {
        $out .= '<a href="' . h(currentUrl(['page' => $currentPage - 1])) . '" style="padding:8px 16px;border:1px solid #333;margin:0 4px;text-decoration:none;">&laquo; Prev</a> ';
    }

    $out .= ' Page ' . $currentPage . ' of ' . $totalPages . ' ';

    if ($currentPage < $totalPages) {
        $out .= ' <a href="' . h(currentUrl(['page' => $currentPage + 1])) . '" style="padding:8px 16px;border:1px solid #333;margin:0 4px;text-decoration:none;">Next &raquo;</a>';
    }

    $out .= '</td></tr></table>';
    return $out;
}

function flashMessage(string $msg): void {
    echo '<table width="100%"><tr><td style="padding:8px;background:#eee;border:1px solid #999;margin-bottom:8px;">' . h($msg) . '</td></tr></table>';
}

function parseSize(string $sizeStr): int {
    $sizeStr = strtolower(trim($sizeStr));
    if (preg_match('/([\d.]+)\s*(mb|kb|gb|b)/i', $sizeStr, $m)) {
        $val = (float)$m[1];
        $unit = $m[2];
        switch ($unit) {
            case 'gb': return (int)($val * 1073741824);
            case 'mb': return (int)($val * 1048576);
            case 'kb': return (int)($val * 1024);
            default: return (int)$val;
        }
    }
    return 0;
}
