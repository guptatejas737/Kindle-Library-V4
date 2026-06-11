<?php
require_once __DIR__ . '/config.php';

/**
 * Attempts to get a cover for a book through multiple strategies:
 * 1. Extract embedded cover from ebook file via kiwilan/php-ebook
 * 2. Fetch from Open Library API by title+author
 * 3. Generate a placeholder using GD
 *
 * Returns relative path to cover image on success, empty string on failure.
 */
function fetchCoverForBook(int $bookId, string $filepath, string $title, string $author): string {
    if (!is_dir(COVERS_DIR)) mkdir(COVERS_DIR, 0755, true);

    $coverFile = 'covers/' . $bookId . '.jpg';
    $coverFullPath = APP_ROOT . '/' . $coverFile;

    // Strategy 1: Extract from ebook file
    if (file_exists($filepath)) {
        $extracted = extractCoverFromEbook($filepath, $coverFullPath);
        if ($extracted) return $coverFile;
    }

    // Strategy 2: Open Library API
    $fetched = fetchCoverFromOpenLibrary($title, $author, $coverFullPath);
    if ($fetched) return $coverFile;

    // Strategy 3: GD placeholder
    $generated = generatePlaceholderCover($title, $author, $coverFullPath);
    if ($generated) return $coverFile;

    return '';
}

function extractCoverFromEbook(string $filepath, string $outputPath): bool {
    $autoload = APP_ROOT . '/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;

    if (!class_exists('Kiwilan\\Ebook\\Ebook')) return false;

    try {
        $ebook = \Kiwilan\Ebook\Ebook::read($filepath);
        $cover = $ebook->getCover();
        if ($cover && $cover->getContents()) {
            $imgData = $cover->getContents();
            $img = @imagecreatefromstring($imgData);
            if ($img) {
                $resized = resizeCover($img, COVER_WIDTH, COVER_HEIGHT);
                imagejpeg($resized, $outputPath, 85);
                imagedestroy($img);
                imagedestroy($resized);
                return true;
            }
            // If GD fails, just save the raw image data
            file_put_contents($outputPath, $imgData);
            return true;
        }
    } catch (\Throwable $e) {
        // Silently continue to next strategy
    }
    return false;
}

function fetchCoverFromOpenLibrary(string $title, string $author, string $outputPath): bool {
    if (empty($title)) return false;

    $params = ['limit' => 1, 'fields' => 'cover_i,title,author_name'];
    $params['title'] = $title;
    if (!empty($author)) $params['author'] = $author;

    $url = OPENLIBRARY_SEARCH . '?' . http_build_query($params);

    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'header' => "User-Agent: KindleLibrary/1.0\r\n"]
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return false;

    $data = json_decode($json, true);
    if (empty($data['docs'][0]['cover_i'])) return false;

    $coverId = $data['docs'][0]['cover_i'];
    $coverUrl = OPENLIBRARY_COVERS . $coverId . '-M.jpg';

    $imgData = @file_get_contents($coverUrl, false, $ctx);
    if (!$imgData || strlen($imgData) < 500) return false;

    $img = @imagecreatefromstring($imgData);
    if ($img) {
        $resized = resizeCover($img, COVER_WIDTH, COVER_HEIGHT);
        imagejpeg($resized, $outputPath, 85);
        imagedestroy($img);
        imagedestroy($resized);
        return true;
    }

    file_put_contents($outputPath, $imgData);
    return true;
}

function generatePlaceholderCover(string $title, string $author, string $outputPath): bool {
    if (!function_exists('imagecreatetruecolor')) return false;

    $w = COVER_WIDTH;
    $h = COVER_HEIGHT;
    $img = imagecreatetruecolor($w, $h);

    $hash = crc32($title . $author);
    $shade = 60 + abs($hash % 120); // range 60-179 for readable backgrounds
    $bg = imagecolorallocate($img, $shade, $shade, $shade);
    $textColor = ($shade > 120) ? imagecolorallocate($img, 0, 0, 0) : imagecolorallocate($img, 255, 255, 255);
    $borderColor = imagecolorallocate($img, 40, 40, 40);

    imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $bg);
    imagerectangle($img, 0, 0, $w - 1, $h - 1, $borderColor);
    imagerectangle($img, 2, 2, $w - 3, $h - 3, $borderColor);

    // Title - wordwrap and draw line by line
    $wrapped = wordwrap($title, 14, "\n", true);
    $lines = explode("\n", $wrapped);
    $y = 30;
    $font = 3;
    foreach (array_slice($lines, 0, 7) as $line) {
        $lineW = imagefontwidth($font) * strlen($line);
        $x = max(4, ($w - $lineW) / 2);
        imagestring($img, $font, (int)$x, $y, $line, $textColor);
        $y += 16;
    }

    // Author at bottom
    if ($author) {
        $authorWrapped = wordwrap($author, 18, "\n", true);
        $authorLines = explode("\n", $authorWrapped);
        $ay = $h - 20 - (count(array_slice($authorLines, 0, 2)) * 12);
        $smallFont = 2;
        foreach (array_slice($authorLines, 0, 2) as $aline) {
            $alineW = imagefontwidth($smallFont) * strlen($aline);
            $ax = max(4, ($w - $alineW) / 2);
            imagestring($img, $smallFont, (int)$ax, $ay, $aline, $textColor);
            $ay += 12;
        }
    }

    imagejpeg($img, $outputPath, 85);
    imagedestroy($img);
    return true;
}

function resizeCover($srcImg, int $targetW, int $targetH) {
    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);
    $dst = imagecreatetruecolor($targetW, $targetH);

    $scale = max($targetW / $srcW, $targetH / $srcH);
    $newW = (int)($srcW * $scale);
    $newH = (int)($srcH * $scale);
    $offX = (int)(($targetW - $newW) / 2);
    $offY = (int)(($targetH - $newH) / 2);

    $black = imagecolorallocate($dst, 0, 0, 0);
    imagefilledrectangle($dst, 0, 0, $targetW - 1, $targetH - 1, $black);
    imagecopyresampled($dst, $srcImg, $offX, $offY, 0, 0, $newW, $newH, $srcW, $srcH);

    return $dst;
}

/**
 * Fetches book info (synopsis, cover) from Open Library with caching.
 */
function getOpenLibraryInfo(string $title, string $author = ''): ?array {
    require_once __DIR__ . '/db.php';
    $db = getDb();
    $cacheKey = md5(strtolower(trim($title)) . '|' . strtolower(trim($author)));

    $stmt = $db->prepare('SELECT data, created_at FROM search_cache WHERE cache_key = ?');
    $stmt->execute([$cacheKey]);
    $cached = $stmt->fetch();

    if ($cached && (time() - $cached['created_at']) < SEARCH_CACHE_TTL) {
        return json_decode($cached['data'], true);
    }

    $params = ['limit' => 1, 'fields' => 'cover_i,title,author_name,first_sentence,subject,key'];
    $params['title'] = $title;
    if (!empty($author)) $params['author'] = $author;

    $url = OPENLIBRARY_SEARCH . '?' . http_build_query($params);
    $ctx = stream_context_create([
        'http' => ['timeout' => 6, 'header' => "User-Agent: KindleLibrary/1.0\r\n"]
    ]);

    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (empty($data['docs'][0])) return null;

    $doc = $data['docs'][0];
    $info = [
        'synopsis' => '',
        'cover_url' => '',
        'subjects' => [],
    ];

    if (!empty($doc['first_sentence'])) {
        $info['synopsis'] = is_array($doc['first_sentence']) ? $doc['first_sentence'][0] : $doc['first_sentence'];
    }
    if (!empty($doc['cover_i'])) {
        $info['cover_url'] = OPENLIBRARY_COVERS . $doc['cover_i'] . '-M.jpg';
    }
    if (!empty($doc['subject'])) {
        $info['subjects'] = array_slice($doc['subject'], 0, 5);
    }

    // Cache it
    $stmt = $db->prepare('INSERT OR REPLACE INTO search_cache (cache_key, data, created_at) VALUES (?, ?, ?)');
    $stmt->execute([$cacheKey, json_encode($info), time()]);

    return $info;
}
