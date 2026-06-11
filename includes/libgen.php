<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

function searchLibgen(string $bookName, string $extension = 'mobi'): array {
    $url = LIBGEN_BASE . '/index.php?req=' . urlencode($bookName)
         . '+ext:' . urlencode($extension)
         . '+lang:eng&gmode=on&res=100';

    $ctx = stream_context_create([
        'http' => ['timeout' => 15, 'header' => "User-Agent: Mozilla/5.0\r\n"]
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) return [];

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $secondTable = $xpath->query('(//table)[2]')->item(0);
    if (!$secondTable) return [];

    $rows = $xpath->query('.//tr', $secondTable);
    $data = [];

    foreach ($rows as $row) {
        $cols = $row->getElementsByTagName('td');
        if ($cols->length < 9) continue;

        $rawTitle = $cols->item(0)->ownerDocument->saveHTML($cols->item(0));
        $title = cleanTitle($rawTitle);
        $author = trim($cols->item(1)->nodeValue);
        $size = trim($cols->item(6)->nodeValue);
        $format = trim($cols->item(7)->nodeValue);

        $mirrorHtml = $cols->item(8)->ownerDocument->saveHTML($cols->item(8));
        $mirrorUrl = extractMirrorUrl($mirrorHtml);

        if (empty($title) || empty($mirrorUrl)) continue;

        $data[] = [
            'title' => $title,
            'author' => $author,
            'size' => $size,
            'size_bytes' => parseSize($size),
            'format' => $format,
            'mirror_url' => $mirrorUrl,
        ];
    }

    return $data;
}

function cleanTitle(string $html): string {
    $text = strip_tags($html);
    $text = preg_replace('/\b\w*[\d]{5,}\w*\b/', '', $text);
    $text = preg_replace('/\b[a-zA-Z]\b(?!\d)/', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = preg_replace('/[^\w\s\d\[\]\'"().,:\-]/u', '', $text);
    return trim($text);
}

function extractMirrorUrl(string $html): string {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $links = $dom->getElementsByTagName('a');

    if ($links->length > 0) {
        $href = $links->item(0)->getAttribute('href');
        if ($href && !preg_match('/^https?:/', $href)) {
            $href = LIBGEN_BASE . '/' . ltrim($href, '/');
        }
        return $href;
    }
    return '';
}
