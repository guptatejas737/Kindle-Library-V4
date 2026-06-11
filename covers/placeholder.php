<?php
/**
 * Dynamic placeholder cover generator.
 * Used as <img src="covers/placeholder.php?t=Title&a=Author">
 * for books that don't have a cached cover yet.
 */
$title = $_GET['t'] ?? 'Unknown';
$author = $_GET['a'] ?? '';

$w = 120;
$h = 180;

if (!function_exists('imagecreatetruecolor')) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

$img = imagecreatetruecolor($w, $h);
$hash = crc32($title . $author);
$shade = 60 + abs($hash % 120);
$bg = imagecolorallocate($img, $shade, $shade, $shade);
$textColor = ($shade > 120) ? imagecolorallocate($img, 0, 0, 0) : imagecolorallocate($img, 255, 255, 255);
$borderColor = imagecolorallocate($img, 40, 40, 40);

imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $bg);
imagerectangle($img, 0, 0, $w - 1, $h - 1, $borderColor);
imagerectangle($img, 2, 2, $w - 3, $h - 3, $borderColor);

$wrapped = wordwrap($title, 14, "\n", true);
$lines = explode("\n", $wrapped);
$y = 30;
foreach (array_slice($lines, 0, 7) as $line) {
    $lineW = imagefontwidth(3) * strlen($line);
    $x = max(4, ($w - $lineW) / 2);
    imagestring($img, 3, (int)$x, $y, $line, $textColor);
    $y += 16;
}

if ($author) {
    $authorLines = explode("\n", wordwrap($author, 18, "\n", true));
    $ay = $h - 20 - (count(array_slice($authorLines, 0, 2)) * 12);
    foreach (array_slice($authorLines, 0, 2) as $aline) {
        $alineW = imagefontwidth(2) * strlen($aline);
        $ax = max(4, ($w - $alineW) / 2);
        imagestring($img, 2, (int)$ax, $ay, $aline, $textColor);
        $ay += 12;
    }
}

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
imagejpeg($img, null, 85);
imagedestroy($img);
