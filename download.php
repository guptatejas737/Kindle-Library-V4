<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/covers.php';

$mode = $_GET['mode'] ?? 'save';     // "save" or "direct"
$mirrorUrl = $_GET['url'] ?? '';
$title = $_GET['title'] ?? 'unknown';
$format = strtolower($_GET['format'] ?? 'mobi');

if (empty($mirrorUrl)) {
    header('Location: search.php?msg=' . urlencode('No URL provided.'));
    exit;
}

// Step 1: Fetch the mirror page to find the actual download link
$actualDownloadUrl = resolveDownloadUrl($mirrorUrl);
if (!$actualDownloadUrl) {
    header('Location: search.php?msg=' . urlencode('Could not resolve download link. Try another edition.'));
    exit;
}

// Mode: Direct download - redirect the Kindle browser to the file URL
if ($mode === 'direct') {
    directDownloadToDevice($actualDownloadUrl, $title, $format);
    exit;
}

// Mode: Save to library - download to server, extract metadata, add to DB
saveToLibrary($actualDownloadUrl, $title, $format);

function resolveDownloadUrl(string $mirrorUrl): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $mirrorUrl,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $httpCode >= 400) return null;

    // Look for the GET download link on the mirror page
    if (preg_match('/<a\s+href="([^"]*)"\s*>(.*?)GET(.*?)<\/a>/i', $html, $matches)) {
        $href = $matches[1];
        // Handle relative URLs
        if (!preg_match('/^https?:\/\//', $href)) {
            $parsed = parse_url($mirrorUrl);
            $base = $parsed['scheme'] . '://' . $parsed['host'];
            $href = $base . '/' . ltrim($href, '/');
        }
        return $href;
    }

    return null;
}

function directDownloadToDevice(string $downloadUrl, string $title, string $format): void {
    // Resolve the final URL (may have redirects) and proxy the download
    // through PHP so Kindle gets proper Content-Disposition headers
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $downloadUrl,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);

    $responseHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    });

    $data = curl_exec($ch);
    curl_close($ch);

    if (!$data) {
        header('Location: search.php?msg=' . urlencode('Download failed.'));
        exit;
    }

    $filename = extractFilenameFromHeaders($responseHeaders, $title, $format);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-cache');
    echo $data;
}

function saveToLibrary(string $downloadUrl, string $title, string $format): void {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $downloadUrl,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);

    $responseHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    });

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$data || $httpCode >= 400) {
        header('Location: search.php?msg=' . urlencode('Download failed (HTTP ' . $httpCode . '). Try another edition.'));
        exit;
    }

    // Determine filename: prefer Content-Disposition, fall back to sanitized title
    $filename = extractFilenameFromHeaders($responseHeaders, $title, $format);

    // Avoid overwriting existing files
    $targetDir = UNREAD_DIR . '/';
    $finalPath = $targetDir . $filename;
    if (file_exists($finalPath)) {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $counter = 1;
        while (file_exists($targetDir . $base . '_' . $counter . '.' . $ext)) {
            $counter++;
        }
        $filename = $base . '_' . $counter . '.' . $ext;
        $finalPath = $targetDir . $filename;
    }

    file_put_contents($finalPath, $data);

    // Add to database (addBook handles metadata extraction + cover generation)
    $bookId = addBook($filename, 'unread');

    header('Location: index.php?msg=' . urlencode('Saved: ' . $filename));
    exit;
}

function extractFilenameFromHeaders(array $headers, string $fallbackTitle, string $fallbackFormat): string {
    // Try Content-Disposition header first
    if (!empty($headers['content-disposition'])) {
        $cd = $headers['content-disposition'];
        // filename*=UTF-8''encoded_name
        if (preg_match("/filename\*=(?:UTF-8''|utf-8'')(.+)/i", $cd, $m)) {
            return sanitizeFileName(urldecode($m[1]));
        }
        // filename="name"
        if (preg_match('/filename="?([^";\n]+)"?/i', $cd, $m)) {
            $name = trim($m[1]);
            if ($name && $name !== 'download') {
                return sanitizeFileName($name);
            }
        }
    }

    // Fallback: build from title + format
    return sanitizeFileName($fallbackTitle) . '.' . $fallbackFormat;
}
